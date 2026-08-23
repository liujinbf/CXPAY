export const feature = {
    id: 'system-update',

    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (!target) return;
            const action = target.dataset.action;
            if (action === 'check-update')   void checkUpdate(context);
            if (action === 'execute-update') void executeUpdate(context);
            if (action === 'do-rollback')    void doRollback(context, target.dataset.hash);
            if (action === 'clear-terminal') {
                const terminal = root.querySelector('#git-terminal-output');
                if (terminal) terminal.innerText = '终端控制台已清空...';
            }
        }, { signal });
        ui.safeCreateIcons();

        await Promise.all([checkUpdate(context), loadHistory(context)]);
    },

    unmount() {},
};

async function checkUpdate({ root, api, signal }) {
    try {
        const response = await api.adminFetch('/api/admin/system/check_update', { signal });
        const payload = await response.json();
        if (payload.code !== 1 || !payload.data || signal.aborted) return;

        const data = payload.data;
        setText(root, 'git-branch-name', data.branch || 'main');
        setText(root, 'git-commit-hash', `#${data.commit || ''}`);
        setText(root, 'git-commit-msg', data.commit_msg || '');

        const status = root.querySelector('#git-behind-status');
        const description = root.querySelector('#git-behind-desc');
        if (!status || !description) return;

        if (data.has_update) {
            status.textContent = `有 ${data.behind_count} 个待更新 Commit`;
            status.className = 'text-xl font-black text-rose-600 font-mono';
            description.textContent = '建议立即点击【一键拉取最新代码并热重启】';
        } else {
            status.textContent = '已是最新代码';
            status.className = 'text-xl font-black text-emerald-600 font-mono';
            description.textContent = '本地代码已与 Git 远端保持完全同步';
        }
    } catch (error) {
        if (error?.name !== 'AbortError') console.error('检查系统更新失败', error);
    }
}

async function executeUpdate(context) {
    const { root, api, ui, signal } = context;
    const confirmed = await ui.showConfirm(
        '系统更新确认',
        '确认立即从远端 Git 仓库拉取最新代码并触发后台服务热重启？此操作将覆盖所有本地改动！',
        true
    );
    if (!confirmed || signal.aborted) return;

    const terminal = root.querySelector('#git-terminal-output');
    if (!terminal) return;
    terminal.innerText = '$ git reset --hard HEAD && git pull\n> 正在连接远端仓库并拉取最新代码...';

    try {
        const response = await api.adminFetch('/api/admin/system/do_update', { method: 'POST', signal });
        const payload = await response.json();
        if (signal.aborted) return;

        if (payload.code === 1) {
            terminal.innerText = `$ git pull 2>&1\n${payload.data?.log || 'Already up to date.'}\n\n[SUCCESS] ${payload.msg || ''}`;
            ui.showToast(payload.msg || '代码拉取成功并已触发热重载！');
            await Promise.all([checkUpdate(context), loadHistory(context)]);
        } else {
            terminal.innerText += `\n[ERROR] ${payload.msg || '更新失败'}`;
            ui.showToast(payload.msg || '代码拉取失败', 'error');
        }
    } catch (error) {
        if (error?.name !== 'AbortError') {
            terminal.innerText += `\n[ERROR] 网络请求失败: ${error.message}`;
        }
    }
}

async function doRollback(context, hash) {
    if (!hash) return;
    const { root, api, ui, signal } = context;
    const confirmed = await ui.showConfirm(
        '⚠️ 版本回滚确认',
        `确认将系统回滚至 Commit #${hash}？\n\n此操作将执行 git reset --hard 并立即覆盖当前代码，触发热重启。`,
        true
    );
    if (!confirmed || signal.aborted) return;

    const terminal = root.querySelector('#git-terminal-output');
    if (terminal) terminal.innerText = `$ git reset --hard ${hash}\n> 正在回滚代码版本...`;

    try {
        const response = await api.adminFetch('/api/admin/system/do_rollback', {
            method: 'POST',
            body: new URLSearchParams({ commit_hash: hash }),
            signal,
        });
        const payload = await response.json();
        if (signal.aborted) return;

        if (payload.code === 1) {
            const d = payload.data || {};
            if (terminal) {
                terminal.innerText = `$ git reset --hard ${hash}\n${d.log || ''}\n\n[SUCCESS] 已从 #${d.rollback_from} 回滚至 #${d.rollback_to}\n版本：${d.commit_msg || ''}`;
            }
            ui.showToast(`✅ 已回滚至 #${d.rollback_to}，后台正在热重启...`);
            await Promise.all([checkUpdate(context), loadHistory(context)]);
        } else {
            if (terminal) terminal.innerText += `\n[ERROR] ${payload.msg || '回滚失败'}`;
            ui.showToast(payload.msg || '版本回滚失败', 'error');
        }
    } catch (error) {
        if (error?.name !== 'AbortError') {
            if (terminal) terminal.innerText += `\n[ERROR] 网络请求失败: ${error.message}`;
            ui.showToast('版本回滚请求失败', 'error');
        }
    }
}

async function loadHistory({ root, api, ui, signal }) {
    const container = root.querySelector('#git-version-history-list');
    if (!container) return;

    try {
        const response = await api.adminFetch('/api/admin/system/version_history', { signal });
        const payload = await response.json();
        if (signal.aborted) return;

        const list = Array.isArray(payload.data)
            ? payload.data
            : (Array.isArray(payload.data?.commits) ? payload.data.commits : []);

        if (payload.code === 1 && list.length) {
            container.innerHTML = list.map((item, idx) => {
                const hash = ui.escapeHtml(item.hash || item.commit || '');
                const isCurrentHead = idx === 0;
                return `<div class="p-2.5 bg-slate-50 rounded-xl border ${isCurrentHead ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-100'} hover:bg-slate-100/80 transition-all space-y-1">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="font-mono font-bold text-blue-600 shrink-0">#${hash}</span>
                            ${isCurrentHead ? '<span class="text-[10px] bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded-full font-bold shrink-0">当前版本</span>' : ''}
                            <span class="text-[10px] text-slate-400 shrink-0">${ui.escapeHtml(item.date || item.time || '')}</span>
                        </div>
                        ${!isCurrentHead
                            ? `<button data-action="do-rollback" data-hash="${hash}"
                                class="shrink-0 px-2 py-0.5 text-[10px] font-bold rounded-lg border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 transition-colors whitespace-nowrap">
                                回滚到此版本
                              </button>`
                            : ''}
                    </div>
                    <div class="text-slate-700 font-medium text-xs truncate">${ui.escapeHtml(item.msg || item.subject || '')}</div>
                    <div class="text-slate-400 text-[11px]">作者：${ui.escapeHtml(item.author || '-')}</div>
                </div>`;
            }).join('');
        } else {
            container.innerHTML = '<div class="text-slate-400 text-center py-6 font-bold">暂无 Commit 历史</div>';
        }
    } catch (error) {
        if (error?.name !== 'AbortError') {
            container.innerHTML = '<div class="text-rose-500 text-center py-4 text-xs font-bold">加载版本历史失败</div>';
        }
    }
}

function setText(root, id, value) {
    const element = root.querySelector(`#${id}`);
    if (element) element.textContent = value;
}
