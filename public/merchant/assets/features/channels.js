import { createChannelEditor } from './channel-editor.js?v=20260822_v55_fresh_all';
import { createChannelAuthorization } from './channel-authorization.js?v=20260822_v55_fresh_all';
import { createChannelAppAsst } from './channel-appasst.js?v=20260822_v55_fresh_all';

let state = null;

export const feature = {
    id: 'channel-list',
    async mount(context) {
        const { root, api, ui, signal } = context;
        const channels = new Map();
        const authorization = createChannelAuthorization({ root, api, ui, signal });
        const appasst = createChannelAppAsst({ root, channels, ui });
        const editor = createChannelEditor({
            root,
            api,
            ui,
            signal,
            reload: load,
            navigate: (id) => window.CXMerchant.navigate(id),
            authorization,
            appasst,
        });

        async function load() {
            const container = root.querySelector('#channel-card-container');
            if (!container) return;
            try {
                const response = await api.merchantFetch('/api/merchant/channel/list', { signal });
                const payload = await response.json();
                if (payload.code !== 1 || !Array.isArray(payload.data)) {
                    throw new Error(payload.msg || '通道列表加载失败');
                }
                channels.clear();
                payload.data.forEach((item) => channels.set(String(item.id), item));
                renderChannels(container, payload.data, ui);
                ui.safeCreateIcons(root);
            } catch (error) {
                if (error?.name === 'AbortError') return;
                container.innerHTML = `<div class="glass-card rounded-2xl p-8 text-center text-rose-500 font-bold">${ui.escapeHtml(error.message || '通道列表加载失败，请刷新页面重试')}</div>`;
            }
        }

        async function ensurePlan() {
            try {
                const response = await api.merchantFetch('/api/merchant/plan/list', { signal });
                const payload = await response.json();
                if (payload.code !== 1 || !payload.data) return true;
                const planId = Number(payload.data.current_plan_id || 0);
                const expiresAt = Number(payload.data.plan_expire_time || 0);
                if (planId > 0 && (!expiresAt || expiresAt >= Date.now() / 1000)) return true;
                const confirmed = await ui.showConfirm(
                    '需先领取或订阅套餐',
                    expiresAt && expiresAt < Date.now() / 1000
                        ? '您的套餐已到期，请先续费或更换套餐后再配置收款通道。'
                        : '当前账号尚未开通收款套餐，请先领取试用套餐或订阅套餐。',
                    false
                );
                if (confirmed) window.CXMerchant.navigate('plan-buy');
                return false;
            } catch (error) {
                if (error?.name !== 'AbortError') ui.showToast('套餐状态校验失败，将由保存接口继续校验', 'warning');
                return error?.name !== 'AbortError';
            }
        }

        const onClick = async (event) => {
            const trigger = event.target.closest('[data-action]');
            if (!trigger || !root.contains(trigger)) return;
            const action = trigger.dataset.action;
            const id = trigger.dataset.channelId;
            if (action === 'open-channel-editor') {
                if (await ensurePlan()) await editor.openNew();
            } else if (action === 'close-channel-editor') {
                editor.close();
            } else if (action === 'save-channel') {
                await editor.submit();
            } else if (action === 'choose-qr-image') {
                root.querySelector('#channel-qr-file')?.click();
            } else if (action === 'open-appasst-modal') {
                appasst.open();
            } else if (action === 'close-appasst-modal') {
                appasst.close();
            } else if (action === 'regen-appasst-secret') {
                appasst.regenerateSecret();
            } else if (action === 'edit-channel') {
                await editor.open(channels.get(String(id)));
            } else if (action === 'test-channel') {
                void openTestChannelModal(context, id || channels.get(String(id)), trigger);
            } else if (action === 'close-test-modal') {
                closeTestChannelModal(root);
            } else if (action === 'toggle-channel') {
                await mutate('/api/merchant/channel/toggle', { id, status: trigger.dataset.status });
            } else if (action === 'delete-channel') {
                if (await ui.showConfirm('删除收款通道', '确定要删除此收款通道吗？', true)) {
                    await mutate('/api/merchant/channel/delete', { id }, '通道已成功删除');
                }
            } else if (action === 'authorize-channel') {
                if (await authorization.start(id, platformName(channels.get(String(id))))) await load();
            } else if (action === 'detect-channel') {
                await authorization.detectCapabilities(id);
            } else if (action === 'configure-bill-source') {
                await authorization.configureBillSource(id);
            } else if (action === 'copy-wecom-url') {
                const webhookUrl = `${window.location.origin}/api/wecom/webhook/${id}`;
                if (navigator.clipboard?.writeText) {
                    navigator.clipboard.writeText(webhookUrl);
                }
                ui.showToast(`Webhook 接收地址已复制：${webhookUrl}`, 'success');
            }
        };

        const onChange = async (event) => {
            const action = event.target.dataset.action;
            if (action === 'change-category') await editor.changeCategory();
            if (action === 'change-driver') editor.changeDriver();
            if (action === 'upload-qr') {
                await editor.uploadQr(event.target.files?.[0]);
                event.target.value = '';
            }
        };

        async function mutate(url, values, successMessage = '') {
            try {
                const response = await api.merchantFetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(values),
                    signal,
                });
                const payload = await response.json();
                if (payload.code !== 1) throw new Error(payload.msg || '操作失败');
                if (successMessage) ui.showToast(successMessage);
                await load();
            } catch (error) {
                if (error?.name !== 'AbortError') ui.showToast(error.message || '通道操作失败', 'error');
            }
        }

        root.addEventListener('click', onClick);
        root.addEventListener('change', onChange);
        state = { root, onClick, onChange, editor, authorization, channels };
        await load();
    },
    unmount() {
        if (liveTicker) clearInterval(liveTicker);
        liveTicker = null;
        if (!state) return;
        clearTestTimers();
        state.root.removeEventListener('click', state.onClick);
        state.root.removeEventListener('change', state.onChange);
        state.editor.dispose();
        state.authorization.dispose();
        state.channels.clear();
        state = null;
    },
};

let testPollTimer = null, testCountdownTimer = null;

function clearTestTimers() {
    if (testPollTimer) clearInterval(testPollTimer);
    if (testCountdownTimer) clearInterval(testCountdownTimer);
    testPollTimer = testCountdownTimer = null;
}

function getOrCreateTestModal(root) {
    return root.querySelector('#channel-test-modal') || document.getElementById('channel-test-modal');
}

async function openTestChannelModal(context, channelOrId, triggerEl = null) {
    const { root, api, ui, signal } = context;
    const channelId = typeof channelOrId === 'object' ? channelOrId?.id : channelOrId;
    if (!channelId) {
        ui.showToast('未获取到收款通道 ID', 'error');
        return;
    }
    const channel = typeof channelOrId === 'object' ? channelOrId : state?.channels?.get(String(channelId));
    const modal = getOrCreateTestModal(root);
    clearTestTimers();

    const titleEl = modal.querySelector('#test-channel-title');
    const orderNoEl = modal.querySelector('#test-order-no');
    const moneyEl = modal.querySelector('#test-order-money');
    const qrBox = modal.querySelector('#test-qrcode-box');
    const statusHint = modal.querySelector('#test-status-hint');
    const jumpBox = modal.querySelector('#test-jump-url-box');
    const customMoneyInput = modal.querySelector('#test-custom-money-input');
    const applyMoneyBtn = modal.querySelector('#btn-apply-custom-money');
    const presetsContainer = modal.querySelector('#test-money-presets');

    if (titleEl) titleEl.textContent = channel?.title || channel?.c_type || '收款通道';
    modal.classList.remove('hidden');
    ui.safeCreateIcons();

    let currentMoney = '0.01';

    async function loadTestOrder(moneyToTest) {
        clearTestTimers();
        currentMoney = Number(moneyToTest).toFixed(2);
        if (moneyEl) moneyEl.textContent = `¥ ${currentMoney}`;
        if (orderNoEl) orderNoEl.textContent = '正在连接网关创建测试订单...';
        if (qrBox) qrBox.innerHTML = '<div class="text-xs text-blue-600 font-bold py-6 flex items-center gap-2"><i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> 正在调起官方网关出码...</div>';
        if (statusHint) statusHint.innerHTML = '<i data-lucide="loader-2" class="w-3.5 h-3.5 text-blue-600 animate-spin"></i><span>正在请求实时出码...</span>';
        jumpBox?.classList.add('hidden');
        ui.safeCreateIcons();

        // 更新快捷按钮样式
        presetsContainer?.querySelectorAll('.test-preset-btn').forEach(btn => {
            if (btn.dataset.money === currentMoney) {
                btn.className = 'test-preset-btn px-2.5 py-1 rounded-lg border border-blue-500 bg-blue-50 text-blue-700 font-bold text-[11px] transition-all';
            } else {
                btn.className = 'test-preset-btn px-2.5 py-1 rounded-lg border border-slate-200 bg-white text-slate-600 hover:border-slate-300 font-bold text-[11px] transition-all';
            }
        });

        try {
            const response = await api.merchantFetch('/api/merchant/channel/test', {
                method: 'POST',
                body: new URLSearchParams({ channel_id: String(channelId), money: currentMoney }),
                signal,
            });
            const payload = await response.json();
            if (payload.code !== 1 || !payload.data) {
                throw new Error(payload.msg || '通道测试出码失败');
            }

            const { trade_no, pay_url, pay_type, expire_time } = payload.data;
            if (orderNoEl) orderNoEl.textContent = `订单号: ${trade_no}`;
            if (statusHint) statusHint.innerHTML = '<i data-lucide="loader-2" class="w-3.5 h-3.5 text-blue-600 animate-spin"></i><span>等待手机扫码支付中，支付后秒级自动核销...</span>';
            if (qrBox) qrBox.innerHTML = '';

            if (pay_url) {
                if (pay_url.startsWith('data:image') || pay_url.startsWith('blob:') || /\.(png|jpg|jpeg|gif|webp)$/i.test(pay_url)) {
                    qrBox.innerHTML = `<img src="${pay_url}" alt="收款码" class="w-60 h-60 max-w-full object-contain rounded-2xl shadow-sm border border-slate-100 mx-auto" />`;
                } else if (typeof window.QRCode === 'function') {
                    new window.QRCode(qrBox, { text: pay_url, width: 190, height: 190, colorDark: '#0f172a', colorLight: '#ffffff', correctLevel: window.QRCode.CorrectLevel.M });
                } else {
                    qrBox.innerHTML = `<div class="font-mono text-[10px] break-all p-2 bg-slate-50 rounded">${ui.escapeHtml(pay_url)}</div>`;
                }
                if (pay_type === 'jump' || (pay_url.startsWith('http') && !pay_url.startsWith('data:image'))) {
                    const jumpLink = modal.querySelector('#test-jump-url-link');
                    if (jumpBox && jumpLink) {
                        jumpLink.href = pay_url;
                        jumpBox.classList.remove('hidden');
                    }
                }
            } else {
                if (qrBox) qrBox.innerHTML = '<div class="text-xs text-emerald-600 font-bold py-4">通道已调起，请使用绑定设备完成入账测试</div>';
            }

            startTestCountdown(modal, expire_time || 600);
            startTestPolling(context, trade_no, modal);
            ui.safeCreateIcons();
        } catch (e) {
            if (e?.name !== 'AbortError') {
                if (qrBox) qrBox.innerHTML = `<div class="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-600 font-bold text-center leading-relaxed">${ui.escapeHtml(e.message || '出码异常')}</div>`;
                if (statusHint) statusHint.innerHTML = '<span class="text-rose-500 font-bold text-xs">出码未完成，请检查通道配置</span>';
                ui.safeCreateIcons();
            }
        }
    }

    // 绑定快捷金额点击
    if (presetsContainer) {
        presetsContainer.onclick = (e) => {
            const btn = e.target.closest('.test-preset-btn');
            if (btn && btn.dataset.money) {
                if (customMoneyInput) customMoneyInput.value = '';
                void loadTestOrder(btn.dataset.money);
            }
        };
    }

    // 绑定自定义金额提交
    if (applyMoneyBtn) {
        applyMoneyBtn.onclick = () => {
            const val = parseFloat(customMoneyInput?.value || '0');
            if (isNaN(val) || val <= 0) {
                ui.showToast('请输入有效的测试金额（大于0元）', 'error');
                return;
            }
            void loadTestOrder(val);
        };
    }

    // 默认以 0.01 元发起测试
    await loadTestOrder('0.01');
}

function closeTestChannelModal(root) {
    clearTestTimers();
    (root.querySelector('#channel-test-modal') || document.getElementById('channel-test-modal'))?.classList.add('hidden');
}

function startTestCountdown(modal, seconds) {
    let left = seconds;
    const el = modal.querySelector('#test-countdown');
    if (!el) return;
    testCountdownTimer = setInterval(() => {
        left--;
        if (left <= 0) { clearInterval(testCountdownTimer); el.textContent = '00:00 (已过期)'; return; }
        el.textContent = `${String(Math.floor(left / 60)).padStart(2, '0')}:${String(left % 60).padStart(2, '0')}`;
    }, 1000);
}

function startTestPolling(context, tradeNo, modal) {
    const { api, ui } = context;
    testPollTimer = setInterval(async () => {
        try {
            const response = await api.merchantFetch('/api/merchant/channel/test_status', {
                method: 'POST',
                body: new URLSearchParams({ trade_no: tradeNo }),
            });
            const payload = await response.json();
            if (payload.code === 1 && payload.data?.paid) {
                clearTestTimers();
                const hint = modal.querySelector('#test-status-hint');
                if (hint) {
                    hint.innerHTML = '<span class="text-emerald-600 font-extrabold text-sm flex items-center justify-center gap-1.5"><i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600"></i> 🎉 恭喜！连通性测试成功，通道运行正常！</span>';
                    ui.safeCreateIcons();
                }
                ui.showToast('🎉 通道连通性测试成功！订单已核销', 'success');
            }
        } catch { /* 容错 */ }
    }, 2500);
}

let liveTicker = null;

function renderChannels(container, items, ui) {
    if (!items.length) {
        container.innerHTML = '<div class="glass-card rounded-2xl p-8 text-center text-slate-500">尚未配置支付通道，请点击“添加通道”开始配置。</div>';
        return;
    }
    container.innerHTML = items.map((item) => renderChannel(item, ui)).join('');
    startLiveTicker(container);
}

function startLiveTicker(container) {
    if (liveTicker) clearInterval(liveTicker);
    liveTicker = setInterval(() => {
        const badges = container.querySelectorAll('[data-live-duration]');
        badges.forEach((el) => {
            let cur = Number(el.dataset.liveDuration || 0);
            cur += 1;
            el.dataset.liveDuration = String(cur);
            el.textContent = `⏱ 已在线 ${formatDuration(cur)}`;
        });
    }, 1000);
}

function renderChannel(item, ui) {
    const isWx = item.pay_category === 'wxpay' || String(item.c_type || '').includes('wx');
    const isAli = item.pay_category === 'alipay' || String(item.c_type || '').includes('ali');
    const enabled = Number(item.status) === 1;
    const online = Number(item.online_status) === 1;
    const icon = isWx ? '微' : (isAli ? '支' : 'QQ');
    const iconClass = isWx ? 'bg-emerald-100 text-emerald-600' : (isAli ? 'bg-blue-100 text-blue-600' : 'bg-purple-100 text-purple-600');
    const qrUrl = item.qr_url || (typeof item.config === 'object' ? item.config?.qr_url : '');
    const id = ui.escapeHtml(item.id);
    const onlineDuration = Number(item.online_duration || 0);
    const lastDuration = Number(item.last_online_duration || 0);
    const onlineSinceStr = item.online_since_format || '';

    let durationTag = '';
    if (online) {
        durationTag = `<span data-live-duration="${onlineDuration}" class="px-2.5 py-0.5 bg-emerald-50 text-emerald-700 rounded-full text-[11px] font-bold border border-emerald-200 shadow-2xs transition-all" title="上线时间: ${onlineSinceStr}">⏱ 已在线 ${formatDuration(onlineDuration)}</span>`;
    } else {
        if (lastDuration > 0) {
            durationTag = `<span class="px-2.5 py-0.5 bg-amber-50 text-amber-700 rounded-full text-[11px] font-bold border border-amber-200 shadow-2xs" title="通道掉线前累计运行总时长">⏱ 曾在线 ${formatDuration(lastDuration)}</span>`;
        }
    }

    return `
        <div class="glass-card rounded-2xl p-6 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2"><span class="w-7 h-7 rounded-lg ${iconClass} flex items-center justify-center font-bold text-xs">${icon}</span><span class="font-extrabold text-sm text-slate-800">${ui.escapeHtml(item.title)}</span></div>
                <div class="flex items-center gap-2">
                    ${durationTag}
                    <span class="px-2.5 py-0.5 rounded-full ${online ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'} text-[11px] font-bold">${online ? '● 在线运行中' : '○ 离线未连接'}</span>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-2.5 text-xs">
                <div><span class="text-slate-400">收款开关:</span> <span class="font-bold ${enabled ? 'text-emerald-600' : 'text-slate-400'} ml-1">${enabled ? '已开启' : '已停用'}</span></div>
                <div><span class="text-slate-400">单日限额:</span> <span class="font-bold text-slate-700 ml-1">不限 / 不限</span></div>
                <div><span class="text-slate-400">今日收款:</span> <span class="font-bold text-slate-800 ml-1">¥ ${money(item.today_money)} (${Number(item.today_count || 0)}笔)</span></div>
                <div><span class="text-slate-400">累计收款:</span> <span class="font-bold text-slate-800 ml-1">¥ ${money(item.total_money)}</span></div>
            </div>
            ${qrUrl ? `<div class="text-xs text-slate-500 font-mono bg-slate-50 p-2 rounded-lg truncate">📷 收款码: ${ui.escapeHtml(qrUrl)}</div>` : ''}
            <div class="text-xs text-slate-400">备注: <span class="font-mono text-slate-600 font-bold">${ui.escapeHtml(item.remark || '无备注')}</span></div>
            <div class="pt-3 border-t border-slate-100 flex items-center gap-3 text-xs font-bold">
                <button data-action="toggle-channel" data-channel-id="${id}" data-status="${enabled ? 0 : 1}" class="${enabled ? 'text-amber-600' : 'text-emerald-600'} hover:underline">${enabled ? '禁用通道' : '开启通道'}</button>
                <button data-action="edit-channel" data-channel-id="${id}" class="text-slate-600 hover:underline">编辑</button>
                <button data-action="test-channel" data-channel-id="${id}" class="px-2.5 py-1 bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white rounded-lg transition-all flex items-center gap-1 font-bold">⚡ 发起测试</button>
                ${supportsBillSource(item) ? `<button data-action="configure-bill-source" data-channel-id="${id}" class="text-blue-600 hover:underline">账单源令牌</button>` : ''}
                ${item.supports_account_capability_detection ? `<button data-action="detect-channel" data-channel-id="${id}" class="text-violet-600 hover:underline">检测收款能力</button>` : ''}
                ${item.supports_account_authorization ? `<button data-action="authorize-channel" data-channel-id="${id}" class="text-emerald-600 hover:text-emerald-800 hover:underline flex items-center gap-1 font-bold cursor-pointer">🔑 ${ui.escapeHtml(item.authorization_label || '扫码登录支付宝获取Cookie')}</button>` : ''}
                ${item.c_type === 'wechat_wecom_app' ? `<button data-action="copy-wecom-url" data-channel-id="${id}" class="text-indigo-600 hover:text-indigo-800 hover:underline flex items-center gap-1 font-bold">🔗 复制 Webhook 地址</button>` : ''}
                <button data-action="delete-channel" data-channel-id="${id}" class="text-rose-600 hover:underline ml-auto">删除</button>
            </div>
        </div>`;
}

function supportsBillSource(item) {
    return String(item.c_type || '').includes('_app_asst') || item.c_type === 'wxpay_recpt_afk_pc';
}

function platformName(item = {}) {
    if (item.pay_category === 'wxpay') return '微信';
    if (item.pay_category === 'alipay') return '支付宝';
    return '账号';
}

function money(value) {
    return (Number.parseFloat(value) || 0).toFixed(2);
}

function formatDuration(seconds) {
    const sec = Math.max(0, Math.floor(Number(seconds) || 0));
    if (sec <= 0) return '刚刚上线';
    const d = Math.floor(sec / 86400);
    const h = Math.floor((sec % 86400) / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;

    if (d > 0) return `${d}天${h}小时`;
    if (h > 0) return `${h}小时${m}分`;
    if (m > 0) return `${m}分${s}秒`;
    return `${s}秒`;
}
