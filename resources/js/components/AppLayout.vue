<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from './Icon.vue';
import { authApi } from '../services/api';
import { clearSession, isAdmin, session } from '../services/auth';

const route = useRoute();
const router = useRouter();

const open = ref(false);
const menu = ref(false);
const theme = ref(localStorage.getItem('theme') || 'light');

watch(theme, (t) => {
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('theme', t);
});
// close drawer / menu on navigation
watch(() => route.path, () => { open.value = false; menu.value = false; });

const workspace = [
    { label: 'Dashboard', to: '/dashboard', icon: 'home' },
    { label: 'Orders', to: '/orders', icon: 'cart' },
    { label: 'Customers', to: '/customers', icon: 'users' },
    { label: 'Products', to: '/products', icon: 'box' },
    { label: 'Work Status', to: '/work-status', icon: 'hat' },
];

const title = computed(() => route.meta.title || 'MK Electrical');
const initials = computed(() => (session.user?.name || 'U').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase());

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
                    <div class="mk-brand-name"><b>MK</b> ELECTRICAL</div>
                    <div class="mk-brand-sub">BUSINESS SUITE</div>
                </div>
            </div>

            <nav class="mk-nav">
                <div class="mk-nav-label">WORKSPACE</div>
                <router-link v-for="m in workspace" :key="m.to" :to="m.to" class="mk-link" active-class="active"><Icon :name="m.icon" /> {{ m.label }}</router-link>

                <template v-if="isAdmin()">
                    <div class="mk-nav-label">ADMIN</div>
                    <router-link to="/users" class="mk-link" active-class="active"><Icon name="shield" /> Users</router-link>
                </template>

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

                <button class="mk-icon-btn" aria-label="Toggle theme" @click="theme = theme === 'dark' ? 'light' : 'dark'">
                    <Icon :name="theme === 'dark' ? 'sun' : 'moon'" />
                </button>

                <div class="mk-user">
                    <button class="mk-user-btn" @click="menu = !menu">
                        <span class="mk-avatar">{{ initials }}</span>
                        <span class="mk-user-meta"><b>{{ session.user?.name }}</b><small>{{ isAdmin() ? 'Admin' : 'Staff' }}</small></span>
                    </button>
                    <div v-if="menu" class="mk-menu">
                        <button @click="router.push('/profile')">My profile</button>
                        <button class="danger" @click="logout">Log out</button>
                    </div>
                </div>
            </header>

            <main class="mk-page"><router-view /></main>
        </div>
    </div>
</template>
