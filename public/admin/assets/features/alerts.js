const templateEvents = ['channel_offline', 'order_paid', 'admin_login', 'merchant_login', 'low_balance'];

export const feature = {
    id: 'alerts',
    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (target?.dataset.action === 'save-system-config') void saveSystemConfig(context);
            if (target?.dataset.action === 'save-alert-config') void saveAlertConfig(context);
            if (target?.dataset.action === 'test-alert') void testAlert(context, target.dataset.channel);
            if (target?.dataset.action === 'reset-alert-templates') resetTemplates(root, ui);
        }, { signal });
        root.querySelector('[data-action="smtp-preset"]')?.addEventListener('change', (event) => applySmtpPreset(root, event.target.value), { signal });
        ui.safeCreateIcons();
        await Promise.all([loadAlertConfig(context), loadSystemConfig(context)]);
    },
    unmount() {},
};

async function loadAlertConfig({ root, api, signal }) {
    try {
        const response = await api.adminFetch('/api/admin/alert/config', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data || signal.aborted) return;
        const data = payload.data;
        check(root, 'alert-admin-enabled', data.enabled);
        ['admin_login', 'merchant_login', 'order_paid', 'channel_offline'].forEach((event) => check(root, `alert-event-${event}`, data.events?.[event]));
        const email = data.email_config || {}; check(root, 'alert-email-enabled', email.enabled);
        value(root, 'alert-email-host', email.host); value(root, 'alert-email-port', email.port || 465); value(root, 'alert-email-encryption', email.encryption || 'ssl'); value(root, 'alert-email-username', email.username); value(root, 'alert-email-to', (email.to_addrs || []).join(', '));
        check(root, 'alert-wxwork-enabled', data.wxwork_config?.enabled); value(root, 'alert-wxwork-url', data.wxwork_config?.webhook_url);
        check(root, 'alert-webhook-enabled', data.webhook_config?.enabled); value(root, 'alert-webhook-url', data.webhook_config?.url);
        templateEvents.forEach((event) => { value(root, `template-title-${event}`, data.custom_templates?.[event]?.title); value(root, `template-body-${event}`, data.custom_templates?.[event]?.body); });
    } catch (error) { if (error?.name !== 'AbortError') console.error('加载告警配置失败', error); }
}

async function loadSystemConfig({ root, api, signal }) {
    try { const response = await api.adminFetch('/api/admin/system/config', { signal }); const payload = await response.json(); if (payload.code === 1) { value(root, 'sys-register-grant-balance', payload.data?.register_grant_balance || '10.00'); value(root, 'sys-recharge-pid', payload.data?.system_recharge_pid || '1000'); } } catch (error) { if (error?.name !== 'AbortError') console.error(error); }
}

async function saveSystemConfig({ root, api, ui, signal }) {
    try { const response = await api.adminFetch('/api/admin/system/config/save', { method: 'POST', body: new URLSearchParams({ register_grant_balance: get(root, 'sys-register-grant-balance'), system_recharge_pid: get(root, 'sys-recharge-pid') }), signal }); const payload = await response.json(); if (payload.code !== 1) throw new Error(payload.msg); ui.showToast(payload.msg || '系统运营配置保存成功！'); } catch (error) { if (error?.name !== 'AbortError') ui.showToast(error.message, 'error'); }
}

async function saveAlertConfig({ root, api, ui, signal }) {
    const templates = {}; templateEvents.forEach((event) => { const title = get(root, `template-title-${event}`).trim(); const body = get(root, `template-body-${event}`).trim(); if (title || body) templates[event] = { title, body }; });
    const data = { enabled: checked(root, 'alert-admin-enabled'), events: Object.fromEntries(['admin_login', 'merchant_login', 'order_paid', 'channel_offline'].map((event) => [event, checked(root, `alert-event-${event}`)])), email_config: { enabled: checked(root, 'alert-email-enabled'), host: get(root, 'alert-email-host').trim(), port: Number(get(root, 'alert-email-port')) || 465, encryption: get(root, 'alert-email-encryption'), username: get(root, 'alert-email-username').trim(), password: get(root, 'alert-email-password'), to_addrs: get(root, 'alert-email-to').split(',').map((item) => item.trim()).filter(Boolean) }, wxwork_config: { enabled: checked(root, 'alert-wxwork-enabled'), webhook_url: get(root, 'alert-wxwork-url').trim() }, webhook_config: { enabled: checked(root, 'alert-webhook-enabled'), url: get(root, 'alert-webhook-url').trim(), headers: get(root, 'alert-webhook-headers').trim() }, custom_templates: templates };
    try { const response = await api.adminFetch('/api/admin/alert/config/save', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data), signal }); const payload = await response.json(); if (payload.code !== 1) throw new Error(payload.msg); ui.showToast(payload.msg || '通知与模版配置保存成功！'); } catch (error) { if (error?.name !== 'AbortError') ui.showToast(error.message, 'error'); }
}

async function testAlert({ api, ui, signal }, channel) { try { const response = await api.adminFetch('/api/admin/alert/test', { method: 'POST', body: new URLSearchParams({ channel }), signal }); const payload = await response.json(); ui.showToast(payload.msg || '测试完成', payload.code === 1 ? 'success' : 'error'); } catch (error) { if (error?.name !== 'AbortError') ui.showToast(error.message, 'error'); } }
function applySmtpPreset(root, type) { const preset = { qq: ['smtp.qq.com', 465, 'ssl'], '163': ['smtp.163.com', 465, 'ssl'], '126': ['smtp.126.com', 465, 'ssl'], tencent_biz: ['smtp.exmail.qq.com', 465, 'ssl'], ali_biz: ['smtp.mxhichina.com', 465, 'ssl'], '163_biz': ['smtp.qiye.163.com', 465, 'ssl'] }[type]; if (preset) { value(root, 'alert-email-host', preset[0]); value(root, 'alert-email-port', preset[1]); value(root, 'alert-email-encryption', preset[2]); } }
function resetTemplates(root, ui) { templateEvents.forEach((event) => { value(root, `template-title-${event}`, ''); value(root, `template-body-${event}`, ''); }); ui.showToast('已清空自定义模版文本'); }
function get(root, id) { return root.querySelector(`#${id}`)?.value || ''; } function value(root, id, data = '') { const element = root.querySelector(`#${id}`); if (element) element.value = data || ''; } function checked(root, id) { return root.querySelector(`#${id}`)?.checked === true; } function check(root, id, state) { const element = root.querySelector(`#${id}`); if (element) element.checked = Boolean(state); }
