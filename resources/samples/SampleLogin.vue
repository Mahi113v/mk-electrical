<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '../components/Icon.vue';
import { authApi, errorMessage } from '../services/api';
import { applyContext, clearSession, homePath, session } from '../services/auth';

/* USER LOGIN: company admin, manager, stock keeper, sales, staff, viewer. (The product admin has a separate login.) */
const LOGO = '/images/mk-logo.png';
const router = useRouter();
const route = useRoute();
const login = ref('');
const password = ref('');
const yearId = ref('');
const years = ref([]);
const show = ref(false);
const loading = ref(false);
const error = ref('');
const pick = ref(null);
const yearRow = ref(null);

const theme = ref(document.documentElement.getAttribute('data-theme') || 'light');
const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', theme.value);
    localStorage.setItem('theme', theme.value);
};

onMounted(async () => {
    try {
        years.value = (await authApi.years()).data.data;
        const today = new Date().toLocaleDateString('en-CA');
        yearId.value = (years.value.find((y) => y.start_date <= today && y.end_date >= today) || years.value[0])?.id || '';
        await nextTick();
        const row = yearRow.value;
        const sel = row?.querySelector('.sel');
        if (row && sel) row.scrollLeft = sel.offsetLeft - row.offsetLeft;
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
</script>

<template>
    <div class="lg">
        <button class="lg-theme" type="button" :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'" @click="toggleTheme">
            <Icon :name="theme === 'dark' ? 'sun' : 'moon'" :size="18" />
        </button>

        <form v-if="!pick" class="lg-card" novalidate @submit.prevent="submit">
            <div class="lg-logo">
                <img :src="LOGO" alt="MK Software" @error="$event.target.style.display = 'none'" />
            </div>
            <h1>MK Software</h1>
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
            <div class="lg-logo">
                <img :src="LOGO" alt="MK Software" @error="$event.target.style.display = 'none'" />
            </div>
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
    </div>
</template>

<style scoped>
.lg {
    position: relative;
    min-height: 100vh;
    min-height: 100dvh;
    display: grid;
    place-items: center;
    padding: 32px 16px;
    color: var(--text);
    background:
        radial-gradient(800px 480px at 12% -8%, rgba(79, 70, 229, .55), transparent 60%),
        radial-gradient(700px 500px at 100% 110%, rgba(139, 92, 246, .45), transparent 60%),
        #0f0b24;
}
.lg-theme {
    position: absolute; top: 20px; right: 20px; width: 40px; height: 40px; border-radius: 12px; cursor: pointer;
    display: grid; place-items: center; border: 1px solid rgba(255, 255, 255, .18);
    background: rgba(255, 255, 255, .08); color: #fff; transition: background .18s;
}
.lg-theme:hover { background: rgba(255, 255, 255, .16); }

.lg-card {
    width: 100%; max-width: 430px; padding: 30px 30px 26px; border-radius: 26px;
    background: var(--surface); border: 1px solid var(--border);
    box-shadow: 0 40px 80px -30px rgba(0, 0, 0, .7);
}
.lg-logo { display: flex; justify-content: center; margin-bottom: 10px; }
.lg-logo img { width: 62px; height: 62px; border-radius: 16px; object-fit: cover; background: #fff; box-shadow: 0 10px 24px -10px rgba(0, 0, 0, .5); }
.lg-card h1 { margin: 0 0 6px; text-align: center; font-size: 28px; letter-spacing: -.03em; font-weight: 800; }
.lg-lead { margin: 0 0 22px; text-align: center; color: var(--text-2); font-size: 14px; line-height: 1.55; }

.lg-alert {
    display: flex; gap: 10px; align-items: flex-start; margin-bottom: 16px; padding: 12px 14px; border-radius: 14px;
    font-size: 14px; line-height: 1.4; color: #b91c1c; background: rgba(239, 68, 68, .1); border: 1px solid rgba(239, 68, 68, .28);
}
.lg-alert svg { flex: none; margin-top: 1px; }
:root[data-theme='dark'] .lg-alert { color: #fca5a5; }

.lg-field { position: relative; margin-bottom: 14px; }
.lg-field input {
    width: 100%; height: 56px; padding: 22px 46px 6px 46px; font: inherit; font-size: 15px; color: var(--text);
    background: var(--bg); border: 1.5px solid var(--border); border-radius: 16px; outline: none;
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

.lg-years { border: 0; margin: 18px 0 20px; padding: 0; min-width: 0; }
.lg-years-row {
    position: relative; display: flex; gap: 8px; overflow-x: auto; overscroll-behavior-x: contain;
    scroll-snap-type: x proximity; padding: 3px 3px 10px; margin: -3px -3px 0;
    scrollbar-width: thin; scrollbar-color: var(--border) transparent;
}
.lg-years-row::-webkit-scrollbar { height: 6px; }
.lg-years-row::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
.lg-years legend { padding: 0; margin-bottom: 10px; font-size: 13px; font-weight: 600; color: var(--text-2); }
.lg-years label {
    flex: 0 0 calc((100% - 16px) / 3); scroll-snap-align: start;
    position: relative; cursor: pointer; display: grid; gap: 1px; padding: 10px 8px; border-radius: 14px; text-align: center;
    background: var(--bg); border: 1.5px solid var(--border); transition: border-color .16s, background .16s;
}
.lg-years label:hover { border-color: color-mix(in srgb, var(--brand) 50%, var(--border)); }
.lg-years label.sel { border-color: var(--brand); background: var(--brand-soft); }
.lg-years input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
.lg-years label:has(input:focus-visible) { outline: 3px solid var(--brand); outline-offset: 2px; }
.lg-years b { font-size: 14px; }
.lg-years small { font-size: 11px; color: var(--text-2); }

.lg-btn {
    width: 100%; height: 52px; display: flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer;
    border: 0; border-radius: 999px; font: inherit; font-size: 16px; font-weight: 700; color: #fff;
    background: linear-gradient(135deg, var(--brand), var(--brand-2));
    box-shadow: 0 16px 30px -14px var(--brand);
    transition: transform .16s, box-shadow .16s, opacity .16s;
}
.lg-btn:hover:not(:disabled) { transform: translateY(-2px); }
.lg-btn:active:not(:disabled) { transform: translateY(0); }
.lg-btn:focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; }
.lg-btn:disabled { opacity: .5; cursor: not-allowed; }
.lg-spin { width: 18px; height: 18px; border-radius: 50%; border: 2.5px solid rgba(255, 255, 255, .4); border-top-color: #fff; animation: lg-rot .7s linear infinite; }
@keyframes lg-rot { to { transform: rotate(360deg); } }

.lg-foot { text-align: center; margin: 20px 0 0; font-size: 14px; color: var(--text-2); }
.lg-foot a { color: var(--brand); font-weight: 700; text-decoration: none; margin-left: 4px; }
.lg-foot a:hover { text-decoration: underline; }

.lg-companies { display: grid; gap: 10px; margin-bottom: 22px; }
.lg-companies label {
    position: relative; cursor: pointer; display: grid; grid-template-columns: 48px 1fr auto; align-items: center; gap: 14px;
    padding: 14px 16px; border-radius: 16px; background: var(--bg); border: 1.5px solid var(--border);
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

@media (max-width: 480px) {
    .lg-card { padding: 24px 20px 22px; border-radius: 22px; }
    .lg-card h1 { font-size: 25px; }
}
@media (prefers-reduced-motion: reduce) {
    .lg-spin { animation: none; }
    .lg * { transition: none !important; }
}
</style>


<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Icon from '../components/Icon.vue';
import { authApi, errorMessage } from '../services/api';
import { applyContext, clearSession, homePath, session } from '../services/auth';

/* USER LOGIN: company admin, manager, stock keeper, sales, staff, viewer. (The product admin has a separate login.) */

/* ---- edit these ---- */
const LOGO = '/images/mk-logo.png';
const BRAND = 'MK Software';
const TAGLINE = 'Business Solutions';
const LOCATION = 'Sivakasi, Tamil Nadu, India';
const SUPPORT_EMAIL = 'spark_software@yahoo.com';
const SUPPORT_PHONE = '+91-93441-20626';
const MAX_CHIPS = 3;             // more than this many years -> dropdown
/* -------------------- */

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

const useDropdown = computed(() => years.value.length > MAX_CHIPS);

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
const yearLabel = (y) => `${y.label} (Apr ${y.start_date.slice(2, 4)} to Mar ${y.end_date.slice(2, 4)})`;
</script>

<template>
    <div class="lg">
        <button class="lg-theme" type="button" :aria-label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'" @click="toggleTheme">
            <Icon :name="theme === 'dark' ? 'sun' : 'moon'" :size="18" />
        </button>

        <div class="lg-shell">
            <!-- ============ left: brand ============ -->
            <aside class="lg-side">
                <img class="lg-logo" :src="LOGO" :alt="BRAND" @error="$event.target.style.display = 'none'" />
                <h1 class="lg-brand">{{ BRAND }}</h1>
                <div class="lg-tag">{{ TAGLINE }}</div>
                <div class="lg-line"></div>

                <div class="lg-info">
                    <div class="lg-info-t">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-5.6 7-11a7 7 0 10-14 0c0 5.4 7 11 7 11z" /><circle cx="12" cy="10" r="2.5" /></svg>
                        Our Location
                    </div>
                    <div class="lg-info-v">{{ LOCATION }}</div>
                </div>

                <div class="lg-info">
                    <div class="lg-info-t">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 7l9 6 9-6" /></svg>
                        Contact Support
                    </div>
                    <div class="lg-info-v sm">{{ SUPPORT_EMAIL }}</div>
                    <div class="lg-info-v sm">{{ SUPPORT_PHONE }}</div>
                </div>

                <small class="lg-copy">© {{ new Date().getFullYear() }} <b>{{ BRAND }}</b></small>
            </aside>

            <!-- ============ right: form ============ -->
            <main class="lg-main">
                <form v-if="!pick" class="lg-form" novalidate @submit.prevent="submit">
                    <div class="lg-badge">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2.5" /><path d="M8 11V8a4 4 0 018 0v3" /></svg>
                        Secure access portal
                    </div>

                    <div v-if="error" class="lg-alert" role="alert">
                        <Icon name="alert" :size="16" /> <span>{{ error }}</span>
                    </div>

                    <label class="lg-lbl" for="login">
                        <Icon name="user" :size="15" /> Email or phone
                    </label>
                    <input id="login" v-model="login" class="lg-input" type="text" autocomplete="username" autofocus />

                    <label class="lg-lbl" for="password">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2.5" /><path d="M8 11V8a4 4 0 018 0v3" /></svg>
                        Password
                    </label>
                    <div class="lg-pass">
                        <input id="password" v-model="password" class="lg-input" :type="show ? 'text' : 'password'" autocomplete="current-password" />
                        <button type="button" class="lg-eye" :aria-label="show ? 'Hide password' : 'Show password'" @click="show = !show">
                            <Icon :name="show ? 'eyeoff' : 'eye'" :size="18" />
                        </button>
                    </div>

                    <!-- account year: chips (3 or fewer) / dropdown (more than 3) -->
                    <div class="lg-lbl">Account year</div>
                    <select v-if="useDropdown" v-model="yearId" class="lg-input lg-select" aria-label="Account year">
                        <option v-for="y in years" :key="y.id" :value="y.id">{{ yearLabel(y) }}</option>
                    </select>
                    <div v-else class="lg-years">
                        <label v-for="y in years" :key="y.id" :class="{ sel: yearId === y.id }">
                            <input v-model="yearId" type="radio" name="year" :value="y.id" />
                            <b>{{ y.label }}</b>
                            <small>Apr {{ y.start_date.slice(2, 4) }} to Mar {{ y.end_date.slice(2, 4) }}</small>
                        </label>
                    </div>

                    <button class="lg-btn" :disabled="loading || !login || !password">
                        <span v-if="loading" class="lg-spin" aria-hidden="true"></span>
                        {{ loading ? 'Signing in…' : 'Access Dashboard' }}
                    </button>

                    <p class="lg-foot">
                        Are you a customer?
                        <router-link to="/order-data">Place an order</router-link>
                    </p>
                </form>

                <form v-else class="lg-form" @submit.prevent="chooseCompany">
                    <div class="lg-badge">Choose company</div>
                    <p class="lg-lead">You can open more than one company. You can switch later from the top bar.</p>

                    <div class="lg-companies">
                        <label v-for="c in companies" :key="c.id" :class="{ sel: pick.choice === c.id }">
                            <input v-model="pick.choice" type="radio" :value="c.id" />
                            <span class="code">{{ c.code }}</span>
                            <span class="meta"><b>{{ c.name }}</b><small>{{ c.role?.name }}</small></span>
                            <span class="tick"><Icon name="check" :size="14" /></span>
                        </label>
                    </div>

                    <button class="lg-btn">Continue</button>
                </form>
            </main>
        </div>
    </div>
</template>

<style scoped>
.lg {
    --amber: #f59e0b;
    --orange: #f97316;
    position: relative;
    min-height: 100vh;
    min-height: 100dvh;
    display: grid;
    place-items: center;
    padding: 32px 16px;
    background: linear-gradient(135deg, #e3f2fd, #b9e3fa);
}
:root[data-theme='dark'] .lg { background: linear-gradient(135deg, #0b1220, #101a33); }

.lg-theme {
    position: absolute; top: 18px; right: 18px; width: 40px; height: 40px; border-radius: 12px; cursor: pointer;
    display: grid; place-items: center; border: 1px solid rgba(15, 23, 42, .15);
    background: rgba(255, 255, 255, .6); color: #1e293b; transition: background .18s;
}
.lg-theme:hover { background: #fff; }
:root[data-theme='dark'] .lg-theme { border-color: rgba(255, 255, 255, .18); background: rgba(255, 255, 255, .08); color: #fff; }

/* the card */
.lg-shell {
    width: 100%; max-width: 1024px; display: grid; grid-template-columns: 1fr 1fr; overflow: hidden;
    border-radius: 28px; color: #fff; background: #1e2638;
    box-shadow: 0 40px 80px -30px rgba(15, 23, 42, .55);
}

/* left */
.lg-side { display: flex; flex-direction: column; padding: 32px; background: #1b2438; }
.lg-logo { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; background: #fff; border: 3px solid var(--amber); box-shadow: 0 0 24px rgba(245, 158, 11, .35); }
.lg-brand { margin: 28px 0 4px; font-size: clamp(30px, 3.4vw, 40px); line-height: 1.05; font-weight: 900; letter-spacing: -.01em; text-transform: uppercase; }
.lg-tag { font-size: 17px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; }
.lg-line { width: 96px; height: 4px; margin: 14px 0 24px; border-radius: 99px; background: var(--orange); }

.lg-info { margin-bottom: 12px; padding: 14px 16px; border-radius: 14px; background: rgba(255, 255, 255, .04); }
.lg-info-t { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-weight: 700; font-size: 16px; }
.lg-info-v { color: #fbbf24; font-size: 15px; }
.lg-info-v.sm { font-size: 13.5px; margin-top: 4px; }
.lg-copy { margin-top: auto; padding-top: 18px; color: rgba(255, 255, 255, .6); font-size: 13px; }
.lg-copy b { color: #fbbf24; }

/* right */
.lg-main { display: grid; align-content: start; padding: 32px; background: #263148; border-left: 1px solid rgba(255, 255, 255, .06); }
.lg-form { width: 100%; }
.lg-badge {
    display: flex; align-items: center; justify-content: center; gap: 8px; width: max-content; margin: 0 auto 20px;
    padding: 9px 22px; border-radius: 99px; font-size: 12.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
    color: #6ee7b7; background: rgba(16, 185, 129, .15); border: 1px solid rgba(16, 185, 129, .45);
}
.lg-lead { margin: 0 0 16px; text-align: center; color: rgba(255, 255, 255, .7); font-size: 14px; line-height: 1.5; }

.lg-alert {
    display: flex; gap: 8px; align-items: flex-start; margin-bottom: 14px; padding: 10px 12px; border-radius: 12px;
    font-size: 13.5px; line-height: 1.4; color: #fecaca; background: rgba(239, 68, 68, .15); border: 1px solid rgba(239, 68, 68, .4);
}
.lg-alert svg { flex: none; margin-top: 1px; }

.lg-lbl { display: flex; align-items: center; gap: 7px; margin: 0 0 7px; font-size: 13.5px; font-weight: 700; color: #fff; }
.lg-input {
    width: 100%; height: 50px; padding: 0 16px; margin-bottom: 16px; font: inherit; font-size: 15px; color: #0f172a;
    background: #e8eefc; border: 2px solid #8b97b3; border-radius: 12px; outline: none;
    transition: border-color .16s, box-shadow .16s;
}
.lg-input:focus { border-color: var(--amber); box-shadow: 0 0 0 4px rgba(245, 158, 11, .22); }

.lg-pass { position: relative; }
.lg-pass .lg-input { padding-right: 54px; }
.lg-eye {
    position: absolute; right: 0; top: 0; width: 48px; height: 50px; display: grid; place-items: center;
    border: 0; border-left: 2px solid #8b97b3; border-radius: 0 12px 12px 0; background: #2c3852; color: #cbd5e1; cursor: pointer;
}
.lg-eye:hover { color: var(--amber); }

.lg-select {
    appearance: none; -webkit-appearance: none; cursor: pointer; padding-right: 44px;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23334155' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>");
    background-repeat: no-repeat; background-position: right 16px center;
}

/* year chips */
.lg-years { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 18px; }
.lg-years label {
    position: relative; cursor: pointer; display: grid; gap: 1px; padding: 10px 6px; text-align: center; border-radius: 12px;
    background: rgba(255, 255, 255, .06); border: 1.5px solid rgba(255, 255, 255, .18); transition: border-color .16s, background .16s;
}
.lg-years label:hover { border-color: rgba(245, 158, 11, .6); }
.lg-years label.sel { border-color: var(--amber); background: rgba(245, 158, 11, .16); }
.lg-years input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
.lg-years label:has(input:focus-visible) { outline: 3px solid var(--amber); outline-offset: 2px; }
.lg-years b { font-size: 14px; }
.lg-years small { font-size: 11px; color: rgba(255, 255, 255, .65); }

/* button */
.lg-btn {
    width: 100%; height: 56px; display: flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer;
    border: 0; border-radius: 12px; font: inherit; font-size: 15px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
    color: #1b2438; background: linear-gradient(90deg, var(--amber), var(--orange));
    transition: transform .16s, box-shadow .16s, opacity .16s;
}
.lg-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 16px 28px -14px var(--orange); }
.lg-btn:focus-visible { outline: 3px solid #fff; outline-offset: 3px; }
.lg-btn:disabled { opacity: .55; cursor: not-allowed; }
.lg-spin { width: 18px; height: 18px; border-radius: 50%; border: 2.5px solid rgba(27, 36, 56, .3); border-top-color: #1b2438; animation: lg-rot .7s linear infinite; }
@keyframes lg-rot { to { transform: rotate(360deg); } }

.lg-foot { text-align: center; margin: 18px 0 0; font-size: 14px; color: rgba(255, 255, 255, .7); }
.lg-foot a { color: #fbbf24; font-weight: 700; text-decoration: none; margin-left: 4px; }
.lg-foot a:hover { text-decoration: underline; }

/* choose company */
.lg-companies { display: grid; gap: 10px; margin-bottom: 20px; max-height: 320px; overflow-y: auto; }
.lg-companies label {
    position: relative; cursor: pointer; display: grid; grid-template-columns: 46px 1fr auto; align-items: center; gap: 12px;
    padding: 12px 14px; border-radius: 14px; background: rgba(255, 255, 255, .06); border: 1.5px solid rgba(255, 255, 255, .18);
    transition: border-color .16s, background .16s;
}
.lg-companies label:hover { border-color: rgba(245, 158, 11, .6); }
.lg-companies label.sel { border-color: var(--amber); background: rgba(245, 158, 11, .16); }
.lg-companies input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
.lg-companies label:has(input:focus-visible) { outline: 3px solid var(--amber); outline-offset: 2px; }
.lg-companies .code {
    width: 46px; height: 46px; display: grid; place-items: center; border-radius: 12px; font-weight: 800; font-size: 14px;
    color: #1b2438; background: linear-gradient(135deg, var(--amber), var(--orange));
}
.lg-companies .meta { display: grid; gap: 2px; min-width: 0; }
.lg-companies .meta b { font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lg-companies .meta small { color: rgba(255, 255, 255, .65); font-size: 12.5px; }
.lg-companies .tick {
    width: 22px; height: 22px; display: grid; place-items: center; border-radius: 50%; color: #1b2438;
    background: var(--amber); transform: scale(0); transition: transform .18s cubic-bezier(.3, 1.6, .5, 1);
}
.lg-companies label.sel .tick { transform: scale(1); }

/* small screens */
@media (max-width: 820px) {
    .lg-shell { grid-template-columns: 1fr; max-width: 460px; }
    .lg-side { padding: 24px 24px 20px; }
    .lg-brand { margin-top: 16px; font-size: 28px; }
    .lg-line { margin-bottom: 4px; }
    .lg-info, .lg-copy { display: none; }
    .lg-main { padding: 24px; border-left: 0; border-top: 1px solid rgba(255, 255, 255, .06); }
}
@media (prefers-reduced-motion: reduce) {
    .lg-spin { animation: none; }
    .lg * { transition: none !important; }
}
</style>

