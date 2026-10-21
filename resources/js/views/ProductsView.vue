<script setup>
import { computed, onMounted, ref } from 'vue';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import { errorMessage, inr, productApi, qty } from '../services/api';
import { canWrite, currentYear } from '../services/auth';
import { printRegister } from '../services/print';
import { toast } from '../services/toast';

const admin = canWrite('products.manage');
const blank = () => ({ id: null, product_name: '', product_code: '', rate: '' });
const products = ref([]);
const loading = ref(true);
const search = ref('');
const form = ref(null);
const saving = ref(false);

const shown = computed(() => {
    const s = search.value.toLowerCase();
    return products.value.filter((p) => `${p.product_name} ${p.product_code} ${p.rate}`.toLowerCase().includes(s));
});

const load = async () => {
    try { products.value = (await productApi.getAll()).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
onMounted(load);

const printReg = () => printRegister({
    title: `Product List (stock of ${currentYear()?.label})`,
    columns: [
        { label: '#', get: (_, i) => i + 1 },
        { label: 'Code', get: (p) => p.product_code },
        { label: 'Product', get: (p) => p.product_name },
        { label: 'Rate', num: true, get: (p) => inr(p.rate) },
        { label: 'Stock', num: true, get: (p) => qty(p.stock) },
    ],
    rows: shown.value,
});

const save = async () => {
    saving.value = true;
    try {
        const f = form.value;
        const payload = { product_name: f.product_name, product_code: f.product_code || null, rate: f.rate };
        const { data } = f.id ? await productApi.update(f.id, payload) : await productApi.create(payload);
        toast.success(data.message);
        form.value = null;
        load();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const remove = async (p) => {
    if (!window.confirm(`Delete product "${p.product_name}"?`)) return;
    try { toast.success((await productApi.delete(p.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3>Products</h3><p class="sub">{{ products.length }} items · shared by all companies · stock is for the selected company and year</p></div>
            <input v-model="search" class="input" style="max-width: 260px" placeholder="Search products…" />
            <button class="btn btn-ghost" @click="printReg"><Icon name="print" :size="16" /> Print list</button>
            <button v-if="admin" class="btn btn-primary" @click="form = blank()"><Icon name="plus" :size="16" /> Add product</button>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>#</th><th>Product</th><th>Code</th><th class="num">Rate</th><th class="num">Stock</th><th v-if="admin" /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="6" class="empty">Loading…</td></tr>
                    <tr v-else-if="!shown.length"><td colspan="6" class="empty">No products found</td></tr>
                    <tr v-for="(p, i) in shown" :key="p.id">
                        <td>{{ i + 1 }}</td>
                        <td><b>{{ p.product_name }}</b></td>
                        <td>{{ p.product_code }}</td>
                        <td class="num">{{ inr(p.rate) }}</td>
                        <td class="num"><span class="badge" :class="p.stock <= 0 ? 'off' : p.stock <= 5 ? 'pending' : 'ok'">{{ qty(p.stock) }}</span></td>
                        <td v-if="admin">
                            <div class="row-actions">
                                <button class="btn btn-ghost btn-sm" title="Edit" @click="form = { ...p }"><Icon name="edit" :size="16" /></button>
                                <button class="btn btn-danger btn-sm" title="Delete" @click="remove(p)"><Icon name="trash" :size="16" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal v-if="form" :title="form.id ? 'Edit product' : 'Add product'" @close="form = null">
            <form @submit.prevent="save">
                <div class="field"><label>Product name *</label><input v-model="form.product_name" class="input" required /></div>
                <div class="field"><label>Product code {{ form.id ? '*' : '' }}</label><input v-model="form.product_code" class="input" :required="!!form.id" :placeholder="form.id ? '' : 'leave empty - created automatically (PRD-00001)'" /></div>
                <div class="field"><label>Selling rate (₹) *</label><input v-model="form.rate" class="input" type="number" min="0" step="0.01" required /></div>
                <p class="sub">Stock is not typed here. Add it with Opening Stock, Purchase or Stock Adjustment.</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
