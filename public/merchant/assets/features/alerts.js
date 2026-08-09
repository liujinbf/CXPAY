let state = null;

export const feature = {
    id: 'notice-config',
    async mount(context) {
        const { root, api, ui, signal } = context;
        const find = (selector) => root.querySelector(selector);

        function apply(data) {
            find('#m-alert-enabled').checked = Boolean(data.enabled);
            const events = data.events || {};
            find('#m-event-merchant_login').checked = Boolean(events.merchant_login);
            find('#m-event-order_paid').checked = Boolean(events.order_paid);
            find('#m-event-channel_offline').checked = Boolean(events.channel_offline);
            find('#m-event-low_balance').checked = Boolean(events.low_balance);
            find('#m-low-balance-threshold').value = data.low_balance_threshold ?? 10;

            const email = data.email_config || {};
            find('#m-email-enabled').checked = Boolean(email.enabled);
            find('#m-email-to').value = (email.to_addrs || []).join(', ');
            const wxwork = data.wxwork_config || {};
            find('#m-wxwork-enabled').checked = Boolean(wxwork.enabled);
            find('#m-wxwork-url').value = wxwork.webhook_url || '';
            const webhook = data.webhook_config || {};
            find('#m-webhook-enabled').checked = Boolean(webhook.enabled);
            find('#m-webhook-url').value = webhook.url || '';
        }

        function collect() {
            return {
                enabled: find('#m-alert-enabled').checked,
                low_balance_threshold: Number.parseFloat(find('#m-low-balance-threshold').value || '10'),
                events: {
                    merchant_login: find('#m-event-merchant_login').checked,
                    order_paid: find('#m-event-order_paid').checked,
                    channel_offline: find('#m-event-channel_offline').checked,
                    low_balance: find('#m-event-low_balance').checked,
                },
                email_config: {
                    enabled: find('#m-email-enabled').checked,
                    to_addrs: find('#m-email-to').value.split(',').map((value) => value.trim()).filter(Boolean),
                },
                wxwork_config: {
                    enabled: find('#m-wxwork-enabled').checked,
                    webhook_url: find('#m-wxwork-url').value.trim(),
                },
                webhook_config: {
                    enabled: find('#m-webhook-enabled').checked,
                    url: find('#m-webhook-url').value.trim(),
                },
            };
        }

        async function load() {
            try {
                const response = await api.merchantFetch('/api/merchant/alert/config', { signal });
                const payload = await response.json();
                if (payload.code !== 1 || !payload.data) {
                    throw new Error(payload.msg || '通知配置加载失败');
                }
                apply(payload.data);
            } catch (error) {
                if (error?.name !== 'AbortError') ui.showToast(error.message || '通知配置加载失败', 'error');
            }
        }

        async function save() {
            try {
                const response = await api.merchantFetch('/api/merchant/alert/config/save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(collect()),
                    signal,
                });
                const payload = await response.json();
                if (payload.code !== 1) throw new Error(payload.msg || '通知设置保存失败');
                ui.showToast(payload.msg || '通知设置已更新');
            } catch (error) {
                if (error?.name !== 'AbortError') ui.showToast(error.message || '通知设置保存失败', 'error');
            }
        }

        async function sendTest(channel) {
            try {
                const response = await api.merchantFetch('/api/merchant/alert/test', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ channel }),
                    signal,
                });
                const payload = await response.json();
                if (payload.code !== 1) throw new Error(payload.msg || '测试通知发送失败');
                ui.showToast(payload.msg || '测试通知已发送');
            } catch (error) {
                if (error?.name !== 'AbortError') ui.showToast(error.message || '测试通知发送失败', 'error');
            }
        }

        const onClick = async (event) => {
            const trigger = event.target.closest('[data-action]');
            if (!trigger || !root.contains(trigger)) return;
            if (trigger.dataset.action === 'save-alerts') await save();
            if (trigger.dataset.action === 'test-alert') await sendTest(trigger.dataset.channel);
        };
        root.addEventListener('click', onClick);
        state = { root, onClick };
        await load();
        ui.safeCreateIcons(root);
    },
    unmount() {
        if (!state) return;
        state.root.removeEventListener('click', state.onClick);
        state = null;
    },
};
