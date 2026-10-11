import axios from 'axios';
import { clearSession, session } from './auth';

/* Single axios instance. Auth = Sanctum Bearer token (no cookies / CSRF needed). */
export const api = axios.create({
    baseURL: '/api',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
    if (session.token) config.headers.Authorization = `Bearer ${session.token}`;
    return config;
});

api.interceptors.response.use(
    (r) => r,
    (error) => {
        const url = error.config?.url || '';
        // expired / revoked token -> login (but not for the login call itself or public form)
        if (error.response?.status === 401 && !url.includes('/login') && !url.includes('/public/')) {
            clearSession();
            if (window.location.pathname !== '/login') window.location.href = '/login';
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
    logout: () => api.post('/logout'),
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
export const userApi = {
    getAll: (params) => api.get('/users', { params }),
    create: (d) => api.post('/users', d),
    update: (id, d) => api.put(`/users/${id}`, d),
    delete: (id) => api.delete(`/users/${id}`),
};
export const publicApi = {
    products: () => api.get('/public/products'),
    createOrder: (d) => api.post('/public/orders', d),
};

export const inr = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });
