export const state = { user: null, csrf: '', demo: true, classes: [], model_classes: [] };
export async function api(action, data, params = {}) {
  const url = new URL('api.php', document.baseURI);
  url.search = new URLSearchParams({ action, ...params });
  const options = { credentials: 'same-origin', headers: {}, signal: AbortSignal.timeout(55000) };
  if (data !== undefined) {
    options.method = 'POST'; options.headers['X-CSRF-Token'] = state.csrf;
    if (data instanceof FormData) options.body = data;
    else { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(data); }
  }
  let response;
  try { response = await fetch(url, options); } catch { throw new Error('Connection failed. Check your connection and try again.'); }
  let body;
  try { body = await response.json(); } catch { throw new Error('The server returned an unexpected response. Please try again.'); }
  if (!response.ok || !body.success) { const error = new Error(body.error || 'Something went wrong.'); error.status = response.status; throw error; }
  return body;
}
export async function bootstrap() { Object.assign(state, await api('bootstrap')); }
export const imageUrl = id => `api.php?action=image&id=${encodeURIComponent(id)}`;
