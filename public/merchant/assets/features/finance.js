let state = null;

export const feature = {
    id: 'finance-log',
    async mount(context) {
        const { root, api, ui, signal } = context;
        const tbody = root.querySelector('#finance-log-tbody');

        async function load() {
            tbody.innerHTML = '<tr><td colspan="7" class="p-6 text-center text-slate-400">正在加载财务明细...</td></tr>';
            try {
                const response = await api.merchantFetch('/api/merchant/finance_log', { signal });
                const payload = await response.json();
                if (payload.code !== 1 || !Array.isArray(payload.data)) {
                    throw new Error(payload.msg || '明细获取失败');
                }
                render(tbody, payload.data, ui);
            } catch (error) {
                if (error?.name === 'AbortError') return;
                tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-rose-500 font-bold font-sans">${ui.escapeHtml(error.message || '记录加载失败')}</td></tr>`;
            }
        }

        const onClick = (event) => {
            if (event.target.closest('[data-action="contact-finance"]')) {
                ui.showToast('充值服务费请联系客服管理员');
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
        tbody.innerHTML = '<tr><td colspan="7" class="p-6 text-center text-slate-400 font-bold font-sans">暂无服务费变动记录</td></tr>';
        return;
    }
    tbody.innerHTML = logs.map((log) => {
        const isMinus = Number(log.money) < 0;
        const color = isMinus ? 'text-rose-600' : 'text-emerald-600';
        return `<tr class="hover:bg-slate-50">
            <td class="p-3 text-slate-500">${ui.escapeHtml(log.create_time)}</td>
            <td class="p-3 text-blue-600 font-mono">#${ui.escapeHtml(log.id)}</td>
            <td class="p-3 font-sans ${color} font-bold">${isMinus ? '服务费扣除' : '充值/退减'}</td>
            <td class="p-3 ${color} font-bold">${isMinus ? '' : '+'}${Number(log.money).toFixed(2)}</td>
            <td class="p-3">¥ ${Number(log.before).toFixed(2)}</td>
            <td class="p-3 text-emerald-600 font-bold">¥ ${Number(log.after).toFixed(2)}</td>
            <td class="p-3 font-sans text-slate-500">${ui.escapeHtml(log.memo || '-')}</td>
        </tr>`;
    }).join('');
}
