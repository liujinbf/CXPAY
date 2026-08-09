export async function merchantFetch(url, options = {}) {
    const response = await fetch(url, options);
    if (response.status === 401) {
        window.location.assign('/merchant_login.html');
        throw new Error('商户登录状态已失效');
    }

    return response;
}
