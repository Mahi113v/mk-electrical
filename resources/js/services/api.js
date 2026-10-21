import axios from 'axios';
import { clearSession, session } from './auth';

/* Single axios instance. Auth = Sanctum Bearer token (no cookies / CSRF needed). */
// Same server: '/api'.  Frontend on Netlify + backend on Railway: set VITE_API_URL=https://your-backend.up.railway.app/api
export const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL || '/api',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
    if (session.token) config.headers.Authorization = `Bearer ${session.token}`;
    // company + account year the person is working in (checked again by the server on every call)
    if (session.companyId) config.headers['X-Company-Id'] = session.companyId;
    if (session.yearId) config.headers['X-Year-Id'] = session.yearId;
    return config;
});

api.interceptors.response.use(
    (r) => r,
    (error) => {
        const url = error.config?.url || '';
        // expired / revoked token -> login (but not for the login call itself or public form)
        if (error.response?.status === 401 && !url.includes('/login') && !url.includes('/public/')) {
            const back = session.portal === 'admin' ? '/admin' : '/login';
            clearSession();
            if (window.location.pathname !== back) window.location.href = back;
        }
        return Promise.reject(error);
    }
);

/* First validation message, or the server message, or a friendly fallback */
export const errorMessage = (error, fallback = 'Something went wrong') => {
    const data = error?.response?.data;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
    return first || data?.message || (error?.request ? 'Cannot reach the server' : fallback);
};

export const authApi = {
    login: (d) => api.post('/login', d),
    adminLogin: (d) => api.post('/admin/login', d),
    logout: () => api.post('/logout'),
    me: () => api.get('/user'),
    years: () => api.get('/public/account-years'),
    updateProfile: (d) => api.put('/profile', d),
    changePassword: (d) => api.put('/profile/password', d),
};
export const dashboardApi = { get: () => api.get('/dashboard') };
export const productApi = {
    getAll: (params) => api.get('/products', { params }),
    create: (d) => api.post('/products', d),
    update: (id, d) => api.put(`/products/${id}`, d),
    delete: (id) => api.delete(`/products/${id}`),
};
export const customerApi = {
    getAll: (params) => api.get('/customers', { params }),
    create: (d) => api.post('/customers', d),
    update: (id, d) => api.put(`/customers/${id}`, d),
    delete: (id) => api.delete(`/customers/${id}`),
};
export const orderApi = {
    getAll: (params) => api.get('/orders', { params }),
    get: (id) => api.get(`/orders/${id}`),
    create: (d) => api.post('/orders', d),
    update: (id, d) => api.put(`/orders/${id}`, d),
    updateStatus: (id, status) => api.patch(`/orders/${id}/status`, { status }),
    delete: (id) => api.delete(`/orders/${id}`),
    // invoice needs the token, so fetch it and show in a new tab
    print: async (id) => {
        const res = await api.get(`/orders/${id}/print`, { responseType: 'text', headers: { Accept: 'text/html' } });
        window.open(URL.createObjectURL(new Blob([res.data], { type: 'text/html' })), '_blank');
    },
};
export const workApi = {
    getAll: (params) => api.get('/works', { params }),
    create: (d) => api.post('/works', d),
    update: (id, d) => api.put(`/works/${id}`, d),
    delete: (id) => api.delete(`/works/${id}`),
};
export const publicApi = {
    products: (company) => api.get('/public/products', { params: { company } }),
    createOrder: (d, company) => api.post('/public/orders', d, { params: { company } }),
};

export const purchaseApi = {
    getAll: (params) => api.get('/purchases', { params }),
    create: (d) => api.post('/purchases', d),
    update: (id, d) => api.put(`/purchases/${id}`, d),
    delete: (id) => api.delete(`/purchases/${id}`),
};
export const saleApi = {
    getAll: (params) => api.get('/sales', { params }),
    create: (d) => api.post('/sales', d),
    update: (id, d) => api.put(`/sales/${id}`, d),
    delete: (id) => api.delete(`/sales/${id}`),
};
export const adjustmentApi = {
    getAll: (params) => api.get('/stock-adjustments', { params }),
    create: (d) => api.post('/stock-adjustments', d),
    delete: (id) => api.delete(`/stock-adjustments/${id}`),
};
export const stockApi = {
    summary: (params) => api.get('/stock', { params }),
    opening: () => api.get('/opening-stock'),
    refreshOpening: (force = false) => api.post('/opening-stock/refresh', { force }),
    setOpening: (productId, quantity) => api.put(`/opening-stock/${productId}`, { quantity }),
};

/* Admin console (super admin only) */
export const adminApi = {
    dashboard: () => api.get('/admin/dashboard'),
    companies: (params) => api.get('/admin/companies', { params }),
    createCompany: (d) => api.post('/admin/companies', d),
    updateCompany: (id, d) => api.put(`/admin/companies/${id}`, d),
    deleteCompany: (id) => api.delete(`/admin/companies/${id}`),
    users: (params) => api.get('/admin/users', { params }),
    createUser: (d) => api.post('/admin/users', d),
    updateUser: (id, d) => api.put(`/admin/users/${id}`, d),
    deleteUser: (id) => api.delete(`/admin/users/${id}`),
    roles: () => api.get('/admin/roles'),
    createRole: (d) => api.post('/admin/roles', d),
    updateRole: (id, d) => api.put(`/admin/roles/${id}`, d),
    deleteRole: (id) => api.delete(`/admin/roles/${id}`),
    years: () => api.get('/admin/account-years'),
    createYear: () => api.post('/admin/account-years'),
    lockYear: (yearId, companyId, is_locked) => api.put(`/admin/account-years/${yearId}/companies/${companyId}`, { is_locked }),
};

export const qty = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
export const inr = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
