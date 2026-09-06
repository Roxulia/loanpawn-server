import http from 'k6/http';
import { check, fail } from 'k6';
import { apiHeaders, settings } from './config.js';

// Module variables are private to each k6 virtual user, so each VU logs in only once.
let authenticated = false;

// Laravel Sanctum requires a CSRF cookie before the session-based login request.
export function authenticate(forceLogin = false) {
    // Reuse the current session unless the caller detected that it is no longer authenticated.
    if (authenticated && !forceLogin) return;

    const csrfResponse = http.get(`${settings.baseUrl}/sanctum/csrf-cookie`, {
        headers: apiHeaders(),
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
        JSON.stringify({ tenant_code: settings.tenantCode, email: settings.email, password: settings.password }),
        {
            headers: apiHeaders({ 'X-XSRF-TOKEN': decodeURIComponent(encodedToken) }),
            tags: { endpoint: 'tenant-login' },
        },
    );
    if (!check(loginResponse, { 'tenant login succeeds': (response) => response.status === 200 })) {
        fail(`Tenant login failed: HTTP ${loginResponse.status} ${loginResponse.body}`);
    }

    authenticated = true;
}

// Read requests share headers, tags, and URL construction for consistent k6 metrics.
export function apiGet(path, endpoint) {
    authenticate();
    return http.get(`${settings.baseUrl}${path}`, {
        headers: apiHeaders(),
        tags: { endpoint },
    });
}

// Write requests need the current CSRF token plus an optional idempotency key.
export function apiWrite(method, path, payload, endpoint, idempotencyKey = null) {
    authenticate();
    const cookies = http.cookieJar().cookiesForURL(settings.baseUrl);
    const encodedToken = cookies['XSRF-TOKEN'] && cookies['XSRF-TOKEN'][0];
    const headers = apiHeaders({ 'X-XSRF-TOKEN': decodeURIComponent(encodedToken || '') });
    if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;

    return http.request(method, `${settings.baseUrl}${path}`, JSON.stringify(payload), {
        headers,
        tags: { endpoint },
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
