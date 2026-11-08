<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import Icon from '../../components/Icon.vue';
import Modal from '../../components/Modal.vue';
import TablePicker from '../../components/TablePicker.vue';
import { adminApi, api, errorMessage } from '../../services/api';
import { isPlatformAdmin } from '../../services/auth';
import { toast } from '../../services/toast';

const platform = isPlatformAdmin();   // company admin: his own company only, no database screens
const DEFAULT_LOGO = '/images/mk-electrical.png';
const blank = () => ({
    id: null, code: '', name: '', gstin: '', phone: '', email: '', address: '', city: '', state: '', pincode: '', status: 'active', allow_negative_stock: false,
    upi_id: '', upi_name: '', bank_name: '', account_holder: '', account_number: '', ifsc: '', bank_branch: '',
    logo_url: null, image_url: null, database_name: null,
});
const rows = ref([]);
const loading = ref(true);
const search = ref('');
const status = ref('');
const form = ref(null);
const tab = ref('details');
const saving = ref(false);
const usersOf = ref(null);

/* logo / image chosen in the form (uploaded right after Save) */
const files = ref({ logo: null, image: null });
const previews = ref({ logo: null, image: null });
const removeFlags = ref({ logo: false, image: false });
const sources = ref({ logo: null, image: null });   // the original file, so "Adjust" can open it again

/* database of a NEW company */
const newDb = ref({ on: false, name: '', tables: [] });
const mainTables = ref({ database: '', tables: [] });

const load = async () => {
    try { rows.value = (await adminApi.companies({ search: search.value || undefined, status: status.value || undefined })).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
    finally { loading.value = false; }
};
onMounted(load);

const loadMainTables = async () => {
    if (mainTables.value.tables.length) return;
    try { mainTables.value = (await adminApi.dbTables()).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
};

const open = (c) => {
    form.value = { ...blank(), ...(c || {}) };
    tab.value = 'details';
    files.value = { logo: null, image: null };
    previews.value = { logo: null, image: null };
    removeFlags.value = { logo: false, image: false };
    sources.value = { logo: null, image: null };
    crop.value = null;
    newDb.value = { on: false, name: '', tables: [] };
    dbInfo.value = null;
    if (!c && platform) loadMainTables();
};
const close = () => { form.value = null; };

/* ---------------- crop + rotate before upload ----------------
 * The picture is placed on a fixed frame (logo = square, image = wide banner). Drag to move, zoom, rotate; "Use this picture"
 * makes the final PNG / JPEG at the right size. Nothing leaves the browser until you press Save. */
const OUT = {
    logo: { label: 'logo', w: 512, h: 512, stage: [280, 280], type: 'image/png', ext: 'png' },
    image: { label: 'image', w: 1200, h: 400, stage: [450, 150], type: 'image/jpeg', ext: 'jpg' },
};
const MAX_SOURCE = 10 * 1024 * 1024;   // what you may choose; the cropped result is much smaller
const MAX_RESULT = 2 * 1024 * 1024;    // what the server accepts
const crop = ref(null);                // { kind, img, name, quarter, fine, zoom, ox, oy }
const cvs = ref(null);
let drag = null;

const theta = (c) => ((c.quarter * 90 + c.fine) * Math.PI) / 180;
/* smallest scale at which the rotated picture still covers the whole frame */
const baseScale = (c, W, H) => {
    const t = theta(c), a = Math.abs(Math.cos(t)), b = Math.abs(Math.sin(t));
    return Math.max((W * a + H * b) / c.img.naturalWidth, (W * b + H * a) / c.img.naturalHeight);
};
/* keep the frame inside the picture (no empty corners) */
const clampOffset = (c) => {
    const [W, H] = OUT[c.kind].stage;
    const t = theta(c), cos = Math.cos(t), sin = Math.sin(t);
    const s = baseScale(c, W, H) * c.zoom;
    const px = -c.ox, py = -c.oy;
    let ix = px * cos + py * sin;
    let iy = -px * sin + py * cos;
    const hx = (W * Math.abs(cos) + H * Math.abs(sin)) / 2;
    const hy = (W * Math.abs(sin) + H * Math.abs(cos)) / 2;
    const mx = Math.max(0, (c.img.naturalWidth * s) / 2 - hx);
    const my = Math.max(0, (c.img.naturalHeight * s) / 2 - hy);
    ix = Math.min(mx, Math.max(-mx, ix));
    iy = Math.min(my, Math.max(-my, iy));
    c.ox = -(ix * cos - iy * sin);
    c.oy = -(ix * sin + iy * cos);
};
const paint = (ctx, c, W, H) => {
    const [sw, sh] = OUT[c.kind].stage;
    const f = W / sw;
    ctx.save();
    ctx.clearRect(0, 0, W, H);
    if (OUT[c.kind].type === 'image/jpeg') { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H); }
    ctx.imageSmoothingQuality = 'high';
    ctx.translate(W / 2 + c.ox * f, H / 2 + c.oy * f);
    ctx.rotate(theta(c));
    const s = baseScale(c, sw, sh) * c.zoom * f;
    ctx.scale(s, s);
    ctx.drawImage(c.img, -c.img.naturalWidth / 2, -c.img.naturalHeight / 2);
    ctx.restore();
};
const draw = async () => {
    await nextTick();
    const c = crop.value, el = cvs.value;
    if (c && el) paint(el.getContext('2d'), c, el.width, el.height);
};
watch(crop, draw, { deep: true });

const startCrop = (kind, file) => {
    if (!file) return;
    if (!/^image\/(png|jpeg|webp)$/.test(file.type)) { toast.error('Choose a PNG, JPG or WebP picture'); return; }
    if (file.size > MAX_SOURCE) { toast.error('Picture must be 10 MB or smaller'); return; }
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
        URL.revokeObjectURL(url);
        sources.value[kind] = file;
        crop.value = { kind, img, name: file.name, quarter: 0, fine: 0, zoom: 1, ox: 0, oy: 0 };
    };
    img.onerror = () => { URL.revokeObjectURL(url); toast.error('Could not read this picture'); };
    img.src = url;
};
const pick = (kind, e) => {
    const f = e.target.files?.[0];
    e.target.value = '';   // the same file can be chosen again
    startCrop(kind, f);
};
const adjust = (kind) => startCrop(kind, sources.value[kind]);

const rotate = (dir) => { crop.value.quarter = (crop.value.quarter + dir + 4) % 4; clampOffset(crop.value); };
const resetCrop = () => Object.assign(crop.value, { quarter: 0, fine: 0, zoom: 1, ox: 0, oy: 0 });
const cropChanged = () => clampOffset(crop.value);
const down = (e) => { drag = { x: e.clientX, y: e.clientY }; e.currentTarget.setPointerCapture(e.pointerId); };
const move = (e) => {
    if (!drag) return;
    const el = cvs.value;
    const ratio = el.width / el.getBoundingClientRect().width;   // the frame may be shown smaller on a phone
    crop.value.ox += (e.clientX - drag.x) * ratio;
    crop.value.oy += (e.clientY - drag.y) * ratio;
    drag = { x: e.clientX, y: e.clientY };
    clampOffset(crop.value);
};
const up = () => { drag = null; };
const wheel = (e) => { crop.value.zoom = Math.min(3, Math.max(1, crop.value.zoom - e.deltaY * 0.002)); clampOffset(crop.value); };
const cancelCrop = () => { crop.value = null; };
const useCrop = () => {
    const c = crop.value, cfg = OUT[c.kind];
    const out = document.createElement('canvas');
    out.width = cfg.w;
    out.height = cfg.h;
    paint(out.getContext('2d'), c, cfg.w, cfg.h);
    out.toBlob((blob) => {
        if (!blob) return toast.error('Could not make the picture');
        if (blob.size > MAX_RESULT) return toast.error('The picture is still bigger than 2 MB. Choose a smaller one.');
        const file = new File([blob], `${c.kind}.${cfg.ext}`, { type: cfg.type });
        files.value[c.kind] = file;
        removeFlags.value[c.kind] = false;
        previews.value[c.kind] = URL.createObjectURL(file);
        crop.value = null;
    }, cfg.type, 0.92);
};
const dropFile = (kind) => {
    files.value[kind] = null;
    previews.value[kind] = null;
    sources.value[kind] = null;
    if (form.value[`${kind}_url`]) removeFlags.value[kind] = true;
};
const shown = (kind) => previews.value[kind] || (removeFlags.value[kind] ? null : form.value?.[`${kind}_url`]);

const save = async () => {
    if (!form.value.id && newDb.value.on && (!newDb.value.name.trim() || !newDb.value.tables.length)) {
        tab.value = 'database';
        return toast.error('Give the database a name and select at least one table');
    }
    saving.value = true;
    try {
        const { id, users_count, created_at, updated_at, logo, image, logo_url, image_url, has_database, database_name, database_tables, database_created_at, ...payload } = form.value;
        if (!id && newDb.value.on) Object.assign(payload, { create_database: true, database_name: newDb.value.name.trim(), tables: newDb.value.tables });
        const { data } = id ? await adminApi.updateCompany(id, payload) : await adminApi.createCompany(payload);
        const cid = data.data.id;
        for (const kind of ['logo', 'image']) {
            if (files.value[kind]) await adminApi.uploadCompanyFile(cid, kind, files.value[kind]);
            else if (removeFlags.value[kind]) await adminApi.removeCompanyFile(cid, kind);
        }
        toast.success(data.message);
        close();
        load();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { saving.value = false; }
};

const remove = async (c) => {
    if (!window.confirm(`Delete company ${c.name}?${c.has_database ? `\nIts own database "${c.database_name}" is dropped too (only possible when it has no data).` : ''}`)) return;
    try { toast.success((await adminApi.deleteCompany(c.id)).data.message); load(); }
    catch (e) { toast.error(errorMessage(e)); }
};

const showUsers = async (c) => {
    try {
        const { data } = await api.get(`/admin/companies/${c.id}/users`);
        usersOf.value = { company: c, users: data.data };
    } catch (e) { toast.error(errorMessage(e)); }
};

/* ---------------- database tab (existing company) ---------------- */
const dbInfo = ref(null);
const dbBusy = ref(false);
const give = ref({ name: '', tables: [], copy: true });
const more = ref([]);
const extra = ref({ table: '', columns: [{ name: '', type: 'string' }] });
const colTypes = ['string', 'text', 'integer', 'decimal', 'date', 'datetime', 'boolean'];

const openDatabaseTab = async () => {
    tab.value = 'database';
    if (!form.value.id || dbInfo.value) return;
    try {
        dbInfo.value = (await adminApi.companyDb(form.value.id)).data.data;
        more.value = [];
        if (!dbInfo.value.database) { give.value = { name: '', tables: [], copy: true }; }
    } catch (e) { toast.error(errorMessage(e)); }
};
const reloadDb = async () => {
    dbInfo.value = (await adminApi.companyDb(form.value.id)).data.data;
    const { data } = await adminApi.companies({});
    rows.value = data.data;
    const fresh = rows.value.find((c) => c.id === form.value.id);
    if (fresh) Object.assign(form.value, { database_name: fresh.database_name, has_database: fresh.has_database });
    more.value = [];
};
const giveDatabase = async () => {
    if (!give.value.name.trim() || !give.value.tables.length) return toast.error('Give the database a name and select at least one table');
    if (!window.confirm(`Create database "${give.value.name}" for ${form.value.name}?${give.value.copy ? '\nThe company\'s current data is copied into it.' : ''}`)) return;
    dbBusy.value = true;
    try {
        toast.success((await adminApi.createCompanyDb(form.value.id, { database_name: give.value.name.trim(), tables: give.value.tables, copy_data: give.value.copy })).data.message);
        await reloadDb();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { dbBusy.value = false; }
};
const addTables = async () => {
    if (!more.value.length) return toast.error('Select the tables to add');
    dbBusy.value = true;
    try { toast.success((await adminApi.addDbTables(form.value.id, more.value)).data.message); await reloadDb(); }
    catch (e) { toast.error(errorMessage(e)); }
    finally { dbBusy.value = false; }
};
const addExtra = async () => {
    dbBusy.value = true;
    try {
        const columns = extra.value.columns.filter((c) => c.name.trim());
        toast.success((await adminApi.addCustomTable(form.value.id, { table: extra.value.table.trim(), columns })).data.message);
        extra.value = { table: '', columns: [{ name: '', type: 'string' }] };
        await reloadDb();
    } catch (e) { toast.error(errorMessage(e)); }
    finally { dbBusy.value = false; }
};
const availableTables = computed(() => (dbInfo.value?.available || []).map((t) => ({ ...t, selectable: true })));
const givableTables = computed(() => (dbInfo.value?.available || []));

/* ---------------- download ---------------- */
const dl = ref(null);
const openDownload = async () => {
    try {
        const options = (await adminApi.dbOptions()).data.data;
        const first = options[0];
        dl.value = { options, companyId: first?.id || null, database: first?.databases[0]?.name || '', busy: false };
    } catch (e) { toast.error(errorMessage(e)); }
};
const dlDatabases = computed(() => dl.value?.options.find((o) => o.id === dl.value.companyId)?.databases || []);
const dlCompanyChanged = () => { dl.value.database = dlDatabases.value[0]?.name || ''; };
const download = async () => {
    dl.value.busy = true;
    try { await adminApi.downloadDb(dl.value.companyId, dl.value.database); toast.success('Download started'); }
    catch (e) {
        // a failed blob download carries its error as a Blob
        let msg = errorMessage(e, 'Download failed');
        try { const j = JSON.parse(await e.response.data.text()); msg = j.errors ? Object.values(j.errors)[0][0] : j.message || msg; } catch { /* keep msg */ }
        toast.error(msg);
    } finally { dl.value.busy = false; }
};
const logoOf = (c) => c.logo_url || DEFAULT_LOGO;
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <div class="grow"><h3 style="font-size: 22px">{{ platform ? 'Companies' : 'Company' }}</h3><p class="sub">{{ platform ? 'Each company has its own products, customers, orders, purchase, sales and stock, and can have its own database.' : 'Your company details, logo, image, payment details and stock setting.' }}</p></div>
            <button v-if="platform" class="btn btn-ghost" @click="openDownload">Download database</button>
            <button v-if="platform" class="btn btn-primary" @click="open()"><Icon name="plus" :size="16" /> New Company</button>
        </div>
        <div v-if="platform" class="toolbar">
            <input v-model="search" class="input" style="max-width: 280px" placeholder="Search code, name, GSTIN" @keyup.enter="load" />
            <select v-model="status" class="select" style="max-width: 190px" @change="load">
                <option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option><option value="inactive">Inactive</option>
            </select>
        </div>

        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>Code</th><th>Company</th><th>GSTIN</th><th>City</th><th>Database</th><th class="num">Users</th><th>Status</th><th /></tr></thead>
                <tbody>
                    <tr v-if="loading"><td colspan="8" class="empty">Loading…</td></tr>
                    <tr v-else-if="!rows.length"><td colspan="8" class="empty">No companies</td></tr>
                    <tr v-for="c in rows" :key="c.id">
                        <td><b>{{ c.code }}</b></td>
                        <td>
                            <div class="co-cell">
                                <img :src="logoOf(c)" alt="" class="co-logo" @error="$event.target.style.display = 'none'" />
                                <span><b>{{ c.name }}</b><br /><small v-if="c.allow_negative_stock" style="color: var(--accent)">negative stock allowed</small><small v-if="c.upi_id" style="color: var(--muted)"> UPI {{ c.upi_id }}</small></span>
                            </div>
                        </td>
                        <td>{{ c.gstin || '—' }}</td><td>{{ c.city || '—' }}</td>
                        <td><span v-if="c.has_database" class="badge ok" :title="(c.database_tables || []).join(', ')">{{ c.database_name }}</span><span v-else class="badge off">shared</span></td>
                        <td class="num">{{ c.users_count }}</td>
                        <td><span class="badge" :class="c.status === 'active' ? 'ok' : 'off'">{{ c.status }}</span></td>
                        <td><div class="row-actions">
                            <button class="btn btn-ghost btn-sm" @click="open(c)">Edit</button>
                            <button class="btn btn-ghost btn-sm" @click="showUsers(c)">Users</button>
                            <button v-if="platform" class="btn btn-danger btn-sm" @click="remove(c)">Delete</button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- create / edit -->
        <Modal v-if="form" :title="form.id ? `Edit ${form.name}` : 'New company'" wide @close="close">
            <div class="tabs">
                <button type="button" :class="{ on: tab === 'details' }" @click="tab = 'details'">Details</button>
                <button type="button" :class="{ on: tab === 'branding' }" @click="tab = 'branding'">Logo &amp; image</button>
                <button type="button" :class="{ on: tab === 'payment' }" @click="tab = 'payment'">UPI &amp; bank</button>
                <button v-if="platform" type="button" :class="{ on: tab === 'database' }" @click="form.id ? openDatabaseTab() : (tab = 'database')">Database</button>
            </div>

            <form @submit.prevent="save">
                <div v-show="tab === 'details'">
                    <div class="form-grid">
                        <div class="field"><label>Code * (short, unique)</label><input v-model="form.code" class="input" maxlength="10" required :disabled="!platform" style="text-transform: uppercase" /></div>
                        <div class="field"><label>Status</label><select v-model="form.status" class="select" :disabled="!platform"><option value="active">Active</option><option value="suspended">Suspended</option><option value="inactive">Inactive</option></select></div>
                        <div class="field full"><label>Company name *</label><input v-model="form.name" class="input" required /></div>
                        <div class="field"><label>GSTIN</label><input v-model="form.gstin" class="input" /></div>
                        <div class="field"><label>Phone</label><input v-model="form.phone" class="input" /></div>
                        <div class="field"><label>Email</label><input v-model="form.email" class="input" type="email" /></div>
                        <div class="field"><label>City</label><input v-model="form.city" class="input" /></div>
                        <div class="field"><label>State</label><input v-model="form.state" class="input" /></div>
                        <div class="field"><label>Pincode</label><input v-model="form.pincode" class="input" /></div>
                        <div class="field full"><label>Address</label><input v-model="form.address" class="input" /></div>
                    </div>
                    <label class="check"><input v-model="form.allow_negative_stock" type="checkbox" /> Allow selling / ordering more than the stock (negative stock)</label>
                </div>

                <div v-show="tab === 'branding'" class="brand-grid">
                    <div class="brand-box">
                        <b>Company logo</b>
                        <small>Shown on the menu, login, order page and invoices. You can crop and rotate it. If empty the MK logo is used.</small>
                        <div class="logo-prev"><img :src="shown('logo') || DEFAULT_LOGO" alt="logo" /></div>
                        <div class="brand-actions">
                            <label class="btn btn-ghost btn-sm" style="cursor: pointer">Choose logo<input type="file" accept="image/png,image/jpeg,image/webp" hidden @change="pick('logo', $event)" /></label>
                            <button v-if="sources.logo && files.logo" type="button" class="btn btn-ghost btn-sm" @click="adjust('logo')">Crop / rotate</button>
                            <button v-if="shown('logo')" type="button" class="btn btn-ghost btn-sm" @click="dropFile('logo')">Remove</button>
                        </div>
                    </div>
                    <div class="brand-box">
                        <b>Company image</b>
                        <small>A wide photo (shop / banner). Shown on the order page and at the top of the dashboard. You can crop and rotate it. If empty a default image is shown.</small>
                        <div class="image-prev"><img v-if="shown('image')" :src="shown('image')" alt="image" /><span v-else>No image</span></div>
                        <div class="brand-actions">
                            <label class="btn btn-ghost btn-sm" style="cursor: pointer">Choose image<input type="file" accept="image/png,image/jpeg,image/webp" hidden @change="pick('image', $event)" /></label>
                            <button v-if="sources.image && files.image" type="button" class="btn btn-ghost btn-sm" @click="adjust('image')">Crop / rotate</button>
                            <button v-if="shown('image')" type="button" class="btn btn-ghost btn-sm" @click="dropFile('image')">Remove</button>
                        </div>
                    </div>
                    <p class="sub" style="grid-column: 1 / -1">Choose a picture, then move, zoom and rotate it. It is uploaded when you press Save.</p>
                </div>

                <div v-show="tab === 'payment'">
                    <p class="sub">Customers see these on the order page after they order, and on the printed invoice.</p>
                    <div class="form-grid">
                        <div class="field"><label>UPI ID</label><input v-model="form.upi_id" class="input" placeholder="name@bank" /></div>
                        <div class="field"><label>UPI name (shown in the UPI app)</label><input v-model="form.upi_name" class="input" /></div>
                        <div class="field"><label>Bank name</label><input v-model="form.bank_name" class="input" /></div>
                        <div class="field"><label>Branch</label><input v-model="form.bank_branch" class="input" /></div>
                        <div class="field"><label>Account holder</label><input v-model="form.account_holder" class="input" /></div>
                        <div class="field"><label>Account number</label><input v-model="form.account_number" class="input" inputmode="numeric" /></div>
                        <div class="field"><label>IFSC</label><input v-model="form.ifsc" class="input" style="text-transform: uppercase" /></div>
                    </div>
                </div>

                <!-- database: NEW company -->
                <div v-if="platform && !form.id" v-show="tab === 'database'">
                    <label class="check"><input v-model="newDb.on" type="checkbox" @change="loadMainTables" /> Create a separate database for this company</label>
                    <p class="sub">Off = the company uses the shared database (its rows are kept apart by company id). On = its own MySQL database with only the tables you select.</p>
                    <template v-if="newDb.on">
                        <div class="field" style="max-width: 360px"><label>Database name *</label><input v-model="newDb.name" class="input" maxlength="48" placeholder="e.g. mk_sairaj" autocomplete="off" /></div>
                        <p class="sub">Tables in database <b>{{ mainTables.database }}</b>. Select the tables this company needs:</p>
                        <TablePicker v-model="newDb.tables" :tables="mainTables.tables" />
                        <p class="sub">More tables can be added later (Edit → Database), and you can make extra tables of your own.</p>
                    </template>
                </div>

                <!-- database: EXISTING company -->
                <div v-if="platform && form.id" v-show="tab === 'database'">
                    <p v-if="!dbInfo" class="empty">Loading…</p>
                    <template v-else-if="!dbInfo.database">
                        <p class="sub">{{ form.name }} uses the <b>shared database</b>. Give it its own database:</p>
                        <div class="field" style="max-width: 360px"><label>Database name *</label><input v-model="give.name" class="input" maxlength="48" placeholder="e.g. mk_sairaj" autocomplete="off" /></div>
                        <TablePicker v-model="give.tables" :tables="givableTables" :show-shared="true" />
                        <label class="check"><input v-model="give.copy" type="checkbox" /> Copy this company's current data into the new database</label>
                        <button type="button" class="btn btn-primary" :disabled="dbBusy" @click="giveDatabase">{{ dbBusy ? 'Creating…' : 'Create database' }}</button>
                    </template>
                    <template v-else>
                        <p class="sub">Own database: <b>{{ dbInfo.database }}</b> · created {{ dbInfo.created_at ? new Date(dbInfo.created_at).toLocaleDateString('en-IN') : '' }}</p>
                        <div class="table-wrap"><table class="tbl">
                            <thead><tr><th>Table in {{ dbInfo.database }}</th><th class="num">Rows</th><th /></tr></thead>
                            <tbody><tr v-for="t in dbInfo.tables" :key="t.name"><td><b>{{ t.name }}</b></td><td class="num">{{ t.rows }}</td><td><span v-if="t.custom" class="badge off">your table</span></td></tr></tbody>
                        </table></div>

                        <h4 class="h4">Add more tables</h4>
                        <p v-if="!availableTables.length" class="sub">Every table of the app is already in this database.</p>
                        <template v-else>
                            <TablePicker v-model="more" :tables="availableTables" :show-shared="false" />
                            <button type="button" class="btn btn-primary" :disabled="dbBusy || !more.length" style="margin-top: 10px" @click="addTables">Add selected tables</button>
                        </template>

                        <h4 class="h4">Extra table of your own</h4>
                        <div class="field" style="max-width: 360px"><label>Table name</label><input v-model="extra.table" class="input" placeholder="e.g. vehicle_log" /></div>
                        <div v-for="(c, i) in extra.columns" :key="i" class="col-row">
                            <input v-model="c.name" class="input" placeholder="column name" />
                            <select v-model="c.type" class="select"><option v-for="t in colTypes" :key="t" :value="t">{{ t }}</option></select>
                            <button type="button" class="btn btn-ghost btn-sm" :disabled="extra.columns.length === 1" @click="extra.columns.splice(i, 1)">✕</button>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm" @click="extra.columns.push({ name: '', type: 'string' })">+ Column</button>
                        <p class="sub">id, company_id, created_at and updated_at are added automatically.</p>
                        <button type="button" class="btn btn-primary" :disabled="dbBusy || !extra.table.trim()" @click="addExtra">Create table</button>
                    </template>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="close">Close</button>
                    <button class="btn btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </form>
        </Modal>

        <!-- download -->
        <Modal v-if="dl" title="Download database" @close="dl = null">
            <div class="field"><label>Company</label>
                <select v-model="dl.companyId" class="select" @change="dlCompanyChanged"><option v-for="o in dl.options" :key="o.id" :value="o.id">{{ o.code }} · {{ o.name }}</option></select>
            </div>
            <div class="field"><label>Database</label>
                <select v-model="dl.database" class="select"><option v-for="d in dlDatabases" :key="d.name" :value="d.name">{{ d.label }}</option></select>
            </div>
            <p class="sub">A .sql file with the structure and the data. Restore it with phpMyAdmin or <code>mysql &lt; file.sql</code>.</p>
            <div class="modal-actions">
                <button class="btn btn-ghost" @click="dl = null">Close</button>
                <button class="btn btn-primary" :disabled="dl.busy || !dl.database" @click="download">{{ dl.busy ? 'Preparing…' : 'Download' }}</button>
            </div>
        </Modal>

        <!-- crop + rotate -->
        <Teleport to="body">
            <div v-if="crop" class="cr-overlay" @click.self="cancelCrop">
                <div class="cr-box" role="dialog" aria-modal="true">
                    <h4>Adjust the {{ OUT[crop.kind].label }}</h4>
                    <p class="cr-hint">Drag the picture to move it. Use the sliders to zoom and rotate.</p>
                    <div class="cr-stage" :class="crop.kind">
                        <canvas
                            ref="cvs"
                            :width="OUT[crop.kind].stage[0]"
                            :height="OUT[crop.kind].stage[1]"
                            @pointerdown="down"
                            @pointermove="move"
                            @pointerup="up"
                            @pointercancel="up"
                            @wheel.prevent="wheel"
                        />
                        <span class="cr-grid" aria-hidden="true"></span>
                    </div>
                    <div class="cr-row">
                        <button type="button" class="btn btn-ghost btn-sm" @click="rotate(-1)">⟲ 90°</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="rotate(1)">⟳ 90°</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="resetCrop">Reset</button>
                    </div>
                    <label class="cr-slider">Zoom <input v-model.number="crop.zoom" type="range" min="1" max="3" step="0.01" @input="cropChanged" /></label>
                    <label class="cr-slider">Rotate <input v-model.number="crop.fine" type="range" min="-45" max="45" step="1" @input="cropChanged" /><b>{{ crop.fine }}°</b></label>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-ghost" @click="cancelCrop">Cancel</button>
                        <button type="button" class="btn btn-primary" @click="useCrop">Use this picture</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Modal v-if="usersOf" :title="`Users of ${usersOf.company.name}`" @close="usersOf = null">
            <p v-if="!usersOf.users.length" class="empty">No user is assigned to this company.</p>
            <div v-for="u in usersOf.users" :key="u.id" style="display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid var(--border)">
                <span><b>{{ u.name }}</b><br /><small style="color: var(--muted)">{{ u.email }}</small></span>
                <span class="chip-role" :class="{ default: u.is_default }">{{ u.role }}</span>
            </div>
            <div class="modal-actions"><button class="btn btn-ghost" @click="usersOf = null">Close</button></div>
        </Modal>
    </div>
</template>

<style scoped>
.co-cell { display: flex; align-items: center; gap: 10px; }
.co-logo { width: 38px; height: 38px; border-radius: 10px; object-fit: cover; background: #fff; border: 1px solid var(--border); flex: none; }
.tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; border-bottom: 1px solid var(--border); }
.tabs button { padding: 9px 14px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: var(--text-2, var(--muted)); font-weight: 600; cursor: pointer; }
.tabs button.on { color: var(--brand); border-bottom-color: var(--brand); }
.brand-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.brand-box { display: grid; gap: 8px; align-content: start; padding: 14px; border: 1px solid var(--border); border-radius: 14px; }
.brand-box small { color: var(--muted); }
.logo-prev { width: 96px; height: 96px; border-radius: 18px; overflow: hidden; border: 1px solid var(--border); background: #fff; }
.logo-prev img { width: 100%; height: 100%; object-fit: cover; }
.image-prev { height: 120px; border-radius: 14px; overflow: hidden; border: 1px dashed var(--border); display: grid; place-items: center; color: var(--muted); }
.image-prev img { width: 100%; height: 100%; object-fit: cover; }
.brand-actions { display: flex; gap: 8px; }
.h4 { margin: 18px 0 8px; font-size: 15px; }
.col-row { display: grid; grid-template-columns: 1fr 150px auto; gap: 8px; margin-bottom: 8px; max-width: 520px; }

/* crop + rotate */
.cr-overlay { position: fixed; inset: 0; z-index: 3000; display: grid; place-items: center; padding: 16px; background: rgba(8, 6, 24, .72); backdrop-filter: blur(4px); }
.cr-box { width: 100%; max-width: 520px; max-height: 94vh; overflow-y: auto; padding: 20px; border-radius: 20px; background: var(--surface); color: var(--text); border: 1px solid var(--border); box-shadow: 0 30px 60px -20px rgba(0, 0, 0, .6); }
.cr-box h4 { margin: 0 0 4px; font-size: 18px; }
.cr-hint { margin: 0 0 12px; font-size: 13px; color: var(--muted); }
.cr-stage { position: relative; margin: 0 auto 12px; width: fit-content; max-width: 100%; border-radius: 12px; overflow: hidden; border: 2px solid var(--brand); background: repeating-conic-gradient(#d1d5db 0% 25%, #f3f4f6 0% 50%) 50% / 16px 16px; touch-action: none; }
.cr-stage canvas { display: block; max-width: 100%; height: auto; cursor: grab; }
.cr-stage canvas:active { cursor: grabbing; }
.cr-grid { position: absolute; inset: 0; pointer-events: none; background-image: linear-gradient(rgba(255, 255, 255, .45) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, .45) 1px, transparent 1px); background-size: 33.333% 33.333%; }
.cr-row { display: flex; gap: 8px; justify-content: center; margin-bottom: 12px; }
.cr-slider { display: grid; grid-template-columns: 56px 1fr auto; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: var(--muted); }
.cr-slider b { min-width: 38px; text-align: right; color: var(--text); }
.cr-slider input[type='range'] { width: 100%; accent-color: var(--brand); }
@media (max-width: 640px) { .brand-grid { grid-template-columns: 1fr; } }
</style>