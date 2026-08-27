const pluginRecords = new Map();
let activeOrderNo = null, pollTimer = null, countdownTimer = null;

export const feature = {
    id: 'plugins',
    async mount(context) {
        const { root, ui, signal } = context;

        root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (!target) return;
            const action = target.dataset.action;
            const pluginId = target.dataset.pluginId || '';

            if (action === 'switch-plugin-subtab') switchSubtab(root, target.dataset.subtab);
            if (action === 'filter-plugin-category') {
                const cat = target.dataset.category || 'all';
                currentCategory = cat;
                root.querySelectorAll('.plugin-cat-btn').forEach(btn => {
                    const isCur = (btn.dataset.category || '') === cat;
                    btn.className = isCur
                        ? 'plugin-cat-btn px-4 py-2 rounded-xl bg-slate-900 text-white transition-all shadow-sm flex items-center gap-1.5 cursor-pointer'
                        : 'plugin-cat-btn px-4 py-2 rounded-xl bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 transition-all flex items-center gap-1.5 cursor-pointer shadow-sm';
                });
                renderFilteredCloudPlugins(root, ui);
            }
            if (action === 'refresh-cloud-status') { void loadCloudPlugins(context); ui.showToast('已刷新官方云端插件与授权信息'); }
            if (action === 'toggle-plugin') void togglePlugin(context, pluginId, Number(target.dataset.enabled));
            if (action === 'uninstall-plugin') void uninstallPlugin(context, pluginId);
            if (action === 'rollback-plugin') void rollbackPlugin(context, pluginId);
            if (action === 'open-purchase-guide') {
                const pForever = target.dataset.pluginPriceForever || target.dataset.pluginPrice || '99.00';
                const pMonth = target.dataset.pluginPriceMonth || '29.00';
                void openCashierModal(context, pluginId, target.dataset.pluginName || '官方通道插件', pForever, pMonth, 'forever', 'alipay');
            }
            if (action === 'close-cashier-modal') closeCashierModal(root);
            if (action === 'refresh-pay-status') void refreshPayStatus(context);
            if (action === 'install-cloud-plugin') void installCloudPlugin(context, pluginId);
            if (action === 'copy-tenant-id') {
                const tid = root.querySelector('#display-tenant-id')?.textContent || 'tenant_official_default';
                navigator.clipboard.writeText(tid).then(() => ui.showToast('租户 ID 已复制到剪贴板'));
            }
            if (action === 'open-rebind-dialog') openRebindGuideModal(root);
            if (action === 'open-issue-modal') openAgentIssueModal(root);
            if (action === 'close-issue-modal') closeAgentIssueModal(root);
            if (action === 'refresh-agent-instances') void loadAgentInstances(context);
            if (action === 'revoke-sub-license') {
                const dom = target.dataset.domain || '';
                if (dom) void doRevokeLicense(context, dom);
            }
        }, { signal });

        root.querySelector('#btn-do-issue-license')?.addEventListener('click', () => void doIssueLicense(context), { signal });
        root.querySelector('#btn-copy-issued-key')?.addEventListener('click', () => {
            const keyText = root.querySelector('#res-issued-key')?.textContent?.trim() || '';
            if (keyText) navigator.clipboard.writeText(keyText).then(() => ui.showToast('License Key 已成功复制到剪贴板！'));
        }, { signal });

        root.querySelectorAll('input[name="cashier_pay_type"]').forEach((radio) => {
            radio.addEventListener('change', () => {
                triggerCashierRecreate(context);
            }, { signal });
        });

        root.querySelectorAll('input[name="cashier_period"]').forEach((radio) => {
            radio.addEventListener('change', () => {
                updateCashierPeriodUI(root, radio.value);
                triggerCashierRecreate(context);
            }, { signal });
        });

        ui.safeCreateIcons();

        root.querySelector('#plugin-package-input')?.addEventListener('change', (e) => void installPluginPackage(context, e.target), { signal });

        ui.safeCreateIcons();
        await Promise.allSettled([loadInstalledPlugins(context), loadCloudPlugins(context)]);
    },
    unmount() { clearCashierTimers(); pluginRecords.clear(); },
};

function switchSubtab(root, subtabId) {
    ['cloud-market', 'monitor-software', 'license-mgmt', 'installed-drivers'].forEach(t => {
        const pane = root.querySelector(`#subtab-pane-${t}`), btn = root.querySelector(`#subtab-btn-${t}`);
        if (pane) pane.classList.toggle('hidden', t !== subtabId);
        if (btn) {
            btn.className = t === subtabId
                ? 'px-4 py-2 rounded-xl bg-blue-600 text-white transition-all flex items-center gap-1.5 shadow-sm'
                : 'px-4 py-2 rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all flex items-center gap-1.5';
        }
    });
}

let cachedCloudList = [];
let currentCategory = 'all';

async function loadCloudPlugins({ root, api, ui, signal }) {
    const container = root.querySelector('#cloud-plugin-grid');
    if (!container) return;
    container.innerHTML = '<div class="p-8 text-center text-xs text-slate-400 col-span-full">正在连接云端插件商城并同步实时分类与价格...</div>';
    cachedCloudList = [];

    try {
        const response = await api.adminFetch('/api/admin/plugin/cloud_market', { signal });
        const payload = await response.json();
        if (Array.isArray(payload.data?.list) && payload.data.list.length > 0) {
            cachedCloudList = payload.data.list;
            const statusEl = root.querySelector('#cloud-sync-status');
            const updateCount = cachedCloudList.filter(p => p.installed && p.has_update).length;
            if (statusEl) {
                const activeCount = cachedCloudList.filter(p => p.status !== 'INACTIVE').length;
                statusEl.innerHTML = updateCount > 0
                    ? `<span class="text-amber-600 font-bold animate-pulse">✨ 发现 ${updateCount} 款插件有新版本可更新</span> (共 ${activeCount} 款)`
                    : `已直连云端 (实时在售 ${activeCount} 款官方插件)`;
            }
            const subtabBtnInstalled = root.querySelector('#subtab-btn-installed-drivers');
            if (subtabBtnInstalled) {
                if (updateCount > 0) {
                    subtabBtnInstalled.innerHTML = `<i data-lucide="cpu" class="w-4 h-4 text-indigo-500"></i> 本地已安装驱动 <span class="px-1.5 py-0.5 bg-amber-500 text-white rounded-full text-[10px] font-mono font-bold animate-pulse">${updateCount} 可更新</span>`;
                } else {
                    subtabBtnInstalled.innerHTML = `<i data-lucide="cpu" class="w-4 h-4 text-indigo-500"></i> 本地已安装驱动`;
                }
            }
        }
    } catch (e) {
        console.warn('拉取云端插件失败:', e);
    }

    // 容错兜底：若云端接口未返回列表，自动降级读取本地已安装驱动进行渲染
    if (!cachedCloudList.length) {
        try {
            const res2 = await api.adminFetch('/api/admin/plugin/market_list', { signal });
            const p2 = await res2.json();
            if (p2.code === 1 && Array.isArray(p2.data?.list) && p2.data.list.length > 0) {
                cachedCloudList = p2.data.list.map(p => ({
                    plugin_id: p.plugin_id || p.c_type,
                    c_type: p.c_type,
                    name: p.name || p.title || p.c_type,
                    description: p.description || '本地支付通道驱动',
                    category: p.category || (String(p.c_type).startsWith('wx') ? 'wxpay' : (String(p.c_type).startsWith('ali') ? 'alipay' : (String(p.c_type).startsWith('qq') ? 'qqpay' : 'other'))),
                    latest_version: p.version || '1.0.0',
                    installed: true,
                    enabled: p.enabled !== false,
                    entitled: true,
                    price_text: '已部署·运行中',
                }));
            }
        } catch { /* 容错 */ }
    }

    if (signal.aborted) return;
    renderFilteredCloudPlugins(root, ui);
}

function renderFilteredCloudPlugins(root, ui) {
    const container = root.querySelector('#cloud-plugin-grid');
    if (!container) return;

    const activeList = cachedCloudList.filter(p => p.status !== 'INACTIVE');
    if (!activeList.length) {
        container.innerHTML = '<div class="p-8 text-center text-xs text-slate-400 col-span-full">暂无在售的官方支付插件</div>';
        return;
    }

    // 统计各分类数量
    const countAll = activeList.length;
    const countAli = activeList.filter(p => p.category === 'alipay' || (p.category !== 'all_in_one' && String(p.c_type).startsWith('ali'))).length;
    const countWx  = activeList.filter(p => p.category === 'wxpay' || (p.category !== 'all_in_one' && (String(p.c_type).startsWith('wx') || String(p.c_type).startsWith('wechat')))).length;
    const countAllInOne = activeList.filter(p => p.category === 'all_in_one' || p.c_type === 'app_asst_universal' || p.plugin_id === 'cxpay.app_asst_universal').length;
    const countOther = activeList.filter(p => {
        const cat = p.category || '';
        const ct = String(p.c_type || '');
        return cat !== 'alipay' && cat !== 'wxpay' && cat !== 'all_in_one' && ct !== 'app_asst_universal' && !ct.startsWith('ali') && !ct.startsWith('wx') && !ct.startsWith('wechat');
    }).length;

    const setBadge = (id, val) => { const el = root.querySelector(id); if (el) el.textContent = String(val); };
    setBadge('#cat-count-all', countAll);
    setBadge('#cat-count-alipay', countAli);
    setBadge('#cat-count-wxpay', countWx);
    setBadge('#cat-count-allinone', countAllInOne);
    setBadge('#cat-count-other', countOther);

    // 筛选当前选中的分类
    const filtered = activeList.filter(p => {
        if (currentCategory === 'all') return true;
        const cat = p.category || '';
        const ct = String(p.c_type || '');
        const pid = String(p.plugin_id || '');
        if (currentCategory === 'alipay') return cat === 'alipay' || (cat !== 'all_in_one' && ct.startsWith('ali'));
        if (currentCategory === 'wxpay') return cat === 'wxpay' || (cat !== 'all_in_one' && (ct.startsWith('wx') || ct.startsWith('wechat')));
        if (currentCategory === 'all_in_one') return cat === 'all_in_one' || ct === 'app_asst_universal' || pid === 'cxpay.app_asst_universal';
        if (currentCategory === 'other') {
            return cat !== 'alipay' && cat !== 'wxpay' && cat !== 'all_in_one' && ct !== 'app_asst_universal' && !ct.startsWith('ali') && !ct.startsWith('wx') && !ct.startsWith('wechat');
        }
        return true;
    });

    if (!filtered.length) {
        container.innerHTML = '<div class="p-8 text-center text-xs text-slate-400 col-span-full">该分类下暂无插件</div>';
        return;
    }

    container.innerHTML = filtered.map((p) => {
        const isEntitled = p.entitled === true;
        const isInstalled = p.installed === true;
        const isEnabled = p.enabled !== false;
        const hasUpdate = p.has_update === true;
        const pId = ui.escapeHtml(p.plugin_id || p.c_type || '');
        const pName = ui.escapeHtml(p.name || '官方插件');
        const pDesc = ui.escapeHtml(p.description || '官方正版通道驱动');
        const pVer = ui.escapeHtml(p.latest_version || '1.0.0');
        const pInstalledVer = ui.escapeHtml(p.installed_version || '1.0.0');
        const pForever = Number(p.price_forever || p.price || 99.00).toFixed(2);
        const pMonth = Number(p.price_month || (Number(pForever) > 0 ? Math.min(29.00, Number((Number(pForever) * 0.3).toFixed(2))) : 0.00)).toFixed(2);
        const isFree = p.is_free === true || (Number(pForever) === 0 && Number(pMonth) === 0) || ['alipay_face_pay', 'alipay_cookie_cloud', 'cxpay.driver.alipay_face_pay', 'cxpay.driver.alipay_cookie_cloud'].includes(p.plugin_id || p.c_type);

        const priceText = isFree
            ? '免费内置 · 永久授权'
            : (isEntitled ? '已购买授权 · 商业版' : `月费 ¥${pMonth} / 永久 ¥${pForever}`);

        const isAllInOne = p.category === 'all_in_one' || p.c_type === 'app_asst_universal' || p.plugin_id === 'cxpay.app_asst_universal';
        const isWx = p.category === 'wxpay' || (!isAllInOne && (String(p.c_type).startsWith('wx') || String(p.c_type).startsWith('wechat')));
        const isAli = p.category === 'alipay' || (!isAllInOne && String(p.c_type).startsWith('ali'));
        const isCrypto = String(p.c_type).includes('usdt') || String(p.plugin_id).includes('usdt');

        const catBadge = isAllInOne ? '<span class="px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded text-[10px] font-bold">三合一手机挂机</span>'
            : (isWx ? '<span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-100 rounded text-[10px] font-bold">微信支付</span>'
            : (isAli ? '<span class="px-2 py-0.5 bg-blue-50 text-blue-700 border border-blue-100 rounded text-[10px] font-bold">支付宝</span>'
            : (isCrypto ? '<span class="px-2 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded text-[10px] font-bold">USDT / 链上</span>'
            : '<span class="px-2 py-0.5 bg-slate-100 text-slate-700 border border-slate-200 rounded text-[10px] font-bold">综合扩展</span>')));

        let statusBadge = `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-600 border border-amber-100">🔒 商业增值·未开通</span>`;
        if (isEntitled) {
            if (isInstalled) {
                if (hasUpdate) {
                    statusBadge = `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-300 animate-pulse flex items-center gap-1">✨ 发现新版 v${pVer} (当前: v${pInstalledVer})</span>`;
                } else {
                    statusBadge = `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${isEnabled ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : 'bg-slate-100 text-slate-500'}">● 已部署 (v${pInstalledVer})</span>`;
                }
            } else {
                statusBadge = `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-600 border border-blue-200">🔵 已授权·待部署</span>`;
            }
        }

        let actionBtn = `<button type="button" data-action="open-purchase-guide" data-plugin-id="${pId}" data-plugin-name="${pName}" data-plugin-price-forever="${pForever}" data-plugin-price-month="${pMonth}" class="px-3.5 py-1.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl text-xs font-bold transition-all shadow-md flex items-center gap-1"><i data-lucide="shopping-cart" class="w-3.5 h-3.5"></i> 立即购买开通</button>`;
        if (isEntitled) {
            if (isInstalled) {
                if (hasUpdate) {
                    actionBtn = `<button type="button" data-action="install-cloud-plugin" data-plugin-id="${pId}" class="px-3.5 py-1.5 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white rounded-xl text-xs font-bold transition-all shadow-md flex items-center gap-1 cursor-pointer"><i data-lucide="sparkles" class="w-3.5 h-3.5"></i> 立即更新至 v${pVer}</button>`;
                } else {
                    actionBtn = `<button type="button" data-action="install-cloud-plugin" data-plugin-id="${pId}" class="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-colors shadow-sm flex items-center gap-1 cursor-pointer"><i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-blue-600"></i> 热更新</button>`;
                }
            } else {
                actionBtn = `<button type="button" data-action="install-cloud-plugin" data-plugin-id="${pId}" class="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-colors shadow-sm flex items-center gap-1 cursor-pointer"><i data-lucide="download" class="w-3.5 h-3.5"></i> 一键安装部署</button>`;
            }
        }

        return `<div class="glass-panel p-5 rounded-2xl border ${hasUpdate ? 'border-amber-300 ring-2 ring-amber-400/20' : 'border-slate-200/80'} bg-white flex flex-col justify-between hover:shadow-md transition-all">
            <div>
                <div class="flex items-center justify-between mb-2"><div class="flex items-center gap-1.5">${catBadge}<span class="px-2 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-mono">${pId}</span></div>${statusBadge}</div>
                <h4 class="text-sm font-bold text-slate-800 mt-1.5">${pName}</h4>
                <div class="text-xs font-mono text-slate-400 mt-0.5">最新版本: v${pVer} · 官方正版</div>
                <p class="text-xs text-slate-500 mt-2.5 line-clamp-2 leading-relaxed">${pDesc}</p>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between"><span class="text-[11px] font-bold ${isFree ? 'text-emerald-600' : (isEntitled ? 'text-blue-600' : 'text-amber-600 font-mono')}">${priceText}</span>${actionBtn}</div>
        </div>`;
    }).join('');
    ui.safeCreateIcons();
}

function updateCashierPeriodUI(root, currentPeriod) {
    root.querySelectorAll('.cashier-period-label').forEach(label => {
        const period = label.dataset.period;
        const isSelected = period === currentPeriod;
        if (isSelected) {
            label.className = 'flex flex-col p-3 rounded-2xl border-2 border-indigo-600 bg-indigo-50/50 cursor-pointer transition-all cashier-period-label relative overflow-hidden select-none';
        } else {
            label.className = 'flex flex-col p-3 rounded-2xl border border-slate-200 bg-white hover:bg-slate-50 cursor-pointer transition-all cashier-period-label select-none';
        }
    });
}

function triggerCashierRecreate(context) {
    const { root } = context;
    const pId = root.querySelector('#cashier-plugin-id')?.textContent || '';
    const pName = root.querySelector('#cashier-plugin-name')?.textContent || '';
    const pForever = root.querySelector('#cashier-period-price-forever')?.textContent?.replace('¥', '').trim() || '99.00';
    const pMonth = root.querySelector('#cashier-period-price-month')?.textContent?.replace('¥', '').trim() || '29.00';
    const period = root.querySelector('input[name="cashier_period"]:checked')?.value || 'forever';
    const payType = root.querySelector('input[name="cashier_pay_type"]:checked')?.value || 'alipay';

    if (pId) {
        void openCashierModal(context, pId, pName, pForever, pMonth, period, payType);
    }
}

async function openCashierModal(context, pluginId, pluginName, priceForever = '99.00', priceMonth = '29.00', period = 'forever', payType = 'alipay') {
    const { root, api, ui, signal } = context;
    const modal = root.querySelector('#plugin-cashier-modal');
    if (!modal) return;
    clearCashierTimers();

    const priceF = Number(priceForever).toFixed(2);
    const priceM = Number(priceMonth).toFixed(2);
    const targetPrice = period === 'month' ? priceM : priceF;

    root.querySelector('#cashier-plugin-id').textContent = pluginId;
    root.querySelector('#cashier-plugin-name').textContent = pluginName;
    root.querySelector('#cashier-price').textContent = `¥ ${targetPrice}`;
    root.querySelector('#cashier-domain').textContent = window.location.host || 'cs.fcwan.cn';
    
    // 更新选择卡片里的价格文本
    const elF = root.querySelector('#cashier-period-price-forever');
    if (elF) elF.textContent = `¥ ${priceF}`;
    const elM = root.querySelector('#cashier-period-price-month');
    if (elM) elM.textContent = `¥ ${priceM}`;

    // 联动同步单选框选中状态
    const radioPeriod = root.querySelector(`input[name="cashier_period"][value="${period}"]`);
    if (radioPeriod) radioPeriod.checked = true;
    updateCashierPeriodUI(root, period);

    const radioPay = root.querySelector(`input[name="cashier_pay_type"][value="${payType}"]`);
    if (radioPay) radioPay.checked = true;

    root.querySelector('#cashier-status-hint').innerHTML = '<i data-lucide="loader-2" class="w-3.5 h-3.5 text-blue-600 animate-spin"></i><span>等待扫码付款中，支付后秒级自动开通...</span>';
    modal.classList.remove('hidden');
    ui.safeCreateIcons();

    const qrBox = root.querySelector('#cashier-qrcode-box');
    qrBox.innerHTML = '<div class="text-xs text-slate-400 py-6 flex items-center justify-center gap-1.5"><i data-lucide="loader-2" class="w-4 h-4 animate-spin text-blue-600"></i> 正在生成官方收款码...</div>';
    ui.safeCreateIcons();

    try {
        const response = await api.adminFetch('/api/admin/plugin/order/create', { 
            method: 'POST', 
            body: new URLSearchParams({ plugin_id: pluginId, pay_type: payType, period: period }), 
            signal 
        });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '创建收银订单失败');
        activeOrderNo = payload.data.order_no;
        root.querySelector('#cashier-order-no').textContent = activeOrderNo;
        qrBox.innerHTML = '';
        if (typeof window.QRCode === 'function') {
            new window.QRCode(qrBox, { text: payload.data.qr_code_content, width: 140, height: 140, colorDark: '#0f172a', colorLight: '#ffffff', correctLevel: window.QRCode.CorrectLevel.M });
        } else {
            qrBox.innerHTML = `<div class="font-mono text-[10px] break-all p-2 bg-slate-50 rounded">${payload.data.qr_code_content}</div>`;
        }
        startCountdown(root, payload.data.expire_seconds || 900);
        startPollingOrderStatus(context, activeOrderNo);
    } catch (e) {
        if (e?.name !== 'AbortError') {
            const errMsg = e.message || '出码失败';
            qrBox.innerHTML = `<div class="text-xs text-rose-500 font-bold p-4 text-center leading-relaxed"><p>⚠ ${ui.escapeHtml(errMsg)}</p><p class="text-[11px] text-slate-400 font-normal mt-1.5">可尝试切换支付方式或计费周期</p></div>`;
        }
    }
}

function closeCashierModal(root) { clearCashierTimers(); root.querySelector('#plugin-cashier-modal')?.classList.add('hidden'); }
function clearCashierTimers() { if (pollTimer) clearInterval(pollTimer); if (countdownTimer) clearInterval(countdownTimer); pollTimer = countdownTimer = activeOrderNo = null; }

function startCountdown(root, seconds) {
    let left = seconds;
    const el = root.querySelector('#cashier-countdown');
    if (!el) return;
    countdownTimer = setInterval(() => {
        left--;
        if (left <= 0) { clearInterval(countdownTimer); el.textContent = '00:00 (已过期)'; return; }
        el.textContent = `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
    }, 1000);
}

function startPollingOrderStatus(context, orderNo) {
    const { api } = context;
    pollTimer = setInterval(async () => {
        try {
            const response = await api.adminFetch('/api/admin/plugin/order/status', { method: 'POST', body: new URLSearchParams({ order_no: orderNo }) });
            const payload = await response.json();
            if (payload.code === 1 && payload.data?.paid) handlePaymentSuccess(context);
        } catch { /* 忽略 */ }
    }, 2500);
}

async function refreshPayStatus(context) {
    const { api, ui } = context;
    if (!activeOrderNo) return;
    try {
        const btn = context.root.querySelector('#btn-cashier-refresh-status');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> 查询中...'; ui.safeCreateIcons(); }
        const response = await api.adminFetch('/api/admin/plugin/order/status', { method: 'POST', body: new URLSearchParams({ order_no: activeOrderNo }) });
        const payload = await response.json();
        if (payload.code === 1 && payload.data?.paid) {
            handlePaymentSuccess(context);
        } else {
            ui.showToast('暂未检测到支付，请确认已完成扫码付款后再试', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> 已扫码支付，刷新授权状态'; ui.safeCreateIcons(); }
        }
    } catch (e) { ui.showToast(e.message, 'error'); }
}

function handlePaymentSuccess(context) {
    const { root, ui } = context;
    clearCashierTimers();
    const hint = root.querySelector('#cashier-status-hint');
    if (hint) {
        hint.innerHTML = '<span class="text-emerald-600 font-extrabold text-sm flex items-center justify-center gap-1.5"><i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600"></i> 🎉 支付成功！授权已生效</span>';
        ui.safeCreateIcons();
    }
    ui.showToast('🎉 官方插件购买成功！授权已生效，正在为您刷新...', 'success');
    setTimeout(() => { closeCashierModal(root); void loadCloudPlugins(context); }, 1200);
}

async function installCloudPlugin(context, pluginId) {
    const { api, ui } = context;
    ui.showToast(`正在部署插件【${pluginId}】...`, 'info');
    try {
        const response = await api.adminFetch('/api/admin/plugin/cloud_download', { method: 'POST', body: new URLSearchParams({ plugin_id: pluginId }) });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '部署失败');
        ui.showToast(payload.msg || '插件安装成功！驱动已就绪', 'success');
        await Promise.all([loadInstalledPlugins(context), loadCloudPlugins(context)]);
    } catch (e) { ui.showToast(e.message, 'error'); }
}

function openRebindGuideModal(root) {
    const tid = root.querySelector('#display-tenant-id')?.textContent || 'tenant_official_default';
    const domain = window.location.host || 'cs.fcwan.cn';
    alert(`【申请更换授权域名】\n当前域名：${domain}\n租户 ID：${tid}\n\n换绑说明：部署新域名后联系官方客服，云端一键换绑，所有已购插件和权益自动继承！`);
}

async function loadInstalledPlugins({ root, api, ui, signal }) {
    const list = root.querySelector('#plugin-driver-list');
    if (!list) return;
    try {
        const response = await api.adminFetch('/api/admin/plugin/market_list', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '加载驱动列表失败');
        if (signal.aborted) return;
        const plugins = Array.isArray(payload.data.list) ? payload.data.list : [];
        pluginRecords.clear();
        plugins.forEach((p) => { if (p.plugin_id) pluginRecords.set(p.plugin_id, p); });
        list.innerHTML = plugins.length
            ? plugins.map((p) => renderPlugin(p, ui)).join('')
            : `<div class="p-12 text-center text-slate-400 col-span-full space-y-3 bg-slate-50/50 rounded-3xl border border-dashed border-slate-200">
                <i data-lucide="package-open" class="w-12 h-12 mx-auto text-slate-300"></i>
                <h4 class="text-sm font-bold text-slate-700">暂无已安装的支付通道驱动</h4>
                <p class="text-xs text-slate-400 max-w-md mx-auto leading-relaxed">主站采用纯插件化微内核架构，不设常驻内置驱动。所有支付插件均通过【官方插件市场】按需一键下载与安装。</p>
                <div class="pt-2">
                    <button type="button" data-action="switch-plugin-subtab" data-subtab="cloud-market" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm inline-flex items-center gap-1.5 cursor-pointer">
                        <i data-lucide="cloud-lightning" class="w-4 h-4"></i> 前往官方插件市场挑选安装
                    </button>
                </div>
            </div>`;
        ui.safeCreateIcons();
    } catch (error) {
        if (error?.name !== 'AbortError') list.innerHTML = `<div class="p-5 text-center text-xs text-rose-500 font-bold col-span-full">${ui.escapeHtml(error.message || '加载驱动异常')}</div>`;
    }
}

function renderPlugin(plugin, ui) {
    const enabled = plugin.enabled === true, pluginId = ui.escapeHtml(plugin.plugin_id || '');
    const pVer = ui.escapeHtml(plugin.version || '1.0.0');
    const pLatestVer = ui.escapeHtml(plugin.latest_version || plugin.version || '1.0.0');
    const hasUpdate = plugin.has_update === true;
    const pDesc = ui.escapeHtml(plugin.description || '官方正版支付驱动');

    let actions = '';
    if (plugin.plugin_id) {
        if (hasUpdate) {
            actions = `<div class="flex items-center gap-2 mt-4 pt-3 border-t border-slate-100">
                <button type="button" data-action="install-cloud-plugin" data-plugin-id="${pluginId}" class="flex-1 py-1.5 text-xs font-bold rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white transition-all shadow-sm flex items-center justify-center gap-1 cursor-pointer"><i data-lucide="sparkles" class="w-3.5 h-3.5"></i> 立即升级至 v${pLatestVer}</button>
                <button type="button" data-action="toggle-plugin" data-plugin-id="${pluginId}" data-enabled="${enabled ? 0 : 1}" class="px-3 py-1.5 text-xs font-bold rounded-xl ${enabled ? 'bg-slate-100 hover:bg-slate-200 text-slate-700' : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700'} transition-colors">${enabled ? '⏸ 停用' : '▶ 启用'}</button>
                <button type="button" data-action="uninstall-plugin" data-plugin-id="${pluginId}" class="px-3 py-1.5 text-xs font-bold rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 transition-colors">卸载</button>
            </div>`;
        } else {
            actions = `<div class="flex items-center gap-2 mt-4 pt-3 border-t border-slate-100">
                <button type="button" data-action="toggle-plugin" data-plugin-id="${pluginId}" data-enabled="${enabled ? 0 : 1}" class="flex-1 py-1.5 text-xs font-bold rounded-xl ${enabled ? 'bg-slate-100 hover:bg-slate-200 text-slate-700' : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700'} transition-colors">${enabled ? '⏸ 停用驱动' : '▶ 启用驱动'}</button>
                <button type="button" data-action="uninstall-plugin" data-plugin-id="${pluginId}" class="px-3.5 py-1.5 text-xs font-bold rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 transition-colors">卸载</button>
            </div>`;
        }
    }

    return `<div class="glass-panel p-5 rounded-2xl border ${hasUpdate ? 'border-amber-300 ring-2 ring-amber-400/20' : 'border-slate-200/80'} bg-white flex flex-col justify-between hover:shadow-md transition-all">
        <div>
            <div class="flex items-center justify-between">
                ${hasUpdate 
                    ? `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-300 animate-pulse flex items-center gap-1"><i data-lucide="sparkles" class="w-3 h-3 text-amber-500"></i> 云端新版 v${pLatestVer} 可更新</span>`
                    : `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-100">官方正版插件</span>`}
                <span class="text-xs font-mono font-bold ${enabled ? 'text-emerald-600' : 'text-slate-400'}">${enabled ? '● 运行中' : '○ 已停用'}</span>
            </div>
            <h4 class="text-sm font-bold text-slate-800 mt-2">${ui.escapeHtml(plugin.name || plugin.c_type)}</h4>
            <div class="text-xs text-slate-400 font-mono mt-0.5 flex items-center justify-between">
                <span>驱动标识: ${ui.escapeHtml(plugin.c_type || plugin.plugin_id || '-')}</span>
                <span>${hasUpdate ? `<span class="text-slate-400 line-through mr-1">v${pVer}</span><strong class="text-amber-600 font-bold">v${pLatestVer}</strong>` : `v${pVer}`}</span>
            </div>
            <p class="text-xs text-slate-500 mt-2.5 leading-relaxed line-clamp-2">${pDesc}</p>
        </div>
        ${actions}
    </div>`;
}

async function togglePlugin(context, pluginId, enabled) {
    const { api, ui } = context;
    try {
        const response = await api.adminFetch('/api/admin/plugin/set_enabled', { method: 'POST', body: new URLSearchParams({ plugin_id: pluginId, enabled: String(enabled) }) });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '操作失败');
        ui.showToast(payload.msg || '操作成功');
        await loadInstalledPlugins(context);
    } catch (error) { ui.showToast(error.message, 'error'); }
}

async function uninstallPlugin(context, pluginId) {
    const { api, ui } = context;
    if (!await ui.showConfirm('卸载插件确认', '确定要卸载该支付插件吗？', true)) return;
    try {
        const response = await api.adminFetch('/api/admin/plugin/uninstall', { method: 'POST', body: new URLSearchParams({ plugin_id: pluginId }) });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '卸载失败');
        ui.showToast('插件已成功卸载');
        await loadInstalledPlugins(context);
    } catch (error) { ui.showToast(error.message, 'error'); }
}

async function rollbackPlugin(context, pluginId) {
    const { api, ui } = context;
    const version = prompt('请输入要回滚到的目标版本号（如 1.0.0）：');
    if (!version) return;
    try {
        const response = await api.adminFetch('/api/admin/plugin/rollback', { method: 'POST', body: new URLSearchParams({ plugin_id: pluginId, version }) });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '回滚失败');
        ui.showToast(payload.msg || '回滚成功');
        await loadInstalledPlugins(context);
    } catch (error) { ui.showToast(error.message, 'error'); }
}

async function installPluginPackage(context, input) {
    const { api, ui } = context;
    const file = input.files?.[0];
    if (!file) return;
    const formData = new FormData();
    formData.append('package', file);
    try {
        ui.showToast('正在上传并校验签名包...', 'info');
        const response = await api.adminFetch('/api/admin/plugin/install', { method: 'POST', body: formData });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '安装失败');
        ui.showToast(payload.msg || '插件安装成功！');
        input.value = '';
        await loadInstalledPlugins(context);
    } catch (error) { ui.showToast(error.message, 'error'); input.value = ''; }
}

// ─────────────────────────────────────────────────────────────
// OEM 代理加盟中心专属数据交互与下发处理
// ─────────────────────────────────────────────────────────────

function openAgentIssueModal(root) {
    const modal = root.querySelector('#agent-issue-modal');
    if (!modal) return;
    root.querySelector('#issue-form-step')?.classList.remove('hidden');
    root.querySelector('#issue-result-step')?.classList.add('hidden');
    const domInput = root.querySelector('#issue-client-domain');
    if (domInput) domInput.value = '';
    const nameInput = root.querySelector('#issue-client-name');
    if (nameInput) nameInput.value = '';
    modal.classList.remove('hidden');
    domInput?.focus();
}

function closeAgentIssueModal(root) {
    root.querySelector('#agent-issue-modal')?.classList.add('hidden');
}

async function loadAgentHub(context) {
    const { root, ui } = context;
    try {
        const res = await fetch('/api/admin/agent/profile').then(r => r.json());
        if (res.code === 1 && res.data) {
            const d = res.data;
            const tenantNameEl = root.querySelector('#agent-tenant-name');
            if (tenantNameEl) tenantNameEl.textContent = d.tenant_name || '代理加盟商';
            
            const usedEl = root.querySelector('#agent-used-instances');
            if (usedEl) usedEl.textContent = d.used_instances ?? 0;

            const maxEl = root.querySelector('#agent-max-instances');
            if (maxEl) maxEl.textContent = d.max_instances ?? 20;

            const remainEl = root.querySelector('#agent-remaining-quota');
            if (remainEl) remainEl.textContent = (d.remaining_quota ?? 0) + ' 站点';

            const discountEl = root.querySelector('#agent-discount-text');
            if (discountEl) discountEl.textContent = d.plugin_discount || '4.0 折 (专享底价)';

            const pct = Math.min(100, Math.round(((d.used_instances || 0) / (d.max_instances || 20)) * 100));
            const bar = root.querySelector('#agent-quota-bar');
            if (bar) bar.style.width = pct + '%';
        }
    } catch (e) {
        console.warn('获取代理商资质失败', e);
    }

    await loadAgentInstances(context);
}

async function loadAgentInstances(context) {
    const { root, ui } = context;
    const tbody = root.querySelector('#agent-instances-tbody');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">正在检索名下客户站点列表...</td></tr>`;

    try {
        const res = await fetch('/api/admin/agent/sub_instances').then(r => r.json());
        if (res.code === 1 && res.data && res.data.list) {
            const list = res.data.list;
            if (list.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">暂无已下发的客户子站点，点击上方按钮即可快速下发！</td></tr>`;
                return;
            }

            tbody.innerHTML = list.map(item => `
                <tr class="hover:bg-slate-50/80 transition-colors">
                    <td class="px-4 py-3 font-mono font-bold text-slate-900">${item.domain}</td>
                    <td class="px-4 py-3 font-mono text-emerald-700 font-semibold select-all">${item.masked_key || item.license_key}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-mono text-[10px]">v${item.product_version || '2.1.0'}</span>
                        <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-500 text-[10px] ml-1">框架授权</span>
                    </td>
                    <td class="px-4 py-3">
                        ${item.status === 'ACTIVE' 
                            ? '<span class="inline-flex items-center gap-1 text-emerald-600 font-bold"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> 运行中 (已激活)</span>' 
                            : '<span class="text-rose-500 font-bold">已冻结</span>'}
                    </td>
                    <td class="px-4 py-3 text-slate-400 font-mono text-[11px]">${item.activated_at ? item.activated_at.split('.')[0] : '-'}</td>
                    <td class="px-4 py-3 text-right">
                        ${item.status === 'ACTIVE' ? `
                            <button type="button" data-action="revoke-sub-license" data-domain="${item.domain}" class="px-2.5 py-1 text-rose-600 hover:bg-rose-50 rounded font-bold text-[11px] transition-colors">
                                冻结授权
                            </button>
                        ` : '<span class="text-slate-400 text-[11px]">已停用</span>'}
                    </td>
                </tr>
            `).join('');
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-rose-400">加载客户站点列表异常：${e.message}</td></tr>`;
    }
}

async function doIssueLicense(context) {
    const { root, ui } = context;
    const domainInput = root.querySelector('#issue-client-domain');
    const nameInput = root.querySelector('#issue-client-name');
    const domain = domainInput?.value?.trim() || '';
    const name = nameInput?.value?.trim() || '';

    if (!domain) {
        ui.showToast('请输入下级客户待授权绑定的主站域名', 'error');
        domainInput?.focus();
        return;
    }

    const btn = root.querySelector('#btn-do-issue-license');
    if (btn) { btn.disabled = true; btn.innerText = '正在生成并开通云端授权...'; }

    try {
        const res = await fetch('/api/admin/agent/license/issue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ client_domain: domain, client_name: name })
        }).then(r => r.json());

        if (res.code !== 1) {
            throw new Error(res.msg || '下发授权失败');
        }

        const d = res.data;
        root.querySelector('#issue-form-step')?.classList.add('hidden');
        root.querySelector('#issue-result-step')?.classList.remove('hidden');

        root.querySelector('#res-issued-key').textContent = d.license_key;
        root.querySelector('#res-issued-domain').textContent = d.client_domain;
        root.querySelector('#res-issued-wm').textContent = d.watermark_id;

        ui.showToast('🎉 客户主站商业授权下发成功！');
        void loadAgentHub(context);
    } catch (e) {
        ui.showToast(e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = `<i data-lucide="check-circle-2" class="w-4 h-4"></i> 确认生成并下发`; ui.safeCreateIcons(); }
    }
}

async function doRevokeLicense(context, domain) {
    const { ui } = context;
    if (!confirm(`确定要冻结客户站点 [${domain}] 的商业授权吗？冻结后该站点将无法收单。`)) return;

    try {
        const res = await fetch('/api/admin/agent/license/revoke', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ domain: domain })
        }).then(r => r.json());

        if (res.code !== 1) throw new Error(res.msg || '冻结失败');
        ui.showToast(res.msg || '已冻结该子站点授权');
        void loadAgentHub(context);
    } catch (e) {
        ui.showToast(e.message, 'error');
    }
}

