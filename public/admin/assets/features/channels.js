export const feature = {
    id: 'channels',

    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            const actionElement = event.target.closest('[data-action]');
            if (!actionElement) return;
            if (actionElement.dataset.action === 'refresh-channels') void loadAdminChannels(context);
            if (actionElement.dataset.action === 'edit-channel') {
                void openChannelConfigEditor(context, actionElement.dataset.channelId);
            }
            if (actionElement.dataset.action === 'close-channel-editor') closeChannelConfigEditor(root);
        }, { signal });
        root.querySelector('[data-role="channel-config-form"]')
            ?.addEventListener('submit', (event) => void submitChannelConfig(context, event), { signal });
        ui.safeCreateIcons();
        await Promise.all([loadAdminDriverCount(context), loadAdminChannels(context)]);
    },

    unmount() {},
};

async function loadAdminDriverCount({ root, api, signal }) {
    const count = root.querySelector('#channel-stat-driver-count');
    if (!count) return;
    count.textContent = '读取中...';
    try {
        const response = await api.adminFetch('/api/admin/plugin/market_list', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !Array.isArray(payload.data?.list)) {
            throw new Error(payload.msg || '驱动数量读取失败');
        }
        if (!signal.aborted) count.textContent = `${payload.data.list.length} 个底层驱动`;
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
        const enabled = channels.filter((channel) => channel.enabled === true).length;
        const online = channels.filter((channel) => channel.enabled === true && Number(channel.online_status) === 1).length;
        if (status) {
            status.textContent = enabled > 0
                ? `${enabled} 个启用 / ${online} 在线`
                : `${channels.length} 个通道 (全部停用)`;
        }
        list.innerHTML = channels.length
            ? channels.map((channel) => renderChannel(channel, ui)).join('')
            : '<div class="p-8 text-center text-xs text-slate-400 col-span-full">暂无配置的底层收款通道</div>';
        ui.safeCreateIcons();
    } catch (error) {
        if (error?.name === 'AbortError') return;
        list.innerHTML = `<div class="p-8 text-center text-xs text-rose-500 font-bold col-span-full">${ui.escapeHtml(error.message || '加载通道异常')}</div>`;
        if (status) status.textContent = '加载异常';
    }
}

function renderChannel(channel, ui) {
    const name = channel.name?.trim() || channel.title?.trim() || channel.code?.trim()
        || channel.c_type?.trim() || '未命名通道';
    const code = channel.code?.trim() || channel.c_type?.trim() || '';
    const enabled = channel.enabled === true;
    const online = enabled && Number(channel.online_status) === 1;
    const configured = channel.configured !== false;
    const payType = String(channel.pay_type || channel.pay_category || channel.c_type || '').toLowerCase();
    const icon = payType.includes('wx') ? '微信' : (payType.includes('ali') ? '支付宝' : 'QQ');
    const status = online ? '● 路由在线' : (enabled ? '◎ 已启用/离线' : '○ 已停用');

    return `<article class="glass-panel p-5 rounded-2xl border border-slate-200/80 bg-white space-y-4 shadow-2xs hover:shadow-md transition-all">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2.5"><span class="w-9 h-9 rounded-xl bg-blue-100 text-blue-700 font-extrabold flex items-center justify-center text-xs">${icon}</span><div><div class="font-extrabold text-sm text-slate-800">${ui.escapeHtml(name)}</div><div class="text-[10px] font-mono text-slate-400">${ui.escapeHtml(code)}</div></div></div>
            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-slate-100 text-slate-600">${status}</span>
        </div>
        <div class="grid grid-cols-2 gap-2.5 text-xs">
            <div class="p-2.5 rounded-xl bg-slate-50"><span class="text-slate-400 block">路由权重</span><strong>${Number(channel.weight ?? 100)}</strong></div>
            <div class="p-2.5 rounded-xl bg-slate-50"><span class="text-slate-400 block">密钥状态</span><strong class="${configured ? 'text-emerald-600' : 'text-amber-600'}">${configured ? '✓ 已加密存储' : '⚠ 待配置'}</strong></div>
        </div>
        ${channel.remark ? `<div class="text-[11px] text-slate-500">备注：${ui.escapeHtml(channel.remark)}</div>` : ''}
        <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
            <button type="button" data-action="edit-channel" data-channel-id="${Number(channel.id)}" class="px-3 py-1.5 rounded-xl bg-blue-50 text-blue-600 text-xs font-bold">⚙️ 配置通讯参数</button>
            <span class="text-[11px] text-slate-400 font-mono">ID #${Number(channel.id)}</span>
        </div>
    </article>`;
}

async function openChannelConfigEditor(context, channelId) {
    const { root, api, ui, signal } = context;
    const modal = root.querySelector('#channel-config-editor');
    const fields = root.querySelector('#channel-dynamic-inputs');
    if (!modal || !fields) return;
    setValue(root, 'channel-config-id', channelId);
    fields.innerHTML = '<div class="p-4 text-center text-slate-400 font-bold">正在读取通道密钥与驱动参数...</div>';
    modal.classList.remove('hidden');

    try {
        const response = await api.adminFetch(`/api/admin/channel/get?id=${encodeURIComponent(channelId)}`, { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '读取通道参数失败');
        const channel = payload.data;
        setText(root, 'channel-config-title', `配置 [${channel.title || channel.c_type}] 通信参数`);
        setText(root, 'channel-config-subtitle', `底层驱动代码: ${channel.c_type}`);
        setValue(root, 'channel-config-ctype', channel.c_type);
        setValue(root, 'channel-config-name', channel.title || '');
        setValue(root, 'channel-config-weight', channel.weight || 100);
        setValue(root, 'channel-config-min', channel.single_min || 0);
        setValue(root, 'channel-config-max', channel.single_max || 0);
        setValue(root, 'channel-config-remark', channel.remark || '');

        const inputResponse = await api.adminFetch(`/api/admin/channel/inputs?c_type=${encodeURIComponent(channel.c_type)}`, { signal });
        const inputPayload = await inputResponse.json();
        const inputs = Array.isArray(inputPayload.data)
            ? inputPayload.data
            : (Array.isArray(inputPayload.data?.inputs) ? inputPayload.data.inputs : []);
        if (signal.aborted) return;
        fields.innerHTML = inputs.length
            ? inputs.map((input) => renderConfigInput(input, channel, ui)).join('')
            : '<div class="p-3 text-slate-400 font-bold text-center">该通道驱动无需额外通信秘钥配置</div>';
    } catch (error) {
        if (error?.name !== 'AbortError') {
            fields.innerHTML = `<div class="p-4 text-center text-rose-500 font-bold">读取失败: ${ui.escapeHtml(error.message)}</div>`;
        }
    }
}

function renderConfigInput(input, channel, ui) {
    const key = input.name || '';
    const label = input.label || key;
    const configured = channel.configured?.[key] === true;
    const value = channel.config?.[key] || '';
    const common = `data-config-key="${ui.escapeHtml(key)}" placeholder="${configured ? '已设置加密参数，留空保持旧设置' : ''}" class="w-full px-3 py-2 border rounded-xl font-mono text-xs"`;
    const control = input.type === 'textarea'
        ? `<textarea ${common} rows="3">${ui.escapeHtml(value)}</textarea>`
        : `<input ${common} type="${input.type === 'password' ? 'password' : 'text'}" value="${ui.escapeHtml(value)}">`;
    return `<div class="space-y-1"><label class="font-bold text-slate-700">${ui.escapeHtml(label)}${configured ? ' <span class="text-emerald-600">✓ 已加密</span>' : ''}</label>${control}</div>`;
}

async function submitChannelConfig(context, event) {
    event.preventDefault();
    const { root, api, ui, signal } = context;
    const body = new URLSearchParams();
    const fields = {
        id: 'channel-config-id', c_type: 'channel-config-ctype', title: 'channel-config-name',
        weight: 'channel-config-weight', single_min: 'channel-config-min',
        single_max: 'channel-config-max', remark: 'channel-config-remark',
    };
    Object.entries(fields).forEach(([key, id]) => body.append(key, root.querySelector(`#${id}`)?.value || ''));
    body.append('status', '1');
    root.querySelectorAll('[data-config-key]').forEach((input) => {
        body.append(`config[${input.dataset.configKey}]`, input.value);
    });

    try {
        const response = await api.adminFetch('/api/admin/channel/config/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            signal,
        });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '保存失败');
        ui.showToast(payload.msg || '通道通信参数保存成功！');
        closeChannelConfigEditor(root);
        await loadAdminChannels(context);
    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast(`保存失败: ${error.message}`, 'error');
    }
}

function closeChannelConfigEditor(root) {
    root.querySelector('#channel-config-editor')?.classList.add('hidden');
}

function setText(root, id, value) {
    const element = root.querySelector(`#${id}`);
    if (element) element.textContent = value;
}

function setValue(root, id, value) {
    const element = root.querySelector(`#${id}`);
    if (element) element.value = value;
}
