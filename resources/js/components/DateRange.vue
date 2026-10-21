<script setup>
import { currentYear, defaultRange } from '../services/auth';

/*
 * From / To of a register. Starts at the account year start date and ends today.
 * v-model = { from, to }   @change = fired after the person changes a date (reload the list)
 */
const props = defineProps({ modelValue: { type: Object, required: true } });
const emit = defineEmits(['update:modelValue', 'change']);

const set = (patch) => {
    let next = { ...props.modelValue, ...patch };
    if (next.from && next.to && next.from > next.to) {
        // keep the range valid: moving "from" past "to" (or the other way round) drags the other date along
        next = patch.from ? { ...next, to: next.from } : { ...next, from: next.to };
    }
    emit('update:modelValue', next);
    emit('change');
};
const reset = () => { emit('update:modelValue', defaultRange()); emit('change'); };
</script>

<template>
    <div class="date-range">
        <label>From
            <input class="input" type="date" :value="modelValue.from" :min="currentYear()?.start_date" :max="currentYear()?.end_date" @change="set({ from: $event.target.value || currentYear()?.start_date })" />
        </label>
        <label>To
            <input class="input" type="date" :value="modelValue.to" :min="currentYear()?.start_date" :max="currentYear()?.end_date" @change="set({ to: $event.target.value || defaultRange().to })" />
        </label>
        <button type="button" class="btn btn-ghost btn-sm" title="Account year start to today" @click="reset">Reset</button>
    </div>
</template>
