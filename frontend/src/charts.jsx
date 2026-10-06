import { Bar, Doughnut, Line } from 'react-chartjs-2';
import {
  ArcElement, BarElement, CategoryScale, Chart as ChartJS, Filler, Legend, LinearScale, LineElement, PointElement, Tooltip,
} from 'chart.js';

ChartJS.register(ArcElement, BarElement, CategoryScale, Filler, Legend, LinearScale, LineElement, PointElement, Tooltip);

export const PALETTE = ['#2a8148', '#e9a23b', '#2f7fb8', '#8a5a2b', '#b9e07a', '#c0392b', '#7a5ca8', '#3aa6a0', '#d77fa1', '#6b7c3a'];

const css = (v, fallback) => (typeof window === 'undefined' ? fallback : getComputedStyle(document.documentElement).getPropertyValue(v).trim() || fallback);

function baseOptions({ money, legend = true, stacked = false, yTitle } = {}) {
  const text = css('--muted', '#62705f');
  const grid = css('--border', '#e1e6dc');
  return {
    responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
    plugins: { legend: { display: legend, position: 'bottom', labels: { color: text, boxWidth: 10, usePointStyle: true, font: { size: 12 } } }, tooltip: { padding: 10, cornerRadius: 8 } },
    scales: {
      x: { stacked, grid: { display: false }, ticks: { color: text, maxRotation: 0, autoSkip: true, font: { size: 11 } } },
      y: { stacked, beginAtZero: true, grid: { color: grid }, ticks: { color: text, font: { size: 11 }, callback: (v) => (money ? compact(v) : v) }, title: yTitle ? { display: true, text: yTitle, color: text } : undefined },
    },
  };
}
const compact = (v) => new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(v);

export const LineChart = ({ labels, datasets, money, legend, yTitle }) => (
  <Line data={{ labels, datasets: datasets.map((d, i) => ({ borderColor: PALETTE[i], backgroundColor: `${PALETTE[i]}22`, tension: 0.3, pointRadius: 2, borderWidth: 2, fill: d.fill ?? false, ...d })) }} options={baseOptions({ money, legend, yTitle })} />
);

export const BarChart = ({ labels, datasets, money, legend, stacked, yTitle }) => (
  <Bar data={{ labels, datasets: datasets.map((d, i) => ({ backgroundColor: PALETTE[i], borderRadius: 4, maxBarThickness: 34, ...d })) }} options={baseOptions({ money, legend, stacked, yTitle })} />
);

export const DonutChart = ({ labels, values }) => (
  <Doughnut
    data={{ labels, datasets: [{ data: values, backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]), borderWidth: 0 }] }}
    options={{ responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right', labels: { color: css('--muted', '#62705f'), boxWidth: 10, usePointStyle: true, font: { size: 12 } } } } }}
  />
);
