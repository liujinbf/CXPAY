const merchantRecords = new Map();

export const feature = {
    id: 'merchants',
    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (!target) return;
            if (target.dataset.action === 'search-merchants') void loadMerchants(context);
            if (target.dataset.action === 'open-merchant') openMerchantEditor(root, target.dataset.merchantId);
            if (target.dataset.action === 'close-merchant') closeMerchantEditor(root);
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

async function loadMerchants({ root, api, ui, signal }) {
    const body = root.querySelector('#merchant-table-body');
    const keyword = root.querySelector('#merchant-search')?.value.trim() || '';
    if (!body) return;
    try {
        const response = await api.adminFetch(`/api/admin/merchant/list?page_size=50&keyword=${encodeURIComponent(keyword)}`, { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '商户加载失败');
        if (signal.aborted) return;
        const rows = Array.isArray(payload.data.data) ? payload.data.data : [];
        merchantRecords.clear();
        rows.forEach((item) => merchantRecords.set(Number(item.id), item));
        body.innerHTML = rows.length ? rows.map((item) => `<tr class="hover:bg-slate-50">
            <td class="p-3 font-mono font-bold text-blue-600">${ui.escapeHtml(item.pid)}</td>
            <td class="p-3 font-bold text-slate-800">${ui.escapeHtml(item.name)}</td>
            <td class="p-3 font-bold text-emerald-600">&#165; ${Number(item.money || 0).toFixed(2)}</td>
            <td class="p-3 text-slate-600">${(Number(item.rate || 0) * 100).toFixed(2)}%</td>
            <td class="p-3">${Number(item.status) === 1 ? '<span class="text-emerald-600 font-bold">启用</span>' : '<span class="text-rose-500 font-bold">停用</span>'}</td>
            <td class="p-3 text-slate-400 text-[11px]">${item.create_time ? new Date(Number(item.create_time) * 1000).toLocaleDateString() : '-'}</td>
            <td class="p-3 text-right"><button type="button" data-action="open-merchant" data-merchant-id="${Number(item.id)}" class="text-blue-600 font-bold">编辑</button></td>
        </tr>`).join('') : '<tr><td colspan="7" class="p-6 text-center text-slate-400">暂无商户</td></tr>';
    } catch (error) {
        if (error?.name !== 'AbortError') body.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-rose-500">${ui.escapeHtml(error.message)}</td></tr>`;
    }
}

function openMerchantEditor(root, id = 0) {
    const item = merchantRecords.get(Number(id));
    setText(root, 'merchant-editor-title', item ? '编辑商户' : '新增商户');
    setValue(root, 'merchant-id', item?.id || 0);
    setValue(root, 'merchant-name', item?.name || '');
    setValue(root, 'merchant-pid', item?.pid || '');
    root.querySelector('#merchant-pid').disabled = Boolean(item);
    setValue(root, 'merchant-rate', item?.rate || '0.0200');
    setValue(root, 'merchant-status', String(item?.status ?? 1));
    setValue(root, 'merchant-password', '');
    setValue(root, 'merchant-api-key', '');
    setValue(root, 'merchant-ip-white', item?.ip_white || '');
    root.querySelector('#merchant-editor')?.classList.remove('hidden');
    root.querySelector('#merchant-editor')?.classList.add('flex');
}

function closeMerchantEditor(root) {
    root.querySelector('#merchant-editor')?.classList.add('hidden');
    root.querySelector('#merchant-editor')?.classList.remove('flex');
}

async function submitMerchant(context, event) {
    event.preventDefault();
    const { root, api, ui, signal } = context;
    const id = Number(root.querySelector('#merchant-id')?.value || 0);
    const value = (selector) => root.querySelector(selector)?.value || '';
    const values = {
        id: String(id), name: value('#merchant-name').trim(), pid: id ? '' : value('#merchant-pid').trim(),
        rate: value('#merchant-rate'), status: value('#merchant-status'), login_password: value('#merchant-password'),
        key: value('#merchant-api-key').trim(), ip_white: value('#merchant-ip-white').trim(),
    };
    try {
        const response = await api.adminFetch('/api/admin/merchant/save', { method: 'POST', body: new URLSearchParams(values), signal });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '商户保存失败');
        closeMerchantEditor(root);
        await loadMerchants(context);
        if (payload.data?.initial_password) {
            window.alert(`商户创建成功，请立即安全交付并保存以下一次性凭据：\nPID：${payload.data.pid}\n登录密码：${payload.data.initial_password}\nAPI 密钥：${payload.data.api_key}`);
        } else if (payload.data?.api_key) {
            window.alert(`商户保存成功，API 密钥已轮换为：\n${payload.data.api_key}`);
        } else ui.showToast(payload.msg || '商户保存成功');
    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast(error.message || '商户保存失败', 'error');
    }
}

function setText(root, id, value) { const element = root.querySelector(`#${id}`); if (element) element.textContent = value; }
function setValue(root, id, value) { const element = root.querySelector(`#${id}`); if (element) element.value = value; }
