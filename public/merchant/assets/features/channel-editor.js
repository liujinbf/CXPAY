export function createChannelEditor({ root, api, ui, signal, reload, navigate }) {
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
        const category = find('#channel-c-type')?.value || 'wxpay';
        const select = find('#channel-driver-list');
        if (!select) return;
        select.innerHTML = '';
        updateQrHint(category);
        try {
            const catalog = await loadDrivers();
            for (const driver of catalog[category] || []) {
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
        for (const definition of driver?.inputs || []) {
            if (definition.type === 'notice') {
                const notice = document.createElement('div');
                notice.className = definition.tone === 'warning'
                    ? 'rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-950'
                    : 'rounded-2xl border border-blue-200 bg-blue-50 p-4 text-xs text-blue-950';
                notice.innerHTML = `<div class="mb-2 font-black"></div><div class="whitespace-pre-line leading-6"></div>`;
                notice.children[0].textContent = definition.title || '配置说明';
                notice.children[1].textContent = definition.content || '';
                container.appendChild(notice);
                continue;
            }
            if (!definition.name) continue;
            const wrapper = document.createElement('div');
            const label = document.createElement('label');
            label.className = 'block text-xs font-bold text-slate-600 mb-1';
            label.textContent = `${definition.required ? '* ' : ''}${definition.title || definition.name}`;
            const input = definition.type === 'textarea'
                ? document.createElement('textarea')
                : document.createElement('input');
            input.className = 'w-full px-3 py-2 border border-slate-200 rounded-xl font-mono text-xs bg-white';
            input.dataset.driverConfig = definition.name;
            input.value = existingConfig[definition.name] ?? definition.default ?? '';
            const configuredSecret = Boolean(configured[definition.name]);
            input.required = Boolean(definition.required) && !configuredSecret;
            if (configuredSecret) input.placeholder = '已安全配置，留空保持不变；填写新值将替换';
            if (definition.type !== 'textarea') {
                input.type = /(?:key|secret|token|password|private|cookie|cert)/i.test(definition.name)
                    ? 'password' : 'text';
            }
            wrapper.append(label, input);
            container.appendChild(wrapper);
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

    async function uploadQr(file) {
        if (!file) return;
        const status = find('#qr-upload-status');
        setQrStatus(status, '⏳ 正在本地解析收款码图片...', 'text-blue-600');
        try {
            if (!file.type.startsWith('image/') || file.size > 5 * 1024 * 1024) {
                throw new Error('请选择不超过 5MB 的图片');
            }
            if (typeof window.jsQR !== 'function') throw new Error('二维码解析组件加载失败，请手工填写支付链接');
            const bitmap = await createImageBitmap(file);
            const canvas = document.createElement('canvas');
            canvas.width = bitmap.width;
            canvas.height = bitmap.height;
            const context = canvas.getContext('2d', { willReadFrequently: true });
            context.drawImage(bitmap, 0, 0);
            bitmap.close();
            const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
            const decoded = window.jsQR(pixels.data, pixels.width, pixels.height, { inversionAttempts: 'attemptBoth' });
            if (!decoded?.data) throw new Error('未识别到二维码，请使用清晰原图或手工填写链接');
            const input = root.querySelector('[data-driver-config="qr_url"], [data-driver-config="qr_code_url"]');
            if (!input) throw new Error('当前驱动没有二维码地址配置项');
            input.value = decoded.data;
            setQrStatus(status, '✅ 已在浏览器本地识别，图片未上传服务器', 'text-emerald-600');
        } catch (error) {
            setQrStatus(status, error.message || '收款码识别失败，请手工填写支付链接', 'text-rose-600');
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
