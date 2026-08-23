// agent-hub.js — OEM 代理加盟中心独立功能模块

// 默认每站点单价（元），从云端 profile 动态拉取更新
let pricePerSite = 199.00;

export const feature = {
    id: 'agent-hub',
    async mount(context) {
        const { root, ui, signal } = context;

        // ── 事件委托 ──────────────────────────────────────────────
        root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action], .quota-qty-btn');
            if (!target) return;
            const action = target.dataset.action || '';

            if (action === 'open-issue-modal')      openIssueModal(root);
            if (action === 'close-issue-modal')     closeIssueModal(root);
            if (action === 'refresh-agent-instances') void loadAgentInstances(context);
            if (action === 'open-buy-quota-modal')  openBuyQuotaModal(root);
            if (action === 'close-buy-quota-modal') closeBuyQuotaModal(root);
            if (action === 'close-rebind-modal')    closeRebindModal(root);

            // 打开换绑弹窗
            if (action === 'open-rebind-modal') {
                const domain = target.dataset.domain || '';
                if (domain) openRebindModal(root, domain);
            }

            // 快捷购买配额选择
            if (action === 'select-quota-qty' || target.classList.contains('quota-qty-btn')) {
                selectQuotaQty(root, parseInt(target.dataset.qty, 10) || 0);
            }

            // 冻结授权
            if (action === 'revoke-sub-license') {
                const domain = target.dataset.domain || '';
                if (domain) void doRevokeLicense(context, domain);
            }
            // 解冻/恢复授权
            if (action === 'restore-sub-license') {
                const domain = target.dataset.domain || '';
                if (domain) void doRestoreLicense(context, domain);
            }
            // 彻底吊销/删除授权
            if (action === 'delete-sub-license') {
                const domain = target.dataset.domain || '';
                if (domain) void doDeleteLicense(context, domain);
            }
        }, { signal });

        // 自定义数量输入框同步
        root.querySelector('#quota-custom-qty')?.addEventListener('input', (e) => {
            const val = parseInt(e.target.value, 10);
            root.querySelectorAll('.quota-qty-btn').forEach(b =>
                b.classList.remove('border-emerald-500', 'text-emerald-700', 'bg-emerald-50'));
            updatePricePreview(root, val > 0 ? val : 0);
        }, { signal });

        // 下发授权
        root.querySelector('#btn-do-issue-license')?.addEventListener('click',
            () => void doIssueLicense(context), { signal });

        // 提交更换绑定域名
        root.querySelector('#btn-do-rebind-license')?.addEventListener('click',
            () => void doRebindLicense(context), { signal });

        // 复制 License Key
        root.querySelector('#btn-copy-issued-key')?.addEventListener('click', () => {
            const keyText = root.querySelector('#res-issued-key')?.textContent?.trim() || '';
            if (keyText) navigator.clipboard.writeText(keyText).then(() => ui.showToast('License Key 已复制到剪贴板！'));
        }, { signal });

        // 确认购买配额
        root.querySelector('#btn-do-buy-quota')?.addEventListener('click',
            () => void doBuyQuota(context), { signal });

        ui.safeCreateIcons();
        await loadAgentHub(context);
    },
    unmount() {},
};

// ── 下发授权弹窗 ──────────────────────────────────────────────────
function openIssueModal(root) {
    const modal = root.querySelector('#agent-issue-modal');
    if (!modal) return;
    root.querySelector('#issue-form-step')?.classList.remove('hidden');
    root.querySelector('#issue-result-step')?.classList.add('hidden');
    const domInput = root.querySelector('#issue-client-domain');
    if (domInput) domInput.value = '';
    const nameInput = root.querySelector('#issue-client-name');
    if (nameInput) nameInput.value = '';
    modal.style.display = 'flex';
    domInput?.focus();
}
function closeIssueModal(root) {
    const modal = root.querySelector('#agent-issue-modal');
    if (modal) modal.style.display = 'none';
}

// ── 更换绑定域名弹窗 ──────────────────────────────────────────────
function openRebindModal(root, oldDomain) {
    const modal = root.querySelector('#agent-rebind-modal');
    if (!modal) return;
    const oldInput = root.querySelector('#rebind-old-domain');
    if (oldInput) oldInput.value = oldDomain;
    const newInput = root.querySelector('#rebind-new-domain');
    if (newInput) newInput.value = '';
    modal.style.display = 'flex';
    newInput?.focus();
}
function closeRebindModal(root) {
    const modal = root.querySelector('#agent-rebind-modal');
    if (modal) modal.style.display = 'none';
}

// ── 购买配额弹窗 ──────────────────────────────────────────────────
function openBuyQuotaModal(root) {
    const modal = root.querySelector('#agent-buy-quota-modal');
    if (!modal) return;
    root.querySelectorAll('.quota-qty-btn').forEach(b =>
        b.classList.remove('border-emerald-500', 'text-emerald-700', 'bg-emerald-50'));
    const customInput = root.querySelector('#quota-custom-qty');
    if (customInput) customInput.value = '';
    
    selectQuotaQty(root, 5);
    modal.style.display = 'flex';
}
function closeBuyQuotaModal(root) {
    const modal = root.querySelector('#agent-buy-quota-modal');
    if (modal) modal.style.display = 'none';
}

function selectQuotaQty(root, qty) {
    root.querySelectorAll('.quota-qty-btn').forEach(b => {
        const isActive = parseInt(b.dataset.qty, 10) === qty;
        b.classList.toggle('border-emerald-500', isActive);
        b.classList.toggle('text-emerald-700',   isActive);
        b.classList.toggle('bg-emerald-50',      isActive);
    });
    const customInput = root.querySelector('#quota-custom-qty');
    if (customInput) customInput.value = '';
    updatePricePreview(root, qty);
}

function updatePricePreview(root, qty) {
    const el = root.querySelector('#quota-price-preview');
    if (!el) return;
    if (!qty || qty <= 0) {
        el.textContent = '–';
        return;
    }
    const total = qty * pricePerSite;
    el.innerHTML = `<span class="text-xs text-slate-500 font-normal mr-2">${qty} 站点 × ¥${pricePerSite.toFixed(2)} =</span> <span class="text-emerald-700 font-extrabold font-mono text-base">¥ ${total.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>`;
}

// ── 数据加载 ──────────────────────────────────────────────────────
async function loadAgentHub(context) {
    const { root, ui, api } = context;

    try {
        const res  = await api.adminFetch('/api/admin/agent/profile');
        const data = await res.json();

        if (data.code === 1 && data.data) {
            const d = data.data;

            if (d.price_per_instance && parseFloat(d.price_per_instance) > 0) {
                pricePerSite = parseFloat(d.price_per_instance);
            }

            const tenantEl = root.querySelector('#agent-tenant-name');
            if (tenantEl) tenantEl.textContent = d.tenant_name || '代理加盟商';

            const badgeEl = root.querySelector('#agent-level-badge');
            if (badgeEl) badgeEl.textContent =
                d.agent_level === 'DIAMOND_AGENT' ? '💎 钻石加盟商' : '👑 加盟商';

            const usedEl = root.querySelector('#agent-used-instances');
            if (usedEl) usedEl.textContent = d.used_instances ?? 0;

            const maxEl = root.querySelector('#agent-max-instances');
            if (maxEl) maxEl.textContent = d.max_instances ?? 20;

            const remainEl = root.querySelector('#agent-remaining-quota');
            if (remainEl) remainEl.textContent = (d.remaining_quota ?? 0) + ' 站点';

            const discountEl = root.querySelector('#agent-discount-text');
            if (discountEl) discountEl.textContent = d.plugin_discount || '4.0 折 (OEM 专享)';

            const pct = Math.min(100, Math.round(
                ((d.used_instances || 0) / Math.max(d.max_instances || 20, 1)) * 100
            ));
            const bar = root.querySelector('#agent-quota-bar');
            if (bar) bar.style.width = pct + '%';
        }
    } catch (e) {
        console.warn('[AgentHub] 获取资质失败', e);
    }

    await loadAgentInstances(context);
}

async function loadAgentInstances(context) {
    const { root, api } = context;
    const tbody = root.querySelector('#agent-instances-tbody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-10 text-center text-slate-400">
        <span class="inline-block animate-spin mr-2">⏳</span> 正在检索名下客户站点...</td></tr>`;

    try {
        const res  = await api.adminFetch('/api/admin/agent/sub_instances');
        const data = await res.json();

        if (data.code === 1 && data.data?.list) {
            const list = data.data.list;
            if (list.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-12 text-center text-slate-400">
                    暂无已下发的客户子站点，点击右上角「+ 一键下发新站点授权」开始！</td></tr>`;
                return;
            }

            tbody.innerHTML = list.map(item => {
                const isActive = item.status === 'ACTIVE';
                const isDeleted = item.status === 'DELETED';

                let statusBadge = '';
                if (isDeleted) {
                    statusBadge = `<span class="inline-flex items-center gap-1 text-rose-500 font-bold">
                                       <span class="w-1.5 h-1.5 rounded-full bg-rose-500 inline-block"></span> 已吊销
                                   </span>`;
                } else if (isActive) {
                    statusBadge = `<span class="inline-flex items-center gap-1 text-emerald-600 font-bold">
                                       <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span> 运行中
                                   </span>`;
                } else {
                    statusBadge = `<span class="inline-flex items-center gap-1 text-slate-400 font-bold">
                                       <span class="w-1.5 h-1.5 rounded-full bg-slate-300 inline-block"></span> 已冻结
                                   </span>`;
                }

                // 操作列
                let actionHtml = '';
                if (isDeleted) {
                    actionHtml = `<span class="text-xs text-slate-300">已终止</span>`;
                } else {
                    const toggleBtn = isActive
                        ? `<button type="button" data-action="revoke-sub-license" data-domain="${item.domain}"
                                class="px-2 py-1 text-amber-600 hover:bg-amber-50 border border-amber-200 rounded-lg font-bold text-[11px] transition-colors">
                               冻结
                           </button>`
                        : `<button type="button" data-action="restore-sub-license" data-domain="${item.domain}"
                                class="px-2 py-1 text-emerald-600 hover:bg-emerald-50 border border-emerald-200 rounded-lg font-bold text-[11px] transition-colors">
                               恢复
                           </button>`;

                    const rebindBtn = `<button type="button" data-action="open-rebind-modal" data-domain="${item.domain}"
                            class="px-2 py-1 text-blue-600 hover:bg-blue-50 border border-blue-200 rounded-lg font-bold text-[11px] transition-colors ml-1">
                           改绑
                       </button>`;

                    const deleteBtn = `<button type="button" data-action="delete-sub-license" data-domain="${item.domain}"
                            class="px-2 py-1 text-rose-600 hover:bg-rose-50 border border-rose-200 rounded-lg font-bold text-[11px] transition-colors ml-1">
                           吊销
                       </button>`;

                    actionHtml = `${toggleBtn}${rebindBtn}${deleteBtn}`;
                }

                return `<tr class="hover:bg-slate-50/80 transition-colors">
                    <td class="px-4 py-3 font-mono font-bold text-slate-900 text-xs">${item.domain}</td>
                    <td class="px-4 py-3 font-mono text-emerald-700 font-semibold select-all text-xs">${item.masked_key || item.license_key}</td>
                    <td class="px-4 py-3 text-xs">
                        <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-mono text-[10px]">v${item.product_version || '2.1.0'}</span>
                        <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-500 text-[10px] ml-1">框架授权</span>
                    </td>
                    <td class="px-4 py-3 text-xs">${statusBadge}</td>
                    <td class="px-4 py-3 text-slate-400 font-mono text-[11px]">${item.activated_at ? item.activated_at.split('.')[0] : '-'}</td>
                    <td class="px-4 py-3 text-right flex items-center justify-end">${actionHtml}</td>
                </tr>`;
            }).join('');
        } else {
            tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">
                ${data.msg || '暂无数据'}</td></tr>`;
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-8 text-center text-rose-400">加载异常：${e.message}</td></tr>`;
    }
}

// ── 下发授权操作 ───────────────────────────────────────────────────
async function doIssueLicense(context) {
    const { root, ui, api } = context;
    const domainInput = root.querySelector('#issue-client-domain');
    const nameInput   = root.querySelector('#issue-client-name');
    const domain = domainInput?.value?.trim() || '';
    const name   = nameInput?.value?.trim()   || '';

    if (!domain) {
        ui.showToast('请输入下级客户主站域名', 'error');
        domainInput?.focus();
        return;
    }

    const btn = root.querySelector('#btn-do-issue-license');
    if (btn) { btn.disabled = true; btn.textContent = '正在生成并开通云端授权...'; }

    try {
        const res  = await api.adminFetch('/api/admin/agent/license/issue', {
            method: 'POST',
            body: new URLSearchParams({ client_domain: domain, client_name: name }),
        });
        const data = await res.json();

        if (data.code !== 1) throw new Error(data.msg || '下发授权失败');

        const d = data.data;
        root.querySelector('#issue-form-step')?.classList.add('hidden');
        root.querySelector('#issue-result-step')?.classList.remove('hidden');
        root.querySelector('#res-issued-key').textContent    = d.license_key;
        root.querySelector('#res-issued-domain').textContent = d.client_domain;
        root.querySelector('#res-issued-wm').textContent     = d.watermark_id;

        ui.showToast('🎉 客户商业授权下发成功！');
        void loadAgentHub(context);
    } catch (e) {
        ui.showToast(e.message, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="check-circle-2" class="w-4 h-4"></i> 确认生成并下发`;
            context.ui?.safeCreateIcons?.();
        }
    }
}

// ── 更换绑定域名操作 ───────────────────────────────────────────────
async function doRebindLicense(context) {
    const { root, ui, api } = context;
    const oldDomain = root.querySelector('#rebind-old-domain')?.value?.trim() || '';
    const newDomain = root.querySelector('#rebind-new-domain')?.value?.trim() || '';

    if (!newDomain) {
        ui.showToast('请输入更换后的新域名', 'error');
        root.querySelector('#rebind-new-domain')?.focus();
        return;
    }

    if (oldDomain.toLowerCase() === newDomain.toLowerCase()) {
        ui.showToast('新域名与原域名相同，无需更换', 'error');
        return;
    }

    if (!confirm(`确认要将客户站点域名从 [${oldDomain}] 改绑为 [${newDomain}] 吗？\n\n• 换绑不消耗任何新配额；\n• 原旧域名 [${oldDomain}] 将被立即作废注销！`)) {
        return;
    }

    const btn = root.querySelector('#btn-do-rebind-license');
    if (btn) { btn.disabled = true; btn.textContent = '正在提交换绑...'; }

    try {
        const res = await api.adminFetch('/api/admin/agent/license/rebind', {
            method: 'POST',
            body: new URLSearchParams({ old_domain: oldDomain, new_domain: newDomain }),
        });
        const data = await res.json();
        if (data.code !== 1) throw new Error(data.msg || '换绑域名失败');

        ui.showToast(data.msg || '🎉 站点域名换绑成功！');
        closeRebindModal(root);
        void loadAgentHub(context);
    } catch (e) {
        ui.showToast(e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = '确认提交换绑'; }
    }
}

// ── 冻结授权操作 ───────────────────────────────────────────────────
async function doRevokeLicense(context, domain) {
    const { ui, api } = context;
    if (!confirm(`确定要冻结客户站点 [${domain}] 的授权吗？\n冻结后该站点将无法收单，可随时恢复。`)) return;

    try {
        const res  = await api.adminFetch('/api/admin/agent/license/revoke', {
            method: 'POST',
            body: new URLSearchParams({ domain }),
        });
        const data = await res.json();
        if (data.code !== 1) throw new Error(data.msg || '冻结失败');
        ui.showToast(data.msg || '✅ 已冻结该子站点授权');
        void loadAgentHub(context);
    } catch (e) {
        ui.showToast(e.message, 'error');
    }
}

// ── 解冻/恢复授权操作 ─────────────────────────────────────────────
async function doRestoreLicense(context, domain) {
    const { ui, api } = context;
    if (!confirm(`确定要恢复客户站点 [${domain}] 的授权吗？\n恢复后该站点将重新开通收单功能。`)) return;

    try {
        const res  = await api.adminFetch('/api/admin/agent/license/restore', {
            method: 'POST',
            body: new URLSearchParams({ domain }),
        });
        const data = await res.json();
        if (data.code !== 1) throw new Error(data.msg || '恢复授权失败');
        ui.showToast(data.msg || '✅ 已成功恢复该站点授权，收单功能已恢复！');
        void loadAgentHub(context);
    } catch (e) {
        ui.showToast(e.message, 'error');
    }
}

// ── 彻底吊销/删除授权操作 ─────────────────────────────────────────
async function doDeleteLicense(context, domain) {
    const { ui, api } = context;
    if (!confirm(`⚠️ 严重警告：\n确定要彻底吊销客户站点 [${domain}] 的商业授权吗？\n\n• 吊销后该站点将永久停机且无法收单；\n• 已消耗的下发配额不予退还！\n\n（💡 若客户只是更换服务器或输错域名，请使用【改绑】功能，不消耗配额）`)) return;

    try {
        const res  = await api.adminFetch('/api/admin/agent/license/delete', {
            method: 'POST',
            body: new URLSearchParams({ domain }),
        });
        const data = await res.json();
        if (data.code !== 1) throw new Error(data.msg || '吊销失败');
        ui.showToast(data.msg || '✅ 已成功彻底吊销该站点商业授权！');
        void loadAgentHub(context);
    } catch (e) {
        ui.showToast(e.message, 'error');
    }
}

// ── 购买配额操作 ───────────────────────────────────────────────────
async function doBuyQuota(context) {
    const { root, ui, api } = context;

    let qty = 0;
    const customVal = parseInt(root.querySelector('#quota-custom-qty')?.value || '0', 10);
    if (customVal > 0) {
        qty = customVal;
    } else {
        const activeBtn = root.querySelector('.quota-qty-btn.border-emerald-500');
        if (activeBtn) qty = parseInt(activeBtn.dataset.qty, 10) || 0;
    }

    if (!qty || qty <= 0) {
        ui.showToast('请选择或输入购买数量', 'error');
        return;
    }
    if (qty > 500) {
        ui.showToast('单次最多购买 500 个配额，如需大批量请联系官方客服', 'error');
        return;
    }

    const btn = root.querySelector('#btn-do-buy-quota');
    if (btn) { btn.disabled = true; btn.textContent = '正在提交，请稍候...'; }

    try {
        const res  = await api.adminFetch('/api/admin/agent/quota/buy', {
            method: 'POST',
            body: new URLSearchParams({ quantity: String(qty) }),
        });
        const data = await res.json();
        if (data.code !== 1) throw new Error(data.msg || '发起购买失败');

        const payUrl = data.data?.pay_url || data.data?.payment_url || '';
        if (payUrl) {
            ui.showToast('🚀 正在跳转官方云端支付页面...');
            closeBuyQuotaModal(root);
            window.open(payUrl, '_blank', 'noopener');
        } else {
            ui.showToast(data.msg || '请前往官方云端授权中心完成支付');
            closeBuyQuotaModal(root);
        }
    } catch (e) {
        ui.showToast(e.message, 'error');
    } finally {
        if (btn) {
            btn.disabled  = false;
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg> 前往云端支付`;
        }
    }
}
