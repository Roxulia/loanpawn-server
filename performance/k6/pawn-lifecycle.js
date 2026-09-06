import { check, fail, sleep } from 'k6';
import { apiGet, apiWrite, findRecordByCode, responseData } from './lib/client.js';
import { numberSetting } from './lib/config.js';

// Keep the write test deliberately smaller because every iteration creates financial history.
export const options = {
    vus: numberSetting('VUS', 2),
    duration: __ENV.DURATION || '1m',
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<2500'],
    },
};

export default function () {
    // Resolve current master-data IDs through the API because database IDs change after reseeding.
    const bootstrapResponse = apiGet('/api/tenant/settings/default-data', 'default-data-bootstrap');
    const bootstrap = responseData(bootstrapResponse, 'default-data bootstrap');
    const interestType = findRecordByCode(bootstrap, 'monthly');
    if (!interestType) fail('Monthly interest type was not present in default-data bootstrap.');

    // The VU, iteration, and timestamp make customer data and idempotency keys collision-resistant.
    const unique = `${__VU}-${__ITER}-${Date.now()}`;
    const createKey = `k6-create-${unique}`;
    const createResponse = apiWrite('POST', '/api/tenant/loan-contract-slips', {
        customer: {
            name: `k6 Customer ${unique}`,
            email: `k6-${unique}@performance.test`,
        },
        collateral_items: [{
            type: 'Normal',
            name: 'k6 Load Test Phone',
            estimated_value: 300000,
            item_status: 'pawned',
            quantity: 1,
            minimum_retail_price: 350000,
        }],
        loan_amount: 200000,
        interest_rate: 5,
        interest_type_id: interestType.id,
        expiry_quota: 3,
        expiry_quota_type: 'Month',
    }, 'slip-create', createKey);
    if (!check(createResponse, { 'slip creation succeeds': (response) => response.status === 201 })) {
        fail(`Slip creation failed: HTTP ${createResponse.status} ${createResponse.body}`);
    }

    const createdSlip = responseData(createResponse, 'slip creation');
    const slipNo = createdSlip.slip_no || createdSlip.slipNo;
    if (!slipNo) fail('Slip creation response did not contain a slip number.');

    // Calculation provides optimistic-lock keys and the exact breakdown required by payment.
    const calculationResponse = apiGet(`/api/tenant/interest-payments/${encodeURIComponent(slipNo)}/calculate`, 'interest-calculate');
    const calculation = responseData(calculationResponse, 'interest calculation');
    const paymentResponse = apiWrite('POST', `/api/tenant/interest-payments/${encodeURIComponent(slipNo)}/pay`, {
        slip_update_key: calculation.slip_update_key,
        payment_amount: calculation.total_interest_amount,
        record_debt: false,
        interest_breakdown: calculation.interest_breakdown,
    }, 'interest-pay', `k6-interest-${unique}`);
    if (!check(paymentResponse, { 'interest payment succeeds': (response) => response.status === 200 })) {
        fail(`Interest payment failed: HTTP ${paymentResponse.status} ${paymentResponse.body}`);
    }

    // Redemption is calculated immediately before posting to avoid stale financial values.
    const redemptionCalculationResponse = apiGet(`/api/tenant/redemptions/${encodeURIComponent(slipNo)}/calculate`, 'redemption-calculate');
    const redemption = responseData(redemptionCalculationResponse, 'redemption calculation');
    const redemptionResponse = apiWrite('POST', '/api/tenant/redemptions', {
        slip_no: slipNo,
        calculated_total: redemption.total_amount_to_pay,
        payment_amount: redemption.total_amount_to_pay,
        interests: redemption.interest_payments || [],
        debts: redemption.debts || [],
    }, 'redemption-create', `k6-redemption-${unique}`);
    check(redemptionResponse, { 'redemption succeeds': (response) => response.status === 201 });

    sleep(1);
}
