import { assetUrl } from '../version.js';

const [{ createChannelEditor }, { createChannelAuthorization }] = await Promise.all([
    import(assetUrl('/merchant/assets/features/channel-editor.js')),
    import(assetUrl('/merchant/assets/features/channel-authorization.js')),
]);

let state = null;

export const feature = {
    id: 'channel-list',
    async mount(context) {
        const { root, api, ui, signal } = context;
        const channels = new Map();
        const authorization = createChannelAuthorization({ root, api, ui, signal });
        const editor = createChannelEditor({
            root,
            api,
            ui,
            signal,
            reload: load,
            navigate: (id) => window.CXMerchant.navigate(id),
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
            } else if (action === 'edit-channel') {
                await editor.open(channels.get(String(id)));
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
        if (!state) return;
        state.root.removeEventListener('click', state.onClick);
        state.root.removeEventListener('change', state.onChange);
        state.editor.dispose();
        state.authorization.dispose();
        state.channels.clear();
        state = null;
    },
};

function renderChannels(container, items, ui) {
    if (!items.length) {
        container.innerHTML = '<div class="glass-card rounded-2xl p-8 text-center text-slate-500">尚未配置支付通道，请点击“添加通道”开始配置。</div>';
        return;
    }
    container.innerHTML = items.map((item) => renderChannel(item, ui)).join('');
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
    return `
        <div class="glass-card rounded-2xl p-6 space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <div class="flex items-center gap-2"><span class="w-7 h-7 rounded-lg ${iconClass} flex items-center justify-center font-bold text-xs">${icon}</span><span class="font-extrabold text-sm text-slate-800">${ui.escapeHtml(item.title)}</span></div>
                <div class="flex items-center gap-2"><span class="px-2 py-0.5 bg-blue-50 text-blue-600 rounded-full text-[10px] font-bold border border-blue-100">🛡️ 防掉线保活</span><span class="px-2.5 py-0.5 rounded-full ${online ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'} text-[11px] font-bold">${online ? '● 在线运行中' : '○ 离线未连接'}</span></div>
            </div>
            <div class="grid grid-cols-2 gap-4 text-xs">
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
                ${supportsBillSource(item) ? `<button data-action="configure-bill-source" data-channel-id="${id}" class="text-blue-600 hover:underline">账单源令牌</button>` : ''}
                ${item.supports_account_authorization ? `<button data-action="authorize-channel" data-channel-id="${id}" class="text-blue-600 hover:underline">${ui.escapeHtml(item.authorization_label || '扫码授权')}</button>` : ''}
                ${item.supports_account_capability_detection ? `<button data-action="detect-channel" data-channel-id="${id}" class="text-violet-600 hover:underline">检测收款能力</button>` : ''}
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
