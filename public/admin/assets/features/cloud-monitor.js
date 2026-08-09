export const feature = {
    id: 'cloud-monitor',

    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            if (event.target.closest('[data-action]')?.dataset.action === 'refresh-cloud-monitor') {
                void loadStatus(context);
            }
        }, { signal });
        ui.safeCreateIcons();
        await loadStatus(context);
    },

    unmount() {},
};

async function loadStatus({ root, api, ui, signal }) {
    const list = root.querySelector('#cloud-monitor-list');
    const warningBox = root.querySelector('#cloud-monitor-warnings');
    if (!list || !warningBox) return;

    list.innerHTML = '<div class="glass-panel rounded-2xl p-6 text-center text-xs text-slate-400">正在读取云监控状态...</div>';
    try {
        const response = await api.adminFetch('/api/admin/cloud-monitor/status', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '云监控状态加载失败');
        if (signal.aborted) return;

        const warnings = Array.isArray(payload.data.warnings) ? payload.data.warnings : [];
        warningBox.classList.toggle('hidden', warnings.length === 0);
        warningBox.innerHTML = warnings.map((item) => `<div>${ui.escapeHtml(item)}</div>`).join('');
        const channels = Array.isArray(payload.data.channels) ? payload.data.channels : [];
        list.innerHTML = channels.length
            ? channels.map((item) => renderChannel(item, ui)).join('')
            : '<div class="glass-panel rounded-2xl p-8 text-center text-slate-400 text-xs">尚未配置微信云监控通道</div>';
    } catch (error) {
        if (error?.name !== 'AbortError') {
            list.innerHTML = `<div class="glass-panel rounded-2xl p-6 text-center text-rose-500 text-xs">${ui.escapeHtml(error.message || '云监控状态加载失败')}</div>`;
        }
    }
}

function renderChannel(item, ui) {
    const account = item.account || {};
    const collector = item.collector || {};
    const metrics = item.metrics || {};
    const anomalyCount = Number(metrics.events?.REVIEW_REQUIRED || 0) + Number(metrics.events?.UNMATCHED || 0);
    const outbox = metrics.outbox || {};
    const outboxBacklog = Number(outbox.PENDING || 0) + Number(outbox.RETRY || 0)
        + Number(outbox.PROCESSING || 0) + Number(outbox.FAILED || 0);
    const orders = metrics.orders || {};
    const lastSeen = Number(collector.last_seen_at || 0);
    const lastSeenText = lastSeen > 0 ? new Date(lastSeen * 1000).toLocaleString() : '从未连接';
    const online = collector.online === true;
    const capabilities = {
        RECEIPT_AVAILABLE: '已开通收款单',
        RECEIPT_NOT_OPENED: '未开通收款单，仅小账本',
        BOOK_AVAILABLE: '小账本可用',
        REAUTH_REQUIRED: '需要重新授权',
        TEMPORARY_ERROR: '能力探测异常',
        UNKNOWN: '能力未知',
    };

    return `<div class="glass-panel rounded-2xl p-5 space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div><div class="font-extrabold text-slate-800">通道 #${Number(item.channel_id)} · ${item.pay_type === 'alipay' ? '支付宝' : '微信'} · ${ui.escapeHtml(item.remark || '云监控')}</div><div class="font-mono text-[10px] text-slate-400 mt-1">${ui.escapeHtml(item.account_id || '尚未绑定账号')}</div></div>
            <span class="px-2 py-1 rounded-full text-[10px] font-bold ${online ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}">${online ? '采集器在线' : '采集器离线'}</span>
        </div>
        <div class="grid grid-cols-3 gap-2 text-center">
            <div class="rounded-xl bg-slate-50 p-3"><div class="text-[10px] text-slate-400">待支付订单</div><div class="font-extrabold text-slate-800">${Number(orders.PENDING || 0)}</div></div>
            <div class="rounded-xl bg-amber-50 p-3"><div class="text-[10px] text-amber-600">异常账单</div><div class="font-extrabold text-amber-700">${anomalyCount}</div></div>
            <div class="rounded-xl ${outboxBacklog ? 'bg-rose-50' : 'bg-emerald-50'} p-3"><div class="text-[10px] ${outboxBacklog ? 'text-rose-600' : 'text-emerald-600'}">回调积压</div><div class="font-extrabold">${outboxBacklog}</div></div>
        </div>
        <div class="text-xs space-y-1.5 border-t border-slate-100 pt-3">
            <div class="flex justify-between"><span class="text-slate-400">账号能力</span><span class="font-bold">${ui.escapeHtml(capabilities[account.capability_status] || '账号未连接')}</span></div>
            <div class="flex justify-between"><span class="text-slate-400">采集器 ID</span><span class="font-mono">${ui.escapeHtml(collector.id || '-')}</span></div>
            <div class="flex justify-between"><span class="text-slate-400">最近鉴权</span><span>${ui.escapeHtml(lastSeenText)}</span></div>
            <div class="flex justify-between"><span class="text-slate-400">通道状态</span><span>${item.enabled ? '已启用' : '已停用'}</span></div>
        </div>
    </div>`;
}
