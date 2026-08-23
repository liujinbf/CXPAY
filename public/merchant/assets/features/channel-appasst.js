export function createChannelAppAsst({ root, channels, ui }) {
    return {
        open() {
            const modal = root.querySelector('#appasst-pair-modal');
            if (!modal) return;

            let wxChannel = null, aliChannel = null, fallbackChannel = null;
            channels.forEach(ch => {
                if (!fallbackChannel) fallbackChannel = ch;
                if ((ch.pay_category === 'wxpay' || ch.c_type?.includes('wx')) && !wxChannel) wxChannel = ch;
                if ((ch.pay_category === 'alipay' || ch.c_type?.includes('ali')) && !aliChannel) aliChannel = ch;
            });

            const mchId = window.CXMerchant?.merchant?.id || '1';
            const deviceId = `AND_MCH_${mchId}`;
            let secret = localStorage.getItem(`cxpay_appasst_secret_${mchId}`);
            if (!secret) {
                secret = Array.from(crypto.getRandomValues(new Uint8Array(16)))
                    .map(b => b.toString(16).padStart(2, '0')).join('');
                localStorage.setItem(`cxpay_appasst_secret_${mchId}`, secret);
            }

            const wxId = wxChannel ? String(wxChannel.id) : (fallbackChannel ? String(fallbackChannel.id) : '1');
            const aliId = aliChannel ? String(aliChannel.id) : (fallbackChannel ? String(fallbackChannel.id) : '2');
            const serverUrl = window.location.origin;

            const setTxt = (id, val) => { const el = modal.querySelector(id); if (el) el.textContent = val; };
            setTxt('#appasst-cfg-server', serverUrl);
            setTxt('#appasst-cfg-device', deviceId);
            setTxt('#appasst-cfg-wx', wxChannel ? `通道 #${wxChannel.id} (${wxChannel.title})` : `通道 #${wxId}`);
            setTxt('#appasst-cfg-ali', aliChannel ? `通道 #${aliChannel.id} (${aliChannel.title})` : `通道 #${aliId}`);
            setTxt('#appasst-cfg-secret', secret);

            // 立即向后端同步此配对密钥与设备ID，确保服务端鉴权通过
            const syncEndpoint = window.location.pathname.startsWith('/admin')
                ? '/api/admin/channel/appasst_sync_pair'
                : '/api/merchant/channel/appasst_sync_pair';

            const syncBody = new URLSearchParams({
                device_id: deviceId,
                notify_secret: secret,
                wx_channel_id: wxId,
                ali_channel_id: aliId
            });

            const token = localStorage.getItem('cxpay_admin_token') || localStorage.getItem('token') || '';
            const mchToken = localStorage.getItem('cxpay_merchant_token') || localStorage.getItem('merchant_token') || '';

            fetch(syncEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Authorization': token ? `Bearer ${token}` : '',
                    'X-Merchant-Token': mchToken
                },
                body: syncBody
            }).catch(() => {});

            const downloadQrBox = modal.querySelector('#appasst-download-qrcode');
            if (downloadQrBox) {
                downloadQrBox.innerHTML = '';
                const downloadUrl = `${serverUrl.replace(/\/+$/, '')}/download/app.html`;
                if (typeof window.QRCode === 'function') {
                    new window.QRCode(downloadQrBox, {
                        text: downloadUrl,
                        width: 140,
                        height: 140,
                        colorDark: '#0f172a',
                        colorLight: '#ffffff',
                        correctLevel: window.QRCode.CorrectLevel.M
                    });
                }
            }

            const pairQrBox = modal.querySelector('#appasst-pair-qrcode');
            if (pairQrBox) {
                pairQrBox.innerHTML = '';
                const configPayload = JSON.stringify({
                    cxpay_config: true,
                    server_url: serverUrl,
                    device_id: deviceId,
                    notify_secret: secret,
                    wx_channel_id: wxId,
                    ali_channel_id: aliId,
                    created_at: Math.floor(Date.now() / 1000)
                });
                if (typeof window.QRCode === 'function') {
                    new window.QRCode(pairQrBox, {
                        text: configPayload,
                        width: 140,
                        height: 140,
                        colorDark: '#312e81',
                        colorLight: '#ffffff',
                        correctLevel: window.QRCode.CorrectLevel.M
                    });
                }
            }

            modal.classList.remove('hidden');
            ui.safeCreateIcons(modal);
        },
        close() {
            root.querySelector('#appasst-pair-modal')?.classList.add('hidden');
        },
        regenerateSecret() {
            const mchId = window.CXMerchant?.merchant?.id || '1';
            const newSecret = Array.from(crypto.getRandomValues(new Uint8Array(16)))
                .map(b => b.toString(16).padStart(2, '0')).join('');
            localStorage.setItem(`cxpay_appasst_secret_${mchId}`, newSecret);
            ui.showToast('已重新生成防泄漏安全密钥，并已自动同步至通道配置！', 'success');
            this.open();
        }
    };
}
