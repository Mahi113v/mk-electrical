<script setup>
import { computed, onMounted, ref } from 'vue';
import Icon from '../../components/Icon.vue';
import Modal from '../../components/Modal.vue';
import { adminApi, errorMessage } from '../../services/api';
import { isPlatformAdmin, session } from '../../services/auth';
import { toast } from '../../services/toast';

const blank = () => ({ id: null, name: '', email: '', phone: '', password: '', is_admin: false, is_active: true, companies: [], year_ids: [] });
const users = ref([]);
const companies = ref([]);
const roles = ref([]);
const years = ref([]);
const loading = ref(true);
const search = ref('');
const companyFilter = ref('');
const activeFilter = ref('');
const form = ref(null);
const saving = ref(false);

const me = computed(() => session.user);
const platform = isPlatformAdmin();   // company admin: only the users of his own company

// a role is shared (no company) or made for exactly that company
const rolesFor = (companyId) => roles.value.filter((r) => !r.company_id || r.company_id === companyId);

const load = async () => {
    try {
        users.value = (await adminApi.users({ search: search.value || undefined, company_id: companyFilter.value || undefined, active: activeFilter.value })).data.data;
    } catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};

onMounted(async () => {
    load();
    try {
        companies.value = (await adminApi.companies()).data.data;
        roles.value = (await adminApi.roles()).data.data;
        years.value = (await adminApi.years()).data.data;
    } catch (e) { toast.error(errorMessage(e)); }
});

const edit = (u) => (form.value = {
    ...blank(), id: u.id, shared: !!u.shared, name: u.name, email: u.email || '', phone: u.phone || '', is_admin: u.is_admin, is_active: u.is_active,
    companies: u.companies.map((c) => ({ company_id: c.company_id, role_id: c.role_id, is_default: c.is_default })), year_ids: [...u.year_ids],
});

const addCompany = () => {
    const used = form.value.companies.map((c) => c.company_id);
    const next = companies.value.find((c) => !used.includes(c.id));
    if (!next) return toast.info('This user already has every company');
    form.value.companies.push({ company_id: next.id, role_id: (rolesFor(next.id).find((r) => r.code === 'staff') || rolesFor(next.id)[0])?.id, is_default: !form.value.companies.length });
};
const setDefault = (i) => form.value.companies.forEach((c, k) => (c.is_default = k === i));
const removeCompany = (i) => {
    const was = form.value.companies[i].is_default;
    form.value.companies.splice(i, 1);
    if (was && form.value.companies.length) form.value.companies[0].is_default = true;
};
const toggleYear = (id) => {
    const l = form.value.year_ids;
    l.includes(id) ? l.splice(l.indexOf(id), 1) : l.push(id);
};

const save = async () => {
    saving.value = true;
    try {
        const f = form.value;
        const payload = { name: f.name, email: f.email || null, phone: f.phone || null, is_active: f.is_active, companies: f.companies, year_ids: f.year_ids };
        if (platform) payload.is_admin = f.is_admin;   // only the product admin makes product admins
        if (f.password) payload.password = f.password;
        const { data } = f.id ? await adminApi.updateUser(f.id, payload) : await adminApi.createUser({ ...payload, password: f.password });
        toast.success(data.message);
        form.value = null;
        load();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const toggleActive = async (u) => {
    try {
        const payload = { name: u.name, email: u.email, phone: u.phone, is_active: !u.is_active };
        if (platform) payload.is_admin = u.is_admin;
        await adminApi.updateUser(u.id, payload);
        load();
    } catch (e) { toast.error(errorMessage(e)); }
};

const remove = async (u) => {
    if (!window.confirm(`Delete ${u.name}? Their orders and products stay.`)) return;
    try { toast.success((await adminApi.deleteUser(u.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3 style="font-size: 22px">Users</h3><p class="sub">{{ platform ? 'Create users, choose which companies they can open (with a role in each) and which account years they can select.' : 'People of your company: give each one a role and the account years they can open.' }}</p></div>
            <button class="btn btn-primary" @click="form = blank(); if (!platform && companies.length) addCompany()"><Icon name="plus" :size="16" /> New User</button>
        </div>
        <div class="toolbar">
            <input v-model="search" class="input" style="max-width: 260px" placeholder="Search name, email, mobile" @keyup.enter="load" />
            <select v-model="companyFilter" class="select" style="max-width: 200px" @change="load"><option value="">All companies</option><option v-for="c in companies" :key="c.id" :value="c.id">{{ c.code }} · {{ c.name }}</option></select>
            <select v-model="activeFilter" class="select" style="max-width: 160px" @change="load"><option value="">All users</option><option value="1">Active</option><option value="0">Inactive</option></select>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>User</th><th>Mobile</th><th>Companies · Role</th><th>Year access</th><th>Last login</th><th>Status</th><th /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="7" class="empty">Loading…</td></tr>
                    <tr v-else-if="!users.length"><td colspan="7" class="empty">No users found</td></tr>
                    <tr v-for="u in users" :key="u.id">
                        <td><b>{{ u.name }}</b> <span v-if="u.is_admin" class="badge active">product admin</span> <span v-if="u.shared" class="badge upcoming" title="Also works for another company">shared</span><br /><small style="color: var(--muted)">{{ u.email }}</small></td>
                        <td>{{ u.phone || '—' }}</td>
                        <td>
                            <span v-for="c in u.companies" :key="c.company_id" class="chip-role" :class="{ default: c.is_default }">{{ c.code }} {{ c.role_name }}</span>
                            <small v-if="!u.companies.length && !u.is_admin" style="color: var(--danger)">no company - cannot sign in</small>
                        </td>
                        <td><span v-for="l in u.year_labels" :key="l" class="chip-year">{{ l }}</span><small v-if="!u.year_labels.length" style="color: var(--muted)">—</small></td>
                        <td>{{ u.last_login_at ? new Date(u.last_login_at).toLocaleString('en-IN') : 'Never' }}</td>
                        <td><span class="badge" :class="u.is_active ? 'ok' : 'off'">{{ u.is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td><div class="row-actions">
                            <button class="btn btn-ghost btn-sm" @click="edit(u)">Edit</button>
                            <template v-if="u.id !== me?.id">
                                <button v-if="platform || !u.shared" class="btn btn-ghost btn-sm" @click="toggleActive(u)">{{ u.is_active ? 'Deactivate' : 'Activate' }}</button>
                                <button v-if="platform" class="btn btn-danger btn-sm" @click="remove(u)">Delete</button>
                            </template>
                        </div></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="form" :title="form.id ? 'Edit user' : 'New user'" wide @close="form = null">
            <form @submit.prevent="save">
                <p v-if="form.shared" class="lock-banner">This person also works for another company, so only his role and access in YOUR company can be changed here.</p>
                <div class="form-grid">
                    <div class="field"><label>Full name *</label><input v-model="form.name" class="input" required :disabled="form.shared" /></div>
                    <div class="field"><label>Mobile</label><input v-model="form.phone" class="input" maxlength="15" :disabled="form.shared" /></div>
                    <div class="field"><label>Email</label><input v-model="form.email" class="input" type="email" :disabled="form.shared" /></div>
                    <div class="field"><label>{{ form.id ? 'New password (empty = keep)' : 'Password * (min 8)' }}</label><input v-model="form.password" class="input" type="password" minlength="8" :required="!form.id" :disabled="form.shared" autocomplete="new-password" /></div>
                </div>

                <label style="font-size: 13px; font-weight: 600; color: var(--text-2)">Companies and role  <small style="font-weight: 400">(★ = opens first)</small></label>
                <div style="margin-top: 8px">
                    <div v-for="(row, i) in form.companies" :key="i" class="row-sel">
                        <select v-model="row.company_id" class="select"><option v-for="c in companies" :key="c.id" :value="c.id">{{ c.code }} · {{ c.name }}</option></select>
                        <select v-model="row.role_id" class="select"><option v-for="r in rolesFor(row.company_id)" :key="r.id" :value="r.id">{{ r.name }}{{ r.company_code ? ` (${r.company_code})` : '' }}</option></select>
                        <label class="check" title="Default company"><input type="radio" name="def" :checked="row.is_default" @change="setDefault(i)" /> ★</label>
                        <button type="button" class="btn btn-danger btn-sm" @click="removeCompany(i)">×</button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="addCompany"><Icon name="plus" :size="14" /> Add company</button>
                </div>

                <label style="font-size: 13px; font-weight: 600; color: var(--text-2); display: block; margin-top: 18px">Account years this user can select at login</label>
                <div class="year-checks" style="margin-top: 8px">
                    <label v-for="y in years" :key="y.id" class="check"><input type="checkbox" :checked="form.year_ids.includes(y.id)" :disabled="form.shared" @change="toggleYear(y.id)" /> {{ y.label }}</label>
                </div>

                <div style="margin-top: 18px; display: grid; gap: 10px">
                    <label v-if="platform" class="check"><input v-model="form.is_admin" type="checkbox" :disabled="form.id === me?.id" /> Product admin (signs in at /admin and manages everything)</label>
                    <label class="check"><input v-model="form.is_active" type="checkbox" :disabled="form.id === me?.id || form.shared" /> Account active</label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
