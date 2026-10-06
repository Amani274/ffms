import { useState } from 'react';
import { api } from '../api';
import { useApp } from '../auth';
import { money, today } from '../format';
import { FkSelect, Modal, useAsync, DataTable, Spinner, useOptions, Confirm } from '../ui';
import { RESOURCES } from '../resources';

const blankSalesLine = () => ({ crop_id: '', quantity_kg: '', unit_price: '', grade: 'A', lot_id: '' });
const blankPoLine = () => ({ item_id: '', description: '', quantity: '', unit_price: '' });

/** Order / purchase-order builder with line items. kind = 'sales' | 'purchase' */
export function OrderBuilder({ kind, onClose, onSaved }) {
  const { farmId, toast } = useApp();
  const sales = kind === 'sales';
  const [head, setHead] = useState(sales ? { customer_id: '', order_date: today(), delivery_date: '', notes: '' } : { supplier_id: '', order_date: today(), expected_date: '', notes: '' });
  const [lines, setLines] = useState([sales ? blankSalesLine() : blankPoLine()]);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const items = useOptions(sales ? null : 'inventory_items');

  const setLine = (i, patch) => setLines((ls) => ls.map((l, k) => (k === i ? { ...l, ...patch } : l)));
  const total = lines.reduce((s, l) => s + (Number(sales ? l.quantity_kg : l.quantity) || 0) * (Number(l.unit_price) || 0), 0);

  const pickCrop = async (i, cropId) => {
    setLine(i, { crop_id: cropId });
    if (!cropId) return;
    try {
      const { data } = await api.get(`predictions/price/${cropId}`);
      if (data.available) setLine(i, { crop_id: cropId, unit_price: data.current_price });
    } catch { /* price optional */ }
  };

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setErrors({}); setMessage('');
    const clean = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v === '' ? null : v]));
    const payload = { ...clean(head), farm_id: farmId, items: lines.filter((l) => Object.values(l).some((v) => v !== '' && v !== 'A')).map(clean) };
    try {
      const { data } = await api.post(sales ? 'sales_orders' : 'purchase_orders', payload);
      toast(`${sales ? 'Order' : 'Purchase order'} ${sales ? data.order_no : data.po_no} created`);
      onSaved(data);
    } catch (err) {
      setMessage(err.message);
      setErrors(err.fields || {});
    } finally { setBusy(false); }
  };

  const err = (k) => errors[k] && <div className="err">{errors[k]}</div>;
  return (
    <Modal title={sales ? 'New sales order' : 'New purchase order'} size="wide" onClose={onClose} footer={<>
      <div style={{ marginRight: 'auto', fontWeight: 700 }}>Total: {money(total)}</div>
      <button className="btn" type="button" onClick={onClose}>Cancel</button>
      <button className="btn primary" form="order-form" disabled={busy}>{busy ? 'Saving…' : sales ? 'Create order' : 'Save as draft'}</button>
    </>}>
      <form id="order-form" onSubmit={submit}>
        {message && <div className="alert-box bad">{message}</div>}
        <div className="form-grid" style={{ marginBottom: 16 }}>
          <div className={`field ${errors.customer_id || errors.supplier_id ? 'has-err' : ''}`}>
            <label>{sales ? 'Customer' : 'Supplier'} <span className="req">*</span></label>
            <FkSelect resource={sales ? 'customers' : 'suppliers'} value={head[sales ? 'customer_id' : 'supplier_id']} onChange={(v) => setHead({ ...head, [sales ? 'customer_id' : 'supplier_id']: v })} />
            {err(sales ? 'customer_id' : 'supplier_id')}
          </div>
          <div className="field"><label>Order date <span className="req">*</span></label><input className="input" type="date" value={head.order_date} onChange={(e) => setHead({ ...head, order_date: e.target.value })} />{err('order_date')}</div>
          <div className="field"><label>{sales ? 'Delivery date' : 'Expected delivery'}</label><input className="input" type="date" value={head[sales ? 'delivery_date' : 'expected_date']} onChange={(e) => setHead({ ...head, [sales ? 'delivery_date' : 'expected_date']: e.target.value })} /></div>
          <div className="field"><label>Notes</label><input className="input" value={head.notes} onChange={(e) => setHead({ ...head, notes: e.target.value })} /></div>
        </div>

        <h3 style={{ marginBottom: 8 }}>Order lines</h3>
        {lines.map((l, i) => (
          <div key={i} className={`form-grid lines ${sales ? 'sales' : 'po'}`}>
            {sales ? (<>
              <div className="field"><label>Crop</label><FkSelect resource="crops" value={l.crop_id} onChange={(v) => pickCrop(i, v)} />{err(`items.${i}.crop_id`)}</div>
              <div className="field"><label>Quantity (kg)</label><input className="input" type="number" min="0" step="any" value={l.quantity_kg} onChange={(e) => setLine(i, { quantity_kg: e.target.value })} />{err(`items.${i}.quantity_kg`)}</div>
              <div className="field"><label>Price / kg</label><input className="input" type="number" min="0" step="any" value={l.unit_price} onChange={(e) => setLine(i, { unit_price: e.target.value })} />{err(`items.${i}.unit_price`)}</div>
              <div className="field"><label>Grade</label><select className="input" value={l.grade} onChange={(e) => setLine(i, { grade: e.target.value })}>{['A', 'B', 'C', 'reject'].map((g) => <option key={g}>{g}</option>)}</select></div>
              <div className="field"><label>From storage lot</label><FkSelect resource="storage_lots" value={l.lot_id} onChange={(v) => setLine(i, { lot_id: v })} params={{ available: 1 }} /></div>
            </>) : (<>
              <div className="field"><label>Inventory item</label>
                <select className="input" value={l.item_id} onChange={(e) => { const id = e.target.value ? Number(e.target.value) : ''; const label = (items || []).find((o) => o.id === id)?.label?.replace(/ \(.*\)$/, '') || l.description; setLine(i, { item_id: id, description: label }); }}>
                  <option value="">— none —</option>{(items || []).map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                </select>
              </div>
              <div className="field"><label>Description</label><input className="input" value={l.description} onChange={(e) => setLine(i, { description: e.target.value })} />{err(`items.${i}.description`)}</div>
              <div className="field"><label>Quantity</label><input className="input" type="number" min="0" step="any" value={l.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} />{err(`items.${i}.quantity`)}</div>
              <div className="field"><label>Unit price</label><input className="input" type="number" min="0" step="any" value={l.unit_price} onChange={(e) => setLine(i, { unit_price: e.target.value })} />{err(`items.${i}.unit_price`)}</div>
            </>)}
            <button type="button" className="btn ghost danger sm" onClick={() => setLines((ls) => (ls.length > 1 ? ls.filter((_, k) => k !== i) : ls))} aria-label="Remove line">✕</button>
          </div>
        ))}
        <button type="button" className="btn sm" onClick={() => setLines((ls) => [...ls, sales ? blankSalesLine() : blankPoLine()])}>+ Add line</button>
      </form>
    </Modal>
  );
}

/** Read-only list of an order's lines. */
export function LinesModal({ resource, keyName, parent, title, onClose }) {
  const cfg = RESOURCES[resource];
  const { data, loading } = useAsync(() => api.get(resource, { [keyName]: parent.id, per_page: 200 }), [parent.id]);
  return (
    <Modal title={title} onClose={onClose}>
      {loading ? <Spinner /> : <DataTable columns={cfg.columns} rows={data?.data} />}
    </Modal>
  );
}

export function PayModal({ po, onClose, onSaved }) {
  const { toast } = useApp();
  const [amount, setAmount] = useState(String(po.balance));
  const [msg, setMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const save = async (e) => {
    e.preventDefault(); setBusy(true); setMsg('');
    try { await api.post(`purchasing/${po.id}/pay`, { amount: Number(amount), payment_date: today() }); toast(`Payment of ${money(amount)} recorded`); onSaved(); }
    catch (err) { setMsg(err.fields?.amount || err.message); } finally { setBusy(false); }
  };
  return (
    <Modal title={`Pay ${po.po_no}`} size="narrow" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" form="pay-form" disabled={busy}>Record payment</button></>}>
      <form id="pay-form" onSubmit={save}>
        {msg && <div className="alert-box bad">{msg}</div>}
        <p className="muted" style={{ marginTop: 0 }}>Outstanding balance: <b>{money(po.balance)}</b> to {po.supplier_name}.</p>
        <div className="field"><label>Amount</label><input className="input" type="number" step="any" min="0" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus /></div>
      </form>
    </Modal>
  );
}

export function PayrollModal({ onClose, onSaved }) {
  const { farmId, toast } = useApp();
  const first = new Date(); first.setDate(1);
  const prevEnd = new Date(first.getTime() - 86400000);
  const prevStart = new Date(prevEnd.getFullYear(), prevEnd.getMonth(), 1);
  const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const [start, setStart] = useState(fmt(prevStart));
  const [end, setEnd] = useState(fmt(prevEnd));
  const [msg, setMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const run = async (e) => {
    e.preventDefault(); setBusy(true); setMsg('');
    try {
      const { data } = await api.post('payroll/generate', { farm_id: farmId, period_start: start, period_end: end });
      toast(data.created ? `${data.created} draft payroll record(s) generated` : 'No new records — payroll for this period already exists');
      onSaved();
    } catch (err) { setMsg(err.message); } finally { setBusy(false); }
  };
  return (
    <Modal title="Generate payroll" size="narrow" onClose={onClose} footer={<><button className="btn" onClick={onClose}>Cancel</button><button className="btn primary" form="payroll-form" disabled={busy}>{busy ? 'Generating…' : 'Generate drafts'}</button></>}>
      <form id="payroll-form" onSubmit={run}>
        {msg && <div className="alert-box bad">{msg}</div>}
        <p className="muted" style={{ marginTop: 0 }}>Creates a draft for every active worker from attendance in the period (daily/hourly) or the monthly rate. Statutory deduction is applied automatically. Review, then mark each as paid.</p>
        <div className="form-grid">
          <div className="field"><label>Period start</label><input className="input" type="date" value={start} onChange={(e) => setStart(e.target.value)} /></div>
          <div className="field"><label>Period end</label><input className="input" type="date" value={end} onChange={(e) => setEnd(e.target.value)} /></div>
        </div>
      </form>
    </Modal>
  );
}

export { Confirm };
