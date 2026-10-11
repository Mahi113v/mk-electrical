import { createRouter, createWebHistory } from 'vue-router';
import { isAdmin, isLoggedIn } from '../services/auth';

const AppLayout = () => import('../components/AppLayout.vue');

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', redirect: '/dashboard' },
        { path: '/login', component: () => import('../views/LoginView.vue'), meta: { guest: true } },
        { path: '/order-data', component: () => import('../views/PublicOrderView.vue') },
        {
            path: '/',
            component: AppLayout,
            meta: { auth: true },
            children: [
                { path: 'dashboard', component: () => import('../views/DashboardView.vue'), meta: { title: 'Dashboard' } },
                { path: 'orders', component: () => import('../views/OrdersView.vue'), meta: { title: 'Orders' } },
                { path: 'customers', component: () => import('../views/CustomersView.vue'), meta: { title: 'Customers' } },
                { path: 'products', component: () => import('../views/ProductsView.vue'), meta: { title: 'Products' } },
                { path: 'work-status', component: () => import('../views/WorkStatusView.vue'), meta: { title: 'Work Status' } },
                { path: 'profile', component: () => import('../views/ProfileView.vue'), meta: { title: 'My Profile' } },
                { path: 'users', component: () => import('../views/UsersView.vue'), meta: { title: 'Users', admin: true } },
            ],
        },
        { path: '/:pathMatch(.*)*', component: () => import('../views/NotFoundView.vue') },
    ],
});

router.beforeEach((to) => {
    if (to.matched.some((r) => r.meta.auth) && !isLoggedIn()) {
        return { path: '/login', query: { next: to.fullPath } };
    }
    if (to.meta.guest && isLoggedIn()) return '/dashboard';
    if (to.matched.some((r) => r.meta.admin) && !isAdmin()) return '/dashboard';
});

export default router;
