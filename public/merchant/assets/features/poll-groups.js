let state = null;

export const feature = {
    id: 'poll-group',
    async mount(context) {
        const { root, api, ui, signal } = context;
        const container = root.querySelector('#poll-group-container');

        async function load() {
            container.innerHTML = '<div class="glass-card rounded-2xl p-6 text-center text-xs text-slate-400">正在加载...</div>';
            try {
                const response = await api.merchantFetch('/api/merchant/channel/list', { signal });
                const payload = await response.json();
                if (payload.code !== 1 || !Array.isArray(payload.data)) {
                    throw new Error(payload.msg || '通道加载失败');
                }
                render(container, payload.data.filter((channel) => Number(channel.status) === 1), ui);
            } catch (error) {
                if (error?.name === 'AbortError') return;
                container.innerHTML = `<div class="glass-card rounded-2xl p-6 text-center text-rose-500 text-xs">${ui.escapeHtml(error.message || '加载失败')}</div>`;
            }
        }

        const onClick = (event) => {
            if (event.target.closest('[data-action="refresh-poll-groups"]')) load();
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

function render(container, channels, ui) {
    if (!channels.length) {
        container.innerHTML = '<div class="glass-card rounded-2xl p-6 text-center text-xs text-slate-400">暂无已启用的收款通道，轮询组需先配置并启用通道</div>';
        return;
    }
    const groups = new Map();
    for (const channel of channels) {
        const category = channel.pay_category || 'other';
        if (!groups.has(category)) groups.set(category, []);
        groups.get(category).push(channel);
    }
    const labels = { wxpay: '微信', alipay: '支付宝', qqpay: 'QQ钱包', other: '其他' };
    const colors = {
        wxpay: 'bg-emerald-100 text-emerald-700',
        alipay: 'bg-blue-100 text-blue-700',
        qqpay: 'bg-purple-100 text-purple-700',
        other: 'bg-slate-100 text-slate-600',
    };
    container.innerHTML = Array.from(groups, ([category, items]) => {
        const rows = items.map((channel, index) => `
            <div class="flex items-center justify-between p-2.5 bg-slate-50 rounded-lg text-xs">
                <span>${index + 1}. ${ui.escapeHtml(channel.title)} · <span class="text-slate-400 font-mono">${ui.escapeHtml(channel.c_type)}</span></span>
                <span class="font-bold text-slate-600">权重: ${(100 / items.length).toFixed(0)}%</span>
            </div>`).join('');
        return `<div class="glass-card rounded-2xl p-5 space-y-3 max-w-3xl">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div><span class="font-bold text-sm text-slate-800">${labels[category] || ui.escapeHtml(category)} 轮询组</span>
                <span class="ml-2 text-xs px-2 py-0.5 ${colors[category] || colors.other} font-bold rounded">${items.length} 个通道</span></div>
            </div>
            <div class="text-xs text-slate-500 space-y-2">${rows}</div>
        </div>`;
    }).join('');
}
