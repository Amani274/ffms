import { useState } from 'react';
import { api } from '../api';
import { useApp } from '../auth';
import { date, humanize } from '../format';
import { Badge, Confirm, DataTable, Empty, FkSelect, Modal, PageHead, Spinner, useAsync } from '../ui';

const ROLE_HELP = {
  admin: 'Full access, users, backups',
  owner: 'All farm data, finance, users',
  manager: 'Operations, labour, approvals',
  agronomist: 'Crops, pests, irrigation, soil',
  worker: 'Own tasks, field records',
  accountant: 'Finance, sales, payroll',
};

function UserForm({ user, onClose, onSaved }) {
  const { toast, role: myRole } = useApp();
  const editing = !!user;
  const [v, setV] = useState({ name: user?.name || '', email: user?.email || '', role: user?.role || 'worker', farm_id: user?.farm_id || '', phone: user?.phone || '', active: user ? user.active : 1, password: '' });
  const [errors, setErrors] = useState({});
  const [msg, setMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const set = (k, val) => setV((p) => ({ ...p, [k]: val }));

  const save = async (e) => {
    e.preventDefault(); setBusy(true); setErrors({}); setMsg('');
    const body = { ...v, farm_id: v.farm_id === '' ? null : v.farm_id };
    if (!body.password) delete body.password;
    try {
      if (editing) await api.patch(`users/${user.id}`, body); else await api.post('users', body);
      toast(editing ? 'User updated' : 'User created'); onSaved();
    } catch (err) { setMsg(err.message); setErrors(err.fields || {}); } finally { setBusy(false); }
  };
  const E = ({ k }) => (errors[k] ? <div className="err">{errors[k]}</div> : null);
  return (
    <Modal title={editing ? `Edit ${user.name}` : 'New user'} onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" form="user-form" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button></>}>
      <form id="user-form" onSubmit={save}>
        {msg && <div className="alert-box bad">{msg}</div>}
        <div className="form-grid">
          <div className={`field ${errors.name ? 'has-err' : ''}`}><label>Full name <span className="req">*</span></label><input className="input" value={v.name} onChange={(e) => set('name', e.target.value)} /><E k="name" /></div>
          <div className={`field ${errors.email ? 'has-err' : ''}`}><label>Email <span className="req">*</span></label><input className="input" type="email" value={v.email} onChange={(e) => set('email', e.target.value)} /><E k="email" /></div>
          <div className="field"><label>Role <span className="req">*</span></label>
            <select className="input" value={v.role} onChange={(e) => set('role', e.target.value)}>
              {Object.keys(ROLE_HELP).filter((r) => myRole === 'admin' || r !== 'admin').map((r) => <option key={r} value={r}>{humanize(r)} — {ROLE_HELP[r]}</option>)}
            </select>
          </div>
          <div className="field"><label>Restrict to farm</label><FkSelect resource="farms" value={v.farm_id} onChange={(x) => set('farm_id', x)} params={{}} /><div className="muted" style={{ fontSize: 12, marginTop: 3 }}>Leave empty for access to all farms.</div></div>
          <div className="field"><label>Phone (for SMS alerts)</label><input className="input" value={v.phone} onChange={(e) => set('phone', e.target.value)} /></div>
          <div className={`field ${errors.password ? 'has-err' : ''}`}><label>{editing ? 'Reset password' : 'Password'} {!editing && <span className="req">*</span>}</label><input className="input" type="password" autoComplete="new-password" value={v.password} onChange={(e) => set('password', e.target.value)} placeholder={editing ? 'Leave blank to keep' : 'Min 10 chars, letters + numbers'} /><E k="password" /></div>
          <div className="field"><label className="check"><input type="checkbox" checked={!!Number(v.active)} onChange={(e) => set('active', e.target.checked ? 1 : 0)} /> Account active</label></div>
        </div>
      </form>
    </Modal>
  );
}

export function UsersPage() {
  const { toast, user: me } = useApp();
  const { data, loading, error, reload } = useAsync(() => api.get('users').then((r) => r.data), []);
  const [modal, setModal] = useState(null);

  const remove = async (u) => { try { await api.del(`users/${u.id}`); toast('User deleted'); setModal(null); reload(); } catch (e) { toast(e.message, 'err'); setModal(null); } };
  const cols = [
    { key: 'name', label: 'Name' }, { key: 'email', label: 'Email' }, { key: 'role', label: 'Role', fmt: 'badge' }, { key: 'farm_name', label: 'Farm', render: (r) => r.farm_name || 'All farms' },
    { key: 'last_login_at', label: 'Last sign-in', render: (r) => (r.last_login_at ? date(r.last_login_at) : 'Never') }, { key: 'active', label: 'Status', render: (r) => <Badge value={r.active ? 'active' : 'inactive'} /> },
  ];
  return (
    <>
      <PageHead title="Users & roles" subtitle="Role-based access: every screen and action is enforced on the server by role and farm.">
        <button className="btn primary" onClick={() => setModal({ type: 'form' })}>+ New user</button>
      </PageHead>
      <div className="card">
        {error ? <div className="alert-box bad" style={{ margin: 14 }}>{error.message}</div> : loading ? <Spinner /> : (
          <DataTable columns={cols} rows={data} empty={<Empty />} actions={(u) => (<>
            <button className="btn sm" onClick={() => setModal({ type: 'form', user: u })}>Edit</button>
            {u.id !== me.id && <button className="btn sm danger" onClick={() => setModal({ type: 'delete', user: u })}>Delete</button>}
          </>)} />
        )}
      </div>
      <div className="grid g3" style={{ marginTop: 14 }}>
        {Object.entries(ROLE_HELP).map(([r, h]) => <div key={r} className="card card-pad"><Badge value={r} kind="info" /><div style={{ marginTop: 6 }}>{h}</div></div>)}
      </div>
      {modal?.type === 'form' && <UserForm user={modal.user} onClose={() => setModal(null)} onSaved={() => { setModal(null); reload(); }} />}
      {modal?.type === 'delete' && <Confirm title="Delete user" danger confirmLabel="Delete" message={`Delete ${modal.user.name}? Their audit history is kept. Consider deactivating instead.`} onClose={() => setModal(null)} onConfirm={() => remove(modal.user)} />}
    </>
  );
}

export function BackupsPage() {
  const { toast } = useApp();
  const { data, loading, error, reload } = useAsync(() => api.get('backups').then((r) => r.data), []);
  const [busy, setBusy] = useState(false);
  const create = async () => { setBusy(true); try { const r = await api.post('backups'); toast(`Backup ${r.data.file} created`); reload(); } catch (e) { toast(e.message, 'err'); } finally { setBusy(false); } };
  const size = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.round(b / 1024)} KB`);
  return (
    <>
      <PageHead title="Data backup" subtitle="Full SQL dumps of the farm database, stored outside the web root.">
        <button className="btn primary" disabled={busy} onClick={create}>{busy ? 'Backing up…' : '+ Create backup now'}</button>
      </PageHead>
      <div className="alert-box info">Restore from the command line: <code>mysql -u root ffms &lt; storage/backups/&lt;file&gt;.sql</code>. Schedule <code>php bin/backup.php</code> in Windows Task Scheduler (or cron) for automatic nightly backups.</div>
      <div className="card">
        {error ? <div className="alert-box bad" style={{ margin: 14 }}>{error.message}</div> : loading ? <Spinner /> : (
          <DataTable columns={[{ key: 'file', label: 'File' }, { key: 'created_at', label: 'Created (UTC)' }, { key: 'size', label: 'Size', render: (r) => size(r.size) }]} rows={data.map((b) => ({ ...b, id: b.file }))}
            empty={<Empty title="No backups yet">Create the first backup with the button above.</Empty>}
            actions={(b) => <a className="btn sm" href={api.url(`backups/${b.file}`)}>Download</a>} />
        )}
      </div>
    </>
  );
}
