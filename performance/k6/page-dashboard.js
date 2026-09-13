import { check, group, sleep } from 'k6';
import { apiBatchGet, apiGet } from './lib/client.js';
import { numberSetting, settings } from './lib/config.js';
import { pageLoadDuration, pageRequestCount, recordPage } from './lib/page-metrics.js';
import { pageMode, routes } from './lib/routes.js';

export { pageLoadDuration, pageRequestCount };

export const options = {
    vus: numberSetting('VUS', 5),
    duration: __ENV.DURATION || '2m',
    thresholds: {
        'http_req_failed{page:dashboard}': ['rate<0.01'],
        'http_req_duration{page:dashboard}': ['p(95)<1200'],
        'page_load_duration{page:dashboard}': ['p(95)<2500'],
    },
};

export default function () {
    group('dashboard', () => {
        const requests = [
            { path: routes.dashboard.summary(), endpoint: 'dashboard-summary' },
            { path: routes.notifications.list({ per_page: 20 }), endpoint: 'dashboard-notifications' },
            { path: routes.financialAccounts.list({ per_page: 20 }), endpoint: 'dashboard-financial-accounts' },
            { path: routes.slips.list({ per_page: 10 }), endpoint: 'dashboard-slips' },
            { path: routes.accounting.overview(), endpoint: 'dashboard-accounting-overview' },
        ];

        const startedAt = Date.now();
        const responses = pageMode(settings.requestMode) === 'parallel'
            ? apiBatchGet(requests, { page: 'dashboard', phase: 'initial-load' })
            : requests.map(({ path, endpoint }) => apiGet(path, endpoint, { page: 'dashboard', phase: 'initial-load' }));

        const successful = recordPage('dashboard', startedAt, responses);
        check(successful, { 'dashboard APIs succeed': (items) => items.every((response) => response.status === 200) });
    });

    sleep(1);
}
