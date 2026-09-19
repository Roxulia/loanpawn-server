import { check, fail, group, sleep } from 'k6';
import { apiBatchGet, apiGet, apiWrite, firstRecord, responseData, resourceCode } from './lib/client.js';
import { numberSetting, settings } from './lib/config.js';
import { pageLoadDuration, pageRequestCount, recordPage } from './lib/page-metrics.js';
import { pageMode, routes } from './lib/routes.js';

export { pageLoadDuration, pageRequestCount };

export const options = {
    vus: numberSetting('VUS', 2),
    duration: __ENV.DURATION || '1m',
    thresholds: {
        'http_req_failed{page:business-loan-detail}': ['rate<0.01'],
        'http_req_duration{page:business-loan-detail}': ['p(95)<1800'],
        'page_load_duration{page:business-loan-detail}': ['p(95)<3500'],
    },
};

function resolveBusinessLoan() {
    if (__ENV.BUSINESS_LOAN_CODE) return __ENV.BUSINESS_LOAN_CODE;

    const accountResponse = apiGet(routes.financialAccounts.list({ per_page: 10 }), 'business-loan-account-list', { page: 'business-loan-detail', phase: 'resolve-record' });
    const account = firstRecord(accountResponse, 'business-loan account list');
    const accountId = account?.id;
    if (!accountId) fail('No financial account was available for business-loan creation. Set BUSINESS_LOAN_CODE to an existing loan code.');

    const unique = `${__VU}-${__ITER}-${Date.now()}`;
    const createResponse = apiWrite('POST', routes.businessLoans.list(), {
        amount: 250000,
        description: `k6 business loan ${unique}`,
        receipt_account_id: accountId,
        apply_interest: false,
        idempotency_key: `k6-business-loan-${unique}`,
    }, 'business-loan-create', `k6-business-loan-${unique}`, { page: 'business-loan-detail', phase: 'resolve-record' });
    check(createResponse, { 'business loan creation succeeds': (response) => response.status === 201 });
    return resourceCode(responseData(createResponse, 'business-loan creation'), ['code']);
}

export default function () {
    group('business-loan-detail', () => {
        const loanCode = resolveBusinessLoan();
        if (!loanCode) fail('Business-loan creation did not return a loan code.');

        const requests = [
            { path: routes.businessLoans.detail(loanCode), endpoint: 'business-loan-detail' },
            { path: routes.businessLoans.interest(loanCode, { per_page: 20 }), endpoint: 'business-loan-interest' },
            { path: routes.businessLoans.payments(loanCode, { per_page: 20 }), endpoint: 'business-loan-payments' },
        ];
        const startedAt = Date.now();
        const responses = pageMode(settings.requestMode) === 'parallel'
            ? apiBatchGet(requests, { page: 'business-loan-detail', phase: 'initial-load' })
            : requests.map(({ path, endpoint }) => apiGet(path, endpoint, { page: 'business-loan-detail', phase: 'initial-load' }));

        const successful = recordPage('business-loan-detail', startedAt, responses);
        check(successful, { 'business-loan detail APIs succeed': (items) => items.every((response) => response.status === 200) });
    });

    sleep(1);
}
