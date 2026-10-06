import { useEffect, useState } from 'react';
import { HashRouter, NavLink, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { api } from './api';
import { AppProvider, useApp } from './auth';
import { NAV } from './resources';
import { Badge, Modal, Spinner } from './ui';
import { BackupsPage, UsersPage } from './pages/AdminPages';
import AlertsPage from './pages/AlertsPage';
import Dashboard from './pages/Dashboard';
import FieldsPage from './pages/FieldsPage';
import LoginPage from './pages/LoginPage';
import PredictionsPage from './pages/PredictionsPage';
import ReportsPage from './pages/ReportsPage';
import ResourcePage from './pages/ResourcePage';
import WeatherPage from './pages/WeatherPage';

function ChangePassword({ onClose }) {
  const { toast } = useApp();
  const [v, setV] = useState({ current_password: '', new_password: '' });
  const [errors, setErrors] = useState({});
  const [msg, setMsg] = useState('');
  const save = async (e) => {
    e.preventDefault(); setErrors({}); setMsg('');
    try { await api.post('auth/change-password', v); toast('Password changed'); onClose(); } catch (err) { setMsg(err.message); setErrors(err.fields || {}); }
  };
  return (
    <Modal title="Change password" size="narrow" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" form="pw-form">Change password</button></>}>
      <form id="pw-form" onSubmit={save}>
        {msg && <div className="alert-box bad">{msg}</div>}
        <div className="field" style={{ marginBottom: 12 }}><label>Current password</label><input className="input" type="password" autoComplete="current-password" value={v.current_password} onChange={(e) => setV({ ...v, current_password: e.target.value })} />{errors.current_password && <div className="err">{errors.current_password}</div>}</div>
        <div className="field"><label>New password</label><input className="input" type="password" autoComplete="new-password" value={v.new_password} onChange={(e) => setV({ ...v, new_password: e.target.value })} placeholder="Min 10 characters, letters and numbers" /></div>
      </form>
    </Modal>
  );
}

function Shell() {
  const { user, meta, farm, farmId, switchFarm, logout, version, toasts, role, can } = useApp();
  const [open, setOpen] = useState(false);
  const [pw, setPw] = useState(false);
  const [alertCount, setAlertCount] = useState(0);
  const loc = useLocation();

  useEffect(() => { setOpen(false); }, [loc.pathname]);
  useEffect(() => {
    if (!can('alerts', 'read')) return;
    api.get('alerts', { per_page: 1, status: 'new,read' }).then((r) => setAlertCount(r.meta?.total || 0)).catch(() => {});
  }, [farmId, version, loc.pathname]);

  const visible = (key) => {
    if (!key) return true;
    if (key === '__reports') return meta.reports.length > 0;
    if (key === '__users') return ['admin', 'owner'].includes(role);
    if (key === '__backups') return role === 'admin';
    if (key === '__predictions') return role !== 'worker';
    return can(key, 'read');
  };

  return (
    <div className="shell">
      <aside className={`sidebar ${open ? 'open' : ''}`}>
        <div className="brand">
          <div className="brand-mark"><svg width="20" height="20" viewBox="0 0 32 32"><path d="M16 4c6 4 9 8 9 14a9 9 0 0 1-18 0c0-6 3-10 9-14z" fill="#1f6b3a" /></svg></div>
          <div><b>FFMS</b><small>Farm management</small></div>
        </div>
        <nav className="nav" aria-label="Main">
          {NAV.map((g) => {
            const items = g.items.filter((i) => visible(i[2]));
            if (!items.length) return null;
            return (
              <div className="nav-group" key={g.title}>
                <div className="nav-title">{g.title}</div>
                {items.map(([to, label]) => (
                  <NavLink key={to} to={to} end={to === '/'} className={({ isActive }) => (isActive ? 'active' : '')}>
                    <span className="dot" />{label}
                    {to === '/alerts' && alertCount > 0 && <span className="count">{alertCount}</span>}
                  </NavLink>
                ))}
              </div>
            );
          })}
        </nav>
      </aside>

      <div className="main">
        <header className="topbar">
          <button className="btn menu-btn" onClick={() => setOpen(!open)} aria-label="Menu">☰</button>
          {meta.farms.length > 1 ? (
            <select className="input" style={{ width: 'auto', fontWeight: 600 }} value={farmId || ''} onChange={(e) => switchFarm(Number(e.target.value))} aria-label="Active farm">
              {meta.farms.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
            </select>
          ) : <b>{farm?.name}</b>}
          <span className="spacer" />
          {can('alerts', 'read') && <NavLink to="/alerts" className="btn" aria-label="Alerts">🔔 {alertCount > 0 ? <span className="badge bad">{alertCount}</span> : 'Alerts'}</NavLink>}
          <div className="user-meta" style={{ textAlign: 'right', lineHeight: 1.2 }}><div style={{ fontWeight: 600 }}>{user.name}</div><Badge value={role} kind="info" /></div>
          <button className="btn sm hide-sm" onClick={() => setPw(true)}>Password</button>
          <button className="btn sm" onClick={logout}>Sign out</button>
        </header>
        <main className="content">
          <Routes>
            <Route path="/" element={<Dashboard />} />
            <Route path="/alerts" element={<AlertsPage />} />
            <Route path="/fields" element={<FieldsPage />} />
            <Route path="/weather" element={<WeatherPage />} />
            <Route path="/predictions" element={<PredictionsPage />} />
            <Route path="/reports" element={<ReportsPage />} />
            <Route path="/users" element={<UsersPage />} />
            <Route path="/backups" element={<BackupsPage />} />
            <Route path="/r/:name" element={<ResourcePage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </main>
      </div>
      {pw && <ChangePassword onClose={() => setPw(false)} />}
      <div className="toasts" aria-live="polite">{toasts.map((t) => <div key={t.id} className={`toast ${t.kind === 'err' ? 'err' : ''}`}>{t.message}</div>)}</div>
    </div>
  );
}

function Gate() {
  const { loading, user } = useApp();
  if (loading) return <div style={{ height: '100%', display: 'grid', placeItems: 'center' }}><Spinner label="Starting FFMS…" /></div>;
  return user ? <Shell /> : <LoginPage />;
}

export default function App() {
  return (
    <HashRouter>
      <AppProvider>
        <Gate />
      </AppProvider>
    </HashRouter>
  );
}
