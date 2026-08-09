let root = null;

export const feature = {
    id: 'profile',
    async mount({ root: featureRoot, api, ui, signal, getMerchantProfile }) {
        root = featureRoot;
        root.addEventListener('click', async (event) => {
            if (event.target.closest?.('[data-action="change-password"]')) {
                await changePassword(api, ui);
            }
        }, { signal });
        const profile = await getMerchantProfile();
        setValue('profile-merchant-name', profile.name || '');
        setValue('profile-merchant-pid', profile.pid || '');
        ui.safeCreateIcons();
    },
    unmount() {
        root = null;
    },
};

async function changePassword(api, ui) {
    const currentPassword = value('profile-current-password');
    const newPassword = value('profile-new-password');
    try {
        const response = await api.merchantFetch('/api/merchant/change_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                current_password: currentPassword,
                new_password: newPassword,
            }),
        });
        const payload = await response.json();
        ui.showToast(payload.msg || '密码修改请求已处理', payload.code === 1 ? 'success' : 'error');
        if (payload.code === 1) {
            setValue('profile-current-password', '');
            setValue('profile-new-password', '');
        }
    } catch {
        ui.showToast('密码修改失败，请稍后重试', 'error');
    }
}

function value(id) {
    return root?.querySelector(`#${id}`)?.value || '';
}

function setValue(id, newValue) {
    const element = root?.querySelector(`#${id}`);
    if (element) element.value = newValue;
}
