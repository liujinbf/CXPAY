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
        console.warn('Lucide icons rendering warning:', error);
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
        type === 'success' ? 'bg-slate-900 text-white shadow-slate-900/20' : 'bg-rose-600 text-white shadow-rose-600/20'
    }`;
    toast.innerHTML = type === 'success'
        ? `<i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-400"></i> ${message}`
        : `<i data-lucide="alert-circle" class="w-4 h-4 text-white"></i> ${message}`;
    safeCreateIcons();

    clearTimeout(window.toastTimer);
    window.toastTimer = setTimeout(() => {
        toast.classList.add('translate-y-4', 'opacity-0', 'pointer-events-none');
    }, 2500);
}

export function showConfirm(title, message, isDanger = false) {
    return new Promise((resolve) => {
        let modal = document.getElementById('custom-confirm-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'custom-confirm-modal';
            modal.className = 'fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm opacity-0 pointer-events-none transition-all duration-300';
            modal.innerHTML = `
                <div class="bg-white rounded-2xl p-6 shadow-2xl border border-slate-100 max-w-sm w-full transform scale-95 transition-all duration-300 space-y-4">
                    <div class="flex items-center gap-3">
                        <div id="confirm-icon-bg" class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                            <i data-lucide="help-circle" class="w-5 h-5"></i>
                        </div>
                        <h3 id="confirm-modal-title" class="font-extrabold text-base text-slate-800">确认操作</h3>
                    </div>
                    <p id="confirm-modal-message" class="text-xs text-slate-600 leading-relaxed pl-1"></p>
                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button id="confirm-btn-cancel" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold rounded-xl text-xs transition-colors cursor-pointer">取消</button>
                        <button id="confirm-btn-ok" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs transition-colors shadow-sm cursor-pointer">确定</button>
                    </div>
                </div>`;
            document.body.appendChild(modal);
        }

        const iconBg = modal.querySelector('#confirm-icon-bg');
        const titleElement = modal.querySelector('#confirm-modal-title');
        const messageElement = modal.querySelector('#confirm-modal-message');
        const confirmButton = modal.querySelector('#confirm-btn-ok');
        const cancelButton = modal.querySelector('#confirm-btn-cancel');

        titleElement.textContent = title;
        messageElement.textContent = message;

        if (isDanger) {
            iconBg.className = 'w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0';
            confirmButton.className = 'px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl text-xs transition-colors shadow-sm cursor-pointer';
        } else {
            iconBg.className = 'w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0';
            confirmButton.className = 'px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs transition-colors shadow-sm cursor-pointer';
        }

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
