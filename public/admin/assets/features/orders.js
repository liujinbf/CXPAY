export const feature = {
    id: 'orders',
    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (target?.dataset.action === 'search-orders') void loadOrders(context);
            if (target?.dataset.action === 'manual-pay') void mutateOrder(context, 'manual_pay', target.dataset.tradeNo);
            if (target?.dataset.action === 'close-order') void mutateOrder(context, 'close', target.dataset.tradeNo);
            if (target?.dataset.action === 'force-notify') void mutateOrder(context, 'force_notify', target.dataset.tradeNo);
        }, { signal });
        root.querySelector('#admin-order-search')?.addEventListener('keydown', (event) => { if (event.key === 'Enter') void loadOrders(context); }, { signal });
        root.querySelector('#admin-order-status')?.addEventListener('change', () => void loadOrders(context), { signal });
        ui.safeCreateIcons();
        await loadOrders(context);
    },
    unmount() {},
};

async function loadOrders({ root, api, ui, signal }) {
    const body = root.querySelector('#order-table-body');
    const tradeNo = root.querySelector('#admin-order-search')?.value.trim() || '';
    const status = root.querySelector('#admin-order-status')?.value || '';
    if (!body) return;
    try {
        const response = await api.adminFetch(`/api/admin/order/list?page_size=20&trade_no=${encodeURIComponent(tradeNo)}&status=${encodeURIComponent(status)}`, { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '订单加载失败');
        const rows = Array.isArray(payload.data.data) ? payload.data.data : [];
        body.innerHTML = rows.length ? rows.map((order) => renderOrder(order, ui)).join('') : '<tr><td colspan="7" class="p-6 text-center text-slate-400">暂无符合条件的订单</td></tr>';
    } catch (error) { if (error?.name !== 'AbortError') body.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-rose-500">${ui.escapeHtml(error.message)}</td></tr>`; }
}

function renderOrder(order, ui) {
    const tradeNo = ui.escapeHtml(order.trade_no || '');
    const state = { 0: '待支付', 1: '已支付', 2: '已关闭/超时', 3: '已退款' }[Number(order.status)] || '未知';
    const actions = Number(order.status) === 0 ? `<button data-action="manual-pay" data-trade-no="${tradeNo}" class="px-2 py-1 text-amber-700">补单核销</button><button data-action="close-order" data-trade-no="${tradeNo}" class="px-2 py-1 text-rose-600">关闭订单</button>` : '';
    return `<tr><td class="p-3 font-mono text-blue-600">${tradeNo}</td><td class="p-3">${ui.escapeHtml(order.merchant_pid || order.merchant_id || '-')}</td><td class="p-3">¥ ${Number(order.price || order.amount || 0).toFixed(2)}</td><td class="p-3">${state}</td><td class="p-3">${order.create_time ? new Date(Number(order.create_time) * 1000).toLocaleString() : '-'}</td><td class="p-3">${order.pay_time ? new Date(Number(order.pay_time) * 1000).toLocaleString() : '-'}</td><td class="p-3 text-right">${actions}</td></tr>`;
}

async function mutateOrder(context, action, tradeNo) {
    const { api, ui, signal } = context;
    const confirmed = await ui.showConfirm('订单操作确认', `确认对订单 ${tradeNo} 执行此操作？`, action !== 'force_notify');
    if (!confirmed || signal.aborted) return;
    const values = { trade_no: tradeNo };
    if (action === 'manual_pay') values.remark = '管理员后台人工补单';
    try {
        const response = await api.adminFetch(`/api/admin/order/${action}`, { method: 'POST', body: new URLSearchParams(values), signal });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '操作失败');
        ui.showToast(payload.msg || '操作成功');
        await loadOrders(context);
    } catch (error) { if (error?.name !== 'AbortError') ui.showToast(error.message, 'error'); }
}
