export const feature = {
    id: 'sys-config',

    async mount(context) {
        const { root, ui, signal } = context;

        root.addEventListener('click', (event) => {
            const action = event.target.closest('[data-action]')?.dataset.action;
            if (action === 'save-sys-config') void saveConfig(context);
        }, { signal });

        ui.safeCreateIcons();
        await loadConfig(context);
    },

    unmount() {},
};

async function loadConfig({ root, api, signal }) {
    try {
        const response = await api.adminFetch('/api/admin/system/config', { signal });
        const payload = await response.json();
        if (signal.aborted || payload.code !== 1 || !payload.data) return;

        const data = payload.data;

        setVal(root, 'syscfg-grant-balance', data.register_grant_balance ?? '10.00');
        setVal(root, 'syscfg-recharge-pid', data.system_recharge_pid ?? '1000');
        setVal(root, 'syscfg-site-name', data.site_name ?? '');
        setVal(root, 'syscfg-home-template', data.active_home_template ?? 'default');

        // 填充收款通道下拉菜单
        const wxSelect = root.querySelector('#syscfg-channel-wx');
        const aliSelect = root.querySelector('#syscfg-channel-ali');
        const badge = root.querySelector('#syscfg-channel-badge');

        if (wxSelect && aliSelect) {
            wxSelect.innerHTML = '<option value="0">自动选择可用微信通道</option>';
            aliSelect.innerHTML = '<option value="0">自动选择可用支付宝通道</option>';

            const channels = data.system_merchant?.channels || [];
            if (channels.length > 0) {
                if (badge) {
                    badge.className = 'px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded-full text-[10px] font-bold';
                    badge.textContent = `已连接商户 PID: ${data.system_recharge_pid} (${channels.length}个通道)`;
                }
                channels.forEach(ch => {
                    const opt = `<option value="${ch.id}">#${ch.id} ${ch.title} (${ch.c_type})</option>`;
                    if (ch.pay_category === 'wxpay' || ch.c_type.startsWith('wx')) {
                        wxSelect.insertAdjacentHTML('beforeend', opt);
                    }
                    if (ch.pay_category === 'alipay' || ch.c_type.startsWith('ali')) {
                        aliSelect.insertAdjacentHTML('beforeend', opt);
                    }
                });
            } else {
                if (badge) {
                    badge.className = 'px-2 py-0.5 bg-amber-100 text-amber-700 rounded-full text-[10px] font-bold';
                    badge.textContent = `商户 PID: ${data.system_recharge_pid} 尚未配置通道`;
                }
            }

            wxSelect.value = data.plugin_payment_channel_wx || '0';
            aliSelect.value = data.plugin_payment_channel_ali || '0';
        }

    } catch (error) {
        if (error?.name !== 'AbortError') console.error('系统配置加载失败', error);
    }
}

async function saveConfig({ root, api, ui, signal }) {
    const grantBalance = root.querySelector('#syscfg-grant-balance')?.value || '0';
    const rechargePid = (root.querySelector('#syscfg-recharge-pid')?.value || '').trim();
    const siteName = (root.querySelector('#syscfg-site-name')?.value || '').trim();
    const homeTemplate = (root.querySelector('#syscfg-home-template')?.value || 'default').trim();
    const channelWx = root.querySelector('#syscfg-channel-wx')?.value || '0';
    const channelAli = root.querySelector('#syscfg-channel-ali')?.value || '0';

    if (parseFloat(grantBalance) < 0) {
        ui.showToast('赠送金额不能为负数', 'error');
        return;
    }
    if (!rechargePid) {
        ui.showToast('系统收款商户 PID 不能为空', 'error');
        return;
    }

    try {
        const body = new URLSearchParams({
            register_grant_balance: parseFloat(grantBalance).toFixed(2),
            system_recharge_pid: rechargePid,
            active_home_template: homeTemplate,
            plugin_payment_channel_wx: channelWx,
            plugin_payment_channel_ali: channelAli,
            plugin_payment_mode: 'system_channel',
        });
        if (siteName) body.append('site_name', siteName);

        const response = await api.adminFetch('/api/admin/system/config/save', {
            method: 'POST',
            body,
            signal,
        });
        const payload = await response.json();

        if (payload.code !== 1) {
            ui.showToast(payload.msg || '保存失败', 'error');
            return;
        }

        ui.showToast(payload.msg || '系统运营与支付配置保存成功！');
        await loadConfig({ root, api, signal });

    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast('保存配置失败', 'error');
    }
}

function setVal(root, id, v) { const el = root.querySelector(`#${id}`); if (el) el.value = v ?? ''; }
function setText(root, id, v) { const el = root.querySelector(`#${id}`); if (el) el.textContent = v ?? '--'; }

