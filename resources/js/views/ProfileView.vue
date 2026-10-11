<script setup>
import { reactive, ref } from 'vue';
import { authApi, errorMessage } from '../services/api';
import { session, updateUser } from '../services/auth';
import { toast } from '../services/toast';

const info = reactive({ name: session.user?.name || '', email: session.user?.email || '', phone: session.user?.phone || '' });
const pw = reactive({ current_password: '', password: '', password_confirmation: '' });
const busy = ref('');

const saveInfo = async () => {
    busy.value = 'info';
    try {
        const { data } = await authApi.updateProfile({ ...info, email: info.email || null, phone: info.phone || null });
        updateUser(data.data);
        toast.success(data.message);
    } catch (e) {
        toast.error(errorMessage(e));
    } finally { busy.value = ''; }
};

const savePw = async () => {
    busy.value = 'pw';
    try {
        const { data } = await authApi.changePassword(pw);
        toast.success(data.message);
        Object.assign(pw, { current_password: '', password: '', password_confirmation: '' });
    } catch (e) {
        toast.error(errorMessage(e));
    } finally { busy.value = ''; }
};
</script>

<template>
    <div class="grid grid-2" style="align-items: start">
        <form class="card" @submit.prevent="saveInfo">
            <h3>Profile</h3>
            <p class="sub">Signed in as <b>{{ session.user?.role }}</b></p>
            <div class="field"><label>Full name</label><input v-model="info.name" class="input" required /></div>
            <div class="field"><label>Email</label><input v-model="info.email" class="input" type="email" /></div>
            <div class="field"><label>Phone</label><input v-model="info.phone" class="input" /></div>
            <button class="btn btn-primary" :disabled="busy === 'info'">{{ busy === 'info' ? 'Saving…' : 'Save changes' }}</button>
        </form>

        <form class="card" @submit.prevent="savePw">
            <h3>Change password</h3>
            <p class="sub">Use at least 8 characters. Other devices will be signed out.</p>
            <div class="field"><label>Current password</label><input v-model="pw.current_password" class="input" type="password" required autocomplete="current-password" /></div>
            <div class="field"><label>New password</label><input v-model="pw.password" class="input" type="password" required minlength="8" autocomplete="new-password" /></div>
            <div class="field"><label>Confirm new password</label><input v-model="pw.password_confirmation" class="input" type="password" required minlength="8" autocomplete="new-password" /></div>
            <button class="btn btn-primary" :disabled="busy === 'pw'">{{ busy === 'pw' ? 'Updating…' : 'Update password' }}</button>
        </form>
    </div>
</template>
