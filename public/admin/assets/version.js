export const ASSET_VERSION = 'admin-modules-v1';

export function assetUrl(path) {
    const url = new URL(path, window.location.origin);
    url.searchParams.set('v', ASSET_VERSION);
    return url.href;
}
