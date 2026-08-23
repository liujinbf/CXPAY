const planRecords = new Map();

export const feature = {
    id: 'plans',
    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (target) {
                if (target.dataset.action === 'open-plan') void openPlanEditor(context, planRecords.get(Number(target.dataset.planId)));
                if (target.dataset.action === 'delete-plan') void deletePlan(context, target.dataset.planId);
                if (target.dataset.action === 'close-plan') closePlanEditor(root);
            }
            if (event.target.id === 'btn-plan-select-all') {
                root.querySelectorAll('input[name="plan_channel_cb"]').forEach((cb) => { cb.checked = true; });
            }
            if (event.target.id === 'btn-plan-clear-all') {
                root.querySelectorAll('input[name="plan_channel_cb"]').forEach((cb) => { cb.checked = false; });
            }
        }, { signal });

        root.querySelector('[data-role="plan-form"]')
            ?.addEventListener('submit', (event) => void submitPlan(context, event), { signal });
        ui.safeCreateIcons();
        await loadPlans(context);
    },
    unmount() { planRecords.clear(); },
};

async function loadPlans({ root, api, ui, signal }) {
    const body = root.querySelector('#plan-table-body');
    if (!body) return;
    body.innerHTML = '<tr><td colspan="10" class="p-6 text-center text-slate-400">正在加载套餐列表...</td></tr>';
    try {
        const response = await api.adminFetch('/api/admin/packvip/list', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !Array.isArray(payload.data)) throw new Error(payload.msg || '加载失败');
        if (signal.aborted) return;
        planRecords.clear();
        payload.data.forEach((plan) => planRecords.set(Number(plan.id), plan));
        body.innerHTML = payload.data.length ? payload.data.map((plan) => renderPlan(plan, ui)).join('')
            : '<tr><td colspan="10" class="p-6 text-center text-slate-400">暂无配置套餐</td></tr>';
    } catch (error) {
        if (error?.name !== 'AbortError') body.innerHTML = `<tr><td colspan="10" class="p-6 text-center text-rose-500">${ui.escapeHtml(error.message)}</td></tr>`;
    }
}

function renderPlan(plan, ui) {
    return `<tr class="hover:bg-slate-50/80">
        <td class="p-3 font-mono">#${Number(plan.id)} <small>(${Number(plan.sort_order || 0)})</small></td>
        <td class="p-3 font-bold">${ui.escapeHtml(plan.name)}${plan.memo ? `<div class="text-[10px] text-slate-400">${ui.escapeHtml(plan.memo)}</div>` : ''}</td>
        <td class="p-3">${Number(plan.price) === 0 ? '0元试用' : `¥ ${Number(plan.price).toFixed(2)}`}</td>
        <td class="p-3">${Number(plan.days) > 0 ? `${plan.days} 天` : '永久有效'}</td>
        <td class="p-3">${Number(plan.rate).toFixed(2)}%</td><td class="p-3">${Number(plan.min_rate) > 0 ? `${Number(plan.min_rate).toFixed(2)}%` : '无限制'}</td>
        <td class="p-3">${Number(plan.channel_quota) > 0 ? `${plan.channel_quota} 个` : '不限制'}</td>
        <td class="p-3">${Number(plan.limit_count) > 0 ? `限 ${plan.limit_count} 次` : '不限次'}</td>
        <td class="p-3">${Number(plan.status) === 1 ? '已启用' : '已禁用'}</td>
        <td class="p-3 text-right"><button type="button" data-action="open-plan" data-plan-id="${Number(plan.id)}" class="text-blue-600 font-bold mr-2">编辑</button><button type="button" data-action="delete-plan" data-plan-id="${Number(plan.id)}" class="text-rose-500 font-bold">删除</button></td>
    </tr>`;
}

async function openPlanEditor({ root, api, ui, signal }, plan = null) {
    const values = {
        id: plan?.id || 0,
        name: plan?.name || '',
        days: plan?.days ?? 30,
        rate: plan?.rate ?? 2.5,
        'min-rate': plan?.min_rate || 0,
        'channel-quota': plan?.channel_quota || 0,
        price: plan?.price || 0,
        'limit-count': plan?.limit_count || 0,
        memo: plan?.memo || '',
        'sort-order': plan?.sort_order || 0
    };
    setText(root, 'plan-editor-title', plan ? '编辑套餐' : '添加套餐');
    Object.entries(values).forEach(([key, value]) => setValue(root, `plan-${key}`, value));

    const selectedRaw = String(plan?.allowed_channels || '').split(',').map((item) => item.trim()).filter(Boolean);
    const container = root.querySelector('#plan-channels-grid');
    if (container) {
        container.innerHTML = '<div class="text-slate-400 text-xs text-center py-4">正在读取可用支付插件驱动...</div>';
        try {
            const response = await api.adminFetch('/api/admin/packvip/drivers', { signal });
            const payload = await response.json();
            const drivers = Array.isArray(payload.data) ? payload.data : [];
            if (drivers.length === 0) {
                container.innerHTML = '<div class="text-slate-400 text-xs text-center py-4">系统暂未安装或启用任何支付插件驱动</div>';
            } else {
                container.innerHTML = drivers.map((driver) => {
                    // 判断是否已勾选：包含驱动标识、包含大类、或原本为空时默认勾选
                    const isChecked = selectedRaw.length === 0 
                        || selectedRaw.includes(driver.c_type)
                        || selectedRaw.includes(driver.category)
                        || selectedRaw.includes('*')
                        || selectedRaw.includes('all');
                    
                    const badgeClass = driver.category === 'wxpay' 
                        ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
                        : (driver.category === 'alipay' 
                            ? 'bg-blue-100 text-blue-800 border-blue-200' 
                            : 'bg-purple-100 text-purple-800 border-purple-200');
                    const badgeText = driver.category === 'wxpay' ? '微信'
                        : (driver.category === 'alipay' ? '支付宝'
                        : (driver.category === 'qqpay' ? 'QQ' : '其他'));

                    return `
                        <label class="flex items-center justify-between p-2.5 bg-white hover:bg-blue-50/50 border border-slate-200 rounded-lg cursor-pointer transition-colors select-none group">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <input type="checkbox" name="plan_channel_cb" value="${ui.escapeHtml(driver.c_type)}" ${isChecked ? 'checked' : ''} class="w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500 cursor-pointer">
                                <div class="truncate">
                                    <span class="font-bold text-slate-700 text-xs">${ui.escapeHtml(driver.name || driver.c_type)}</span>
                                    <span class="block text-[10px] text-slate-400 font-mono">${ui.escapeHtml(driver.c_type)}</span>
                                </div>
                            </div>
                            <span class="px-2 py-0.5 text-[10px] font-bold rounded border ${badgeClass} flex-shrink-0">${badgeText}</span>
                        </label>
                    `;
                }).join('');
            }
        } catch (error) {
            container.innerHTML = `<div class="text-rose-500 text-xs text-center py-4">${ui.escapeHtml(error.message || '加载驱动失败')}</div>`;
        }
    }

    root.querySelectorAll('input[name="plan_status"]').forEach((radio) => { radio.checked = radio.value === String(plan?.status ?? 1); });
    root.querySelector('#plan-editor')?.classList.remove('hidden');
    root.querySelector('#plan-editor')?.classList.add('flex');
}

function closePlanEditor(root) {
    root.querySelector('#plan-editor')?.classList.add('hidden');
    root.querySelector('#plan-editor')?.classList.remove('flex');
}

async function submitPlan(context, event) {
    event.preventDefault();
    const { root, api, ui, signal } = context;
    const value = (id) => root.querySelector(`#${id}`)?.value || '';
    
    const checkedChannels = Array.from(root.querySelectorAll('input[name="plan_channel_cb"]:checked'))
        .map((cb) => cb.value)
        .join(',');

    const payload = {
        id: value('plan-id'),
        name: value('plan-name').trim(),
        days: value('plan-days'),
        rate: value('plan-rate'),
        min_rate: value('plan-min-rate'),
        channel_quota: value('plan-channel-quota'),
        allowed_channels: checkedChannels,
        price: value('plan-price'),
        limit_count: value('plan-limit-count'),
        memo: value('plan-memo').trim(),
        sort_order: value('plan-sort-order'),
        status: root.querySelector('input[name="plan_status"]:checked')?.value || '1'
    };
    await mutatePlan(context, '/api/admin/packvip/save', payload, '套餐保存成功！');
    if (!signal.aborted) closePlanEditor(root);
}

async function deletePlan(context, id) {
    const { ui, signal } = context;
    if (!await ui.showConfirm('删除套餐确认', '确定要删除该套餐配置吗？删除后新商户将无法进行订阅。', true) || signal.aborted) return;
    await mutatePlan(context, '/api/admin/packvip/delete', { id }, '套餐已成功删除！');
}

async function mutatePlan(context, url, values, success) {
    const { api, ui, signal } = context;
    try {
        const response = await api.adminFetch(url, { method: 'POST', body: new URLSearchParams(values), signal });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '操作失败');
        ui.showToast(payload.msg || success);
        await loadPlans(context);
    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast(error.message, 'error');
    }
}

function setText(root, id, value) { const element = root.querySelector(`#${id}`); if (element) element.textContent = value; }
function setValue(root, id, value) { const element = root.querySelector(`#${id}`); if (element) element.value = value; }
