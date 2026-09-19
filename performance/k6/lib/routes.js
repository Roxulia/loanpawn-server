const tenant = '/api/tenant';

function encode(value) {
    return encodeURIComponent(value);
}

function query(path, values = {}) {
    const entries = Object.entries(values).filter(([, value]) => value !== undefined && value !== null && value !== '');
    if (entries.length === 0) return path;
    return `${path}?${entries.map(([key, value]) => `${encode(key)}=${encode(value)}`).join('&')}`;
}

export const routes = {
    auth: {
        me: () => `${tenant}/me`,
    },
    dashboard: {
        summary: () => `${tenant}/dashboard/summary`,
    },
    notifications: {
        list: (params) => query(`${tenant}/notifications`, params),
    },
    customers: {
        list: (params) => query(`${tenant}/customers`, params),
        detail: (code) => `${tenant}/customers/${encode(code)}`,
    },
    collateral: {
        list: (params) => query(`${tenant}/collateral-items`, params),
        detail: (code) => `${tenant}/collateral-items/${encode(code)}`,
    },
    slips: {
        list: (params) => query(`${tenant}/loan-contract-slips`, params),
        detail: (slipNo) => `${tenant}/loan-contract-slips/${encode(slipNo)}`,
        compoundSchedule: (slipNo) => `${tenant}/loan-contract-slips/${encode(slipNo)}/compound-schedule`,
        compoundInterest: (slipNo) => `${tenant}/loan-contract-slips/${encode(slipNo)}/compound-interest`,
        partialPrincipal: (slipNo) => `${tenant}/loan-contract-slips/${encode(slipNo)}/partial-principal`,
    },
    interest: {
        history: (params) => query(`${tenant}/interest-payments`, params),
        calculate: (slipNo) => `${tenant}/interest-payments/${encode(slipNo)}/calculate`,
        pay: (slipNo) => `${tenant}/interest-payments/${encode(slipNo)}/pay`,
    },
    redemptions: {
        list: (params) => query(`${tenant}/redemptions`, params),
        calculate: (slipNo) => `${tenant}/redemptions/${encode(slipNo)}/calculate`,
        records: (slipNo) => `${tenant}/redemption-records/${encode(slipNo)}`,
    },
    debts: {
        list: (params) => query(`${tenant}/debts`, params),
        detail: (code) => `${tenant}/debts/${encode(code)}`,
        interest: (code) => `${tenant}/debts/${encode(code)}/interest`,
        payments: (code, params) => query(`${tenant}/debts/${encode(code)}/payments`, params),
        compoundSchedule: (code) => `${tenant}/debts/${encode(code)}/compound-schedule`,
        compoundInterest: (code) => `${tenant}/debts/${encode(code)}/compound-interest`,
    },
    lenders: {
        list: (params) => query(`${tenant}/lenders`, params),
        detail: (code) => `${tenant}/lenders/${encode(code)}`,
    },
    businessLoans: {
        list: (params) => query(`${tenant}/business-loans`, params),
        detail: (code) => `${tenant}/business-loans/${encode(code)}`,
        interest: (code, params) => query(`${tenant}/business-loans/${encode(code)}/interest`, params),
        payments: (code, params) => query(`${tenant}/business-loans/${encode(code)}/payments`, params),
        pay: (code) => `${tenant}/business-loans/${encode(code)}/payments`,
        compoundSchedule: (code) => `${tenant}/business-loans/${encode(code)}/compound-schedule`,
        compoundInterest: (code) => `${tenant}/business-loans/${encode(code)}/compound-interest`,
    },
    scheduledExpenses: {
        list: (params) => query(`${tenant}/scheduled-expenses`, params),
        detail: (code) => `${tenant}/scheduled-expenses/${encode(code)}`,
        occurrences: (code, params) => query(`${tenant}/scheduled-expenses/${encode(code)}/occurrences`, params),
        pause: (code) => `${tenant}/scheduled-expenses/${encode(code)}/pause`,
        resume: (code) => `${tenant}/scheduled-expenses/${encode(code)}/resume`,
    },
    accounting: {
        overview: () => `${tenant}/accounting/overview`,
        movements: (params) => query(`${tenant}/accounting/movements`, params),
        currentDay: () => `${tenant}/accounting-days/current`,
    },
    financialAccounts: {
        list: (params) => query(`${tenant}/financial-accounts`, params),
        detail: (code) => `${tenant}/financial-accounts/${encode(code)}`,
        transactions: (code, params) => query(`${tenant}/financial-accounts/${encode(code)}/transactions`, params),
    },
    settings: {
        tenant: () => `${tenant}/settings/tenant`,
        finance: () => `${tenant}/settings/finance`,
        defaultData: () => `${tenant}/settings/default-data`,
        loanSlipCreation: () => `${tenant}/settings/loan-slip-creation`,
        interestProcess: () => `${tenant}/settings/interest-process`,
    },
    defaultData: {
        interestTypes: (params) => query(`${tenant}/interest-types`, params),
        expenseTypes: (params) => query(`${tenant}/expense-types`, params),
    },
};

export function pageMode(value) {
    return value === 'sequential' ? 'sequential' : 'parallel';
}
