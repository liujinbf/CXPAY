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
definitions.set('report', { view: 'report.html', module: 'report.js' });
definitions.set('security', { view: 'security.html', module: 'security.js' });
definitions.set('sys-config', { view: 'sys-config.html', module: 'sys-config.js' });
definitions.set('poll-group', { view: 'poll-group.html', module: 'poll-group.js' });
definitions.set('bill-source', { view: 'bill-source.html', module: 'bill-source.js' });
definitions.set('agent-hub', { view: 'agent-hub.html', module: 'agent-hub.js' });
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
    report: '交易统计报表',
    security: '管理员安全设置',
    'sys-config': '系统运营配置',
    'poll-group': '轮询组管理（开发中）',
    'bill-source': '账单来源管理',
};

const tabGroupMap = {
    dashboard: 'overview',
    report: 'overview',
    'channel-config': 'channels',
    'plugin-market': 'channels',
    'cloud-monitor': 'channels',
    'poll-group': 'channels',
    'merchant-mgmt': 'merchants',
    'plan-mgmt': 'merchants',
    'order-list': 'finance',
    'callbill-review': 'finance',
    'bill-source': 'finance',
    'agent-hub': 'agent',
    'sys-config': 'system',
    'alert-config': 'system',
    security: 'system',
    'system-update': 'system',
};

function toggleAccordion(groupId) {
    const body = document.getElementById('body-' + groupId);
    const chevron = document.getElementById('chevron-' + groupId);
    if (!body) return;

    const isCollapsed = body.classList.contains('collapsed');
    if (isCollapsed) {
        body.classList.remove('collapsed');
        body.classList.add('expanded');
        if (chevron) chevron.classList.add('rotate-180');
    } else {
        body.classList.remove('expanded');
        body.classList.add('collapsed');
        if (chevron) chevron.classList.remove('rotate-180');
    }
}

function ensureGroupExpanded(groupId) {
    const body = document.getElementById('body-' + groupId);
    const chevron = document.getElementById('chevron-' + groupId);
    if (body) {
        body.classList.remove('collapsed');
        body.classList.add('expanded');
    }
    if (chevron) chevron.classList.add('rotate-180');
}

window.ensureGroupExpanded = ensureGroupExpanded;
window.toggleAccordion = toggleAccordion;

function updateNavigation(id) {
    document.querySelectorAll('.nav-btn').forEach((button) => {
        button.classList.remove('nav-link-active');
    });
    document.getElementById(`nav-${id}`)?.classList.add('nav-link-active');

    const groupId = tabGroupMap[id];
    if (groupId) {
        ensureGroupExpanded(groupId);
    }

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
    const accordionHeader = event.target.closest?.('.accordion-header, [data-action="toggle-accordion"]');
    if (accordionHeader) {
        const group = accordionHeader.dataset.group || accordionHeader.closest?.('.accordion-group')?.dataset.group;
        if (group) toggleAccordion(group);
        return;
    }

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

// 启动后检测代理商资质并自动激活 OEM 代理加盟中心
try {
    const res = await api.adminFetch('/api/admin/agent/profile');
    if (res.status === 200) {
        const data = await res.json();
        if (data.code === 1 && data.data && data.data.can_issue === true) {
            const navEl = document.getElementById('nav-agent-hub');
            if (navEl) navEl.classList.remove('hidden');
            const grpDiv = document.getElementById('group-agent');
            if (grpDiv) grpDiv.classList.remove('hidden');
            const grpBody = document.getElementById('body-agent');
            const grpChevron = document.getElementById('chevron-agent');
            if (grpBody) { grpBody.classList.remove('collapsed'); grpBody.classList.add('expanded'); }
            if (grpChevron) grpChevron.classList.add('rotate-180');
        }
    }
} catch (_) {
    // 静默忽略
}
