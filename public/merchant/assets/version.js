export const MERCHANT_ASSET_VERSION = 'merchant-modules-v2';

export function assetUrl(path) {
    const url = new URL(path, window.location.origin);
    url.searchParams.set('v', MERCHANT_ASSET_VERSION);
    return url.href;
}
