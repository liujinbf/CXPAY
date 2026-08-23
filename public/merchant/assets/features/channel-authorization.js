export function createChannelAuthorization({ root, api, ui, signal }) {
    let controller = null;
    let overlay = null;
    let disposed = false;

    const abort = () => controller?.abort();
    signal?.addEventListener('abort', abort, { once: true });

    async function start(id, platformName = '支付宝') {
        controller?.abort();
        controller = new AbortController();
        if (signal?.aborted || disposed) {
            controller.abort();
            return false;
        }

        try {
            const startResponse = await post('/api/merchant/channel/authorization/start', {
                id: String(id),
            });
            const startPayload = await startResponse.json();
            if (startPayload.code !== 1 || !startPayload.data?.session_id) {
                throw new Error(startPayload.msg || '无法创建授权会话');
            }

            const sessionId = startPayload.data.session_id;
            let qrShown = false;
            let currentQrContent = '';

            if (startPayload.data.qr_url) {
                showQr(startPayload.data.qr_url, startPayload.data.expires_at, platformName);
                qrShown = true;
                currentQrContent = startPayload.data.qr_url;
            }

            for (let attempt = 0; attempt < 150; attempt += 1) {
                const response = await post('/api/merchant/channel/authorization/poll', {
                    id: String(id),
                    session_id: sessionId,
                });
                const payload = await response.json();
                if (payload.code !== 1 || !payload.data) {
                    throw new Error(payload.msg || '授权状态查询失败');
                }
                const state = payload.data;

                if (state.qr_url && !qrShown) {
                    showQr(state.qr_url, state.expires_at, platformName);
                    qrShown = true;
                    currentQrContent = state.qr_url;
                }

                if (state.status === 'NEED_SECOND_SCAN' && state.qr_url && state.qr_url !== currentQrContent) {
                    currentQrContent = state.qr_url;
                    updateQrImage(state.qr_url, '🔒 登录成功！请再次扫码完成二次安全校验（解除消费明细限制）', true);
                }

                if (state.status === 'CONFIRMED') {
                    closeQr();
                    ui.showToast(`🎉 ${platformName}账号扫码授权成功，全量子域 Cookie 已自动提取并保存！`);
                    return true;
                }

                if (['FAILED', 'EXPIRED'].includes(state.status)) {
                    throw new Error(state.message || `${platformName}扫码授权失败或已过期`);
                }
                await wait(2000, controller.signal);
            }
            throw new Error('授权等待超时，请重新发起');
        } catch (error) {
            closeQr();
            if (error?.name === 'AbortError') return false;
            ui.showToast(error.message || `${platformName}扫码授权失败`, 'error');
            return false;
        }
    }

    async function detectCapabilities(id) {
        try {
            const response = await post('/api/merchant/channel/capabilities', { id: String(id) });
            const payload = await response.json();
            if (payload.code !== 1 || !payload.data) {
                throw new Error(payload.msg || '账号能力探测失败');
            }
            ui.showToast(payload.data.message || '账号能力探测完成');
            return payload.data;
        } catch (error) {
            if (error?.name !== 'AbortError') ui.showToast(error.message || '账号能力探测失败', 'error');
            return null;
        }
    }

    async function configureBillSource(id) {
        try {
            const statusResponse = await api.merchantFetch(
                `/api/merchant/bill-source/status?id=${encodeURIComponent(id)}`,
                { signal: controller?.signal || signal }
            );
            const statusPayload = await statusResponse.json();
            if (statusPayload.code !== 1) throw new Error(statusPayload.msg || '账单源状态获取失败');
            const current = statusPayload.data || {};
            const collectorId = window.prompt(
                '请输入授权采集端 ID（例如 ANDROID_PHONE_01）：',
                current.collector_id || 'ANDROID_PHONE_01'
            );
            if (collectorId === null) return null;
            const ipWhite = window.prompt(
                '采集端 IP 白名单（可留空；多个 IP 用逗号分隔）：',
                current.ingest_ip_white || ''
            );
            if (ipWhite === null) return null;
            const confirmed = await ui.showConfirm(
                '更新账单源令牌',
                '将同时轮换采集端写入令牌和 PC 拉取令牌，旧令牌会立即失效。是否继续？',
                true
            );
            if (!confirmed) return null;
            const ingestToken = await rotateToken(id, 'ingest', {
                collector_id: collectorId.trim(),
                ingest_ip_white: ipWhite.trim(),
            });
            const feedToken = await rotateToken(id, 'feed');
            window.prompt('采集端写入令牌（仅显示一次，请立即复制）：', ingestToken);
            window.prompt('PC 拉取令牌（仅显示一次，请填入 PC 客户端“账单源令牌”）：', feedToken);
            window.prompt('PC 客户端“授权账单源 URL”：', `${window.location.origin}/api/bill-source/poll`);
            ui.showToast('账单源令牌已更新');
            return { ingestToken, feedToken };
        } catch (error) {
            if (error?.name !== 'AbortError') ui.showToast(error.message || '账单源配置失败', 'error');
            return null;
        }
    }

    async function rotateToken(id, scope, extra = {}) {
        const response = await post('/api/merchant/bill-source/rotate-token', {
            id: String(id),
            scope,
            ...extra,
        });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data?.token) {
            throw new Error(payload.msg || `${scope} 令牌生成失败`);
        }
        return payload.data.token;
    }

    function post(url, values) {
        return api.merchantFetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(values),
            signal: controller?.signal || signal,
        });
    }

    function showQr(content, expiresAt, platformName) {
        if (typeof document === 'undefined') return;
        closeQr();
        overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-[100] bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4 animate-in fade-in duration-200';
        overlay.innerHTML = `
            <div class="bg-white rounded-3xl p-6 w-full max-w-sm text-center space-y-4 shadow-2xl border border-slate-100 animate-in zoom-in-95 duration-200">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2">
                        <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-sm">🔑</span>
                        <h3 class="font-extrabold text-sm text-slate-800">${ui.escapeHtml(platformName)}扫码授权提取 Cookie</h3>
                    </div>
                    <button type="button" data-action="close-authorization-qr" class="text-slate-400 hover:text-slate-700 p-1 text-base leading-none">✕</button>
                </div>
                <div id="auth-status-hint" class="p-3 bg-blue-50 rounded-2xl border border-blue-100 text-blue-900 text-xs font-medium leading-relaxed">
                    打开手机${ui.escapeHtml(platformName)} <strong>「扫一扫」</strong> 确认登录，系统将自动通过浏览器全链路握手提取账单权限 Cookie。
                </div>
                <div data-authorization-qr class="mx-auto w-[200px] min-h-[200px] flex items-center justify-center p-2 bg-white rounded-2xl border border-slate-200 shadow-inner"></div>
                <p class="text-[11px] text-slate-400 font-mono">二维码有效期至 ${new Date(Number(expiresAt || (Date.now()/1000 + 300)) * 1000).toLocaleTimeString()}</p>
                <div class="pt-2 border-t border-slate-100">
                    <button type="button" data-action="close-authorization-qr" class="w-full px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold transition-colors">关闭窗口</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelectorAll('[data-action="close-authorization-qr"]')
            .forEach(btn => btn.addEventListener('click', closeQr, { once: true }));

        renderQrContent(content);
    }

    function renderQrContent(content, isSecondScan = false) {
        if (!overlay) return;
        const qr = overlay.querySelector('[data-authorization-qr]');
        if (!qr) return;

        if (content.startsWith('data:image') || content.startsWith('blob:') || /\.(png|jpg|jpeg|gif)$/i.test(content)) {
            qr.innerHTML = `<img src="${content}" alt="授权二维码" class="w-44 h-44 object-contain rounded-xl shadow-sm ${isSecondScan ? 'border-2 border-amber-400 animate-in zoom-in-95' : 'border border-slate-100'}" />`;
        } else if (typeof window.QRCode === 'function') {
            qr.innerHTML = '';
            new window.QRCode(qr, { text: String(content), width: 170, height: 170, colorDark: isSecondScan ? '#b45309' : '#0f172a', colorLight: '#ffffff' });
        } else {
            qr.innerHTML = `<div class="font-mono text-[10px] break-all p-2 bg-slate-50 rounded">${ui.escapeHtml(content)}</div>`;
        }
    }

    function updateQrImage(content, hintText, isSecondScan = true) {
        if (!overlay) return;
        renderQrContent(content, isSecondScan);
        const hint = overlay.querySelector('#auth-status-hint');
        if (hint) {
            hint.className = 'p-3 bg-amber-50 rounded-2xl border border-amber-200 text-amber-900 text-xs font-bold leading-relaxed animate-pulse';
            hint.innerHTML = hintText;
        }
    }

    async function startAlipayAppAuth() {
        controller?.abort();
        controller = new AbortController();
        if (signal?.aborted || disposed) {
            controller.abort();
            return false;
        }

        try {
            const startResponse = await api.merchantFetch('/api/alipay/auth/start', {
                method: 'POST',
                signal: controller.signal
            });
            const startPayload = await startResponse.json();
            if (startPayload.code !== 1 || !startPayload.data?.state) {
                throw new Error(startPayload.msg || '无法生成支付宝授权二维码');
            }

            const state = startPayload.data.state;
            const authUrl = startPayload.data.auth_url;

            showQr(authUrl, 0, '支付宝当面付 (官方 AppAuth 扫码代授权)');
            const hint = overlay?.querySelector('#auth-status-hint');
            if (hint) {
                hint.innerHTML = '请使用开通了当面付的【手机支付宝】扫描上方二维码<br>在手机上点击「同意授权」即可秒级全自动绑定！';
            }

            for (let attempt = 0; attempt < 150; attempt += 1) {
                const response = await api.merchantFetch(`/api/alipay/auth/poll?state=${encodeURIComponent(state)}`, {
                    signal: controller.signal
                });
                const payload = await response.json();
                if (payload.code === 1 && payload.status === 'SUCCESS') {
                    closeQr();
                    ui.showToast('🎉 支付宝当面付已成功授权并激活！');
                    return true;
                }

                if (payload.status === 'FAILED' || payload.status === 'EXPIRED') {
                    throw new Error(payload.msg || '授权已失效或被取消');
                }
                await wait(2000, controller.signal);
            }
            throw new Error('授权等待超时，请重新扫码');
        } catch (error) {
            closeQr();
            if (error?.name === 'AbortError') return false;
            ui.showToast(error.message || '支付宝扫码授权失败', 'error');
            return false;
        }
    }

    function closeQr() {
        overlay?.remove();
        overlay = null;
    }

    function dispose() {
        disposed = true;
        controller?.abort();
        controller = null;
        closeQr();
        signal?.removeEventListener('abort', abort);
        root = null;
    }

    return { start, startAlipayAppAuth, detectCapabilities, configureBillSource, closeQr, dispose };
}

function wait(milliseconds, signal) {
    if (signal?.aborted) {
        return Promise.reject(new DOMException('操作已中止', 'AbortError'));
    }
    return new Promise((resolve, reject) => {
        const timer = setTimeout(resolve, milliseconds);
        signal?.addEventListener('abort', () => {
            clearTimeout(timer);
            reject(new DOMException('操作已中止', 'AbortError'));
        }, { once: true });
    });
}
