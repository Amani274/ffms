import axios from 'axios';

const BASE = import.meta.env.VITE_API_URL || '/ffms/api';
let csrf = '';
let activeFarm = null;
let onUnauthorized = () => {};

export const setCsrf = (t) => { csrf = t || ''; };
export const setActiveFarm = (id) => { activeFarm = id; };
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn; };

const http = axios.create({ baseURL: BASE, withCredentials: true, headers: { 'Content-Type': 'application/json' } });

http.interceptors.request.use((cfg) => {
  if (csrf && cfg.method !== 'get') cfg.headers['X-CSRF-Token'] = csrf;
  if (activeFarm && cfg.method === 'get' && !(cfg.params && 'farm_id' in cfg.params)) {
    cfg.params = { ...(cfg.params || {}), farm_id: activeFarm };
  }
  return cfg;
});

http.interceptors.response.use(
  (r) => r,
  (err) => {
    const res = err.response;
    if (res?.status === 401 && !err.config.url.includes('auth/login')) onUnauthorized();
    const e = new Error(res?.data?.message || (res ? `Request failed (${res.status})` : 'Cannot reach the server. Is Apache running?'));
    e.status = res?.status;
    e.fields = res?.data?.errors && Object.keys(res.data.errors).length ? res.data.errors : null;
    return Promise.reject(e);
  }
);

export const api = {
  get: (url, params) => http.get(url, { params }).then((r) => r.data),
  post: (url, body) => http.post(url, body ?? {}).then((r) => r.data),
  patch: (url, body) => http.patch(url, body).then((r) => r.data),
  del: (url) => http.delete(url).then((r) => r.data),
  url: (path, params = {}) => {
    const q = new URLSearchParams({ ...(activeFarm ? { farm_id: activeFarm } : {}), ...params }).toString();
    return `${BASE}/${path}${q ? `?${q}` : ''}`;
  },
};
