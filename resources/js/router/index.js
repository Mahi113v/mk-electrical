import { createRouter, createWebHistory } from 'vue-router';
import { can, homePath, isAdminLoggedIn, isLoggedIn, session } from '../services/auth';

// explicit list of pages (a variable import cannot contain a sub-folder)
const pages = import.meta.glob('../views/**/*.vue');
const V = (name) => pages[`../views/${name}.vue`];

/*
 * TWO SEPARATE LOGINS
 *   /login  : company admin, manager, stock keeper, sales, staff, viewer ...   (company app)
 *   /admin  : product admin and company admin                                  (admin console)
 * There is no link from one to the other. Opening /admin/... without the admin login shows the admin login.
 */
const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', redirect: '/login' },   // opening the site always starts at the user login
        { path: '/login', component: V('LoginView') },
        { path: '/order-data', component: V('PublicOrderView') },

        /* ADMIN LOGIN: only this exact address shows it */
        { path: '/admin', component: V('admin/AdminLoginView'), meta: { adminGuest: true } },

        /* ADMIN CONSOLE */
        {
            path: '',
            component: () => import('../components/AdminLayout.vue'),
            meta: { adminAuth: true },
            children: [
                { path: '/admin/dashboard', component: V('admin/AdminDashboard'), meta: { title: 'Dashboard' } },
                { path: '/admin/companies', component: V('admin/CompaniesView'), meta: { title: 'Companies' } },
                { path: '/admin/users', component: V('admin/UsersAdminView'), meta: { title: 'Users' } },
                { path: '/admin/roles', component: V('admin/RolesView'), meta: { title: 'Roles & Access' } },
                { path: '/admin/account-years', component: V('admin/AccountYearsView'), meta: { title: 'Account Years' } },
                { path: '/admin/profile', component: V('ProfileView'), meta: { title: 'My Profile' } },
            ],
        },

        /* COMPANY APP - needs a company + year, and the permission of the page */
        {
            path: '',
            component: () => import('../components/AppLayout.vue'),
            meta: { appAuth: true },
            children: [
                { path: '/dashboard', component: V('DashboardView'), meta: { title: 'Dashboard', perm: 'dashboard.view' } },
                { path: '/orders', component: V('OrdersView'), meta: { title: 'Orders', perm: 'orders.view' } },
                { path: '/sales', component: V('SalesView'), meta: { title: 'Sales', perm: 'sales.view' } },
                { path: '/purchases', component: V('PurchasesView'), meta: { title: 'Purchase', perm: 'purchases.view' } },
                { path: '/opening-stock', component: V('OpeningStockView'), meta: { title: 'Opening Stock', perm: 'opening_stock.view' } },
                { path: '/stock-adjustments', component: V('StockAdjustmentsView'), meta: { title: 'Stock Adjustment', perm: 'stock_adjustments.view' } },
                { path: '/stock', component: V('StockSummaryView'), meta: { title: 'Stock Summary', perm: 'stock.view' } },
                { path: '/customers', component: V('CustomersView'), meta: { title: 'Customers', perm: 'customers.view' } },
                { path: '/products', component: V('ProductsView'), meta: { title: 'Products', perm: 'products.view' } },
                { path: '/work-status', component: V('WorkStatusView'), meta: { title: 'Work Status', perm: 'work_status.view' } },
                { path: '/profile', component: V('ProfileView'), meta: { title: 'My Profile' } },
            ],
        },
        { path: '/:pathMatch(.*)*', component: V('NotFoundView') },
    ],
});

router.beforeEach((to) => {
    // ----- admin console: only with the admin login, otherwise show the admin login
    if (to.matched.some((r) => r.meta.adminAuth)) {
        return isAdminLoggedIn() ? true : '/admin';
    }
    if (to.meta.adminGuest) {
        return isAdminLoggedIn() ? '/admin/dashboard' : true;
    }

    // ----- company app: only with the user login (a signed-in admin is sent back to the console)
    if (to.matched.some((r) => r.meta.appAuth)) {
        if (isAdminLoggedIn()) return '/login';   // admin session never takes over the user pages
        if (!isLoggedIn()) return { path: '/login', query: { next: to.fullPath } };
        if (!session.companyId) return '/login';

        const perm = to.meta.perm;
        if (perm && !can(perm)) {
            const home = homePath();

            return home === to.path ? false : home;
        }
    }

    if (to.path === '/login') {
        if (isLoggedIn()) return homePath();
        // an admin session does NOT redirect here - /login must always show the user login
    }
});

export default router;

