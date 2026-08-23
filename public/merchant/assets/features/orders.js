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

        const onClick = async (event) => {
            const btn = event.target.closest('[data-action]');
            if (!btn || !root.contains(btn)) return;
            const action = btn.dataset.action;
            const tradeNo = btn.dataset.tradeNo;

            if (action === 'refresh-orders') {
                await load();
            } else if (action === 'manual-pay-order') {
                const confirmed = await ui.showConfirm(
                    '确认手动补单',
                    `确定要将订单 [${tradeNo}] 手动核销补单吗？\n补单后将扣除对应通道手续费并向商户异步回调地址推送入账通知。`,
                    false
                );
                if (!confirmed) return;
                try {
                    btn.disabled = true;
                    btn.innerHTML = '<i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i> 补单中...';
                    ui.safeCreateIcons(root);
                    const res = await api.merchantFetch('/api/merchant/order/manual_pay', {
                        method: 'POST',
                        body: new URLSearchParams({ trade_no: tradeNo }),
                        signal,
                    });
                    const payload = await res.json();
                    if (payload.code === 1) {
                        ui.showToast(payload.msg || '手动补单成功', 'success');
                        await load();
                    } else {
                        ui.showToast(payload.msg || '补单失败', 'error');
                    }
                } catch (e) {
                    if (e?.name !== 'AbortError') ui.showToast(e.message || '操作失败', 'error');
                } finally {
                    btn.disabled = false;
                }
            } else if (action === 'delete-order') {
                const confirmed = await ui.showConfirm(
                    '删除未完成订单',
                    `确定要删除订单 [${tradeNo}] 吗？\n（若订单预占了手续费将自动原路退回可用余额）`,
                    true
                );
                if (!confirmed) return;
                try {
                    const res = await api.merchantFetch('/api/merchant/order/delete', {
                        method: 'POST',
                        body: new URLSearchParams({ trade_no: tradeNo }),
                        signal,
                    });
                    const payload = await res.json();
                    if (payload.code === 1) {
                        ui.showToast('订单已成功删除', 'success');
                        await load();
                    } else {
                        ui.showToast(payload.msg || '删除失败', 'error');
                    }
                } catch (e) {
                    if (e?.name !== 'AbortError') ui.showToast(e.message || '操作失败', 'error');
                }
            } else if (action === 'resend-order-notify') {
                try {
                    btn.disabled = true;
                    btn.innerHTML = '<i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i> 发送中';
                    ui.safeCreateIcons(root);
                    const res = await api.merchantFetch('/api/merchant/order/resend_notify', {
                        method: 'POST',
                        body: new URLSearchParams({ trade_no: tradeNo }),
                        signal,
                    });
                    const payload = await res.json();
                    if (payload.code === 1) {
                        ui.showToast(payload.msg || '通知已重新发送', 'success');
                    } else {
                        ui.showToast(payload.msg || '发送失败', 'error');
                    }
                } catch (e) {
                    if (e?.name !== 'AbortError') ui.showToast(e.message || '操作失败', 'error');
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i data-lucide="send" class="w-3 h-3"></i> 重发回调';
                    ui.safeCreateIcons(root);
                }
            } else if (action === 'batch-clean-orders') {
                const confirmed = await ui.showConfirm(
                    '一键清理未完成订单',
                    '确定要一键清理 5 分钟以前创建的所有「待支付」与「已超时关闭」废弃订单吗？\n已成功付款的交易订单不会被删除。',
                    true
                );
                if (!confirmed) return;
                try {
                    btn.disabled = true;
                    btn.innerHTML = '<i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i> 清理中...';
                    ui.safeCreateIcons(root);
                    const res = await api.merchantFetch('/api/merchant/order/batch_clean', {
                        method: 'POST',
                        body: new URLSearchParams({ before_minutes: '5' }),
                        signal,
                    });
                    const payload = await res.json();
                    if (payload.code === 1) {
                        ui.showToast(payload.msg || '清理完成', 'success');
                        await load();
                    } else {
                        ui.showToast(payload.msg || '清理失败', 'error');
                    }
                } catch (e) {
                    if (e?.name !== 'AbortError') ui.showToast(e.message || '操作失败', 'error');
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i data-lucide="trash-2" class="w-3.5 h-3.5"></i> 一键清理未完成订单';
                    ui.safeCreateIcons(root);
                }
            }
        };
        root.addEventListener('click', onClick);
        state = { root, onClick };
        await load();
    },
    unmount() {
        if (!state) return;
        state.root.removeEventListener('click', state.onClick);
        state = null;
    },
};

function render(tbody, orders, ui) {
    if (!orders.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="p-6 text-center text-slate-400 font-bold">暂无订单记录</td></tr>';
        return;
    }
    tbody.innerHTML = orders.map((order) => {
        const status = getOrderStatus(order.status);
        const tradeNo = ui.escapeHtml(order.trade_no);
        const statusCode = Number(order.status);
        
        let actionHtml = '';
        if (statusCode === 1) {
            // 已完成订单：提供重发回调
            actionHtml = `
                <button data-action="resend-order-notify" data-trade-no="${tradeNo}" class="px-2 py-1 bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white rounded-lg text-[11px] font-bold transition-all flex items-center gap-1 cursor-pointer" title="重新向商户回调地址推送支付结果">
                    <i data-lucide="send" class="w-3 h-3"></i> 重发回调
                </button>
            `;
        } else {
            // 待支付 (0) 或 已超时/已关闭 (2) 或 已退款 (3)：提供手动补单与删除
            actionHtml = `
                <div class="flex items-center justify-center gap-1.5">
                    <button data-action="manual-pay-order" data-trade-no="${tradeNo}" class="px-2 py-1 bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white rounded-lg text-[11px] font-bold transition-all flex items-center gap-1 cursor-pointer" title="手动标记为已支付并补发回调">
                        <i data-lucide="check-circle" class="w-3 h-3"></i> 补单
                    </button>
                    <button data-action="delete-order" data-trade-no="${tradeNo}" class="px-2 py-1 bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white rounded-lg text-[11px] font-bold transition-all flex items-center gap-1 cursor-pointer" title="删除此未完成订单">
                        <i data-lucide="trash" class="w-3 h-3"></i> 删除
                    </button>
                </div>
            `;
        }

        return `<tr class="hover:bg-slate-50 transition-colors">
            <td class="p-3 font-mono font-bold text-blue-600">${tradeNo}</td>
            <td class="p-3 font-mono text-slate-500">${ui.escapeHtml(order.out_trade_no)}</td>
            <td class="p-3 font-bold text-slate-800">${ui.escapeHtml(order.subject || '普通收单')}</td>
            <td class="p-3 uppercase font-mono">${ui.escapeHtml(order.pay_type)}</td>
            <td class="p-3 font-bold text-slate-800">¥ ${Number(order.amount).toFixed(2)}</td>
            <td class="p-3 font-mono text-emerald-600 font-bold">¥ ${Number(order.price).toFixed(2)}</td>
            <td class="p-3"><span class="px-2 py-0.5 rounded-full font-bold ${status.className}">${status.label}</span></td>
            <td class="p-3 text-slate-400">${ui.escapeHtml(order.create_time)}</td>
            <td class="p-3 text-slate-400">${ui.escapeHtml(order.pay_time)}</td>
            <td class="p-3 text-center">${actionHtml}</td>
        </tr>`;
    }).join('');
    ui.safeCreateIcons(tbody);
}
