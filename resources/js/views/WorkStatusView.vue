<script setup>
import { computed, onMounted, ref } from 'vue';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import DateRange from '../components/DateRange.vue';
import { fmtDate, printRegister } from '../services/print';
import { errorMessage, workApi } from '../services/api';
import { canWrite, currentYear, defaultDate, defaultRange } from '../services/auth';
import { toast } from '../services/toast';

const range = ref(defaultRange());   // account year start -> today
const works = ref([]);
const loading = ref(true);
const form = ref(null);
const saving = ref(false);

const today = () => defaultDate();
const blank = () => ({ id: null, work_date: today(), items: [{ work_place: '', worker_name: '' }] });
const manage = canWrite('work_status.manage');
const canManage = () => manage;

// group the month's entries by date
const grouped = computed(() => {
    const map = {};
    works.value.forEach((w) => ((map[(w.work_date || '').slice(0, 10)] ||= []).push(w)));
    return Object.entries(map).sort((a, b) => b[0].localeCompare(a[0]));
});

const load = async () => {
    loading.value = true;
    try {
        const { data } = await workApi.getAll({ from: range.value.from, to: range.value.to });
        works.value = data.data;
    } catch (e) { toast.error(errorMessage(e)); works.value = []; }
    finally { loading.value = false; }
};
onMounted(load);

const printReg = () => printRegister({
    title: 'Work Status Register', period: range.value,
    columns: [
        { label: 'Date', get: (r) => fmtDate(r.date) },
        { label: 'Work place', get: (r) => r.place },
        { label: 'Worker', get: (r) => r.worker },
        { label: 'Added by', get: (r) => r.by },
    ],
    rows: works.value.slice().sort((a, b) => (a.work_date < b.work_date ? 1 : -1))
        .flatMap((w) => w.work_lists.map((l) => ({ date: w.work_date, place: l.work_place, worker: l.worker_name, by: w.user?.name || '' }))),
});

const edit = (w) => (form.value = { id: w.id, work_date: (w.work_date || '').slice(0, 10), items: w.work_lists.map((l) => ({ work_place: l.work_place, worker_name: l.worker_name })) });

const save = async () => {
    saving.value = true;
    try {
        const f = form.value;
        const payload = { work_date: f.work_date, items: f.items.filter((i) => i.work_place && i.worker_name) };
        if (!payload.items.length) throw { response: { data: { message: 'Add at least one work place and worker' } } };
        const { data } = f.id ? await workApi.update(f.id, payload) : await workApi.create(payload);
        toast.success(data.message);
        form.value = null;
        load();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const remove = async (w) => {
    if (!window.confirm('Delete this work entry?')) return;
    try { toast.success((await workApi.delete(w.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};

const fmt = (d) => new Date(d + 'T00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short' });
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3>Work status</h3><p class="sub">Who worked where, day by day</p></div>
            <button class="btn btn-ghost" @click="printReg"><Icon name="print" :size="16" /> Print register</button>
            <button v-if="manage" class="btn btn-primary" @click="form = blank()"><Icon name="plus" :size="16" /> Add work</button>
        </div>

        <DateRange v-model="range" @change="load" />

        <div v-if="loading" class="empty">Loading…</div>
        <div v-else-if="!grouped.length" class="empty">No work entries in this period</div>

        <div v-for="[date, list] in grouped" :key="date" class="day-group">
            <h4>{{ fmt(date) }}</h4>
            <div class="table-wrap">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Work places</th>
                            <th style="width: 30%;">Worker</th>
                            <th style="width: 20%;">Added by</th>
                            <th style="width: 20%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="w in list" :key="w.id">
                            <tr v-for="(l, i) in w.work_lists" :key="l.id">
                                <td>{{ l.work_place }}</td>
                                <td><b>{{ l.worker_name }}</b></td>
                                <td>{{ i === 0 ? w.user?.name : '' }}</td>
                                <td>
                                    <div v-if="i === 0 && canManage(w)" class="row-actions">
                                        <button class="btn btn-ghost btn-sm" @click="edit(w)"><Icon name="edit" :size="16" /></button>
                                        <button class="btn btn-danger btn-sm" @click="remove(w)"><Icon name="trash" :size="16" /></button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal v-if="form" :title="form.id ? 'Edit work' : 'Add work'" @close="form = null">
            <form @submit.prevent="save">
                <div class="field"><label>Date</label><input v-model="form.work_date" class="input" type="date" required :min="currentYear()?.start_date" :max="currentYear()?.end_date" /></div>
                <label style="font-size: 13px; font-weight: 600; color: var(--text-2)">Work places and workers</label>
                <div v-for="(row, i) in form.items" :key="i" style="display: grid; grid-template-columns: 1fr 1fr 36px; gap: 8px; margin: 8px 0">
                    <input v-model="row.work_place" class="input" placeholder="Work place" required />
                    <input v-model="row.worker_name" class="input" placeholder="Worker name" required />
                    <button type="button" class="btn btn-danger btn-sm" :disabled="form.items.length === 1" @click="form.items.splice(i, 1)">×</button>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" @click="form.items.push({ work_place: '', worker_name: '' })"><Icon name="plus" :size="14" /> Add row</button>
                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="form = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
