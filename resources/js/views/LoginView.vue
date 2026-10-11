<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '../components/Icon.vue';
import { authApi, errorMessage } from '../services/api';
import { saveSession } from '../services/auth';

const router = useRouter();
const route = useRoute();
const login = ref('');
const password = ref('');
const show = ref(false);
const loading = ref(false);
const error = ref('');

const submit = async () => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await authApi.login({ login: login.value.trim(), password: password.value });
        saveSession(data.token, data.user);
        router.replace(String(route.query.next || '/dashboard'));
    } catch (e) {
        error.value = errorMessage(e, 'Invalid credentials. Please try again.');
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="auth">
        <section class="auth-art">
            <div class="brand">
                <img src="/images/mk-electrical.png" alt="MK Electrical" @error="$event.target.style.display = 'none'" />
                <div style="font-weight: 800; letter-spacing: .05em"><span style="color: #fb7185">MK</span> ELECTRICAL</div>
            </div>
            <div>
                <h2>Run your electrical<br />business, effortlessly.</h2>
                <p>Orders, customers, stock and daily work status in one place. Built for the MK Electrical team in Sivakasi.</p>
                <div class="auth-points">
                    <div v-for="t in ['Create and track orders in seconds', 'Live stock and revenue dashboard', 'Printable customer invoices']" :key="t"><Icon name="check" :size="18" /> {{ t }}</div>
                </div>
            </div>
            <small style="opacity: .7; position: relative; z-index: 1">© {{ new Date().getFullYear() }} MK Electrical · Sivakasi, Tamil Nadu</small>
        </section>

        <section class="auth-form">
            <form class="auth-card" novalidate @submit.prevent="submit">
                <h1>Welcome back 👋</h1>
                <p class="lead">Sign in with your email or phone number.</p>

                <div v-if="error" class="alert" role="alert">{{ error }}</div>

                <div class="field">
                    <label for="login">Email or phone</label>
                    <input id="login" v-model="login" class="input" autocomplete="username" autofocus placeholder="you@mkelectrical-app.in" />
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="pw">
                        <input id="password" v-model="password" class="input" :type="show ? 'text' : 'password'" autocomplete="current-password" placeholder="••••••••" />
                        <button type="button" aria-label="Show password" @click="show = !show"><Icon :name="show ? 'eyeoff' : 'eye'" :size="18" /></button>
                    </div>
                </div>

                <button class="btn btn-primary" style="width: 100%; margin-top: 6px" :disabled="loading || !login || !password">{{ loading ? 'Signing in…' : 'Sign in' }}</button>

                <p style="text-align: center; color: var(--text-2); font-size: 14px; margin-top: 22px">
                    Are you a customer? <router-link to="/order-data" style="color: var(--brand); font-weight: 600">Place an order</router-link>
                </p>
            </form>
        </section>
    </div>
</template>
