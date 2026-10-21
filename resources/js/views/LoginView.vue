<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '../components/Icon.vue';
import { authApi, errorMessage } from '../services/api';
import { applyContext, clearSession, homePath, session } from '../services/auth';

/* USER LOGIN: company admin, manager, stock keeper, sales, staff, viewer. (The product admin has a separate login.) */
const router = useRouter();
const route = useRoute();
const login = ref('');
const password = ref('');
const yearId = ref('');
const years = ref([]);
const show = ref(false);
const loading = ref(false);
const error = ref('');
const pick = ref(null);          // set when the user has more than one company
const yearRow = ref(null);       // the scrolling row of year chips

/* light / dark (same key the rest of the app uses, see app.js) */
const theme = ref(document.documentElement.getAttribute('data-theme') || 'light');
const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', theme.value);
    localStorage.setItem('theme', theme.value);
};

onMounted(async () => {
    try {
        years.value = (await authApi.years()).data.data;
        const today = new Date().toLocaleDateString('en-CA');   // local YYYY-MM-DD
        yearId.value = (years.value.find((y) => y.start_date <= today && y.end_date >= today) || years.value[0])?.id || '';
        await nextTick();
        const row = yearRow.value;
        const sel = row?.querySelector('.sel');
        if (row && sel) row.scrollLeft = sel.offsetLeft - row.offsetLeft;   // bring the selected year into view
    } catch {
        error.value = 'Cannot reach the server. Check your internet / API address.';
    }
});

const go = () => router.replace(route.query.next ? String(route.query.next) : homePath());

const submit = async () => {
    loading.value = true;
    error.value = '';
    try {
        clearSession();
        const { data } = await authApi.login({ login: login.value.trim(), password: password.value, year_id: yearId.value || null });
        applyContext(data);
        if (data.companies.length > 1) pick.value = { choice: data.company_id };
        else go();
    } catch (e) {
        error.value = errorMessage(e, 'Invalid credentials. Please try again.');
    } finally {
        loading.value = false;
    }
};

const chooseCompany = () => {
    applyContext({ company_id: pick.value.choice });
    go();
};

const companies = computed(() => session.companies);
const bars = [38, 62, 48, 80, 58, 92];
</script>

<template>
    <div class="lg">
        <!-- ============ left: brand + product preview ============ -->
        <aside class="lg-art" aria-hidden="true">
            <svg class="lg-trace" viewBox="0 0 600 800" preserveAspectRatio="xMidYMid slice" fill="none">
                <g class="lg-wire">
                    <path d="M-20 140 H120 L170 190 H330 L380 140 H620" />
                    <path d="M-20 330 H90 L140 380 H260 L310 330 H420 L470 380 H620" />
                    <path d="M-20 560 H200 L250 610 H400 L450 560 H620" />
                    <path d="M-20 720 H150 L200 670 H360 L410 720 H620" />
                </g>
                <g class="lg-pulse">
                    <path d="M-20 140 H120 L170 190 H330 L380 140 H620" />
                    <path d="M-20 330 H90 L140 380 H260 L310 330 H420 L470 380 H620" style="animation-delay: 1.2s" />
                    <path d="M-20 560 H200 L250 610 H400 L450 560 H620" style="animation-delay: 2.4s" />
                </g>
                <g class="lg-node">
                    <circle cx="170" cy="190" r="4" /><circle cx="380" cy="140" r="4" />
                    <circle cx="140" cy="380" r="4" /><circle cx="470" cy="380" r="4" />
                    <circle cx="250" cy="610" r="4" /><circle cx="450" cy="560" r="4" />
                </g>
            </svg>

            <div class="lg-brand">
                <img src="/images/mk-electrical.png" alt="" @error="$event.target.style.display = 'none'" />
                <span>MK Electrical</span>
            </div>

            <div class="lg-hero">
                <h2>Every company.<br />Every year.<br />One place.</h2>
                <p>Orders, purchase, sales and stock, kept separately for each company and account year, with access you control.</p>
            </div>

            <div class="lg-preview">
                <div class="lg-glass">
                    <div class="lg-glass-top">
                        <div class="lg-chips">
                            <span class="on">MK</span>
                        </div>
                        <small>April to March</small>
                    </div>
                    <div class="lg-bars">
                        <i v-for="(h, i) in bars" :key="i" :style="{ height: h + '%', animationDelay: 0.5 + i * 0.08 + 's' }"></i>
                    </div>
                    <div class="lg-formula">opening + purchase − sales − orders ± adjustments</div>
                </div>
                <div class="lg-toast">
                    <span class="ok"><Icon name="check" :size="14" /></span>
                    Stock is calculated for you, never typed in
                </div>
            </div>

            <small class="lg-copy">© {{ new Date().getFullYear() }} MK Electrical, Sivakasi, Tamil Nadu</small>
        </aside>

        <!-- ============ right: form ============ -->
        <main class="lg-form">
            <button class="lg-theme" type="button" :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'" @click="toggleTheme">
                <Icon :name="theme === 'dark' ? 'sun' : 'moon'" :size="18" />
            </button>

            <form v-if="!pick" class="lg-card" novalidate @submit.prevent="submit">
                <div class="lg-mobile-brand">
                    <img src="/images/mk-electrical.png" alt="" @error="$event.target.style.display = 'none'" />
                    <span>MK Electrical</span>
                </div>

                <h1>Welcome back</h1>
                <p class="lg-lead">Sign in and choose the account year you want to work in.</p>

                <div v-if="error" class="lg-alert" role="alert">
                    <Icon name="alert" :size="18" /> <span>{{ error }}</span>
                </div>

                <div class="lg-field">
                    <span class="lg-ico"><Icon name="user" :size="18" /></span>
                    <input id="login" v-model="login" type="text" placeholder=" " autocomplete="username" autofocus />
                    <label for="login">Email or phone</label>
                </div>

                <div class="lg-field">
                    <span class="lg-ico">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2.5" /><path d="M8 11V8a4 4 0 018 0v3" /></svg>
                    </span>
                    <input id="password" v-model="password" :type="show ? 'text' : 'password'" placeholder=" " autocomplete="current-password" />
                    <label for="password">Password</label>
                    <button type="button" class="lg-eye" :aria-label="show ? 'Hide password' : 'Show password'" @click="show = !show">
                        <Icon :name="show ? 'eyeoff' : 'eye'" :size="18" />
                    </button>
                </div>

                <fieldset class="lg-years">
                    <legend>Account year</legend>
                    <div ref="yearRow" class="lg-years-row">
                        <label v-for="y in years" :key="y.id" :class="{ sel: yearId === y.id }">
                            <input v-model="yearId" type="radio" name="year" :value="y.id" />
                            <b>{{ y.label }}</b>
                            <small>Apr {{ y.start_date.slice(2, 4) }} to Mar {{ y.end_date.slice(2, 4) }}</small>
                        </label>
                    </div>
                </fieldset>

                <button class="lg-btn" :disabled="loading || !login || !password">
                    <span v-if="loading" class="lg-spin" aria-hidden="true"></span>
                    {{ loading ? 'Signing in…' : 'Sign in' }}
                </button>

                <p class="lg-foot">
                    Are you a customer?
                    <router-link to="/order-data">Place an order</router-link>
                </p>
            </form>

            <form v-else class="lg-card" @submit.prevent="chooseCompany">
                <h1>Choose company</h1>
                <p class="lg-lead">You can open more than one company. You can switch later from the top bar.</p>

                <div class="lg-companies">
                    <label v-for="c in companies" :key="c.id" :class="{ sel: pick.choice === c.id }">
                        <input v-model="pick.choice" type="radio" :value="c.id" />
                        <span class="code">{{ c.code }}</span>
                        <span class="meta"><b>{{ c.name }}</b><small>{{ c.role?.name }}</small></span>
                        <span class="tick"><Icon name="check" :size="16" /></span>
                    </label>
                </div>

                <button class="lg-btn">Continue</button>
            </form>
        </main>
    </div>
</template>

<style scoped>
.lg {
    --spark: #fbbf24;
    min-height: 100vh;
    min-height: 100dvh;
    display: grid;
    grid-template-columns: minmax(0, 1.08fr) minmax(0, 1fr);
    background: var(--bg);
    color: var(--text);
}

/* ---------- left panel ---------- */
.lg-art {
    position: relative;
    overflow: hidden;
    isolation: isolate;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 28px;
    padding: 40px 48px;
    color: #fff;
    background:
        radial-gradient(900px 520px at 85% -10%, rgba(139, 92, 246, .55), transparent 60%),
        radial-gradient(700px 500px at -10% 110%, rgba(99, 102, 241, .5), transparent 60%),
        #0a0f24;
}
.lg-trace { position: absolute; inset: 0; width: 100%; height: 100%; z-index: -1; }
.lg-wire path { stroke: rgba(255, 255, 255, .11); stroke-width: 1.5; }
.lg-pulse path {
    stroke: var(--spark); stroke-width: 2; stroke-linecap: round;
    stroke-dasharray: 60 1400; stroke-dashoffset: 1460;
    filter: drop-shadow(0 0 6px rgba(251, 191, 36, .9));
    animation: lg-run 5.2s linear infinite;
}
.lg-node circle { fill: #0a0f24; stroke: rgba(255, 255, 255, .35); stroke-width: 1.5; }
@keyframes lg-run { to { stroke-dashoffset: 0; } }

.lg-brand { display: flex; align-items: center; gap: 12px; font-weight: 800; font-size: 17px; letter-spacing: .01em; }
.lg-brand img, .lg-mobile-brand img { width: 42px; height: 42px; border-radius: 12px; background: #fff; object-fit: cover; }

.lg-hero h2 { margin: 0 0 16px; font-size: clamp(34px, 3.6vw, 50px); line-height: 1.06; letter-spacing: -.035em; font-weight: 800; }
.lg-hero p { margin: 0; max-width: 430px; line-height: 1.65; font-size: 15px; color: rgba(255, 255, 255, .74); }

.lg-preview { position: relative; max-width: 420px; padding-bottom: 22px; }
.lg-glass {
    padding: 18px 18px 14px; border-radius: 22px;
    background: rgba(255, 255, 255, .07); border: 1px solid rgba(255, 255, 255, .16);
    backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 30px 60px -30px rgba(0, 0, 0, .6);
}
.lg-glass-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.lg-glass-top small { font-size: 12px; color: rgba(255, 255, 255, .6); }
.lg-chips { display: flex; gap: 6px; }
.lg-chips span { padding: 5px 12px; border-radius: 99px; font-size: 12px; font-weight: 700; color: rgba(255, 255, 255, .7); background: rgba(255, 255, 255, .08); }
.lg-chips span.on { background: #fff; color: #1e1b4b; }
.lg-bars { display: flex; align-items: flex-end; gap: 10px; height: 92px; margin: 18px 4px 14px; }
.lg-bars i {
    flex: 1; border-radius: 8px 8px 3px 3px; transform-origin: bottom;
    background: linear-gradient(180deg, #a5b4fc, #6366f1);
    animation: lg-grow .7s cubic-bezier(.2, .8, .2, 1) both;
}
.lg-bars i:last-child { background: linear-gradient(180deg, #fde68a, var(--spark)); }
@keyframes lg-grow { from { transform: scaleY(.05); opacity: 0; } }
.lg-formula { font-size: 12px; color: rgba(255, 255, 255, .62); border-top: 1px dashed rgba(255, 255, 255, .2); padding-top: 10px; }

.lg-toast {
    position: absolute; right: -14px; bottom: 0; display: flex; align-items: center; gap: 9px;
    padding: 9px 14px 9px 10px; border-radius: 14px; font-size: 12.5px; font-weight: 600;
    background: #fff; color: #1e1b4b; box-shadow: 0 18px 36px -14px rgba(0, 0, 0, .55);
}
.lg-toast .ok { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; background: #dcfce7; color: #15803d; }

.lg-copy { color: rgba(255, 255, 255, .5); font-size: 12px; }

/* ---------- right panel ---------- */
.lg-form {
    position: relative; display: grid; place-items: center; padding: 40px 28px;
    background: radial-gradient(520px 360px at 100% 0%, var(--brand-soft), transparent 70%), var(--bg);
}
.lg-theme {
    position: absolute; top: 22px; right: 22px; width: 40px; height: 40px; border-radius: 12px; cursor: pointer;
    display: grid; place-items: center; border: 1px solid var(--border); background: var(--surface); color: var(--text-2);
    transition: color .18s, border-color .18s;
}
.lg-theme:hover { color: var(--brand); border-color: var(--brand); }

.lg-card { width: 100%; max-width: 410px; }
.lg-card h1 { margin: 0 0 8px; font-size: 32px; letter-spacing: -.03em; font-weight: 800; }
.lg-lead { margin: 0 0 26px; color: var(--text-2); font-size: 15px; line-height: 1.55; }
.lg-mobile-brand { display: none; align-items: center; gap: 10px; font-weight: 800; margin-bottom: 22px; }

.lg-alert {
    display: flex; gap: 10px; align-items: flex-start; margin-bottom: 18px; padding: 12px 14px; border-radius: 14px;
    font-size: 14px; line-height: 1.4; color: #b91c1c; background: rgba(239, 68, 68, .1); border: 1px solid rgba(239, 68, 68, .28);
}
.lg-alert svg { flex: none; margin-top: 1px; }
:root[data-theme='dark'] .lg-alert { color: #fca5a5; }

/* floating-label fields */
.lg-field { position: relative; margin-bottom: 14px; }
.lg-field input {
    width: 100%; height: 56px; padding: 22px 46px 6px 46px; font: inherit; font-size: 15px; color: var(--text);
    background: var(--surface); border: 1.5px solid var(--border); border-radius: 16px; outline: none;
    transition: border-color .18s, box-shadow .18s;
}
.lg-field input:hover { border-color: color-mix(in srgb, var(--brand) 45%, var(--border)); }
.lg-field input:focus { border-color: var(--brand); box-shadow: 0 0 0 4px var(--brand-soft); }
.lg-field label {
    position: absolute; left: 46px; top: 17px; font-size: 15px; color: var(--text-2); pointer-events: none;
    transform-origin: left top; transition: transform .16s ease, color .16s ease;
}
.lg-field input:focus + label,
.lg-field input:not(:placeholder-shown) + label { transform: translateY(-10px) scale(.78); }
.lg-field input:focus + label { color: var(--brand); }
.lg-ico { position: absolute; left: 15px; top: 0; height: 56px; display: grid; place-items: center; color: var(--text-2); pointer-events: none; }
.lg-field:focus-within .lg-ico { color: var(--brand); }
.lg-eye {
    position: absolute; right: 6px; top: 6px; width: 44px; height: 44px; display: grid; place-items: center;
    border: 0; background: transparent; color: var(--text-2); border-radius: 12px; cursor: pointer;
}
.lg-eye:hover { color: var(--brand); background: var(--brand-soft); }

/* account year chips */
.lg-years { border: 0; margin: 20px 0 22px; padding: 0; min-width: 0; }
.lg-years-row {
    position: relative; display: flex; gap: 8px; overflow-x: auto; overscroll-behavior-x: contain;
    scroll-snap-type: x proximity; padding: 3px 3px 10px; margin: -3px -3px 0;
    scrollbar-width: thin; scrollbar-color: var(--border) transparent;
}
.lg-years-row::-webkit-scrollbar { height: 6px; }
.lg-years-row::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
.lg-years-row::-webkit-scrollbar-thumb:hover { background: var(--text-2); }
.lg-years legend { padding: 0; margin-bottom: 10px; font-size: 13px; font-weight: 600; color: var(--text-2); }
.lg-years label {
    flex: 0 0 calc((100% - 16px) / 3); scroll-snap-align: start;
    position: relative; cursor: pointer; display: grid; gap: 1px; padding: 10px 12px; border-radius: 14px; text-align: center;
    background: var(--surface); border: 1.5px solid var(--border); transition: border-color .16s, background .16s;
}
.lg-years label:hover { border-color: color-mix(in srgb, var(--brand) 50%, var(--border)); }
.lg-years label.sel { border-color: var(--brand); background: var(--brand-soft); }
.lg-years input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
.lg-years label:has(input:focus-visible) { outline: 3px solid var(--brand); outline-offset: 2px; }
.lg-years b { font-size: 14px; }
.lg-years small { font-size: 11.5px; color: var(--text-2); }

/* button */
.lg-btn {
    width: 100%; height: 54px; display: flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer;
    border: 0; border-radius: 16px; font: inherit; font-size: 16px; font-weight: 700; color: #fff;
    background: linear-gradient(135deg, var(--brand), var(--brand-2));
    box-shadow: 0 16px 30px -14px var(--brand);
    transition: transform .16s, box-shadow .16s, opacity .16s;
}
.lg-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 20px 34px -14px var(--brand); }
.lg-btn:active:not(:disabled) { transform: translateY(0); }
.lg-btn:focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; }
.lg-btn:disabled { opacity: .5; cursor: not-allowed; }
.lg-spin { width: 18px; height: 18px; border-radius: 50%; border: 2.5px solid rgba(255, 255, 255, .4); border-top-color: #fff; animation: lg-rot .7s linear infinite; }
@keyframes lg-rot { to { transform: rotate(360deg); } }

.lg-foot { text-align: center; margin: 24px 0 0; font-size: 14px; color: var(--text-2); }
.lg-foot a { color: var(--brand); font-weight: 700; text-decoration: none; margin-left: 4px; }
.lg-foot a:hover { text-decoration: underline; }

/* choose company */
.lg-companies { display: grid; gap: 10px; margin-bottom: 22px; }
.lg-companies label {
    position: relative; cursor: pointer; display: grid; grid-template-columns: 48px 1fr auto; align-items: center; gap: 14px;
    padding: 14px 16px; border-radius: 16px; background: var(--surface); border: 1.5px solid var(--border);
    transition: border-color .16s, background .16s;
}
.lg-companies label:hover { border-color: color-mix(in srgb, var(--brand) 50%, var(--border)); }
.lg-companies label.sel { border-color: var(--brand); background: var(--brand-soft); }
.lg-companies input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
.lg-companies label:has(input:focus-visible) { outline: 3px solid var(--brand); outline-offset: 2px; }
.lg-companies .code {
    width: 48px; height: 48px; display: grid; place-items: center; border-radius: 14px; font-weight: 800; font-size: 14px;
    color: #fff; background: linear-gradient(135deg, var(--brand), var(--brand-2));
}
.lg-companies .meta { display: grid; gap: 2px; min-width: 0; }
.lg-companies .meta b { font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lg-companies .meta small { color: var(--text-2); font-size: 12.5px; }
.lg-companies .tick {
    width: 24px; height: 24px; display: grid; place-items: center; border-radius: 50%; color: #fff;
    background: var(--brand); transform: scale(0); transition: transform .18s cubic-bezier(.3, 1.6, .5, 1);
}
.lg-companies label.sel .tick { transform: scale(1); }

/* ---------- small screens ---------- */
@media (max-width: 920px) {
    .lg { grid-template-columns: 1fr; }
    .lg-art { display: none; }
    .lg-mobile-brand { display: flex; }
    .lg-form { align-items: start; padding-top: 72px; }
}
@media (max-width: 420px) {
    .lg-card h1 { font-size: 28px; }
}
@media (prefers-reduced-motion: reduce) {
    .lg-pulse path, .lg-bars i, .lg-spin { animation: none; }
    .lg-pulse { display: none; }
    .lg * { transition: none !important; }
}
</style>

