<script setup>
import { onMounted, ref } from 'vue';
import Icon from '../../components/Icon.vue';
import { adminApi, errorMessage } from '../../services/api';
import { toast } from '../../services/toast';

const d = ref(null);
onMounted(async () => {
    try { d.value = (await adminApi.dashboard()).data.data; }
    catch (e) { toast.error(errorMessage(e)); }
});
const by = (s) => d.value?.companies_by_status?.[s] || 0;
</script>

<template>
    <div v-if="!d" class="grid grid-4"><div v-for="i in 4" :key="i" class="skeleton" style="height: 110px" /></div>
    <template v-else>
        <div class="toolbar">
            <div class="grow"><h3 style="font-size: 26px; margin: 0">Dashboard</h3><p class="sub" style="margin: 4px 0 0">{{ d.scope === 'platform' ? 'Platform overview' : 'Your company' }} · current account year {{ d.current_year?.label || '—' }}</p></div>
            <router-link v-if="d.scope === 'platform'" to="/admin/companies" class="btn btn-ghost">+ Company</router-link>
            <router-link to="/admin/users" class="btn btn-primary">+ User</router-link>
        </div>

        <div class="grid grid-4" style="margin-bottom: 22px">
            <div class="card stat"><div class="ic"><Icon name="cart" /></div><div class="lbl">Companies</div><div class="val">{{ d.companies }}</div><div class="hint">{{ by('active') }} active · {{ by('suspended') }} suspended · {{ by('inactive') }} inactive</div></div>
            <div class="card stat"><div class="ic"><Icon name="users" /></div><div class="lbl">Company users</div><div class="val">{{ d.users }}</div><div class="hint">{{ d.users_active }} active</div></div>
            <div v-if="d.scope === 'platform'" class="card stat amber"><div class="ic"><Icon name="alert" /></div><div class="lbl">Users without company</div><div class="val">{{ d.users_without_company }}</div><div class="hint">They cannot sign in until assigned</div></div>
            <div v-else class="card stat amber"><div class="ic"><Icon name="alert" /></div><div class="lbl">Inactive users</div><div class="val">{{ d.users - d.users_active }}</div><div class="hint">Cannot sign in</div></div>
            <div class="card stat sky"><div class="ic"><Icon name="clock" /></div><div class="lbl">Account year</div><div class="val">{{ d.current_year?.label || '—' }}</div><div class="hint">April → March</div></div>
        </div>

        <div class="card">
            <h3>{{ d.scope === 'platform' ? 'Recent companies' : 'Your company' }}</h3><p class="sub">{{ d.scope === 'platform' ? 'Newest first' : 'Companies you manage' }}</p>
            <div class="table-wrap">
                <table class="tbl">
                    <thead><tr><th>Code</th><th>Company</th><th>City</th><th class="num">Users</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-if="!d.recent_companies.length"><td colspan="5" class="empty">No companies yet</td></tr>
                        <tr v-for="c in d.recent_companies" :key="c.id">
                            <td><b>{{ c.code }}</b></td><td>{{ c.name }}</td><td>{{ c.city || '—' }}</td><td class="num">{{ c.users_count }}</td>
                            <td><span class="badge" :class="c.status === 'active' ? 'ok' : 'off'">{{ c.status }}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </template>
</template>
