import { assetUrl } from './version.js';

export function resolveFeatureId(requestedId, definitions, knownIds = definitions) {
    const requested = String(requestedId || 'dashboard');
    return definitions.has(requested) || knownIds.has(requested) ? requested : 'dashboard';
}

export function createRouter({
    container,
    definitions,
    knownIds,
    context,
    activateLegacy,
    activateFeature = () => {},
}) {
    const fragmentCache = new Map();
    let activeFeature = null;
    let activeController = null;
    let navigation = 0;

    async function navigate(requestedId) {
        const id = resolveFeatureId(requestedId, definitions, knownIds);
        const currentNavigation = ++navigation;

        activeController?.abort();
        activeController = null;
        if (activeFeature && typeof activeFeature.unmount === 'function') {
            await activeFeature.unmount();
        }
        activeFeature = null;

        const definition = definitions.get(id);
        if (!definition) return activateLegacy(id);

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

            container.innerHTML = `
                <section class="p-6 text-center" data-feature-error="${id}">
                    <p class="text-rose-600 font-bold">页面加载失败，请稍后重试</p>
                    <button type="button" data-action="retry-fragment">重新加载</button>
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
