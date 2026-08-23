let trendChart = null;
let channelChart = null;

export const feature = {
    id: 'report',

    async mount(context) {
        const { root, ui, signal } = context;

        // 默认时间范围：最近 30 天
        const today = new Date();
        const ago30 = new Date(today);
        ago30.setDate(today.getDate() - 30);
        setVal(root, 'report-start', formatDate(ago30));
        setVal(root, 'report-end', formatDate(today));

        root.addEventListener('click', (event) => {
            const action = event.target.closest('[data-action]')?.dataset.action;
            if (action === 'load-report') void loadReport(context);
            if (action === 'export-csv') void exportCsv(context);
        }, { signal });

        window.addEventListener('resize', () => {
            trendChart?.resize();
            channelChart?.resize();
        }, { signal });

        ui.safeCreateIcons();
        await loadReport(context);
    },

    unmount() {
        trendChart?.dispose();
        trendChart = null;
        channelChart?.dispose();
        channelChart = null;
    },
};

async function loadReport(context) {
    await Promise.all([
        loadTrend(context),
        loadChannelDist(context),
        loadMerchantRank(context),
    ]);
}

async function loadTrend({ root, api, ui, signal }) {
    const start = getVal(root, 'report-start');
    const end = getVal(root, 'report-end');
    const period = getVal(root, 'report-period');
    const loadingEl = root.querySelector('#report-trend-loading');
    if (loadingEl) loadingEl.textContent = '加载中...';

    try {
        const params = new URLSearchParams({ start, end, period });
        const response = await api.adminFetch(`/api/admin/report/trend?${params}`, { signal });
        const payload = await response.json();
        if (signal.aborted) return;

        if (payload.code !== 1) {
            if (loadingEl) loadingEl.textContent = payload.msg || '加载失败';
            ui.showToast(payload.msg || '趋势数据加载失败', 'error');
            return;
        }

        const rows = Array.isArray(payload.data) ? payload.data : [];
        if (loadingEl) loadingEl.textContent = `共 ${rows.length} 个周期`;

        // 汇总指标
        let totalAmount = 0, totalPaid = 0, totalFee = 0, totalClosed = 0;
        rows.forEach((r) => {
            totalAmount += parseFloat(r.amount || 0);
            totalPaid += Number(r.paid || 0);
            totalFee += parseFloat(r.fee || 0);
            totalClosed += Number(r.closed || 0);
        });
        const settled = totalPaid + totalClosed;
        const successRate = settled > 0 ? ((totalPaid / settled) * 100).toFixed(2) + '%' : '100.00%';

        setText(root, 'report-total-amount', `¥ ${totalAmount.toFixed(2)}`);
        setText(root, 'report-paid-count', `${totalPaid.toLocaleString()} 笔`);
        setText(root, 'report-total-fee', `¥ ${totalFee.toFixed(2)}`);
        setText(root, 'report-success-rate', successRate);

        // ECharts 趋势图
        const chartEl = root.querySelector('#report-trend-chart');
        if (chartEl && window.echarts) {
            trendChart?.dispose();
            trendChart = window.echarts.init(chartEl);
            trendChart.setOption({
                tooltip: {
                    trigger: 'axis',
                    formatter: (items) =>
                        `${items[0].name}<br/>流水：¥${parseFloat(items[0].value).toFixed(2)}<br/>成功：${items[1]?.value || 0} 笔`,
                },
                legend: { data: ['成功金额', '成功笔数'], bottom: 0, textStyle: { fontSize: 11, color: '#94a3b8' } },
                grid: { top: 16, right: 16, bottom: 40, left: 70 },
                xAxis: {
                    type: 'category',
                    data: rows.map((r) => r.period),
                    axisLabel: { color: '#94a3b8', fontSize: 11, rotate: rows.length > 20 ? 30 : 0 },
                    axisLine: { lineStyle: { color: '#e2e8f0' } },
                },
                yAxis: [
                    {
                        type: 'value', name: '金额',
                        axisLabel: { formatter: '¥{value}', color: '#94a3b8', fontSize: 11 },
                        splitLine: { lineStyle: { color: '#f1f5f9' } },
                    },
                    {
                        type: 'value', name: '笔数',
                        axisLabel: { color: '#94a3b8', fontSize: 11 },
                        splitLine: { show: false },
                    },
                ],
                series: [
                    {
                        name: '成功金额', type: 'bar', yAxisIndex: 0,
                        data: rows.map((r) => parseFloat(r.amount || 0)),
                        barMaxWidth: 40,
                        itemStyle: { color: '#6366f1', borderRadius: [4, 4, 0, 0] },
                    },
                    {
                        name: '成功笔数', type: 'line', yAxisIndex: 1,
                        data: rows.map((r) => Number(r.paid || 0)),
                        smooth: true,
                        lineStyle: { color: '#10b981', width: 2 },
                        itemStyle: { color: '#10b981' },
                        symbol: 'circle', symbolSize: 5,
                    },
                ],
            });
        }
    } catch (error) {
        if (error?.name !== 'AbortError') {
            if (loadingEl) loadingEl.textContent = '加载失败';
            ui.showToast('趋势数据加载失败', 'error');
        }
    }
}

async function loadChannelDist({ root, api, ui, signal }) {
    const start = getVal(root, 'report-start');
    const end = getVal(root, 'report-end');
    const tableBody = root.querySelector('#report-channel-table');
    if (!tableBody) return;

    try {
        const params = new URLSearchParams({ start, end });
        const response = await api.adminFetch(`/api/admin/report/channel_dist?${params}`, { signal });
        const payload = await response.json();
        if (signal.aborted) return;
        if (payload.code !== 1) return;

        const rows = Array.isArray(payload.data) ? payload.data : [];

        // 表格
        tableBody.innerHTML = rows.length
            ? rows.map((r) => `<tr class="hover:bg-slate-50/80 transition-colors">
                <td class="p-2 font-medium text-slate-700">${ui.escapeHtml(r.channel || '--')}</td>
                <td class="p-2 text-right">${Number(r.paid || 0).toLocaleString()}</td>
                <td class="p-2 text-right font-bold text-indigo-700">¥${parseFloat(r.amount || 0).toFixed(2)}</td>
            </tr>`).join('')
            : '<tr><td colspan="3" class="p-4 text-center text-slate-400">暂无数据</td></tr>';

        // 饼图
        const chartEl = root.querySelector('#report-channel-chart');
        if (chartEl && window.echarts && rows.length) {
            channelChart?.dispose();
            channelChart = window.echarts.init(chartEl);
            channelChart.setOption({
                tooltip: { trigger: 'item', formatter: '{b}: ¥{c} ({d}%)' },
                legend: { orient: 'vertical', left: 'left', textStyle: { fontSize: 11, color: '#64748b' } },
                series: [{
                    type: 'pie', radius: ['40%', '70%'], avoidLabelOverlap: true,
                    label: { show: false },
                    emphasis: { label: { show: true, fontSize: 12, fontWeight: 'bold' } },
                    data: rows.map((r) => ({ name: r.channel || '未知', value: parseFloat(r.amount || 0) })),
                }],
            });
        }
    } catch (error) {
        if (error?.name !== 'AbortError') console.error('通道分布加载失败', error);
    }
}

async function loadMerchantRank({ root, api, ui, signal }) {
    const start = getVal(root, 'report-start');
    const end = getVal(root, 'report-end');
    const tableBody = root.querySelector('#report-merchant-table');
    if (!tableBody) return;

    try {
        const params = new URLSearchParams({ start, end });
        const response = await api.adminFetch(`/api/admin/report/merchant_rank?${params}`, { signal });
        const payload = await response.json();
        if (signal.aborted) return;
        if (payload.code !== 1) return;

        const rows = Array.isArray(payload.data) ? payload.data : [];
        const medals = ['🥇', '🥈', '🥉'];

        tableBody.innerHTML = rows.length
            ? rows.map((r, idx) => `<tr class="hover:bg-slate-50/80 transition-colors">
                <td class="p-2 font-bold text-slate-500 text-center w-10">${medals[idx] || idx + 1}</td>
                <td class="p-2 font-mono font-bold text-blue-700">${ui.escapeHtml(r.pid || '--')}<br>
                    <span class="text-slate-400 font-normal text-[11px]">${ui.escapeHtml(r.name || '')}</span></td>
                <td class="p-2 text-right">${Number(r.paid || 0).toLocaleString()}</td>
                <td class="p-2 text-right font-bold text-indigo-700">¥${parseFloat(r.amount || 0).toFixed(2)}</td>
                <td class="p-2 text-right text-amber-600">¥${parseFloat(r.fee || 0).toFixed(2)}</td>
            </tr>`).join('')
            : '<tr><td colspan="5" class="p-4 text-center text-slate-400">暂无数据</td></tr>';
    } catch (error) {
        if (error?.name !== 'AbortError') console.error('商户排行加载失败', error);
    }
}

async function exportCsv({ root, api, ui, signal }) {
    const start = getVal(root, 'report-start');
    const end = getVal(root, 'report-end');
    if (!start || !end) { ui.showToast('请先选择时间范围', 'error'); return; }

    ui.showToast('正在生成 CSV，请稍候...');
    try {
        const params = new URLSearchParams({ start, end });
        const response = await api.adminFetch(`/api/admin/report/export_csv?${params}`, { signal });
        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            ui.showToast(payload.msg || 'CSV 导出失败', 'error');
            return;
        }
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `cxpay_orders_${start}_${end}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        ui.showToast('CSV 文件已开始下载');
    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast('CSV 导出失败', 'error');
    }
}

function formatDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function getVal(root, id) { return root.querySelector(`#${id}`)?.value || ''; }
function setVal(root, id, v) { const el = root.querySelector(`#${id}`); if (el) el.value = v; }
function setText(root, id, v) { const el = root.querySelector(`#${id}`); if (el) el.textContent = v; }
