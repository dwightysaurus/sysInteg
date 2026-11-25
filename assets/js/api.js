// Small helper for calling the portal's JSON API.
// API_BASE is set by each page (relative path to the /api folder).
const Api = (() => {
  async function request(method, path, body) {
    const opts = { method, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    let res, json;
    try {
      res = await fetch(window.API_BASE + path, opts);
      json = await res.json();
    } catch (e) {
      throw { code: 'NETWORK', message: 'Cannot reach the server. Check your connection and try again.' };
    }
    if (res.status === 401 && json?.error?.code === 'UNAUTHENTICATED') {
      window.location.href = window.APP_ROOT + 'index.php';
    }
    if (!json.ok) throw json.error || { code: 'ERROR', message: 'Request failed.' };
    return json.data;
  }
  return {
    get: (p) => request('GET', p),
    post: (p, b = {}) => request('POST', p, b),
    patch: (p, b = {}) => request('PATCH', p, b),
    del: (p) => request('DELETE', p),
  };
})();

const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function toast(msg) {
  const t = document.createElement('div');
  t.className = 'toast';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 2600);
}
