<script setup>
import { computed, onMounted, ref } from 'vue';
import Icon from '../components/Icon.vue';
import { errorMessage, qty, stockApi } from '../services/api';
import { canWrite, currentCompany, currentYear } from '../services/auth';
import { printRegister } from '../services/print';
import { toast } from '../services/toast';

const manage = canWrite('opening_stock.manage');
const data = ref(null);
const loading = ref(true);
const fetching = ref(false);
const force = ref(false);
const search = ref('');
const summary = ref(null);
const edits = ref({});       // product_id -> text typed in the box

const load = async () => {
    try { data.value = (await stockApi.opening()).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
onMounted(load);

const rows = computed(() => (data.value?.rows || []).filter((r) => `${r.product_name} ${r.product_code}`.toLowerCase().includes(search.value.toLowerCase())));
const prev = computed(() => data.value?.previous_year);

const printReg = () => printRegister({
    title: `Opening Stock ${currentYear()?.label}`,
    period: currentYear() ? { from: currentYear().start_date, to: currentYear().end_date } : null,
    columns: [
        { label: '#', get: (_, i) => i + 1 },
        { label: 'Product', get: (r) => r.product_name },
        { label: 'Code', get: (r) => r.product_code },
        { label: 'Opening stock', num: true, get: (r) => (r.quantity === null ? '' : qty(r.quantity)) },
        { label: 'Source', get: (r) => (r.source === 'calculated' ? 'from last year' : r.source === 'manual' ? 'typed' : 'not set') },
    ],
    rows: rows.value,
    footer: ['', 'Total', '', qty(rows.value.reduce((s, r) => s + Number(r.quantity || 0), 0)), ''],
});

/** the "Fetch / Refresh stock" button: server adds up last year's purchase, sales, orders and adjustments */
const fetchStock = async () => {
    if (force.value && !window.confirm('This also replaces numbers you typed by hand. Continue?')) return;
    fetching.value = true;
    try {
        const res = await stockApi.refreshOpening(force.value);
        data.value = { year: res.data.data.year, previous_year: res.data.data.previous_year, rows: res.data.data.rows };
        summary.value = res.data.data.summary;
        edits.value = {};
        toast.success(res.data.message);
    } catch (e) { toast.error(errorMessage(e)); }
    finally { fetching.value = false; }
};

const saveOne = async (row) => {
    const text = edits.value[row.product_id];
    if (text === undefined || text === '' || Number(text) === row.quantity) { delete edits.value[row.product_id]; return; }
    try {
        data.value = (await stockApi.setOpening(row.product_id, Number(text))).data.data;
        delete edits.value[row.product_id];
        toast.success(`${row.product_name}: opening stock saved`);
    } catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow">
                <h3>Opening stock · {{ currentYear()?.label }}</h3>
                <p class="sub">{{ currentCompany()?.name }} · stock you start the year with.
                    <span v-if="prev">Fetch it from {{ prev.label }}'s purchase and sales.</span>
                    <span v-else>This is the first year - type the opening stock by hand.</span>
                </p>
            </div>
            <input v-model="search" class="input" style="max-width: 220px" placeholder="Search product…" />
            <button class="btn btn-ghost" @click="printReg"><Icon name="print" :size="16" /> Print</button>
            <label v-if="manage && prev" class="check" title="Also replace numbers typed by hand"><input v-model="force" type="checkbox" /> Overwrite manual</label>
            <button v-if="manage && prev" class="btn btn-primary" :disabled="fetching" @click="fetchStock">
                {{ fetching ? 'Calculating…' : 'Fetch / Refresh stock' }}
            </button>
        </div>

        <div v-if="summary" class="lock-banner" style="background: rgba(16, 185, 129, .12); color: #059669; border-color: rgba(16, 185, 129, .35)">
            <Icon name="check" :size="18" />
            From {{ summary.previous_year }}: {{ summary.updated }} updated, {{ summary.unchanged }} unchanged, {{ summary.skipped_manual }} kept (typed by hand).
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Opening stock</th>
                        <th>Source</th>
                        <th v-if="prev">{{ prev.label }} movement</th>
                        <th v-if="prev" class="num">{{ prev.label }} closing</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading"><td colspan="5" class="empty">Loading…</td></tr>
                    <tr v-else-if="!rows.length"><td colspan="5" class="empty">No products. Add a purchase or a product first.</td></tr>
                    <tr v-for="r in rows" :key="r.product_id">
                        <td><b>{{ r.product_name }}</b><br /><small style="color: var(--muted)">{{ r.product_code }}</small></td>
                        <td class="num">
                            <input v-if="manage" class="input" style="width: 110px; text-align: right; margin-left: auto" type="number" min="0" step="any"
                                :value="edits[r.product_id] ?? (r.quantity ?? '')" placeholder="0"
                                @input="edits[r.product_id] = $event.target.value" @blur="saveOne(r)" @keyup.enter="$event.target.blur()" />
                            <b v-else>{{ qty(r.quantity) }}</b>
                        </td>
                        <td><span v-if="r.source" class="src-badge" :class="r.source">{{ r.source === 'calculated' ? 'from last year' : 'typed' }}</span><span v-else class="src-badge">not set</span></td>
                        <td v-if="prev" class="calc-cell">
                            <template v-if="r.previous">
                                opening {{ qty(r.previous.opening) }} + purchase {{ qty(r.previous.purchased) }}<br />
                                − sales {{ qty(r.previous.sold) }} − orders {{ qty(r.previous.ordered) }} ± adj {{ qty(r.previous.adjusted) }}
                            </template>
                            <template v-else>no movement</template>
                        </td>
                        <td v-if="prev" class="num"><b :class="{ neg: r.suggested < 0 }">{{ qty(r.suggested) }}</b></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
