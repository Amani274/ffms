import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { api } from '../api';
import { useApp } from '../auth';
import { RESOURCES } from '../resources';
import { humanize } from '../format';
import { Confirm, DataTable, Empty, FkSelect, PageHead, ResourceForm, Spinner, clearOptionCache, useAsync } from '../ui';
import { LinesModal, OrderBuilder, PayModal, PayrollModal } from './Documents';

const FK_OF = {
  field_id: 'fields', crop_id: 'crops', planting_id: 'plantings', animal_id: 'animals', worker_id: 'workers', equipment_id: 'equipment', item_id: 'inventory_items',
  supplier_id: 'suppliers', customer_id: 'customers', warehouse_id: 'warehouses', system_id: 'irrigation_systems', loan_id: 'loans', po_id: 'purchase_orders',
  order_id: 'sales_orders', invoice_id: 'invoices', lot_id: 'storage_lots', scouting_id: 'scouting_reports',
};
const PER_PAGE = 25;

function Filter({ name, spec, value, onChange }) {
  if (FK_OF[name]) return <div style={{ minWidth: 170 }}><FkSelect resource={FK_OF[name]} value={value} onChange={onChange} params={{}} placeholder={`${humanize(name.replace(/_id$/, ''))}: all`} /></div>;
  if (spec?.enum) {
    return (
      <select className="input" value={value ?? ''} onChange={(e) => onChange(e.target.value)} aria-label={humanize(name)}>
        <option value="">{humanize(name)}: all</option>
        {spec.enum.map((o) => <option key={o} value={o}>{humanize(o)}</option>)}
      </select>
    );
  }
  return <input className="input" placeholder={humanize(name)} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />;
}

export default function ResourcePage({ name: forced }) {
  const params = useParams();
  const name = forced || params.name;
  const cfg = RESOURCES[name];
  const { meta, can, farmId, version, toast, refresh } = useApp();
  const spec = meta.resources[name];

  const [filters, setFilters] = useState({});
  const [qInput, setQInput] = useState('');
  const [q, setQ] = useState('');
  const [range, setRange] = useState({ from: '', to: '' });
  const [page, setPage] = useState(1);
  const [modal, setModal] = useState(null);

  useEffect(() => { setFilters({}); setQInput(''); setQ(''); setRange({ from: '', to: '' }); setPage(1); setModal(null); }, [name]);
  useEffect(() => { const t = setTimeout(() => { setQ(qInput); setPage(1); }, 300); return () => clearTimeout(t); }, [qInput]);

  const query = useMemo(() => {
    const p = { page, per_page: PER_PAGE };
    if (q) p.q = q;
    if (range.from) p.from = range.from;
    if (range.to) p.to = range.to;
    Object.entries(filters).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) p[k] = v; });
    return p;
  }, [page, q, range, filters]);

  const { data, loading, error, reload } = useAsync(
    () => (cfg && spec?.read ? api.get(name, query) : Promise.resolve(null)),
    [name, farmId, version, JSON.stringify(query)]
  );

  if (!cfg) return <Empty title="Unknown screen" />;
  if (!spec?.read) return <Empty title="No access">Your role does not have access to {cfg.title.toLowerCase()}.</Empty>;

  const rows = data?.data || [];
  const total = data?.meta?.total ?? 0;
  const pages = Math.max(1, Math.ceil(total / PER_PAGE));
  const done = (msg) => { clearOptionCache(); if (msg) toast(msg); setModal(null); reload(); refresh(); };

  const runAction = async (a, row) => {
    try {
      if (a.patch) await api.patch(`${name}/${row.id}`, a.patch);
      else if (a.post) await api.post(a.post(row));
      toast(`${a.label} — done`);
      clearOptionCache(); reload();
    } catch (e) { toast(e.message, 'err'); }
    setModal(null);
  };

  const onAction = (a, row) => {
    if (a.form) return setModal({ type: 'form', resource: a.form.resource, defaults: a.form.defaults(row), title: a.label });
    if (a.pay) return setModal({ type: 'pay', row });
    if (a.confirm) return setModal({ type: 'confirm', action: a, row });
    return runAction(a, row);
  };

  const rowActions = (r) => {
    const out = [];
    (cfg.actions || []).filter((a) => !a.show || a.show(r)).forEach((a, i) => {
      const allowed = a.form ? can(a.form.resource, 'create') : can(name, 'update');
      if (allowed) out.push(<button key={i} className={`btn sm ${a.kind || ''}`} onClick={() => onAction(a, r)}>{a.label}</button>);
    });
    if (cfg.detail) out.push(<button key="lines" className="btn sm" onClick={() => setModal({ type: 'lines', row: r })}>Lines</button>);
    if (spec.update && (!cfg.editable || cfg.editable(r))) out.push(<button key="e" className="btn sm" onClick={() => setModal({ type: 'edit', row: r })}>Edit</button>);
    if (spec.delete && (!cfg.deletable || cfg.deletable(r))) out.push(<button key="d" className="btn sm danger" onClick={() => setModal({ type: 'delete', row: r })}>Delete</button>);
    return out;
  };

  const remove = async (row) => {
    try { await api.del(`${name}/${row.id}`); done(`${spec.label} deleted`); }
    catch (e) { toast(e.message, 'err'); setModal(null); }
  };

  const hasFilters = (cfg.filters || []).length || cfg.search || cfg.dates;
  return (
    <>
      <PageHead title={cfg.title} subtitle={cfg.sub}>
        {cfg.toolbar === 'payroll' && can('payroll_runs', 'create') && <button className="btn" onClick={() => setModal({ type: 'payroll' })}>Generate payroll</button>}
        {spec.create && <button className="btn primary" onClick={() => setModal({ type: cfg.create ? 'builder' : 'create' })}>+ New {spec.label.toLowerCase()}</button>}
      </PageHead>

      {hasFilters && (
        <div className="toolbar">
          {cfg.search && <input className="input search" placeholder="Search…" value={qInput} onChange={(e) => setQInput(e.target.value)} aria-label="Search" />}
          {(cfg.filters || []).map((f) => (
            <Filter key={f} name={f} spec={spec.cols[f]} value={filters[f]} onChange={(v) => { setFilters((s) => ({ ...s, [f]: v })); setPage(1); }} />
          ))}
          {cfg.dates && (<>
            <input className="input" type="date" value={range.from} onChange={(e) => { setRange((r) => ({ ...r, from: e.target.value })); setPage(1); }} aria-label="From date" />
            <span className="muted">to</span>
            <input className="input" type="date" value={range.to} onChange={(e) => { setRange((r) => ({ ...r, to: e.target.value })); setPage(1); }} aria-label="To date" />
          </>)}
          {(Object.values(filters).some((v) => v) || qInput || range.from || range.to) && (
            <button className="btn sm ghost" onClick={() => { setFilters({}); setQInput(''); setRange({ from: '', to: '' }); setPage(1); }}>Clear</button>
          )}
        </div>
      )}

      <div className="card">
        {error ? <div className="alert-box bad" style={{ margin: 14 }}>{error.message}</div>
          : loading && !data ? <Spinner />
            : <DataTable columns={cfg.columns} rows={rows} actions={(spec.update || spec.delete || cfg.actions || cfg.detail) ? rowActions : null}
              empty={<Empty title={`No ${cfg.title.toLowerCase()} found`}>{spec.create ? 'Use the button above to add the first one.' : 'Records will appear here as the farm operates.'}</Empty>} />}
        {total > 0 && (
          <div className="pager">
            <span>{total} record{total === 1 ? '' : 's'}</span><span className="spacer" />
            <button className="btn sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>← Previous</button>
            <span>Page {page} of {pages}</span>
            <button className="btn sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>Next →</button>
          </div>
        )}
      </div>

      {modal?.type === 'create' && <ResourceForm resource={name} hide={cfg.hide || []} extra={cfg.extra || []} onClose={() => setModal(null)} onSaved={() => done()} />}
      {modal?.type === 'edit' && <ResourceForm resource={name} row={modal.row} hide={cfg.hide || []} onClose={() => setModal(null)} onSaved={() => done()} />}
      {modal?.type === 'form' && <ResourceForm resource={modal.resource} defaults={modal.defaults} title={modal.title} extra={RESOURCES[modal.resource]?.extra || []} onClose={() => setModal(null)} onSaved={() => done()} />}
      {modal?.type === 'builder' && <OrderBuilder kind={cfg.create} onClose={() => setModal(null)} onSaved={() => done()} />}
      {modal?.type === 'lines' && <LinesModal resource={cfg.detail} keyName={cfg.detailKey} parent={modal.row} title={`${modal.row.order_no || modal.row.po_no} — lines`} onClose={() => setModal(null)} />}
      {modal?.type === 'pay' && <PayModal po={modal.row} onClose={() => setModal(null)} onSaved={() => done()} />}
      {modal?.type === 'payroll' && <PayrollModal onClose={() => setModal(null)} onSaved={() => done()} />}
      {modal?.type === 'confirm' && <Confirm title={modal.action.label} message={modal.action.confirm} danger={modal.action.kind === 'danger'} confirmLabel={modal.action.label} onClose={() => setModal(null)} onConfirm={() => runAction(modal.action, modal.row)} />}
      {modal?.type === 'delete' && <Confirm title="Delete record" danger confirmLabel="Delete" message="This permanently deletes the record. This cannot be undone." onClose={() => setModal(null)} onConfirm={() => remove(modal.row)} />}
    </>
  );
}
