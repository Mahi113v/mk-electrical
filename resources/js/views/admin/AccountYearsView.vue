<script setup>
import { onMounted, ref } from 'vue';
import Icon from '../../components/Icon.vue';
import { adminApi, errorMessage } from '../../services/api';
import { toast } from '../../services/toast';

const years = ref([]);
const companies = ref([]);
const loading = ref(true);
const busy = ref(false);
const canCreate = ref(false);

const load = async () => {
    try {
        const { data } = await adminApi.years();
        years.value = data.data;
        companies.value = data.companies;
        canCreate.value = data.can_create;
    } catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
onMounted(load);

const fmt = (d) => new Date(d + 'T00:00').toLocaleDateString('en-GB');
const locked = (y, c) => y.locked_company_ids.includes(c.id);

const createNext = async () => {
    busy.value = true;
    try { toast.success((await adminApi.createYear()).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
    finally { busy.value = false; }
};

const toggle = async (y, c) => {
    const lock = !locked(y, c);
    if (lock && !window.confirm(`Lock ${y.label} for ${c.name}? It becomes view only.`)) return;
    try { toast.success((await adminApi.lockYear(y.id, c.id, lock)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3 style="font-size: 22px">Account years</h3><p class="sub">Years run from 1 April to 31 March and are the same for every company. Locking a year makes it view only for that company.</p></div>
            <button v-if="canCreate" class="btn btn-primary" :disabled="busy" @click="createNext"><Icon name="plus" :size="16" /> Create next year</button>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>Year</th><th>Period</th><th v-for="c in companies" :key="c.id" style="text-align: center">{{ c.code }}</th></tr></thead>
                <tbody>
                    <tr v-if="loading"><td :colspan="2 + companies.length" class="empty">Loading…</td></tr>
                    <tr v-for="y in years" :key="y.id">
                        <td><b>{{ y.label }}</b> <span v-if="y.is_current" class="badge ok">Current</span><br /><small style="color: var(--muted)">code {{ y.code }}</small></td>
                        <td>{{ fmt(y.start_date) }} – {{ fmt(y.end_date) }}</td>
                        <td v-for="c in companies" :key="c.id" style="text-align: center">
                            <button class="btn btn-ghost btn-sm lock-btn" :class="{ locked: locked(y, c) }" @click="toggle(y, c)">{{ locked(y, c) ? 'Locked' : 'Open' }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="sub" style="margin-top: 14px">Users can only choose the years you tick for them in Users.<span v-if="canCreate"> A new year is given to everyone who could open the last year.</span></p>
    </div>
</template>
