import { check, fail, group, sleep } from 'k6';
import { apiBatchGet, apiGet, firstRecord, resourceCode } from './lib/client.js';
import { numberSetting, settings } from './lib/config.js';
import { pageLoadDuration, pageRequestCount, recordPage } from './lib/page-metrics.js';
import { pageMode, routes } from './lib/routes.js';

export { pageLoadDuration, pageRequestCount };

export const options = {
    vus: numberSetting('VUS', 5),
    duration: __ENV.DURATION || '2m',
    thresholds: {
        'http_req_failed{page:debt-detail}': ['rate<0.01'],
        'http_req_duration{page:debt-detail}': ['p(95)<1500'],
        'page_load_duration{page:debt-detail}': ['p(95)<3000'],
    },
};

export default function () {
    group('debt-detail', () => {
        const listResponse = apiGet(routes.debts.list({ per_page: 25 }), 'debt-detail-list', { page: 'debt-detail', phase: 'resolve-record' });
        check(listResponse, { 'debt list succeeds': (response) => response.status === 200 });
        const debt = firstRecord(listResponse, 'debt list');
        const debtCode = __ENV.DEBT_CODE || resourceCode(debt);
        if (!debtCode) fail('No debt record was available for the debt-detail test. Set DEBT_CODE to an existing debt code.');

        const requests = [
            { path: routes.debts.detail(debtCode), endpoint: 'debt-detail' },
            { path: routes.debts.interest(debtCode), endpoint: 'debt-interest' },
            { path: routes.debts.payments(debtCode, { per_page: 20 }), endpoint: 'debt-payments' },
        ];
        const startedAt = Date.now();
        const responses = pageMode(settings.requestMode) === 'parallel'
            ? apiBatchGet(requests, { page: 'debt-detail', phase: 'initial-load' })
            : requests.map(({ path, endpoint }) => apiGet(path, endpoint, { page: 'debt-detail', phase: 'initial-load' }));

        const successful = recordPage('debt-detail', startedAt, responses);
        check(successful, { 'debt detail APIs succeed': (items) => items.every((response) => response.status === 200) });
    });

    sleep(1);
}
