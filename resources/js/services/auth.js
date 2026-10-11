import { reactive } from 'vue';

// reactive session so every component updates when the user logs in / out
const read = () => {
    try { return JSON.parse(localStorage.getItem('user') || 'null'); } catch { return null; }
};

export const session = reactive({
    token: localStorage.getItem('token'),
    user: read(),
});

export const isLoggedIn = () => !!session.token && !!session.user;
export const isAdmin = () => session.user?.role === 'admin' || session.user?.is_admin === true;

export const saveSession = (token, user) => {
    session.token = token;
    session.user = user;
    localStorage.setItem('token', token);
    localStorage.setItem('user', JSON.stringify(user));
};

export const updateUser = (user) => {
    session.user = user;
    localStorage.setItem('user', JSON.stringify(user));
};

export const clearSession = () => {
    session.token = null;
    session.user = null;
    localStorage.removeItem('token');
    localStorage.removeItem('user');
};
