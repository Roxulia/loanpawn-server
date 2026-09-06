// Centralizing settings keeps every scenario runnable with the same command-line variables.
export const settings = {
    baseUrl: (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, ''),
    origin: __ENV.ORIGIN || 'http://127.0.0.1:5173',
    tenantCode: __ENV.TENANT_CODE || 'perf-tenant-001',
    email: __ENV.EMAIL || 'owner001@performance.test',
    password: __ENV.PASSWORD || 'Performance123!',
    appVersion: __ENV.APP_VERSION || '1.3.0',
};

// Numeric helpers make malformed environment values fall back instead of breaking a run.
export function numberSetting(name, fallback) {
    const value = Number(__ENV[name]);
    return Number.isFinite(value) && value > 0 ? value : fallback;
}

// Every tenant request carries the headers used by the real SPA middleware.
export function apiHeaders(extra = {}) {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Origin: settings.origin,
        Referer: `${settings.origin}/`,
        'X-Tenant-Code': settings.tenantCode,
        'X-LonePawn-App-Version': settings.appVersion,
        ...extra,
    };
}
