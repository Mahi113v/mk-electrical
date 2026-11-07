<script setup>
import { computed } from 'vue';

/*
 * The tables of the main database, to choose which ones a company's own database gets.
 *  - tables: [{ name, label, group, requires[], selectable, rows }]
 *  - v-model: the chosen table names
 * Choosing a table also chooses the tables it cannot work without (an items table needs its parent, and the products);
 * un-choosing a table un-chooses the tables that need it.
 */
const props = defineProps({ tables: { type: Array, default: () => [] }, modelValue: { type: Array, default: () => [] }, showShared: { type: Boolean, default: true } });
const emit = defineEmits(['update:modelValue']);

const pickable = computed(() => props.tables.filter((t) => t.selectable));
const shared = computed(() => props.tables.filter((t) => !t.selectable));
const groups = computed(() => {
    const map = {};
    pickable.value.forEach((t) => { (map[t.group] ||= []).push(t); });
    return Object.entries(map);
});
const byName = computed(() => Object.fromEntries(props.tables.map((t) => [t.name, t])));
const has = (n) => props.modelValue.includes(n);

const needs = (name, seen = new Set()) => {
    if (seen.has(name)) return seen;
    seen.add(name);
    (byName.value[name]?.requires || []).forEach((d) => byName.value[d]?.selectable && needs(d, seen));
    return seen;
};
const required = (name) => props.modelValue.some((other) => other !== name && needs(other).has(name));

const toggle = (t) => {
    const set = new Set(props.modelValue);
    if (set.has(t.name)) {
        // remove it and everything that needs it
        pickable.value.forEach((o) => { if (needs(o.name).has(t.name)) set.delete(o.name); });
    } else {
        needs(t.name).forEach((n) => set.add(n));
    }
    emit('update:modelValue', pickable.value.map((x) => x.name).filter((n) => set.has(n)));
};
const all = () => emit('update:modelValue', pickable.value.map((t) => t.name));
const none = () => emit('update:modelValue', []);
</script>

<template>
    <div class="tp">
        <div class="tp-top">
            <b>{{ modelValue.length }} of {{ pickable.length }} tables selected</b>
            <span><button type="button" class="po-link" @click="all">Select all</button> · <button type="button" class="po-link" @click="none">Clear</button></span>
        </div>
        <div v-for="[group, list] in groups" :key="group" class="tp-group">
            <div class="tp-g">{{ group }}</div>
            <label v-for="t in list" :key="t.name" class="tp-row" :class="{ on: has(t.name) }">
                <input type="checkbox" :checked="has(t.name)" @change="toggle(t)" />
                <span class="tp-name"><b>{{ t.name }}</b><small>{{ t.label }}<template v-if="t.rows !== null && t.rows !== undefined"> · {{ t.rows }} rows in the main database</template></small></span>
                <small v-if="has(t.name) && required(t.name)" class="tp-need">needed by another table</small>
            </label>
        </div>
        <p v-if="!groups.length" class="empty">No tables found.</p>
        <details v-if="showShared && shared.length" class="tp-shared">
            <summary>{{ shared.length }} shared tables (users, roles, companies ... always stay in the main database)</summary>
            <div class="tp-chips"><span v-for="t in shared" :key="t.name">{{ t.name }}</span></div>
        </details>
    </div>
</template>

<style scoped>
.tp { border: 1px solid var(--border); border-radius: 14px; padding: 12px; max-height: 360px; overflow-y: auto; background: var(--bg); }
.tp-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 13px; }
.po-link { border: 0; background: transparent; color: var(--brand); font-weight: 600; cursor: pointer; font-size: 13px; }
.tp-g { margin: 10px 0 4px; font-size: 11.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--text-2); }
.tp-row { display: flex; align-items: center; gap: 10px; padding: 7px 10px; border-radius: 10px; border: 1px solid transparent; cursor: pointer; }
.tp-row:hover { background: var(--brand-soft); }
.tp-row.on { border-color: var(--brand); background: var(--brand-soft); }
.tp-name { display: grid; flex: 1; min-width: 0; }
.tp-name small { color: var(--text-2); }
.tp-need { color: var(--accent); font-size: 11.5px; }
.tp-shared { margin-top: 12px; font-size: 13px; color: var(--text-2); }
.tp-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.tp-chips span { padding: 2px 9px; border-radius: 99px; background: var(--surface); border: 1px solid var(--border); font-size: 12px; }
</style>
