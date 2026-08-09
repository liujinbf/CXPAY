const STORAGE_KEY = 'cx_cashier_config';
const DEFAULT_CONFIG = Object.freeze({
    notice: '',
    timeout: 180,
    redirect: 'return_url',
    custom_url: '',
    tts_enabled: true,
    mapi_mode: 'qrcode',
    float_min: '0.00',
    float_max: '0.09',
    theme: 'classic_blue',
});
const THEMES = ['classic_blue', 'vip_gold', 'dark', 'simple_white'];

export function readCashierConfig(storage) {
    try {
        const saved = JSON.parse(storage.getItem(STORAGE_KEY) || 'null');
        return normalizeCashierConfig(saved && typeof saved === 'object' ? saved : DEFAULT_CONFIG);
    } catch {
        return { ...DEFAULT_CONFIG };
    }
}

export function normalizeCashierConfig(value = {}) {
    const timeout = Number(value.timeout ?? DEFAULT_CONFIG.timeout);
    return {
        notice: String(value.notice ?? DEFAULT_CONFIG.notice),
        timeout: Number.isFinite(timeout) ? timeout : DEFAULT_CONFIG.timeout,
        redirect: ['return_url', 'custom', 'stay'].includes(value.redirect)
            ? value.redirect : DEFAULT_CONFIG.redirect,
        custom_url: String(value.custom_url ?? DEFAULT_CONFIG.custom_url),
        tts_enabled: value.tts_enabled === undefined ? DEFAULT_CONFIG.tts_enabled : Boolean(value.tts_enabled),
        mapi_mode: ['qrcode', 'payurl'].includes(value.mapi_mode)
            ? value.mapi_mode : DEFAULT_CONFIG.mapi_mode,
        float_min: String(value.float_min ?? DEFAULT_CONFIG.float_min),
        float_max: String(value.float_max ?? DEFAULT_CONFIG.float_max),
        theme: THEMES.includes(value.theme) ? value.theme : DEFAULT_CONFIG.theme,
    };
}

export function validateCashierConfig(config) {
    if (config.timeout < 60 || config.timeout > 300) {
        return '超时时间必须在 60 到 300 秒之间';
    }
    return null;
}

let state = null;

export const feature = {
    id: 'channel-config',
    async mount(context) {
        const { root, ui } = context;
        let selectedTheme = DEFAULT_CONFIG.theme;

        function find(selector) {
            return root.querySelector(selector);
        }

        function applyConfig(config) {
            find('#cfg-notice').value = config.notice;
            find('#notice-char-count').textContent = String(config.notice.length);
            find('#cfg-timeout').value = String(config.timeout);
            find('#cfg-redirect-select').value = config.redirect;
            find('#cfg-custom-url').value = config.custom_url;
            find('#cfg-tts-enabled').checked = config.tts_enabled;
            const mapi = find(`input[name="cfg_mapi_mode"][value="${config.mapi_mode}"]`);
            if (mapi) mapi.checked = true;
            find('#cfg-float-min').value = config.float_min;
            find('#cfg-float-max').value = config.float_max;
            selectTheme(config.theme, false);
            toggleCustomUrl();
        }

        function collectConfig() {
            return normalizeCashierConfig({
                notice: find('#cfg-notice').value.trim(),
                timeout: find('#cfg-timeout').value,
                redirect: find('#cfg-redirect-select').value,
                custom_url: find('#cfg-custom-url').value.trim(),
                tts_enabled: find('#cfg-tts-enabled').checked,
                mapi_mode: find('input[name="cfg_mapi_mode"]:checked')?.value || 'qrcode',
                float_min: find('#cfg-float-min').value,
                float_max: find('#cfg-float-max').value,
                theme: selectedTheme,
            });
        }

        function selectTheme(theme, notify = true) {
            selectedTheme = THEMES.includes(theme) ? theme : DEFAULT_CONFIG.theme;
            for (const name of THEMES) {
                const card = find(`#theme-card-${name}`);
                const check = find(`#theme-check-${name}`);
                if (!card || !check) continue;
                if (name === selectedTheme) {
                    card.className = name === 'classic_blue'
                        ? 'border-2 border-blue-500 bg-blue-50/20 rounded-2xl p-3.5 cursor-pointer transition-all relative'
                        : 'border-2 border-blue-500 bg-white rounded-2xl p-3.5 cursor-pointer transition-all relative shadow-xs';
                    check.classList.remove('hidden');
                    check.classList.add('flex');
                } else {
                    card.className = 'border border-slate-200 rounded-2xl p-3.5 cursor-pointer hover:border-blue-400 transition-all relative';
                    check.classList.add('hidden');
                    check.classList.remove('flex');
                }
            }
            if (notify) {
                const names = { classic_blue: '经典蓝', vip_gold: 'VIP 尊享黑金', dark: '科技暗黑', simple_white: '极简纯白' };
                ui.showToast(`已成功选择 [${names[selectedTheme]}] 收银台外观风格！`);
            }
        }

        function toggleCustomUrl() {
            find('#custom-url-wrapper').classList.toggle(
                'hidden',
                find('#cfg-redirect-select').value !== 'custom'
            );
        }

        function adjustTimeout(delta) {
            const input = find('#cfg-timeout');
            const current = Number.parseInt(input.value, 10) || DEFAULT_CONFIG.timeout;
            input.value = String(Math.max(60, Math.min(300, current + delta)));
        }

        function testSpeech() {
            if (!('speechSynthesis' in window)) {
                ui.showToast('您的当前浏览器不支持 HTML5 语音合成播报', 'error');
                return;
            }
            window.speechSynthesis.cancel();
            const message = new SpeechSynthesisUtterance('微信支付收款到账 88 元！感谢使用 CXPAY 聚合收银台！');
            message.lang = 'zh-CN';
            message.rate = 1;
            message.pitch = 1;
            window.speechSynthesis.speak(message);
            ui.showToast('正在播报演示语音...');
        }

        const onClick = (event) => {
            const trigger = event.target.closest('[data-action]');
            if (!trigger || !root.contains(trigger)) return;
            const action = trigger.dataset.action;
            if (action === 'reset-cashier') applyConfig(readCashierConfig(localStorage));
            if (action === 'adjust-timeout') adjustTimeout(Number(trigger.dataset.delta));
            if (action === 'set-timeout') {
                find('#cfg-timeout').value = trigger.dataset.seconds;
                ui.showToast(`已设为 ${Number(trigger.dataset.seconds) / 60} 分钟倒计时超时`);
            }
            if (action === 'select-theme') selectTheme(trigger.dataset.theme);
            if (action === 'test-tts') testSpeech();
            if (action === 'save-cashier') {
                const config = collectConfig();
                const error = validateCashierConfig(config);
                if (error) {
                    ui.showToast(error, 'error');
                    return;
                }
                localStorage.setItem(STORAGE_KEY, JSON.stringify(config));
                ui.showToast('收银台与交易匹配引擎配置已保存生效！');
            }
        };

        const onInput = (event) => {
            if (event.target.dataset.action === 'notice-input') {
                find('#notice-char-count').textContent = String(event.target.value.length);
            }
        };

        const onChange = (event) => {
            if (event.target.dataset.action === 'change-redirect') toggleCustomUrl();
        };

        root.addEventListener('click', onClick);
        root.addEventListener('input', onInput);
        root.addEventListener('change', onChange);
        applyConfig(readCashierConfig(localStorage));
        ui.safeCreateIcons(root);
        state = { root, onClick, onInput, onChange };
    },
    unmount() {
        if (!state) return;
        state.root.removeEventListener('click', state.onClick);
        state.root.removeEventListener('input', state.onInput);
        state.root.removeEventListener('change', state.onChange);
        window.speechSynthesis?.cancel();
        state = null;
    },
};
