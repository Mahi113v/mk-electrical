<script setup>
defineProps({ name: String, size: { type: [Number, String], default: 20 } });

const paths = {
    home: 'M3 11l9-8 9 8M5 10v10h14V10',
    cart: 'M6 6h15l-1.5 9h-12zM6 6L5 3H2M9 20h.01M18 20h.01',
    box: 'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
    users: 'M9 12a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5M16 4.6a3.5 3.5 0 010 6.8M18 14.8c2 .6 3.2 2.4 3.5 5.2',
    hat: 'M3 18h18M5 18a7 7 0 0114 0M12 6v5M9 8v3M15 8v3',
    shield: 'M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6zM9 12l2 2 4-4',
    user: 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21c.8-4 4-6 8-6s7.2 2 8 6',
    sun: 'M12 16a4 4 0 100-8 4 4 0 000 8zM12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4',
    moon: 'M21 13a9 9 0 11-10-10 7 7 0 0010 10z',
    menu: 'M4 7h16M4 12h16M4 17h16',
    wallet: 'M3 7h16a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2zM3 7l12-4v4M16.5 14h.01',
    clock: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2',
    alert: 'M12 3l10 18H2zM12 10v5M12 18h.01',
    eye: 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12zM12 15a3 3 0 100-6 3 3 0 000 6z',
    eyeoff: 'M3 3l18 18M10.6 6.1A9.8 9.8 0 0112 6c6.4 0 10 6 10 6a17 17 0 01-3.2 3.9M6.6 7.6C3.9 9.3 2 12 2 12s3.6 6 10 6c1.6 0 3-.4 4.3-1',
    check: 'M5 12.5l4.5 4.5L19 7',
    edit: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M17.6 3.6a2 2 0 112.8 2.8L11.8 15H9v-2.8z',
    trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
    print: 'M6 9V3h12v6M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v7H6z',
    plus: 'M12 5v14M5 12h14',
    logout: 'M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9',
    chevl: 'M15 18l-6-6 6-6',
    chevr: 'M9 18l6-6-6-6',
};
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path :d="paths[name]" />
    </svg>
</template>
