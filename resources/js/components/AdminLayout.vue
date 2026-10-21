<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from './Icon.vue';
import { authApi } from '../services/api';
import { applyContext, clearSession, isPlatformAdmin, session } from '../services/auth';

/*
 * Admin console. Same shell for both admins, different menu:
 *   product admin : Dashboard, Companies, Users, Roles & Access, Account Years
 *   company admin : Dashboard, Users, Roles & Access, Account Years, Company   (only his own company)
 */
const route = useRoute();
const router = useRouter();
const open = ref(false);
const menu = ref(false);
let previousTheme = 'light';

onMounted(async () => {
    previousTheme = document.documentElement.getAttribute('data-theme') || 'light';
    document.documentElement.setAttribute('data-theme', 'dark');   // the admin console is always dark
    try { applyContext((await authApi.me()).data); } catch { /* 401 is handled by the api layer */ }
});
onBeforeUnmount(() => document.documentElement.setAttribute('data-theme', previousTheme));
watch(() => route.path, () => { open.value = false; menu.value = false; });

const items = computed(() => (isPlatformAdmin()
    ? [
        { label: 'Dashboard', to: '/admin/dashboard', icon: 'home' },
        { label: 'Companies', to: '/admin/companies', icon: 'cart' },
        { label: 'Users', to: '/admin/users', icon: 'users' },
        { label: 'Roles & Access', to: '/admin/roles', icon: 'shield' },
        { label: 'Account Years', to: '/admin/account-years', icon: 'clock' },
    ]
    : [
        { label: 'Dashboard', to: '/admin/dashboard', icon: 'home' },
        { label: 'Users', to: '/admin/users', icon: 'users' },
        { label: 'Roles & Access', to: '/admin/roles', icon: 'shield' },
        { label: 'Account Years', to: '/admin/account-years', icon: 'clock' },
        { label: 'Company', to: '/admin/companies', icon: 'cart' },
    ]));

const roleLabel = computed(() => (isPlatformAdmin() ? 'Product Admin' : `Company Admin · ${session.managed.map((c) => c.code).join(', ')}`));

const logout = async () => {
    try { await authApi.logout(); } catch { /* ignore */ }
    clearSession();
    router.replace('/admin');
};
</script>

<template>
    <div class="mk-shell theme-admin">
        <div class="mk-overlay" :class="{ show: open }" @click="open = false" />

        <aside class="mk-sidebar" :class="{ open }">
            <div class="mk-brand">
                <img class="mk-brand-logo" src="/images/mk-electrical.png" alt="MK" @error="$event.target.style.display = 'none'" />
                <div>
                    <div class="mk-brand-name">MK <b>Admin</b></div>
                    <div class="mk-brand-sub" style="color: #fb7185">{{ isPlatformAdmin() ? 'Product Admin Console' : 'Company Admin Console' }}</div>
                </div>
            </div>

            <nav class="mk-nav">
                <router-link v-for="m in items" :key="m.to" :to="m.to" class="mk-link" active-class="active"><Icon :name="m.icon" /> {{ m.label }}</router-link>
                <div class="mk-nav-label">ACCOUNT</div>
                <router-link to="/admin/profile" class="mk-link" active-class="active"><Icon name="user" /> My Profile</router-link>
            </nav>

            <div class="mk-side-foot">
                <button class="mk-link" style="width: 100%; border: 0; background: none; cursor: pointer" @click="logout"><Icon name="logout" /> Log out</button>
            </div>
        </aside>

        <div class="mk-main">
            <header class="mk-topbar">
                <button class="mk-icon-btn mk-burger" aria-label="Menu" @click="open = true"><Icon name="menu" /></button>
                <h1>{{ route.meta.title }}</h1>
                <div class="mk-user">
                    <button class="mk-user-btn" @click="menu = !menu">
                        <span class="mk-avatar">{{ (session.user?.name || 'A')[0] }}</span>
                        <span class="mk-user-meta"><b>{{ session.user?.name }}</b><small>{{ roleLabel }}</small></span>
                    </button>
                    <div v-if="menu" class="mk-menu">
                        <button @click="router.push('/admin/profile')">My profile</button>
                        <button class="danger" @click="logout">Log out</button>
                    </div>
                </div>
            </header>
            <main class="mk-page"><router-view /></main>
        </div>
    </div>
</template>
