let cachedChannels = [];
let activeFilter = 'all';
let searchKeyword = '';
let refreshTimer = null;

export const feature = {
    id: 'cloud-monitor',

    async mount(context) {
        const { root, ui, signal } = context;

        const onClick = async (event) => {
            const trigger = event.target.closest('[data-action]');
            if (trigger && root.contains(trigger)) {
                const action = trigger.dataset.action;
                const id = Number(trigger.dataset.id || 0);

                if (action === 'refresh-cloud-monitor') void loadStatus(context);
                if (action === 'toggle-channel') {
                    const status = Number(trigger.dataset.status || 0);
                    void toggleChannelStatus(context, id, status === 1 ? 0 : 1);
                }
                if (action === 'show-pair-qr') {
                    void showPairModal(context, id);
                }
                if (action === 'close-pair-modal') {
                    closePairModal(root);
                }
            }

            const filterBtn = event.target.closest('.filter-btn');
            if (filterBtn && root.contains(filterBtn)) {
                activeFilter = filterBtn.dataset.filter || 'all';
                root.querySelectorAll('.filter-btn').forEach(btn => {
                    if (btn === filterBtn) {
                        btn.className = 'px-3 py-1.5 rounded-xl text-xs font-bold bg-indigo-50 text-indigo-600 border border-indigo-200 filter-btn';
                    } else {
                        btn.className = 'px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-50 border border-transparent filter-btn';
                    }
                });
                renderChannelList(context);
            }
        };

        const onSearch = (event) => {
            if (event.target.id === 'cloud-monitor-search') {
                searchKeyword = event.target.value.trim().toLowerCase();
                renderChannelList(context);
            }
        };

        root.addEventListener('click', onClick, { signal });
        root.addEventListener('input', onSearch, { signal });

        ui.safeCreateIcons();
        await loadStatus(context);

        // 开启 10 秒自动轮询心跳
        refreshTimer = setInterval(() => {
            if (!signal.aborted) {
                void loadStatus(context, true);
            }
        }, 10000);
    },

    unmount() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
        cachedChannels = [];
        activeFilter = 'all';
        searchKeyword = '';
    },
};

async function loadStatus(context, isSilent = false) {
    const { root, api, ui, signal } = context;
    const list = root.querySelector('#cloud-monitor-list');
    const warningBox = root.querySelector('#cloud-monitor-warnings');
    if (!list) return;

    if (!isSilent) {
        list.innerHTML = '<div class="col-span-full p-12 text-center text-xs text-slate-400 bg-white rounded-3xl border border-dashed border-slate-200">正在读取云监控运行状态...</div>';
    }

    try {
        const response = await api.adminFetch('/api/admin/cloud-monitor/status', { signal });
        const payload = await response.json();
        if (signal.aborted) return;
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '云监控状态加载失败');

        const stats = payload.data.stats || {};
        updateStats(root, stats);

        const warnings = Array.isArray(payload.data.warnings) ? payload.data.warnings : [];
        if (warningBox) {
            warningBox.classList.toggle('hidden', warnings.length === 0);
            warningBox.innerHTML = warnings.map(w => `<div class="flex items-center gap-1.5">${ui.escapeHtml(w)}</div>`).join('');
        }

        cachedChannels = Array.isArray(payload.data.channels) ? payload.data.channels : [];
        renderChannelList(context);
    } catch (error) {
        if (error?.name !== 'AbortError' && !isSilent) {
            list.innerHTML = `<div class="col-span-full p-8 text-center text-rose-500 bg-rose-50 rounded-2xl border border-rose-200 text-xs font-bold">${ui.escapeHtml(error.message || '云监控状态加载失败')}</div>`;
        }
    }
}

function updateStats(root, stats) {
    const setVal = (id, val) => {
        const el = root.querySelector(id);
        if (el) el.textContent = val;
    };
    setVal('#stat-total-devices', `${stats.total_devices || 0} 个`);
    setVal('#stat-online-devices', `${stats.online_devices || 0} 正常`);
    setVal('#stat-offline-devices', `${stats.offline_devices || 0} 离线`);
    setVal('#stat-today-money', `¥${stats.today_total_money || '0.00'}`);
}

function renderChannelList(context) {
    const { root, ui } = context;
    const list = root.querySelector('#cloud-monitor-list');
    if (!list) return;

    let filtered = cachedChannels.filter(ch => {
        if (activeFilter === 'online' && !ch.is_online) return false;
        if (activeFilter === 'offline' && ch.is_online) return false;
        if (activeFilter === 'wxpay' && !ch.pay_category.includes('wx') && !ch.c_type.includes('wx')) return false;
        if (activeFilter === 'alipay' && !ch.pay_category.includes('ali') && !ch.c_type.includes('ali')) return false;
        if (searchKeyword) {
            const titleMatch = (ch.title || '').toLowerCase().includes(searchKeyword);
            const idMatch = String(ch.id).includes(searchKeyword);
            const typeMatch = (ch.c_type || '').toLowerCase().includes(searchKeyword);
            if (!titleMatch && !idMatch && !typeMatch) return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        list.innerHTML = `
            <div class="col-span-full p-16 text-center text-slate-400 bg-white rounded-3xl border border-dashed border-slate-200 space-y-2">
                <i data-lucide="cloud-off" class="w-12 h-12 mx-auto text-slate-300"></i>
                <div class="text-sm font-bold text-slate-700">没有符合筛选条件的监控通道</div>
                <p class="text-xs text-slate-400">请检查筛选条件或前往【收款通道配置】添加新的挂机通道</p>
            </div>
        `;
        ui.safeCreateIcons(list);
        return;
    }

    list.innerHTML = filtered.map(ch => renderCard(ch, ui)).join('');
    ui.safeCreateIcons(list);
}

function renderCard(ch, ui) {
    const isOnline = Boolean(ch.is_online);
    const isEnabled = Number(ch.status) === 1;

    let heartbeatText = '从未收到心跳';
    if (ch.last_heartbeat_time > 0) {
        if (ch.heartbeat_diff <= 30) {
            heartbeatText = `<span class="text-emerald-600 font-bold font-mono">🟢 刚刚 (${ch.heartbeat_diff}秒前)</span>`;
        } else if (ch.heartbeat_diff <= 120) {
            heartbeatText = `<span class="text-emerald-600 font-mono">${ch.heartbeat_diff}秒前</span>`;
        } else if (ch.heartbeat_diff < 3600) {
            heartbeatText = `<span class="text-amber-600 font-mono">${Math.floor(ch.heartbeat_diff / 60)}分钟前 (超时)</span>`;
        } else {
            heartbeatText = `<span class="text-slate-400">${new Date(ch.last_heartbeat_time * 1000).toLocaleString()}</span>`;
        }
    }

    const driverNames = {
        'cxpay.app_asst_universal': '安卓通用监控助手',
        'wxpay_app_asst': '微信挂机助手',
        'alipay_app_asst': '支付宝挂机助手',
        'qqpay_app_asst': 'QQ挂机助手',
        'alipay_cookie_cloud': '支付宝Cookie云端免挂',
        'wechat_dy_bill': '微信店员小账本',
    };
    const driverLabel = driverNames[ch.c_type] || ch.c_type;

    let durationText = '';
    if (isOnline && ch.online_since > 0) {
        const secs = Math.floor(Date.now() / 1000) - ch.online_since;
        durationText = formatDuration(secs);
    }

    return `
    <div class="bg-white rounded-3xl p-5 border ${isOnline ? 'border-emerald-200/80 shadow-xs ring-1 ring-emerald-500/10' : 'border-slate-200 opacity-80'} space-y-4 flex flex-col justify-between transition-all hover:shadow-md">
        <!-- 头部信息 -->
        <div class="space-y-2">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0 flex-1">
                    <span class="w-3 h-3 rounded-full ${isOnline ? 'bg-emerald-500 shadow-sm shadow-emerald-500/50 animate-pulse' : 'bg-rose-400'}" title="${isOnline ? '采集端在线监听' : '采集端离线'}"></span>
                    <h3 class="font-extrabold text-slate-800 text-sm truncate">${ui.escapeHtml(ch.title)}</h3>
                    <span class="text-[10px] text-slate-400 font-mono shrink-0">#${ch.id}</span>
                </div>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${isOnline ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200'}">
                    ${isOnline ? '🟢 在线' : '🔴 离线'}
                </span>
            </div>

            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 text-[10px] font-mono">
                    ${ui.escapeHtml(driverLabel)}
                </span>
                <span class="px-2 py-0.5 rounded-md ${ch.merchant_id > 0 ? 'bg-purple-50 text-purple-700' : 'bg-blue-50 text-blue-700'} text-[10px] font-bold">
                    ${ch.merchant_id > 0 ? `商户 #${ch.merchant_id}` : '平台直营'}
                </span>
                ${durationText ? `<span class="px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 text-[10px] font-bold font-mono">⏱ 已连线 ${durationText}</span>` : ''}
            </div>
        </div>

        <!-- 中间指标仪表板 -->
        <div class="grid grid-cols-2 gap-2 p-3 bg-slate-50/80 rounded-2xl border border-slate-100 text-center">
            <div class="p-2 bg-white rounded-xl border border-slate-100 shadow-2xs">
                <div class="text-[10px] text-slate-400">今日累计收款</div>
                <div class="text-sm font-black text-amber-600 font-mono mt-0.5">¥${ch.today_money}</div>
            </div>
            <div class="p-2 bg-white rounded-xl border border-slate-100 shadow-2xs">
                <div class="text-[10px] text-slate-400">今日交易笔数</div>
                <div class="text-sm font-black text-slate-800 font-mono mt-0.5">${ch.today_paid_orders} <span class="text-[10px] text-slate-400 font-normal">/ ${ch.today_orders}单</span></div>
            </div>
        </div>

        <!-- 详细信息参数 -->
        <div class="text-[11px] space-y-1.5 text-slate-500 pt-1 border-t border-slate-100">
            <div class="flex justify-between items-center">
                <span>采集设备 ID:</span>
                <span class="font-mono text-slate-700 font-bold">${ui.escapeHtml(ch.device_id || '免配置自动匹配')}</span>
            </div>
            <div class="flex justify-between items-center">
                <span>最后收到心跳:</span>
                <span>${heartbeatText}</span>
            </div>
            <div class="flex justify-between items-center">
                <span>限额规则:</span>
                <span class="font-mono text-slate-700">单笔 ¥${ch.single_min}~${ch.single_max > 0 ? ch.single_max : '不限'} | 日上限 ¥${ch.day_max > 0 ? ch.day_max : '不限'}</span>
            </div>
        </div>

        <!-- 底部快捷操作 -->
        <div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
            <button type="button" data-action="toggle-channel" data-id="${Number(ch.id)}" data-status="${isEnabled ? 1 : 0}" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${isEnabled ? 'bg-amber-50 hover:bg-amber-100 text-amber-700' : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700'}">
                ${isEnabled ? '⏸ 暂停通道' : '▶ 启用通道'}
            </button>
            <div class="flex items-center gap-1.5">
                <button type="button" data-action="show-pair-qr" data-id="${Number(ch.id)}" class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 rounded-xl text-xs font-bold transition-colors flex items-center gap-1 cursor-pointer">
                    📷 扫码配对
                </button>
                <a href="/api/admin/channel/download_preset?id=${Number(ch.id)}" target="_blank" class="px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-600 rounded-xl text-xs font-bold transition-colors">
                    📥 监控包
                </a>
            </div>
        </div>
    </div>
    `;
}

function formatDuration(seconds) {
    if (seconds <= 0) return '0秒';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}小时${m}分`;
    if (m > 0) return `${m}分${s}秒`;
    return `${s}秒`;
}

async function toggleChannelStatus(context, id, newStatus) {
    const { api, ui, signal } = context;
    try {
        const body = new URLSearchParams({ id: String(id), status: String(newStatus) });
        const resp = await api.adminFetch('/api/admin/cloud-monitor/toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            signal,
        });
        const res = await resp.json();
        if (res.code !== 1) throw new Error(res.msg || '操作失败');
        ui.showToast(res.msg || '通道状态已更新', 'success');
        await loadStatus(context);
    } catch (e) {
        ui.showToast(e.message || '操作失败', 'error');
    }
}

async function showPairModal(context, id) {
    const { root, api, ui, signal } = context;
    const modal = root.querySelector('#cloud-appasst-pair-modal');
    const canvas = root.querySelector('#cloud-pair-qrcode');
    const titleEl = root.querySelector('#cloud-pair-title');
    if (!modal || !canvas) return;

    if (titleEl) titleEl.textContent = `通道 #${id} 配对二维码生成中...`;
    modal.classList.remove('hidden');
    ui.safeCreateIcons(modal);

    try {
        const resp = await api.adminFetch(`/api/admin/channel/appasst_pair_payload?id=${id}`, { signal });
        const res = await resp.json();
        if (res.code !== 1 || !res.data?.qr_payload) throw new Error(res.msg || '获取配对二维码失败');

        if (titleEl) titleEl.textContent = `通道 #${id} 专属配对二维码`;

        // 渲染二维码
        if (window.qrcodelib?.QRCode) {
            window.qrcodelib.QRCode.toCanvas(canvas, res.data.qr_payload, {
                width: 220,
                margin: 2,
                color: { dark: '#020617', light: '#ffffff' }
            });
        } else if (window.QRCode) {
            canvas.style.display = 'none';
            let imgContainer = root.querySelector('#cloud-pair-qr-container');
            if (!imgContainer) {
                imgContainer = document.createElement('div');
                imgContainer.id = 'cloud-pair-qr-container';
                canvas.parentNode.appendChild(imgContainer);
            }
            imgContainer.innerHTML = '';
            new window.QRCode(imgContainer, {
                text: res.data.qr_payload,
                width: 220,
                height: 220,
            });
        }
    } catch (e) {
        ui.showToast(e.message || '生成配对二维码失败', 'error');
    }
}

function closePairModal(root) {
    root.querySelector('#cloud-appasst-pair-modal')?.classList.add('hidden');
}
