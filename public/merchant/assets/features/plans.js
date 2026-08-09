let state = null;

export const feature = {
    id: 'plan-buy',
    async mount(context) {
        const { root, api, ui, signal, getMerchantProfile } = context;
        const plans = new Map();
        const grid = root.querySelector('#merchant-plan-grid');

        async function load() {
            grid.innerHTML = '<div class="col-span-3 glass-card rounded-2xl p-8 text-center text-slate-400 font-bold">正在加载套餐列表...</div>';
            try {
                const response = await api.merchantFetch('/api/merchant/plan/list', { signal });
                const payload = await response.json();
                if (payload.code !== 1 || !payload.data) {
                    throw new Error(payload.msg || '加载套餐列表失败');
                }
                const data = payload.data;
                setText(root, '#plan-merchant-money', `¥ ${data.merchant_money || '0.00'}`);
                setText(root, '#plan-current-discount', `¥ ${data.plan_fee_discount_balance || '0.00'}`);
                setText(root, '#plan-current-name', data.current_plan_name || '默认基础套餐');
                setText(root, '#plan-current-expire', data.plan_expire_format || '无到期限制');
                plans.clear();
                for (const plan of data.list || []) plans.set(String(plan.id), plan);
                renderPlans(grid, Array.from(plans.values()), ui);
                ui.safeCreateIcons(root);
            } catch (error) {
                if (error?.name === 'AbortError') return;
                grid.innerHTML = `<div class="col-span-3 glass-card rounded-2xl p-8 text-center text-rose-500 font-bold">${ui.escapeHtml(error.message || '网络请求异常')}</div>`;
            }
        }

        async function buy(plan) {
            if (!plan) return;
            const free = Number(plan.price) === 0;
            const confirmed = await ui.showConfirm(
                free ? '免费领取套餐确认' : '付费订阅套餐确认',
                free
                    ? `确定立即免费领取【${plan.name}】试用体验吗？`
                    : `确定购买【${plan.name}】吗？系统将从您的服务费余额中扣除 ¥${Number(plan.price).toFixed(2)}`,
                false
            );
            if (!confirmed) return;
            try {
                const response = await api.merchantFetch('/api/merchant/plan/buy', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ plan_id: String(plan.id) }),
                    signal,
                });
                const payload = await response.json();
                if (payload.code !== 1) throw new Error(payload.msg || '订阅失败');
                ui.showToast(payload.msg || '套餐升级订阅成功！');
                await Promise.all([load(), getMerchantProfile({ refresh: true })]);
            } catch (error) {
                if (error?.name !== 'AbortError') ui.showToast(`订阅失败: ${error.message || '未知错误'}`, 'error');
            }
        }

        const onClick = async (event) => {
            const trigger = event.target.closest('[data-action]');
            if (!trigger || !root.contains(trigger)) return;
            if (trigger.dataset.action === 'refresh-plans') await load();
            if (trigger.dataset.action === 'buy-plan') await buy(plans.get(trigger.dataset.planId));
        };
        root.addEventListener('click', onClick);
        state = { root, onClick, plans };
        await load();
    },
    unmount() {
        if (!state) return;
        state.root.removeEventListener('click', state.onClick);
        state.plans.clear();
        state = null;
    },
};

function renderPlans(grid, plans, ui) {
    if (!plans.length) {
        grid.innerHTML = '<div class="col-span-3 glass-card rounded-2xl p-8 text-center text-slate-400 font-bold">暂无上架可购买的套餐</div>';
        return;
    }
    grid.innerHTML = plans.map((plan) => renderPlan(plan, ui)).join('');
}

function renderPlan(plan, ui) {
    const free = Number(plan.price) === 0;
    const current = Boolean(plan.is_current);
    const canBuy = Boolean(plan.can_buy);
    const price = free
        ? '<div class="text-2xl font-black text-emerald-600">¥ 0.00 <span class="text-xs font-bold bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full ml-1">零元试用</span></div>'
        : `<div class="text-2xl font-black text-slate-800">¥ ${Number(plan.price).toFixed(2)} <span class="text-xs font-normal text-slate-400">/ ${plan.days > 0 ? `${plan.days}天` : '永久'}</span></div>`;
    const buttonClass = current
        ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
        : (!canBuy ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : (free ? 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-md' : 'bg-purple-600 hover:bg-purple-700 text-white shadow-md'));
    const buttonText = current ? '✓ 当前已激活套餐' : (!canBuy ? '已达购买上限' : (free ? '⚡ 免费试用领取' : '立即订阅购买'));
    const action = !current && canBuy
        ? `data-action="buy-plan" data-plan-id="${ui.escapeHtml(plan.id)}"` : 'disabled';
    return `<div class="glass-card rounded-2xl p-6 border ${current ? 'border-2 border-purple-500 bg-purple-50/20' : 'border-slate-200/80'} shadow-sm flex flex-col justify-between space-y-5 transition-all hover:shadow-md">
        <div class="space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3"><div>
                <h3 class="text-base font-extrabold text-slate-800">${ui.escapeHtml(plan.name)}</h3>
                ${plan.memo ? `<p class="text-[11px] text-slate-400 mt-0.5">${ui.escapeHtml(plan.memo)}</p>` : ''}
            </div>${current ? '<span class="px-2.5 py-1 bg-purple-600 text-white font-bold text-[10px] rounded-full">当前生效</span>' : ''}</div>
            ${price}
            <div class="space-y-2 text-xs border-t border-b border-slate-100 py-3">
                <div class="flex justify-between text-slate-600"><span>交易扣除费率:</span><strong class="font-mono font-extrabold text-blue-600">${Number(plan.rate).toFixed(2)}%</strong></div>
                <div class="flex justify-between text-slate-600"><span>套餐时长:</span><strong class="font-bold text-slate-800">${plan.days > 0 ? `${plan.days} 天` : '永久有效'}</strong></div>
                <div class="flex justify-between text-slate-600"><span>通道配额上限:</span><strong class="font-bold text-indigo-600">${plan.channel_quota > 0 ? `${plan.channel_quota} 个` : '无限制'}</strong></div>
                <div class="flex justify-between text-slate-600"><span>用户购买限额:</span><strong class="text-slate-500">${plan.limit_count > 0 ? `限购 ${plan.limit_count} 次 (已购 ${plan.bought_count} 次)` : '无限制'}</strong></div>
            </div>
        </div>
        <button ${action} class="w-full py-2.5 font-bold rounded-xl text-center text-xs transition-colors ${buttonClass}">${buttonText}</button>
    </div>`;
}

function setText(root, selector, value) {
    const element = root.querySelector(selector);
    if (element) element.textContent = value;
}
