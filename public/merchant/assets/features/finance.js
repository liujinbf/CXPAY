let state = null;

export const feature = {
    id: 'finance-log',
    async mount(context) {
        const { root, api, ui, signal } = context;
        const tbody = root.querySelector('#finance-log-tbody');

        async function load() {
            tbody.innerHTML = '<tr><td colspan="7" class="p-6 text-center text-slate-400 font-bold">正在加载财务明细...</td></tr>';
            try {
                const response = await api.merchantFetch('/api/merchant/finance_log', { signal });
                const payload = await response.json();
                if (payload.code !== 1 || !Array.isArray(payload.data)) {
                    throw new Error(payload.msg || '明细获取失败');
                }
                render(tbody, payload.data, ui);
                ui.safeCreateIcons(root);
            } catch (error) {
                if (error?.name === 'AbortError') return;
                tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-rose-500 font-bold">${ui.escapeHtml(error.message || '记录加载失败')}</td></tr>`;
            }
        }

        const onClick = async (event) => {
            const btn = event.target.closest('[data-action]');
            if (!btn || !root.contains(btn)) return;
            const action = btn.dataset.action;

            if (action === 'refresh-finance') {
                await load();
                ui.showToast('财务明细已刷新', 'success');
            } else if (action === 'contact-finance') {
                ui.showRechargeModal({
                    api,
                    ui,
                    onRecharged: async () => {
                        ui.showToast('服务费余额充值到账成功！', 'success');
                        await Promise.all([
                            load(),
                            context.getMerchantProfile ? context.getMerchantProfile({ refresh: true }) : Promise.resolve(),
                        ]);
                    }
                });
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

function render(tbody, logs, ui) {
    if (!logs.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="p-6 text-center text-slate-400 font-bold">暂无服务费变动明细记录</td></tr>';
        return;
    }

    tbody.innerHTML = logs.map((log) => {
        const moneyNum = Number(log.money || 0);
        const isMinus = moneyNum < 0;
        const isZero = moneyNum === 0;

        let amountHtml = '';
        if (isZero) {
            amountHtml = `<span class="font-mono text-slate-400 font-bold">¥ 0.00</span>`;
        } else if (isMinus) {
            amountHtml = `<span class="font-mono text-rose-600 font-extrabold">- ¥ ${Math.abs(moneyNum).toFixed(2)}</span>`;
        } else {
            amountHtml = `<span class="font-mono text-emerald-600 font-extrabold">+ ¥ ${moneyNum.toFixed(2)}</span>`;
        }

        const typeClass = log.type_class || (isMinus ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200');
        const typeText = log.type_text || (isMinus ? '手续费扣除' : '资金变动');
        const tradeNo = log.trade_no ? ui.escapeHtml(log.trade_no) : null;

        return `<tr class="hover:bg-slate-50 transition-colors">
            <td class="p-3 font-mono text-slate-500">${ui.escapeHtml(log.create_time)}</td>
            <td class="p-3 font-mono">
                <span class="text-slate-400 text-[11px]">#${ui.escapeHtml(log.id)}</span>
                ${tradeNo ? `<div class="text-blue-600 font-bold text-[11px] truncate max-w-[170px]" title="关联订单: ${tradeNo}">${tradeNo}</div>` : ''}
            </td>
            <td class="p-3 text-center">
                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold ${typeClass}">${ui.escapeHtml(typeText)}</span>
            </td>
            <td class="p-3">${amountHtml}</td>
            <td class="p-3 font-mono text-slate-600">¥ ${Number(log.before || 0).toFixed(2)}</td>
            <td class="p-3 font-mono font-bold text-slate-800">¥ ${Number(log.after || 0).toFixed(2)}</td>
            <td class="p-3 text-slate-600 text-xs leading-relaxed max-w-[280px] break-words">${ui.escapeHtml(log.memo || '-')}</td>
        </tr>`;
    }).join('');
}
