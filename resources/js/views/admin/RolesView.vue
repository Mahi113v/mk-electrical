<script setup>
import { onMounted, ref } from 'vue';
import Icon from '../../components/Icon.vue';
import Modal from '../../components/Modal.vue';
import { adminApi, errorMessage } from '../../services/api';
import { isPlatformAdmin } from '../../services/auth';
import { toast } from '../../services/toast';

const platform = isPlatformAdmin();   // company admin: shared roles are read only, he makes roles for his own company
const companies = ref([]);
const roles = ref([]);
const catalogue = ref([]);
const loading = ref(true);
const form = ref(null);
const saving = ref(false);

const load = async () => {
    try {
        const { data } = await adminApi.roles();
        roles.value = data.data;
        catalogue.value = data.catalogue;
    } catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
onMounted(async () => {
    load();
    try { companies.value = (await adminApi.companies()).data.data; } catch { /* shown by load() */ }
});

const readOnly = () => form.value && form.value.id && form.value.editable === false;
const locked = () => readOnly() || form.value?.code === 'company_admin';

const key = (m, a) => `${m}.${a}`;
const has = (k) => form.value.permissions.includes(k);
const toggle = (k) => {
    if (locked()) return;
    const l = form.value.permissions;
    l.includes(k) ? l.splice(l.indexOf(k), 1) : l.push(k);
};
const toggleModule = (m) => {
    if (locked()) return;
    const keys = Object.keys(m.actions).map((a) => key(m.key, a));
    const all = keys.every(has);
    form.value.permissions = form.value.permissions.filter((p) => !keys.includes(p)).concat(all ? [] : keys);
};

const save = async () => {
    saving.value = true;
    try {
        const f = form.value;
        const payload = { name: f.name, description: f.description, permissions: f.permissions };
        if (!f.id) payload.company_id = f.company_id || null;
        const { data } = f.id ? await adminApi.updateRole(f.id, payload) : await adminApi.createRole(payload);
        toast.success(data.message);
        form.value = null;
        load();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const remove = async (r) => {
    if (!window.confirm(`Delete role ${r.name}?`)) return;
    try { toast.success((await adminApi.deleteRole(r.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3 style="font-size: 22px">Roles &amp; access</h3><p class="sub">Decide who can see and do what. A role is given to a user separately for each company.{{ platform ? '' : ' Shared roles are read only; make your own role for your company.' }}</p></div>
            <button class="btn btn-primary" @click="form = { id: null, code: '', name: '', description: '', company_id: platform ? '' : companies[0]?.id, permissions: [] }"><Icon name="plus" :size="16" /> New Role</button>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>Role</th><th>What it can do</th><th class="num">Users</th><th /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="4" class="empty">Loading…</td></tr>
                    <tr v-for="r in roles" :key="r.id">
                        <td><b>{{ r.name }}</b> <span v-if="r.is_system" class="badge">shared</span> <span v-else-if="r.company_code" class="badge upcoming">{{ r.company_code }} only</span><br /><small style="color: var(--muted)">{{ r.description }}</small></td>
                        <td style="max-width: 460px">
                            <span v-for="m in catalogue.filter((c) => Object.keys(c.actions).some((a) => r.permissions.includes(key(c.key, a))))" :key="m.key" class="chip-year">{{ m.label }}
                                <small>({{ Object.keys(m.actions).filter((a) => r.permissions.includes(key(m.key, a))).join(', ') }})</small></span>
                        </td>
                        <td class="num">{{ r.users_count }}</td>
                        <td><div class="row-actions">
                            <button class="btn btn-ghost btn-sm" @click="form = JSON.parse(JSON.stringify(r))">{{ r.editable ? 'Edit access' : 'View' }}</button>
                            <button v-if="r.editable && !r.is_system" class="btn btn-danger btn-sm" @click="remove(r)">Delete</button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="form" :title="form.id ? `${readOnly() ? 'Role' : 'Edit role'}: ${form.name}` : 'New role'" wide @close="form = null">
            <form @submit.prevent="save">
                <div class="form-grid">
                    <div class="field"><label>Role name *</label><input v-model="form.name" class="input" required :disabled="readOnly()" /></div>
                    <div class="field"><label>Description</label><input v-model="form.description" class="input" :disabled="readOnly()" /></div>
                    <div v-if="!form.id" class="field"><label>For company {{ platform ? '' : '*' }}</label>
                        <select v-model="form.company_id" class="select" :required="!platform">
                            <option v-if="platform" value="">All companies (shared role)</option>
                            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.code }} · {{ c.name }}</option>
                        </select>
                    </div>
                </div>
                <p v-if="readOnly()" class="sub">Shared roles are changed by the product admin only.</p>
                <p v-if="form.code === 'company_admin'" class="sub">Company Admin always has every permission.</p>
                <table class="matrix">
                    <thead><tr><th>Page</th><th>Allowed actions</th></tr></thead>
                    <tbody>
                        <tr v-for="m in catalogue" :key="m.key">
                            <td style="width: 190px"><label class="perm"><input type="checkbox" :checked="Object.keys(m.actions).every((a) => has(key(m.key, a)))" :disabled="locked()" @change="toggleModule(m)" /> <b>{{ m.label }}</b></label></td>
                            <td><label v-for="(label, a) in m.actions" :key="a" class="perm"><input type="checkbox" :checked="has(key(m.key, a))" :disabled="locked()" @change="toggle(key(m.key, a))" /> {{ label }}</label></td>
                        </tr>
                    </tbody>
                </table>
                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button v-if="!readOnly()" class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save role' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
