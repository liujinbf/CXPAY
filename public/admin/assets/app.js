import { assetUrl } from './version.js';

const [api, ui, routerModule] = await Promise.all([
    import(assetUrl('/admin/assets/api.js')),
    import(assetUrl('/admin/assets/ui.js')),
    import(assetUrl('/admin/assets/router.js')),
]);

const featureRoot = document.getElementById('admin-feature-root');
const definitions = new Map();
const tabTitles = {
    dashboard: '控制台仪表盘',
    'channel-config': '收款通道配置',
    'plugin-market': '已安装支付驱动',
    'cloud-monitor': '个人码云监控运维',
    'merchant-mgmt': '商户账号与费率',
    'plan-mgmt': '套餐设置与配额',
    'order-list': '交易订单与补单',
    'callbill-review': '到账账单复核',
    'system-update': '系统 Git 一键更新',
    'alert-config': '系统告警与通知',
};

function updateNavigation(id) {
    document.querySelectorAll('.nav-btn').forEach((button) => {
        button.classList.remove('nav-link-active');
    });
    document.getElementById(`nav-${id}`)?.classList.add('nav-link-active');

    if (location.hash !== `#${id}`) {
        history.replaceState(null, '', `#${id}`);
    }

    const title = document.getElementById('current-tab-title');
    if (title) title.innerText = tabTitles[id] || id.toUpperCase();
}

function activateLegacy(requestedId) {
    const id = document.getElementById(`tab-${requestedId}`) ? requestedId : 'dashboard';
    featureRoot.classList.add('hidden');
    featureRoot.innerHTML = '';
    document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('active'));
    document.getElementById(`tab-${id}`)?.classList.add('active');
    updateNavigation(id);
    ui.safeCreateIcons();

    const legacyLoaders = {
        dashboard: () => window.loadDashboard(),
        'channel-config': () => {
            const status = document.getElementById('channel-stat-active-count');
            if (status) status.textContent = '读取中...';
            return window.loadAdminChannels();
        },
        'merchant-mgmt': () => window.loadMerchants(),
        'plan-mgmt': () => window.loadPlans(),
        'order-list': () => window.loadOrders(),
        'callbill-review': () => window.loadCallbillReviews(),
        'plugin-market': () => window.loadInstalledPlugins(),
        'cloud-monitor': () => window.loadCloudMonitorStatus(),
        'system-update': () => Promise.all([
            window.checkGitUpdate(),
            window.loadGitVersionHistory(),
        ]),
        'alert-config': () => Promise.all([
            window.loadAdminAlertConfig(),
            window.loadSystemOpConfig(),
        ]),
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
    context: { api, ui },
    activateLegacy,
    activateFeature,
});

window.CXAdmin = Object.freeze({ api, ui, navigate: router.navigate });

let hashTab = (location.hash || '').replace('#', '').trim();
if (hashTab.includes('?')) hashTab = hashTab.split('?')[0];
const initialTab = window.CXAdminPendingTab || hashTab || 'dashboard';
delete window.CXAdminPendingTab;
router.navigate(initialTab);
