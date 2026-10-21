<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import DateRange from '../components/DateRange.vue';
import { fmtDate, inr as inrP, printDocument, printRegister, qty as qtyP } from '../services/print';
import { customerApi, errorMessage, inr, productApi, qty, saleApi } from '../services/api';
import { canWrite, currentCompany, currentYear, defaultDate, defaultRange } from '../services/auth';
import { toast } from '../services/toast';

const route = useRoute();
const router = useRouter();
const canCreate = canWrite('sales.create');
const canDelete = canWrite('sales.delete');
const negativeOk = () => !!currentCompany()?.allow_negative_stock;

const blankRow = () => ({ product_id: '', quantity: 1, rate: 0 });
const blank = () => ({ id: null, sale_date: defaultDate(), customer_name: '', reference: '', payment_mode: 'cash', notes: '', items: [blankRow()] });

const sales = ref([]);
const products = ref([]);
const customers = ref([]);
const loading = ref(true);
const search = ref('');
const range = ref(defaultRange());   // account year start -> today
const form = ref(null);
const saving = ref(false);

const total = computed(() => (form.value?.items || []).reduce((s, i) => s + Number(i.quantity || 0) * Number(i.rate || 0), 0));

const load = async () => {
    try { sales.value = (await saleApi.getAll({ search: search.value || undefined, from: range.value.from, to: range.value.to })).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};

onMounted(async () => {
    load();
    try {
        products.value = (await productApi.getAll()).data.data;
        customers.value = (await customerApi.getAll()).data.data;
    } catch (e) { toast.error(errorMessage(e)); }
    if (route.query.new && canCreate) { form.value = blank(); router.replace({ query: {} }); }
});

const pickProduct = (row) => {
    const p = products.value.find((x) => x.id === Number(row.product_id));
    if (p) row.rate = Number(p.rate);
};
const stockOf = (id) => products.value.find((p) => p.id === Number(id))?.stock ?? 0;

const edit = (s) => {
    form.value = {
        id: s.id, sale_date: (s.sale_date || '').slice(0, 10), customer_name: s.customer_name, reference: s.reference,
        payment_mode: s.payment_mode || 'cash', notes: s.notes || '',
        items: s.items.map((i) => ({ product_id: i.product_id, quantity: Number(i.quantity), rate: Number(i.rate) })),
    };
};

const save = async () => {
    const f = form.value;
    const items = f.items.filter((i) => i.product_id && Number(i.quantity) > 0).map((i) => ({ product_id: Number(i.product_id), quantity: Number(i.quantity), rate: Number(i.rate) }));
    if (!items.length) return toast.error('Add at least one product');

    saving.value = true;
    try {
        const c = customers.value.find((x) => x.customer_name.toLowerCase() === f.customer_name.trim().toLowerCase());
        const payload = {
            sale_date: f.sale_date, customer_id: c?.id || null, customer_name: f.customer_name || null, reference: f.reference,
            payment_mode: f.payment_mode || null, notes: f.notes || null, items,
        };
        const { data } = f.id ? await saleApi.update(f.id, payload) : await saleApi.create(payload);
        toast.success(data.message);
        form.value = null;
        load();
        productApi.getAll().then((r) => (products.value = r.data.data));
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const lines = (s) => s.items.map((i) => `${i.product?.product_name} × ${qtyP(i.quantity)}`).join(', ');

const printReg = () => printRegister({
    title: 'Sales Register', period: range.value,
    columns: [
        { label: 'Sale no', get: (s) => s.sale_no },
        { label: 'Date', get: (s) => fmtDate(s.sale_date) },
        { label: 'Customer', get: (s) => s.customer_name },
        { label: 'Reference (given to)', get: (s) => s.reference },
        { label: 'Payment', get: (s) => s.payment_mode || '' },
        { label: 'Products', get: lines },
        { label: 'Total', num: true, get: (s) => inrP(s.total) },
    ],
    rows: sales.value,
    footer: ['', '', '', '', '', 'Total', inrP(sales.value.reduce((t, s) => t + Number(s.total), 0))],
});

const printBill = (s) => printDocument({
    title: 'Sales Bill', number: s.sale_no, date: s.sale_date,
    left: [['Customer', s.customer_name], ['Given to (reference)', s.reference]],
    right: [['Payment', s.payment_mode]],
    columns: [
        { label: '#', get: (_, i) => i + 1 },
        { label: 'Product', get: (i) => i.product?.product_name },
        { label: 'Qty', num: true, get: (i) => qtyP(i.quantity) },
        { label: 'Rate', num: true, get: (i) => inrP(i.rate) },
        { label: 'Amount', num: true, get: (i) => inrP(i.amount) },
    ],
    rows: s.items, totals: [['Total', inrP(s.total)]], notes: s.notes,
    signatures: ['Received by', 'Authorised signature'],
});

const remove = async (s) => {
    if (!window.confirm(`Delete sale ${s.sale_no}? The stock will come back.`)) return;
    try { toast.success((await saleApi.delete(s.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3>Sales</h3><p class="sub">Direct sales - no order needed. Note who the goods were given to in Reference.</p></div>
            <input v-model="search" class="input" style="max-width: 240px" placeholder="Search customer, reference…" @keyup.enter="load" />
            <button class="btn btn-ghost" @click="printReg"><Icon name="print" :size="16" /> Print register</button>
            <button v-if="canCreate" class="btn btn-primary" @click="form = blank()"><Icon name="plus" :size="16" /> New sale</button>
        </div>

        <DateRange v-model="range" @change="load" />

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>Sale no</th><th>Date</th><th>Customer</th><th>Reference (given to)</th><th>Products</th><th class="num">Total</th><th /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="7" class="empty">Loading…</td></tr>
                    <tr v-else-if="!sales.length"><td colspan="7" class="empty">No sales in {{ currentYear()?.label }} yet</td></tr>
                    <tr v-for="s in sales" :key="s.id">
                        <td><b>{{ s.sale_no }}</b><br /><small style="color: var(--muted)">{{ s.payment_mode }}</small></td>
                        <td>{{ (s.sale_date || '').slice(0, 10) }}</td>
                        <td>{{ s.customer_name }}</td>
                        <td><span class="chip-role">{{ s.reference }}</span></td>
                        <td><span v-for="i in s.items" :key="i.id" class="chip-year">{{ i.product?.product_name }} × {{ qty(i.quantity) }}</span></td>
                        <td class="num"><b>{{ inr(s.total) }}</b></td>
                        <td>
                            <div class="row-actions">
                                <button class="btn btn-ghost btn-sm" title="Print bill" @click="printBill(s)"><Icon name="print" :size="16" /></button>
                                <button v-if="canCreate" class="btn btn-ghost btn-sm" title="Edit" @click="edit(s)"><Icon name="edit" :size="16" /></button>
                                <button v-if="canDelete" class="btn btn-danger btn-sm" title="Delete" @click="remove(s)"><Icon name="trash" :size="16" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="form" :title="form.id ? 'Edit sale' : 'New sale'" wide @close="form = null">
            <form @submit.prevent="save">
                <div class="form-grid">
                    <div class="field"><label>Date</label><input v-model="form.sale_date" class="input" type="date" :min="currentYear()?.start_date" :max="currentYear()?.end_date" /></div>
                    <div class="field">
                        <label>Customer</label>
                        <input v-model="form.customer_name" class="input" list="sale-cust" placeholder="Walk-in" autocomplete="off" />
                        <datalist id="sale-cust"><option v-for="c in customers" :key="c.id" :value="c.customer_name" /></datalist>
                    </div>
                    <div class="field"><label>Reference * (who did we give it to?)</label><input v-model="form.reference" class="input" required placeholder="e.g. Ravi - shop, Mani anna" /></div>
                    <div class="field"><label>Payment</label>
                        <select v-model="form.payment_mode" class="select"><option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="credit">Credit</option><option value="other">Other</option></select>
                    </div>
                </div>

                <label style="font-size: 13px; font-weight: 600; color: var(--text-2)">Items</label>
                <div style="margin-top: 8px">
                    <div v-for="(row, i) in form.items" :key="i" class="item-row">
                        <select v-model="row.product_id" class="select" @change="pickProduct(row)">
                            <option value="">Select product…</option>
                            <option v-for="p in products" :key="p.id" :value="p.id" :disabled="p.stock <= 0 && !negativeOk() && row.product_id !== p.id">{{ p.product_name }} (stock {{ qty(p.stock) }})</option>
                        </select>
                        <input v-model.number="row.quantity" class="input" type="number" min="0.01" step="any" title="Quantity" />
                        <input v-model.number="row.rate" class="input" type="number" min="0" step="0.01" title="Rate" />
                        <div style="text-align: right; font-weight: 600">{{ inr(row.quantity * row.rate) }}</div>
                        <button type="button" class="btn btn-danger btn-sm" :disabled="form.items.length === 1" @click="form.items.splice(i, 1)">×</button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="form.items.push(blankRow())"><Icon name="plus" :size="14" /> Add item</button>
                </div>

                <div class="field" style="margin-top: 14px"><label>Notes</label><input v-model="form.notes" class="input" /></div>
                <div class="totals"><div class="grand"><span>Total</span><span>{{ inr(total) }}</span></div></div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : form.id ? 'Update sale' : 'Save sale' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
