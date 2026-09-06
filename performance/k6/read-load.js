import { check, fail, sleep } from 'k6';
import { apiGet, authenticate, responseData } from './lib/client.js';
import { numberSetting } from './lib/config.js';

// Safe defaults can be overridden with VUS and DURATION without editing this file.
export const options = {
    vus: numberSetting('VUS', 5),
    duration: __ENV.DURATION || '2m',
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<1500'],
    },
};

export default function () {
    // Start from a real paginated response so detail requests never depend on database IDs.
    const slipList = apiGetWithAuthenticationRetry('/api/tenant/loan-contract-slips?per_page=100', 'slip-list');
    // Stop the iteration with response details when the prerequisite list request fails.
    if (!check(slipList, { 'slip list succeeds': (response) => response.status === 200 })) {
        fail(`Slip list failed: HTTP ${slipList.status} ${slipList.body}`);
    }

    // Safely extract list items when the successful response contains no data object.
    const slipData = responseData(slipList, 'slip list');
    const slips = slipData?.items || slipData?.data || [];

    // Weight common navigation endpoints more heavily than individual detail calculations.
    const roll = Math.random();
    let response;
    if (roll < 0.25) {
        response = apiGetWithAuthenticationRetry('/api/tenant/dashboard/summary', 'dashboard-summary');
    } else if (roll < 0.45) {
        response = apiGetWithAuthenticationRetry('/api/tenant/customers?per_page=50', 'customer-list');
    } else if (roll < 0.60) {
        response = apiGetWithAuthenticationRetry('/api/tenant/collateral-items?per_page=50', 'collateral-list');
    } else if (roll < 0.75) {
        response = apiGetWithAuthenticationRetry('/api/tenant/interest-payments?per_page=50', 'interest-history');
    } else if (roll < 0.90) {
        response = apiGetWithAuthenticationRetry('/api/tenant/redemptions?per_page=50', 'redemption-list');
    } else if (slips.length > 0) {
        const slip = slips[Math.floor(Math.random() * slips.length)];
        const slipNo = encodeURIComponent(slip.slip_no || slip.slipNo);
        response = apiGetWithAuthenticationRetry(`/api/tenant/interest-payments/${slipNo}/calculate`, 'interest-calculate');
    } else {
        response = slipList;
    }

    check(response, { 'selected read succeeds': (result) => result.status === 200 });
    sleep(Math.random() * 1.5 + 0.5);
}

// Retry a read request once with a fresh Sanctum session after an unauthorized response.
function apiGetWithAuthenticationRetry(path, endpoint) {
    // Perform the normal request using the virtual user's current authenticated session.
    let response = apiGet(path, endpoint);
    if (response.status !== 401) return response;

    // Force a new CSRF handshake and login before retrying the failed request once.
    authenticate(true);
    response = apiGet(path, `${endpoint}-retry`);

    return response;
}
