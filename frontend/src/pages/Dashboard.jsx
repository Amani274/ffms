import { Link } from 'react-router-dom';
import { api } from '../api';
import { useApp } from '../auth';
import { BarChart, DonutChart } from '../charts';
import { daysLabel, humanize, kg, money, monthLabel, num, shortDate } from '../format';
import { Badge, Empty, PageHead, Spinner, Stat, useAsync } from '../ui';

const wxIcon = (c = '') => {
  const s = c.toLowerCase();
  if (s.includes('thunder')) return '⛈️';
  if (s.includes('heavy') || s.includes('rain') || s.includes('shower') || s.includes('drizzle')) return '🌧️';
  if (s.includes('cloud') || s.includes('overcast')) return '⛅';
  if (s.includes('fog')) return '🌫️';
  return '☀️';
};

export default function Dashboard() {
  const { user, farm, farmId, version, refresh } = useApp();
  const { data, loading, error, reload } = useAsync(() => api.get('dashboard').then((r) => r.data), [farmId, version]);

  if (error) return <div className="alert-box bad">{error.message}</div>;
  if (loading || !data) return <Spinner label="Loading dashboard…" />;
  const d = data;
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
  const fin = d.finance;
  const crit = d.alerts.counts.find((c) => c.severity === 'critical')?.count || 0;
  const warn = d.alerts.counts.find((c) => c.severity === 'warning')?.count || 0;
  const wx = d.weather;

  return (
    <>
      <PageHead title={`${greeting}, ${user.name.split(' ')[0]}`} subtitle={`${farm?.name || 'All farms'} — ${new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}`}>
        <button className="btn" onClick={() => { reload(); refresh(); }}>Reload</button>
      </PageHead>

      <div className="stats">
        <Stat label="Farm area" value={`${num(d.land.total_ha)} ha`} hint={`${d.land.fields} fields · ${num(d.land.planted_ha, 1)} ha planted`} />
        <Stat label="Active crops" value={d.land.active_plantings} hint={`${d.land.active_crops} crop types growing`} />
        <Stat label="Livestock" value={num(d.livestock_total)} hint={d.livestock.slice(0, 3).map((l) => `${l.count} ${l.species}`).join(' · ')} />
        <Stat label="Expected harvest" value={kg(d.land.expected_kg)} hint="from growing crops" kind="info" />
        <Stat label="Harvested this year" value={kg(d.actual_production_kg_ytd)} hint="net of losses" />
        <Stat label="Open alerts" value={crit + warn} kind={crit ? 'bad' : warn ? 'warn' : 'good'} hint={`${crit} critical · ${warn} warning`} />
      </div>

      {fin && (
        <div className="stats">
          <Stat label="Income (YTD)" value={money(fin.income_ytd, { compact: true })} kind="good" />
          <Stat label="Expenses (YTD)" value={money(fin.expense_ytd, { compact: true })} />
          <Stat label="Profit (YTD)" value={money(fin.profit_ytd, { compact: true })} kind={fin.profit_ytd >= 0 ? 'good' : 'bad'} hint={fin.income_ytd ? `${num((fin.profit_ytd / fin.income_ytd) * 100)}% margin` : ''} />
          <Stat label="Receivables" value={money(fin.receivables, { compact: true })} kind={fin.overdue_invoices ? 'warn' : ''} hint={`${fin.overdue_invoices} overdue invoice${fin.overdue_invoices === 1 ? '' : 's'}`} />
          <Stat label="Loan balance" value={money(fin.loan_balance, { compact: true })} />
          <Stat label="Inventory value" value={money(d.inventory.value, { compact: true })} hint={`${d.inventory.low_stock} low · ${d.inventory.expiring} expiring`} kind={d.inventory.low_stock ? 'warn' : ''} />
        </div>
      )}

      <div className="grid g-main" style={{ marginBottom: 14 }}>
        {fin ? (
          <div className="card">
            <div className="card-head"><h3>Income vs expenses</h3><span className="sub">last 12 months</span></div>
            <div className="chart-box">
              <BarChart money labels={d.finance_monthly.income.map((m) => monthLabel(m.month))} datasets={[
                { label: 'Income', data: d.finance_monthly.income.map((m) => m.value), backgroundColor: '#2a8148' },
                { label: 'Expenses', data: d.finance_monthly.expense.map((m) => m.value), backgroundColor: '#e9a23b' },
              ]} />
            </div>
          </div>
        ) : (
          <div className="card">
            <div className="card-head"><h3>Harvest output</h3><span className="sub">kg, last 12 months</span></div>
            <div className="chart-box"><BarChart labels={d.production_monthly.map((m) => monthLabel(m.month))} datasets={[{ label: 'Harvested (kg)', data: d.production_monthly.map((m) => m.value) }]} legend={false} /></div>
          </div>
        )}
        <div className="card">
          <div className="card-head"><h3>Alerts</h3><Link className="sub" to="/alerts">View all →</Link></div>
          {d.alerts.latest.length === 0 ? <Empty title="All clear">No active alerts.</Empty> : (
            <ul className="list">
              {d.alerts.latest.slice(0, 6).map((a) => (
                <li key={a.id}><span className={`sev ${a.severity}`} /><div className="grow"><div className="title">{a.title}</div><div className="sub">{a.message}</div></div></li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <div className="grid g3" style={{ marginBottom: 14 }}>
        <div className="card">
          <div className="card-head"><h3>Weather</h3><Link className="sub" to="/weather">Details →</Link></div>
          <div className="card-pad">
            {wx.current ? (
              <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 12 }}>
                <div style={{ fontSize: 40 }}>{wxIcon(wx.current.conditions)}</div>
                <div><div style={{ fontSize: 26, fontWeight: 700 }}>{num(wx.current.temp_max_c)}°C</div><div className="muted">{wx.current.conditions} · {num(wx.current.humidity_pct)}% humidity · {num(wx.rain_30d_mm)} mm rain (30 d)</div></div>
              </div>
            ) : <p className="muted">No observations recorded.</p>}
            <div className="wx-days" style={{ border: '1px solid var(--border)', borderRadius: 10 }}>
              {wx.forecast.map((f) => (
                <div className="wx-day" key={f.forecast_date}>
                  <div className="muted" style={{ fontSize: 12 }}>{shortDate(f.forecast_date)}</div>
                  <div style={{ fontSize: 22 }}>{wxIcon(f.conditions)}</div>
                  <div style={{ fontWeight: 600 }}>{num(f.temp_max_c)}°</div>
                  <div className="muted" style={{ fontSize: 12 }}>{num(f.rain_probability_pct)}% · {num(f.rainfall_mm, 1)}mm</div>
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="card">
          <div className="card-head"><h3>Upcoming harvests</h3><Link className="sub" to="/r/plantings">Plantings →</Link></div>
          {d.upcoming_harvests.length === 0 ? <Empty title="No crops growing" /> : (
            <ul className="list">
              {d.upcoming_harvests.map((h) => (
                <li key={h.id}><div className="grow"><div className="title">{h.crop} <span className="muted">· {h.field}</span></div><div className="sub">{humanize(h.growth_stage)} · est. {kg(h.expected_yield_kg)}</div></div><Badge value={h.days} kind={h.days < 8 ? 'warn' : 'info'} label={daysLabel(h.days)} /></li>
              ))}
            </ul>
          )}
        </div>

        <div className="card">
          <div className="card-head"><h3>Crop growth stages</h3></div>
          <div className="chart-box short">
            {d.crop_stages.length ? <DonutChart labels={d.crop_stages.map((s) => `${humanize(s.stage)} (${s.plantings})`)} values={d.crop_stages.map((s) => Number(s.area_ha))} /> : <Empty title="No active crops" />}
          </div>
          <div className="muted" style={{ padding: '0 18px 12px', fontSize: 12.5 }}>Share of planted area by stage</div>
        </div>
      </div>

      <div className="grid g4">
        <div className="card">
          <div className="card-head"><h3>Pests & diseases</h3><Link className="sub" to="/r/scouting_reports">Scouting →</Link></div>
          {d.pests.latest.length === 0 ? <Empty title="No open reports" /> : (
            <ul className="list">{d.pests.latest.map((p) => (<li key={p.id}><span className={`sev ${p.severity === 'critical' ? 'critical' : p.severity === 'high' ? 'warning' : 'info'}`} /><div className="grow"><div className="title">{p.pest}</div><div className="sub">{p.field} · {humanize(p.severity)}</div></div></li>))}</ul>
          )}
        </div>
        <div className="card">
          <div className="card-head"><h3>Irrigation</h3><Link className="sub" to="/r/irrigation_schedules">Schedules →</Link></div>
          <div className="card-pad">
            <div style={{ display: 'grid', gap: 8 }}>
              {d.irrigation.systems.map((s) => (<div key={s.status} style={{ display: 'flex', justifyContent: 'space-between' }}><Badge value={s.status} /><b>{s.count} system{s.count > 1 ? 's' : ''}</b></div>))}
              <hr style={{ border: 0, borderTop: '1px solid var(--border)', width: '100%' }} />
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span className="muted">Runs planned today</span><b>{d.irrigation.planned_today}</b></div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span className="muted">Water used (30 d)</span><b>{num(d.irrigation.litres_30d / 1000)} m³</b></div>
            </div>
          </div>
        </div>
        <div className="card">
          <div className="card-head"><h3>Low stock</h3><Link className="sub" to="/r/inventory_items">Inventory →</Link></div>
          {d.low_stock_items.length === 0 ? <Empty title="Stock levels healthy" /> : (
            <ul className="list">{d.low_stock_items.map((i) => (<li key={i.id}><div className="grow"><div className="title">{i.name}</div><div className="sub">{num(i.quantity, 1)} {i.unit} left · reorder at {num(i.reorder_level)}</div></div></li>))}</ul>
          )}
        </div>
        {d.market && <div className="card">
          <div className="card-head"><h3>Market prices</h3><Link className="sub" to="/predictions">Outlook →</Link></div>
          {d.market.length === 0 ? <Empty title="No prices recorded" /> : (
            <ul className="list">
              {d.market.slice(0, 6).map((m) => {
                const ch = m.prev_price ? ((m.price - m.prev_price) / m.prev_price) * 100 : null;
                return (<li key={m.crop_id}><div className="grow"><div className="title">{m.crop}</div></div><div style={{ textAlign: 'right' }}><b className="mono">{money(m.price, { decimals: 2 })}</b>/kg{ch !== null && <div className={ch >= 0 ? 'trend-up' : 'trend-down'} style={{ fontSize: 12 }}>{ch >= 0 ? '▲' : '▼'} {num(Math.abs(ch), 1)}%</div>}</div></li>);
              })}
            </ul>
          )}
        </div>}
      </div>
    </>
  );
}
