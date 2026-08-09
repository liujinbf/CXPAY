export const feature = {
    id: 'system-update',

    async mount(context) {
        const { root, ui, signal } = context;
        root.addEventListener('click', (event) => {
            const action = event.target.closest('[data-action]')?.dataset.action;
            if (action === 'check-update') void checkUpdate(context);
            if (action === 'execute-update') void executeUpdate(context);
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
        '确认立即从远端 Git 仓库拉取最新代码并触发后台服务热重启？',
        true
    );
    if (!confirmed || signal.aborted) return;

    const terminal = root.querySelector('#git-terminal-output');
    if (!terminal) return;
    terminal.innerText = '$ git pull 2>&1\n> 正在连接远端仓库并拉取最新代码...';

    try {
        const response = await api.adminFetch('/api/admin/system/do_update', { method: 'POST', signal });
        const payload = await response.json();
        if (signal.aborted) return;

        if (payload.code === 1) {
            terminal.innerText = `$ git pull 2>&1\n${payload.data?.log || 'Already up to date.'}\n\n[SUCCESS] ${payload.msg || ''}`;
            window.alert(payload.msg || '代码拉取成功并已触发热重载！');
            await Promise.all([checkUpdate(context), loadHistory(context)]);
        } else {
            terminal.innerText += `\n[ERROR] ${payload.msg || '更新失败'}`;
            if (!payload.msg?.includes('在线更新已禁用')) window.alert(payload.msg || '代码拉取失败');
        }
    } catch (error) {
        if (error?.name !== 'AbortError') terminal.innerText += `\n[ERROR] 网络请求失败: ${error.message}`;
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

        container.innerHTML = payload.code === 1 && list.length
            ? list.map((item) => `<div class="p-2.5 bg-slate-50 rounded-xl border border-slate-100 hover:bg-slate-100/80 transition-all space-y-1">
                <div class="flex items-center justify-between">
                    <span class="font-mono font-bold text-blue-600">#${ui.escapeHtml(item.hash || item.commit || '')}</span>
                    <span class="text-[10px] text-slate-400">${ui.escapeHtml(item.date || item.time || '')}</span>
                </div>
                <div class="text-slate-700 font-medium truncate">${ui.escapeHtml(item.msg || item.subject || '')}</div>
            </div>`).join('')
            : '<div class="text-slate-400 text-center py-6 font-bold">暂无 Commit 历史</div>';
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
