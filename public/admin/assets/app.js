import { assetUrl } from './version.js';

const [api, ui, routerModule] = await Promise.all([
    import(assetUrl('/admin/assets/api.js')),
    import(assetUrl('/admin/assets/ui.js')),
    import(assetUrl('/admin/assets/router.js')),
]);

const featureRoot = document.getElementById('admin-feature-root');
const definitions = new Map();
definitions.set('dashboard', { view: 'dashboard.html', module: 'dashboard.js' });
definitions.set('system-update', { view: 'system-update.html', module: 'system-update.js' });
definitions.set('cloud-monitor', { view: 'cloud-monitor.html', module: 'cloud-monitor.js' });
definitions.set('channel-config', { view: 'channels.html', module: 'channels.js' });
definitions.set('plugin-market', { view: 'plugins.html', module: 'plugins.js' });
definitions.set('merchant-mgmt', { view: 'merchants.html', module: 'merchants.js' });
definitions.set('plan-mgmt', { view: 'plans.html', module: 'plans.js' });
definitions.set('order-list', { view: 'orders.html', module: 'orders.js' });
definitions.set('callbill-review', { view: 'callbill.html', module: 'callbill.js' });
definitions.set('alert-config', { view: 'alerts.html', module: 'alerts.js' });
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

function activateFeature(id) {
    featureRoot.classList.remove('hidden');
    updateNavigation(id);
}

const router = routerModule.createRouter({
    container: featureRoot,
    definitions,
    context: { api, ui },
    activateFeature,
});

window.CXAdmin = Object.freeze({ api, ui, navigate: router.navigate });

document.getElementById('app')?.addEventListener('click', (event) => {
    const target = event.target.closest?.('[data-tab], [data-action]');
    if (!target) return;

    const tab = target.dataset.tab;
    if (tab) {
        event.preventDefault();
        void router.navigate(tab);
        return;
    }

    if (target.dataset.action === 'logout-admin') {
        void logoutAdmin();
    }
});

async function logoutAdmin() {
    try {
        await api.adminFetch('/api/admin/logout', { method: 'POST' });
    } catch {
        // 服务端会话清理失败时，仍需清除本地凭据并退出管理页。
    }
    localStorage.removeItem('cx_admin_token');
    window.location.assign('/admin_login.html');
}

let hashTab = (location.hash || '').replace('#', '').trim();
if (hashTab.includes('?')) hashTab = hashTab.split('?')[0];
const initialTab = hashTab || 'dashboard';
router.navigate(initialTab);
