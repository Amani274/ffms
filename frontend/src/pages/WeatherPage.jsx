import { useState } from 'react';
import { api } from '../api';
import { useApp } from '../auth';
import { BarChart, LineChart } from '../charts';
import { monthLabel, num, shortDate } from '../format';
import { Badge, Empty, PageHead, Spinner, Stat, useAsync } from '../ui';
import ResourcePage from './ResourcePage';

const icon = (c = '') => {
  const s = c.toLowerCase();
  if (s.includes('thunder')) return '⛈️';
  if (s.includes('heavy') || s.includes('rain') || s.includes('shower') || s.includes('drizzle')) return '🌧️';
  if (s.includes('cloud') || s.includes('overcast')) return '⛅';
  if (s.includes('fog')) return '🌫️';
  return '☀️';
};

export default function WeatherPage() {
  const { farmId, version, toast, can } = useApp();
  const [tab, setTab] = useState('overview');
  const { data, loading, error, reload } = useAsync(() => api.get('weather').then((r) => r.data), [farmId, version]);

  const refresh = async () => {
    try { await api.post('weather/refresh'); toast('Forecast refreshed'); reload(); } catch (e) { toast(e.message, 'err'); }
  };
  if (error) return <div className="alert-box bad">{error.message}</div>;
  if (loading || !data) return <Spinner label="Loading weather…" />;
  const live = data.source === 'open-meteo';
  const cur = data.current;
  const hist = data.history;
  const rain30 = hist.slice(-30).reduce((s, r) => s + Number(r.rainfall_mm), 0);

  return (
    <>
      <PageHead title="Weather & environment" subtitle="Forecasts, rainfall, temperature, humidity and wind for this farm.">
        <button className="btn" onClick={refresh}>↻ Refresh forecast</button>
      </PageHead>
      <div className="tabs">
        {[['overview', 'Overview'], ['records', 'Records']].map(([k, l]) => <button key={k} className={`tab ${tab === k ? 'active' : ''}`} onClick={() => setTab(k)}>{l}</button>)}
      </div>

      {tab === 'records' ? <ResourcePage name="weather_records" /> : (
        <>
          <div className={`alert-box ${live ? 'ok' : 'info'}`}>
            {live ? 'Live 7-day forecast from Open-Meteo for the farm’s coordinates.' : data.source === 'estimate' ? 'Offline estimate based on this farm’s own weather history (no internet forecast available).' : 'Showing stored forecast data. Use “Refresh forecast” when online for the live outlook.'}
          </div>

          {data.risks.length > 0 && (
            <div className="card" style={{ marginBottom: 14 }}>
              <div className="card-head"><h3>Weather risks (next 7 days)</h3></div>
              <ul className="list">{data.risks.map((r, i) => <li key={i}><span className={`sev ${r.severity}`} /><div className="grow"><div className="title">{shortDate(r.date)} — {r.type.replace('_', ' ')}</div><div className="sub">{r.message}</div></div><Badge value={r.severity} /></li>)}</ul>
            </div>
          )}

          <div className="stats">
            <Stat label="Latest observation" value={cur ? `${num(cur.temp_max_c)}°C` : '—'} hint={cur ? `${cur.conditions} · ${cur.record_date}` : ''} />
            <Stat label="Humidity" value={cur ? `${num(cur.humidity_pct)}%` : '—'} />
            <Stat label="Wind" value={cur ? `${num(cur.wind_kph)} km/h` : '—'} />
            <Stat label="Rainfall (30 days)" value={`${num(rain30)} mm`} kind="info" />
          </div>

          <div className="card" style={{ marginBottom: 14 }}>
            <div className="card-head"><h3>7-day forecast</h3></div>
            <div className="wx-days" style={{ margin: 14, border: '1px solid var(--border)', borderRadius: 10 }}>
              {data.forecast.map((f) => (
                <div className="wx-day" key={f.forecast_date} style={{ minWidth: 118 }}>
                  <div style={{ fontWeight: 600 }}>{new Date(`${f.forecast_date}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short' })}</div>
                  <div className="muted" style={{ fontSize: 12 }}>{shortDate(f.forecast_date)}</div>
                  <div style={{ fontSize: 30, margin: '4px 0' }}>{icon(f.conditions)}</div>
                  <div style={{ fontWeight: 700 }}>{num(f.temp_max_c)}° <span className="muted" style={{ fontWeight: 400 }}>{num(f.temp_min_c)}°</span></div>
                  <div className="muted" style={{ fontSize: 12 }}>{f.conditions}</div>
                  <div style={{ fontSize: 12.5, marginTop: 4 }}>💧 {num(f.rain_probability_pct)}% · {num(f.rainfall_mm, 1)} mm</div>
                  <div className="muted" style={{ fontSize: 12 }}>💨 {num(f.wind_kph)} km/h</div>
                </div>
              ))}
            </div>
          </div>

          <div className="grid g2">
            <div className="card">
              <div className="card-head"><h3>Temperature</h3><span className="sub">last 60 days</span></div>
              <div className="chart-box"><LineChart labels={hist.map((h) => shortDate(h.record_date))} datasets={[{ label: 'Max °C', data: hist.map((h) => Number(h.temp_max_c)), borderColor: '#e9a23b' }, { label: 'Min °C', data: hist.map((h) => Number(h.temp_min_c)), borderColor: '#2f7fb8' }]} /></div>
            </div>
            <div className="card">
              <div className="card-head"><h3>Monthly rainfall</h3><span className="sub">mm, last 24 months</span></div>
              <div className="chart-box">{data.monthly.length ? <BarChart labels={data.monthly.map((m) => monthLabel(m.month))} datasets={[{ label: 'Rainfall (mm)', data: data.monthly.map((m) => Number(m.rainfall_mm)), backgroundColor: '#2f7fb8' }]} legend={false} /> : <Empty />}</div>
            </div>
          </div>
          {can('weather_records', 'create') && <p className="muted" style={{ marginTop: 12 }}>Record daily observations under the “Records” tab to improve yield predictions and the offline estimate.</p>}
        </>
      )}
    </>
  );
}
