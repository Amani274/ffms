let currency = 'ZMW';
export const setCurrency = (c) => { currency = c || 'ZMW'; };
const SYMBOL = { ZMW: 'K', USD: '$', EUR: '€', GBP: '£', KES: 'KSh ', TZS: 'TSh ', ZAR: 'R' };

export const money = (v, opts = {}) => {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  if (!Number.isFinite(n)) return '—';
  const sym = SYMBOL[currency] ?? `${currency} `;
  const abs = Math.abs(n);
  const body = opts.compact && abs >= 1000
    ? new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(abs)
    : abs.toLocaleString('en', { minimumFractionDigits: opts.decimals ?? (abs < 100 && abs % 1 ? 2 : 0), maximumFractionDigits: opts.decimals ?? 2 });
  return `${n < 0 ? '−' : ''}${sym}${body}`;
};

export const num = (v, d = 0) => {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  return Number.isFinite(n) ? n.toLocaleString('en', { maximumFractionDigits: d }) : '—';
};

export const kg = (v) => (v === null || v === undefined ? '—' : Math.abs(Number(v)) >= 1000 ? `${num(Number(v) / 1000, 1)} t` : `${num(v)} kg`);

export const date = (v) => {
  if (!v) return '—';
  const d = new Date(String(v).length <= 10 ? `${v}T00:00:00` : String(v).replace(' ', 'T') + 'Z');
  return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
};

export const shortDate = (v) => {
  if (!v) return '';
  const d = new Date(`${String(v).slice(0, 10)}T00:00:00`);
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
};

export const monthLabel = (ym) => {
  const [y, m] = ym.split('-');
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString('en-GB', { month: 'short', year: '2-digit' });
};

export const humanize = (s) => String(s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

export const today = () => new Date().toISOString().slice(0, 10);

export const daysLabel = (d) => {
  if (d === null || d === undefined) return '';
  const n = Number(d);
  if (n === 0) return 'today';
  if (n === 1) return 'tomorrow';
  if (n === -1) return 'yesterday';
  return n > 0 ? `in ${n} days` : `${Math.abs(n)} days ago`;
};
