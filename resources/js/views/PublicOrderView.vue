<script setup>
import QRCode from 'qrcode';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { errorMessage, inr, publicApi } from '../services/api';
import { toast } from '../services/toast';

const DEFAULT_LOGO = '/images/mk-logo.png' || '/images/mk-logo1.png';
const DEFAULT_BANNER = '/images/MK Software Banner.png' || '/images/default-company.svg';   // shown when the company has no image
const MAX_RESULTS = 30;          // product search dropdown limit
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
const cart = ref([]);
const sending = ref(false);
const done = ref(null);
const receipt = ref(null);
const qrImage = ref('');

/* how to pay: UPI link / QR for the order total, and the bank details of the company */
const upiLink = computed(() => {
    const c = company.value;
    if (!c?.upi_id || !done.value) return '';
    const q = new URLSearchParams({ pa: c.upi_id, pn: c.upi_name || c.name, am: String(Number(done.value.total || 0).toFixed(2)), cu: 'INR', tn: `Order #${done.value.id}` });
    return `upi://pay?${q.toString().replace(/\+/g, '%20')}`;
});
watch(upiLink, async (link) => {
    qrImage.value = link ? await QRCode.toDataURL(link, { width: 200, margin: 1 }).catch(() => '') : '';
});
const hasBank = computed(() => !!company.value?.account_number);
const askPrint = ref(false);

/* ---------- customer: search first, or add new ---------- */
const sameWa = ref(false);           // WhatsApp number = phone number
const custMode = ref('search');      // 'search' | 'form'
const custPicked = ref(null);        // the existing customer chosen from search (for the badge)
const custQuery = ref('');
const custResults = ref([]);
const custLoading = ref(false);
const custSearched = ref(false);     // true once a search finished (to show "not found")
let custTimer = null;
let custSeq = 0;

/* safe mode: the server only finds a FULL 10-digit mobile number (set PUBLIC_CUSTOMER_SEARCH=phone in .env) */
// const searchable = (q) => q.replace(/\D+/g, '').length >= 10;

/* phone: min 4 digits (any part of the number) | name: min 3 letters */
const isPhoneQuery = (q) => /^[\d\s+\-()]+$/.test(q.trim());
const searchable = (q) => {
    const t = q.trim();
    if (!t) return false;
    if (isPhoneQuery(t)) return t.replace(/\D+/g, '').length >= 4;
    return t.length >= 3;
};

const runCustSearch = async () => {
    const q = custQuery.value.trim();
    if (!searchable(q)) { custResults.value = []; custSearched.value = false; custLoading.value = false; return; }
    const seq = ++custSeq;
    custLoading.value = true;
    try {
        // phone → send digits only, name → send text as typed
        const term = isPhoneQuery(q) ? q.replace(/\D+/g, '') : q;
        const { data } = await publicApi.searchCustomers(term, companyCode.value);
        if (seq !== custSeq) return;
        custResults.value = data.data || [];
        custSearched.value = true;
    } catch {
        if (seq !== custSeq) return;
        custResults.value = [];
        custSearched.value = true;
    } finally {
        if (seq === custSeq) custLoading.value = false;
    }
};

watch(custQuery, () => {
    clearTimeout(custTimer);
    custSearched.value = false;
    custTimer = setTimeout(runCustSearch, 350);   // wait until the customer stops typing
});

/* while "same as phone" is ticked the WhatsApp number follows the phone number (and is saved with it) */
watch(() => form.customer_phone, (v) => { if (sameWa.value) form.whatsapp = v; });
watch(sameWa, (on) => { if (on) form.whatsapp = form.customer_phone; });

const pickCustomer = (c) => {
    Object.assign(form, {
        customer_name: c.customer_name || '',
        customer_phone: c.customer_phone || '',
        whatsapp: c.whatsapp || '',
        alternate_phone: c.alternate_phone || '',
        area: c.area || '',
        city: c.city || '',
        address: c.address || '',
    });
    sameWa.value = !!c.customer_phone && c.whatsapp === c.customer_phone;
    custPicked.value = c;
    custMode.value = 'form';
};

const newCustomer = () => {
    const keepNotes = form.notes;
    Object.assign(form, blank());
    form.notes = keepNotes;
    // if they typed a number in the search box, carry it into the new form
    const digits = custQuery.value.replace(/\D+/g, '');
    if (digits.length >= 6) form.customer_phone = digits.slice(0, 15);
    else if (custQuery.value.trim()) form.customer_name = custQuery.value.trim();
    sameWa.value = false;
    custPicked.value = null;
    custMode.value = 'form';
};

const backToSearch = () => {
    sameWa.value = false;
    custMode.value = 'search';
    custPicked.value = null;
    custQuery.value = '';
    custResults.value = [];
    custSearched.value = false;
};

/* ---------- product search ---------- */
const query = ref('');
const open = ref(false);
const active = ref(0);
const listEl = ref(null);

const logoSrc = computed(() => company.value?.logo_url || DEFAULT_LOGO);
/* company image; if it is missing or broken the default image is shown */
const bannerSrc = computed(() => company.value?.image_url || DEFAULT_BANNER);
const onBannerError = (e) => {
    if (!e.target.src.endsWith(DEFAULT_BANNER)) e.target.src = DEFAULT_BANNER;
    else e.target.style.display = 'none';
};
const onLogoError = (e) => {
    if (!e.target.src.endsWith(DEFAULT_LOGO)) e.target.src = DEFAULT_LOGO;
    else e.target.style.display = 'none';
};

const norm = (s) => String(s || '').toLowerCase();

const matches = computed(() => {
    const words = norm(query.value).split(/\s+/).filter(Boolean);
    if (!words.length) return [];
    return products.value.filter((p) => {
        const name = norm(p.product_name);
        return words.every((w) => name.includes(w));
    });
});
const results = computed(() => matches.value.slice(0, MAX_RESULTS));

const inCart = (id) => cart.value.find((c) => c.id === id);
const total = computed(() => cart.value.reduce((s, c) => s + (Number(c.qty) || 0) * c.rate, 0));
const chosen = computed(() => cart.value.filter((c) => Number(c.qty) > 0));

const add = (p) => {
    const row = inCart(p.id);
    if (row) row.qty = (Number(row.qty) || 0) + 1;
    else cart.value.push({ id: p.id, name: p.product_name, rate: Number(p.rate), qty: 1 });
    query.value = '';
    active.value = 0;
};
const addActive = () => { if (results.value[active.value]) add(results.value[active.value]); };

const move = (n) => {
    if (!results.value.length) return;
    open.value = true;
    active.value = (active.value + n + results.value.length) % results.value.length;
    nextTick(() => listEl.value?.querySelector('.on')?.scrollIntoView({ block: 'nearest' }));
};

const step = (c, n) => { c.qty = Math.max(1, (Number(c.qty) || 0) + n); };
const fixQty = (c) => { if (!Number(c.qty) || Number(c.qty) < 1) c.qty = 1; };
const remove = (id) => { cart.value = cart.value.filter((c) => c.id !== id); };

const resetOrder = () => {
    cart.value = [];
    query.value = '';
    open.value = false;
};

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
        resetOrder();
        backToSearch();
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
    resetOrder();
    Object.assign(form, blank());
    backToSearch();
};

onMounted(() => { if (codeInput.value) loadCompany(); });

const submit = async () => {
    if (custMode.value === 'search') return toast.error('Type your mobile number, or tap "New customer"');
    if (!chosen.value.length) return toast.error('Please add at least one product');
    sending.value = true;
    try {
        const items = chosen.value.map((c) => ({ product_id: c.id, quantity: Number(c.qty) }));
        const { data } = await publicApi.createOrder({ ...form, items }, companyCode.value);
        done.value = data.data;

        // snapshot BEFORE clearing the form, so printing still works
        receipt.value = {
            id: data.data.id,
            total: data.data.total ?? total.value,
            date: new Date().toLocaleString('en-IN'),
            company: company.value?.name || '',
            address: company.value?.address || '',
            phone: company.value?.phone || '',
            pay: company.value ? { upi_id: company.value.upi_id, upi_name: company.value.upi_name, bank_name: company.value.bank_name, bank_branch: company.value.bank_branch, account_holder: company.value.account_holder, account_number: company.value.account_number, ifsc: company.value.ifsc } : null,
            customer: { ...form },
            items: chosen.value.map((c) => ({
                name: c.name,
                qty: Number(c.qty),
                rate: c.rate,
                amount: Number(c.qty) * c.rate,
            })),
        };

        Object.assign(form, blank());
        resetOrder();
        backToSearch();
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
            <!-- company image (banner) -->
            <img class="po-banner" :src="bannerSrc" :alt="company?.name || ''" @error="onBannerError" />

            <!-- header: logo + company name -->
            <div class="po-head">
                <img :src="logoSrc" :alt="company?.name || 'MK Software'" @error="onLogoError" />
                <h1>{{ company?.name || 'Place an order' }}</h1>
                <p v-if="company">Search and add what you need, we will call you to confirm.</p>
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
                <div v-if="upiLink || hasBank" class="po-pay">
                    <b>Pay to {{ company.name }}</b>
                    <template v-if="upiLink">
                        <img v-if="qrImage" :src="qrImage" alt="UPI QR code" class="po-qr" />
                        <div>UPI ID: <b>{{ company.upi_id }}</b></div>
                        <a :href="upiLink" class="btn btn-primary po-upi">Pay {{ inr(done.total) }} with UPI app</a>
                    </template>
                    <div v-if="hasBank" class="po-bank">
                        <div>{{ company.bank_name }}<template v-if="company.bank_branch">, {{ company.bank_branch }}</template></div>
                        <div>A/c name: <b>{{ company.account_holder || company.name }}</b></div>
                        <div>A/c no: <b>{{ company.account_number }}</b><template v-if="company.ifsc"> · IFSC: <b>{{ company.ifsc }}</b></template></div>
                    </div>
                </div>
                <div class="po-row">
                    <button class="btn btn-ghost" type="button" @click="askPrint = true">Print order</button>
                    <button class="btn btn-primary" type="button" @click="done = null">Place another order</button>
                </div>
            </div>

            <!-- step 2: order form -->
            <form v-else @submit.prevent="submit">
                <!-- customer details -->
                <div class="po-sec">
                    <div class="po-sec-top">
                        <h3>Your details</h3>
                        <button type="button" class="po-link" @click="changeCompany">Change company ({{ companyCode }})</button>
                    </div>

                    <!-- A) search existing customer -->
                    <template v-if="custMode === 'search'">
                        <p class="sub">Ordered before? Type your name or mobile number and we fill your details. New here? Tap “New customer”.</p>
                        <div class="cs">
                            <input
                                v-model="custQuery"
                                class="input cs-input"
                                type="search"
                                placeholder="Your name or phone number"
                                autocomplete="off"
                                enterkeyhint="search"
                                @keydown.enter.prevent="runCustSearch"
                            />
                            <span v-if="custLoading" class="cs-spin" aria-hidden="true"></span>
                        </div>

                        <p v-if="custQuery.trim().length > 0 && !searchable(custQuery.trim())" class="cs-hint">
                            {{ isPhoneQuery(custQuery) ? 'Type at least 4 digits of your mobile number' : 'Type at least 3 letters of your name' }}
                        </p>

                        <!-- <p v-if="custQuery.trim().length > 0 && !searchable(custQuery.trim())" class="cs-hint">Type your full 10-digit mobile number</p> -->

                        <div v-if="custResults.length" class="cs-list">
                            <button v-for="c in custResults" :key="c.id" type="button" class="cs-item" @click="pickCustomer(c)">
                                <span class="cs-ava">{{ (c.customer_name || '?').charAt(0).toUpperCase() }}</span>
                                <span class="cs-info">
                                    <b>{{ c.customer_name }}</b>
                                    <small>{{ c.customer_phone }}<template v-if="c.area || c.city"> · {{ [c.area, c.city].filter(Boolean).join(', ') }}</template></small>
                                </span>
                                <span class="cs-go">Select</span>
                            </button>
                        </div>
                        <div v-else-if="custSearched && !custLoading" class="cs-none">
                            No customer found for “{{ custQuery }}”.
                        </div>

                        <button type="button" class="btn btn-primary cs-new" @click="newCustomer">+ New customer</button>
                    </template>

                    <!-- B) the details form (picked customer, or a new one) -->
                    <template v-else>
                        <div class="cs-bar">
                            <span v-if="custPicked" class="cs-badge">Existing customer: {{ custPicked.customer_name }}</span>
                            <span v-else class="cs-badge new">New customer</span>
                            <button type="button" class="po-link" @click="backToSearch">Search another customer</button>
                        </div>
                        <div class="form-grid">
                            <div class="field"><label>Name *</label><input v-model="form.customer_name" class="input" required /></div>
                            <div class="field"><label>Phone *</label><input v-model="form.customer_phone" class="input" maxlength="15" required inputmode="tel" /></div>
                            <div class="field">
                                <label>WhatsApp</label>
                                <input v-model="form.whatsapp" class="input" maxlength="15" inputmode="tel" :readonly="sameWa" :class="{ 'is-same': sameWa }" />
                                <label class="wa-same"><input v-model="sameWa" type="checkbox" :disabled="!form.customer_phone" /> Same as phone number</label>
                            </div>
                            <div class="field"><label>Alternate phone</label><input v-model="form.alternate_phone" class="input" maxlength="15" inputmode="tel" /></div>
                            <div class="field"><label>Area</label><input v-model="form.area" class="input" /></div>
                            <div class="field"><label>City</label><input v-model="form.city" class="input" /></div>
                            <div class="field full"><label>Address</label><textarea v-model="form.address" class="input" rows="2" /></div>
                        </div>
                    </template>
                </div>

                <!-- products -->
                <div class="po-sec">
                    <h3>Choose products</h3>
                    <p class="sub">Search by name and tap a product to add it. Final price is confirmed by our team.</p>

                    <div class="ps">
                        <input
                            v-model="query"
                            class="input ps-input"
                            type="search"
                            :placeholder="products.length ? `Search ${products.length} products…` : 'No products available right now'"
                            :disabled="!products.length"
                            autocomplete="off"
                            enterkeyhint="search"
                            @focus="open = true"
                            @blur="open = false"
                            @input="active = 0; open = true"
                            @keydown.down.prevent="move(1)"
                            @keydown.up.prevent="move(-1)"
                            @keydown.enter.prevent="addActive"
                            @keydown.esc="open = false"
                        />
                        <div v-if="open && query.trim()" ref="listEl" class="ps-list">
                            <div v-if="!results.length" class="ps-empty">No product matches “{{ query }}”</div>
                            <button
                                v-for="(p, i) in results"
                                :key="p.id"
                                type="button"
                                class="ps-item"
                                :class="{ on: i === active }"
                                @mousedown.prevent="add(p)"
                                @mouseenter="active = i"
                            >
                                <span class="ps-name">{{ p.product_name }}</span>
                                <span class="ps-meta">
                                    <span v-if="inCart(p.id)" class="ps-added">Added ×{{ inCart(p.id).qty }}</span>
                                    <b>{{ inr(p.rate) }}</b>
                                    <span class="ps-plus">+</span>
                                </span>
                            </button>
                            <div v-if="matches.length > MAX_RESULTS" class="ps-more">
                                Showing {{ MAX_RESULTS }} of {{ matches.length }}. Type more letters to narrow down.
                            </div>
                        </div>
                    </div>

                    <div v-if="cart.length" class="cart">
                        <div class="cart-title">Your order ({{ cart.length }} {{ cart.length === 1 ? 'item' : 'items' }})</div>
                        <div v-for="c in cart" :key="c.id" class="pick sel">
                            <div>
                                <b>{{ c.name }}</b><br />
                                <small style="color: var(--text-2)">{{ inr(c.rate) }} × {{ c.qty || 0 }} = {{ inr((Number(c.qty) || 0) * c.rate) }}</small>
                            </div>
                            <div class="cart-ctl">
                                <button type="button" class="btn btn-ghost btn-sm" @click="step(c, -1)">−</button>
                                <input v-model.number="c.qty" class="input cart-qty" type="number" min="1" @blur="fixQty(c)" />
                                <button type="button" class="btn btn-ghost btn-sm" @click="step(c, 1)">+</button>
                                <button type="button" class="cart-x" aria-label="Remove" @click="remove(c.id)">✕</button>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="products.length" class="empty">No products added yet. Use the search box above.</div>

                    <div class="field" style="margin-top: 16px"><label>Notes</label><input v-model="form.notes" class="input" placeholder="Anything we should know?" /></div>
                    <div class="totals"><div class="grand"><span>Estimated total</span><span>{{ inr(total) }}</span></div></div>
                </div>

                <p v-if="custMode === 'search'" class="cs-warn">Search your details above, or tap “New customer”, to place the order.</p>
                <button class="btn btn-primary po-full" style="padding: 14px" :disabled="sending || !chosen.length || custMode === 'search'">{{ sending ? 'Placing order…' : 'Place order' }}</button>
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
                    <div v-if="receipt.address || receipt.phone"><small>{{ [receipt.address, receipt.phone && 'Ph: ' + receipt.phone].filter(Boolean).join(' · ') }}</small></div>
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
            <div v-if="receipt.pay && (receipt.pay.upi_id || receipt.pay.account_number)" class="pr-pay">
                <b>Pay to</b>
                <div v-if="receipt.pay.upi_id">UPI: {{ receipt.pay.upi_id }}</div>
                <template v-if="receipt.pay.account_number">
                    <div>{{ receipt.pay.bank_name }}<template v-if="receipt.pay.bank_branch">, {{ receipt.pay.bank_branch }}</template></div>
                    <div>A/c name: {{ receipt.pay.account_holder || receipt.company }} · A/c no: {{ receipt.pay.account_number }}<template v-if="receipt.pay.ifsc"> · IFSC: {{ receipt.pay.ifsc }}</template></div>
                </template>
            </div>
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
.po-banner { display: block; width: calc(100% + 56px); height: 150px; margin: -28px -28px 18px; border-radius: 26px 26px 0 0; object-fit: cover; background: #1e1b4b; }
.po-pay { display: grid; gap: 8px; justify-items: center; margin: 18px auto 4px; padding: 16px; max-width: 360px; border-radius: 16px; background: var(--bg); border: 1px solid var(--border); font-size: 14px; }
.po-qr { width: 170px; height: 170px; border-radius: 12px; background: #fff; padding: 6px; }
.po-upi { text-decoration: none; }
.po-bank { display: grid; gap: 2px; color: var(--text-2); font-size: 13px; }
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

/* customer search */
.cs { position: relative; margin: 12px 0 10px; }
.cs-input { width: 100%; height: 48px; font-size: 15px; padding-right: 44px; }
.cs-spin {
    position: absolute; right: 14px; top: 15px; width: 18px; height: 18px; border-radius: 50%;
    border: 2.5px solid var(--border); border-top-color: var(--brand); animation: cs-rot .7s linear infinite;
}
@keyframes cs-rot { to { transform: rotate(360deg); } }
.cs-hint { margin: 0 0 10px; font-size: 12.5px; color: var(--text-2); }
.cs-list { display: grid; gap: 8px; margin-bottom: 12px; max-height: 300px; overflow-y: auto; }
.cs-item {
    width: 100%; display: grid; grid-template-columns: 40px 1fr auto; align-items: center; gap: 12px; text-align: left;
    padding: 10px 12px; border-radius: 14px; background: var(--surface); border: 1.5px solid var(--border);
    color: var(--text); font: inherit; cursor: pointer; transition: border-color .16s, background .16s;
}
.cs-item:hover { border-color: var(--brand); background: var(--brand-soft); }
.cs-ava { width: 40px; height: 40px; display: grid; place-items: center; border-radius: 12px; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--brand), var(--brand-2)); }
.cs-info { display: grid; gap: 2px; min-width: 0; }
.cs-info b { font-size: 14.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cs-info small { color: var(--text-2); font-size: 12.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cs-go { font-size: 12.5px; font-weight: 700; color: var(--brand); }
.cs-none { margin-bottom: 12px; padding: 12px; border-radius: 12px; text-align: center; font-size: 13.5px; color: var(--text-2); background: var(--surface); border: 1px dashed var(--border); }
.cs-new { width: 100%; margin-top: 2px; }
.cs-bar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin: 10px 0 14px; }
.cs-badge { padding: 4px 12px; border-radius: 99px; font-size: 12.5px; font-weight: 700; color: #15803d; background: #dcfce7; }
.cs-badge.new { color: var(--brand); background: var(--brand-soft); }
.wa-same { display: flex; align-items: center; gap: 7px; margin-top: 6px; font-size: 12.5px; font-weight: 600; color: var(--text-2); cursor: pointer; }
.is-same { opacity: .75; }
.cs-warn { margin: 0 0 10px; text-align: center; font-size: 13px; color: var(--text-2); }

/* product search */
.ps { position: relative; margin: 12px 0 14px; z-index: 5; }
.ps-input { width: 100%; height: 48px; font-size: 15px; }
.ps-list {
    position: absolute; left: 0; right: 0; top: calc(100% + 6px); max-height: 320px; overflow-y: auto;
    border-radius: 14px; background: var(--surface); border: 1px solid var(--border);
    box-shadow: 0 24px 48px -16px rgba(0, 0, 0, .45); padding: 6px;
}
.ps-item {
    width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 12px; text-align: left;
    padding: 10px 12px; border: 0; border-radius: 10px; background: transparent; color: var(--text); font: inherit; font-size: 14px; cursor: pointer;
}
.ps-item.on { background: var(--brand-soft); }
.ps-name { min-width: 0; font-weight: 600; }
.ps-meta { display: flex; align-items: center; gap: 10px; flex: none; color: var(--text-2); }
.ps-meta b { color: var(--text); font-size: 13.5px; }
.ps-added { padding: 2px 8px; border-radius: 99px; font-size: 11.5px; font-weight: 700; color: #15803d; background: #dcfce7; }
.ps-plus { display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; font-weight: 800; color: #fff; background: var(--brand); }
.ps-empty, .ps-more { padding: 12px; text-align: center; font-size: 13px; color: var(--text-2); }

/* added products */
.cart { display: grid; gap: 10px; }
.cart-title { font-size: 13px; font-weight: 700; color: var(--text-2); }
.cart-ctl { display: flex; align-items: center; gap: 6px; justify-content: flex-end; }
.cart-qty { width: 60px; text-align: center; padding: 7px; }
.cart-x {
    width: 32px; height: 32px; margin-left: 4px; display: grid; place-items: center; border: 0; border-radius: 10px;
    background: transparent; color: var(--text-2); font-size: 14px; cursor: pointer;
}
.cart-x:hover { color: #b91c1c; background: rgba(239, 68, 68, .12); }

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
    .po-banner { width: calc(100% + 36px); margin: -18px -18px 14px; height: 120px; border-radius: 22px 22px 0 0; }
    .po { padding: 20px 10px; }
    .po-card { padding: 18px; border-radius: 22px; }
    .ps-added { display: none; }
    .cs-go { display: none; }
    .cs-item { grid-template-columns: 40px 1fr; }
}
@media (prefers-reduced-motion: reduce) {
    .cs-spin { animation: none; }
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
    .print-area .pr-pay { margin-top: 16px; padding: 10px 12px; border: 1px solid #999; font-size: 13px; line-height: 1.6; }
    .print-area .pr-note { margin-top: 18px; font-size: 12px; text-align: center; }
}
</style>


