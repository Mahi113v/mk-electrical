<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import { customerApi, errorMessage } from '../services/api';
import { canWrite } from '../services/auth';
import { printRegister } from '../services/print';
import { toast } from '../services/toast';

const route = useRoute();
const router = useRouter();
const admin = canWrite('customers.delete');
const manage = canWrite('customers.manage');
const blank = () => ({ id: null, customer_name: '', customer_phone: '', whatsapp: '', alternate_phone: '', area: '', city: '', address: '' });
const customers = ref([]);
const loading = ref(true);
const search = ref('');
const form = ref(null);
const saving = ref(false);

const shown = computed(() => {
    const s = search.value.toLowerCase();
    return customers.value.filter((c) => `${c.customer_name} ${c.customer_phone} ${c.area} ${c.city}`.toLowerCase().includes(s));
});

const load = async () => {
    try { customers.value = (await customerApi.getAll()).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
onMounted(() => {
    load();
    if (route.query.new && manage) { form.value = blank(); router.replace({ query: {} }); }
});

const printReg = () => printRegister({
    title: 'Customer List',
    columns: [
        { label: '#', get: (_, i) => i + 1 },
        { label: 'Name', get: (c) => c.customer_name },
        { label: 'Phone', get: (c) => [c.customer_phone, c.whatsapp && c.whatsapp !== c.customer_phone ? `WA ${c.whatsapp}` : ''].filter(Boolean).join(' / ') },
        { label: 'Area', get: (c) => c.area || '' },
        { label: 'City', get: (c) => c.city || '' },
        { label: 'Address', get: (c) => c.address || '' },
        { label: 'Orders', num: true, get: (c) => c.orders_count },
    ],
    rows: shown.value,
});

const save = async () => {
    saving.value = true;
    try {
        const f = form.value;
        const { id, ...payload } = f;
        const { data } = id ? await customerApi.update(id, payload) : await customerApi.create(payload);
        toast.success(data.message);
        form.value = null;
        load();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const remove = async (c) => {
    if (!window.confirm(`Delete customer "${c.customer_name}"? Their orders are kept.`)) return;
    try { toast.success((await customerApi.delete(c.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3>Customers</h3><p class="sub">{{ customers.length }} customers · added automatically from orders</p></div>
            <input v-model="search" class="input" style="max-width: 260px" placeholder="Search name, phone, area…" />
            <button class="btn btn-ghost" @click="printReg"><Icon name="print" :size="16" /> Print list</button>
            <button v-if="manage" class="btn btn-primary" @click="form = blank()"><Icon name="plus" :size="16" /> Add customer</button>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Area</th><th>City</th><th class="num">Orders</th><th /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="7" class="empty">Loading…</td></tr>
                    <tr v-else-if="!shown.length"><td colspan="7" class="empty">No customers found</td></tr>
                    <tr v-for="(c, i) in shown" :key="c.id">
                        <td>{{ i + 1 }}</td>
                        <td><b>{{ c.customer_name }}</b></td>
                        <td><a :href="'tel:' + c.customer_phone">{{ c.customer_phone }}</a></td>
                        <td>{{ c.area || '—' }}</td>
                        <td>{{ c.city || '—' }}</td>
                        <td class="num">{{ c.orders_count }}</td>
                        <td>
                            <div class="row-actions">
                                <button v-if="manage" class="btn btn-ghost btn-sm" title="Edit" @click="form = { ...blank(), ...c }"><Icon name="edit" :size="16" /></button>
                                <button v-if="admin" class="btn btn-danger btn-sm" title="Delete" @click="remove(c)"><Icon name="trash" :size="16" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="form" :title="form.id ? 'Edit customer' : 'Add customer'" wide @close="form = null">
            <form @submit.prevent="save">
                <div class="form-grid">
                    <div class="field"><label>Customer name *</label><input v-model="form.customer_name" class="input" required /></div>
                    <div class="field"><label>Phone *</label><input v-model="form.customer_phone" class="input" maxlength="15" required /></div>
                    <div class="field"><label>WhatsApp</label><input v-model="form.whatsapp" class="input" maxlength="15" /></div>
                    <div class="field"><label>Alternate phone</label><input v-model="form.alternate_phone" class="input" maxlength="15" /></div>
                    <div class="field"><label>Area</label><input v-model="form.area" class="input" /></div>
                    <div class="field"><label>City</label><input v-model="form.city" class="input" /></div>
                    <div class="field full"><label>Address</label><textarea v-model="form.address" class="input" rows="2" /></div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
