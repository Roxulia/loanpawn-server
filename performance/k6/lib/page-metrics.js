import { Counter, Trend } from 'k6/metrics';

export const pageLoadDuration = new Trend('page_load_duration', true);
export const pageRequestCount = new Counter('page_request_count');

export function recordPage(page, startedAt, responses) {
    const successfulResponses = responses.filter(Boolean);
    pageLoadDuration.add(Date.now() - startedAt, { page });
    pageRequestCount.add(successfulResponses.length, { page });
    return successfulResponses;
}
