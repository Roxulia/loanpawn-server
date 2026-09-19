// Centralizing settings keeps every scenario runnable with the same command-line variables.
export const settings = {
    baseUrl: (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, ''),
    origin: __ENV.ORIGIN || 'http://127.0.0.1:5173',
    tenantCode: __ENV.TENANT_CODE || 'perf-tenant-001',
    appVersion: __ENV.APP_VERSION || '1.3.0',
    requestMode: __ENV.REQUEST_MODE || 'parallel',
    page: __ENV.PAGE || 'unknown',
};

/*
 * Legacy single-user authentication setup (intentionally disabled, not deleted):
 *
 * email: __ENV.EMAIL || 'owner001@performance.test',
 * password: __ENV.PASSWORD || 'Performance123!',
 *
 * The VU-aware setup below assigns one seeded tenant user to each VU instead.
 */

let configuredUsers = null;

if (__ENV.K6_USERS_JSON) {
    try {
        configuredUsers = JSON.parse(__ENV.K6_USERS_JSON);
    } catch (error) {
        throw new Error(`K6_USERS_JSON must be valid JSON: ${error.message}`);
    }

    if (!Array.isArray(configuredUsers) || configuredUsers.length === 0) {
        throw new Error('K6_USERS_JSON must contain a non-empty array of user credentials.');
    }
}

function tenantSuffix(tenantCode) {
    const match = tenantCode.match(/(\d+)$/);
    return match ? match[1] : '001';
}

// Select a distinct seeded identity for every VU, while keeping all VUs in the same tenant by default.
export function authForVu() {
    const userIndex = Math.max(0, (__VU || 1) - 1);
    const configuredUser = configuredUsers ? configuredUsers[userIndex] : null;

    if (configuredUsers && !configuredUser) {
        throw new Error(`No credential is configured for VU ${__VU}. Add more entries to K6_USERS_JSON.`);
    }

    if (configuredUser) {
        if (!configuredUser.tenantCode || !configuredUser.email || !configuredUser.password) {
            throw new Error(`K6_USERS_JSON entry ${userIndex + 1} must contain tenantCode, email, and password.`);
        }

        return configuredUser;
    }

    const suffix = tenantSuffix(settings.tenantCode);
    const seededEmail = userIndex === 0
        ? `owner${suffix}@performance.test`
        : `user${String(userIndex + 1).padStart(2, '0')}${suffix}@performance.test`;

    return {
        tenantCode: settings.tenantCode,
        email: seededEmail,
        password: __ENV.PASSWORD || 'Performance123!',
    };
}

// Numeric helpers make malformed environment values fall back instead of breaking a run.
export function numberSetting(name, fallback) {
    const value = Number(__ENV[name]);
    return Number.isFinite(value) && value > 0 ? value : fallback;
}

// Every tenant request carries the headers used by the real SPA middleware.
export function apiHeaders(extra = {}, auth = authForVu()) {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Origin: settings.origin,
        Referer: `${settings.origin}/`,
        'X-Tenant-Code': auth.tenantCode,
        'X-LonePawn-App-Version': settings.appVersion,
        ...extra,
    };
}
