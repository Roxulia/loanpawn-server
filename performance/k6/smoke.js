import { check, sleep } from 'k6';
import { apiGet } from './lib/client.js';
import { routes } from './lib/routes.js';

// A smoke run confirms configuration and authentication before applying meaningful load.
export const options = {
    vus: 1,
    iterations: 1,
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<15000'],
    },
};

export default function () {
    // Each check names the business capability that a failed request represents.
    const requests = [
        [routes.auth.me(), 'current-user'],
        [routes.dashboard.summary(), 'dashboard-summary'],
        [routes.customers.list({ per_page: 10 }), 'customer-list'],
        [routes.slips.list({ per_page: 10 }), 'slip-list'],
        [routes.interest.history({ per_page: 10 }), 'interest-history'],
        [routes.debts.list({ per_page: 10 }), 'debt-list'],
        [routes.lenders.list({ per_page: 10 }), 'lender-list'],
        [routes.businessLoans.list({ per_page: 10 }), 'business-loan-list'],
        [routes.scheduledExpenses.list({ per_page: 10 }), 'scheduled-expense-list'],
        [routes.settings.interestProcess(), 'interest-process-settings'],
    ];

    for (const [path, endpoint] of requests) {
        const response = apiGet(path, endpoint);
        check(response, { [`${endpoint} returns HTTP 200`]: (result) => result.status === 200 });
    }

    // A short pause makes local logs and traces easier to read.
    sleep(1);
}
