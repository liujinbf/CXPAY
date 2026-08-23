export const feature = {
    id: 'security',

    async mount(context) {
        const { root, ui, signal } = context;

        root.addEventListener('click', (event) => {
            const action = event.target.closest('[data-action]')?.dataset.action;
            if (action === 'save-password') void savePassword(context);
            if (action === 'save-security') void saveSecurity(context);
        }, { signal });

        ui.safeCreateIcons();
        await loadSecurityConfig(context);
    },

    unmount() {},
};

async function loadSecurityConfig({ root, api, signal }) {
    try {
        const response = await api.adminFetch('/api/admin/security/config', { signal });
        const payload = await response.json();
        if (signal.aborted || payload.code !== 1 || !payload.data) return;

        const data = payload.data;

        // Token 版本号
        const versionEl = root.querySelector('#security-token-version');
        if (versionEl) versionEl.textContent = `Token 版本：v${data.token_version ?? 1}`;

        // 二次验证开关状态
        const enabledBadge = root.querySelector('#sec-verify-status-badge');
        if (enabledBadge) {
            enabledBadge.textContent = data.verify_enabled ? '✅ 已启用' : '❌ 未启用';
            enabledBadge.className = `px-2.5 py-1 rounded-full text-[11px] font-bold ${
                data.verify_enabled
                    ? 'bg-emerald-100 text-emerald-700'
                    : 'bg-slate-200 text-slate-500'
            }`;
        }

        // 验证码配置状态
        const configuredBadge = root.querySelector('#sec-verify-configured-badge');
        if (configuredBadge) {
            configuredBadge.textContent = data.verify_configured ? '验证码：已配置' : '验证码：未配置';
            configuredBadge.className = `px-2.5 py-1 rounded-full text-[11px] font-bold ${
                data.verify_configured
                    ? 'bg-blue-100 text-blue-700'
                    : 'bg-amber-100 text-amber-600'
            }`;
        }

        // 设置 checkbox 状态
        const checkbox = root.querySelector('#sec-verify-enabled');
        if (checkbox) checkbox.checked = Boolean(data.verify_enabled);

    } catch (error) {
        if (error?.name !== 'AbortError') console.error('安全设置加载失败', error);
    }
}

async function savePassword({ root, api, ui, signal }) {
    const currentPassword = root.querySelector('#sec-current-password')?.value || '';
    const newPassword = root.querySelector('#sec-new-password')?.value || '';
    const confirmPassword = root.querySelector('#sec-confirm-password')?.value || '';

    if (!currentPassword || !newPassword) {
        ui.showToast('当前密码和新密码不能为空', 'error');
        return;
    }
    if (newPassword.length < 6) {
        ui.showToast('新密码不能少于 6 位', 'error');
        return;
    }
    if (newPassword !== confirmPassword) {
        ui.showToast('两次输入的新密码不一致', 'error');
        return;
    }

    try {
        const response = await api.adminFetch('/api/admin/security/config/save', {
            method: 'POST',
            body: new URLSearchParams({
                current_password: currentPassword,
                new_password: newPassword,
            }),
            signal,
        });
        const payload = await response.json();

        if (payload.code !== 1) {
            ui.showToast(payload.msg || '密码修改失败', 'error');
            return;
        }

        ui.showToast('密码修改成功，即将跳转至登录页...');

        // 清除所有输入框
        ['sec-current-password', 'sec-new-password', 'sec-confirm-password'].forEach((id) => {
            const el = root.querySelector(`#${id}`);
            if (el) el.value = '';
        });

        // 密码修改后 Token 版本号递增，需重新登录
        setTimeout(() => {
            localStorage.removeItem('cx_admin_token');
            window.location.assign('/admin_login.html');
        }, 1500);

    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast('密码修改请求失败', 'error');
    }
}

async function saveSecurity({ root, api, ui, signal }) {
    const verifyEnabled = root.querySelector('#sec-verify-enabled')?.checked ? 1 : 0;
    const verifyCode = root.querySelector('#sec-verify-code')?.value?.trim() || '';

    if (verifyEnabled && !verifyCode) {
        // 检查是否已有验证码配置
        const configuredBadge = root.querySelector('#sec-verify-configured-badge');
        const alreadyConfigured = configuredBadge?.textContent?.includes('已配置');
        if (!alreadyConfigured) {
            ui.showToast('启用二次验证时，必须先设置验证码', 'error');
            return;
        }
    }

    if (verifyCode && (verifyCode.length < 4 || verifyCode.length > 32)) {
        ui.showToast('验证码长度须在 4～32 位之间', 'error');
        return;
    }

    try {
        const body = new URLSearchParams({ verify_enabled: String(verifyEnabled) });
        if (verifyCode) body.append('verify_code', verifyCode);

        const response = await api.adminFetch('/api/admin/security/config/save', {
            method: 'POST',
            body,
            signal,
        });
        const payload = await response.json();

        if (payload.code !== 1) {
            ui.showToast(payload.msg || '安全设置保存失败', 'error');
            return;
        }

        // 清空验证码输入框
        const codeInput = root.querySelector('#sec-verify-code');
        if (codeInput) codeInput.value = '';

        ui.showToast('安全设置已保存');
        // 刷新状态显示
        await loadSecurityConfig({ root, api, signal });

    } catch (error) {
        if (error?.name !== 'AbortError') ui.showToast('安全设置保存失败', 'error');
    }
}
