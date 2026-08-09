export function createChannelAuthorization({ root, api, ui, signal }) {
    let controller = null;
    let overlay = null;
    let disposed = false;

    const abort = () => controller?.abort();
    signal?.addEventListener('abort', abort, { once: true });

    async function start(id, platformName = '账号') {
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

            let qrShown = false;
            for (let attempt = 0; attempt < 150; attempt += 1) {
                const response = await post('/api/merchant/channel/authorization/poll', {
                    id: String(id),
                    session_id: startPayload.data.session_id,
                });
                const payload = await response.json();
                if (payload.code !== 1 || !payload.data) {
                    throw new Error(payload.msg || '授权状态查询失败');
                }
                const state = payload.data;
                if (state.qr_url && !qrShown) {
                    showQr(state.qr_url, state.expires_at, platformName);
                    qrShown = true;
                }
                if (state.status === 'CONFIRMED') {
                    closeQr();
                    ui.showToast(`${platformName}账号授权成功，请确认监控服务状态后再手动启用通道。`);
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
        overlay.className = 'fixed inset-0 z-[100] bg-slate-900/60 flex items-center justify-center p-4';
        overlay.innerHTML = `
            <div class="bg-white rounded-2xl p-6 w-full max-w-sm text-center space-y-4 shadow-2xl">
                <h3 class="font-extrabold text-slate-800">请使用${ui.escapeHtml(platformName)}扫码授权</h3>
                <div data-authorization-qr class="mx-auto w-[220px] min-h-[220px] flex items-center justify-center"></div>
                <p class="text-xs text-slate-500">二维码有效期至 ${new Date(Number(expiresAt || 0) * 1000).toLocaleTimeString()}</p>
                <button type="button" data-action="close-authorization-qr" class="px-4 py-2 rounded-xl bg-slate-100 text-slate-600 text-xs font-bold">关闭显示（后台仍继续等待）</button>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('[data-action="close-authorization-qr"]')
            ?.addEventListener('click', closeQr, { once: true });
        const qr = overlay.querySelector('[data-authorization-qr]');
        if (window.QRCode) new window.QRCode(qr, { text: String(content), width: 220, height: 220 });
        else qr.textContent = String(content);
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

    return { start, detectCapabilities, configureBillSource, closeQr, dispose };
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
