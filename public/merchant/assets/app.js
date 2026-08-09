import { assetUrl } from './version.js';

const [api, ui, routerModule] = await Promise.all([
    import(assetUrl('/merchant/assets/api.js')),
    import(assetUrl('/merchant/assets/ui.js')),
    import(assetUrl('/merchant/assets/router.js')),
]);

const featureRoot = document.getElementById('merchant-feature-root');
const definitions = new Map();
definitions.set('dashboard', { view: 'dashboard.html', module: 'dashboard.js' });
definitions.set('profile', { view: 'profile.html', module: 'profile.js' });
definitions.set('channel-list', { view: 'channels.html', module: 'channels.js' });
definitions.set('channel-config', { view: 'cashier.html', module: 'cashier.js' });
definitions.set('poll-group', { view: 'poll-groups.html', module: 'poll-groups.js' });
definitions.set('order-list', { view: 'orders.html', module: 'orders.js' });
definitions.set('finance-log', { view: 'finance.html', module: 'finance.js' });
definitions.set('plan-buy', { view: 'plans.html', module: 'plans.js' });
definitions.set('api-keys', { view: 'api-keys.html', module: 'api-keys.js' });
const knownIds = new Set([
    'dashboard',
    'profile',
    'notice-config',
    'channel-list',
    'channel-config',
    'poll-group',
    'order-list',
    'finance-log',
    'plan-buy',
    'api-keys',
]);
const tabTitles = {
    dashboard: '首页概览',
    profile: '账户设置',
    'notice-config': '通知设置 · 预警偏好',
    'channel-list': '通道管理 · 我的收款通道',
    'channel-config': '通道配置 · 收银台设置',
    'poll-group': '通道轮询组',
    'order-list': '订单记录 · 交易明细',
    'finance-log': '财务管理 · 服务费明细',
    'plan-buy': '套餐订阅与套餐广场',
    'api-keys': '开发与对接 · API 密钥管理',
};

function setText(id, value) {
    const element = document.getElementById(id);
    if (element) element.textContent = value;
}

const shell = Object.freeze({
    applyProfile(profile) {
        setText('sidebar-merchant-pid', `PID: ${profile.pid}`);
        setText('header-merchant-label', `${profile.name} (PID: ${profile.pid})`);
        setText('sidebar-merchant-balance', `¥ ${profile.money || '0.00'}`);
    },
    applyDashboard(data) {
        setText('sidebar-merchant-balance', `¥ ${Number(data.money || 0).toFixed(2)}`);
    },
});

let profilePromise = null;

async function getMerchantProfile({ refresh = false } = {}) {
    if (refresh || !profilePromise) {
        profilePromise = api.merchantFetch('/api/merchant/profile')
            .then((response) => response.json())
            .then((payload) => {
                if (payload.code !== 1 || !payload.data) {
                    throw new Error(payload.msg || '商户资料加载失败');
                }
                shell.applyProfile(payload.data);
                return Object.freeze({ ...payload.data });
            })
            .catch((error) => {
                profilePromise = null;
                throw error;
            });
    }
    return profilePromise;
}

function updateNavigation(id) {
    document.querySelectorAll('.nav-btn').forEach((button) => {
        button.classList.remove('nav-link-active');
    });
    document.getElementById(`nav-${id}`)?.classList.add('nav-link-active');

    if (location.hash !== `#${id}`) {
        history.replaceState(null, '', `#${id}`);
    }
    setText('current-tab-title', tabTitles[id] || '首页概览');
}

function activateLegacy(id) {
    featureRoot.classList.add('hidden');
    featureRoot.innerHTML = '';
    document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('active'));
    document.getElementById(`tab-${id}`)?.classList.add('active');
    updateNavigation(id);
    ui.safeCreateIcons();

    const legacyLoaders = {
        dashboard: async () => {
            await window.loadMerchantDashboardData?.();
            await window.initMerchantTrendChart?.();
        },
        profile: () => window.loadMerchantProfile?.(),
        'channel-list': () => window.loadMerchantChannels?.(),
        'channel-config': () => window.loadCashierConfig?.(),
        'poll-group': () => window.loadPollGroups?.(),
        'order-list': () => window.loadMerchantOrderList?.(),
        'finance-log': () => window.loadMerchantFinanceLogs?.(),
        'plan-buy': () => window.loadMerchantPlans?.(),
        'api-keys': () => window.loadMerchantProfile?.(),
        'notice-config': () => window.loadMerchantAlertConfig?.(),
    };

    return legacyLoaders[id]?.();
}

function activateFeature(id) {
    document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('active'));
    featureRoot.classList.remove('hidden');
    updateNavigation(id);
}

const router = routerModule.createRouter({
    container: featureRoot,
    definitions,
    knownIds,
    context: { api, ui, shell, getMerchantProfile },
    activateLegacy,
    activateFeature,
});

window.CXMerchant = Object.freeze({ navigate: router.navigate });

try {
    await getMerchantProfile();
} catch {
    window.location.assign('/merchant_login.html');
}

let hashTab = (location.hash || '').replace('#', '').trim();
if (hashTab.includes('?')) hashTab = hashTab.split('?')[0];
const initialTab = window.CXMerchantPendingTab || hashTab || 'dashboard';
delete window.CXMerchantPendingTab;
router.navigate(initialTab);
