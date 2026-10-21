import { reactive } from 'vue';

/*
 * Everything the app needs to know about the logged in person:
 *   token, user, companies (each with role + permissions), years, and the company / year they are working in.
 * The company and year are sent to the server in X-Company-Id / X-Year-Id on every call (see api.js)
 * and the server re-checks them every time, so changing them in the browser gives no extra access.
 */
const KEY = 'mk_session';

const load = () => {
    try { return JSON.parse(localStorage.getItem(KEY) || 'null') || {}; } catch { return {}; }
};
const saved = load();

export const session = reactive({
    token: saved.token || null,
    user: saved.user || null,
    companies: saved.companies || [],
    years: saved.years || [],
    companyId: saved.companyId || null,
    yearId: saved.yearId || null,
    // which login the person used: 'app' (/login) or 'admin' (/admin). The two never mix.
    portal: saved.portal || null,
    scope: saved.scope || null,      // admin portal: 'platform' (product admin) | 'company' (company admin)
    managed: saved.managed || [],    // admin portal: the companies he manages
});

const persist = () => localStorage.setItem(KEY, JSON.stringify(session));

export const isLoggedIn = () => !!session.token && !!session.user && session.portal === 'app';
export const isAdminLoggedIn = () => !!session.token && !!session.user && session.portal === 'admin';
export const isPlatformAdmin = () => isAdminLoggedIn() && session.scope === 'platform';
export const currentCompany = () => session.companies.find((c) => c.id === session.companyId) || null;
export const currentYear = () => session.years.find((y) => y.id === session.yearId) || null;
export const isLocked = () => !!currentCompany()?.locked_year_ids?.includes(session.yearId);

/** permission check for the menu / buttons (the server checks again) */
export const can = (permission) => !!currentCompany()?.permissions?.includes(permission);
/** can do it AND the year is not locked */
export const canWrite = (permission) => can(permission) && !isLocked();

export const applyContext = ({ user, companies, years, token, company_id, year_id, portal, scope, managed }) => {
    if (token) session.token = token;
    if (portal) session.portal = portal;
    if (scope !== undefined) session.scope = scope;
    if (managed) session.managed = managed;
    if (user) session.user = user;
    if (companies) session.companies = companies;
    if (years) session.years = years;

    // keep the chosen company / year if still allowed, else fall back
    if (company_id !== undefined && company_id !== null) session.companyId = company_id;
    if (year_id !== undefined && year_id !== null) session.yearId = year_id;
    if (!session.companies.some((c) => c.id === session.companyId)) {
        session.companyId = (session.companies.find((c) => c.is_default) || session.companies[0])?.id || null;
    }
    if (!session.years.some((y) => y.id === session.yearId)) {
        session.yearId = (session.years.find((y) => y.is_current) || session.years[0])?.id || null;
    }
    persist();
};

export const setCompany = (id) => { session.companyId = id; persist(); };
export const setYear = (id) => { session.yearId = id; persist(); };
export const updateUser = (user) => { session.user = user; persist(); };

export const clearSession = () => {
    Object.assign(session, { token: null, user: null, companies: [], years: [], companyId: null, yearId: null, portal: null, scope: null, managed: [] });
    localStorage.removeItem(KEY);
};

/** first screen the user is allowed to see */
export const homePath = () => {
    const order = [
        ['dashboard.view', '/dashboard'], ['orders.view', '/orders'], ['sales.view', '/sales'], ['purchases.view', '/purchases'],
        ['stock.view', '/stock'], ['opening_stock.view', '/opening-stock'], ['stock_adjustments.view', '/stock-adjustments'],
        ['products.view', '/products'], ['customers.view', '/customers'], ['work_status.view', '/work-status'],
    ];
    const hit = order.find(([p]) => can(p));
    if (hit) return hit[1];

    return '/profile';
};

/** YYYY-MM-DD of a Date in the computer's own time zone (toISOString would give the UTC day - a day behind early in the morning in India) */
export const isoDate = (d = new Date()) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** default date of a new document: today, or the last day of the year when a past year is selected */
export const defaultDate = () => {
    const y = currentYear();
    const today = isoDate();

    return y && (today < y.start_date || today > y.end_date) ? y.end_date : today;
};

/** default register period: account year start date -> today (never past the end of the year) */
export const defaultRange = () => {
    const y = currentYear();
    const today = isoDate();
    if (!y) return { from: today, to: today };

    return { from: y.start_date, to: today > y.end_date ? y.end_date : today < y.start_date ? y.start_date : today };
};
