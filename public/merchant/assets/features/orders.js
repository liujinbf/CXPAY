const STATUS = Object.freeze({
    0: { label: '待支付', className: 'bg-slate-100 text-slate-600' },
    1: { label: '已完成', className: 'bg-emerald-100 text-emerald-700' },
    2: { label: '已超时/关闭', className: 'bg-amber-100 text-amber-700' },
    3: { label: '已退款', className: 'bg-rose-100 text-rose-700' },
});

export function getOrderStatus(status) {
    return STATUS[Number(status)] || { label: '未知', className: 'bg-slate-100 text-slate-600' };
}

let state = null;

export const feature = {
    id: 'order-list',
    async mount(context) {
        const { root, api, ui, signal } = context;
        const tbody = root.querySelector('#order-table-body');

        async function load() {
            tbody.innerHTML = '<tr><td colspan="9" class="p-6 text-center text-slate-400 font-bold">正在加载最新订单数据...</td></tr>';
            try {
                const response = await api.merchantFetch('/api/merchant/order/list', { signal });
                const payload = await response.json();
                if (payload.code !== 1 || !Array.isArray(payload.data)) {
                    throw new Error(payload.msg || '订单获取失败');
                }
                render(tbody, payload.data, ui);
            } catch (error) {
                if (error?.name === 'AbortError') return;
                tbody.innerHTML = `<tr><td colspan="9" class="p-6 text-center text-rose-500 font-bold">${ui.escapeHtml(error.message || '订单加载失败')}</td></tr>`;
            }
        }

        const onClick = (event) => {
            if (event.target.closest('[data-action="refresh-orders"]')) load();
        };
        root.addEventListener('click', onClick);
        state = { root, onClick };
        await load();
        ui.safeCreateIcons(root);
    },
    unmount() {
        if (!state) return;
        state.root.removeEventListener('click', state.onClick);
        state = null;
    },
};

function render(tbody, orders, ui) {
    if (!orders.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="p-6 text-center text-slate-400 font-bold">暂无订单记录</td></tr>';
        return;
    }
    tbody.innerHTML = orders.map((order) => {
        const status = getOrderStatus(order.status);
        return `<tr class="hover:bg-slate-50">
            <td class="p-3 font-mono font-bold text-blue-600">${ui.escapeHtml(order.trade_no)}</td>
            <td class="p-3 font-mono text-slate-500">${ui.escapeHtml(order.out_trade_no)}</td>
            <td class="p-3 font-bold text-slate-800">${ui.escapeHtml(order.subject || '普通收单')}</td>
            <td class="p-3 uppercase font-mono">${ui.escapeHtml(order.pay_type)}</td>
            <td class="p-3 font-bold text-slate-800">¥ ${Number(order.amount).toFixed(2)}</td>
            <td class="p-3 font-mono text-emerald-600 font-bold">¥ ${Number(order.price).toFixed(2)}</td>
            <td class="p-3"><span class="px-2 py-0.5 rounded-full font-bold ${status.className}">${status.label}</span></td>
            <td class="p-3 text-slate-400">${ui.escapeHtml(order.create_time)}</td>
            <td class="p-3 text-slate-400">${ui.escapeHtml(order.pay_time)}</td>
        </tr>`;
    }).join('');
}
