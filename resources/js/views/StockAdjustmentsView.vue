<script setup>
import { onMounted, ref } from 'vue';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import DateRange from '../components/DateRange.vue';
import { fmtDate, inr as inrP, printDocument, printRegister, qty as qtyP } from '../services/print';
import { adjustmentApi, errorMessage, productApi, qty } from '../services/api';
import { canWrite, currentYear, defaultDate, defaultRange } from '../services/auth';
import { toast } from '../services/toast';

const canCreate = canWrite('stock_adjustments.create');
const canDelete = canWrite('stock_adjustments.delete');
const REASONS = { damage: 'Damaged', lost: 'Lost / missing', found: 'Found extra', correction: 'Count correction', expired: 'Expired', returned: 'Returned', other: 'Other' };
const reasonLabel = (r) => (r === 'order_cancel' ? 'Order cancelled' : REASONS[r] || r);

const blankRow = () => ({ product_id: '', type: 'decrease', quantity: 1 });
const blank = () => ({ adjustment_date: defaultDate(), reason: 'damage', notes: '', items: [blankRow()] });

const rows = ref([]);
const products = ref([]);
const loading = ref(true);
const filter = ref('');
const range = ref(defaultRange());   // account year start -> today
const form = ref(null);
const saving = ref(false);

const load = async () => {
    try { rows.value = (await adjustmentApi.getAll({ reason: filter.value || undefined, from: range.value.from, to: range.value.to })).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
onMounted(async () => {
    load();
    try { products.value = (await productApi.getAll()).data.data; } catch (e) { toast.error(errorMessage(e)); }
});

const save = async () => {
    const f = form.value;
    const items = f.items.filter((i) => i.product_id && Number(i.quantity) > 0).map((i) => ({ product_id: Number(i.product_id), type: i.type, quantity: Number(i.quantity) }));
    if (!items.length) return toast.error('Add at least one product');
    saving.value = true;
    try {
        const { data } = await adjustmentApi.create({ adjustment_date: f.adjustment_date, reason: f.reason, notes: f.notes || null, items });
        toast.success(data.message);
        form.value = null;
        load();
        productApi.getAll().then((r) => (products.value = r.data.data));
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const change = (i) => `${i.product?.product_name} ${Number(i.quantity_change) > 0 ? '+' : ''}${qtyP(i.quantity_change)}`;

const printReg = () => printRegister({
    title: 'Stock Adjustment Register', period: range.value,
    columns: [
        { label: 'No', get: (a) => a.adjustment_no },
        { label: 'Date', get: (a) => fmtDate(a.adjustment_date) },
        { label: 'Reason', get: (a) => reasonLabel(a.reason) + (a.is_auto ? ' (automatic)' : '') },
        { label: 'Products', get: (a) => a.items.map(change).join(', ') },
        { label: 'Note', get: (a) => (a.notes || '') + (a.order ? ` · order #${a.order.id}` : '') },
    ],
    rows: rows.value,
});

const printVoucher = (a) => printDocument({
    title: 'Stock Adjustment Voucher', number: a.adjustment_no, date: a.adjustment_date,
    left: [['Reason', reasonLabel(a.reason)], ['Order', a.order ? `#${a.order.id} ${a.order.order_name}` : '']],
    right: [['Type', a.is_auto ? 'Automatic' : 'Manual']],
    columns: [
        { label: '#', get: (_, i) => i + 1 },
        { label: 'Product', get: (i) => i.product?.product_name },
        { label: 'Change', num: true, get: (i) => (Number(i.quantity_change) > 0 ? '+' : '') + qtyP(i.quantity_change) },
    ],
    rows: a.items, totals: [['Products', String(a.items.length)]], notes: a.notes,
    signatures: ['Prepared by', 'Approved by'],
});

const remove = async (a) => {
    if (!window.confirm(`Delete adjustment ${a.adjustment_no}? Stock goes back to what it was.`)) return;
    try { toast.success((await adjustmentApi.delete(a.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3>Stock adjustment</h3><p class="sub">Correct the stock (damage, lost, found...). Cancelling an order adds an automatic entry here.</p></div>
            <select v-model="filter" class="select" style="max-width: 190px" @change="load">
                <option value="">All reasons</option>
                <option value="order_cancel">Order cancelled</option>
                <option v-for="(l, k) in REASONS" :key="k" :value="k">{{ l }}</option>
            </select>
            <button class="btn btn-ghost" @click="printReg"><Icon name="print" :size="16" /> Print register</button>
            <button v-if="canCreate" class="btn btn-primary" @click="form = blank()"><Icon name="plus" :size="16" /> New adjustment</button>
        </div>

        <DateRange v-model="range" @change="load" />

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>No</th><th>Date</th><th>Reason</th><th>Products</th><th>Note</th><th /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="6" class="empty">Loading…</td></tr>
                    <tr v-else-if="!rows.length"><td colspan="6" class="empty">No adjustments in {{ currentYear()?.label }}</td></tr>
                    <tr v-for="a in rows" :key="a.id">
                        <td><b>{{ a.adjustment_no }}</b></td>
                        <td>{{ (a.adjustment_date || '').slice(0, 10) }}</td>
                        <td><span class="badge" :class="a.is_auto ? 'upcoming' : 'pending'">{{ reasonLabel(a.reason) }}</span><br /><small v-if="a.is_auto" style="color: var(--muted)">automatic</small></td>
                        <td>
                            <span v-for="i in a.items" :key="i.id" class="chip-year">{{ i.product?.product_name }}
                                <b :class="Number(i.quantity_change) < 0 ? 'neg' : ''" :style="Number(i.quantity_change) >= 0 ? 'color: var(--success)' : ''">{{ Number(i.quantity_change) > 0 ? '+' : '' }}{{ qty(i.quantity_change) }}</b></span>
                        </td>
                        <td>{{ a.notes }}<span v-if="a.order"> · order #{{ a.order.id }}</span></td>
                        <td><div class="row-actions"><button class="btn btn-ghost btn-sm" title="Print voucher" @click="printVoucher(a)"><Icon name="print" :size="16" /></button><button v-if="canDelete && !a.is_auto" class="btn btn-danger btn-sm" title="Delete" @click="remove(a)"><Icon name="trash" :size="16" /></button></div></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="form" title="New stock adjustment" wide @close="form = null">
            <form @submit.prevent="save">
                <div class="form-grid">
                    <div class="field"><label>Date</label><input v-model="form.adjustment_date" class="input" type="date" :min="currentYear()?.start_date" :max="currentYear()?.end_date" /></div>
                    <div class="field"><label>Reason *</label><select v-model="form.reason" class="select"><option v-for="(l, k) in REASONS" :key="k" :value="k">{{ l }}</option></select></div>
                </div>
                <label style="font-size: 13px; font-weight: 600; color: var(--text-2)">Products</label>
                <div style="margin-top: 8px">
                    <div v-for="(row, i) in form.items" :key="i" class="item-row adj">
                        <select v-model="row.product_id" class="select">
                            <option value="">Select product…</option>
                            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.product_name }} (stock {{ qty(p.stock) }})</option>
                        </select>
                        <select v-model="row.type" class="select"><option value="decrease">Decrease −</option><option value="increase">Increase +</option></select>
                        <input v-model.number="row.quantity" class="input" type="number" min="0.01" step="any" title="Quantity" />
                        <button type="button" class="btn btn-danger btn-sm" :disabled="form.items.length === 1" @click="form.items.splice(i, 1)">×</button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" @click="form.items.push(blankRow())"><Icon name="plus" :size="14" /> Add row</button>
                </div>
                <div class="field" style="margin-top: 14px"><label>Notes</label><input v-model="form.notes" class="input" placeholder="What happened?" /></div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Adjust stock' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
