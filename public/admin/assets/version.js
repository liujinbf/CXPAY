export const ASSET_VERSION = 'admin-v20260824_2128_agent_hub_restored';

export function assetUrl(path) {
    const url = new URL(path, window.location.origin);
    url.searchParams.set('v', ASSET_VERSION);
    return url.href;
}


