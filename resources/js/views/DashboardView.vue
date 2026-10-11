<script setup>
import { computed, onMounted, ref } from 'vue';
import Icon from '../components/Icon.vue';
import { dashboardApi, errorMessage, inr } from '../services/api';
import { isAdmin, session } from '../services/auth';
import { toast } from '../services/toast';

const stats = ref(null);
const admin = isAdmin();

const greeting = () => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
};
const max = computed(() => Math.max(...(stats.value?.monthly_revenue.map((m) => m.total) || [1]), 1));
const totalOrders = computed(() => Math.max(stats.value?.orders_total || 0, 1));

onMounted(async () => {
    try {
        stats.value = (await dashboardApi.get()).data.data;
    } catch (e) {
        toast.error(errorMessage(e, 'Could not load dashboard'));
    }
});
</script>

<template>
    <div v-if="!stats" class="grid grid-4">
        <div v-for="i in 4" :key="i" class="skeleton" style="height: 140px" />
    </div>

    <template v-else>
        <section class="hero">
            <h2>{{ greeting() }}, {{ session.user?.name?.split(' ')[0] }} 👋</h2>
            <p>{{ admin ? 'Here is how the business is doing today.' : 'Here is a summary of your orders.' }}</p>
            <div class="hero-actions">
                <router-link to="/orders?new=1" class="btn btn-light">+ New order</router-link>
                <router-link to="/customers?new=1" class="btn btn-ghost">Add customer</router-link>
                <router-link to="/work-status" class="btn btn-ghost">Work status</router-link>
            </div>
        </section>

        <div class="grid grid-4" style="margin-bottom: 18px">
            <div class="card stat">
                <div class="ic"><Icon name="cart" /></div>
                <div class="lbl">Total orders</div>
                <div class="val">{{ stats.orders_total }}</div>
                <div class="hint">{{ stats.orders_by_status.pending }} pending · {{ stats.orders_by_status.active }} active</div>
            </div>
            <div class="card stat green">
                <div class="ic"><Icon name="wallet" /></div>
                <div class="lbl">Revenue (completed)</div>
                <div class="val">{{ inr(stats.revenue_total) }}</div>
                <div class="hint">{{ inr(stats.revenue_month) }} this month</div>
            </div>
            <div class="card stat amber">
                <div class="ic"><Icon name="clock" /></div>
                <div class="lbl">Yet to collect</div>
                <div class="val">{{ inr(stats.pending_amount) }}</div>
                <div class="hint">pending, upcoming and active orders</div>
            </div>
            <div class="card stat sky">
                <div class="ic"><Icon name="alert" /></div>
                <div class="lbl">{{ admin ? 'Low stock items' : 'Customers' }}</div>
                <div class="val">{{ admin ? stats.low_stock.length : stats.customers }}</div>
                <div class="hint">{{ stats.products }} products · {{ stats.customers }} customers</div>
            </div>
        </div>

        <div class="grid grid-2-1" style="margin-bottom: 18px">
            <div class="card">
                <h3>Revenue, last 6 months</h3>
                <p class="sub">Completed orders only</p>
                <div class="bars">
                    <div v-for="m in stats.monthly_revenue" :key="m.month" class="bar-col">
                        <div class="bar" :data-v="inr(m.total)" :style="{ height: (m.total / max) * 100 + '%' }" />
                        <span>{{ m.month }}</span>
                    </div>
                </div>
            </div>
            <div class="card">
                <h3>Orders by status</h3>
                <p class="sub">Share of all orders</p>
                <div v-for="(v, k) in stats.orders_by_status" :key="k" class="progress-row">
                    <div class="top"><span style="text-transform: capitalize">{{ k }}</span><b>{{ v }}</b></div>
                    <div class="progress"><i :style="{ width: (v / totalOrders) * 100 + '%' }" /></div>
                </div>
            </div>
        </div>

        <div class="grid grid-2-1">
            <div class="card">
                <div class="toolbar"><h3 class="grow">Recent orders</h3><router-link to="/orders" class="btn btn-ghost btn-sm">View all</router-link></div>
                <div class="table-wrap">
                    <table class="tbl">
                        <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="num">Total</th></tr></thead>
                        <tbody>
                            <tr v-if="!stats.recent_orders.length"><td colspan="4" class="empty">No orders yet</td></tr>
                            <tr v-for="o in stats.recent_orders" :key="o.id">
                                <td><b>{{ o.order_name }}</b> <span v-if="o.source === 'web'" class="badge upcoming">web</span></td>
                                <td>{{ o.customer_name }}</td>
                                <td><span class="badge" :class="o.status">{{ o.status }}</span></td>
                                <td class="num">{{ inr(o.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid" style="align-content: start">
                <div class="card">
                    <h3>Low stock</h3>
                    <p class="sub">5 units or fewer left</p>
                    <div v-if="!stats.low_stock.length" class="empty" style="padding: 12px">All good 🎉</div>
                    <div v-for="p in stats.low_stock" :key="p.id" style="display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px">
                        <span>{{ p.product_name }}</span><span class="badge" :class="p.stock === 0 ? 'off' : 'pending'">{{ p.stock }} left</span>
                    </div>
                </div>
                <div v-if="admin && stats.top_products" class="card">
                    <h3>Top products</h3>
                    <p class="sub">By sales amount</p>
                    <div v-if="!stats.top_products.length" class="empty" style="padding: 12px">No sales yet</div>
                    <div v-for="p in stats.top_products" :key="p.product" style="display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px">
                        <span>{{ p.product }}</span><b>{{ inr(p.amount) }}</b>
                    </div>
                </div>
            </div>
        </div>
    </template>
</template>
