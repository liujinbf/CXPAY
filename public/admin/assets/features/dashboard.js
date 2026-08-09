let chart = null;

export const feature = {
    id: 'dashboard',

    async mount(context) {
        const { root, ui, signal, navigate } = context;
        root.addEventListener('click', (event) => {
            const action = event.target.closest('[data-action]')?.dataset.action;
            if (action === 'refresh-dashboard') void loadDashboard(context);
            if (action === 'navigate-orders') void navigate('order-list');
            if (action === 'navigate-alerts') void navigate('alert-config');
        }, { signal });
        window.addEventListener('resize', () => chart?.resize(), { signal });
        ui.safeCreateIcons();

        await Promise.all([
            loadMetrics(context),
            loadRecentOrders(context),
            loadTrend(context),
        ]);
    },

    unmount() {
        chart?.dispose();
        chart = null;
    },
};

async function loadDashboard(context) {
    await Promise.all([
        loadMetrics(context),
        loadRecentOrders(context),
        loadTrend(context),
    ]);
}

async function loadMetrics({ root, api, signal }) {
    try {
        const response = await api.adminFetch('/api/admin/dashboard', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data || signal.aborted) return;

        const metrics = payload.data;
        setText(root, 'metric-total-amount', `¥ ${Number(metrics.total_amount || 0).toFixed(2)}`);
        setText(root, 'metric-total-orders', Number(metrics.total_orders || 0).toLocaleString());
        setText(root, 'metric-merchant-count', Number(metrics.merchant_count || 0).toLocaleString());
        setText(root, 'metric-vip-count', Number(metrics.vip_merchant_count || 0).toLocaleString());
        setText(root, 'metric-channel-count', `${Number(metrics.online_channel_count || 0)} / ${Number(metrics.channel_count || 0)}`);
        setText(root, 'admin-metric-success-rate', metrics.success_rate || '--');
        setText(root, 'admin-metric-paid-orders', `${Number(metrics.paid_orders || 0).toLocaleString()} 笔`);
        setText(root, 'admin-metric-active-merchants', `${Number(metrics.active_merchant_count || 0).toLocaleString()} 个`);
        setText(root, 'admin-metric-all-channels', `${Number(metrics.channel_count || 0).toLocaleString()} 个`);
    } catch (error) {
        if (error?.name !== 'AbortError') console.error('控制台数据加载失败', error);
    }
}

async function loadRecentOrders({ root, api, ui, signal }) {
    const tableBody = root.querySelector('#dashboard-recent-orders');
    if (!tableBody) return;

    try {
        const response = await api.adminFetch('/api/admin/order/list?page_size=5', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data || signal.aborted) return;

        const rows = Array.isArray(payload.data.data) ? payload.data.data : [];
        const statusMap = {
            0: ['待支付', 'bg-slate-100 text-slate-600'],
            1: ['已支付', 'bg-emerald-100 text-emerald-700'],
            2: ['已关闭', 'bg-amber-100 text-amber-700'],
            3: ['已退款', 'bg-rose-100 text-rose-700'],
        };
        tableBody.innerHTML = rows.length ? rows.map((order) => {
            const state = statusMap[Number(order.status)] || ['未知', 'bg-slate-100 text-slate-600'];
            const createdAt = order.create_time
                ? new Date(Number(order.create_time) * 1000).toLocaleTimeString()
                : '-';
            return `<tr class="hover:bg-slate-50/80 transition-colors">
                <td class="p-2.5 font-mono font-bold text-blue-600">${ui.escapeHtml(order.trade_no || '')}</td>
                <td class="p-2.5 font-mono text-slate-600">${ui.escapeHtml(order.merchant_pid || order.merchant_id || '-')}</td>
                <td class="p-2.5 font-extrabold text-slate-800">&#165; ${Number(order.price || order.amount || 0).toFixed(2)}</td>
                <td class="p-2.5"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${state[1]}">${state[0]}</span></td>
                <td class="p-2.5 text-right text-slate-400 font-mono text-[11px]">${createdAt}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="5" class="p-4 text-center text-slate-400">暂无订单动态</td></tr>';
    } catch (error) {
        if (error?.name !== 'AbortError') {
            tableBody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-rose-500">无法读取实时订单列表</td></tr>';
        }
    }
}

async function loadTrend({ root, api, signal }) {
    const chartElement = root.querySelector('#admin-trend-chart');
    if (!chartElement || !window.echarts) return;

    chart?.dispose();
    chart = window.echarts.init(chartElement);
    const days = recentDays();
    chart.setOption(trendOptions(days, days.map(() => 0)));

    try {
        const response = await api.adminFetch('/api/admin/order/list?page_size=300&status=1', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !Array.isArray(payload.data?.data) || signal.aborted) return;

        const totals = Object.fromEntries(days.map((day) => [day, 0]));
        payload.data.data.forEach((order) => {
            if (Number(order.status) !== 1 || !order.pay_time && !order.paid_at) return;
            const paidAt = new Date(String(order.pay_time || order.paid_at).replace(' ', 'T'));
            const key = `${paidAt.getMonth() + 1}/${paidAt.getDate()}`;
            if (Object.hasOwn(totals, key)) totals[key] += Number(order.price || order.amount || 0);
        });
        chart?.setOption({ series: [{ data: days.map((day) => totals[day]) }] });
    } catch (error) {
        if (error?.name !== 'AbortError') console.warn('趋势数据加载失败，保留占位图', error);
    }
}

function recentDays() {
    const days = [];
    const today = new Date();
    for (let offset = 6; offset >= 0; offset -= 1) {
        const day = new Date(today);
        day.setDate(day.getDate() - offset);
        days.push(`${day.getMonth() + 1}/${day.getDate()}`);
    }
    return days;
}

function trendOptions(days, amounts) {
    return {
        tooltip: { trigger: 'axis', formatter: (items) => `${items[0].name}<br/>流水：¥${items[0].value.toFixed(2)}` },
        grid: { top: 10, right: 16, bottom: 20, left: 60 },
        xAxis: { type: 'category', data: days, axisLine: { lineStyle: { color: '#e2e8f0' } }, axisLabel: { color: '#94a3b8', fontSize: 11 } },
        yAxis: { type: 'value', axisLabel: { color: '#94a3b8', fontSize: 11, formatter: '¥{value}' }, splitLine: { lineStyle: { color: '#f1f5f9' } } },
        series: [{
            data: amounts,
            type: 'bar',
            barMaxWidth: 36,
            itemStyle: { color: '#6366f1', borderRadius: [4, 4, 0, 0] },
        }],
    };
}

function setText(root, id, value) {
    const element = root.querySelector(`#${id}`);
    if (element) element.textContent = value;
}
