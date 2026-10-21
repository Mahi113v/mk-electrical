<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import DateRange from '../components/DateRange.vue';
import { fmtDate, inr as inrP, printDocument, printRegister, qty as qtyP } from '../services/print';
import { errorMessage, inr, productApi, purchaseApi, qty } from '../services/api';
import { canWrite, currentYear, defaultDate, defaultRange } from '../services/auth';
import { toast } from '../services/toast';

const route = useRoute();
const router = useRouter();
const canCreate = canWrite('purchases.create');
const canDelete = canWrite('purchases.delete');

const blankRow = () => ({ product_name: '', quantity: 1, rate: 0, selling_rate: 0 });
const blank = () => ({ id: null, purchase_date: defaultDate(), supplier_name: '', supplier_phone: '', invoice_no: '', notes: '', items: [blankRow()] });

const purchases = ref([]);
const products = ref([]);
const loading = ref(true);
const search = ref('');
const range = ref(defaultRange());   // account year start -> today
const form = ref(null);
const saving = ref(false);

// existing product with the same name (case-insensitive) = stock is added to it, anything else = new product
const match = (name) => products.value.find((p) => p.product_name.trim().toLowerCase() === String(name || '').trim().toLowerCase());
const total = computed(() => (form.value?.items || []).reduce((s, i) => s + Number(i.quantity || 0) * Number(i.rate || 0), 0));
const newCount = computed(() => (form.value?.items || []).filter((i) => i.product_name.trim() && !match(i.product_name)).length);

const load = async () => {
    try { purchases.value = (await purchaseApi.getAll({ search: search.value || undefined, from: range.value.from, to: range.value.to })).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
const loadProducts = async () => { products.value = (await productApi.getAll()).data.data; };

onMounted(async () => {
    load();
    try { await loadProducts(); } catch (e) { toast.error(errorMessage(e)); }
    if (route.query.new && canCreate) { form.value = blank(); router.replace({ query: {} }); }
});

const pickName = (row) => {
    const p = match(row.product_name);
    if (p) { row.selling_rate = Number(p.rate); }
};

const edit = (p) => {
    form.value = {
        id: p.id, purchase_date: (p.purchase_date || '').slice(0, 10), supplier_name: p.supplier_name, supplier_phone: p.supplier_phone || '',
        invoice_no: p.invoice_no || '', notes: p.notes || '',
        items: p.items.map((i) => ({ product_id: i.product_id, product_name: i.product?.product_name || '', quantity: Number(i.quantity), rate: Number(i.rate), selling_rate: Number(i.product?.rate || 0) })),
    };
};

const save = async () => {
    const f = form.value;
    const items = f.items.filter((i) => i.product_name.trim() && Number(i.quantity) > 0).map((i) => {
        const p = match(i.product_name);

        return p
            ? { product_id: p.id, quantity: Number(i.quantity), rate: Number(i.rate) }
            : { product_name: i.product_name.trim(), quantity: Number(i.quantity), rate: Number(i.rate), selling_rate: Number(i.selling_rate || i.rate) };
    });
    if (!items.length) return toast.error('Add at least one product');

    saving.value = true;
    try {
        const payload = { purchase_date: f.purchase_date, supplier_name: f.supplier_name, supplier_phone: f.supplier_phone || null, invoice_no: f.invoice_no || null, notes: f.notes || null, items };
        const { data } = f.id ? await purchaseApi.update(f.id, payload) : await purchaseApi.create(payload);
        const made = data.data.new_products;
        toast.success(made.length ? `${data.message}. New products: ${made.map((m) => `${m.product_name} (${m.product_code})`).join(', ')}` : data.message);
        form.value = null;
        load();
        loadProducts();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const lines = (p) => p.items.map((i) => `${i.product?.product_name} × ${qtyP(i.quantity)}`).join(', ');

const printReg = () => printRegister({
    title: 'Purchase Register', period: range.value,
    columns: [
        { label: 'Purchase no', get: (p) => p.purchase_no },
        { label: 'Date', get: (p) => fmtDate(p.purchase_date) },
        { label: 'Supplier', get: (p) => p.supplier_name },
        { label: 'Invoice', get: (p) => p.invoice_no || '' },
        { label: 'Products', get: lines },
        { label: 'Total', num: true, get: (p) => inrP(p.total) },
    ],
    rows: purchases.value,
    footer: ['', '', '', '', 'Total', inrP(purchases.value.reduce((t, p) => t + Number(p.total), 0))],
});

const printVoucher = (p) => printDocument({
    title: 'Purchase Voucher', number: p.purchase_no, date: p.purchase_date,
    left: [['Supplier', p.supplier_name], ['Phone', p.supplier_phone]],
    right: [['Supplier invoice', p.invoice_no]],
    columns: [
        { label: '#', get: (_, i) => i + 1 },
        { label: 'Product', get: (i) => `${i.product?.product_name} (${i.product?.product_code})` },
        { label: 'Qty', num: true, get: (i) => qtyP(i.quantity) },
        { label: 'Rate', num: true, get: (i) => inrP(i.rate) },
        { label: 'Amount', num: true, get: (i) => inrP(i.amount) },
    ],
    rows: p.items, totals: [['Total', inrP(p.total)]], notes: p.notes,
    signatures: ['Stock keeper', 'Authorised signature'],
});

const remove = async (p) => {
    if (!window.confirm(`Delete purchase ${p.purchase_no}? The stock it added will be removed.`)) return;
    try { toast.success((await purchaseApi.delete(p.id)).data.message); load(); loadProducts(); }
    catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3>Purchase</h3><p class="sub">Products you buy. Saving adds them to stock and to the product list.</p></div>
            <input v-model="search" class="input" style="max-width: 240px" placeholder="Search supplier, invoice…" @keyup.enter="load" />
            <button class="btn btn-ghost" @click="printReg"><Icon name="print" :size="16" /> Print register</button>
            <button v-if="canCreate" class="btn btn-primary" @click="form = blank()"><Icon name="plus" :size="16" /> Add products</button>
        </div>

        <DateRange v-model="range" @change="load" />

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>Purchase no</th><th>Date</th><th>Supplier</th><th>Products</th><th class="num">Total</th><th /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="6" class="empty">Loading…</td></tr>
                    <tr v-else-if="!purchases.length"><td colspan="6" class="empty">No purchases in {{ currentYear()?.label }} yet</td></tr>
                    <tr v-for="p in purchases" :key="p.id">
                        <td><b>{{ p.purchase_no }}</b><br /><small style="color: var(--muted)">{{ p.invoice_no || '' }}</small></td>
                        <td>{{ (p.purchase_date || '').slice(0, 10) }}</td>
                        <td>{{ p.supplier_name }}<br /><small style="color: var(--muted)">{{ p.supplier_phone || '' }}</small></td>
                        <td><span v-for="i in p.items" :key="i.id" class="chip-year">{{ i.product?.product_name }} × {{ qty(i.quantity) }}</span></td>
                        <td class="num"><b>{{ inr(p.total) }}</b></td>
                        <td>
                            <div class="row-actions">
                                <button class="btn btn-ghost btn-sm" title="Print voucher" @click="printVoucher(p)"><Icon name="print" :size="16" /></button>
                                <button v-if="canCreate" class="btn btn-ghost btn-sm" title="Edit" @click="edit(p)"><Icon name="edit" :size="16" /></button>
                                <button v-if="canDelete" class="btn btn-danger btn-sm" title="Delete" @click="remove(p)"><Icon name="trash" :size="16" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="form" :title="form.id ? 'Edit purchase' : 'Add products (purchase)'" wide @close="form = null">
            <form @submit.prevent="save">
                <div class="form-grid">
                    <div class="field"><label>Supplier *</label><input v-model="form.supplier_name" class="input" required /></div>
                    <div class="field"><label>Supplier phone</label><input v-model="form.supplier_phone" class="input" /></div>
                    <div class="field"><label>Invoice no</label><input v-model="form.invoice_no" class="input" /></div>
                    <div class="field"><label>Date</label><input v-model="form.purchase_date" class="input" type="date" :min="currentYear()?.start_date" :max="currentYear()?.end_date" /></div>
                </div>

                <label style="font-size: 13px; font-weight: 600; color: var(--text-2)">Products</label>
                <p class="sub" style="margin: 2px 0 8px">Pick an existing product to add stock to it, or type a new name - it is created with an automatic code.</p>
                <datalist id="prod-list"><option v-for="p in products" :key="p.id" :value="p.product_name" /></datalist>

                <div v-for="(row, i) in form.items" :key="i" class="item-row pur" style="margin-bottom: 10px">
                    <div>
                        <input v-model="row.product_name" class="input" list="prod-list" placeholder="Product name" autocomplete="off" @change="pickName(row)" />
                        <small v-if="row.product_name.trim()" style="display: block; margin-top: 4px">
                            <span v-if="match(row.product_name)" class="new-tag old">Existing · stock {{ qty(match(row.product_name).stock) }} · {{ match(row.product_name).product_code }}</span>
                            <span v-else class="new-tag new">New product · code created automatically</span>
                        </small>
                    </div>
                    <input v-model.number="row.quantity" class="input" type="number" min="0.01" step="any" title="Quantity" />
                    <input v-model.number="row.rate" class="input" type="number" min="0" step="0.01" title="Purchase rate" placeholder="Buy rate" />
                    <input v-model.number="row.selling_rate" class="input" type="number" min="0" step="0.01" title="Selling rate (new products)" placeholder="Sell rate" :disabled="!!match(row.product_name)" />
                    <div style="text-align: right; font-weight: 600">{{ inr(row.quantity * row.rate) }}</div>
                    <button type="button" class="btn btn-danger btn-sm" :disabled="form.items.length === 1" @click="form.items.splice(i, 1)">×</button>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" @click="form.items.push(blankRow())"><Icon name="plus" :size="14" /> Add row</button>

                <div class="field" style="margin-top: 14px"><label>Notes</label><input v-model="form.notes" class="input" /></div>
                <div class="totals"><div class="grand"><span>Total</span><span>{{ inr(total) }}</span></div></div>

                <div class="modal-actions">
                    <span v-if="newCount" class="new-tag new" style="align-self: center">{{ newCount }} new product{{ newCount > 1 ? 's' : '' }} will be created</span>
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : form.id ? 'Update' : 'Add products' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
