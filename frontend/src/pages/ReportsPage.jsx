import { useState } from 'react';
import { api } from '../api';
import { useApp } from '../auth';
import { date, money, num } from '../format';
import { Badge, Empty, PageHead, Spinner, useAsync } from '../ui';

const fmtCell = (col, v) => {
  if (v === null || v === undefined || v === '') return <span className="muted">—</span>;
  if (col.format === 'money') return <span className="mono">{money(v, { decimals: 2 })}</span>;
  if (col.format === 'number') return <span className="mono">{num(v, 2)}</span>;
  if (col.format === 'date') return date(v);
  if (['status', 'state', 'invoice_status', 'kind'].includes(col.key)) return <Badge value={String(v)} />;
  return String(v);
};

export default function ReportsPage() {
  const { meta, farmId, version } = useApp();
  const year = new Date().getFullYear();
  const [sel, setSel] = useState(meta.reports[0]?.key);
  const [range, setRange] = useState({ from: `${year}-01-01`, to: new Date().toISOString().slice(0, 10) });
  const { data, loading, error } = useAsync(() => (sel ? api.get(`reports/${sel}`, range).then((r) => r.data) : Promise.resolve(null)), [sel, range.from, range.to, farmId, version]);

  if (!meta.reports.length) return <Empty title="No reports available">Your role does not have access to reports.</Empty>;
  const current = meta.reports.find((r) => r.key === sel);
  const numeric = (c) => ['money', 'number'].includes(c.format);

  return (
    <>
      <PageHead title="Reports & analytics" subtitle="Run any report for a date range." />
      <div className="reports-layout">
        <div className="card no-print">
          <ul className="list">
            {meta.reports.map((r) => (
              <li key={r.key} style={{ cursor: 'pointer', background: r.key === sel ? 'var(--green-50)' : undefined }} onClick={() => setSel(r.key)}>
                <div className="grow"><div className="title" style={{ color: r.key === sel ? 'var(--green-700)' : undefined }}>{r.title}</div></div>
              </li>
            ))}
          </ul>
        </div>
        <div>
          <div className="toolbar no-print">
            <label className="muted">From</label><input className="input" type="date" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} />
            <label className="muted">To</label><input className="input" type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} />
            {[['This year', `${year}-01-01`, `${year}-12-31`], ['Last year', `${year - 1}-01-01`, `${year - 1}-12-31`], ['Last 90 days', new Date(Date.now() - 90 * 864e5).toISOString().slice(0, 10), new Date().toISOString().slice(0, 10)]].map(([l, f, t]) => (
              <button key={l} className="btn sm" onClick={() => setRange({ from: f, to: t > new Date().toISOString().slice(0, 10) ? new Date().toISOString().slice(0, 10) : t })}>{l}</button>
            ))}
          </div>
          <div className="card">
            <div className="card-head"><h3>{current?.title}</h3><span className="sub">{current?.description}</span></div>
            {error ? <div className="alert-box bad" style={{ margin: 14 }}>{error.message}</div> : loading || !data ? <Spinner label="Running report…" /> : (
              <>
                {Object.keys(data.summary).length > 0 && (
                  <div style={{ display: 'flex', gap: 22, flexWrap: 'wrap', padding: '12px 18px' }}>
                    {Object.entries(data.summary).map(([k, v]) => (
                      <div key={k}><div className="muted" style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: .4 }}>{k}</div><div style={{ fontWeight: 700, fontSize: 17 }}>{typeof v === 'number' ? num(v, 2) : v}</div></div>
                    ))}
                  </div>
                )}
                {data.rows.length === 0 ? <Empty title="No data for this period">Try widening the date range.</Empty> : (
                  <div className="table-wrap">
                    <table>
                      <thead><tr>{data.columns.map((c) => <th key={c.key} className={numeric(c) ? 'num' : ''}>{c.label}</th>)}</tr></thead>
                      <tbody>{data.rows.map((r, i) => <tr key={i}>{data.columns.map((c) => <td key={c.key} className={numeric(c) ? 'num' : ''}>{fmtCell(c, r[c.key])}</td>)}</tr>)}</tbody>
                    </table>
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
