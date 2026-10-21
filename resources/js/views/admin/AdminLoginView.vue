<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import Icon from '../../components/Icon.vue';
import { authApi, errorMessage } from '../../services/api';
import { applyContext, clearSession } from '../../services/auth';

/* ADMIN LOGIN - only at /admin. Product admin and company admin. Nothing on the normal pages links here. */
const router = useRouter();
const login = ref('');
const password = ref('');
const show = ref(false);
const loading = ref(false);
const error = ref('');

const submit = async () => {
    loading.value = true;
    error.value = '';
    try {
        clearSession();
        const { data } = await authApi.adminLogin({ login: login.value.trim(), password: password.value });
        applyContext(data);
        router.replace('/admin/dashboard');
    } catch (e) {
        error.value = errorMessage(e, 'Sign in failed. Please try again.');
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="admin-login">
        <form class="admin-card" novalidate @submit.prevent="submit">
            <div class="admin-logo"><Icon name="shield" :size="30" /></div>
            <h1>Admin Console</h1>
            <p class="lead">Product admin and company admin sign in here.</p>

            <div v-if="error" class="alert" role="alert">{{ error }}</div>

            <div class="field">
                <label for="a-login">Email or phone</label>
                <input id="a-login" v-model="login" class="input" autocomplete="username" autofocus placeholder="admin@company.in" />
            </div>
            <div class="field">
                <label for="a-password">Password</label>
                <div class="pw">
                    <input id="a-password" v-model="password" class="input" :type="show ? 'text' : 'password'" autocomplete="current-password" placeholder="••••••••" />
                    <button type="button" aria-label="Show password" @click="show = !show"><Icon :name="show ? 'eyeoff' : 'eye'" :size="18" /></button>
                </div>
            </div>
            <button class="btn btn-primary" style="width: 100%; margin-top: 6px" :disabled="loading || !login || !password">{{ loading ? 'Signing in…' : 'Sign in' }}</button>
        </form>
    </div>
</template>
