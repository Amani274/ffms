import { useState } from 'react';
import { api } from '../api';
import { useApp } from '../auth';
import { LineChart } from '../charts';
import { date, humanize, kg, money, monthLabel, num } from '../format';
import { Badge, Empty, PageHead, Spinner, Stat, useAsync } from '../ui';

function YieldTab() {
  const { farmId, version } = useApp();
  const { data, loading, error } = useAsync(() => api.get('predictions/yield').then((r) => r.data), [farmId, version]);
  const [open, setOpen] = useState(null);
  if (error) return <div className="alert-box bad">{error.message}</div>;
  if (loading) return <Spinner label="Calculating yield predictions…" />;
  if (!data.length) return <Empty title="No active plantings">Plant a crop to see its predicted yield.</Empty>;
  const totalPred = data.reduce((s, p) => s + p.predicted_kg, 0);
  const totalRev = data.reduce((s, p) => s + (p.expected_revenue || 0), 0);
  return (
    <>
      <div className="stats">
        <Stat label="Predicted harvest" value={kg(totalPred)} hint={`${data.length} active plantings`} />
        <Stat label="Baseline (variety targets)" value={kg(data.reduce((s, p) => s + p.baseline_kg, 0))} />
        <Stat label="Expected revenue" value={money(totalRev, { compact: true })} kind="good" hint="at forecast prices, net of losses" />
      </div>
      <div className="alert-box info">Predictions combine each variety’s target yield with this farm’s history, soil pH, rain + irrigation received, pest pressure, heat stress and fertiliser programme. Open a card to see exactly what moved the number.</div>
      <div className="grid" style={{ gap: 12 }}>
        {data.map((p) => {
          const isOpen = open === p.planting_id;
          return (
            <div className="card" key={p.planting_id}>
              <div className="pred-row">
                <div><div style={{ fontWeight: 700, fontSize: 16 }}>{p.crop}</div><div className="muted">{p.field} · {num(p.area_ha, 1)} ha · {humanize(p.stage)}</div></div>
                <div><div className="muted" style={{ fontSize: 12 }}>PREDICTED YIELD</div><div style={{ fontWeight: 700, fontSize: 19 }}>{kg(p.predicted_kg)}</div><div className="muted" style={{ fontSize: 12 }}>{kg(p.low_kg)} – {kg(p.high_kg)} · {num(p.predicted_kg_ha)} kg/ha</div></div>
                <div><div className="muted" style={{ fontSize: 12 }}>VS VARIETY TARGET</div><div className={p.vs_baseline_pct >= 0 ? 'trend-up' : 'trend-down'} style={{ fontWeight: 700, fontSize: 19 }}>{p.vs_baseline_pct >= 0 ? '+' : ''}{p.vs_baseline_pct}%</div><div className="muted" style={{ fontSize: 12 }}>target {kg(p.baseline_kg)}</div></div>
                <div><div className="muted" style={{ fontSize: 12 }}>EXPECTED REVENUE</div><div style={{ fontWeight: 700 }}>{p.expected_revenue ? money(p.expected_revenue, { compact: true }) : '—'}</div><div className="muted" style={{ fontSize: 12 }}>{p.expected_price_per_kg ? `@ ${money(p.expected_price_per_kg, { decimals: 2 })}/kg` : 'no price data'}</div></div>
                <button className="btn sm" onClick={() => setOpen(isOpen ? null : p.planting_id)}>{isOpen ? 'Hide' : 'Why?'}</button>
              </div>
              <div style={{ padding: '0 18px 12px' }}>
                <div className="muted" style={{ fontSize: 12, marginBottom: 4 }}>Growth progress {p.progress_pct}% · harvest {date(p.expected_harvest_date)} · confidence {p.confidence_pct}%</div>
                <div className="bar"><i style={{ width: `${p.progress_pct}%` }} /></div>
              </div>
              {isOpen && (
                <div style={{ borderTop: '1px solid var(--border)', padding: '10px 18px 14px' }}>
                  {p.factors.map((f) => (
                    <div className="factor" key={f.key}>
                      <b>{f.label}</b>
                      <span className="muted" style={{ fontSize: 13 }}>{f.detail}</span>
                      <b className={f.impact_pct < -0.5 ? 'trend-down' : f.impact_pct > 0.5 ? 'trend-up' : 'muted'} style={{ textAlign: 'right' }}>{f.impact_pct > 0 ? '+' : ''}{f.impact_pct}%</b>
                    </div>
                  ))}
                  <div className="muted" style={{ fontSize: 12, marginTop: 8 }}>Expected post-harvest loss {p.expected_loss_pct}% (from farm history). Method: {p.method}.</div>
                </div>
              )}
            </div>
          );
        })}
      </div>
    </>
  );
}

function PriceTab() {
  const { farmId, version } = useApp();
  const crops = useAsync(() => api.get('predictions/price').then((r) => r.data.filter((c) => c.available)), [farmId, version]);
  const [sel, setSel] = useState(null);
  const cropId = sel ?? crops.data?.[0]?.crop_id;
  const detail = useAsync(() => (cropId ? api.get(`predictions/price/${cropId}`).then((r) => r.data) : Promise.resolve(null)), [cropId]);
  if (crops.loading) return <Spinner />;
  if (!crops.data?.length) return <Empty title="Not enough price history">Record at least four months of market prices to enable forecasts.</Empty>;
  const f = detail.data;
  return (
    <>
      <div className="chips" style={{ marginBottom: 14 }}>
        {crops.data.map((c) => <button key={c.crop_id} className={`chip ${cropId === c.crop_id ? 'active' : ''}`} onClick={() => setSel(c.crop_id)}>{c.crop} <span style={{ opacity: .75 }}>{c.direction === 'rising' ? '▲' : c.direction === 'falling' ? '▼' : '►'}</span></button>)}
      </div>
      {detail.loading || !f ? <Spinner /> : !f.available ? <Empty title="No forecast">{f.message}</Empty> : (
        <>
          <div className="stats">
            <Stat label="Current price" value={`${money(f.current_price, { decimals: 2 })}/kg`} />
            <Stat label="Trend" value={`${f.trend_pct_per_month > 0 ? '+' : ''}${f.trend_pct_per_month}%/mo`} kind={f.direction === 'rising' ? 'good' : f.direction === 'falling' ? 'bad' : ''} hint={humanize(f.direction)} />
            <Stat label="Best selling month" value={monthLabel(f.best_month)} hint={`${money(f.best_price, { decimals: 2 })}/kg (${f.upside_pct >= 0 ? '+' : ''}${f.upside_pct}% vs now)`} kind="info" />
            <Stat label="Market risk" value={humanize(f.risk)} kind={f.risk === 'high' ? 'bad' : f.risk === 'medium' ? 'warn' : 'good'} hint={`volatility ${f.volatility_pct}% · demand ${f.demand_trend}`} />
          </div>
          <div className="card">
            <div className="card-head"><h3>{f.crop} price history & 6-month forecast</h3><span className="sub">{money(0).replace('0', '')}/kg</span></div>
            <div className="chart-box tall">
              {(() => {
                const hist = f.history.slice(-24);
                const labels = [...hist.map((h) => monthLabel(h.month)), ...f.forecast.map((x) => monthLabel(x.month))];
                const lastH = hist[hist.length - 1].price;
                return (
                  <LineChart money labels={labels} datasets={[
                    { label: 'Market price', data: [...hist.map((h) => h.price), ...Array(f.forecast.length).fill(null)], borderColor: '#2a8148' },
                    { label: 'Forecast', data: [...Array(hist.length - 1).fill(null), lastH, ...f.forecast.map((x) => x.price)], borderColor: '#e9a23b', borderDash: [6, 4] },
                  ]} />
                );
              })()}
            </div>
          </div>
          <div className="card" style={{ marginTop: 14 }}>
            <div className="card-head"><h3>Forecast detail</h3></div>
            <div className="table-wrap"><table><thead><tr><th>Month</th><th className="num">Expected price / kg</th></tr></thead>
              <tbody>{f.forecast.map((x) => <tr key={x.month} style={x.month === f.best_month ? { background: 'var(--green-50)' } : undefined}><td>{monthLabel(x.month)} {x.month === f.best_month && <Badge value="best" kind="ok" label="Best month" />}</td><td className="num mono">{money(x.price, { decimals: 2 })}</td></tr>)}</tbody></table></div>
            <div className="muted" style={{ padding: '10px 18px 14px', fontSize: 12.5 }}>Method: {f.method}. Forecasts are decision support, not guarantees.</div>
          </div>
        </>
      )}
    </>
  );
}

function RecoTab() {
  const { farmId, version } = useApp();
  const { data, loading, error } = useAsync(() => api.get('predictions/recommendations').then((r) => r.data), [farmId, version]);
  if (error) return <div className="alert-box bad">{error.message}</div>;
  if (loading) return <Spinner label="Analysing conditions…" />;
  const b = data.weather_basis;
  return (
    <>
      <div className="stats">
        <Stat label="Rain next 48 h" value={`${b.rain_48h_mm} mm`} kind="info" />
        <Stat label="Rain last 30 days" value={`${b.rain_30d_mm} mm`} />
        <Stat label="Mean temperature" value={`${b.mean_temp_c}°C`} />
      </div>
      <div className="grid g-main">
        <div className="card">
          <div className="card-head"><h3>Recommended actions</h3><span className="sub">from forecast + field data</span></div>
          {data.actions.length ? (
            <ul className="list">{data.actions.map((a, i) => <li key={i}><span className={`sev ${a.severity}`} /><div className="grow"><div className="title">{a.title}</div><div className="sub">{a.message}</div></div><Badge value={a.type} kind="info" /></li>)}</ul>
          ) : <Empty title="No recommendations right now" />}
        </div>
        <div className="card">
          <div className="card-head"><h3>How it works</h3></div>
          <div className="card-pad muted" style={{ fontSize: 13.5 }}>
            Irrigation advice compares forecast rainfall, recent rain, days since the last irrigation run and temperature. Crop suitability scores each crop against the field’s soil pH, current temperature, and water availability.
          </div>
        </div>
      </div>
      <h3 style={{ margin: '18px 0 10px' }}>Crop suitability by field</h3>
      <div className="grid g3">
        {data.crop_suitability.map((f) => (
          <div className="card" key={f.field_id}>
            <div className="card-head"><h3>{f.field}</h3><span className="sub">pH {f.soil_ph ?? '–'}</span></div>
            <ul className="list">
              {f.top.map((c, i) => (
                <li key={c.crop_id}><div className="grow"><div className="title">{i + 1}. {c.crop}</div><div className="sub">{c.reasons.join(' · ')}</div><div className={`bar ${c.score < 50 ? 'bad' : c.score < 70 ? 'warn' : ''}`} style={{ marginTop: 5 }}><i style={{ width: `${c.score}%` }} /></div></div><b className="mono">{c.score}</b></li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </>
  );
}

export default function PredictionsPage() {
  const [tab, setTab] = useState('yield');
  const tabs = [['yield', 'Yield outlook'], ['price', 'Price & market'], ['reco', 'Recommendations']];
  return (
    <>
      <PageHead title="Predictions & decision support" subtitle="Transparent, rule-based forecasts built from your own farm data." />
      <div className="tabs">{tabs.map(([k, l]) => <button key={k} className={`tab ${tab === k ? 'active' : ''}`} onClick={() => setTab(k)}>{l}</button>)}</div>
      {tab === 'yield' && <YieldTab />}
      {tab === 'price' && <PriceTab />}
      {tab === 'reco' && <RecoTab />}
    </>
  );
}
