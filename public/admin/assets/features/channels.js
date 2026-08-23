import { createChannelEditor } from '/merchant/assets/features/channel-editor.js?v=20260822_v55_fresh_all';
import { createChannelAuthorization } from '/merchant/assets/features/channel-authorization.js?v=20260822_v55_fresh_all';
import { createChannelAppAsst } from '/merchant/assets/features/channel-appasst.js?v=20260822_v55_fresh_all';

let activeEditor = null;
let activeAuthorization = null;
let activeAppAsst = null;
const adminChannelsMap = new Map();

export const feature = {
    id: 'channels',

    async mount(context) {
        const { root, api, ui, signal } = context;

        // 统一适配层：将商户端的 API 调用直接桥接到管理后台管理员端点
        const editorApi = {
            ...api,
            merchantFetch: (url, opts = {}) => {
                let adminUrl = url;
                if (url.startsWith('/api/merchant/channel/drivers')) {
                    adminUrl = '/api/admin/channel/drivers';
                } else if (url.startsWith('/api/merchant/channel/save')) {
                    adminUrl = '/api/admin/channel/save';
                } else if (url.startsWith('/api/merchant/channel/authorization/start') || url.startsWith('/api/merchant/driver/start_auth')) {
                    adminUrl = '/api/admin/channel/start_driver_auth';
                } else if (url.startsWith('/api/merchant/channel/authorization/poll') || url.startsWith('/api/merchant/driver/poll_auth')) {
                    adminUrl = '/api/admin/channel/poll_driver_auth';
                }
                return api.adminFetch(adminUrl, opts);
            }
        };

        // 实例化与商户端 100% 相同的高级扫码授权模块
        activeAuthorization = createChannelAuthorization({
            root,
            api: editorApi,
            ui,
            signal,
        });

        // 实例化与商户端 100% 相同的手机监控助手配对模块
        activeAppAsst = createChannelAppAsst({
            root,
            channels: adminChannelsMap,
            ui,
        });

        // 实例化与商户端 100% 相同的通道配置编辑器（完整继承所有云端驱动表单、二维码解析、代授权横幅、监控助手）
        activeEditor = createChannelEditor({
            root,
            api: editorApi,
            ui,
            signal,
            reload: () => loadAdminChannels(context),
            authorization: activeAuthorization,
            appasst: activeAppAsst,
        });

        const onClick = async (event) => {
            const trigger = event.target.closest('[data-action]');
            if (!trigger || !root.contains(trigger)) return;
            const action = trigger.dataset.action;
            const id = trigger.dataset.channelId;

            if (action === 'refresh-channels') void loadAdminChannels(context);
            if (action === 'create-channel') {
                event.preventDefault();
                await activeEditor?.openNew();
            }
            if (action === 'edit-channel') {
                event.preventDefault();
                await openEditChannel(context, id);
            }
            if (action === 'close-channel-editor') {
                activeEditor?.close();
            }
            if (action === 'save-channel') {
                await activeEditor?.submit();
            }
            if (action === 'choose-qr-image') {
                root.querySelector('#channel-qr-file')?.click();
            }
            if (action === 'open-appasst-modal') {
                event.preventDefault();
                activeAppAsst?.open();
            }
            if (action === 'close-appasst-modal') {
                activeAppAsst?.close();
            }
            if (action === 'regen-appasst-secret') {
                activeAppAsst?.regenerateSecret();
            }
            if (action === 'open-isv-config') void openPlatformIsvModal(context);
            if (action === 'close-isv-modal') closePlatformIsvModal(root);
            if (action === 'copy-isv-callback-url') {
                const txt = root.querySelector('#admin-isv-callback-url-text')?.textContent?.trim();
                if (txt && navigator.clipboard?.writeText) {
                    navigator.clipboard.writeText(txt);
                    ui.showToast('📋 授权回调地址已复制到剪贴板！', 'success');
                }
            }
            if (action === 'open-clerk-config') void openPlatformClerkModal(context);
            if (action === 'close-clerk-modal') closePlatformClerkModal(root);
        };

        const onChange = async (event) => {
            const action = event.target.dataset.action;
            if (action === 'change-category') await activeEditor?.changeCategory();
            if (action === 'change-driver') activeEditor?.changeDriver();
            if (action === 'upload-qr') {
                await activeEditor?.uploadQr(event.target.files?.[0]);
                event.target.value = '';
            }
        };

        root.addEventListener('click', onClick, { signal });
        root.addEventListener('change', onChange, { signal });

        root.querySelector('[data-role="platform-isv-form"]')
            ?.addEventListener('submit', (event) => void submitPlatformIsvConfig(context, event), { signal });

        root.querySelector('[data-role="platform-clerk-form"]')
            ?.addEventListener('submit', (event) => void submitPlatformClerkConfig(context, event), { signal });

        root.querySelector('#admin-clerk-file-input')?.addEventListener('change', (event) => {
            void handleClerkFileSelected(root, ui, event);
        }, { signal });

        ui.safeCreateIcons();
        await Promise.all([loadAdminDriverCount(context), loadAdminChannels(context), loadPlatformClerkStatus(context)]);
    },

    unmount() {
        activeEditor = null;
        activeAuthorization = null;
    },
};

async function openEditChannel(context, channelId) {
    const { api, ui, signal } = context;
    if (!channelId || !activeEditor) return;
    try {
        const response = await api.adminFetch(`/api/admin/channel/get?id=${encodeURIComponent(channelId)}`, { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) {
            throw new Error(payload.msg || '读取通道配置失败');
        }
        await activeEditor.open(payload.data);
    } catch (error) {
        if (error?.name !== 'AbortError') {
            ui.showToast(error.message || '读取通道配置失败', 'error');
        }
    }
}

async function loadAdminDriverCount({ root, api, signal }) {
    const count = root.querySelector('#channel-stat-driver-count');
    if (!count) return;
    count.textContent = '读取中...';
    try {
        const response = await api.adminFetch('/api/admin/channel/driver_list', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) {
            throw new Error(payload.msg || '驱动数量读取失败');
        }
        const total = Array.isArray(payload.data?.list)
            ? payload.data.list.length
            : Object.values(payload.data || {}).flat().length;
        if (!signal.aborted) count.textContent = `${total} 个底层驱动`;
    } catch (error) {
        if (error?.name !== 'AbortError') count.textContent = '读取失败';
    }
}

async function loadAdminChannels({ root, api, ui, signal }) {
    const status = root.querySelector('#channel-stat-active-count');
    const list = root.querySelector('#admin-channel-list');
    if (!list) return;
    if (status) status.textContent = '读取中...';
    list.innerHTML = '<div class="p-8 text-center text-xs text-slate-400 col-span-full">正在加载平台全局通道...</div>';

    try {
        const response = await api.adminFetch('/api/admin/channel/list', { signal });
        const text = await response.text();
        let payload;
        try {
            payload = JSON.parse(text);
        } catch {
            throw new Error(`接口返回非法数据 (HTTP ${response.status})：${text.substring(0, 100)}`);
        }
        if (payload.code !== 1 || !Array.isArray(payload.data)) {
            throw new Error(payload.msg || '通道加载失败');
        }
        if (signal.aborted) return;

        const channels = payload.data;
        adminChannelsMap.clear();
        channels.forEach((item) => adminChannelsMap.set(String(item.id), item));
        const enabled = channels.filter((channel) => channel.enabled === true).length;
        const online = channels.filter((channel) => channel.enabled === true && Number(channel.online_status) === 1).length;
        if (status) {
            status.textContent = enabled > 0
                ? `${enabled} 个启用 / ${online} 在线`
                : (channels.length > 0 ? `${channels.length} 个通道 (全部停用)` : '0 个启用 / 0 在线');
        }
        list.innerHTML = channels.length
            ? channels.map((channel) => renderChannel(channel, ui)).join('')
            : `<div class="p-12 text-center text-slate-400 col-span-full space-y-4 bg-slate-50/50 rounded-3xl border border-dashed border-slate-200">
                <i data-lucide="layers" class="w-12 h-12 mx-auto text-slate-300"></i>
                <div>
                    <h4 class="text-sm font-bold text-slate-700">暂无已配置的平台收款通道</h4>
                    <p class="text-xs text-slate-400 max-w-md mx-auto leading-relaxed mt-1">您已成功安装底层支付驱动。您可以直接点击下方按钮为平台新建直营收款通道，或由商户在前台自行配置独立收款通道。</p>
                </div>
                <div class="pt-2">
                    <button type="button" data-action="create-channel" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm inline-flex items-center gap-1.5 cursor-pointer">
                        <i data-lucide="plus-circle" class="w-4 h-4"></i> 立即新建平台收款通道
                    </button>
                </div>
            </div>`;
        ui.safeCreateIcons();
    } catch (error) {
        if (error?.name === 'AbortError') return;
        if (status) status.textContent = '加载失败';
        list.innerHTML = `<div class="p-8 text-center text-xs text-rose-500 font-bold col-span-full bg-rose-50/50 rounded-3xl border border-rose-100">${ui.escapeHtml(error.message || '通道加载失败')}</div>`;
    }
}

function formatDuration(seconds) {
    if (seconds < 60) return `${seconds}秒`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}分`;
    return `${Math.floor(seconds / 3600)}小时`;
}

function renderChannel(channel, ui) {
    const cType = ui.escapeHtml(channel.c_type || 'default');
    const title = ui.escapeHtml(channel.name || channel.title || channel.c_type);
    const remark = ui.escapeHtml(channel.remark || '无备注');
    const enabled = channel.enabled === true;
    const online = enabled && Number(channel.online_status) === 1;

    const onlineSince = Number(channel.online_since || 0);
    const lastDuration = Number(channel.last_online_duration || 0);
    const nowTs = Math.floor(Date.now() / 1000);

    let durationTag = '';
    if (online) {
        const curDuration = onlineSince > 0 ? Math.max(1, nowTs - onlineSince) : 1;
        durationTag = `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">⏱ ${formatDuration(curDuration)}</span>`;
    } else if (lastDuration > 0) {
        durationTag = `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">⏱ 曾运行 ${formatDuration(lastDuration)}</span>`;
    }

    return `<article class="glass-panel p-5 rounded-2xl border ${enabled ? (online ? 'border-emerald-200/80 bg-white' : 'border-amber-200/80 bg-white') : 'border-slate-200 bg-slate-50/70'} space-y-4 shadow-sm transition-all hover:shadow-md">
        <div class="flex items-start justify-between gap-3">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-extrabold text-slate-800">${title}</h3>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${enabled ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : 'bg-slate-100 text-slate-500'}">${enabled ? '已启用' : '已停用'}</span>
                    ${durationTag}
                </div>
                <div class="text-xs text-slate-400 font-mono flex items-center gap-2">
                    <span>驱动: ${cType}</span>
                    <span>•</span>
                    <span class="${online ? 'text-emerald-600 font-bold' : 'text-slate-400'}">${online ? '● 运行中' : '○ 离线/等待'}</span>
                </div>
            </div>
            <div class="text-right text-xs">
                <span class="text-[10px] text-slate-400">权重</span>
                <p class="font-extrabold text-slate-700">${Number(channel.weight || 100)}</p>
            </div>
        </div>

        <p class="text-xs text-slate-500 bg-slate-50 p-2.5 rounded-xl border border-slate-100 line-clamp-2">${remark}</p>

        <div class="pt-2 border-t border-slate-100 flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <button type="button" data-action="edit-channel" data-channel-id="${Number(channel.id)}" class="px-3 py-1.5 rounded-xl bg-blue-50 text-blue-600 text-xs font-bold hover:bg-blue-100 cursor-pointer">⚙️ 通讯参数</button>
                <a href="/api/admin/channel/download_preset?id=${Number(channel.id)}" target="_blank" class="px-3 py-1.5 rounded-xl bg-emerald-50 text-emerald-600 text-xs font-bold hover:bg-emerald-100">📥 专属监控包</a>
            </div>
            <span class="text-[11px] font-mono text-slate-400">#${Number(channel.id)}</span>
        </div>
    </article>`;
}

async function loadPlatformClerkStatus({ root, api, signal }) {
    try {
        const resp = await api.adminFetch('/api/admin/channel/clerk_config', { signal });
        const res = await resp.json();
        if (res.code === 1 && res.data) {
            const hasQr = Boolean(res.data.clerk_qrcode);
            const statusBadge = root.querySelector('#admin-clerk-status-badge');
            const nameLabel = root.querySelector('#admin-clerk-name-label');
            if (statusBadge) {
                statusBadge.textContent = hasQr ? '已配置托管' : '待配置';
                statusBadge.className = hasQr
                    ? 'px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 text-[10px] font-bold border border-emerald-400/30'
                    : 'px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 text-[10px] font-bold border border-amber-400/30';
            }
            if (nameLabel && res.data.clerk_name) {
                nameLabel.textContent = res.data.clerk_name;
            }
        }
    } catch {
        // 忽略
    }
}

async function openPlatformClerkModal({ root, api, ui, signal }) {
    const modal = root.querySelector('#modal-platform-clerk-config');
    if (!modal) return;
    modal.classList.remove('hidden');
    ui.safeCreateIcons(modal);

    try {
        const resp = await api.adminFetch('/api/admin/channel/clerk_config', { signal });
        const res = await resp.json();
        if (res.code === 1 && res.data) {
            const nameInput = root.querySelector('#admin-clerk-name-input');
            const qrInput = root.querySelector('#admin-clerk-qrcode-input');
            const previewBox = root.querySelector('#admin-clerk-modal-preview');
            if (nameInput) nameInput.value = res.data.clerk_name || '平台官方收款店员';
            if (qrInput) qrInput.value = res.data.clerk_qrcode || '';
            if (previewBox && res.data.clerk_qrcode) {
                if (res.data.clerk_qrcode.startsWith('http://') || res.data.clerk_qrcode.startsWith('https://') || res.data.clerk_qrcode.startsWith('wxp://')) {
                    previewBox.innerHTML = `<div id="admin-clerk-modal-qr-canvas" class="flex items-center justify-center p-1 bg-white"></div>`;
                    new window.QRCode(previewBox.querySelector('#admin-clerk-modal-qr-canvas'), {
                        text: res.data.clerk_qrcode,
                        width: 100,
                        height: 100,
                    });
                } else {
                    previewBox.innerHTML = `<img src="${res.data.clerk_qrcode}" class="w-full h-full object-contain" />`;
                }
            }
        }
    } catch (e) {
        ui.showToast('读取店员配置失败: ' + e.message, 'error');
    }
}

function closePlatformClerkModal(root) {
    const modal = root.querySelector('#modal-platform-clerk-config');
    if (modal) modal.classList.add('hidden');
}

async function handleClerkFileSelected(root, ui, event) {
    const file = event.target.files?.[0];
    if (!file) return;

    try {
        const bitmap = await createImageBitmap(file);
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(bitmap, 0, 0);
        bitmap.close();

        const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        let decoded = null;
        if (window.jsQR) {
            decoded = window.jsQR(imgData.data, imgData.width, imgData.height, { inversionAttempts: 'attemptBoth' });
        }

        const qrInput = root.querySelector('#admin-clerk-qrcode-input');
        const previewBox = root.querySelector('#admin-clerk-modal-preview');

        if (decoded?.data) {
            if (qrInput) qrInput.value = decoded.data;
            if (previewBox) {
                previewBox.innerHTML = `<div id="admin-clerk-modal-qr-canvas" class="flex items-center justify-center p-1 bg-white"></div>`;
                new window.QRCode(previewBox.querySelector('#admin-clerk-modal-qr-canvas'), {
                    text: decoded.data,
                    width: 100,
                    height: 100,
                });
            }
            ui.showToast('🎉 二维码解码成功！');
        } else {
            const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
            if (qrInput) qrInput.value = dataUrl;
            if (previewBox) previewBox.innerHTML = `<img src="${dataUrl}" class="w-full h-full object-contain" />`;
            ui.showToast('🎉 图片已转为高画质数据载入！');
        }
    } catch (err) {
        ui.showToast('解析店员码失败: ' + err.message, 'error');
    }
}

async function submitPlatformClerkConfig({ root, api, ui, signal }, event) {
    event.preventDefault();
    const nameInput = root.querySelector('#admin-clerk-name-input');
    const qrInput = root.querySelector('#admin-clerk-qrcode-input');

    const body = new URLSearchParams({
        clerk_name: nameInput?.value.trim() || '平台官方收款店员',
        clerk_qrcode: qrInput?.value.trim() || '',
    });

    try {
        const resp = await api.adminFetch('/api/admin/channel/clerk_config/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            signal,
        });
        const res = await resp.json();
        if (res.code !== 1) throw new Error(res.msg || '保存店员配置失败');
        ui.showToast('🎉 平台统一店员配置保存成功！');
        closePlatformClerkModal(root);
        void loadPlatformClerkStatus({ root, api, signal });
    } catch (e) {
        ui.showToast('保存失败: ' + e.message, 'error');
    }
}

async function openPlatformIsvModal({ root, api, ui, signal }) {
    const modal = root.querySelector('#modal-platform-isv-config');
    if (!modal) return;
    modal.classList.remove('hidden');
    ui.safeCreateIcons(modal);

    const callbackUrlText = root.querySelector('#admin-isv-callback-url-text');
    if (callbackUrlText) {
        callbackUrlText.textContent = `${window.location.origin}/api/alipay/auth/callback`;
    }

    try {
        const resp = await api.adminFetch('/api/admin/channel/isv_config', { signal });
        const res = await resp.json();
        if (res.code === 1 && res.data) {
            const appIdInput = root.querySelector('#admin-isv-appid-input');
            const privKeyInput = root.querySelector('#admin-isv-privkey-input');
            const pubKeyInput = root.querySelector('#admin-isv-pubkey-input');

            if (appIdInput) appIdInput.value = res.data.app_id || '';
            if (privKeyInput) privKeyInput.value = res.data.private_key || '';
            if (pubKeyInput) pubKeyInput.value = res.data.public_key || '';
            if (callbackUrlText) {
                callbackUrlText.textContent = res.data.callback_url || `${window.location.origin}/api/alipay/auth/callback`;
            }
        }
    } catch (e) {
        ui.showToast('读取 ISV 配置失败: ' + e.message, 'error');
    }
}

function closePlatformIsvModal(root) {
    const modal = root.querySelector('#modal-platform-isv-config');
    if (modal) modal.classList.add('hidden');
}

async function submitPlatformIsvConfig({ root, api, ui, signal }, event) {
    event.preventDefault();
    const appIdInput = root.querySelector('#admin-isv-appid-input');
    const privKeyInput = root.querySelector('#admin-isv-privkey-input');
    const pubKeyInput = root.querySelector('#admin-isv-pubkey-input');

    const body = new URLSearchParams({
        app_id: appIdInput?.value.trim() || '',
        private_key: privKeyInput?.value.trim() || '',
        public_key: pubKeyInput?.value.trim() || '',
    });

    try {
        const resp = await api.adminFetch('/api/admin/channel/isv_config/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            signal,
        });
        const res = await resp.json();
        if (res.code !== 1) throw new Error(res.msg || '保存 ISV 配置失败');
        ui.showToast('🎉 支付宝 ISV 扫码代授权主应用配置保存成功！');
        closePlatformIsvModal(root);
    } catch (e) {
        ui.showToast('保存失败: ' + e.message, 'error');
    }
}
