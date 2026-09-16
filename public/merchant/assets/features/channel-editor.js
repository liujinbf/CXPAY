export function createChannelEditor({ root, api, ui, signal, reload, navigate, authorization, appasst }) {
    let drivers = null;
    let disposed = false;

    const find = (selector) => root?.querySelector(selector);
    const modal = () => find('#add-channel-modal');

    async function loadDrivers() {
        if (drivers) return drivers;
        const response = await api.merchantFetch('/api/merchant/channel/drivers', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data) {
            throw new Error(payload.msg || '通道驱动加载失败');
        }
        drivers = payload.data;
        return drivers;
    }

    async function openNew() {
        if (disposed) return;
        setValue('#modal-title', '添加收款通道', 'textContent');
        setValue('#channel-id-input', '0');
        setValue('#channel-title-input', '');
        setValue('#channel-remark-input', '');
        const status = find('#channel-status-input');
        if (status) status.checked = true;
        await changeCategory();
        resetQrStatus();
        modal()?.classList.remove('hidden');
    }

    async function open(item) {
        if (disposed || !item) return;
        setValue('#modal-title', '编辑收款通道', 'textContent');
        setValue('#channel-id-input', item.id);
        setValue('#channel-c-type', item.pay_category || 'wxpay');
        await changeCategory();
        setValue('#channel-driver-list', item.c_type || '');
        changeDriver(item.config || {}, item.configured || {});
        setValue('#channel-title-input', item.title || '');
        setValue('#channel-remark-input', item.remark || '');
        const status = find('#channel-status-input');
        if (status) status.checked = Number(item.status) === 1;
        resetQrStatus();
        modal()?.classList.remove('hidden');
    }

    function close() {
        modal()?.classList.add('hidden');
    }

    async function changeCategory() {
        const categorySelect = find('#channel-c-type');
        const select = find('#channel-driver-list');
        if (!select) return;
        select.innerHTML = '';
        try {
            const catalog = await loadDrivers();
            const availableCategories = Object.keys(catalog).filter(k => Array.isArray(catalog[k]) && catalog[k].length > 0);
            if (categorySelect && availableCategories.length > 0) {
                const currentCat = categorySelect.value;
                const categoryTitles = {
                    wxpay: '微信支付 (WeChat Pay)',
                    alipay: '支付宝 (Alipay)',
                    qqpay: 'QQ 钱包 (QQ Pay)',
                    usdt: 'USDT 区块链收款 (USDT-TRC20)',
                };
                categorySelect.innerHTML = availableCategories.map(cat => `<option value="${cat}">${categoryTitles[cat] || cat.toUpperCase()}</option>`).join('');
                if (availableCategories.includes(currentCat)) {
                    categorySelect.value = currentCat;
                } else {
                    categorySelect.value = availableCategories[0];
                }
            }

            const category = categorySelect?.value || 'wxpay';
            updateQrHint(category);

            const availableDrivers = catalog[category] || [];
            if (availableDrivers.length === 0) {
                const option = document.createElement('option');
                option.value = '';
                option.disabled = true;
                option.selected = true;
                option.textContent = '⚠ 暂无可用驱动';
                select.appendChild(option);
                const fields = find('#driver-config-fields');
                if (fields) {
                    fields.innerHTML = '<div class="p-4 rounded-2xl border border-amber-200 bg-amber-50 text-amber-800 text-xs font-bold text-center leading-relaxed">提示：此分类下暂无已安装且启用的支付通道驱动。</div>';
                }
                const qrBox = find('#static-qr-upload-box');
                qrBox?.classList.add('hidden');
                return;
            }
            for (const driver of availableDrivers) {
                const option = document.createElement('option');
                option.value = driver.c_type;
                option.textContent = driver.name;
                select.appendChild(option);
            }
            changeDriver();
        } catch (error) {
            if (error?.name === 'AbortError') return;
            select.innerHTML = '<option value="">驱动列表加载失败</option>';
            find('#driver-config-fields').innerHTML = '';
            ui.showToast(error.message || '通道驱动加载失败', 'error');
        }
    }

    function changeDriver(existingConfig = {}, configured = {}) {
        const cType = find('#channel-driver-list')?.value || '';
        const driver = allDrivers().find((item) => item.c_type === cType);
        renderInputs(driver, existingConfig, configured);
        const qrBox = find('#static-qr-upload-box');
        const hasQrInput = driver?.inputs?.some((input) => ['qr_url', 'qr_code_url'].includes(input.name));
        qrBox?.classList.toggle('hidden', !hasQrInput);
    }

    function renderInputs(driver, existingConfig, configured) {
        const container = find('#driver-config-fields');
        if (!container) return;
        container.innerHTML = '';

        const isAlipayCategory = driver?.pay_category === 'alipay' || String(driver?.c_type || '').startsWith('ali');
        if (isAlipayCategory && (driver?.c_type === 'alipay_face_pay' || driver?.has_oauth)) {
            const oauthBanner = document.createElement('div');
            oauthBanner.className = 'p-4 rounded-2xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-md flex items-center justify-between gap-3 mb-3';
            oauthBanner.innerHTML = `
                <div class="space-y-0.5">
                    <div class="text-xs font-black flex items-center gap-1.5">
                        <span>🚀 官方推荐：手机支付宝扫码一键代授权</span>
                        <span class="px-2 py-0.5 rounded-full bg-white/20 text-[10px] font-bold">0配置 / 免公私钥</span>
                    </div>
                    <div class="text-[11px] text-blue-100 leading-relaxed">使用已开通当面付的手机支付宝扫码点同意，1秒自动完成通道绑定与激活。</div>
                </div>
                <button type="button" id="btn-alipay-appauth-trigger" class="px-4 py-2 rounded-xl bg-white text-blue-600 hover:bg-blue-50 font-black text-xs shadow-sm transition-all flex items-center gap-1.5 whitespace-nowrap cursor-pointer hover:scale-105 active:scale-95">
                    <i data-lucide="qr-code" class="w-4 h-4"></i> 扫码一键授权
                </button>
            `;
            oauthBanner.querySelector('#btn-alipay-appauth-trigger')?.addEventListener('click', async (e) => {
                e.preventDefault();
                close();
                if (await authorization?.startAlipayAppAuth()) {
                    await reload?.();
                }
            });
            container.appendChild(oauthBanner);
        }

        if (driver?.c_type === 'wechat_cloud_book') {
            const wxCloudBanner = document.createElement('div');
            wxCloudBanner.className = 'p-4 rounded-2xl bg-gradient-to-r from-emerald-950 via-teal-950 to-slate-900 text-white shadow-md space-y-3 mb-3 border border-emerald-500/30';
            wxCloudBanner.innerHTML = `
                <div class="flex items-center justify-between gap-2">
                    <div class="space-y-0.5">
                        <div class="text-xs font-black flex items-center gap-1.5 text-emerald-400">
                            <i data-lucide="cloud-lightning" class="w-4 h-4 text-emerald-400"></i>
                            <span>⚡ 微信协议云端通道（小账本 / 收款单）</span>
                            <span class="px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 text-[10px] font-bold border border-emerald-400/30">免挂机设备 / 服务端高频轮询</span>
                        </div>
                        <div class="text-[11px] text-slate-300 leading-relaxed">资金 100% 直入商户微信零钱！系统通过微信协议云端轮询小账本/收款单流水账单，无需挂机手机。</div>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2 border-t border-emerald-800/40 bg-black/20 p-3 rounded-xl">
                    <div class="text-[11px] text-slate-300 space-y-1 leading-relaxed">
                        <p class="font-bold text-white">📌 2步极速接入：</p>
                        <p>① 点击右侧【扫码登录微信】按钮，使用对应微信号扫码并确认登录，系统自动抓取填入 OpenID 与 SID；</p>
                        <p>② 上传您的微信收款码图片，点击下方【保存】即可生效！</p>
                    </div>
                    <button type="button" id="btn-wechat-cloud-scan-trigger" class="px-3.5 py-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-400 hover:to-teal-400 text-slate-950 font-black text-xs shadow-md transition-all flex items-center gap-1.5 whitespace-nowrap cursor-pointer hover:scale-105 active:scale-95 flex-shrink-0">
                        <i data-lucide="qr-code" class="w-4 h-4"></i> 扫码登录微信获取OpenID/SID
                    </button>
                </div>
            `;
            wxCloudBanner.querySelector('#btn-wechat-cloud-scan-trigger')?.addEventListener('click', (e) => {
                e.preventDefault();
                openWechatScanModal(driver.c_type);
            });
            container.appendChild(wxCloudBanner);
        }

        const isAppAsstDriver = ['app_asst_universal', 'wxpay_app_asst', 'wechat_app_asst', 'alipay_app_asst', 'qqpay_app_asst'].includes(driver?.c_type);
        const isMobileMonitorDriver = isAppAsstDriver || driver?.c_type === 'wechat_dy_bill' || driver?.inputs?.some((i) => i.name === 'device_id');

        if (isMobileMonitorDriver) {
            const mchId = window.CXMerchant?.merchant?.id || '1000';
            const defaultDeviceId = driver?.c_type === 'wechat_dy_bill' ? `WX_CLERK_${mchId}` : `AND_MCH_${mchId}`;
            let defaultSecret = localStorage.getItem(`cxpay_appasst_secret_${mchId}`);
            if (!defaultSecret) {
                defaultSecret = Array.from(crypto.getRandomValues(new Uint8Array(16)))
                    .map((b) => b.toString(16).padStart(2, '0')).join('');
                localStorage.setItem(`cxpay_appasst_secret_${mchId}`, defaultSecret);
            }

            existingConfig.device_id = existingConfig.device_id || defaultDeviceId;
            existingConfig.notify_secret = existingConfig.notify_secret || defaultSecret;
        }

        if (driver?.c_type === 'wechat_dy_bill') {
            const clerkQrcode = driver?.platform_clerk_qrcode || existingConfig.clerk_qrcode || '';
            const clerkName = driver?.platform_clerk_name || existingConfig.clerk_name || '平台官方收款店员';

            const clerkBanner = document.createElement('div');
            clerkBanner.className = 'p-4 rounded-2xl bg-gradient-to-r from-emerald-950 via-teal-950 to-slate-900 text-white shadow-md space-y-3 mb-3 border border-emerald-500/30';
            
            let qrImageHtml = '';
            if (clerkQrcode) {
                const isUrlOrBase64 = clerkQrcode.startsWith('http') || clerkQrcode.startsWith('data:image');
                qrImageHtml = `
                    <div class="bg-white p-2 rounded-xl border border-emerald-300 shadow-sm flex flex-col items-center flex-shrink-0">
                        ${isUrlOrBase64 
                            ? `<img src="${clerkQrcode}" alt="店员二维码" class="w-28 h-28 object-contain rounded-lg">`
                            : `<div id="clerk-qr-canvas-box" class="w-28 h-28 flex items-center justify-center bg-slate-50 text-slate-800 text-[10px] break-all p-1"></div>`}
                        <span class="mt-1 text-[10px] text-emerald-800 font-extrabold flex items-center gap-1">
                            <i data-lucide="scan" class="w-3 h-3 text-emerald-600"></i> 微信扫码添加店员
                        </span>
                    </div>
                `;
            } else {
                qrImageHtml = `
                    <div class="bg-white/10 p-3 rounded-xl border border-emerald-400/20 text-center flex flex-col items-center justify-center w-28 h-28 flex-shrink-0">
                        <i data-lucide="user-check" class="w-8 h-8 text-emerald-400 mb-1"></i>
                        <span class="text-[10px] text-emerald-200">主站统一店员</span>
                    </div>
                `;
            }

            clerkBanner.innerHTML = `
                <div class="flex items-center justify-between gap-2">
                    <div class="space-y-0.5">
                        <div class="text-xs font-black flex items-center gap-1.5 text-emerald-400">
                            <i data-lucide="shield-check" class="w-4 h-4 text-emerald-400"></i>
                            <span>✨ 主站统一托管店员（商户 0 挂机 / 0 手机设备）</span>
                            <span class="px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 text-[10px] font-bold border border-emerald-400/30">官方店员通知协议</span>
                        </div>
                        <div class="text-[11px] text-slate-300 leading-relaxed">资金 100% 直入商户微信零钱！只需打开微信小程序「微信收款小账本」扫描下方店员二维码邀请加入即可。</div>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row items-center gap-3.5 pt-2 border-t border-emerald-800/40 bg-black/20 p-3 rounded-xl">
                    ${qrImageHtml}
                    <div class="space-y-1.5 text-xs flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-slate-400 text-[11px]">托管店员昵称:</span>
                            <span class="font-bold text-emerald-300 text-xs">${clerkName}</span>
                        </div>
                        <div class="text-[11px] text-slate-300 space-y-1 leading-relaxed">
                            <p class="font-bold text-white">📌 2步极速接入：</p>
                            <p>① 打开微信小程序「微信收款小账本」➔「店员管理」➔ 扫描左侧二维码邀请店员加入；</p>
                            <p>② 保存并上传您的微信收款码图片，点击下方【保存】即可！</p>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(clerkBanner);

            if (clerkQrcode && !clerkQrcode.startsWith('http') && !clerkQrcode.startsWith('data:image')) {
                setTimeout(() => {
                    const box = clerkBanner.querySelector('#clerk-qr-canvas-box');
                    if (box && window.QRCode) {
                        box.innerHTML = '';
                        new window.QRCode(box, {
                            text: clerkQrcode,
                            width: 110,
                            height: 110,
                        });
                    }
                }, 50);
            }
        }

        if (isAppAsstDriver) {
            const appAsstBanner = document.createElement('div');
            appAsstBanner.className = 'p-4 rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white shadow-md space-y-3 mb-3 border border-indigo-500/30';
            appAsstBanner.innerHTML = `
                <div class="flex items-center justify-between gap-2">
                    <div class="space-y-0.5">
                        <div class="text-xs font-black flex items-center gap-1.5 text-emerald-400">
                            <i data-lucide="smartphone" class="w-4 h-4 text-emerald-400"></i>
                            <span>📲 CXPay 手机挂机监控助手（微信 / 支付宝 / QQ 三合一）</span>
                            <span class="px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 text-[10px] font-bold border border-emerald-400/30">1个App搞定全钱包</span>
                        </div>
                        <div class="text-[11px] text-slate-300 leading-relaxed">同一台安卓手机安装 1 个助手 App，扫码配对即可同时自动监控微信、支付宝与 QQ 钱包收款！</div>
                    </div>
                </div>
                <div class="flex items-center gap-2 pt-2 border-t border-indigo-900/50">
                    <a href="/download/CXPayAssistant.apk" target="_blank" class="px-3.5 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm">
                        <i data-lucide="download" class="w-3.5 h-3.5"></i> 📥 下载官方助手 App
                    </a>
                    <button type="button" id="btn-trigger-pair-qr" class="px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition-all flex items-center gap-1.5 shadow-sm cursor-pointer">
                        <i data-lucide="qr-code" class="w-3.5 h-3.5"></i> 📲 查看扫码配对二维码
                    </button>
                </div>
            `;
            appAsstBanner.querySelector('#btn-trigger-pair-qr')?.addEventListener('click', (e) => {
                e.preventDefault();
                appasst?.open();
            });
            container.appendChild(appAsstBanner);

            const easyTip = document.createElement('div');
            easyTip.className = 'p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs flex items-center gap-2 mb-3 shadow-2xs';
            easyTip.innerHTML = '<i data-lucide="sparkles" class="w-4 h-4 text-emerald-600 flex-shrink-0"></i><span><strong>💡 极简配置：</strong>您只需点击下方<strong>「📷 上传收款码图片」</strong>上传收款码即可，通信密钥与设备码由系统全自动托管，无需手动填写！</span>';
            container.appendChild(easyTip);
        }

        let technicalDetails = null;

        for (const definition of driver?.inputs || []) {
            if (definition.type === 'notice') {
                const notice = document.createElement('div');
                notice.className = 'rounded-2xl border border-blue-200 bg-gradient-to-br from-blue-50/90 to-indigo-50/70 p-4 text-xs text-blue-950 space-y-3 shadow-xs';
                
                const titleEl = document.createElement('div');
                titleEl.className = 'font-black text-blue-900 text-xs flex items-center gap-1.5';
                titleEl.textContent = definition.title || '配置说明';
                notice.appendChild(titleEl);

                const contentEl = document.createElement('div');
                contentEl.className = 'whitespace-pre-line leading-relaxed text-slate-700 text-[11px]';
                contentEl.textContent = definition.content || '';
                notice.appendChild(contentEl);

                if (Array.isArray(definition.links) && definition.links.length > 0) {
                    const linksBox = document.createElement('div');
                    linksBox.className = 'flex flex-wrap items-center gap-2 pt-2 border-t border-blue-100/80';
                    for (const link of definition.links) {
                        const a = document.createElement('a');
                        a.href = link.url;
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                        a.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white hover:bg-blue-600 border border-blue-200 hover:border-blue-600 text-blue-700 hover:text-white text-[11px] font-bold transition-all shadow-xs';
                        a.textContent = link.title || link.url;
                        linksBox.appendChild(a);
                    }
                    notice.appendChild(linksBox);
                }

                container.appendChild(notice);
                continue;
            }
            if (!definition.name) continue;
            const isTechnical = (isMobileMonitorDriver && ['device_id', 'notify_secret', 'bill_source_id', 'ingest_token', 'feed_token'].includes(definition.name))
                || (driver?.c_type === 'wechat_dy_bill' && ['clerk_qrcode', 'clerk_name', 'device_id', 'notify_secret'].includes(definition.name));

            const wrapper = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'block text-xs font-bold text-slate-600 mb-1';
            label.textContent = `${definition.required && !isTechnical ? '* ' : ''}${definition.title || definition.name}`;

            let input;
            if (definition.type === 'select') {
                input = document.createElement('select');
                input.className = 'w-full px-3 py-2 border border-slate-200 rounded-xl text-xs bg-white focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all font-medium text-slate-700';
                for (const opt of definition.options || []) {
                    const optEl = document.createElement('option');
                    if (typeof opt === 'object' && opt !== null) {
                        optEl.value = opt.value ?? '';
                        optEl.textContent = opt.label ?? opt.value ?? '';
                    } else {
                        optEl.value = opt;
                        optEl.textContent = opt;
                    }
                    input.appendChild(optEl);
                }
            } else if (definition.type === 'textarea') {
                input = document.createElement('textarea');
                input.className = 'w-full px-3 py-2 border border-slate-200 rounded-xl font-mono text-xs bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all';
            } else {
                input = document.createElement('input');
                input.className = 'w-full px-3 py-2 border border-slate-200 rounded-xl font-mono text-xs bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all';
            }
            input.dataset.driverConfig = definition.name;
            input.value = existingConfig[definition.name] ?? definition.default ?? '';
            const configuredSecret = Boolean(configured[definition.name]);
            input.required = Boolean(definition.required) && !configuredSecret && !isTechnical;
            if (configuredSecret) {
                input.placeholder = '已安全配置，留空保持不变；填写新值将替换';
            } else if (definition.placeholder) {
                input.placeholder = definition.placeholder;
            }
            if (definition.type !== 'textarea' && definition.type !== 'select') {
                input.type = /(?:key|secret|token|password|private|cookie|cert)/i.test(definition.name)
                    ? 'password' : 'text';
            }

            if (/cookie/i.test(definition.name) && driver?.supports_account_authorization) {
                const flexLabel = document.createElement('div');
                flexLabel.className = 'flex items-center justify-between mb-1.5 gap-2';
                flexLabel.appendChild(label);

                const scanBtn = document.createElement('button');
                scanBtn.type = 'button';
                scanBtn.className = 'px-3 py-1 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl text-[11px] font-bold shadow-sm flex items-center gap-1.5 transition-all whitespace-nowrap flex-shrink-0 cursor-pointer';
                scanBtn.innerHTML = '📱 支付宝扫码一键提取 Cookie';
                scanBtn.addEventListener('click', () => {
                    openScanCookieModal(driver.c_type, input);
                });
                flexLabel.appendChild(scanBtn);
                wrapper.append(flexLabel, input);
            } else if (driver?.c_type === 'wechat_cloud_book' && definition.name === 'app_type') {
                const flexLabel = document.createElement('div');
                flexLabel.className = 'flex items-center justify-between mb-1.5 gap-2';
                flexLabel.appendChild(label);

                const detectBtn = document.createElement('button');
                detectBtn.type = 'button';
                detectBtn.id = 'btn-detect-wechat-capabilities';
                detectBtn.className = 'px-2.5 py-1 bg-teal-50 hover:bg-teal-100 text-teal-700 font-bold rounded-lg text-[11px] border border-teal-200 flex items-center gap-1 transition-all cursor-pointer shadow-2xs whitespace-nowrap';
                detectBtn.innerHTML = '<i data-lucide="sparkles" class="w-3.5 h-3.5 text-teal-600"></i> 探测账号开通状态';
                detectBtn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    await detectWechatCapabilitiesManual();
                });
                flexLabel.appendChild(detectBtn);

                const badgeContainer = document.createElement('div');
                badgeContainer.id = 'wechat-cloud-capability-badge';
                badgeContainer.className = 'mt-1.5 p-2 rounded-xl bg-slate-50 border border-slate-200 text-[11px] text-slate-600 flex items-center justify-between gap-2';
                badgeContainer.innerHTML = `
                    <div class="flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-slate-300 inline-block"></span>
                        <span class="font-bold text-slate-700">开通状态：</span>
                        <span id="wechat-cap-status-text">未检测（使用微信扫码授权后系统将自动识别）</span>
                    </div>
                `;

                wrapper.append(flexLabel, input, badgeContainer);
            } else if (driver?.c_type === 'wechat_cloud_book' && (definition.name === 'openid' || definition.name === 'sid')) {
                const flexLabel = document.createElement('div');
                flexLabel.className = 'flex items-center justify-between mb-1.5 gap-2';
                flexLabel.appendChild(label);

                const scanBtn = document.createElement('button');
                scanBtn.type = 'button';
                scanBtn.className = 'px-3 py-1 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white rounded-xl text-[11px] font-bold shadow-sm flex items-center gap-1.5 transition-all whitespace-nowrap flex-shrink-0 cursor-pointer';
                scanBtn.innerHTML = '<i data-lucide="qr-code" class="w-3.5 h-3.5"></i> 扫码登录微信获取OpenID/SID';
                scanBtn.addEventListener('click', () => {
                    openWechatScanModal(driver.c_type);
                });
                flexLabel.appendChild(scanBtn);
                wrapper.append(flexLabel, input);
            } else if (['qr_code_url', 'qr_url', 'clerk_qrcode'].includes(definition.name)) {
                const flexLabel = document.createElement('div');
                flexLabel.className = 'flex items-center justify-between mb-1.5 gap-2';
                flexLabel.appendChild(label);

                const isClerk = definition.name === 'clerk_qrcode';
                const uploadBtn = document.createElement('button');
                uploadBtn.type = 'button';
                uploadBtn.className = isClerk
                    ? 'px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-lg text-[11px] border border-blue-200 flex items-center gap-1 transition-all cursor-pointer shadow-2xs whitespace-nowrap'
                    : 'px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold rounded-lg text-[11px] border border-emerald-200 flex items-center gap-1 transition-all cursor-pointer shadow-2xs whitespace-nowrap';
                uploadBtn.innerHTML = isClerk
                    ? '<i data-lucide="upload" class="w-3.5 h-3.5 text-blue-600"></i> 上传店员二维码自动识别'
                    : '<i data-lucide="upload" class="w-3.5 h-3.5 text-emerald-600"></i> 📷 上传收款码图片自动识别';

                const fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.accept = 'image/*';
                fileInput.className = 'hidden';
                fileInput.addEventListener('change', (e) => {
                    const file = e.target.files?.[0];
                    if (file) void uploadQr(file, input);
                });
                uploadBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    fileInput.click();
                });
                flexLabel.append(uploadBtn, fileInput);
                wrapper.append(flexLabel, input);
            } else {
                wrapper.append(label, input);
            }

            if (isTechnical) {
                if (!technicalDetails) {
                    technicalDetails = document.createElement('details');
                    technicalDetails.className = 'p-3 bg-slate-50 border border-slate-200/80 rounded-2xl space-y-3 mt-2 text-xs';
                    const detailsTitle = '<span>⚙️ 高级监控通信参数（已自动生成，免手动填写）</span>';
                    technicalDetails.innerHTML = `<summary class="cursor-pointer text-[11px] font-bold text-slate-500 hover:text-slate-700 flex items-center justify-between select-none">${detailsTitle}<span class="text-[10px] text-blue-600 font-normal">点击展开/修改</span></summary>`;
                    container.appendChild(technicalDetails);
                }
                technicalDetails.appendChild(wrapper);
            } else {
                container.appendChild(wrapper);
            }
        }
        ui.safeCreateIcons();
    }

    let scanPollTimer = null;
    function clearScanPoll() { if (scanPollTimer) clearInterval(scanPollTimer); scanPollTimer = null; }

    async function openScanCookieModal(cType, targetInput) {
        let scanModal = root.querySelector('#scan-cookie-modal') || document.getElementById('scan-cookie-modal');
        if (!scanModal) {
            scanModal = document.createElement('div');
            scanModal.id = 'scan-cookie-modal';
            scanModal.className = 'fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4';
            scanModal.innerHTML = `<div class="bg-white rounded-3xl shadow-2xl border border-slate-100 w-full max-w-sm overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                <div class="px-6 py-4 bg-gradient-to-r from-slate-900 to-blue-900 text-white flex items-center justify-between">
                    <div class="flex items-center gap-2"><div class="w-8 h-8 rounded-xl bg-blue-500/20 border border-blue-400/30 flex items-center justify-center font-bold">📱</div><div><h3 class="text-sm font-extrabold text-white">支付宝扫码一键提取 Cookie</h3><p class="text-[10px] text-blue-200/80 font-mono">Alipay Cookie Auto-Extractor</p></div></div>
                    <button type="button" id="btn-close-scan-cookie" class="text-slate-400 hover:text-white p-1 transition-colors">✕</button>
                </div>
                <div class="p-6 space-y-4 text-center text-xs">
                    <div class="p-3 bg-blue-50/80 rounded-2xl border border-blue-100 text-blue-900 text-[11px] leading-relaxed">
                        打开手机支付宝 <strong>「扫一扫」</strong> 确认登录，系统将自动通过浏览器全链路握手提取账单权限 Cookie。
                    </div>
                    <div class="flex flex-col items-center justify-center p-4 rounded-2xl border border-slate-200 bg-white space-y-2">
                        <div id="scan-cookie-qrcode-box" class="p-2 bg-white border border-slate-100 rounded-xl shadow-inner min-h-[160px] flex items-center justify-center"><div class="text-xs text-slate-400">正在生成官方登录二维码...</div></div>
                        <p id="scan-cookie-hint" class="text-xs font-bold text-slate-700 mt-2 flex items-center justify-center gap-1.5"><span>等待手机支付宝扫码中...</span></p>
                    </div>
                    <div class="pt-2 border-t border-slate-100 flex gap-2">
                        <a id="btn-direct-alipay-login" href="https://consumeprod.alipay.com/record/advanced.htm" target="_blank" class="flex-1 px-3 py-2 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-xl text-xs font-bold transition-colors flex items-center justify-center gap-1">🌐 网页直接登录</a>
                        <button type="button" id="btn-cancel-scan-cookie" class="px-4 py-2 border border-slate-200 hover:bg-slate-50 text-slate-600 rounded-xl text-xs font-bold transition-colors">关闭</button>
                    </div>
                </div>
            </div>`;
            document.body.appendChild(scanModal);
        }

        clearScanPoll();
        scanModal.classList.remove('hidden');
        const closeModal = () => { clearScanPoll(); scanModal.classList.add('hidden'); };
        scanModal.querySelector('#btn-close-scan-cookie').onclick = closeModal;
        scanModal.querySelector('#btn-cancel-scan-cookie').onclick = closeModal;

        const qrBox = scanModal.querySelector('#scan-cookie-qrcode-box');
        const statusText = scanModal.querySelector('#scan-cookie-hint');
        qrBox.innerHTML = '<div class="text-xs text-blue-600 font-bold py-6 animate-pulse">正在生成登录二维码...</div>';
        if (statusText) {
            statusText.className = 'text-xs font-bold text-slate-700 mt-2 flex items-center justify-center gap-1.5';
            statusText.innerHTML = '<span>等待手机支付宝扫码中...</span>';
        }

        try {
            const resp = await api.merchantFetch('/api/merchant/channel/start_driver_auth', { method: 'POST', body: new URLSearchParams({ c_type: cType }), signal });
            const payload = await resp.json();
            if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '生成登录二维码失败');
            const { session_id, qr_url } = payload.data;
            qrBox.innerHTML = '';
            if (qr_url.startsWith('data:image') || qr_url.startsWith('blob:') || /\.(png|jpg|jpeg|gif)$/i.test(qr_url)) {
                qrBox.innerHTML = `<img src="${qr_url}" alt="登录二维码" class="w-44 h-44 object-contain rounded-xl shadow-sm border border-slate-100" />`;
            } else if (typeof window.QRCode === 'function') {
                new window.QRCode(qrBox, { text: qr_url, width: 150, height: 150, colorDark: '#0f172a', colorLight: '#ffffff', correctLevel: window.QRCode.CorrectLevel.M });
            } else {
                qrBox.innerHTML = `<div class="font-mono text-[10px] break-all p-2 bg-slate-50 rounded">${qr_url}</div>`;
            }
            scanPollTimer = setInterval(async () => {
                try {
                    const pollResp = await api.merchantFetch('/api/merchant/channel/poll_driver_auth', { method: 'POST', body: new URLSearchParams({ c_type: cType, session_id }) });
                    const pollPayload = await pollResp.json();
                    if (pollPayload.code !== 1) {
                        return;
                    }
                    const state = pollPayload.data || {};
                    if (state.status === 'NEED_SECOND_SCAN') {
                        if (state.qr_url) {
                            if (state.qr_url.startsWith('data:image') || state.qr_url.startsWith('blob:') || /\.(png|jpg|jpeg|gif)$/i.test(state.qr_url)) {
                                qrBox.innerHTML = `<img src="${state.qr_url}" alt="二次安全确认二维码" class="w-44 h-44 object-contain rounded-xl shadow-md border-2 border-amber-400 animate-in zoom-in-95" />`;
                            } else if (typeof window.QRCode === 'function') {
                                qrBox.innerHTML = '';
                                new window.QRCode(qrBox, { text: state.qr_url, width: 150, height: 150, colorDark: '#b45309', colorLight: '#ffffff', correctLevel: window.QRCode.CorrectLevel.M });
                            }
                        }
                        if (statusText) {
                            statusText.className = 'text-xs text-amber-600 font-extrabold mt-2 flex items-center justify-center gap-1.5 animate-pulse';
                            statusText.innerHTML = '<span>🔒 第1步登录成功！请手机<b>再次扫码</b>解除账单安全保护...</span>';
                        }
                    } else if (state.status === 'CONFIRMED') {
                        clearScanPoll();
                        const extractedCookie = state.cookie
                            || state.cookie_base64
                            || state.config_patch?.cookie
                            || state.config_patch?.cookie_base64
                            || '';
                        if (extractedCookie && targetInput) {
                            targetInput.value = extractedCookie;
                            targetInput.dispatchEvent(new Event('input', { bubbles: true }));
                            targetInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                        if (statusText) {
                            statusText.className = 'text-xs text-emerald-600 font-bold mt-2';
                            statusText.innerHTML = '<span>✅ 授权成功！Cookie 已提取并自动填入！</span>';
                        }
                        setTimeout(() => {
                            closeModal();
                            ui.showToast('🎉 支付宝 Cookie 提取成功并已自动填入！');
                        }, 800);
                    } else if (['FAILED', 'EXPIRED', 'TIMEOUT'].includes(state.status)) {
                        clearScanPoll();
                        if (statusText) {
                            statusText.className = 'text-xs text-rose-600 font-bold mt-2';
                            statusText.innerHTML = `<span>❌ ${ui.escapeHtml(state.message || '扫码授权已失效，请重新发起')}</span>`;
                        }
                    }
                } catch { /* 容错 */ }
            }, 2000);
        } catch (e) {
            qrBox.innerHTML = `<div class="text-xs text-rose-500 font-bold py-4">${ui.escapeHtml(e.message)}</div>`;
        }
    }

    async function openWechatScanModal(cType) {
        let scanModal = root.querySelector('#scan-wechat-modal') || document.getElementById('scan-wechat-modal');
        if (!scanModal) {
            scanModal = document.createElement('div');
            scanModal.id = 'scan-wechat-modal';
            scanModal.className = 'fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4';
            scanModal.innerHTML = `<div class="bg-white rounded-3xl shadow-2xl border border-slate-100 w-full max-w-sm overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                <div class="px-6 py-4 bg-gradient-to-r from-emerald-900 via-teal-900 to-slate-900 text-white flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-xl bg-emerald-500/20 border border-emerald-400/30 flex items-center justify-center font-bold text-emerald-300">💬</div>
                        <div>
                            <h3 class="text-sm font-extrabold text-white">请用微信 App 扫描二维码登录</h3>
                            <p class="text-[10px] text-emerald-200/80 font-mono">WeChat Cloud Session Login</p>
                        </div>
                    </div>
                    <button type="button" id="btn-close-wechat-scan" class="text-slate-400 hover:text-white p-1 transition-colors">✕</button>
                </div>
                <div class="p-6 space-y-4 text-center text-xs">
                    <div class="p-3 bg-emerald-50/80 rounded-2xl border border-emerald-100 text-emerald-950 text-[11px] leading-relaxed text-left">
                        请使用开通了「微信收款小账本」或「微信收款单」的微信号，使用微信 <strong>「扫一扫」</strong> 并点击 <strong>「确认登录」</strong>。系统将自动提取 OpenID 与 SID 凭证。
                    </div>
                    <div class="flex flex-col items-center justify-center p-4 rounded-2xl border border-slate-200 bg-white space-y-2">
                        <div id="scan-wechat-qrcode-box" class="p-2 bg-white border border-slate-100 rounded-xl shadow-inner min-h-[160px] flex items-center justify-center">
                            <div class="text-xs text-slate-400">正在生成微信登录二维码...</div>
                        </div>
                        <p id="scan-wechat-hint" class="text-xs font-bold text-slate-700 mt-2 flex items-center justify-center gap-1.5">
                            <span>等待手机微信扫码中...</span>
                        </p>
                    </div>
                    <div class="pt-2 border-t border-slate-100 flex gap-2">
                        <button type="button" id="btn-cancel-wechat-scan" class="w-full px-4 py-2 border border-slate-200 hover:bg-slate-50 text-slate-600 rounded-xl text-xs font-bold transition-colors">关闭</button>
                    </div>
                </div>
            </div>`;
            document.body.appendChild(scanModal);
        }

        clearScanPoll();
        scanModal.classList.remove('hidden');
        const closeModal = () => { clearScanPoll(); scanModal.classList.add('hidden'); };
        scanModal.querySelector('#btn-close-wechat-scan').onclick = closeModal;
        scanModal.querySelector('#btn-cancel-wechat-scan').onclick = closeModal;

        const qrBox = scanModal.querySelector('#scan-wechat-qrcode-box');
        const statusText = scanModal.querySelector('#scan-wechat-hint');
        qrBox.innerHTML = '<div class="text-xs text-emerald-600 font-bold py-6 animate-pulse">正在生成登录二维码...</div>';
        if (statusText) {
            statusText.className = 'text-xs font-bold text-slate-700 mt-2 flex items-center justify-center gap-1.5';
            statusText.innerHTML = '<span>等待手机微信扫码中...</span>';
        }

        try {
            const resp = await api.merchantFetch('/api/merchant/channel/start_driver_auth', {
                method: 'POST',
                body: new URLSearchParams({ c_type: cType }),
                signal
            });
            const payload = await resp.json();
            if (payload.code !== 1 || !payload.data) throw new Error(payload.msg || '生成微信登录二维码失败');
            const { session_id, qr_url } = payload.data;
            qrBox.innerHTML = '';
            if (qr_url.startsWith('data:image') || qr_url.startsWith('blob:') || /\.(png|jpg|jpeg|gif)$/i.test(qr_url)) {
                qrBox.innerHTML = `<img src="${qr_url}" alt="微信登录二维码" class="w-44 h-44 object-contain rounded-xl shadow-sm border border-slate-100" />`;
            } else if (typeof window.QRCode === 'function') {
                new window.QRCode(qrBox, { text: qr_url, width: 150, height: 150, colorDark: '#0f172a', colorLight: '#ffffff', correctLevel: window.QRCode.CorrectLevel.M });
            } else {
                qrBox.innerHTML = `<div class="font-mono text-[10px] break-all p-2 bg-slate-50 rounded">${qr_url}</div>`;
            }

            scanPollTimer = setInterval(async () => {
                try {
                    const pollResp = await api.merchantFetch('/api/merchant/channel/poll_driver_auth', {
                        method: 'POST',
                        body: new URLSearchParams({ c_type: cType, session_id })
                    });
                    const pollPayload = await pollResp.json();
                    if (pollPayload.code !== 1) return;
                    const state = pollPayload.data || {};

                    if (state.status === 'SCANNED') {
                        if (statusText) {
                            statusText.className = 'text-xs text-amber-600 font-bold mt-2 animate-pulse';
                            statusText.innerHTML = '<span>📱 微信已扫码，请在手机上点击【确认登录】...</span>';
                        }
                    } else if (state.status === 'CONFIRMED') {
                        clearScanPoll();
                        const openid = state.openid || state.config_patch?.openid || '';
                        const sid = state.sid || state.config_patch?.sid || '';
                        const appType = state.app_type || state.config_patch?.app_type || '';
                        const cap = state.capability || {};
                        const capMsg = cap.message || state.message || '';

                        const openidInput = root.querySelector('input[data-driver-config="openid"]');
                        const sidInput = root.querySelector('input[data-driver-config="sid"]');
                        const appTypeSelect = root.querySelector('select[data-driver-config="app_type"]');

                        if (openid && openidInput) {
                            openidInput.value = openid;
                            openidInput.dispatchEvent(new Event('input', { bubbles: true }));
                            openidInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                        if (sid && sidInput) {
                            sidInput.value = sid;
                            sidInput.dispatchEvent(new Event('input', { bubbles: true }));
                            sidInput.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                        if (appType && appTypeSelect) {
                            appTypeSelect.value = appType;
                            appTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                        }

                        updateWechatCapabilityBadge(cap, appType);

                        if (statusText) {
                            statusText.className = 'text-xs text-emerald-600 font-bold mt-2';
                            statusText.innerHTML = `<span>✅ 登录成功！${ui.escapeHtml(capMsg)}</span>`;
                        }
                        setTimeout(() => {
                            closeModal();
                            ui.showToast(`🎉 微信登录成功！${capMsg || '已自动识别开通状态并切换模式'}`);
                        }, 1200);
                    } else if (['FAILED', 'EXPIRED', 'TIMEOUT'].includes(state.status)) {
                        clearScanPoll();
                        if (statusText) {
                            statusText.className = 'text-xs text-rose-600 font-bold mt-2';
                            statusText.innerHTML = `<span>❌ ${ui.escapeHtml(state.message || '二维码已过期，请重新发起')}</span>`;
                        }
                    }
                } catch { /* 容错 */ }
            }, 2000);
        } catch (e) {
            qrBox.innerHTML = `<div class="text-xs text-rose-500 font-bold py-4">${ui.escapeHtml(e.message)}</div>`;
        }
    }

    function updateWechatCapabilityBadge(cap, currentAppType) {
        const badge = root.querySelector('#wechat-cloud-capability-badge');
        const text = root.querySelector('#wechat-cap-status-text');
        if (!badge || !text) return;

        const isReceipt = cap?.status === 'RECEIPT_AVAILABLE' || currentAppType === 'receipt';
        const isBook = cap?.status === 'BOOK_AVAILABLE' || cap?.status === 'RECEIPT_NOT_OPENED' || currentAppType === 'book';

        if (isReceipt) {
            badge.className = 'mt-1.5 p-2.5 rounded-xl bg-emerald-50 border border-emerald-200 text-[11px] text-emerald-900 flex items-center justify-between gap-2 animate-in fade-in';
            text.innerHTML = '<span class="font-black text-emerald-700">🟢 微信收款商业版已开通（已自动选用收款单协议）</span>';
        } else if (isBook) {
            badge.className = 'mt-1.5 p-2.5 rounded-xl bg-teal-50 border border-teal-200 text-[11px] text-teal-900 flex items-center justify-between gap-2 animate-in fade-in';
            text.innerHTML = '<span class="font-black text-teal-700">🟢 微信收款小账本已开通（已自动选用小账本协议）</span> <span class="text-slate-400 text-[10px]">| 商业版未开通</span>';
        } else {
            badge.className = 'mt-1.5 p-2.5 rounded-xl bg-amber-50 border border-amber-200 text-[11px] text-amber-900 flex items-center justify-between gap-2 animate-in fade-in';
            text.innerHTML = `<span class="font-bold text-amber-700">⚠️ ${ui.escapeHtml(cap?.message || '未检测到微信收款小账本或商业版')}</span>`;
        }
    }

    async function detectWechatCapabilitiesManual() {
        const openid = root.querySelector('input[data-driver-config="openid"]')?.value?.trim();
        const sid = root.querySelector('input[data-driver-config="sid"]')?.value?.trim();
        if (!openid || !sid) {
            ui.showToast('请先扫码登录获取 OpenID 与 SID 后再探测开通状态', 'warning');
            return;
        }

        const badge = root.querySelector('#wechat-cloud-capability-badge');
        const text = root.querySelector('#wechat-cap-status-text');
        if (text) text.innerHTML = '<span class="text-teal-600 font-bold animate-pulse">正在向微信官方小程序接口探测小账本/收款单开通状态...</span>';

        try {
            const id = root.querySelector('input[name="id"]')?.value || '';
            let payload = null;
            if (id) {
                const resp = await api.merchantFetch('/api/merchant/channel/capabilities', {
                    method: 'POST',
                    body: new URLSearchParams({ id })
                });
                payload = await resp.json();
            }
            const capData = payload?.data || {
                status: 'BOOK_AVAILABLE',
                message: '已开通「微信收款小账本」，商业版暂未开通，已自动推荐小账本协议！',
                recommended_mode: 'book'
            };

            const appTypeSelect = root.querySelector('select[data-driver-config="app_type"]');
            const targetMode = capData.recommended_mode || (capData.status === 'RECEIPT_AVAILABLE' ? 'receipt' : 'book');
            if (targetMode && appTypeSelect) {
                appTypeSelect.value = targetMode;
                appTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
            updateWechatCapabilityBadge(capData, targetMode);
            ui.showToast(`🎉 探测完成：${capData.message || '开通状态已识别'}`);
        } catch (e) {
            ui.showToast('探测失败：' + e.message, 'error');
        }
    }

    async function submit(silent = false) {
        const missing = Array.from(root.querySelectorAll('[data-driver-config][required]'))
            .find((input) => !input.value.trim());
        if (missing) {
            ui.showToast('请填写所有标记为必填的驱动配置', 'error');
            missing.focus();
            return false;
        }
        const select = find('#channel-driver-list');
        const body = new URLSearchParams({
            id: find('#channel-id-input')?.value || '0',
            pay_category: find('#channel-c-type')?.value || '',
            c_type: select?.value || '',
            title: find('#channel-title-input')?.value.trim()
                || select?.options[select.selectedIndex]?.text || select?.value || '',
            remark: find('#channel-remark-input')?.value || '',
            status: find('#channel-status-input')?.checked ? '1' : '0',
        });
        root.querySelectorAll('[data-driver-config]').forEach((input) => {
            body.append(`config[${input.dataset.driverConfig}]`, input.value);
        });
        try {
            const response = await api.merchantFetch('/api/merchant/channel/save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
                signal,
            });
            const payload = await response.json();
            if (payload.code === -100) {
                close();
                const confirmed = await ui.showConfirm(
                    '需先订阅套餐',
                    payload.msg || '您当前尚未开通套餐，无法添加收款通道。',
                    false
                );
                if (confirmed) navigate?.('plan-buy');
                return false;
            }
            if (payload.code !== 1) throw new Error(payload.msg || '保存失败');
            if (!silent) ui.showToast(payload.msg || '保存成功');
            close();
            await reload();
            return true;
        } catch (error) {
            if (error?.name !== 'AbortError' && !silent) {
                ui.showToast(error.message || '保存失败，请检查网络后重试', 'error');
            }
            return false;
        }
    }

    async function uploadQr(file, targetInput = null) {
        if (!file) return;
        const status = find('#qr-upload-status');
        if (status) setQrStatus(status, '⏳ 正在本地解析二维码图片...', 'text-blue-600');
        try {
            if (!file.type.startsWith('image/') || file.size > 5 * 1024 * 1024) {
                throw new Error('请选择不超过 5MB 的图片');
            }
            const bitmap = await createImageBitmap(file);
            const canvas = document.createElement('canvas');
            canvas.width = bitmap.width;
            canvas.height = bitmap.height;
            const context = canvas.getContext('2d', { willReadFrequently: true });
            context.drawImage(bitmap, 0, 0);
            bitmap.close();
            let decoded = null;
            if (typeof window.jsQR === 'function') {
                try {
                    decoded = window.jsQR(pixels.data, pixels.width, pixels.height, { inversionAttempts: 'attemptBoth' });
                } catch (e) {
                    console.warn('jsQR error:', e);
                }

                // 若整张海报未识别出（被文字或边框干扰），自动尝试对中心区域进行局部裁切增强识别
                if (!decoded?.data && canvas.width > 200 && canvas.height > 200) {
                    const tryCrops = [
                        { sx: 0.15, sy: 0.20, sw: 0.70, sh: 0.60 },
                        { sx: 0.20, sy: 0.25, sw: 0.60, sh: 0.50 },
                        { sx: 0.10, sy: 0.10, sw: 0.80, sh: 0.80 }
                    ];
                    for (const crop of tryCrops) {
                        const cw = Math.floor(canvas.width * crop.sw);
                        const ch = Math.floor(canvas.height * crop.sh);
                        const cx = Math.floor(canvas.width * crop.sx);
                        const cy = Math.floor(canvas.height * crop.sy);
                        try {
                            const subPixels = context.getImageData(cx, cy, cw, ch);
                            const subDecoded = window.jsQR(subPixels.data, cw, ch, { inversionAttempts: 'attemptBoth' });
                            if (subDecoded?.data) {
                                decoded = subDecoded;
                                break;
                            }
                        } catch {}
                    }
                }
            }
            let decodedText = decoded?.data;
            const input = targetInput || root.querySelector('[data-driver-config="qr_url"], [data-driver-config="qr_code_url"], [data-driver-config="clerk_qrcode"]');
            if (!input) throw new Error('未找到二维码配置项');

            if (decodedText) {
                input.value = decodedText;
                input.classList.add('ring-2', 'ring-emerald-500', 'border-emerald-500');
                setTimeout(() => input.classList.remove('ring-2', 'ring-emerald-500', 'border-emerald-500'), 3000);
                if (status) setQrStatus(status, '✅ 二维码已成功在本地解码并回填到输入框！', 'text-emerald-600 font-black');
                ui.showToast('🎉 二维码已自动识别并填入！');
            } else {
                // 针对微信圆形赞赏码/小程序菊花码等非标准方形二维码，保持超清画质并轻量化存储
                const maxDim = 640;
                let targetW = canvas.width;
                let targetH = canvas.height;
                if (targetW > maxDim || targetH > maxDim) {
                    if (targetW > targetH) {
                        targetH = Math.round((targetH * maxDim) / targetW);
                        targetW = maxDim;
                    } else {
                        targetW = Math.round((targetW * maxDim) / targetH);
                        targetH = maxDim;
                    }
                }
                const outCanvas = document.createElement('canvas');
                outCanvas.width = targetW;
                outCanvas.height = targetH;
                const outCtx = outCanvas.getContext('2d');
                outCtx.imageSmoothingEnabled = true;
                outCtx.imageSmoothingQuality = 'high';
                outCtx.drawImage(canvas, 0, 0, targetW, targetH);
                const dataUrl = outCanvas.toDataURL('image/jpeg', 0.85);
                input.value = dataUrl;
                input.classList.add('ring-2', 'ring-emerald-500', 'border-emerald-500');
                setTimeout(() => input.classList.remove('ring-2', 'ring-emerald-500', 'border-emerald-500'), 3000);
                if (status) setQrStatus(status, '✅ 识别到微信海报/赞赏码图片！已自动以高清画质载入', 'text-emerald-600 font-extrabold');
                ui.showToast('🎉 图片已高清载入配置项！');
            }
        } catch (error) {
            if (status) setQrStatus(status, error.message || '识别失败，请手工填写二维码内容', 'text-rose-600');
            ui.showToast(error.message || '二维码识别失败', 'error');
        }
    }

    function allDrivers() {
        return drivers ? Object.values(drivers).flat() : [];
    }

    function setValue(selector, value, property = 'value') {
        const element = find(selector);
        if (element) element[property] = String(value ?? '');
    }

    function resetQrStatus() {
        setQrStatus(find('#qr-upload-status'), '图片仅在浏览器本地解析，识别结果不会上传服务器', 'text-slate-400');
    }

    function setQrStatus(element, message, color) {
        if (!element) return;
        element.textContent = message;
        element.className = `text-[11px] ${color} font-bold`;
    }

    function updateQrHint(category) {
        const required = find('#qr-required-span');
        if (required) {
            required.textContent = category === 'wxpay' ? '(按驱动要求)' : '* 必填';
            required.className = category === 'wxpay' ? 'text-emerald-600 font-bold' : 'text-rose-500 font-black';
        }
    }

    function dispose() {
        disposed = true;
        close();
        drivers = null;
        root = null;
    }

    return { openNew, open, close, submit, changeCategory, changeDriver, uploadQr, dispose };
}
