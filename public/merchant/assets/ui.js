let toastTimer = null;

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;',
    })[character]);
}

export function safeCreateIcons() {
    try {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    } catch (error) {
        console.warn('Lucide 图标刷新失败：', error);
    }
}

export function showToast(message, type = 'success') {
    let toast = document.getElementById('global-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'global-toast';
        toast.className = 'fixed bottom-6 right-6 z-50 px-4 py-2.5 rounded-xl shadow-lg font-bold text-xs flex items-center gap-2 transition-all duration-300 transform translate-y-4 opacity-0 pointer-events-none';
        document.body.appendChild(toast);
    }

    toast.className = `fixed bottom-6 right-6 z-50 px-4 py-2.5 rounded-xl shadow-lg font-bold text-xs flex items-center gap-2 transition-all duration-300 transform translate-y-0 opacity-100 ${
        type === 'success'
            ? 'bg-slate-900 text-white shadow-slate-900/20'
            : 'bg-rose-600 text-white shadow-rose-600/20'
    }`;
    const icon = type === 'success'
        ? '<i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-400"></i> '
        : '<i data-lucide="alert-circle" class="w-4 h-4 text-white"></i> ';
    toast.innerHTML = `${icon}${escapeHtml(message)}`;
    safeCreateIcons();

    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.classList.add('translate-y-4', 'opacity-0', 'pointer-events-none');
    }, 2000);
}

export function showConfirm(title, message, isDanger = false) {
    return new Promise((resolve) => {
        let modal = document.getElementById('custom-confirm-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'custom-confirm-modal';
            modal.className = 'fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm opacity-0 pointer-events-none transition-all duration-300';
            modal.innerHTML = `
                <div class="bg-white rounded-2xl p-6 shadow-2xl border border-slate-100 max-w-sm w-full transform scale-95 transition-all duration-300 space-y-4">
                    <div class="flex items-center gap-3">
                        <div data-confirm-icon class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
                            <i data-lucide="help-circle" class="w-5 h-5"></i>
                        </div>
                        <h3 data-confirm-title class="font-extrabold text-base text-slate-800">确认操作</h3>
                    </div>
                    <p data-confirm-message class="text-xs text-slate-600 leading-relaxed pl-1"></p>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button type="button" data-confirm-cancel class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold rounded-xl text-xs transition-colors cursor-pointer">取消</button>
                        <button type="button" data-confirm-ok class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-xl text-xs transition-colors shadow-sm cursor-pointer">确定</button>
                    </div>
                </div>`;
            document.body.appendChild(modal);
        }

        const icon = modal.querySelector('[data-confirm-icon]');
        const titleElement = modal.querySelector('[data-confirm-title]');
        const messageElement = modal.querySelector('[data-confirm-message]');
        const confirmButton = modal.querySelector('[data-confirm-ok]');
        const cancelButton = modal.querySelector('[data-confirm-cancel]');

        titleElement.textContent = title;
        messageElement.textContent = message;
        icon.className = `w-10 h-10 rounded-xl ${
            isDanger ? 'bg-rose-50 text-rose-600' : 'bg-purple-50 text-purple-600'
        } flex items-center justify-center shrink-0`;
        confirmButton.className = `px-4 py-2 ${
            isDanger ? 'bg-rose-600 hover:bg-rose-700' : 'bg-purple-600 hover:bg-purple-700'
        } text-white font-bold rounded-xl text-xs transition-colors shadow-sm cursor-pointer`;

        safeCreateIcons();
        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.firstElementChild.classList.remove('scale-95');

        const cleanup = (value) => {
            modal.classList.add('opacity-0', 'pointer-events-none');
            modal.firstElementChild.classList.add('scale-95');
            confirmButton.onclick = null;
            cancelButton.onclick = null;
            resolve(value);
        };

        confirmButton.onclick = () => cleanup(true);
        cancelButton.onclick = () => cleanup(false);
    });
}

export async function copyText(value, trigger = null) {
    const text = String(value ?? '');
    if (!text) {
        showToast('暂无可复制的内容', 'error');
        return false;
    }

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            fallbackCopy(text);
        }
        showToast('已成功复制到剪贴板！');
        showCopiedState(trigger);
        return true;
    } catch {
        showToast('复制失败，请手动选中复制', 'error');
        return false;
    }
}

function fallbackCopy(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    const copied = document.execCommand('copy');
    document.body.removeChild(textArea);
    if (!copied) throw new Error('浏览器拒绝复制');
}

function showCopiedState(trigger) {
    if (!trigger || trigger.tagName !== 'BUTTON') return;
    const originalText = trigger.innerText;
    trigger.innerText = '已复制 √';
    trigger.classList.add('bg-emerald-600', 'text-white');
    setTimeout(() => {
        trigger.innerText = originalText;
        trigger.classList.remove('bg-emerald-600', 'text-white');
    }, 1500);
}
