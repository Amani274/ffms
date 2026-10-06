import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from './api';
import { useApp } from './auth';
import { date, daysLabel, humanize, kg, money, num } from './format';

/* ---------- basics ---------- */
export const Spinner = ({ label = 'Loading…' }) => (
  <div className="loading"><div className="spinner" /><div style={{ marginTop: 10 }}>{label}</div></div>
);

export const Empty = ({ title = 'Nothing here yet', children }) => (
  <div className="empty"><b>{title}</b>{children}</div>
);

const TONE = {
  ok: ['active', 'operational', 'paid', 'done', 'delivered', 'harvested', 'received', 'present', 'resolved', 'approved', 'completed', 'accepted', 'stored', 'born', 'ok', 'good', 'simulated', 'sent', 'strengthening', 'rising'],
  warn: ['low', 'partial', 'pending', 'pending_approval', 'treating', 'maintenance', 'medium', 'open', 'half', 'leave', 'warning', 'unpaid', 'weakening', 'inactive', 'draft', 'quoted'],
  bad: ['critical', 'fault', 'dead', 'failed', 'overdue', 'defaulted', 'absent', 'broken', 'dry', 'offline', 'high', 'terminated', 'cancelled', 'rejected', 'expired', 'spoiled', 'falling', 'culled', 'void', 'aborted'],
  info: ['growing', 'planned', 'scheduled', 'ordered', 'confirmed', 'in_progress', 'pregnant', 'dispatched', 'new', 'info', 'quote', 'queued', 'stable', 'steady', 'todo', 'sold'],
};
export const tone = (v) => {
  const s = String(v ?? '').toLowerCase();
  return Object.keys(TONE).find((k) => TONE[k].includes(s)) || '';
};
export const Badge = ({ value, label, kind }) => (
  value === null || value === undefined || value === '' ? <span className="muted">—</span>
    : <span className={`badge ${kind ?? tone(value)}`}>{label ?? humanize(value)}</span>
);

export const Stat = ({ label, value, hint, kind = '' }) => (
  <div className={`card stat ${kind}`}>
    <div className="label">{label}</div>
    <div className="value">{value}</div>
    {hint && <div className="hint">{hint}</div>}
  </div>
);

export function Modal({ title, onClose, children, footer, size = '' }) {
  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);
  return (
    <div className="overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={`modal ${size}`} role="dialog" aria-modal="true" aria-label={title}>
        <div className="modal-head"><h2>{title}</h2><button className="btn ghost sm" onClick={onClose} aria-label="Close">✕</button></div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-foot">{footer}</div>}
      </div>
    </div>
  );
}

export function Confirm({ title, message, confirmLabel = 'Confirm', danger, onConfirm, onClose }) {
  const [busy, setBusy] = useState(false);
  return (
    <Modal title={title} size="narrow" onClose={onClose} footer={<>
      <button className="btn" onClick={onClose}>Cancel</button>
      <button className={`btn ${danger ? 'danger' : 'primary'}`} disabled={busy} onClick={async () => { setBusy(true); try { await onConfirm(); } finally { setBusy(false); } }}>{confirmLabel}</button>
    </>}>
      <p style={{ margin: 0 }}>{message}</p>
    </Modal>
  );
}

/* ---------- data loading ---------- */
export function useAsync(fn, deps) {
  const [state, setState] = useState({ loading: true, data: null, error: null });
  const seq = useRef(0);
  const run = useCallback(() => {
    const id = ++seq.current;
    setState((s) => ({ ...s, loading: true, error: null }));
    fn().then((data) => id === seq.current && setState({ loading: false, data, error: null }))
      .catch((error) => id === seq.current && setState({ loading: false, data: null, error }));
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  useEffect(() => { run(); }, [run]);
  return { ...state, reload: run };
}

const optionCache = new Map();
export function useOptions(resource, params = {}) {
  const { farmId, version } = useApp();
  const key = `${resource}|${farmId}|${version}|${JSON.stringify(params)}`;
  const [opts, setOpts] = useState(optionCache.get(key) || null);
  useEffect(() => {
    if (!resource) return undefined;
    if (optionCache.has(key)) { setOpts(optionCache.get(key)); return undefined; }
    let live = true;
    api.get(`options/${resource}`, params).then((r) => { optionCache.set(key, r.data); if (live) setOpts(r.data); }).catch(() => live && setOpts([]));
    return () => { live = false; };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
  return opts;
}
export const clearOptionCache = () => optionCache.clear();

/* ---------- cells ---------- */
export function Cell({ col, row }) {
  const v = row[col.key];
  if (col.render) return col.render(row);
  switch (col.fmt) {
    case 'money': return <span className="mono">{money(v)}</span>;
    case 'num': return <span className="mono">{num(v, col.d ?? 0)}{col.suffix || ''}</span>;
    case 'kg': return <span className="mono">{kg(v)}</span>;
    case 'date': return date(v);
    case 'badge': return <Badge value={v} />;
    case 'bool': return v ? <Badge value="yes" kind="ok" /> : <span className="muted">No</span>;
    case 'pct': return v === null || v === undefined ? '—' : <span className="mono">{num(v, 1)}%</span>;
    case 'days': return v === null || v === undefined ? '—' : <span className={Number(v) < 0 ? 'trend-down' : ''}>{daysLabel(v)}</span>;
    case 'text-wrap': return <span style={{ whiteSpace: 'normal' }}>{v ?? '—'}</span>;
    default: return v === null || v === undefined || v === '' ? <span className="muted">—</span> : String(v);
  }
}

const NOWRAP_FMT = ['money', 'num', 'kg', 'pct', 'date', 'badge', 'days', 'bool'];
const nw = (c) => (NOWRAP_FMT.includes(c.fmt) || /(_no|_code|tag_no)$/.test(c.key) ? 'nw' : '');

export function DataTable({ columns, rows, actions, empty }) {
  if (!rows?.length) return empty || <Empty />;
  return (
    <div className="table-wrap">
      <table>
        <thead><tr>{columns.map((c) => <th key={c.key} className={['money', 'num', 'kg', 'pct'].includes(c.fmt) ? 'num' : ''}>{c.label}</th>)}{actions && <th />}</tr></thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={r.id ?? i}>
              {columns.map((c) => <td key={c.key} className={`${['money', 'num', 'kg', 'pct'].includes(c.fmt) ? 'num' : ''} ${nw(c)}`}><Cell col={c} row={r} /></td>)}
              {actions && <td><div className="row-actions">{actions(r)}</div></td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/* ---------- form fields ---------- */
const FK_LABEL = {
  user_id: 'Login account', item_id: 'Inventory item', dam_id: 'Dam (mother)', sire_id: 'Sire (father)', scouting_id: 'Scouting report', catalog_id: 'Pest / disease',
  pump_equipment_id: 'Pump', po_id: 'Purchase order', order_id: 'Sales order', lot_id: 'Storage lot', farm_id: 'Farm',
};
export const fieldLabel = (key) => FK_LABEL[key] || humanize(key.replace(/_id$/, '').replace(/_pct$/, ' %').replace(/_kg$/, ' (kg)').replace(/_ha$/, ' (ha)').replace(/_litres$/, ' (litres)').replace(/_c$/, ' (°C)').replace(/_kph$/, ' (km/h)').replace(/_mm$/, ' (mm)').replace(/_min$/, ' (min)').replace(/_ppm$/, ' (ppm)').replace(/_cm$/, ' (cm)'));

const FK_PARAMS = { plantings: { active: 1 }, animals: { active: 1 }, storage_lots: { available: 1 } };

export function FkSelect({ resource, value, onChange, hasErr, params, disabled, placeholder = '— select —' }) {
  const opts = useOptions(resource, params ?? FK_PARAMS[resource] ?? {});
  return (
    <select className="input" value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))} disabled={disabled} aria-invalid={hasErr}>
      <option value="">{opts ? placeholder : 'Loading…'}</option>
      {(opts || []).map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
      {value && opts && !opts.some((o) => o.id === Number(value)) && <option value={value}>#{value}</option>}
    </select>
  );
}

export function FieldInput({ name, spec, value, onChange, error, label, disabled }) {
  const common = { className: 'input', id: `f-${name}`, disabled };
  const t = spec.type;
  let input;
  if (spec.fk) input = <FkSelect resource={spec.fk} value={value} onChange={onChange} hasErr={!!error} disabled={disabled} />;
  else if (t === 'enum') {
    input = (
      <select {...common} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
        {!spec.required && <option value="">—</option>}
        {spec.enum.map((o) => <option key={o} value={o}>{humanize(o)}</option>)}
      </select>
    );
  } else if (t === 'bool') {
    return (
      <div className="field"><label className="check"><input type="checkbox" checked={!!Number(value)} onChange={(e) => onChange(e.target.checked ? 1 : 0)} disabled={disabled} /> {label}</label></div>
    );
  } else if (t === 'text') input = <textarea {...common} rows={3} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
  else if (t === 'date') input = <input {...common} type="date" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
  else if (t === 'time') input = <input {...common} type="time" value={String(value ?? '').slice(0, 5)} onChange={(e) => onChange(e.target.value)} />;
  else if (t === 'int' || t === 'dec') input = <input {...common} type="number" step={t === 'int' ? 1 : 'any'} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
  else if (t === 'email') input = <input {...common} type="email" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
  else input = <input {...common} type="text" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
  return (
    <div className={`field ${t === 'text' ? 'full' : ''} ${error ? 'has-err' : ''}`}>
      <label htmlFor={`f-${name}`}>{label}{spec.required && <span className="req"> *</span>}</label>
      {input}
      {error && <div className="err">{error}</div>}
    </div>
  );
}

/** Generic create/edit modal built from the server's resource metadata. */
export function ResourceForm({ resource, row, defaults = {}, hide = [], extra = [], title, onClose, onSaved }) {
  const { meta, farmId, toast } = useApp();
  const spec = meta.resources[resource];
  const editing = !!row?.id;
  const [values, setValues] = useState(() => {
    const v = {};
    Object.entries(spec.cols).forEach(([k, s]) => {
      if (k === 'farm_id') return;
      v[k] = editing ? row[k] ?? '' : (defaults[k] ?? (s.default !== null && s.default !== undefined && s.default !== 'null' ? s.default : ''));
    });
    extra.forEach((e) => { v[e.key] = e.default ?? ''; });
    return v;
  });
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErrors({}); setMessage('');
    const payload = { ...values };
    if (!editing && spec.cols.farm_id) payload.farm_id = farmId;
    Object.keys(payload).forEach((k) => { if (payload[k] === '') payload[k] = null; });
    try {
      const res = editing ? await api.patch(`${resource}/${row.id}`, payload) : await api.post(resource, payload);
      toast(editing ? `${spec.label} updated` : `${spec.label} created`);
      onSaved?.(res.data);
    } catch (err) {
      setMessage(err.message);
      setErrors(err.fields || {});
    } finally { setBusy(false); }
  };

  const entries = Object.entries(spec.cols).filter(([k]) => k !== 'farm_id' && !hide.includes(k));
  return (
    <Modal title={title || `${editing ? 'Edit' : 'New'} ${spec.label.toLowerCase()}`} onClose={onClose} footer={<>
      <button className="btn" type="button" onClick={onClose}>Cancel</button>
      <button className="btn primary" form="resource-form" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button>
    </>}>
      <form id="resource-form" onSubmit={submit}>
        {message && <div className="alert-box bad">{message}</div>}
        <div className="form-grid">
          {entries.map(([k, s]) => (
            <FieldInput key={k} name={k} spec={s} label={fieldLabel(k)} value={values[k]} error={errors[k]} onChange={(val) => setValues((p) => ({ ...p, [k]: val }))} />
          ))}
          {extra.map((e) => (
            <FieldInput key={e.key} name={e.key} spec={{ type: e.type || 'bool', required: false, enum: e.enum }} label={e.label} value={values[e.key]} onChange={(val) => setValues((p) => ({ ...p, [e.key]: val }))} />
          ))}
        </div>
      </form>
    </Modal>
  );
}

export function PageHead({ title, subtitle, children }) {
  return (
    <div className="page-head">
      <div><h1>{title}</h1>{subtitle && <p>{subtitle}</p>}</div>
      {children && <div className="actions">{children}</div>}
    </div>
  );
}
