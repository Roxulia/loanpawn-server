import { check, sleep } from 'k6';
import { apiGet } from './lib/client.js';

// A smoke run confirms configuration and authentication before applying meaningful load.
export const options = {
    vus: 1,
    iterations: 1,
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<1500'],
    },
};

export default function () {
    // Each check names the business capability that a failed request represents.
    const requests = [
        ['/api/tenant/me', 'current-user'],
        ['/api/tenant/dashboard/summary', 'dashboard-summary'],
        ['/api/tenant/customers?per_page=10', 'customer-list'],
        ['/api/tenant/loan-contract-slips?per_page=10', 'slip-list'],
        ['/api/tenant/interest-payments?per_page=10', 'interest-history'],
    ];

    for (const [path, endpoint] of requests) {
        const response = apiGet(path, endpoint);
        check(response, { [`${endpoint} returns HTTP 200`]: (result) => result.status === 200 });
    }

    // A short pause makes local logs and traces easier to read.
    sleep(1);
}
