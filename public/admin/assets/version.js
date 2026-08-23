export const ASSET_VERSION = 'admin-v20260824_0315_fix_render';

export function assetUrl(path) {
    const url = new URL(path, window.location.origin);
    url.searchParams.set('v', ASSET_VERSION);
    return url.href;
}

