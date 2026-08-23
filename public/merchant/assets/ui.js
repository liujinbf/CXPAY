let toastTimer = null;

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;',
    })[character]);
}

export function safeCreateIcons() {
    try {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    } catch (error) {
        console.warn('Lucide 图标刷新失败：', error);
    }
}

export function showToast(message, type = 'success') {
    let toast = document.getElementById('global-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'global-toast';
        toast.className = 'fixed bottom-6 right-6 z-50 px-4 py-2.5 rounded-xl shadow-lg font-bold text-xs flex items-center gap-2 transition-all duration-300 transform translate-y-4 opacity-0 pointer-events-none';
        document.body.appendChild(toast);
    }

    toast.className = `fixed bottom-6 right-6 z-50 px-4 py-2.5 rounded-xl shadow-lg font-bold text-xs flex items-center gap-2 transition-all duration-300 transform translate-y-0 opacity-100 ${
        type === 'success'
            ? 'bg-slate-900 text-white shadow-slate-900/20'
            : 'bg-rose-600 text-white shadow-rose-600/20'
    }`;
    const icon = type === 'success'
        ? '<i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-400"></i> '
        : '<i data-lucide="alert-circle" class="w-4 h-4 text-white"></i> ';
    toast.innerHTML = `${icon}${escapeHtml(message)}`;
    safeCreateIcons();

    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.classList.add('translate-y-4', 'opacity-0', 'pointer-events-none');
    }, 2000);
}

export function showConfirm(title, message, isDanger = false) {
    return new Promise((resolve) => {
        let modal = document.getElementById('custom-confirm-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'custom-confirm-modal';
            modal.className = 'fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm opacity-0 pointer-events-none transition-all duration-300';
            modal.innerHTML = `
                <div class="bg-white rounded-2xl p-6 shadow-2xl border border-slate-100 max-w-sm w-full transform scale-95 transition-all duration-300 space-y-4">
                    <div class="flex items-center gap-3">
                        <div data-confirm-icon class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
                            <i data-lucide="help-circle" class="w-5 h-5"></i>
                        </div>
                        <h3 data-confirm-title class="font-extrabold text-base text-slate-800">确认操作</h3>
                    </div>
                    <p data-confirm-message class="text-xs text-slate-600 leading-relaxed pl-1"></p>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" data-confirm-cancel class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold rounded-xl text-xs transition-colors cursor-pointer">取消</button>
                        <button type="button" data-confirm-ok class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-xl text-xs transition-colors shadow-sm cursor-pointer">确定</button>
                    </div>
                </div>`;
            document.body.appendChild(modal);
        }

        const icon = modal.querySelector('[data-confirm-icon]');
        const titleElement = modal.querySelector('[data-confirm-title]');
        const messageElement = modal.querySelector('[data-confirm-message]');
        const confirmButton = modal.querySelector('[data-confirm-ok]');
        const cancelButton = modal.querySelector('[data-confirm-cancel]');

        titleElement.textContent = title;
        messageElement.textContent = message;
        icon.className = `w-10 h-10 rounded-xl ${
            isDanger ? 'bg-rose-50 text-rose-600' : 'bg-purple-50 text-purple-600'
        } flex items-center justify-center shrink-0`;
        confirmButton.className = `px-4 py-2 ${
            isDanger ? 'bg-rose-600 hover:bg-rose-700' : 'bg-purple-600 hover:bg-purple-700'
        } text-white font-bold rounded-xl text-xs transition-colors shadow-sm cursor-pointer`;

        safeCreateIcons();
        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.firstElementChild.classList.remove('scale-95');

        const cleanup = (value) => {
            modal.classList.add('opacity-0', 'pointer-events-none');
            modal.firstElementChild.classList.add('scale-95');
            confirmButton.onclick = null;
            cancelButton.onclick = null;
            resolve(value);
        };

        confirmButton.onclick = () => cleanup(true);
        cancelButton.onclick = () => cleanup(false);
    });
}

export async function copyText(value, trigger = null) {
    const text = String(value ?? '');
    if (!text) {
        showToast('暂无可复制的内容', 'error');
        return false;
    }

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            fallbackCopy(text);
        }
        showToast('已成功复制到剪贴板！');
        showCopiedState(trigger);
        return true;
    } catch {
        showToast('复制失败，请手动选中复制', 'error');
        return false;
    }
}

function fallbackCopy(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    const copied = document.execCommand('copy');
    document.body.removeChild(textArea);
    if (!copied) throw new Error('浏览器拒绝复制');
}

function showCopiedState(trigger) {
    if (!trigger || trigger.tagName !== 'BUTTON') return;
    const originalText = trigger.innerText;
    trigger.innerText = '已复制 √';
    trigger.classList.add('bg-emerald-600', 'text-white');
    setTimeout(() => {
        trigger.innerText = originalText;
        trigger.classList.remove('bg-emerald-600', 'text-white');
    }, 1500);
}

/**
 * 弹出商户端聚合扫码收银台模态框
 */
export function showCashierModal({
    title = '收银台扫码支付',
    subtitle = '请使用手机 App 扫码完成支付',
    amount = '0.00',
    initialPayType = 'alipay',
    createOrderFn, // async (payType) => { trade_no, qr_code_content, pay_url, price }
    onPaid,        // async () => void
}) {
    let modal = document.getElementById('merchant-cashier-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'merchant-cashier-modal';
        modal.className = 'fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-all duration-300 opacity-0 pointer-events-none';
        modal.innerHTML = `
            <div class="bg-white rounded-3xl p-6 md:p-8 shadow-2xl border border-slate-100 max-w-sm w-full transform scale-95 transition-all duration-300 space-y-5 text-center relative">
                <button type="button" data-cashier-close class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition-colors">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
                <div class="space-y-1">
                    <h3 data-cashier-title class="font-extrabold text-base text-slate-800">${escapeHtml(title)}</h3>
                    <p data-cashier-subtitle class="text-xs text-slate-400">${escapeHtml(subtitle)}</p>
                </div>
                <div class="py-2">
                    <span class="text-xs text-slate-400 font-bold block">实付金额</span>
                    <span data-cashier-amount class="text-3xl font-black text-slate-800 font-mono tracking-tight">¥ ${amount}</span>
                </div>
                <div class="flex items-center justify-center gap-3 bg-slate-50 p-1.5 rounded-2xl border border-slate-100">
                    <label class="flex-1 flex items-center justify-center gap-2 py-2 px-3 rounded-xl border border-blue-200 bg-blue-50 text-blue-700 text-xs font-extrabold cursor-pointer transition-all has-[:checked]:border-blue-500 has-[:checked]:bg-blue-100/50 has-[:checked]:text-blue-800">
                        <input type="radio" name="m-cashier-channel" value="alipay" class="hidden" ${initialPayType === 'alipay' ? 'checked' : ''}>
                        <i data-lucide="credit-card" class="w-4 h-4 text-blue-500"></i> 支付宝
                    </label>
                    <label class="flex-1 flex items-center justify-center gap-2 py-2 px-3 rounded-xl border border-slate-200 bg-white text-slate-600 text-xs font-bold cursor-pointer transition-all has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 has-[:checked]:text-emerald-800">
                        <input type="radio" name="m-cashier-channel" value="wxpay" class="hidden" ${initialPayType === 'wxpay' ? 'checked' : ''}>
                        <i data-lucide="smartphone" class="w-4 h-4 text-emerald-500"></i> 微信支付
                    </label>
                </div>
                <div class="flex flex-col items-center justify-center min-h-[190px] bg-slate-50 rounded-2xl border border-slate-100 p-4 relative">
                    <div id="m-cashier-qrcode" class="p-2 bg-white rounded-xl shadow-sm border border-slate-100 flex items-center justify-center"></div>
                    <div id="m-cashier-loading" class="absolute inset-0 bg-slate-50/90 backdrop-blur-sm rounded-2xl flex flex-col items-center justify-center gap-2">
                        <i data-lucide="loader-2" class="w-6 h-6 text-blue-600 animate-spin"></i>
                        <span class="text-xs text-slate-500 font-bold">正在生成专属收款码...</span>
                    </div>
                </div>
                <div class="space-y-2">
                    <div class="text-[11px] text-slate-400 flex items-center justify-center gap-1">
                        <i data-lucide="shield-check" class="w-3.5 h-3.5 text-emerald-500"></i> 资金由主站官方收款通道直收直达
                    </div>
                    <button type="button" data-cashier-refresh class="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs transition-colors flex items-center justify-center gap-1.5 cursor-pointer">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> 已付款？点击即时核销并刷新
                    </button>
                </div>
            </div>`;
        document.body.appendChild(modal);
    }

    const titleEl = modal.querySelector('[data-cashier-title]');
    const subtitleEl = modal.querySelector('[data-cashier-subtitle]');
    const amountEl = modal.querySelector('[data-cashier-amount]');
    const qrContainer = modal.querySelector('#m-cashier-qrcode');
    const loadingEl = modal.querySelector('#m-cashier-loading');
    const closeBtn = modal.querySelector('[data-cashier-close]');
    const refreshBtn = modal.querySelector('[data-cashier-refresh]');
    const channelRadios = modal.querySelectorAll('input[name="m-cashier-channel"]');

    titleEl.textContent = title;
    subtitleEl.textContent = subtitle;
    amountEl.textContent = `¥ ${amount}`;

    let pollTimer = null;
    let currentTradeNo = '';

    async function loadQr(payType) {
        loadingEl.classList.remove('hidden');
        qrContainer.innerHTML = '';
        clearInterval(pollTimer);
        try {
            const orderData = await createOrderFn(payType);
            if (!orderData || (!orderData.pay_url && !orderData.trade_no)) {
                throw new Error('未获取到出码数据');
            }
            currentTradeNo = orderData.trade_no || orderData.order_no;
            amountEl.textContent = `¥ ${Number(orderData.price || amount).toFixed(2)}`;

            const qrUrl = orderData.qr_code_content || orderData.pay_url;
            if (window.QRCode && qrUrl) {
                new window.QRCode(qrContainer, {
                    text: qrUrl,
                    width: 160,
                    height: 160,
                    colorDark: '#0f172a',
                    colorLight: '#ffffff',
                    correctLevel: window.QRCode.CorrectLevel.M,
                });
            } else if (qrUrl) {
                qrContainer.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=${encodeURIComponent(qrUrl)}" class="w-40 h-40 rounded-lg">`;
            }
            loadingEl.classList.add('hidden');

            // 启动高频状态轮询
            pollTimer = setInterval(async () => {
                if (!currentTradeNo) return;
                try {
                    const res = await fetch(`/api/order/query?trade_no=${encodeURIComponent(currentTradeNo)}`);
                    const data = await res.json();
                    if (data && Number(data.status) === 1) {
                        clearInterval(pollTimer);
                        showToast('支付成功！权益已即时生效', 'success');
                        close();
                        if (typeof onPaid === 'function') onPaid();
                    }
                } catch {}
            }, 2500);

        } catch (err) {
            loadingEl.classList.add('hidden');
            qrContainer.innerHTML = `<div class="p-4 text-xs text-rose-500 font-bold">${escapeHtml(err.message || '出码失败，请重试')}</div>`;
        }
        safeCreateIcons();
    }

    function close() {
        clearInterval(pollTimer);
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.firstElementChild.classList.add('scale-95');
    }

    closeBtn.onclick = close;
    refreshBtn.onclick = async () => {
        if (!currentTradeNo) return;
        refreshBtn.disabled = true;
        try {
            const res = await fetch(`/api/order/query?trade_no=${encodeURIComponent(currentTradeNo)}`);
            const data = await res.json();
            if (data && Number(data.status) === 1) {
                clearInterval(pollTimer);
                showToast('核销成功！权益已生效', 'success');
                close();
                if (typeof onPaid === 'function') onPaid();
                return;
            }
            showToast('正在等待网关回调，请稍候...', 'error');
        } catch {
            showToast('状态查询异常', 'error');
        } finally {
            refreshBtn.disabled = false;
        }
    };

    channelRadios.forEach((radio) => {
        radio.onchange = () => {
            if (radio.checked) loadQr(radio.value);
        };
    });

    safeCreateIcons();
    modal.classList.remove('opacity-0', 'pointer-events-none');
    modal.firstElementChild.classList.remove('scale-95');

    const selectedRadio = Array.from(channelRadios).find((r) => r.checked) || channelRadios[0];
    loadQr(selectedRadio ? selectedRadio.value : 'alipay');
}

/**
 * 弹出商户端服务费余额在线充值面板
 */
export function showRechargeModal({ api, ui, onRecharged }) {
    let modal = document.getElementById('merchant-recharge-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'merchant-recharge-modal';
        modal.className = 'fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-all duration-300 opacity-0 pointer-events-none';
        modal.innerHTML = `
            <div class="bg-white rounded-3xl p-6 md:p-8 shadow-2xl border border-slate-100 max-w-md w-full transform scale-95 transition-all duration-300 space-y-5 relative">
                <button type="button" data-recharge-close class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition-colors">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                        <i data-lucide="wallet" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h3 class="font-extrabold text-base text-slate-800">服务费余额在线充值</h3>
                        <p class="text-xs text-slate-400">充值款项将即时充入您的商户服务费账户中</p>
                    </div>
                </div>
                <div class="space-y-3">
                    <label class="text-xs font-bold text-slate-600 block">选择充值金额</label>
                    <div class="grid grid-cols-3 gap-2.5" id="recharge-presets">
                        <button type="button" data-amount="10" class="preset-btn py-2.5 rounded-xl border border-slate-200 hover:border-emerald-500 font-extrabold font-mono text-sm text-slate-700 hover:text-emerald-700 transition-all cursor-pointer">¥ 10.00</button>
                        <button type="button" data-amount="50" class="preset-btn py-2.5 rounded-xl border border-emerald-500 bg-emerald-50 font-extrabold font-mono text-sm text-emerald-700 transition-all cursor-pointer">¥ 50.00</button>
                        <button type="button" data-amount="100" class="preset-btn py-2.5 rounded-xl border border-slate-200 hover:border-emerald-500 font-extrabold font-mono text-sm text-slate-700 hover:text-emerald-700 transition-all cursor-pointer">¥ 100.00</button>
                        <button type="button" data-amount="200" class="preset-btn py-2.5 rounded-xl border border-slate-200 hover:border-emerald-500 font-extrabold font-mono text-sm text-slate-700 hover:text-emerald-700 transition-all cursor-pointer">¥ 200.00</button>
                        <button type="button" data-amount="500" class="preset-btn py-2.5 rounded-xl border border-slate-200 hover:border-emerald-500 font-extrabold font-mono text-sm text-slate-700 hover:text-emerald-700 transition-all cursor-pointer">¥ 500.00</button>
                        <button type="button" data-amount="1000" class="preset-btn py-2.5 rounded-xl border border-slate-200 hover:border-emerald-500 font-extrabold font-mono text-sm text-slate-700 hover:text-emerald-700 transition-all cursor-pointer">¥ 1000.00</button>
                    </div>
                    <div class="pt-2">
                        <label class="text-xs font-bold text-slate-600 block mb-1">自定义金额 (¥)</label>
                        <input id="custom-recharge-amount" type="number" step="0.01" min="0.01" placeholder="输入其他金额 (如 20.00)" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm font-mono font-bold text-slate-800 focus:outline-none focus:border-emerald-500">
                    </div>
                </div>
                <div class="pt-2">
                    <button type="button" id="start-recharge-btn" class="w-full py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold rounded-2xl text-xs transition-all shadow-md flex items-center justify-center gap-2 cursor-pointer">
                        <i data-lucide="qr-code" class="w-4 h-4"></i> 前往扫码充值 (¥ <span id="recharge-btn-amount">50.00</span>)
                    </button>
                </div>
            </div>`;
        document.body.appendChild(modal);
    }

    let selectedAmount = '50.00';
    const closeBtn = modal.querySelector('[data-recharge-close]');
    const customInput = modal.querySelector('#custom-recharge-amount');
    const startBtn = modal.querySelector('#start-recharge-btn');
    const btnAmountEl = modal.querySelector('#recharge-btn-amount');
    const presetBtns = modal.querySelectorAll('.preset-btn');

    function updateSelected(amt) {
        selectedAmount = Number(amt).toFixed(2);
        btnAmountEl.textContent = selectedAmount;
        presetBtns.forEach((btn) => {
            if (btn.dataset.amount === String(parseInt(amt, 10)) && Number(amt) === parseInt(amt, 10)) {
                btn.className = 'preset-btn py-2.5 rounded-xl border border-emerald-500 bg-emerald-50 font-extrabold font-mono text-sm text-emerald-700 transition-all cursor-pointer';
            } else {
                btn.className = 'preset-btn py-2.5 rounded-xl border border-slate-200 hover:border-emerald-500 font-extrabold font-mono text-sm text-slate-700 hover:text-emerald-700 transition-all cursor-pointer';
            }
        });
    }

    presetBtns.forEach((btn) => {
        btn.onclick = () => {
            customInput.value = '';
            updateSelected(btn.dataset.amount);
        };
    });

    customInput.oninput = () => {
        const val = parseFloat(customInput.value);
        if (val > 0) updateSelected(val);
    };

    function close() {
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.firstElementChild.classList.add('scale-95');
    }

    closeBtn.onclick = close;

    startBtn.onclick = () => {
        const finalAmt = parseFloat(selectedAmount);
        if (isNaN(finalAmt) || finalAmt <= 0) {
            ui.showToast('请输入有效的充值金额', 'error');
            return;
        }
        close();
        showCashierModal({
            title: '商户服务费在线充值',
            subtitle: '付款完成后资金将秒级充入您的商户服务费余额',
            amount: selectedAmount,
            initialPayType: 'alipay',
            createOrderFn: async (payType) => {
                const resp = await api.merchantFetch('/api/merchant/recharge/create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ money: selectedAmount, type: payType }),
                });
                const payload = await resp.json();
                if (payload.code !== 1 || !payload.data) {
                    throw new Error(payload.msg || '创建充值订单失败');
                }
                return payload.data;
            },
            onPaid: async () => {
                if (typeof onRecharged === 'function') onRecharged();
            },
        });
    };

    safeCreateIcons();
    modal.classList.remove('opacity-0', 'pointer-events-none');
    modal.firstElementChild.classList.remove('scale-95');
}
