const merchantRecords = new Map();
let availablePlans = [];

export const feature = {
    id: 'merchants',
    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (!target) return;
            const action = target.dataset.action;
            if (action === 'search-merchants') void loadMerchants(context);
            if (action === 'batch-clean-test') void handleBatchCleanTest(context);
            if (action === 'open-merchant') void openMerchantEditor(context, target.dataset.merchantId);
            if (action === 'close-merchant') closeMerchantEditor(root);
            if (action === 'delete-merchant') void handleDeleteMerchant(context, target.dataset.merchantId);
            if (action === 'open-balance') openBalanceModal(root, target.dataset.merchantId);
            if (action === 'close-balance') closeBalanceModal(root);
            if (action === 'submit-balance') void submitBalance(context);
        }, { signal });

        // 生成随机密码与密钥
        root.querySelector('#btn-generate-password')?.addEventListener('click', () => {
            const randomPass = generateRandomPassword();
            const passInput = root.querySelector('#merchant-password');
            if (passInput) {
                passInput.value = randomPass;
                passInput.focus();
                ui.showToast('已随机生成高强密码');
            }
        }, { signal });

        root.querySelector('#btn-generate-key')?.addEventListener('click', () => {
            const randomKey = generateRandomHex(16);
            const keyInput = root.querySelector('#merchant-api-key');
            if (keyInput) {
                keyInput.value = randomKey;
                keyInput.focus();
                ui.showToast('已生成新 API 密钥，保存后生效');
            }
        }, { signal });

        root.querySelector('#btn-copy-key')?.addEventListener('click', () => {
            const keyInput = root.querySelector('#merchant-api-key');
            const key = keyInput?.value?.trim();
            if (key) {
                navigator.clipboard.writeText(key).then(() => {
                    ui.showToast('API 密钥已复制到剪贴板');
                }).catch(() => {
                    ui.showToast('复制失败，请手动选择复制', 'error');
                });
            } else {
                ui.showToast('当前暂无可复制的密钥', 'error');
            }
        }, { signal });

        root.querySelector('#merchant-search')?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') void loadMerchants(context);
        }, { signal });

        root.querySelector('[data-role="merchant-form"]')
            ?.addEventListener('submit', (event) => void submitMerchant(context, event), { signal });

        ui.safeCreateIcons();
        await loadMerchants(context);
    },
    unmount() {
        merchantRecords.clear();
    },
};

async function loadMerchants(context) {
    const { root, api, ui, signal } = context;
    const body = root.querySelector('#merchant-table-body');
    const keyword = root.querySelector('#merchant-search')?.value.trim() || '';
    if (!body) return;
    try {
        const response = await api.adminFetch(`/api/admin/merchant/list?page_size=50&keyword=${encodeURIComponent(keyword)}`, { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '商户加载失败');
        if (signal.aborted) return;
        const rows = Array.isArray(payload.data.list) ? payload.data.list : (Array.isArray(payload.data.data) ? payload.data.data : []);
        merchantRecords.clear();
        rows.forEach((item) => merchantRecords.set(Number(item.id), item));

        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="9" class="p-8 text-center text-slate-400">暂无匹配的商户账号</td></tr>';
            return;
        }

        body.innerHTML = rows.map((item) => {
            const pidBadge = `<span class="font-mono font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">${ui.escapeHtml(item.pid)}</span>`;
            
            // 套餐标签渲染
            let planBadge = '<span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-600">默认基础</span>';
            if (item.is_vip) {
                planBadge = `<span class="px-2 py-0.5 rounded text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200/60 flex items-center gap-1 w-fit"><span class="text-amber-500">👑</span> ${ui.escapeHtml(item.plan_name)}</span>`;
            } else if (item.plan_name === '体验套餐') {
                planBadge = `<span class="px-2 py-0.5 rounded text-[11px] font-medium bg-purple-50 text-purple-700">${ui.escapeHtml(item.plan_name)}</span>`;
            }

            const statusBadge = Number(item.status) === 1
                ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>正常</span>'
                : '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 text-rose-600"><span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>停用</span>';

            const isProtected = (item.pid === '1000' || Number(item.id) === 1000);
            const deleteBtn = isProtected
                ? '<span class="px-2 py-1 text-slate-300 text-[11px] cursor-not-allowed" title="系统主商户受保护禁止删除">主账号</span>'
                : `<button type="button" data-action="delete-merchant" data-merchant-id="${Number(item.id)}" class="px-2 py-1 bg-rose-50 text-rose-600 hover:bg-rose-100 rounded-lg font-bold transition-colors text-[11px]">删除</button>`;

            return `
            <tr class="hover:bg-slate-50/80 transition-colors">
                <td class="p-3">${pidBadge}</td>
                <td class="p-3">
                    <div class="font-bold text-slate-800">${ui.escapeHtml(item.name)}</div>
                    ${item.ip_white ? `<div class="text-[10px] text-slate-400 font-mono truncate max-w-[120px]" title="白名单: ${ui.escapeHtml(item.ip_white)}">IP: ${ui.escapeHtml(item.ip_white)}</div>` : ''}
                </td>
                <td class="p-3">
                    ${planBadge}
                    ${item.plan_expire_format ? `<div class="text-[10px] text-slate-400 mt-0.5">到期: ${ui.escapeHtml(item.plan_expire_format)}</div>` : ''}
                </td>
                <td class="p-3 font-bold text-emerald-600 font-mono">¥ ${Number(item.money || 0).toFixed(2)}</td>
                <td class="p-3 text-slate-600 font-mono font-medium">${(Number(item.rate || 0) * 100).toFixed(2)}%</td>
                <td class="p-3 text-slate-600">
                    <span class="text-blue-600 font-bold">${item.channel_count || 0}</span> 通道 / 
                    <span class="text-slate-700 font-bold">${item.order_count || 0}</span> 订单
                </td>
                <td class="p-3">${statusBadge}</td>
                <td class="p-3 text-slate-400 text-[11px] whitespace-nowrap">${ui.escapeHtml(item.create_time || '-')}</td>
                <td class="p-3 text-right space-x-1.5 whitespace-nowrap">
                    <button type="button" data-action="open-merchant" data-merchant-id="${Number(item.id)}" class="px-2 py-1 bg-purple-50 text-purple-700 hover:bg-purple-100 rounded-lg font-bold transition-colors text-[11px]">编辑/配置</button>
                    <button type="button" data-action="open-balance" data-merchant-id="${Number(item.id)}" class="px-2 py-1 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 rounded-lg font-bold transition-colors text-[11px]">余额调整</button>
                    ${deleteBtn}
                </td>
            </tr>`;
        }).join('');
        ui.safeCreateIcons();
    } catch (error) {
        if (error?.name !== 'AbortError') body.innerHTML = `<tr><td colspan="9" class="p-6 text-center text-rose-500">${ui.escapeHtml(error.message)}</td></tr>`;
    }
}

async function openMerchantEditor(context, id = 0) {
    const { root, api, ui, signal } = context;
    const numId = Number(id);
    const editor = root.querySelector('#merchant-editor');
    if (!editor) return;

    setText(root, 'merchant-editor-title', numId > 0 ? '编辑商户配置' : '新增商户');
    setValue(root, 'merchant-id', numId);
    setValue(root, 'merchant-password', '');
    setValue(root, 'merchant-api-key', '');
    setValue(root, 'merchant-ip-white', '');

    if (numId > 0) {
        try {
            const resp = await api.adminFetch(`/api/admin/merchant/detail?id=${numId}`, { signal });
            const payload = await resp.json();
            if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '获取商户详情失败');
            const data = payload.data;
            availablePlans = data.plans || [];

            setValue(root, 'merchant-name', data.name || '');
            setValue(root, 'merchant-pid', data.pid || '');
            root.querySelector('#merchant-pid').disabled = true;
            setValue(root, 'merchant-rate', data.rate || '0.0200');
            setValue(root, 'merchant-status', String(data.status ?? 1));
            setValue(root, 'merchant-api-key', data.key || '');
            setValue(root, 'merchant-plan-expire', data.plan_expire_date || '');
            setValue(root, 'merchant-channel-quota', data.channel_quota || 0);
            setValue(root, 'merchant-ip-white', data.ip_white || '');

            // 渲染套餐选择下拉框
            renderPlanSelect(root, data.plan_id);
        } catch (e) {
            ui.showToast(e.message, 'error');
            return;
        }
    } else {
        setValue(root, 'merchant-name', '');
        setValue(root, 'merchant-pid', '');
        root.querySelector('#merchant-pid').disabled = false;
        setValue(root, 'merchant-rate', '0.0200');
        setValue(root, 'merchant-status', '1');
        setValue(root, 'merchant-plan-expire', '');
        setValue(root, 'merchant-channel-quota', '0');
        setValue(root, 'merchant-api-key', generateRandomHex(16));
        setValue(root, 'merchant-password', generateRandomPassword());
        renderPlanSelect(root, 0);
    }

    editor.classList.remove('hidden');
    editor.classList.add('flex');
    ui.safeCreateIcons();
}

function renderPlanSelect(root, currentPlanId = 0) {
    const planSelect = root.querySelector('#merchant-plan-id');
    if (!planSelect) return;
    
    let html = '<option value="0">无套餐 / 默认基础</option>';
    availablePlans.forEach(p => {
        const isSelected = Number(p.id) === Number(currentPlanId);
        html += `<option value="${p.id}" ${isSelected ? 'selected' : ''}>${p.name} (¥${p.price})</option>`;
    });
    planSelect.innerHTML = html;
}

function closeMerchantEditor(root) {
    const editor = root.querySelector('#merchant-editor');
    editor?.classList.add('hidden');
    editor?.classList.remove('flex');
}

async function submitMerchant(context, event) {
    event.preventDefault();
    const { root, api, ui, signal } = context;
    const id = Number(root.querySelector('#merchant-id')?.value || 0);
    const value = (selector) => root.querySelector(selector)?.value || '';

    const values = {
        id: String(id),
        name: value('#merchant-name').trim(),
        pid: id ? '' : value('#merchant-pid').trim(),
        rate: value('#merchant-rate'),
        status: value('#merchant-status'),
        plan_id: value('#merchant-plan-id'),
        plan_expire_date: value('#merchant-plan-expire'),
        channel_quota: value('#merchant-channel-quota'),
        login_password: value('#merchant-password'),
        key: value('#merchant-api-key').trim(),
        ip_white: value('#merchant-ip-white').trim(),
    };

    try {
        const response = await api.adminFetch('/api/admin/merchant/save', {
            method: 'POST',
            body: new URLSearchParams(values),
            signal
        });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '商户保存失败');
        closeMerchantEditor(root);
        ui.showToast(payload.msg || '商户保存成功！');
        await loadMerchants(context);

        if (payload.data?.initial_password) {
            window.alert(`新商户开户成功！请妥善记录并向商户交付以下凭据：\n\n商户 PID：${payload.data.pid}\n初始登录密码：${payload.data.initial_password}\nAPI 对接密钥：${payload.data.api_key}`);
        }
    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast(error.message || '商户保存失败', 'error');
    }
}

async function handleDeleteMerchant(context, id) {
    const { root, api, ui, signal } = context;
    const item = merchantRecords.get(Number(id));
    if (!item) return;

    const confirmed = await ui.showConfirm(
        '删除商户确认',
        `确定要永久删除商户【${item.name}】（PID: ${item.pid}）吗？\n删除后该商户的通道、密钥与登录凭据将被同步清理。`,
        true
    );
    if (!confirmed || signal.aborted) return;

    try {
        const response = await api.adminFetch('/api/admin/merchant/delete', {
            method: 'POST',
            body: new URLSearchParams({ id: String(id) }),
            signal
        });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '删除失败');
        ui.showToast(payload.msg || '商户已成功删除');
        await loadMerchants(context);
    } catch (e) {
        if (e?.name !== 'AbortError') ui.showToast(e.message, 'error');
    }
}

async function handleBatchCleanTest(context) {
    const { root, api, ui, signal } = context;
    const confirmed = await ui.showConfirm(
        '一键清理闲置测试商户',
        '系统将自动检索并清理所有【无订单数据、无绑定通道】的测试商户账号（系统主商户 1000 受到绝对保护不会被删除）。\n确定执行一键清理？',
        true
    );
    if (!confirmed || signal.aborted) return;

    try {
        const response = await api.adminFetch('/api/admin/merchant/batch_clean_test', {
            method: 'POST',
            signal
        });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '清理失败');
        ui.showToast(payload.msg);
        await loadMerchants(context);
    } catch (e) {
        if (e?.name !== 'AbortError') ui.showToast(e.message, 'error');
    }
}

function openBalanceModal(root, id) {
    const item = merchantRecords.get(Number(id));
    if (!item) return;
    setValue(root, 'balance-merchant-id', item.id);
    setText(root, 'balance-merchant-name', `${item.name}（PID: ${item.pid}）`);
    setText(root, 'balance-current', `¥ ${Number(item.money || 0).toFixed(2)}`);
    setValue(root, 'balance-amount', '');
    setValue(root, 'balance-memo', '');
    const addRadio = root.querySelector('#balance-type-add');
    if (addRadio) addRadio.checked = true;
    const modal = root.querySelector('#balance-adjust-modal');
    modal?.classList.remove('hidden');
    modal?.classList.add('flex');
}

function closeBalanceModal(root) {
    const modal = root.querySelector('#balance-adjust-modal');
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
}

async function submitBalance({ root, api, ui, signal }) {
    const id     = root.querySelector('#balance-merchant-id')?.value || '0';
    const amount = root.querySelector('#balance-amount')?.value || '';
    const type   = root.querySelector('input[name="balance-type"]:checked')?.value || 'add';
    const memo   = root.querySelector('#balance-memo')?.value?.trim() || '';

    if (!amount || parseFloat(amount) <= 0) { ui.showToast('请填写有效的调整金额', 'error'); return; }
    if (!memo) { ui.showToast('请填写调整备注，便于审计追溯', 'error'); return; }

    const label = type === 'add' ? `充值 +¥${parseFloat(amount).toFixed(2)}` : `扣减 -¥${parseFloat(amount).toFixed(2)}`;
    const confirmed = await ui.showConfirm('余额调整确认', `确定对该商户执行【${label}】操作？\n备注：${memo}`, type === 'sub');
    if (!confirmed || signal.aborted) return;

    try {
        const response = await api.adminFetch('/api/admin/merchant/adjust_balance', {
            method: 'POST',
            body: new URLSearchParams({ id, amount, type, memo }),
            signal,
        });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '余额调整失败');
        closeBalanceModal(root);
        ui.showToast(`调整成功！最新余额：¥ ${parseFloat(payload.data?.after || 0).toFixed(2)}`);
        await loadMerchants({ root, api, ui, signal });
    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast(error.message, 'error');
    }
}

function generateRandomPassword() {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    let res = '';
    for (let i = 0; i < 12; i++) {
        res += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return res;
}

function generateRandomHex(len = 16) {
    const chars = '0123456789abcdef';
    let res = '';
    for (let i = 0; i < len * 2; i++) {
        res += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return res;
}

function setText(root, id, value) { const element = root.querySelector(`#${id}`); if (element) element.textContent = value; }
function setValue(root, id, value) { const element = root.querySelector(`#${id}`); if (element) element.value = value; }
