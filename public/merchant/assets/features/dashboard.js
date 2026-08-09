let root = null;
let chart = null;
let resizeHandler = null;

export const feature = {
    id: 'dashboard',
    async mount(context) {
        root = context.root;
        root.addEventListener('click', (event) => handleClick(event, context), { signal: context.signal });
        await refresh(context);
        context.ui.safeCreateIcons();
    },
    unmount() {
        chart?.dispose();
        chart = null;
        resizeHandler = null;
        root = null;
    },
};

async function handleClick(event, context) {
    const target = event.target.closest?.('[data-action], [data-navigate]');
    if (!target) return;
    if (target.dataset.navigate) {
        await context.navigate(target.dataset.navigate);
        return;
    }
    if (target.dataset.action === 'refresh-dashboard') {
        await refresh(context);
    }
    if (target.dataset.action === 'add-channel') {
        await context.navigate('channel-list');
        const trigger = document.querySelector('[data-action="open-channel-editor"]');
        if (trigger) trigger.click();
        else window.openAddChannelModal?.();
    }
}

async function refresh(context) {
    const profilePromise = context.getMerchantProfile().then(renderProfile).catch(() => {});
    await Promise.all([
        loadDashboard(context),
        loadRecentOrders(context),
        initTrendChart(context),
        profilePromise,
    ]);
}

async function loadDashboard({ api, shell }) {
    const response = await api.merchantFetch('/api/merchant/dashboard');
    const payload = await response.json();
    if (payload.code !== 1 || !payload.data) return;

    const data = payload.data;
    setText('dashboard-today-amount', `¥ ${Number(data.today_amount || 0).toFixed(2)}`);
    setText('dashboard-today-count', `${data.today_count || 0} 笔`);
    setText('dashboard-today-count-sub', `今日交易 ${data.today_count || 0} 笔`);
    setText('dashboard-channel-count', `${data.running_channel_count || data.channel_count || 0} 个`);
    setText('dashboard-success-rate', `成功率 ${Number(data.today_success_rate || data.success_rate || 100).toFixed(1)}%`);
    setText('dashboard-merchant-balance', `¥ ${Number(data.money || 0).toFixed(2)}`);
    setText('dashboard-discount-balance-sub', `套餐抵扣金 ¥ ${data.plan_fee_discount_balance || '0.00'}`);
    setText('dashboard-merchant-rate', `当前扣率 ${(Number(data.rate || 0.02) * 100).toFixed(2)}%`);
    setText('dashboard-plan-name', data.plan_name || '默认基础套餐');
    setText('dashboard-plan-expire', data.plan_expire_format || '无到期限制');
    shell.applyDashboard(data);
}

async function loadRecentOrders({ api, ui }) {
    const tbody = root?.querySelector('#dashboard-recent-orders-tbody');
    if (!tbody) return;
    try {
        const response = await api.merchantFetch('/api/merchant/order/list?page_size=5');
        const payload = await response.json();
        if (payload.code !== 1 || !Array.isArray(payload.data) || payload.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="py-8 text-center text-slate-400 font-bold">暂无最新交易记录</td></tr>';
            return;
        }
        const statusMap = {
            0: ['未支付', 'bg-amber-100 text-amber-700'],
            1: ['支付成功', 'bg-emerald-100 text-emerald-700'],
            2: ['已过期', 'bg-slate-100 text-slate-500'],
            3: ['已退款', 'bg-rose-100 text-rose-700'],
        };
        tbody.innerHTML = payload.data.slice(0, 5).map((order) => {
            const status = statusMap[order.status] || ['未知', 'bg-slate-100 text-slate-500'];
            return `<tr class="hover:bg-slate-50 transition-colors">
                <td class="py-3 px-3 font-mono font-bold text-blue-600">${ui.escapeHtml(order.trade_no || '')}</td>
                <td class="py-3 px-3 font-bold text-slate-700 truncate max-w-[120px]">${ui.escapeHtml(order.subject || '通用收款')}</td>
                <td class="py-3 px-3 font-mono font-extrabold text-slate-800">¥ ${Number(order.price || order.amount || 0).toFixed(2)}</td>
                <td class="py-3 px-3"><span class="px-2 py-0.5 rounded-full font-bold text-[10px] ${status[1]}">${status[0]}</span></td>
                <td class="py-3 px-3 text-slate-400 font-mono text-[11px]">${ui.escapeHtml(order.create_time || order.pay_time || '')}</td>
            </tr>`;
        }).join('');
    } catch {
        tbody.innerHTML = '<tr><td colspan="5" class="py-8 text-center text-slate-400">最新订单加载失败</td></tr>';
    }
}

async function initTrendChart({ api, signal }) {
    const chartElement = root?.querySelector('#merchant-trend-chart');
    if (!chartElement || !window.echarts) return;
    chart?.dispose();
    chart = window.echarts.init(chartElement);
    const days = [];
    const today = new Date();
    for (let index = 6; index >= 0; index -= 1) {
        const date = new Date(today);
        date.setDate(date.getDate() - index);
        days.push(`${date.getMonth() + 1}/${date.getDate()}`);
    }
    const amounts = Array(7).fill(0);
    chart.setOption({
        tooltip: { trigger: 'axis', formatter: (items) => `${items[0].name}<br/>收款：¥${items[0].value.toFixed(2)}` },
        grid: { top: 10, right: 16, bottom: 20, left: 54 },
        xAxis: { type: 'category', data: days, axisLine: { lineStyle: { color: '#e2e8f0' } }, axisLabel: { color: '#94a3b8', fontSize: 11 } },
        yAxis: { type: 'value', axisLabel: { color: '#94a3b8', fontSize: 11, formatter: '¥{value}' }, splitLine: { lineStyle: { color: '#f1f5f9' } } },
        series: [{ data: amounts, type: 'line', smooth: true, symbol: 'circle', symbolSize: 6, lineStyle: { color: '#3b82f6', width: 2.5 }, areaStyle: { color: 'rgba(59,130,246,0.12)' }, itemStyle: { color: '#3b82f6' } }],
    });
    resizeHandler = () => chart?.resize();
    window.addEventListener('resize', resizeHandler, { signal });

    try {
        const response = await api.merchantFetch('/api/merchant/order/list?page_size=200&status=1', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !Array.isArray(payload.data)) return;
        const totals = Object.fromEntries(days.map((day) => [day, 0]));
        payload.data.forEach((order) => {
            if (!order.pay_time || Number(order.status) !== 1) return;
            const date = new Date(String(order.pay_time).replace(' ', 'T'));
            const key = `${date.getMonth() + 1}/${date.getDate()}`;
            if (Object.hasOwn(totals, key)) totals[key] += Number(order.price || order.amount || 0);
        });
        chart.setOption({ series: [{ data: days.map((day) => totals[day]) }] });
    } catch (error) {
        if (error?.name !== 'AbortError') console.warn('商户趋势数据加载失败：', error);
    }
}

function renderProfile(profile) {
    setText('quick-merchant-pid', profile.pid || '--');
    setText('quick-merchant-key', profile.key || '--');
    setText('quick-submit-url', location.origin + '/submit.php');
}

function setText(id, value) {
    const element = root?.querySelector(`#${id}`);
    if (element) element.textContent = value;
}
