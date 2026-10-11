<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { errorMessage, inr, publicApi } from '../services/api';
import { toast } from '../services/toast';

const blank = () => ({ customer_name: '', customer_phone: '', whatsapp: '', alternate_phone: '', area: '', city: '', address: '', notes: '' });
const form = reactive(blank());
const products = ref([]);
const qty = reactive({});
const loading = ref(true);
const sending = ref(false);
const done = ref(null);

const total = computed(() => products.value.reduce((s, p) => s + (Number(qty[p.id]) || 0) * Number(p.rate), 0));
const chosen = computed(() => products.value.filter((p) => Number(qty[p.id]) > 0));

onMounted(async () => {
    try { products.value = (await publicApi.products()).data.data; }
    catch (e) { toast.error(errorMessage(e, 'Could not load products')); }
    finally { loading.value = false; }
});

const step = (p, n) => (qty[p.id] = Math.max(0, (Number(qty[p.id]) || 0) + n));

const submit = async () => {
    if (!chosen.value.length) return toast.error('Please choose at least one product');
    sending.value = true;
    try {
        const payload = { ...form, items: chosen.value.map((p) => ({ product_id: p.id, quantity: Number(qty[p.id]) })) };
        const { data } = await publicApi.createOrder(payload);
        done.value = data.data;
        Object.assign(form, blank());
        Object.keys(qty).forEach((k) => delete qty[k]);
    } catch (e) { toast.error(errorMessage(e)); }
    finally { sending.value = false; }
};
</script>

<template>
    <div class="public-wrap">
        <div class="public-head">
            <img src="/images/mk-electrical.png" alt="MK Electrical" @error="$event.target.style.display = 'none'" />
            <h1><span style="color: var(--danger)">MK</span> Electrical</h1>
            <p style="color: var(--text-2); margin: 0">Camera &amp; RO systems · Sivakasi</p>
        </div>

        <div v-if="done" class="card success-box">
            <div style="font-size: 44px">✅</div>
            <h3 style="font-size: 20px">Order placed!</h3>
            <p style="color: var(--text-2)">Your order number is <b>#{{ done.id }}</b>. Estimated total {{ inr(done.total) }}. We will call you shortly to confirm.</p>
            <button class="btn btn-primary" @click="done = null">Place another order</button>
        </div>

        <form v-else @submit.prevent="submit">
            <div class="card" style="margin-bottom: 18px">
                <h3>Your details</h3>
                <p class="sub">So we can reach you</p>
                <div class="form-grid">
                    <div class="field"><label>Name *</label><input v-model="form.customer_name" class="input" required /></div>
                    <div class="field"><label>Phone *</label><input v-model="form.customer_phone" class="input" maxlength="15" required inputmode="tel" /></div>
                    <div class="field"><label>WhatsApp</label><input v-model="form.whatsapp" class="input" maxlength="15" inputmode="tel" /></div>
                    <div class="field"><label>Alternate phone</label><input v-model="form.alternate_phone" class="input" maxlength="15" inputmode="tel" /></div>
                    <div class="field"><label>Area</label><input v-model="form.area" class="input" /></div>
                    <div class="field"><label>City</label><input v-model="form.city" class="input" /></div>
                    <div class="field full"><label>Address</label><textarea v-model="form.address" class="input" rows="2" /></div>
                </div>
            </div>

            <div class="card" style="margin-bottom: 18px">
                <h3>Choose products</h3>
                <p class="sub">Final price is confirmed by our team</p>
                <div v-if="loading" class="empty">Loading…</div>
                <div v-else-if="!products.length" class="empty">No products available right now</div>
                <div style="display: grid; gap: 10px">
                    <div v-for="p in products" :key="p.id" class="pick" :class="{ sel: qty[p.id] > 0 }">
                        <div><b>{{ p.product_name }}</b><br /><small style="color: var(--text-2)">{{ inr(p.rate) }}</small></div>
                        <div style="display: flex; align-items: center; gap: 6px; justify-content: flex-end">
                            <button type="button" class="btn btn-ghost btn-sm" @click="step(p, -1)">−</button>
                            <input v-model.number="qty[p.id]" class="input" style="width: 56px; text-align: center; padding: 7px" type="number" min="0" placeholder="0" />
                            <button type="button" class="btn btn-ghost btn-sm" @click="step(p, 1)">+</button>
                        </div>
                    </div>
                </div>
                <div class="field" style="margin-top: 16px"><label>Notes</label><input v-model="form.notes" class="input" placeholder="Anything we should know?" /></div>
                <div class="totals"><div class="grand"><span>Estimated total</span><span>{{ inr(total) }}</span></div></div>
            </div>

            <button class="btn btn-primary" style="width: 100%; padding: 14px" :disabled="sending">{{ sending ? 'Placing order…' : 'Place order' }}</button>
            <p style="text-align: center; margin-top: 18px; font-size: 13px"><router-link to="/login" style="color: var(--text-2)">Staff login</router-link></p>
        </form>
    </div>
</template>
