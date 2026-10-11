<script setup>
defineProps({ title: String, wide: Boolean });
defineEmits(['close']);
</script>

<template>
    <div class="modal-back" @mousedown.self="$emit('close')">
        <div class="modal" :class="{ wide }" role="dialog" aria-modal="true">
            <h3>{{ title }}</h3>
            <slot />
        </div>
    </div>
</template>
