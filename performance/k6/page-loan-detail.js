import { check, fail, group, sleep } from 'k6';
import { apiBatchGet, apiGet, firstRecord, responseData, resourceCode } from './lib/client.js';
import { numberSetting, settings } from './lib/config.js';
import { pageLoadDuration, pageRequestCount, recordPage } from './lib/page-metrics.js';
import { pageMode, routes } from './lib/routes.js';

export { pageLoadDuration, pageRequestCount };

export const options = {
    vus: numberSetting('VUS', 5),
    duration: __ENV.DURATION || '2m',
    thresholds: {
        'http_req_failed{page:loan-detail}': ['rate<0.01'],
        'http_req_duration{page:loan-detail}': ['p(95)<1500'],
        'page_load_duration{page:loan-detail}': ['p(95)<3000'],
    },
};

function loadRelatedRequests(slipNo) {
    const requests = [
        { path: routes.slips.detail(slipNo), endpoint: 'loan-detail-slip' },
        { path: routes.interest.calculate(slipNo), endpoint: 'loan-detail-interest' },
        { path: routes.interest.history({ per_page: 20 }), endpoint: 'loan-detail-interest-history' },
        { path: routes.redemptions.calculate(slipNo), endpoint: 'loan-detail-redemption' },
    ];

    if (pageMode(settings.requestMode) === 'parallel') {
        return apiBatchGet(requests, { page: 'loan-detail', phase: 'initial-load' });
    }

    return requests.map(({ path, endpoint }) => apiGet(path, endpoint, { page: 'loan-detail', phase: 'initial-load' }));
}

export default function () {
    group('loan-detail', () => {
        const listResponse = apiGet(routes.slips.list({ per_page: 25 }), 'loan-detail-slip-list', { page: 'loan-detail', phase: 'resolve-record' });
        check(listResponse, { 'loan detail slip list succeeds': (response) => response.status === 200 });
        const slip = firstRecord(listResponse, 'loan detail slip list');
        const slipNo = __ENV.SLIP_CODE || resourceCode(slip);
        if (!slipNo) fail('No slip record was available for the loan-detail test. Set SLIP_CODE to an existing slip number.');

        const startedAt = Date.now();
        const responses = loadRelatedRequests(slipNo);
        const successful = recordPage('loan-detail', startedAt, responses);
        check(successful, { 'loan detail APIs succeed': (items) => items.every((response) => response.status === 200) });
    });

    sleep(1);
}
