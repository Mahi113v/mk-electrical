<script setup>
import { onMounted, ref } from 'vue';
import Icon from '../../components/Icon.vue';
import Modal from '../../components/Modal.vue';
import { adminApi, errorMessage } from '../../services/api';
import { isPlatformAdmin } from '../../services/auth';
import { toast } from '../../services/toast';

const platform = isPlatformAdmin();   // company admin: his own company only, profile fields only
const blank = () => ({ id: null, code: '', name: '', gstin: '', phone: '', email: '', address: '', city: '', state: '', pincode: '', status: 'active', allow_negative_stock: false });
const rows = ref([]);
const loading = ref(true);
const search = ref('');
const status = ref('');
const form = ref(null);
const saving = ref(false);
const usersOf = ref(null);

const load = async () => {
    try { rows.value = (await adminApi.companies({ search: search.value || undefined, status: status.value || undefined })).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
onMounted(load);

const save = async () => {
    saving.value = true;
    try {
        const { id, users_count, created_at, updated_at, ...payload } = form.value;
        const { data } = id ? await adminApi.updateCompany(id, payload) : await adminApi.createCompany(payload);
        toast.success(data.message);
        form.value = null;
        load();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const remove = async (c) => {
    if (!window.confirm(`Delete company ${c.name}?`)) return;
    try { toast.success((await adminApi.deleteCompany(c.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};

const showUsers = async (c) => {
    try {
        const { data } = await (await import('../../services/api')).api.get(`/admin/companies/${c.id}/users`);
        usersOf.value = { company: c, users: data.data };
    } catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3 style="font-size: 22px">{{ platform ? 'Companies' : 'Company' }}</h3><p class="sub">{{ platform ? 'Each company keeps its own orders, purchase, sales and stock. Products and customers are shared.' : 'Your company details and stock setting.' }}</p></div>
            <button v-if="platform" class="btn btn-primary" @click="form = blank()"><Icon name="plus" :size="16" /> New Company</button>
        </div>
        <div v-if="platform" class="toolbar">
            <input v-model="search" class="input" style="max-width: 280px" placeholder="Search code, name, GSTIN" @keyup.enter="load" />
            <select v-model="status" class="select" style="max-width: 190px" @change="load">
                <option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option><option value="inactive">Inactive</option>
            </select>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>Code</th><th>Company</th><th>GSTIN</th><th>City</th><th class="num">Users</th><th>Status</th><th /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="7" class="empty">Loading…</td></tr>
                    <tr v-else-if="!rows.length"><td colspan="7" class="empty">No companies</td></tr>
                    <tr v-for="c in rows" :key="c.id">
                        <td><b>{{ c.code }}</b></td>
                        <td><b>{{ c.name }}</b><br v-if="c.allow_negative_stock" /><small v-if="c.allow_negative_stock" style="color: var(--accent)">negative stock allowed</small></td>
                        <td>{{ c.gstin || '—' }}</td><td>{{ c.city || '—' }}</td><td class="num">{{ c.users_count }}</td>
                        <td><span class="badge" :class="c.status === 'active' ? 'ok' : 'off'">{{ c.status }}</span></td>
                        <td><div class="row-actions">
                            <button class="btn btn-ghost btn-sm" @click="form = { ...blank(), ...c }">Edit</button>
                            <button class="btn btn-ghost btn-sm" @click="showUsers(c)">Users</button>
                            <button v-if="platform" class="btn btn-danger btn-sm" @click="remove(c)">Delete</button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="form" :title="form.id ? 'Edit company' : 'New company'" wide @close="form = null">
            <form @submit.prevent="save">
                <div class="form-grid">
                    <div class="field"><label>Code * (short, unique)</label><input v-model="form.code" class="input" maxlength="10" required :disabled="!platform" style="text-transform: uppercase" /></div>
                    <div class="field"><label>Status</label><select v-model="form.status" class="select" :disabled="!platform"><option value="active">Active</option><option value="suspended">Suspended</option><option value="inactive">Inactive</option></select></div>
                    <div class="field full"><label>Company name *</label><input v-model="form.name" class="input" required /></div>
                    <div class="field"><label>GSTIN</label><input v-model="form.gstin" class="input" /></div>
                    <div class="field"><label>Phone</label><input v-model="form.phone" class="input" /></div>
                    <div class="field"><label>Email</label><input v-model="form.email" class="input" type="email" /></div>
                    <div class="field"><label>City</label><input v-model="form.city" class="input" /></div>
                    <div class="field"><label>State</label><input v-model="form.state" class="input" /></div>
                    <div class="field"><label>Pincode</label><input v-model="form.pincode" class="input" /></div>
                    <div class="field full"><label>Address</label><input v-model="form.address" class="input" /></div>
                </div>
                <label class="check"><input v-model="form.allow_negative_stock" type="checkbox" /> Allow selling / ordering more than the stock (negative stock)</label>
                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </form>
        </Modal>

        <Modal v-if="usersOf" :title="`Users of ${usersOf.company.name}`" @close="usersOf = null">
            <p v-if="!usersOf.users.length" class="empty">No user is assigned to this company.</p>
            <div v-for="u in usersOf.users" :key="u.id" style="display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid var(--border)">
                <span><b>{{ u.name }}</b><br /><small style="color: var(--muted)">{{ u.email }}</small></span>
                <span class="chip-role" :class="{ default: u.is_default }">{{ u.role }}</span>
            </div>
            <div class="modal-actions"><button class="btn btn-ghost" @click="usersOf = null">Close</button></div>
        </Modal>
    </div>
</template>
