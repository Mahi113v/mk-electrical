<script setup>
import { toasts } from '../services/toast';
</script>

<template>
    <div class="toast-host">
        <div v-for="t in toasts" :key="t.id" class="toast" :class="t.type">{{ t.message }}</div>
    </div>
</template>
