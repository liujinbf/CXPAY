export const feature = {
    id: 'callbill',
    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (target?.dataset.action === 'refresh-callbill') void loadReviews(context);
            if (target?.dataset.action === 'match-bill') void reviewBill(context, target, true);
            if (target?.dataset.action === 'ignore-bill') void reviewBill(context, target, false);
        }, { signal });
        ui.safeCreateIcons();
        await loadReviews(context);
    },
    unmount() {},
};

async function loadReviews({ root, api, ui, signal }) {
    const body = root.querySelector('#callbill-review-body');
    if (!body) return;
    try {
        const response = await api.adminFetch('/api/admin/callbill/review_list?page_size=50', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '账单加载失败');
        const rows = Array.isArray(payload.data.data) ? payload.data.data : [];
        body.innerHTML = rows.length ? rows.map((bill) => renderBill(bill, ui)).join('') : '<tr><td colspan="5" class="p-6 text-center text-emerald-600">当前没有待复核账单</td></tr>';
    } catch (error) { if (error?.name !== 'AbortError') body.innerHTML = `<tr><td colspan="5" class="p-6 text-center text-rose-500">${ui.escapeHtml(error.message)}</td></tr>`; }
}

function renderBill(bill, ui) {
    const candidates = Array.isArray(bill.candidates) ? bill.candidates : [];
    const suggested = candidates.length === 1 ? candidates[0].trade_no : '';
    const attrs = `data-bill-id="${ui.escapeHtml(String(bill.id))}" data-channel-id="${Number(bill.cloud_channel_id || 0)}" data-source="${bill.source === 'cloud' ? 'cloud' : 'local'}" data-trade-no="${ui.escapeHtml(suggested)}"`;
    return `<tr><td class="p-3">#${ui.escapeHtml(String(bill.id))}<div>${ui.escapeHtml(bill.source_bill_id || '')}</div></td><td class="p-3">¥ ${Number(bill.money || 0).toFixed(2)}<div>${ui.escapeHtml(bill.remark || '')}</div></td><td class="p-3">${candidates.map((item) => ui.escapeHtml(item.trade_no)).join('<br>') || '无安全候选'}</td><td class="p-3">${ui.escapeHtml(String(bill.status))}</td><td class="p-3 text-right"><button data-action="match-bill" ${attrs}>匹配订单</button><button data-action="ignore-bill" ${attrs}>忽略</button></td></tr>`;
}

async function reviewBill(context, target, matches) {
    const { api, ui, signal } = context;
    const values = { bill_id: target.dataset.billId, event_id: target.dataset.billId, channel_id: target.dataset.channelId, source: target.dataset.source };
    if (matches) {
        const tradeNo = window.prompt('请输入要匹配的平台订单号：', target.dataset.tradeNo || '');
        if (!tradeNo) return;
        values.trade_no = tradeNo.trim(); values.note = 'CXPAY 管理后台人工复核';
    } else {
        const reason = window.prompt('请输入忽略原因：', '确认不是有效到账');
        if (!reason) return;
        values.reason = reason.trim();
    }
    if (!await ui.showConfirm('账单复核确认', `确认${matches ? '匹配' : '忽略'}账单 #${values.bill_id}？`, true) || signal.aborted) return;
    try {
        const endpoint = matches ? 'review_match' : 'review_ignore';
        const response = await api.adminFetch(`/api/admin/callbill/${endpoint}`, { method: 'POST', body: new URLSearchParams(values), signal });
        const payload = await response.json();
        if (payload.code !== 1) throw new Error(payload.msg || '操作失败');
        ui.showToast(payload.msg || '操作成功'); await loadReviews(context);
    } catch (error) { if (error?.name !== 'AbortError') ui.showToast(error.message, 'error'); }
}
