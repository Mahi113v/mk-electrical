<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import { customerApi, errorMessage, inr, orderApi, productApi } from '../services/api';
import { isAdmin, session } from '../services/auth';
import { toast } from '../services/toast';

const route = useRoute();
const router = useRouter();
const STATUSES = ['pending', 'upcoming', 'active', 'completed'];
const today = () => new Date().toISOString().slice(0, 10);
const blank = () => ({
    id: null, order_name: '', order_date: today(), status: 'pending', customer_name: '', customer_phone: '',
    whatsapp: '', alternate_phone: '', area: '', city: '', address: '', referral: 'Mathan', notes: '',
    service_charge: 0, items: [{ product_id: '', quantity: 1, rate: 0 }],
});

const orders = ref([]);
const products = ref([]);
const customers = ref([]);
const loading = ref(true);
const search = ref('');
const statusFilter = ref('');
const form = ref(null);
const saving = ref(false);

const canManage = (o) => isAdmin() || o.user_id === session.user?.id;

const shown = computed(() => {
    const s = search.value.toLowerCase();
    return orders.value.filter((o) =>
        (!statusFilter.value || o.status === statusFilter.value) &&
        `${o.order_name} ${o.customer_name} ${o.customer_phone}`.toLowerCase().includes(s));
});

const itemsTotal = computed(() => (form.value?.items || []).reduce((s, i) => s + Number(i.quantity || 0) * Number(i.rate || 0), 0));
const grandTotal = computed(() => itemsTotal.value + Number(form.value?.service_charge || 0));

const load = async () => {
    try { orders.value = (await orderApi.getAll()).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};

onMounted(async () => {
    load();
    try {
        products.value = (await productApi.getAll()).data.data;
        customers.value = (await customerApi.getAll()).data.data;
    } catch (e) { toast.error(errorMessage(e)); }
    if (route.query.new) { form.value = blank(); router.replace({ query: {} }); }
});

/* ---- form helpers ---- */
const pickProduct = (row) => {
    const p = products.value.find((x) => x.id === Number(row.product_id));
    if (p) row.rate = Number(p.rate);
};
const addRow = () => form.value.items.push({ product_id: '', quantity: 1, rate: 0 });
const removeRow = (i) => form.value.items.length > 1 && form.value.items.splice(i, 1);

// choosing an existing customer name fills in the rest
const fillCustomer = () => {
    const c = customers.value.find((x) => x.customer_name.toLowerCase() === form.value.customer_name.toLowerCase());
    if (!c) return;
    Object.assign(form.value, {
        customer_phone: c.customer_phone, whatsapp: c.whatsapp || '', alternate_phone: c.alternate_phone || '',
        area: c.area || '', city: c.city || '', address: c.address || '',
    });
};

const edit = async (o) => {
    try {
        const full = (await orderApi.get(o.id)).data.data;
        form.value = {
            ...blank(), ...full,
            order_date: (full.order_date || '').slice(0, 10),
            service_charge: Number(full.service_charge),
            items: full.items.map((i) => ({ product_id: i.product_id, quantity: Number(i.quantity), rate: Number(i.rate) })),
        };
    } catch (e) { toast.error(errorMessage(e)); }
};

const save = async () => {
    const f = form.value;
    const items = f.items.filter((i) => i.product_id && Number(i.quantity) > 0)
        .map((i) => ({ product_id: Number(i.product_id), quantity: Number(i.quantity), rate: Number(i.rate) }));
    if (!items.length) return toast.error('Add at least one product');

    saving.value = true;
    try {
        const payload = {
            order_name: f.order_name, order_date: f.order_date, status: f.status, customer_name: f.customer_name,
            customer_phone: f.customer_phone, whatsapp: f.whatsapp, alternate_phone: f.alternate_phone, area: f.area,
            city: f.city, address: f.address, referral: f.referral, notes: f.notes,
            service_charge: Number(f.service_charge || 0), items,
        };
        const { data } = f.id ? await orderApi.update(f.id, payload) : await orderApi.create(payload);
        toast.success(data.message);
        form.value = null;
        load();
        productApi.getAll().then((r) => (products.value = r.data.data));   // refresh stock numbers
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const changeStatus = async (o, status) => {
    try { await orderApi.updateStatus(o.id, status); o.status = status; toast.success('Status updated'); }
    catch (e) { toast.error(errorMessage(e)); }
};

const print = async (o) => {
    try { await orderApi.print(o.id); } catch (e) { toast.error(errorMessage(e, 'Could not open invoice')); }
};

const remove = async (o) => {
    if (!window.confirm(`Delete order "${o.order_name}"? Stock will be restored.`)) return;
    try { toast.success((await orderApi.delete(o.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3>Orders</h3><p class="sub">{{ orders.length }} orders</p></div>
            <input v-model="search" class="input" style="max-width: 260px" placeholder="Search order, customer, phone…" />
            <button class="btn btn-primary" @click="form = blank()"><Icon name="plus" :size="16" /> New order</button>
        </div>

        <div class="chips" style="margin-bottom: 14px">
            <button class="chip" :class="{ on: !statusFilter }" @click="statusFilter = ''">All</button>
            <button v-for="s in STATUSES" :key="s" class="chip" :class="{ on: statusFilter === s }" @click="statusFilter = s">{{ s }}</button>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>#</th><th>Order</th><th>Customer</th><th>Date</th><th>Status</th><th class="num">Total</th><th /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="7" class="empty">Loading…</td></tr>
                    <tr v-else-if="!shown.length"><td colspan="7" class="empty">No orders found</td></tr>
                    <tr v-for="o in shown" :key="o.id">
                        <td>{{ o.id }}</td>
                        <td><b>{{ o.order_name }}</b> <span v-if="o.source === 'web'" class="badge upcoming">web</span><br /><small style="color: var(--muted)">{{ o.user?.name || 'Customer web form' }}</small></td>
                        <td>{{ o.customer_name }}<br /><small style="color: var(--muted)">{{ o.customer_phone }}</small></td>
                        <td>{{ (o.order_date || '').slice(0, 10) }}</td>
                        <td>
                            <select class="select" style="padding: 6px 10px; width: auto" :value="o.status" :disabled="!canManage(o)" @change="changeStatus(o, $event.target.value)">
                                <option v-for="s in STATUSES" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </td>
                        <td class="num"><b>{{ inr(o.total) }}</b></td>
                        <td>
                            <div class="row-actions">
                                <button class="btn btn-ghost btn-sm" title="Print invoice" @click="print(o)"><Icon name="print" :size="16" /></button>
                                <template v-if="canManage(o)">
                                    <button class="btn btn-ghost btn-sm" title="Edit" @click="edit(o)"><Icon name="edit" :size="16" /></button>
                                    <button class="btn btn-danger btn-sm" title="Delete" @click="remove(o)"><Icon name="trash" :size="16" /></button>
                                </template>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="form" :title="form.id ? `Edit order #${form.id}` : 'New order'" wide @close="form = null">
            <form @submit.prevent="save">
                <div class="form-grid">
                    <div class="field"><label>Order name *</label><input v-model="form.order_name" class="input" required placeholder="e.g. CCTV installation" /></div>
                    <div class="field"><label>Order date</label><input v-model="form.order_date" class="input" type="date" /></div>
                    <div class="field">
                        <label>Customer name *</label>
                        <input v-model="form.customer_name" class="input" list="cust-list" required autocomplete="off" @change="fillCustomer" />
                        <datalist id="cust-list"><option v-for="c in customers" :key="c.id" :value="c.customer_name" /></datalist>
                    </div>
                    <div class="field"><label>Phone *</label><input v-model="form.customer_phone" class="input" maxlength="15" required /></div>
                    <div class="field"><label>WhatsApp</label><input v-model="form.whatsapp" class="input" maxlength="15" /></div>
                    <div class="field"><label>Alternate phone</label><input v-model="form.alternate_phone" class="input" maxlength="15" /></div>
                    <div class="field"><label>Area</label><input v-model="form.area" class="input" /></div>
                    <div class="field"><label>City</label><input v-model="form.city" class="input" /></div>
                    <div class="field full"><label>Address</label><input v-model="form.address" class="input" /></div>
                </div>

                <label style="font-size: 13px; font-weight: 600; color: var(--text-2)">Items</label>
                <div style="margin-top: 8px">
                    <div v-for="(row, i) in form.items" :key="i" class="item-row">
                        <select v-model="row.product_id" class="select" @change="pickProduct(row)">
                            <option value="">Select product…</option>
                            <option v-for="p in products" :key="p.id" :value="p.id" :disabled="p.stock === 0">{{ p.product_name }}{{ p.stock !== null ? ` (stock ${p.stock})` : '' }}</option>
                        </select>
                        <input v-model.number="row.quantity" class="input" type="number" min="0.01" step="any" title="Quantity" />
                        <input v-model.number="row.rate" class="input" type="number" min="0" step="0.01" title="Rate" />
                        <div style="text-align: right; font-weight: 600">{{ inr(row.quantity * row.rate) }}</div>
                        <button type="button" class="btn btn-danger btn-sm" :disabled="form.items.length === 1" @click="removeRow(i)">×</button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="addRow"><Icon name="plus" :size="14" /> Add item</button>
                </div>

                <div class="form-grid" style="margin-top: 16px">
                    <div class="field"><label>Service charge (₹)</label><input v-model.number="form.service_charge" class="input" type="number" min="0" step="0.01" /></div>
                    <div class="field"><label>Status</label>
                        <select v-model="form.status" class="select"><option v-for="s in STATUSES" :key="s" :value="s">{{ s }}</option></select>
                    </div>
                    <div class="field"><label>Referral</label><input v-model="form.referral" class="input" /></div>
                    <div class="field"><label>Notes</label><input v-model="form.notes" class="input" /></div>
                </div>

                <div class="totals">
                    <div><span>Items total</span><span>{{ inr(itemsTotal) }}</span></div>
                    <div><span>Service charge</span><span>{{ inr(form.service_charge) }}</span></div>
                    <div class="grand"><span>Total</span><span>{{ inr(grandTotal) }}</span></div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : form.id ? 'Update order' : 'Create order' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
