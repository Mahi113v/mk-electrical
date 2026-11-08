<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { errorMessage, inr, publicApi } from '../services/api';
import { toast } from '../services/toast';

const DEFAULT_LOGO = '/images/mk-logo.png';
const route = useRoute();
const router = useRouter();

const codeInput = ref(String(route.query.company || '').toUpperCase());
const companyCode = ref('');
const company = ref(null);
const looking = ref(false);
const codeError = ref('');

const blank = () => ({ customer_name: '', customer_phone: '', whatsapp: '', alternate_phone: '', area: '', city: '', address: '', notes: '' });
const form = reactive(blank());
const products = ref([]);
const qty = reactive({});
const loading = ref(false);
const sending = ref(false);
const done = ref(null);
const receipt = ref(null);       // snapshot used for printing
const askPrint = ref(false);

const logoSrc = computed(() => company.value?.logo_url || DEFAULT_LOGO);
const onLogoError = (e) => {
    if (!e.target.src.endsWith(DEFAULT_LOGO)) e.target.src = DEFAULT_LOGO;
    else e.target.style.display = 'none';
};

const total = computed(() => products.value.reduce((s, p) => s + (Number(qty[p.id]) || 0) * Number(p.rate), 0));
const chosen = computed(() => products.value.filter((p) => Number(qty[p.id]) > 0));

const loadCompany = async () => {
    const code = codeInput.value.trim().toUpperCase();
    if (!code) { codeError.value = 'Please enter the company code'; return; }
    looking.value = true;
    codeError.value = '';
    try {
        const { data } = await publicApi.products(code);
        products.value = data.data.products || [];
        company.value = data.data.company;
        companyCode.value = code;
        done.value = null;
        Object.keys(qty).forEach((k) => delete qty[k]);
        router.replace({ query: { ...route.query, company: code } });
    } catch (e) {
        company.value = null;
        codeError.value = errorMessage(e, 'Company not found. Check the code and try again.');
    } finally {
        looking.value = false;
    }
};

const changeCompany = () => {
    company.value = null;
    companyCode.value = '';
    products.value = [];
    done.value = null;
    receipt.value = null;
    Object.keys(qty).forEach((k) => delete qty[k]);
    Object.assign(form, blank());
};

onMounted(() => { if (codeInput.value) loadCompany(); });

const step = (p, n) => (qty[p.id] = Math.max(0, (Number(qty[p.id]) || 0) + n));

const submit = async () => {
    if (!chosen.value.length) return toast.error('Please choose at least one product');
    sending.value = true;
    try {
        const items = chosen.value.map((p) => ({ product_id: p.id, quantity: Number(qty[p.id]) }));
        const { data } = await publicApi.createOrder({ ...form, items }, companyCode.value);
        done.value = data.data;

        // snapshot BEFORE clearing the form, so printing still works
        receipt.value = {
            id: data.data.id,
            total: data.data.total ?? total.value,
            date: new Date().toLocaleString('en-IN'),
            company: company.value?.name || '',
            customer: { ...form },
            items: chosen.value.map((p) => ({
                name: p.product_name,
                qty: Number(qty[p.id]),
                rate: Number(p.rate),
                amount: Number(qty[p.id]) * Number(p.rate),
            })),
        };

        Object.assign(form, blank());
        Object.keys(qty).forEach((k) => delete qty[k]);
        askPrint.value = true;
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        sending.value = false;
    }
};

const doPrint = async () => {
    askPrint.value = false;
    await nextTick();
    window.print();
};
const skipPrint = () => { askPrint.value = false; };
</script>

<template>
    <div class="po">
        <div class="po-card">
            <!-- header: logo + company name -->
            <div class="po-head">
                <img :src="logoSrc" :alt="company?.name || 'MK Software'" @error="onLogoError" />
                <h1>{{ company?.name || 'Place an order' }}</h1>
                <p v-if="company">Choose what you need and we will call you to confirm.</p>
                <p v-else>Enter your company code to start your order.</p>
            </div>

            <!-- step 1: company code -->
            <form v-if="!company" class="po-code" @submit.prevent="loadCompany">
                <div v-if="codeError" class="po-alert" role="alert">{{ codeError }}</div>
                <div class="field">
                    <label>Company code</label>
                    <input v-model="codeInput" class="input po-code-input" maxlength="10" placeholder="e.g. MK" autocomplete="off" autofocus @input="codeInput = codeInput.toUpperCase()" />
                </div>
                <button class="btn btn-primary po-full" :disabled="looking || !codeInput.trim()">{{ looking ? 'Checking…' : 'Continue' }}</button>
                <p class="po-staff"><router-link to="/login">Staff login</router-link></p>
            </form>

            <!-- order saved -->
            <div v-else-if="done" class="po-done">
                <div class="po-tick">✓</div>
                <h3>Order placed!</h3>
                <p>Your order number is <b>#{{ done.id }}</b>. Estimated total {{ inr(done.total) }}. We will call you shortly to confirm.</p>
                <div class="po-row">
                    <button class="btn btn-ghost" type="button" @click="askPrint = true">Print order</button>
                    <button class="btn btn-primary" type="button" @click="done = null">Place another order</button>
                </div>
            </div>

            <!-- step 2: order form -->
            <form v-else @submit.prevent="submit">
                <div class="po-sec">
                    <div class="po-sec-top">
                        <h3>Your details</h3>
                        <button type="button" class="po-link" @click="changeCompany">Change company ({{ companyCode }})</button>
                    </div>
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

                <div class="po-sec">
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

                <button class="btn btn-primary po-full" style="padding: 14px" :disabled="sending">{{ sending ? 'Placing order…' : 'Place order' }}</button>
                <p class="po-staff"><router-link to="/login">Staff login</router-link></p>
            </form>
        </div>

        <!-- print question -->
        <div v-if="askPrint" class="po-modal" @click.self="skipPrint">
            <div class="po-dialog" role="dialog" aria-modal="true">
                <div class="po-tick">✓</div>
                <h3>Order #{{ receipt?.id }} saved</h3>
                <p>Do you want to print this order?</p>
                <div class="po-row">
                    <button class="btn btn-ghost" type="button" @click="skipPrint">Cancel</button>
                    <button class="btn btn-primary" type="button" @click="doPrint">Print</button>
                </div>
            </div>
        </div>

        <!-- print-only receipt -->
        <div v-if="receipt" class="print-area">
            <div class="pr-head">
                <img :src="logoSrc" alt="" @error="onLogoError" />
                <div>
                    <h2>{{ receipt.company }}</h2>
                    <small>Order #{{ receipt.id }} &nbsp;|&nbsp; {{ receipt.date }}</small>
                </div>
            </div>
            <div class="pr-cust">
                <div><b>Name:</b> {{ receipt.customer.customer_name }}</div>
                <div><b>Phone:</b> {{ receipt.customer.customer_phone }}</div>
                <div v-if="receipt.customer.whatsapp"><b>WhatsApp:</b> {{ receipt.customer.whatsapp }}</div>
                <div v-if="receipt.customer.alternate_phone"><b>Alt phone:</b> {{ receipt.customer.alternate_phone }}</div>
                <div v-if="receipt.customer.area || receipt.customer.city"><b>Area / City:</b> {{ [receipt.customer.area, receipt.customer.city].filter(Boolean).join(', ') }}</div>
                <div v-if="receipt.customer.address"><b>Address:</b> {{ receipt.customer.address }}</div>
                <div v-if="receipt.customer.notes"><b>Notes:</b> {{ receipt.customer.notes }}</div>
            </div>
            <table class="pr-table">
                <thead><tr><th>#</th><th>Product</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead>
                <tbody>
                    <tr v-for="(it, i) in receipt.items" :key="i">
                        <td>{{ i + 1 }}</td><td>{{ it.name }}</td><td class="r">{{ it.qty }}</td><td class="r">{{ inr(it.rate) }}</td><td class="r">{{ inr(it.amount) }}</td>
                    </tr>
                </tbody>
                <tfoot><tr><td colspan="4" class="r"><b>Estimated total</b></td><td class="r"><b>{{ inr(receipt.total) }}</b></td></tr></tfoot>
            </table>
            <p class="pr-note">Final price will be confirmed by our team. Thank you!</p>
        </div>
    </div>
</template>

<style scoped>
.po {
    min-height: 100vh; min-height: 100dvh; display: grid; place-items: start center; padding: 40px 16px;
    background:
        radial-gradient(800px 480px at 12% -8%, rgba(79, 70, 229, .55), transparent 60%),
        radial-gradient(700px 500px at 100% 110%, rgba(139, 92, 246, .45), transparent 60%),
        #0f0b24;
}
.po-card {
    width: 100%; max-width: 720px; padding: 28px; border-radius: 26px; color: var(--text);
    background: var(--surface); border: 1px solid var(--border); box-shadow: 0 40px 80px -30px rgba(0, 0, 0, .7);
}
.po-head { text-align: center; margin-bottom: 22px; }
.po-head img { width: 68px; height: 68px; border-radius: 16px; object-fit: cover; background: #fff; box-shadow: 0 10px 24px -10px rgba(0, 0, 0, .5); }
.po-head h1 { margin: 10px 0 4px; font-size: 26px; letter-spacing: -.03em; font-weight: 800; }
.po-head p { margin: 0; color: var(--text-2); font-size: 14px; }

.po-code { max-width: 360px; margin: 0 auto; }
.po-code-input { text-transform: uppercase; letter-spacing: .12em; text-align: center; font-weight: 700; font-size: 18px; }
.po-alert { margin-bottom: 14px; padding: 11px 14px; border-radius: 12px; font-size: 14px; color: #b91c1c; background: rgba(239, 68, 68, .1); border: 1px solid rgba(239, 68, 68, .28); }
.po-full { width: 100%; margin-top: 4px; }
.po-staff { text-align: center; margin: 18px 0 0; font-size: 13px; }
.po-staff a { color: var(--text-2); }

.po-sec { padding: 18px; margin-bottom: 16px; border-radius: 18px; background: var(--bg); border: 1px solid var(--border); }
.po-sec-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.po-link { border: 0; background: transparent; color: var(--brand); font-weight: 600; font-size: 13px; cursor: pointer; }
.po-link:hover { text-decoration: underline; }

.po-done { text-align: center; padding: 8px 0 4px; }
.po-done p { color: var(--text-2); }
.po-row { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 16px; }
.po-tick { width: 52px; height: 52px; margin: 0 auto 10px; display: grid; place-items: center; border-radius: 50%; font-size: 26px; font-weight: 800; color: #15803d; background: #dcfce7; }

.po-modal { position: fixed; inset: 0; z-index: 50; display: grid; place-items: center; padding: 16px; background: rgba(8, 6, 24, .65); backdrop-filter: blur(4px); }
.po-dialog { width: 100%; max-width: 360px; padding: 26px; text-align: center; border-radius: 22px; background: var(--surface); color: var(--text); border: 1px solid var(--border); box-shadow: 0 30px 60px -20px rgba(0, 0, 0, .6); }
.po-dialog h3 { margin: 0 0 6px; }
.po-dialog p { margin: 0; color: var(--text-2); }

.print-area { display: none; }

@media (max-width: 520px) {
    .po { padding: 20px 10px; }
    .po-card { padding: 18px; border-radius: 22px; }
}
</style>

<!-- not scoped: print rules must reach <body> -->
<style>
@media print {
    body * { visibility: hidden !important; }
    .print-area, .print-area * { visibility: visible !important; }
    .print-area { display: block !important; position: absolute; left: 0; top: 0; width: 100%; padding: 24px; color: #000; background: #fff; font-family: Arial, sans-serif; }
    .print-area .pr-head { display: flex; align-items: center; gap: 14px; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 14px; }
    .print-area .pr-head img { width: 60px; height: 60px; object-fit: cover; border-radius: 10px; }
    .print-area .pr-head h2 { margin: 0 0 4px; font-size: 22px; }
    .print-area .pr-cust { display: grid; gap: 4px; font-size: 14px; margin-bottom: 16px; }
    .print-area .pr-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .print-area .pr-table th, .print-area .pr-table td { border: 1px solid #444; padding: 6px 8px; text-align: left; }
    .print-area .pr-table .r { text-align: right; }
    .print-area .pr-note { margin-top: 18px; font-size: 12px; text-align: center; }
}
</style>