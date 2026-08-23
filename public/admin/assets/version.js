export const ASSET_VERSION = 'admin-v20260822_2101_merchant_manager_upgrade';

export function assetUrl(path) {
    const url = new URL(path, window.location.origin);
    url.searchParams.set('v', ASSET_VERSION);
    return url.href;
}
