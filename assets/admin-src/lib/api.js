/**
 * Minimal fetch client for the wpsd/v1 API (admin: X-WP-Nonce).
 * Mirrors the contract in docs/openapi.yaml.
 */
export function api(config) {
  const base = (config && config.restUrl) || '/wp-json/wpsd/v1/';
  const nonce = (config && config.nonce) || '';

  async function request(path, options = {}) {
    const res = await fetch(base + path, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
        ...(options.headers || {}),
      },
    });
    const json = await res.json().catch(() => null);
    if (!res.ok || (json && json.success === false)) {
      const err = new Error((json && json.error && json.error.message) || `Request failed (${res.status})`);
      err.code = json && json.error && json.error.code;
      err.details = json && json.data;
      err.status = res.status;
      throw err;
    }
    return json ? json.data : null;
  }

  return {
    get: (p) => request(p),
    post: (p, body) => request(p, { method: 'POST', body: JSON.stringify(body) }),
    patch: (p, body) => request(p, { method: 'PATCH', body: JSON.stringify(body) }),
    put: (p, body) => request(p, { method: 'PUT', body: JSON.stringify(body) }),
    del: (p) => request(p, { method: 'DELETE' }),
  };
}
