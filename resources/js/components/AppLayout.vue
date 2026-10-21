<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from './Icon.vue';
import { authApi } from '../services/api';
import {
    applyContext, can, clearSession, currentCompany, currentYear, isLocked, session, setCompany, setYear,
} from '../services/auth';

const route = useRoute();
const router = useRouter();

const open = ref(false);
const menu = ref(false);
const theme = ref(localStorage.getItem('theme') || 'light');

watch(theme, (t) => {
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('theme', t);
});

watch(() => route.path, () => { open.value = false; menu.value = false; });

const items = [
    { label: 'Dashboard', to: '/dashboard', icon: 'home', perm: 'dashboard.view' },
    { label: 'Opening Stock', to: '/opening-stock', icon: 'clock', perm: 'opening_stock.view' },
    { label: 'Stock Adjustment', to: '/stock-adjustments', icon: 'alert', perm: 'stock_adjustments.view' },
    { label: 'Stock Summary', to: '/stock', icon: 'shield', perm: 'stock.view' },
    { label: 'Work Status', to: '/work-status', icon: 'hat', perm: 'work_status.view' },
];

const masterItems = [
    { label: 'Orders', to: '/orders', icon: 'cart', perm: 'orders.view' },
    { label: 'Sales', to: '/sales', icon: 'wallet', perm: 'sales.view' },
    { label: 'Purchase', to: '/purchases', icon: 'box', perm: 'purchases.view' },
    { label: 'Customers', to: '/customers', icon: 'users', perm: 'customers.view' },
    { label: 'Products', to: '/products', icon: 'box', perm: 'products.view' },
];

const visible = computed(() => items.filter((i) => can(i.perm)));
const masterVisible = computed(() => masterItems.filter((i) => can(i.perm)));
const title = computed(() => route.meta.title || 'MK Electrical');
const initials = computed(() => (session.user?.name || 'U').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase());
const company = computed(() => currentCompany());
const year = computed(() => currentYear());
const viewKey = computed(() => `${session.companyId}-${session.yearId}`);   // screens reload when company / year changes

// fresh permissions + lock state every time the app opens
onMounted(async () => {
    try { applyContext((await authApi.me()).data); } catch { /* 401 is handled by the api layer */ }
});

const logout = async () => {
    try { await authApi.logout(); } catch { /* token may already be invalid */ }
    clearSession();
    router.replace('/login');
};
</script>

<template>
    <div class="mk-shell">
        <div class="mk-overlay" :class="{ show: open }" @click="open = false" />

        <aside class="mk-sidebar" :class="{ open }">
            <div class="mk-brand">
                <img class="mk-brand-logo" src="/images/mk-electrical.png" alt="MK" @error="$event.target.style.display = 'none'" />
                <div>
                    <div class="mk-brand-name">{{ (company?.name || 'MK Electrical').toUpperCase().slice(0, 18) }}</div>
                    <div class="mk-brand-sub">{{ company?.code }} · BUSINESS SUITE</div>
                </div>
            </div>

            <nav class="mk-nav">
                <div class="mk-nav-label">WORKSPACE</div>
                <router-link v-for="m in visible" :key="m.to" :to="m.to" class="mk-link" active-class="active"><Icon :name="m.icon" /> {{ m.label }}</router-link>
                
                <div class="mk-nav-label">MASTERS</div>
                <router-link v-for="m in masterVisible" :key="m.to" :to="m.to" class="mk-link" active-class="active"><Icon :name="m.icon" /> {{ m.label }}</router-link>

                <div class="mk-nav-label">ACCOUNT</div>
                <router-link to="/profile" class="mk-link" active-class="active"><Icon name="user" /> My Profile</router-link>
            </nav>

            <div class="mk-side-foot">
                <button class="mk-link" style="width: 100%; border: 0; background: none; cursor: pointer" @click="logout"><Icon name="logout" /> Log out</button>
            </div>
        </aside>

        <div class="mk-main">
            <header class="mk-topbar">
                <button class="mk-icon-btn mk-burger" aria-label="Menu" @click="open = true"><Icon name="menu" /></button>
                <h1>{{ title }}</h1>

                <div class="switchers">
                    <select v-if="session.companies.length > 1" class="select" :value="session.companyId" aria-label="Company" @change="setCompany(Number($event.target.value))">
                        <option v-for="c in session.companies" :key="c.id" :value="c.id">{{ c.code }} · {{ c.name }}</option>
                    </select>
                    <select class="select" :value="session.yearId" aria-label="Account year" @change="setYear(Number($event.target.value))">
                        <option v-for="y in session.years" :key="y.id" :value="y.id">{{ y.label }}{{ y.is_current ? ' ★' : '' }}</option>
                    </select>
                </div>

                <button class="mk-icon-btn" aria-label="Toggle theme" @click="theme = theme === 'dark' ? 'light' : 'dark'">
                    <Icon :name="theme === 'dark' ? 'sun' : 'moon'" />
                </button>

                <div class="mk-user">
                    <button class="mk-user-btn" @click="menu = !menu">
                        <span class="mk-avatar">{{ initials }}</span>
                        <span class="mk-user-meta"><b>{{ session.user?.name }}</b><small>{{ company?.role?.name || 'User' }}</small></span>
                    </button>
                    <div v-if="menu" class="mk-menu">
                        <button @click="router.push('/profile')">My profile</button>
                        <button class="danger" @click="logout">Log out</button>
                    </div>
                </div>
            </header>

            <main class="mk-page">
                <div v-if="isLocked()" class="lock-banner"><Icon name="alert" :size="18" /> {{ year?.label }} is locked for {{ company?.name }}. You can view data but not change it.</div>
                <router-view :key="viewKey" />
            </main>
        </div>
    </div>
</template>
