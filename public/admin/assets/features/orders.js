let currentPage = 1;
let lastPage = 1;
let orderContext = null;

export const feature = {
    id: 'orders',
    async mount(context) {
        orderContext = context;
        const { root, ui, signal } = context;

        // 日期快捷键
        root.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-action]');
            const action = btn?.dataset.action;
            const tradeNo = event.target.closest('[data-trade-no]')?.dataset.tradeNo;

            if (action === 'search-orders') { currentPage = 1; void loadOrders(context); }
            if (action === 'manual-pay')   void mutateOrder(context, 'manual_pay', tradeNo);
            if (action === 'close-order')  void mutateOrder(context, 'close', tradeNo);
            if (action === 'delete-order') void mutateOrder(context, 'delete', tradeNo);
            if (action === 'force-notify') void mutateOrder(context, 'force_notify', tradeNo);
            if (action === 'batch-clean-orders') void batchCleanOrders(context);
            if (action === 'date-today')     setDateRange(root, 0, 0);
            if (action === 'date-yesterday') setDateRange(root, -1, -1);
            if (action === 'date-7d')        setDateRange(root, -6, 0);
            if (action === 'date-clear')     clearDate(root);
            if (action === 'order-prev-page') { if (currentPage > 1) { currentPage--; void loadOrders(context); } }
            if (action === 'order-next-page') { if (currentPage < lastPage) { currentPage++; void loadOrders(context); } }
        }, { signal });

        root.querySelector('#admin-order-search')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { currentPage = 1; void loadOrders(context); } }, { signal });
        root.querySelector('#admin-order-status')?.addEventListener('change', () => { currentPage = 1; void loadOrders(context); }, { signal });
        root.querySelector('#order-page-size')?.addEventListener('change', () => { currentPage = 1; void loadOrders(context); }, { signal });

        ui.safeCreateIcons();
        await loadOrders(context);
    },
    unmount() { orderContext = null; },
};

async function loadOrders(context) {
    const { root, api, ui, signal } = context;
    const body = root.querySelector('#order-table-body');
    if (!body) return;

    const tradeNo   = root.querySelector('#admin-order-search')?.value.trim() || '';
    const status    = root.querySelector('#admin-order-status')?.value || '';
    const dateStart = root.querySelector('#order-date-start')?.value || '';
    const dateEnd   = root.querySelector('#order-date-end')?.value || '';
    const pageSize  = root.querySelector('#order-page-size')?.value || '20';

    const params = new URLSearchParams({
        page_size: pageSize,
        page: String(currentPage),
        trade_no: tradeNo,
        status,
    });
    if (dateStart) params.set('date_start', dateStart);
    if (dateEnd)   params.set('date_end',   dateEnd);

    body.innerHTML = '<tr><td colspan="8" class="p-6 text-center text-slate-400">加载中...</td></tr>';

    try {
        const response = await api.adminFetch(`/api/admin/order/list?${params}`, { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '订单加载失败');

        const rows   = Array.isArray(payload.data.data) ? payload.data.data : [];
        const total  = payload.data.total ?? rows.length;
        lastPage     = payload.data.last_page ?? 1;
        currentPage  = payload.data.current_page ?? currentPage;

        // 结果计数
        const countEl = root.querySelector('#order-result-count');
        if (countEl) countEl.textContent = `共 ${total.toLocaleString()} 条`;

        // 分页
        const pagEl = root.querySelector('#order-pagination');
        if (pagEl) {
            if (total > 0) {
                pagEl.classList.remove('hidden');
                const infoEl = root.querySelector('#order-page-info');
                if (infoEl) infoEl.textContent = `第 ${currentPage} / ${lastPage} 页，共 ${total} 条`;
                const indEl = root.querySelector('#order-page-indicator');
                if (indEl) indEl.textContent = String(currentPage);
                const prevBtn = root.querySelector('[data-action="order-prev-page"]');
                const nextBtn = root.querySelector('[data-action="order-next-page"]');
                if (prevBtn) prevBtn.disabled = currentPage <= 1;
                if (nextBtn) nextBtn.disabled = currentPage >= lastPage;
            } else {
                pagEl.classList.add('hidden');
            }
        }

        body.innerHTML = rows.length
            ? rows.map((order) => renderOrder(order, ui)).join('')
            : '<tr><td colspan="8" class="p-6 text-center text-slate-400">暂无符合条件的订单</td></tr>';

    } catch (error) {
        if (error?.name !== 'AbortError')
            body.innerHTML = `<tr><td colspan="8" class="p-6 text-center text-rose-500">${ui.escapeHtml(error.message)}</td></tr>`;
    }
}

function renderOrder(order, ui) {
    const tradeNo = ui.escapeHtml(order.trade_no || '');
    const statusNum = Number(order.status);
    const stateMap = {
        0: ['待支付',    'bg-amber-100 text-amber-700'],
        1: ['已支付',    'bg-emerald-100 text-emerald-700'],
        2: ['已关闭',    'bg-slate-100 text-slate-500'],
        3: ['已退款',    'bg-rose-100 text-rose-600'],
    };
    const [stateText, stateCls] = stateMap[statusNum] ?? ['未知', 'bg-slate-100 text-slate-500'];
    const amount = Number(order.amount || 0).toFixed(2);
    const price  = Number(order.price  || 0).toFixed(2);
    const actions = statusNum === 0
        ? `<button data-action="manual-pay"   data-trade-no="${tradeNo}" class="px-2 py-1 bg-amber-50 text-amber-700 rounded-lg font-bold hover:bg-amber-100 transition-colors cursor-pointer">补单</button>
           <button data-action="close-order"  data-trade-no="${tradeNo}" class="px-2 py-1 bg-slate-100 text-slate-600 rounded-lg font-bold hover:bg-slate-200 transition-colors ml-1 cursor-pointer">关闭</button>
           <button data-action="delete-order" data-trade-no="${tradeNo}" class="px-2 py-1 bg-rose-50 text-rose-600 rounded-lg font-bold hover:bg-rose-100 transition-colors ml-1 cursor-pointer">删除</button>`
        : (statusNum === 1
            ? `<button data-action="force-notify" data-trade-no="${tradeNo}" class="px-2 py-1 bg-sky-50 text-sky-700 rounded-lg font-bold hover:bg-sky-100 transition-colors cursor-pointer">重发通知</button>`
            : `<button data-action="manual-pay"   data-trade-no="${tradeNo}" class="px-2 py-1 bg-amber-50 text-amber-700 rounded-lg font-bold hover:bg-amber-100 transition-colors cursor-pointer">补单</button>
               <button data-action="delete-order" data-trade-no="${tradeNo}" class="px-2 py-1 bg-rose-50 text-rose-600 rounded-lg font-bold hover:bg-rose-100 transition-colors ml-1 cursor-pointer">删除</button>`);

    return `<tr class="hover:bg-slate-50/80 transition-colors">
        <td class="p-3 font-mono text-blue-600 text-[11px]">${tradeNo}</td>
        <td class="p-3 font-mono text-slate-600">${ui.escapeHtml(order.merchant_pid || order.merchant_id || '-')}</td>
        <td class="p-3 font-bold text-slate-700">¥ ${amount}</td>
        <td class="p-3 font-bold text-emerald-700">¥ ${price}</td>
        <td class="p-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${stateCls}">${stateText}</span></td>
        <td class="p-3 text-slate-400 text-[11px]">${order.create_time ? new Date(Number(order.create_time) * 1000).toLocaleString() : '-'}</td>
        <td class="p-3 text-slate-400 text-[11px]">${order.pay_time ? new Date(Number(order.pay_time) * 1000).toLocaleString() : '-'}</td>
        <td class="p-3 text-right whitespace-nowrap">${actions}</td>
    </tr>`;
}

async function mutateOrder(context, action, tradeNo) {
    if (!tradeNo) return;
    const { api, ui, signal } = context;
    const labels = { manual_pay: '人工补单核销', close: '手动关闭订单', delete: '删除未完成订单', force_notify: '重发支付通知' };
    const isDanger = action === 'close' || action === 'delete';
    const confirmed = await ui.showConfirm(labels[action] || '订单操作确认', `确认对订单 ${tradeNo} 执行此操作？`, isDanger);
    if (!confirmed || signal.aborted) return;

    const values = { trade_no: tradeNo };
    if (action === 'manual_pay') values.remark = '管理员后台人工补单';

    const apiAction = action === 'force_notify' ? 'force_notify' : action;
    try {
        const response = await api.adminFetch(`/api/admin/order/${apiAction}`, {
            method: 'POST',
            body: new URLSearchParams(values),
            signal,
        });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '操作失败');
        ui.showToast(payload.msg || '操作成功');
        await loadOrders(context);
    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast(error.message, 'error');
    }
}

async function batchCleanOrders(context) {
    const { api, ui, signal } = context;
    const confirmed = await ui.showConfirm(
        '一键清理未完成订单',
        '确定要一键清理平台 5 分钟前所有「待支付」与「已关闭/超时」的废弃订单记录吗？\n已成功付款的交易订单不受影响。',
        true
    );
    if (!confirmed || signal.aborted) return;

    try {
        const response = await api.adminFetch('/api/admin/order/batch_clean', {
            method: 'POST',
            body: new URLSearchParams({ before_minutes: '5' }),
            signal,
        });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '清理失败');
        ui.showToast(payload.msg || '清理成功');
        await loadOrders(context);
    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast(error.message, 'error');
    }
}

// ─── 日期辅助 ──────────────────────────────────────────────
function setDateRange(root, startOffset, endOffset) {
    const today = new Date();
    const s = new Date(today); s.setDate(today.getDate() + startOffset);
    const e = new Date(today); e.setDate(today.getDate() + endOffset);
    setVal(root, 'order-date-start', fmtDate(s));
    setVal(root, 'order-date-end',   fmtDate(e));
    currentPage = 1;
    if (orderContext) void loadOrders(orderContext);
}
function clearDate(root) {
    setVal(root, 'order-date-start', '');
    setVal(root, 'order-date-end',   '');
    currentPage = 1;
    if (orderContext) void loadOrders(orderContext);
}
function fmtDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function setVal(root, id, v) { const el = root.querySelector(`#${id}`); if (el) el.value = v; }
