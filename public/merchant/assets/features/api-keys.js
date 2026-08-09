let root = null;

export const feature = {
    id: 'api-keys',
    async mount(context) {
        root = context.root;
        root.addEventListener('click', (event) => handleClick(event, context), { signal: context.signal });
        renderProfile(await context.getMerchantProfile());
        context.ui.safeCreateIcons();
    },
    unmount() {
        root = null;
    },
};

async function handleClick(event, context) {
    const target = event.target.closest?.('[data-action]');
    if (!target) return;
    if (target.dataset.action === 'copy') {
        const input = root?.querySelector(`#${target.dataset.copySource}`);
        await context.ui.copyText(input?.value || '', target);
    }
    if (target.dataset.action === 'reset-key') {
        await resetKey(context);
    }
}

async function resetKey({ api, ui, getMerchantProfile }) {
    const confirmed = await ui.showConfirm(
        '重新生成 API 密钥',
        '重置后所有已对接网站需同步更新新密钥才能正常交易，确认继续吗？',
        true
    );
    if (!confirmed) return;

    try {
        const response = await api.merchantFetch('/api/merchant/reset_key', { method: 'POST' });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.new_key) {
            ui.showToast(payload.msg || 'API 密钥重置失败', 'error');
            return;
        }
        setValue('epay-key', payload.new_key);
        ui.showToast(payload.msg || '密钥已成功重新生成！');
        renderProfile(await getMerchantProfile({ refresh: true }));
    } catch {
        ui.showToast('网络请求失败，请稍后重试', 'error');
    }
}

function renderProfile(profile) {
    const siteUrl = profile.site_url || (location.origin + '/');
    setText('api-card-pid', profile.pid || '');
    setValue('epay-pid-display', profile.pid || '');
    setValue('epay-site-url', siteUrl);
    setValue('epay-api-url', profile.gateway_url || (location.origin + '/submit.php'));
    setValue('epay-mapi-url', profile.mapi_url || (location.origin + '/mapi.php'));
    setValue('epay-key', profile.key || '');
}

function setText(id, value) {
    const element = root?.querySelector(`#${id}`);
    if (element) element.textContent = value;
}

function setValue(id, value) {
    const element = root?.querySelector(`#${id}`);
    if (element) element.value = value;
}
