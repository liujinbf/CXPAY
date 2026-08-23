let isRedirecting = false;

export async function adminFetch(url, options = {}) {
    const headers = new Headers(options.headers || {});
    const token = localStorage.getItem('cx_admin_token');
    if (token) headers.set('Authorization', `Bearer ${token}`);

    const response = await fetch(url, { ...options, headers });
    if (response.status === 401) {
        localStorage.removeItem('cx_admin_token');
        if (!isRedirecting) {
            isRedirecting = true;
            window.location.replace('/admin_login.html');
        }
        throw new Error('管理员登录状态已失效');
    }

    return response;
}

