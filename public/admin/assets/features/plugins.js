const pluginRecords = new Map();

export const feature = {
    id: 'plugins',

    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (!target) return;
            const pluginId = target.dataset.pluginId || '';
            if (target.dataset.action === 'toggle-plugin') {
                void togglePlugin(context, pluginId, Number(target.dataset.enabled));
            }
            if (target.dataset.action === 'uninstall-plugin') void uninstallPlugin(context, pluginId);
            if (target.dataset.action === 'rollback-plugin') void rollbackPlugin(context, pluginId);
        }, { signal });
        root.querySelector('#plugin-package-input')
            ?.addEventListener('change', (event) => void installPluginPackage(context, event.target), { signal });
        ui.safeCreateIcons();
        await loadInstalledPlugins(context);
    },

    unmount() {
        pluginRecords.clear();
    },
};

async function loadInstalledPlugins({ root, api, ui, signal }) {
    const list = root.querySelector('#plugin-driver-list');
    if (!list) return;
    list.innerHTML = '<div class="p-5 text-center text-xs text-slate-400 col-span-full">正在读取本地驱动...</div>';
    try {
        const response = await api.adminFetch('/api/admin/plugin/market_list', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '加载驱动列表失败');
        if (signal.aborted) return;
        const plugins = Array.isArray(payload.data.list) ? payload.data.list : [];
        pluginRecords.clear();
        plugins.forEach((plugin) => {
            if (plugin.plugin_id) pluginRecords.set(plugin.plugin_id, plugin);
        });
        list.innerHTML = plugins.length
            ? plugins.map((plugin) => renderPlugin(plugin, ui)).join('')
            : '<div class="p-5 text-center text-xs text-slate-400 col-span-full">暂无已安装的支付驱动或插件</div>';
        ui.safeCreateIcons();
    } catch (error) {
        if (error?.name !== 'AbortError') {
            list.innerHTML = `<div class="p-5 text-center text-xs text-rose-500 font-bold col-span-full">${ui.escapeHtml(error.message || '加载插件驱动时发生异常')}</div>`;
        }
    }
}

function renderPlugin(plugin, ui) {
    const builtin = plugin.source === 'builtin';
    const enabled = plugin.enabled === true;
    const pluginId = ui.escapeHtml(plugin.plugin_id || '');
    const versions = Array.isArray(plugin.versions) ? plugin.versions : [];
    const actions = !builtin && plugin.plugin_id ? `<div class="flex items-center gap-2 mt-3 pt-3 border-t border-slate-100">
        <button type="button" data-action="toggle-plugin" data-plugin-id="${pluginId}" data-enabled="${enabled ? 0 : 1}" class="flex-1 py-1.5 text-xs font-bold rounded-xl bg-slate-100">${enabled ? '⏸ 停用插件' : '▶ 启用插件'}</button>
        ${versions.length > 1 ? `<button type="button" data-action="rollback-plugin" data-plugin-id="${pluginId}" class="px-3 py-1.5 text-xs font-bold rounded-xl bg-amber-50 text-amber-700">回滚</button>` : ''}
        <button type="button" data-action="uninstall-plugin" data-plugin-id="${pluginId}" class="px-3 py-1.5 text-xs font-bold rounded-xl bg-rose-50 text-rose-600">卸载</button>
    </div>` : '';

    return `<article class="glass-panel p-4 rounded-2xl border border-slate-200/80 bg-white space-y-2">
        <div class="flex items-start justify-between gap-2"><div><div class="font-extrabold text-sm text-slate-800">${ui.escapeHtml(plugin.name || plugin.c_type || '未命名驱动')}</div><code class="text-[10px] text-slate-400">${ui.escapeHtml(plugin.c_type || '')}</code></div><div class="text-[10px] font-bold">${builtin ? '系统内置' : '扩展插件'} · ${enabled ? '已启用' : '已停用'}</div></div>
        ${plugin.description ? `<p class="text-[11px] text-slate-500">${ui.escapeHtml(plugin.description)}</p>` : ''}
        ${plugin.author ? `<div class="text-[11px] text-slate-400">作者 / 发布方：${ui.escapeHtml(plugin.author)}</div>` : ''}
        ${actions}
    </article>`;
}

async function togglePlugin(context, pluginId, enabled) {
    const { api, ui, signal } = context;
    const confirmed = await ui.showConfirm(
        enabled ? '启用插件确认' : '停用插件确认',
        `确定要${enabled ? '启用' : '停用'}插件 "${pluginId}" 吗？`,
        !enabled
    );
    if (!confirmed || signal.aborted) return;
    await mutatePlugin(context, '/api/admin/plugin/set_enabled', { plugin_id: pluginId, enabled: String(enabled) });
}

async function uninstallPlugin(context, pluginId) {
    const { ui, signal } = context;
    const confirmed = await ui.showConfirm('卸载插件确认', `确定要卸载插件 "${pluginId}" 吗？历史订单不会删除。`, true);
    if (!confirmed || signal.aborted) return;
    await mutatePlugin(context, '/api/admin/plugin/uninstall', { plugin_id: pluginId });
}

async function rollbackPlugin(context, pluginId) {
    const versions = pluginRecords.get(pluginId)?.versions || [];
    const version = window.prompt(`可回滚版本：${versions.join(', ')}\n请输入目标版本号`);
    if (!version) return;
    await mutatePlugin(context, '/api/admin/plugin/rollback', { plugin_id: pluginId, version: version.trim() });
}

async function mutatePlugin(context, url, values) {
    const { api, ui, signal } = context;
    try {
        const response = await api.adminFetch(url, {
            method: 'POST',
            body: new URLSearchParams(values),
            signal,
        });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '操作失败');
        ui.showToast(payload.msg || '操作成功');
    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast(error.message || '操作失败', 'error');
        return;
    }
    await loadInstalledPlugins(context);
}

async function installPluginPackage(context, input) {
    const { root, api, ui, signal } = context;
    const file = input.files?.[0];
    if (!file) return;
    if (!file.name.toLowerCase().endsWith('.cxpay-plugin')) {
        ui.showToast('只允许上传 .cxpay-plugin 安装包', 'error');
        input.value = '';
        return;
    }

    const list = root.querySelector('#plugin-driver-list');
    if (list) list.innerHTML = '<div class="p-5 text-center text-xs text-slate-400">⏳ 正在上传并安装插件包...</div>';
    const body = new FormData();
    body.append('package', file);
    try {
        const response = await api.adminFetch('/api/admin/plugin/install', { method: 'POST', body, signal });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '安装失败');
        ui.showToast(payload.msg || '插件安装成功！');
    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast(`安装失败: ${error.message}`, 'error');
    } finally {
        input.value = '';
    }
    if (!signal.aborted) await loadInstalledPlugins(context);
}
