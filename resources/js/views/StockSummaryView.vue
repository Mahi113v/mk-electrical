<script setup>
import { onMounted, ref } from 'vue';
import Icon from '../components/Icon.vue';
import DateRange from '../components/DateRange.vue';
import { errorMessage, inr, qty, stockApi } from '../services/api';
import { currentCompany, currentYear, defaultRange } from '../services/auth';
import { fmtDate, printRegister } from '../services/print';
import { toast } from '../services/toast';

const data = ref(null);
const search = ref('');
const loading = ref(true);
const range = ref(defaultRange());   // account year start -> today

/** Stock register: opening at the FROM date, movements in the period, closing at the TO date */
const load = async () => {
    try { data.value = (await stockApi.summary({ search: search.value || undefined, from: range.value.from, to: range.value.to })).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
onMounted(load);

const printReg = () => printRegister({
    title: 'Stock Register', period: range.value,
    columns: [
        { label: 'Product', get: (r) => `${r.product_name} (${r.product_code})` },
        { label: `Opening ${fmtDate(range.value.from)}`, num: true, get: (r) => qty(r.opening) },
        { label: 'Purchase', num: true, get: (r) => qty(r.purchased) },
        { label: 'Sales', num: true, get: (r) => qty(r.sold) },
        { label: 'Orders', num: true, get: (r) => qty(r.ordered) },
        { label: 'Adjust', num: true, get: (r) => qty(r.adjusted) },
        { label: `Closing ${fmtDate(range.value.to)}`, num: true, get: (r) => qty(r.closing) },
        { label: 'Value', num: true, get: (r) => inr(Math.max(0, r.closing) * r.rate) },
    ],
    rows: data.value?.rows || [],
    footer: ['Total', '', '', '', '', '', qty(data.value?.totals.closing), inr(data.value?.totals.value)],
});
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow">
                <h3>Stock register · {{ currentYear()?.label }}</h3>
                <p class="sub">{{ currentCompany()?.name }} · opening + purchase − sales − orders ± adjustments for the period below</p>
            </div>
            <input v-model="search" class="input" style="max-width: 220px" placeholder="Search product…" @keyup.enter="load" />
            <button class="btn btn-ghost" @click="printReg"><Icon name="print" :size="16" /> Print register</button>
        </div>

        <DateRange v-model="range" @change="load" />

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>Product</th><th class="num">Opening</th><th class="num">Purchase</th><th class="num">Sales</th><th class="num">Orders</th><th class="num">Adjust</th><th class="num">Closing</th><th class="num">Value</th></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="8" class="empty">Loading…</td></tr>
                    <tr v-else-if="!data?.rows.length"><td colspan="8" class="empty">No products yet</td></tr>
                    <tr v-for="r in data?.rows" :key="r.product_id">
                        <td><b>{{ r.product_name }}</b><br /><small style="color: var(--muted)">{{ r.product_code }}</small></td>
                        <td class="num">{{ qty(r.opening) }}</td>
                        <td class="num">{{ qty(r.purchased) }}</td>
                        <td class="num">{{ qty(r.sold) }}</td>
                        <td class="num">{{ qty(r.ordered) }}</td>
                        <td class="num">{{ qty(r.adjusted) }}</td>
                        <td class="num"><span class="badge" :class="r.closing <= 0 ? 'off' : r.closing <= 5 ? 'pending' : 'ok'">{{ qty(r.closing) }}</span></td>
                        <td class="num">{{ inr(Math.max(0, r.closing) * r.rate) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="data">
                    <tr><td colspan="6"><b>Total</b></td><td class="num"><b>{{ qty(data.totals.closing) }}</b></td><td class="num"><b>{{ inr(data.totals.value) }}</b></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
