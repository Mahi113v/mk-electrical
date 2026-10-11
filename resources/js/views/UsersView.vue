<script setup>
import { computed, onMounted, ref } from 'vue';
import Modal from '../components/Modal.vue';
import { errorMessage, userApi } from '../services/api';
import { session } from '../services/auth';
import { toast } from '../services/toast';

const blank = () => ({ id: null, name: '', email: '', phone: '', password: '', is_admin: false, is_active: true });
const users = ref([]);
const loading = ref(true);
const form = ref(null);
const saving = ref(false);
const search = ref('');

const me = computed(() => session.user);
const shown = computed(() => users.value.filter((u) => `${u.name} ${u.email} ${u.phone}`.toLowerCase().includes(search.value.toLowerCase())));

const load = async () => {
    try { users.value = (await userApi.getAll()).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
onMounted(load);

const edit = (u) => (form.value = { ...blank(), ...u, email: u.email || '', phone: u.phone || '', password: '' });

const save = async () => {
    saving.value = true;
    try {
        const f = form.value;
        const payload = { name: f.name, email: f.email || null, phone: f.phone || null, is_admin: f.is_admin, is_active: f.is_active };
        if (f.password) payload.password = f.password;
        const { data } = f.id ? await userApi.update(f.id, payload) : await userApi.create({ ...payload, password: f.password });
        toast.success(data.message);
        form.value = null;
        load();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const toggle = async (u) => {
    try {
        await userApi.update(u.id, { name: u.name, email: u.email, phone: u.phone, is_admin: u.is_admin, is_active: !u.is_active });
        load();
    } catch (e) { toast.error(errorMessage(e)); }
};

const remove = async (u) => {
    if (!window.confirm(`Delete ${u.name}?`)) return;
    try { toast.success((await userApi.delete(u.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow">
                <h3>Team members</h3>
                <p class="sub">Admins manage everything. Staff can create orders and customers.</p>
            </div>
            <input v-model="search" class="input" style="max-width: 240px" placeholder="Search…" />
            <button class="btn btn-primary" @click="form = blank()">+ Add user</button>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="6" class="empty">Loading…</td></tr>
                    <tr v-else-if="!shown.length"><td colspan="6" class="empty">No users found</td></tr>
                    <tr v-for="u in shown" :key="u.id">
                        <td><b>{{ u.name }}</b> <small v-if="u.id === me?.id" style="color: var(--muted)">(you)</small></td>
                        <td>{{ u.email || '—' }}</td>
                        <td>{{ u.phone || '—' }}</td>
                        <td><span class="badge" :class="{ active: u.is_admin }">{{ u.role }}</span></td>
                        <td><span class="badge" :class="u.is_active ? 'ok' : 'off'">{{ u.is_active ? 'Active' : 'Disabled' }}</span></td>
                        <td>
                            <div class="row-actions">
                                <button class="btn btn-ghost btn-sm" @click="edit(u)">Edit</button>
                                <template v-if="u.id !== me?.id">
                                    <button class="btn btn-ghost btn-sm" @click="toggle(u)">{{ u.is_active ? 'Disable' : 'Enable' }}</button>
                                    <button class="btn btn-danger btn-sm" @click="remove(u)">Delete</button>
                                </template>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="form" :title="form.id ? 'Edit user' : 'Add user'" @close="form = null">
            <form @submit.prevent="save">
                <div class="field"><label>Full name *</label><input v-model="form.name" class="input" required /></div>
                <div class="field"><label>Email</label><input v-model="form.email" class="input" type="email" /></div>
                <div class="field"><label>Phone</label><input v-model="form.phone" class="input" /></div>
                <div class="field">
                    <label>{{ form.id ? 'New password (leave empty to keep)' : 'Password * (min 8 characters)' }}</label>
                    <input v-model="form.password" class="input" type="password" minlength="8" :required="!form.id" autocomplete="new-password" />
                </div>
                <label class="check" style="margin-bottom: 10px"><input v-model="form.is_admin" type="checkbox" :disabled="form.id === me?.id" /> Admin access</label>
                <label class="check"><input v-model="form.is_active" type="checkbox" :disabled="form.id === me?.id" /> Account active</label>
                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
