let currentChannelId = 0;
let currentChannelData = null;
let state = null;

export const feature = {
    id: 'bill-source',

    async mount(context) {
        const { root, api, ui, signal } = context;

        async function loadList() {
            const tbody = root.querySelector('#bill-source-tbody');
            if (!tbody) return;

            const keyword     = root.querySelector('#bs-filter-keyword')?.value?.trim() || '';
            const payType     = root.querySelector('#bs-filter-pay-type')?.value || '';
            const online      = root.querySelector('#bs-filter-online')?.value || '';
            const merchantPid = root.querySelector('#bs-filter-merchant-pid')?.value?.trim() || '';

            const params = new URLSearchParams();
            if (keyword)     params.set('keyword', keyword);
            if (payType)     params.set('pay_type', payType);
            if (online)      params.set('online', online);
            if (merchantPid) params.set('merchant_pid', merchantPid);

            tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center text-slate-400 font-bold">正在加载监控通道数据...</td></tr>';

            try {
                const res = await api.adminFetch(`/api/admin/bill-source/list?${params}`, { signal });
                const payload = await res.json();
                if (payload.code !== 1 || !payload.data) {
                    throw new Error(payload.msg || '获取通道列表失败');
                }

                const { list, stats } = payload.data;

                // 更新顶部三指标
                setText(root, 'stat-total-channels', String(stats?.total_channels ?? list.length));
                setText(root, 'stat-online-channels', String(stats?.online_channels ?? 0));
                setText(root, 'stat-total-events', String(stats?.total_events ?? 0));

                renderChannelList(tbody, list, ui);
                ui.safeCreateIcons(root);
            } catch (e) {
                if (e?.name !== 'AbortError') {
                    tbody.innerHTML = `<tr><td colspan="7" class="p-8 text-center text-rose-500 font-bold">加载失败: ${ui.escapeHtml(e.message)}</td></tr>`;
                }
            }
        }

        const onClick = async (event) => {
            const btn = event.target.closest('[data-action]');
            if (!btn || !root.contains(btn)) return;
            const action = btn.dataset.action;
            const channelId = parseInt(btn.dataset.channelId || '0', 10);

            if (action === 'refresh-list' || action === 'search-list') {
                await loadList();
            } else if (action === 'open-token-modal') {
                await openTokenModal(context, channelId);
            } else if (action === 'close-token-modal') {
                closeTokenModal(root);
            } else if (action === 'modal-rotate-ingest') {
                await rotateToken(context, 'ingest');
            } else if (action === 'modal-rotate-feed') {
                await rotateToken(context, 'feed');
            } else if (action === 'modal-show-qrcode') {
                showConfigQrCode(context);
            } else if (action === 'copy-modal-ingest-token') {
                copyText(root.querySelector('#modal-ingest-token-val')?.textContent, ui);
            } else if (action === 'copy-modal-feed-token') {
                copyText(root.querySelector('#modal-feed-token-val')?.textContent, ui);
            } else if (action === 'open-events-modal' || action === 'refresh-events') {
                const targetId = channelId || currentChannelId;
                await openEventsModal(context, targetId);
            } else if (action === 'close-events-modal') {
                closeEventsModal(root);
            } else if (action === 'test-ingest') {
                await testIngest(context, channelId, loadList);
            } else if (action === 'clear-events') {
                await clearEvents(context, channelId, loadList);
            }
        };

        root.addEventListener('click', onClick);
        root.querySelector('#bs-filter-keyword')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') void loadList();
        }, { signal });

        state = { root, onClick };
        await loadList();
    },

    unmount() {
        if (state) {
            state.root.removeEventListener('click', state.onClick);
            state = null;
        }
        currentChannelId = 0;
        currentChannelData = null;
    },
};

function renderChannelList(tbody, list, ui) {
    if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="p-8 text-center text-slate-400 font-bold">暂无匹配的监控通道</td></tr>';
        return;
    }

    tbody.innerHTML = list.map((c) => {
        const isOnline = Number(c.online_status) === 1;
        const onlineBadge = isOnline
            ? `<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>在线 (${ui.escapeHtml(c.online_duration_format)})</span>
               </span>`
            : `<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500 border border-slate-200">
                <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                <span>离线</span>
               </span>`;

        const ingestBadge = c.ingest_token_configured
            ? `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700">采集端 ✓</span>`
            : `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-600">采集端 ✕</span>`;

        const feedBadge = c.feed_token_configured
            ? `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-indigo-50 text-indigo-700">拉取端 ✓</span>`
            : `<span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-600">拉取端 ✕</span>`;

        const payIcon = c.pay_type === 'wxpay'
            ? '<span class="text-emerald-600 font-bold">微信</span>'
            : (c.pay_type === 'alipay' ? '<span class="text-blue-600 font-bold">支付宝</span>' : '<span class="text-indigo-600 font-bold">QQ</span>');

        return `<tr class="hover:bg-slate-50/80 transition-colors">
            <td class="p-3 font-mono">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-blue-600 text-sm">#${c.id}</span>
                    <span class="text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded font-bold">${payIcon}</span>
                    <span class="font-bold text-slate-800 text-xs">${ui.escapeHtml(c.name)}</span>
                </div>
                <div class="text-[10px] text-slate-400 font-mono mt-0.5">驱动: ${ui.escapeHtml(c.c_type)}</div>
            </td>
            <td class="p-3">
                <div class="font-mono text-slate-700 font-bold">PID: ${ui.escapeHtml(c.merchant_pid)}</div>
                <div class="text-[11px] text-slate-400">${ui.escapeHtml(c.merchant_name)}</div>
            </td>
            <td class="p-3 font-mono text-[11px]">
                <div class="text-slate-700 font-bold">设备: ${ui.escapeHtml(c.device_id || '未绑定')}</div>
                <div class="text-slate-400">采集器: ${ui.escapeHtml(c.collector_id || '未绑定')}</div>
            </td>
            <td class="p-3 text-center space-x-1">
                ${ingestBadge}
                ${feedBadge}
            </td>
            <td class="p-3 text-center">
                ${onlineBadge}
                <div class="text-[10px] text-slate-400 font-mono mt-0.5">心跳: ${ui.escapeHtml(c.last_heartbeat)}</div>
            </td>
            <td class="p-3 text-center">
                <span class="font-mono font-black text-sm ${c.event_count > 0 ? 'text-indigo-600' : 'text-slate-400'}">${c.event_count}</span>
            </td>
            <td class="p-3 text-right whitespace-nowrap">
                <div class="flex items-center justify-end gap-1">
                    <button data-action="open-token-modal" data-channel-id="${c.id}" class="px-2 py-1 bg-cyan-50 text-cyan-700 hover:bg-cyan-600 hover:text-white rounded-lg font-bold transition-colors cursor-pointer" title="管理并轮换 Ingest/Feed 令牌与扫码配置">
                        🔑 令牌与扫码
                    </button>
                    <button data-action="open-events-modal" data-channel-id="${c.id}" class="px-2 py-1 bg-purple-50 text-purple-700 hover:bg-purple-600 hover:text-white rounded-lg font-bold transition-colors cursor-pointer" title="查看最近采集到的账单流水">
                        📋 账单流水
                    </button>
                    <button data-action="test-ingest" data-channel-id="${c.id}" class="px-2 py-1 bg-amber-50 text-amber-700 hover:bg-amber-600 hover:text-white rounded-lg font-bold transition-colors cursor-pointer" title="模拟推送一条测试账单事件">
                        ⚡ 模拟
                    </button>
                    <button data-action="clear-events" data-channel-id="${c.id}" class="px-2 py-1 bg-rose-50 text-rose-600 hover:bg-rose-600 hover:text-white rounded-lg font-bold transition-colors cursor-pointer" title="清空该通道已积压的账单事件">
                        🧹 清空
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

async function openTokenModal(context, channelId) {
    const { root, api, ui, signal } = context;
    currentChannelId = channelId;

    const modal = root.querySelector('#modal-token-config');
    if (!modal) return;

    setText(root, 'modal-channel-subtitle', `通道 #${channelId} 正在加载...`);
    modal.classList.remove('hidden');

    // 隐藏旧的令牌展示框和二维码
    root.querySelector('#modal-ingest-new-token-box')?.classList.add('hidden');
    root.querySelector('#modal-feed-new-token-box')?.classList.add('hidden');
    root.querySelector('#modal-qrcode-container')?.classList.add('hidden');

    try {
        const res = await api.adminFetch(`/api/admin/bill-source/status?id=${channelId}`, { signal });
        const payload = await res.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '加载通道令牌状态失败');

        const d = payload.data;
        currentChannelData = d;

        setText(root, 'modal-channel-subtitle', `通道 #${channelId} · ${d.channel_name || d.pay_type}`);
        setVal(root, 'modal-collector-id', d.collector_id || '');
        setVal(root, 'modal-ingest-ip-white', d.ingest_ip_white || '');

        setBadge(root, 'modal-ingest-badge', d.ingest_token_configured, '已配置令牌', '未配置令牌');
        setBadge(root, 'modal-feed-badge', d.feed_token_configured, '已配置令牌', '未配置令牌');

        ui.safeCreateIcons(modal);
    } catch (e) {
        if (e?.name !== 'AbortError') ui.showToast(e.message, 'error');
    }
}

function closeTokenModal(root) {
    root.querySelector('#modal-token-config')?.classList.add('hidden');
}

async function rotateToken(context, scope) {
    const { root, api, ui, signal } = context;
    if (!currentChannelId) return;

    const label = scope === 'ingest' ? '采集端 (Ingest)' : '拉取端 (Feed)';
    const confirmed = await ui.showConfirm(
        `轮换${label}令牌`,
        `确定要轮换通道 #${currentChannelId} 的${label}访问令牌吗？\n轮换后旧令牌将立即作废，手机监控端需重新填入新令牌！`,
        true
    );
    if (!confirmed || signal.aborted) return;

    const body = new URLSearchParams({ id: String(currentChannelId), scope });
    if (scope === 'ingest') {
        const collectorId = root.querySelector('#modal-collector-id')?.value?.trim() || '';
        const ipWhite = root.querySelector('#modal-ingest-ip-white')?.value?.trim() || '';
        if (collectorId) body.append('collector_id', collectorId);
        if (ipWhite)     body.append('ingest_ip_white', ipWhite);
    }

    try {
        const res = await api.adminFetch('/api/admin/bill-source/rotate-token', {
            method: 'POST', body, signal,
        });
        const payload = await res.json();
        if (payload.code !== 1) throw new Error(payload.msg || payload.message || '轮换失败');

        const token = payload.data?.token || '';
        if (scope === 'ingest') {
            setText(root, 'modal-ingest-token-val', token);
            root.querySelector('#modal-ingest-new-token-box')?.classList.remove('hidden');
            setBadge(root, 'modal-ingest-badge', true, '已配置令牌', '未配置令牌');
            if (currentChannelData) currentChannelData.ingest_token = token;
        } else {
            setText(root, 'modal-feed-token-val', token);
            root.querySelector('#modal-feed-new-token-box')?.classList.remove('hidden');
            setBadge(root, 'modal-feed-badge', true, '已配置令牌', '未配置令牌');
        }

        ui.showToast(`${label}令牌已生成，请立即复制或扫码保存！`, 'success');
        ui.safeCreateIcons(root);
    } catch (e) {
        if (e?.name !== 'AbortError') ui.showToast(e.message, 'error');
    }
}

function showConfigQrCode(context) {
    const { root, ui } = context;
    const container = root.querySelector('#modal-qrcode-container');
    const canvasBox = root.querySelector('#modal-qrcode-canvas');
    if (!container || !canvasBox) return;

    const collectorId = root.querySelector('#modal-collector-id')?.value?.trim() || currentChannelData?.collector_id || '';
    const ingestToken = root.querySelector('#modal-ingest-token-val')?.textContent?.trim() || '';

    const host = window.location.origin;
    const configPayload = {
        action: 'cxpay_monitor_config',
        version: '2',
        gateway_url: host,
        channel_id: currentChannelId,
        collector_id: collectorId,
        token: ingestToken || 'CONFIGURED',
    };

    const qrText = JSON.stringify(configPayload);
    canvasBox.innerHTML = '';

    if (window.QRCode) {
        new window.QRCode(canvasBox, {
            text: qrText,
            width: 180,
            height: 180,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: window.QRCode.CorrectLevel.M,
        });
        container.classList.remove('hidden');
        ui.showToast('手机扫码配置二维码已生成！', 'success');
    } else {
        ui.showToast('QRCode 组件未就绪，配置串为: ' + qrText, 'info');
    }
}

async function openEventsModal(context, channelId) {
    const { root, api, ui, signal } = context;
    currentChannelId = channelId;

    const modal = root.querySelector('#modal-events-list');
    const tbody = root.querySelector('#modal-events-tbody');
    if (!modal || !tbody) return;

    setText(root, 'modal-events-subtitle', `通道 #${channelId}`);
    modal.classList.remove('hidden');
    tbody.innerHTML = '<tr><td colspan="6" class="p-6 text-center text-slate-400">正在拉取账单流水...</td></tr>';

    try {
        const res = await api.adminFetch(`/api/admin/bill-source/events?channel_id=${channelId}`, { signal });
        const payload = await res.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '获取账单流水失败');

        const events = payload.data.list || [];
        if (!events.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="p-6 text-center text-slate-400 font-bold">该通道暂无采集账单事件</td></tr>';
            return;
        }

        tbody.innerHTML = events.map((e) => `
            <tr class="hover:bg-slate-50 font-mono text-[11px]">
                <td class="p-2.5 text-slate-400">#${e.id}</td>
                <td class="p-2.5 font-bold text-blue-600">${ui.escapeHtml(e.source_bill_id)}</td>
                <td class="p-2.5 font-bold text-emerald-600">¥ ${e.money}</td>
                <td class="p-2.5 uppercase font-bold">${ui.escapeHtml(e.pay_type)}</td>
                <td class="p-2.5 text-slate-600 font-sans">${ui.escapeHtml(e.remark || '-')}</td>
                <td class="p-2.5 text-slate-400">${ui.escapeHtml(e.create_time)}</td>
            </tr>
        `).join('');
    } catch (e) {
        if (e?.name !== 'AbortError') {
            tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-rose-500">${ui.escapeHtml(e.message)}</td></tr>`;
        }
    }
}

function closeEventsModal(root) {
    root.querySelector('#modal-events-list')?.classList.add('hidden');
}

async function testIngest(context, channelId, refreshCallback) {
    const { api, ui, signal } = context;
    const confirmed = await ui.showConfirm(
        '模拟上报测试账单',
        `确定要向通道 #${channelId} 模拟写入一笔 ¥0.01 的测试账单事件吗？\n写入后可测试 PC 端拉取与系统核销链路。`,
        false
    );
    if (!confirmed || signal.aborted) return;

    try {
        const res = await api.adminFetch('/api/admin/bill-source/test-ingest', {
            method: 'POST',
            body: new URLSearchParams({ channel_id: String(channelId), money: '0.01', remark: '管理员后台模拟上报' }),
            signal,
        });
        const payload = await res.json();
        if (payload.code !== 1) throw new Error(payload.msg || '模拟失败');

        ui.showToast(payload.msg || '测试账单已成功写入！', 'success');
        if (refreshCallback) await refreshCallback();
    } catch (e) {
        if (e?.name !== 'AbortError') ui.showToast(e.message, 'error');
    }
}

async function clearEvents(context, channelId, refreshCallback) {
    const { api, ui, signal } = context;
    const confirmed = await ui.showConfirm(
        '清空通道账单队列',
        `确定要清空通道 #${channelId} 当前积压的所有历史账单事件吗？`,
        true
    );
    if (!confirmed || signal.aborted) return;

    try {
        const res = await api.adminFetch('/api/admin/bill-source/clear-events', {
            method: 'POST',
            body: new URLSearchParams({ channel_id: String(channelId) }),
            signal,
        });
        const payload = await res.json();
        if (payload.code !== 1) throw new Error(payload.msg || '清空失败');

        ui.showToast(payload.msg || '已清空队列', 'success');
        if (refreshCallback) await refreshCallback();
    } catch (e) {
        if (e?.name !== 'AbortError') ui.showToast(e.message, 'error');
    }
}

function copyText(text, ui) {
    const val = (text || '').trim();
    if (!val) { ui.showToast('内容为空', 'error'); return; }
    navigator.clipboard.writeText(val)
        .then(() => ui.showToast('已复制到剪贴板', 'success'))
        .catch(() => ui.showToast('复制失败，请手动选中文本复制', 'error'));
}

function setText(root, id, v) { const el = root.querySelector(`#${id}`); if (el) el.textContent = v ?? '--'; }
function setVal(root, id, v)  { const el = root.querySelector(`#${id}`); if (el) el.value = v ?? ''; }
function setBadge(root, id, configured, trueText, falseText) {
    const el = root.querySelector(`#${id}`);
    if (!el) return;
    el.textContent = configured ? trueText : falseText;
    el.className = `px-2 py-0.5 rounded-full text-[10px] font-bold ${
        configured ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-600'
    }`;
}
