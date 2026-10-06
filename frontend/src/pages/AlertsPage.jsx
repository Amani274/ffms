import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { useApp } from '../auth';
import { date, humanize } from '../format';
import { Badge, Empty, PageHead, Spinner, useAsync } from '../ui';

const GO = {
  inventory_items: '/r/inventory_items', animal_health: '/r/animal_health', maintenance_records: '/r/maintenance_records', equipment: '/r/equipment', irrigation_systems: '/r/irrigation_systems',
  water_sources: '/r/water_sources', irrigation_schedules: '/r/irrigation_schedules', crop_activities: '/r/crop_activities', plantings: '/r/plantings', scouting_reports: '/r/scouting_reports',
  pest_catalog: '/r/pest_catalog', storage_lots: '/r/storage_lots', crops: '/predictions', invoices: '/r/invoices', loans: '/r/loans', tasks: '/r/tasks',
};

export default function AlertsPage() {
  const { farmId, version, toast, refresh, can } = useApp();
  const [sev, setSev] = useState('');
  const [status, setStatus] = useState('active');
  const [type, setType] = useState('');
  const { data, loading, reload } = useAsync(() => api.get('alerts', { per_page: 300, ...(sev ? { severity: sev } : {}), ...(type ? { alert_type: type } : {}) }).then((r) => r.data), [farmId, version, sev, type]);

  const act = async (a, s) => { try { await api.patch(`alerts/${a.id}`, { status: s }); reload(); refresh(); } catch (e) { toast(e.message, 'err'); } };
  const regenerate = async () => { try { const r = await api.post('alerts/refresh'); const v = Object.values(r.data)[0]; toast(`Alerts refreshed — ${v.new} new, ${v.auto_resolved} cleared`); reload(); refresh(); } catch (e) { toast(e.message, 'err'); } };

  const rows = (data || []).filter((a) => (status === 'active' ? a.status !== 'resolved' : status === 'resolved' ? a.status === 'resolved' : true));
  const types = [...new Set((data || []).map((a) => a.alert_type))].sort();
  return (
    <>
      <PageHead title="Alerts & notifications" subtitle="Generated from live farm data: planting, spraying, irrigation, vaccinations, stock, equipment, weather, markets and more.">
        <Link className="btn" to="/r/notification_outbox">Notification outbox</Link>
        {can('alerts', 'update') && <button className="btn primary" onClick={regenerate}>↻ Re-evaluate now</button>}
      </PageHead>
      <div className="toolbar">
        <div className="chips">{[['active', 'Active'], ['resolved', 'Resolved'], ['all', 'All']].map(([k, l]) => <button key={k} className={`chip ${status === k ? 'active' : ''}`} onClick={() => setStatus(k)}>{l}</button>)}</div>
        <select className="input" value={sev} onChange={(e) => setSev(e.target.value)}><option value="">Severity: all</option>{['critical', 'warning', 'info'].map((s) => <option key={s} value={s}>{humanize(s)}</option>)}</select>
        <select className="input" value={type} onChange={(e) => setType(e.target.value)}><option value="">Type: all</option>{types.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}</select>
      </div>
      <div className="card">
        {loading && !data ? <Spinner /> : rows.length === 0 ? <Empty title="No alerts">Everything looks fine.</Empty> : (
          <ul className="list">
            {rows.map((a) => (
              <li key={a.id} style={{ opacity: a.status === 'resolved' ? .6 : 1 }}>
                <span className={`sev ${a.severity}`} />
                <div className="grow">
                  <div className="title">{a.title} {a.status === 'new' && <Badge value="new" kind="info" label="New" />}</div>
                  <div className="sub">{a.message}</div>
                  <div className="sub"><Badge value={a.alert_type} label={humanize(a.alert_type)} />{a.due_date && <span> · due {date(a.due_date)}</span>}</div>
                </div>
                <div className="row-actions">
                  {GO[a.entity] && <Link className="btn sm" to={GO[a.entity]}>Open</Link>}
                  {a.status === 'new' && <button className="btn sm" onClick={() => act(a, 'read')}>Mark read</button>}
                  {a.status !== 'resolved' && <button className="btn sm primary" onClick={() => act(a, 'resolved')}>Resolve</button>}
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </>
  );
}
