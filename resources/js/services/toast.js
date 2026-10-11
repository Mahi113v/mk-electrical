import { reactive } from 'vue';

export const toasts = reactive([]);
let id = 0;

const push = (type, message, ms = 3200) => {
    const t = { id: ++id, type, message };
    toasts.push(t);
    setTimeout(() => {
        const i = toasts.findIndex((x) => x.id === t.id);
        if (i > -1) toasts.splice(i, 1);
    }, ms);
};

export const toast = {
    success: (m) => push('success', m),
    error: (m) => push('error', m, 4500),
    info: (m) => push('info', m),
};
