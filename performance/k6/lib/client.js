import http from 'k6/http';
import { check, fail } from 'k6';
import { apiHeaders, authForVu, settings } from './config.js';

// Module variables are private to each k6 virtual user, so each VU logs in only once.
let authenticated = false;

// Laravel Sanctum requires a CSRF cookie before the session-based login request.
export function authenticate(forceLogin = false) {
    // Reuse the current session unless the caller detected that it is no longer authenticated.
    if (authenticated && !forceLogin) return;

    const auth = authForVu();
    const csrfResponse = http.get(`${settings.baseUrl}/sanctum/csrf-cookie`, {
        headers: apiHeaders({}, auth),
        tags: { endpoint: 'csrf-cookie' },
    });
    if (!check(csrfResponse, { 'CSRF cookie returned': (response) => response.status === 204 || response.status === 200 })) {
        fail(`Unable to obtain CSRF cookie: HTTP ${csrfResponse.status}`);
    }

    // k6 stores response cookies in the VU cookie jar, matching browser behavior.
    const cookies = http.cookieJar().cookiesForURL(settings.baseUrl);
    const encodedToken = cookies['XSRF-TOKEN'] && cookies['XSRF-TOKEN'][0];
    if (!encodedToken) fail('The XSRF-TOKEN cookie was not returned by Laravel.');

    const loginResponse = http.post(
        `${settings.baseUrl}/api/tenant/login/public-spa`,
        JSON.stringify({ tenant_code: auth.tenantCode, email: auth.email, password: auth.password }),
        {
            headers: apiHeaders({ 'X-XSRF-TOKEN': decodeURIComponent(encodedToken) }, auth),
            tags: { endpoint: 'tenant-login' },
        },
    );
    if (!check(loginResponse, { 'tenant login succeeds': (response) => response.status === 200 })) {
        fail(`Tenant login failed: HTTP ${loginResponse.status} ${loginResponse.body}`);
    }

    authenticated = true;
}

// Read requests share headers, tags, and URL construction for consistent k6 metrics.
export function apiGet(path, endpoint, tags = {}) {
    authenticate();
    return http.get(`${settings.baseUrl}${path}`, {
        headers: apiHeaders(),
        tags: { endpoint, ...tags },
    });
}

// Batch reads model the parallel requests made by a page during its initial load.
export function apiBatchGet(requests, tags = {}) {
    authenticate();

    return http.batch(requests.map(({ path, endpoint, tags: requestTags = {} }) => [
        'GET',
        `${settings.baseUrl}${path}`,
        null,
        {
            headers: apiHeaders(),
            tags: { endpoint, ...tags, ...requestTags },
        },
    ]));
}

// Write requests need the current CSRF token plus an optional idempotency key.
export function apiWrite(method, path, payload, endpoint, idempotencyKey = null, tags = {}) {
    authenticate();
    const cookies = http.cookieJar().cookiesForURL(settings.baseUrl);
    const encodedToken = cookies['XSRF-TOKEN'] && cookies['XSRF-TOKEN'][0];
    const headers = apiHeaders({ 'X-XSRF-TOKEN': decodeURIComponent(encodedToken || '') });
    if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;

    return http.request(method, `${settings.baseUrl}${path}`, JSON.stringify(payload), {
        headers,
        tags: { endpoint, ...tags },
    });
}

// API errors are easier to diagnose when response parsing fails with endpoint context.
export function responseData(response, label) {
    let body;
    try {
        body = response.json();
    } catch (_) {
        fail(`${label} returned non-JSON content: HTTP ${response.status}`);
    }

    return body && body.data !== undefined ? body.data : body;
}

// Bootstrap responses are nested, so this helper locates a master record by code safely.
export function findRecordByCode(value, code) {
    if (!value || typeof value !== 'object') return null;
    if (value.code === code && value.id !== undefined) return value;

    for (const child of Object.values(value)) {
        const match = findRecordByCode(child, code);
        if (match) return match;
    }

    return null;
}

// Paginated responses can use either items or data depending on the endpoint.
export function responseItems(response, label) {
    const data = responseData(response, label);
    if (Array.isArray(data)) return data;
    return data?.items || data?.data || [];
}

export function firstRecord(response, label) {
    return responseItems(response, label)[0] || null;
}

export function resourceCode(record, fields = ['code', 'slip_no', 'slipNo']) {
    if (!record) return null;
    for (const field of fields) {
        if (record[field]) return record[field];
    }
    return null;
}
