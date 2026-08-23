let currentGroups = [];
let editingGroup = null;

export const feature = {
    id: 'poll-group',

    async mount(context) {
        const { root, ui, signal } = context;

        const onClick = async (event) => {
            const trigger = event.target.closest('[data-action]');
            if (!trigger || !root.contains(trigger)) return;
            const action = trigger.dataset.action;
            const id = Number(trigger.dataset.id || 0);

            if (action === 'reload-poll-groups') void loadGroups(context);
            if (action === 'create-poll-group') openGroupEditor(context, null);
            if (action === 'edit-poll-group') {
                const group = currentGroups.find(g => Number(g.id) === id);
                if (group) openGroupEditor(context, group);
            }
            if (action === 'delete-poll-group') {
                void deleteGroup(context, id);
            }
            if (action === 'toggle-poll-group') {
                const status = Number(trigger.dataset.status || 0);
                void toggleGroupStatus(context, id, status === 1 ? 0 : 1);
            }
            if (action === 'bind-channels') {
                const group = currentGroups.find(g => Number(g.id) === id);
                if (group) void openBindModal(context, group);
            }
            if (action === 'close-editor-modal') closeEditorModal(root);
            if (action === 'close-bind-modal') closeBindModal(root);
            if (action === 'open-simulate-modal') openSimulateModal(context);
            if (action === 'close-simulate-modal') closeSimulateModal(root);
            if (action === 'run-simulation') void runSimulation(context);
        };

        const onInput = (event) => {
            if (event.target.classList.contains('bind-weight-input') || event.target.classList.contains('bind-channel-check')) {
                updateBindWeightCalculations(root);
            }
        };

        root.addEventListener('click', onClick, { signal });
        root.addEventListener('input', onInput, { signal });
        root.addEventListener('change', onInput, { signal });

        root.querySelector('[data-role="poll-group-form"]')
            ?.addEventListener('submit', (e) => void submitGroupForm(context, e), { signal });

        root.querySelector('[data-role="poll-bind-form"]')
            ?.addEventListener('submit', (e) => void submitBindForm(context, e), { signal });

        ui.safeCreateIcons();
        await loadGroups(context);
    },

    unmount() {
        currentGroups = [];
        editingGroup = null;
    },
};

async function loadGroups(context) {
    const { root, api, ui, signal } = context;
    const container = root.querySelector('#poll-group-list-container');
    if (!container) return;

    container.innerHTML = '<div class="col-span-full p-12 text-center text-xs text-slate-400 bg-white rounded-3xl border border-dashed border-slate-200">正在获取轮询调度组...</div>';

    try {
        const response = await api.adminFetch('/api/admin/poll_group/list', { signal });
        const payload = await response.json();
        if (signal.aborted) return;

        if (payload.code !== 1 || !Array.isArray(payload.data)) {
            throw new Error(payload.msg || '读取轮询组失败');
        }

        currentGroups = payload.data;
        updateTopStats(root, currentGroups);

        if (currentGroups.length === 0) {
            container.innerHTML = `
                <div class="col-span-full p-16 text-center text-slate-400 bg-white rounded-3xl border border-dashed border-slate-200 space-y-3">
                    <i data-lucide="layers" class="w-12 h-12 mx-auto text-slate-300"></i>
                    <div>
                        <div class="text-sm font-bold text-slate-700">暂无轮询调度组</div>
                        <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1">您可以点击右上角「新建轮询组」，将多个收款通道聚合为一个高并发分流大池。</p>
                    </div>
                    <button type="button" data-action="create-poll-group" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-sm transition-all cursor-pointer">
                        ➕ 立即新建第一个轮询组
                    </button>
                </div>
            `;
            ui.safeCreateIcons(container);
            return;
        }

        container.innerHTML = currentGroups.map(group => renderGroupCard(group, ui)).join('');
        ui.safeCreateIcons(container);
    } catch (error) {
        if (error?.name !== 'AbortError') {
            container.innerHTML = `<div class="col-span-full p-8 text-center text-rose-500 bg-rose-50 rounded-2xl border border-rose-200 text-xs font-bold">${ui.escapeHtml(error.message)}</div>`;
        }
    }
}

function updateTopStats(root, groups) {
    const totalGroups = groups.length;
    const activeGroups = groups.filter(g => Number(g.status) === 1).length;
    let boundChannels = 0;
    groups.forEach(g => {
        boundChannels += (g.channels_count || 0);
    });

    const setVal = (id, val) => {
        const el = root.querySelector(id);
        if (el) el.textContent = val;
    };

    setVal('#stat-total-groups', `${totalGroups} 个`);
    setVal('#stat-active-groups', `${activeGroups} 个运行中`);
    setVal('#stat-bound-channels', `${boundChannels} 个通道`);
}

function renderGroupCard(group, ui) {
    const isActive = Number(group.status) === 1;
    const channels = Array.isArray(group.channels) ? group.channels : [];
    const totalWeight = Number(group.total_weight || 0);

    const categoryNames = {
        wxpay: '微信支付',
        alipay: '支付宝',
        qqpay: 'QQ 钱包',
        usdt: 'USDT 区块链',
    };
    const categoryColors = {
        wxpay: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        alipay: 'bg-blue-50 text-blue-700 border-blue-200',
        qqpay: 'bg-sky-50 text-sky-700 border-sky-200',
        usdt: 'bg-teal-50 text-teal-700 border-teal-200',
    };

    const strategyBadges = {
        1: '<span class="px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200 text-[10px] font-bold">📊 加权随机分流</span>',
        2: '<span class="px-2 py-0.5 rounded-full bg-purple-50 text-purple-700 border border-purple-200 text-[10px] font-bold">🔄 顺序平滑轮询</span>',
        3: '<span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-bold">⚖️ 最小负载优先</span>',
    };

    return `
    <div class="bg-white rounded-3xl p-5 border ${isActive ? 'border-slate-200 shadow-xs' : 'border-slate-100 opacity-75'} space-y-4 flex flex-col justify-between transition-all hover:shadow-md">
        <!-- 头部信息 -->
        <div class="space-y-2">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full ${isActive ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300'}"></span>
                    <h3 class="font-extrabold text-slate-800 text-sm">${ui.escapeHtml(group.name)}</h3>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border ${categoryColors[group.c_type] || 'bg-slate-50 text-slate-600 border-slate-200'}">
                        ${categoryNames[group.c_type] || group.c_type.toUpperCase()}
                    </span>
                    ${strategyBadges[group.strategy] || strategyBadges[1]}
                </div>
            </div>

            <!-- 通道数量与状态提示 -->
            <div class="flex items-center justify-between text-xs text-slate-400 font-mono">
                <span>绑定: <b class="text-slate-700">${group.channels_count || 0}</b> 个通道（${group.online_channels_count || 0} 在线）</span>
                <span>总权重: <b class="text-indigo-600">${totalWeight}</b></span>
            </div>
        </div>

        <!-- 组内通道列表预览 -->
        <div class="p-3 bg-slate-50/80 rounded-2xl border border-slate-100 space-y-2 text-xs flex-1">
            ${channels.length === 0 ? `
                <div class="py-4 text-center text-slate-400 space-y-1.5">
                    <p class="text-[11px]">暂未绑定通道，当前不会被分配订单</p>
                    <button type="button" data-action="bind-channels" data-id="${Number(group.id)}" class="px-3 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 font-bold rounded-lg text-xs transition-colors cursor-pointer">
                        ⚙️ 立即绑定通道
                    </button>
                </div>
            ` : `
                <div class="space-y-1.5 max-h-48 overflow-y-auto custom-scrollbar pr-1">
                    ${channels.map(ch => {
                        const percent = totalWeight > 0 ? Math.round((ch.weight / totalWeight) * 100) : 0;
                        const isOnline = (Number(ch.status) === 1 && Number(ch.online_status) === 1);
                        return `
                        <div class="p-2 bg-white rounded-xl border border-slate-100 flex items-center justify-between gap-2 shadow-2xs text-[11px]">
                            <div class="flex items-center gap-1.5 min-w-0 flex-1">
                                <span class="w-1.5 h-1.5 rounded-full ${isOnline ? 'bg-emerald-500' : 'bg-rose-400'}" title="${isOnline ? '通道在线' : '通道离线'}"></span>
                                <span class="font-bold text-slate-700 truncate">${ui.escapeHtml(ch.title)}</span>
                                <span class="text-[10px] text-slate-400 font-mono">#${ch.id}</span>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="text-[10px] font-mono text-slate-400">今日 ¥${ch.today_money}</span>
                                <span class="px-1.5 py-0.5 bg-indigo-50 text-indigo-700 rounded text-[10px] font-bold font-mono">
                                    权重 ${ch.weight} (${percent}%)
                                </span>
                            </div>
                        </div>
                        `;
                    }).join('')}
                </div>
            `}
        </div>

        <!-- 底部操作按钮 -->
        <div class="pt-2 border-t border-slate-100 flex items-center justify-between gap-2">
            <button type="button" data-action="toggle-poll-group" data-id="${Number(group.id)}" data-status="${isActive ? 1 : 0}" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer ${isActive ? 'bg-amber-50 hover:bg-amber-100 text-amber-700' : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700'}">
                ${isActive ? '⏸ 暂停调度' : '▶ 启用调度'}
            </button>
            <div class="flex items-center gap-1.5">
                <button type="button" data-action="bind-channels" data-id="${Number(group.id)}" class="px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-xl text-xs font-bold transition-colors cursor-pointer">
                    ⚙️ 分流权重
                </button>
                <button type="button" data-action="edit-poll-group" data-id="${Number(group.id)}" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-colors cursor-pointer">
                    ✏️ 编辑
                </button>
                <button type="button" data-action="delete-poll-group" data-id="${Number(group.id)}" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-xl text-xs font-bold transition-colors cursor-pointer" title="删除轮询组">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                </button>
            </div>
        </div>
    </div>
    `;
}

function openGroupEditor({ root, ui }, group = null) {
    editingGroup = group;
    const modal = root.querySelector('#modal-poll-group-editor');
    if (!modal) return;

    const titleEl = root.querySelector('#poll-group-modal-title');
    const idInput = root.querySelector('#pg-id-input');
    const nameInput = root.querySelector('#pg-name-input');
    const cTypeSelect = root.querySelector('#pg-ctype-select');
    const strategySelect = root.querySelector('#pg-strategy-select');

    if (group) {
        if (titleEl) titleEl.textContent = '编辑轮询调度组';
        if (idInput) idInput.value = group.id;
        if (nameInput) nameInput.value = group.name;
        if (cTypeSelect) cTypeSelect.value = group.c_type;
        if (strategySelect) strategySelect.value = String(group.strategy || 1);
        const radio = modal.querySelector(`input[name="pg-status"][value="${group.status}"]`);
        if (radio) radio.checked = true;
    } else {
        if (titleEl) titleEl.textContent = '新建轮询调度组';
        if (idInput) idInput.value = '0';
        if (nameInput) nameInput.value = '';
        if (cTypeSelect) cTypeSelect.value = 'wxpay';
        if (strategySelect) strategySelect.value = '1';
        const radio = modal.querySelector('input[name="pg-status"][value="1"]');
        if (radio) radio.checked = true;
    }

    modal.classList.remove('hidden');
    ui.safeCreateIcons(modal);
}

function closeEditorModal(root) {
    root.querySelector('#modal-poll-group-editor')?.classList.add('hidden');
}

async function submitGroupForm(context, event) {
    event.preventDefault();
    const { root, api, ui, signal } = context;

    const id = Number(root.querySelector('#pg-id-input')?.value || 0);
    const name = root.querySelector('#pg-name-input')?.value.trim() || '';
    const c_type = root.querySelector('#pg-ctype-select')?.value || 'wxpay';
    const strategy = Number(root.querySelector('#pg-strategy-select')?.value || 1);
    const status = Number(root.querySelector('input[name="pg-status"]:checked')?.value || 1);

    if (!name) {
        ui.showToast('请输入轮询组名称', 'error');
        return;
    }

    const body = new URLSearchParams({
        id: String(id),
        name,
        c_type,
        strategy: String(strategy),
        status: String(status),
    });

    try {
        const resp = await api.adminFetch('/api/admin/poll_group/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            signal,
        });
        const res = await resp.json();
        if (res.code !== 1) throw new Error(res.msg || '保存轮询组失败');

        ui.showToast('🎉 轮询组配置保存成功！', 'success');
        closeEditorModal(root);
        await loadGroups(context);
    } catch (e) {
        ui.showToast(e.message || '保存失败', 'error');
    }
}

async function toggleGroupStatus(context, id, newStatus) {
    const { api, ui, signal } = context;
    try {
        const body = new URLSearchParams({ id: String(id), status: String(newStatus) });
        const resp = await api.adminFetch('/api/admin/poll_group/toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            signal,
        });
        const res = await resp.json();
        if (res.code !== 1) throw new Error(res.msg || '操作失败');
        ui.showToast(res.msg || '状态已更新', 'success');
        await loadGroups(context);
    } catch (e) {
        ui.showToast(e.message || '操作失败', 'error');
    }
}

async function deleteGroup(context, id) {
    if (!confirm('确定要删除此轮询调度组吗？删除后组内通道关联将被移除，通道本身不会被删除。')) {
        return;
    }
    const { api, ui, signal } = context;
    try {
        const body = new URLSearchParams({ id: String(id) });
        const resp = await api.adminFetch('/api/admin/poll_group/delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            signal,
        });
        const res = await resp.json();
        if (res.code !== 1) throw new Error(res.msg || '删除失败');
        ui.showToast('轮询组已删除', 'success');
        await loadGroups(context);
    } catch (e) {
        ui.showToast(e.message || '删除失败', 'error');
    }
}

async function openBindModal(context, group) {
    const { root, api, ui, signal } = context;
    const modal = root.querySelector('#modal-poll-group-bind');
    if (!modal) return;

    root.querySelector('#bind-group-id').value = String(group.id);
    root.querySelector('#bind-modal-group-name').textContent = group.name;

    const list = root.querySelector('#bind-channels-list');
    list.innerHTML = '<div class="p-8 text-center text-slate-400">正在读取可用通道...</div>';
    modal.classList.remove('hidden');
    ui.safeCreateIcons(modal);

    try {
        const resp = await api.adminFetch(`/api/admin/poll_group/available_channels?c_type=${encodeURIComponent(group.c_type)}`, { signal });
        const res = await resp.json();
        if (res.code !== 1 || !Array.isArray(res.data)) {
            throw new Error(res.msg || '读取通道列表失败');
        }

        const available = res.data;
        if (available.length === 0) {
            list.innerHTML = `<div class="p-8 text-center text-slate-400">当前支付分类 (${group.c_type}) 暂无已配置的可用收款通道。</div>`;
            return;
        }

        const boundMap = new Map();
        (group.channels || []).forEach(ch => boundMap.set(Number(ch.id), Number(ch.weight || 50)));

        list.innerHTML = available.map(ch => {
            const isChecked = boundMap.has(Number(ch.id));
            const weight = boundMap.get(Number(ch.id)) || 50;
            const isOnline = (Number(ch.status) === 1 && Number(ch.online_status) === 1);

            return `
            <div class="p-3.5 bg-slate-50 border border-slate-200/80 rounded-2xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 hover:bg-white hover:border-blue-200 transition-all channel-bind-row" data-channel-id="${Number(ch.id)}">
                <div class="flex items-center gap-3">
                    <input type="checkbox" class="w-4 h-4 text-blue-600 rounded cursor-pointer bind-channel-check" ${isChecked ? 'checked' : ''}>
                    <div>
                        <div class="font-extrabold text-slate-800 text-xs flex items-center gap-2">
                            <span>${ui.escapeHtml(ch.title)}</span>
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono ${isOnline ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}">
                                ${isOnline ? '在线' : '离线'}
                            </span>
                        </div>
                        <div class="text-[10px] text-slate-400 font-mono mt-0.5">
                            ID: #${ch.id} | 类型: ${ch.c_type} | 今日: ¥${ch.today_money}
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 self-end sm:self-center">
                    <span class="text-[11px] font-bold text-slate-500">权重:</span>
                    <input type="number" min="1" max="1000" value="${weight}" class="w-16 px-2 py-1 text-center border rounded-xl font-mono font-bold text-xs bg-white bind-weight-input">
                    <span class="text-[10px] font-mono font-bold px-2 py-1 rounded-lg bg-blue-50 text-blue-600 bind-percent-tag">0%</span>
                </div>
            </div>
            `;
        }).join('');

        updateBindWeightCalculations(root);
        ui.safeCreateIcons(list);
    } catch (e) {
        list.innerHTML = `<div class="p-4 text-center text-rose-500 text-xs font-bold">${e.message || '加载通道失败'}</div>`;
    }
}

function updateBindWeightCalculations(root) {
    const rows = root.querySelectorAll('.channel-bind-row');
    let totalWeight = 0;

    rows.forEach(row => {
        const check = row.querySelector('.bind-channel-check');
        const input = row.querySelector('.bind-weight-input');
        if (check && check.checked && input) {
            totalWeight += Number(input.value || 0);
        }
    });

    const badge = root.querySelector('#bind-total-weight-badge');
    if (badge) badge.textContent = `总权重: ${totalWeight}`;

    rows.forEach(row => {
        const check = row.querySelector('.bind-channel-check');
        const input = row.querySelector('.bind-weight-input');
        const percentTag = row.querySelector('.bind-percent-tag');
        if (check && input && percentTag) {
            if (check.checked && totalWeight > 0) {
                const w = Number(input.value || 0);
                const pct = ((w / totalWeight) * 100).toFixed(1);
                percentTag.textContent = `${pct}%`;
                percentTag.className = 'text-[10px] font-mono font-bold px-2 py-1 rounded-lg bg-blue-100 text-blue-700 bind-percent-tag';
                input.disabled = false;
            } else {
                percentTag.textContent = '未选';
                percentTag.className = 'text-[10px] font-mono px-2 py-1 rounded-lg bg-slate-100 text-slate-400 bind-percent-tag';
                input.disabled = !check?.checked;
            }
        }
    });
}

function closeBindModal(root) {
    root.querySelector('#modal-poll-group-bind')?.classList.add('hidden');
}

async function submitBindForm(context, event) {
    event.preventDefault();
    const { root, api, ui, signal } = context;

    const groupId = Number(root.querySelector('#bind-group-id')?.value || 0);
    const rows = root.querySelectorAll('.channel-bind-row');
    const channels = [];

    rows.forEach(row => {
        const check = row.querySelector('.bind-channel-check');
        const input = row.querySelector('.bind-weight-input');
        const channelId = Number(row.dataset.channelId || 0);
        if (check && check.checked && channelId > 0) {
            const weight = Number(input?.value || 50);
            channels.push({ channel_id: channelId, weight });
        }
    });

    const body = new URLSearchParams({
        group_id: String(groupId),
        channels: JSON.stringify(channels),
    });

    try {
        const resp = await api.adminFetch('/api/admin/poll_group/bind', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            signal,
        });
        const res = await resp.json();
        if (res.code !== 1) throw new Error(res.msg || '保存失败');

        ui.showToast('🎉 通道绑定与分流权重配置已成功生效！', 'success');
        closeBindModal(root);
        await loadGroups(context);
    } catch (e) {
        ui.showToast(e.message || '绑定失败', 'error');
    }
}

function openSimulateModal({ root, ui }) {
    const modal = root.querySelector('#modal-poll-group-simulate');
    if (!modal) return;
    const resultBox = root.querySelector('#sim-result-box');
    if (resultBox) resultBox.classList.add('hidden');
    modal.classList.remove('hidden');
    ui.safeCreateIcons(modal);
}

function closeSimulateModal(root) {
    root.querySelector('#modal-poll-group-simulate')?.classList.add('hidden');
}

async function runSimulation(context) {
    const { root, api, ui, signal } = context;
    const cType = root.querySelector('#sim-ctype-select')?.value || 'wxpay';
    const amount = root.querySelector('#sim-amount-input')?.value || '10.00';
    const resultBox = root.querySelector('#sim-result-box');

    try {
        const body = new URLSearchParams({ c_type: cType, amount });
        const resp = await api.adminFetch('/api/admin/poll_group/simulate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
            signal,
        });
        const res = await resp.json();
        if (res.code !== 1 || !res.data) {
            throw new Error(res.msg || '模拟调度失败');
        }

        if (resultBox) {
            root.querySelector('#sim-channel-title').textContent = res.data.selected_channel_title;
            root.querySelector('#sim-channel-id').textContent = `#${res.data.selected_channel_id}`;
            root.querySelector('#sim-channel-driver').textContent = res.data.c_type;
            root.querySelector('#sim-dispatch-type').textContent = res.data.poll_group_id
                ? `🎯 轮询组智能调度 (组ID: #${res.data.poll_group_id})`
                : `⚡ 全局通道池备用分发 (无活跃轮询组)`;
            resultBox.classList.remove('hidden');
            ui.showToast('✅ 模拟调度测算完成！', 'success');
        }
    } catch (e) {
        ui.showToast('模拟调度测算失败: ' + e.message, 'error');
    }
}
