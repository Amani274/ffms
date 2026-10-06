import { useState } from 'react';
import { useApp } from '../auth';

const DEMO = [
  ['admin@ffms.test', 'Administrator', 'Everything incl. users & backups'],
  ['owner@ffms.test', 'Farm owner', 'All farms, finance, reports'],
  ['manager@ffms.test', 'Farm manager', 'Operations & approvals'],
  ['agronomist@ffms.test', 'Agronomist', 'Crops, pests, irrigation'],
  ['accountant@ffms.test', 'Accountant', 'Finance, sales, payroll'],
  ['worker@ffms.test', 'Worker', 'Own tasks & field records'],
];

export default function LoginPage() {
  const { login } = useApp();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const showDemo = import.meta.env.VITE_SHOW_DEMO !== '0';

  const submit = async (e) => {
    e?.preventDefault();
    setBusy(true); setError('');
    try { await login(email, password); } catch (err) { setError(err.message); } finally { setBusy(false); }
  };

  return (
    <div className="login">
      <div className="login-hero">
        <div className="brand" style={{ padding: 0 }}>
          <div className="brand-mark"><svg width="20" height="20" viewBox="0 0 32 32"><path d="M16 4c6 4 9 8 9 14a9 9 0 0 1-18 0c0-6 3-10 9-14z" fill="#1f6b3a" /></svg></div>
          <div><b>FFMS</b><small>Full Farm Management System</small></div>
        </div>
        <h1>Every field, herd and kwacha — in one place.</h1>
        <p>Plan crops, manage livestock and irrigation, track inventory and money, and get weather- and data-driven recommendations for better decisions.</p>
        <ul>
          <li>Crops, harvest, storage and sales from planting to payment</li>
          <li>Livestock, feed, health and production records</li>
          <li>Live alerts, yield and price forecasts</li>
          <li>Role-based access with a full audit trail</li>
        </ul>
      </div>
      <div className="login-panel">
        <div className="login-box">
          <h2>Sign in</h2>
          <p className="muted" style={{ marginTop: 0 }}>Use your farm account to continue.</p>
          {error && <div className="alert-box bad" role="alert">{error}</div>}
          <form onSubmit={submit}>
            <div className="field" style={{ marginBottom: 12 }}><label htmlFor="email">Email</label><input id="email" className="input" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus /></div>
            <div className="field" style={{ marginBottom: 16 }}><label htmlFor="password">Password</label><input id="password" className="input" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required /></div>
            <button className="btn primary" style={{ width: '100%', padding: '10px' }} disabled={busy}>{busy ? 'Signing in…' : 'Sign in'}</button>
          </form>
          {showDemo && (
            <>
              <p className="muted" style={{ marginBottom: 6, marginTop: 22, fontSize: 12.5 }}>Demo accounts — click to fill the email, password is <code>Farm@Demo2026</code>:</p>
              <div className="demo-list">
                {DEMO.map(([em, role, desc]) => (
                  <button key={em} type="button" onClick={() => { setEmail(em); setPassword('Farm@Demo2026'); setError(''); }}><b>{role}</b><span className="muted" style={{ fontSize: 12 }}>{desc}</span></button>
                ))}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
