import { assetUrl } from './version.js';

export function resolveFeatureId(requestedId, definitions, knownIds = definitions) {
    const requested = String(requestedId || 'dashboard');
    return definitions.has(requested) || knownIds.has(requested) ? requested : 'dashboard';
}

export function createRouter({
    container,
    definitions,
    context,
    activateFeature = () => {},
}) {
    const fragmentCache = new Map();
    let activeFeature = null;
    let activeController = null;
    let navigation = 0;

    async function navigate(requestedId) {
        const id = resolveFeatureId(requestedId, definitions);
        const currentNavigation = ++navigation;

        activeController?.abort();
        activeController = null;
        if (activeFeature && typeof activeFeature.unmount === 'function') {
            await activeFeature.unmount();
        }
        activeFeature = null;

        const definition = definitions.get(id);

        activateFeature(id);
        activeController = new AbortController();
        const signal = activeController.signal;

        try {
            const viewUrl = assetUrl(`/merchant/views/${definition.view}`);
            const fragmentPromise = fragmentCache.has(viewUrl)
                ? Promise.resolve(fragmentCache.get(viewUrl))
                : fetchFragment(viewUrl, signal).then((html) => {
                    fragmentCache.set(viewUrl, html);
                    return html;
                });
            const [html, module] = await Promise.all([
                fragmentPromise,
                import(assetUrl(`/merchant/assets/features/${definition.module}`)),
            ]);

            if (currentNavigation !== navigation) return;
            if (!module.feature || typeof module.feature.mount !== 'function') {
                throw new Error(`功能模块 ${id} 未导出有效 feature`);
            }

            container.innerHTML = html;
            activeFeature = module.feature;
            await activeFeature.mount({
                ...context,
                root: container,
                signal,
                navigate,
            });
        } catch (error) {
            if (currentNavigation !== navigation || error?.name === 'AbortError') return;
            console.error(`[Router Error] Failed to load feature ${id}:`, error);

            container.innerHTML = `
                <section class="p-8 text-center space-y-4" data-feature-error="${id}">
                    <div class="inline-flex p-3 rounded-full bg-rose-50 text-rose-500 font-black text-2xl">⚠️</div>
                    <h3 class="text-base font-extrabold text-slate-800">模块「${id}」加载失败</h3>
                    <p class="text-xs text-rose-600 font-bold">${error?.message || '未知脚本错误'}</p>
                    <pre class="text-[11px] text-slate-500 font-mono max-w-xl mx-auto overflow-auto p-3 bg-slate-50 rounded-xl border border-slate-200 text-left">${error?.stack || String(error)}</pre>
                    <div>
                        <button type="button" data-action="retry-fragment" class="px-5 py-2.5 bg-blue-600 text-white rounded-xl text-xs font-bold shadow-md hover:bg-blue-700 transition-all cursor-pointer">重新加载</button>
                    </div>
                </section>`;
            container.querySelector('[data-action="retry-fragment"]')
                ?.addEventListener('click', () => navigate(id), { once: true });
        }
    }

    return { navigate };
}

async function fetchFragment(url, signal) {
    const response = await fetch(url, { signal });
    if (!response.ok) {
        throw new Error(`页面片段加载失败（${response.status}）`);
    }

    return response.text();
}
