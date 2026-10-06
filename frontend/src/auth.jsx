import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, setActiveFarm, setCsrf, setUnauthorizedHandler } from './api';
import { setCurrency } from './format';

const Ctx = createContext(null);
export const useApp = () => useContext(Ctx);

export function AppProvider({ children }) {
  const [state, setState] = useState({ loading: true, user: null, meta: null });
  const [farmId, setFarmId] = useState(() => {
    try { return Number(localStorage.getItem('ffms.farm')) || null; } catch { return null; }
  });
  const [toasts, setToasts] = useState([]);
  const [version, setVersion] = useState(0);

  const loadMeta = useCallback(async () => {
    const { data } = await api.get('meta');
    setCsrf(data.csrf);
    setCurrency(data.currency);
    const farms = data.farms;
    let active = data.active_farm || null;
    if (!active) {
      const stored = Number(localStorage.getItem('ffms.farm'));
      active = farms.find((f) => f.id === stored)?.id || farms[0]?.id || null;
    }
    setActiveFarm(active);
    setFarmId(active);
    setState({ loading: false, user: data.user, meta: data });
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => setState({ loading: false, user: null, meta: null }));
    api.get('auth/me').then(loadMeta).catch(() => setState({ loading: false, user: null, meta: null }));
  }, [loadMeta]);

  const login = async (email, password) => {
    const { data } = await api.post('auth/login', { email, password });
    setCsrf(data.csrf);
    await loadMeta();
  };

  const logout = async () => {
    try { await api.post('auth/logout'); } catch { /* already signed out */ }
    setCsrf('');
    setState({ loading: false, user: null, meta: null });
  };

  const switchFarm = (id) => {
    setActiveFarm(id);
    setFarmId(id);
    try { localStorage.setItem('ffms.farm', String(id)); } catch { /* storage unavailable */ }
    setVersion((v) => v + 1);
  };

  const toast = useCallback((message, kind = 'ok') => {
    const id = Math.random().toString(36).slice(2);
    setToasts((t) => [...t, { id, message, kind }]);
    setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), kind === 'err' ? 6000 : 3500);
  }, []);

  const value = useMemo(() => ({
    ...state, farmId, farm: state.meta?.farms.find((f) => f.id === farmId) || null, version, toasts, toast,
    login, logout, switchFarm, refresh: () => setVersion((v) => v + 1),
    can: (res, op = 'read') => !!state.meta?.resources?.[res]?.[op],
    role: state.user?.role,
  }), [state, farmId, version, toasts, toast]);

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}
