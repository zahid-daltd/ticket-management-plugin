import React, { useEffect, useState } from 'react';

const productLabel = (p) => p.name + (p.sku ? ` (${p.sku})` : '');

/**
 * Public ticket form: a single free-text address field, plus a live
 * WooCommerce product search. POSTs to wpsd/v1/tickets. Client validation
 * is UX only; the REST layer is authoritative.
 */
export function TicketForm({ config }) {
  const base = (config && config.restUrl) || '/wp-json/wpsd/v1/';
  const nonce = (config && config.nonce) || '';
  const [productSearch, setProductSearch] = useState('');
  const [products, setProducts] = useState([]);
  const [productOpen, setProductOpen] = useState(false);
  const [productActive, setProductActive] = useState(0);
  const [form, setForm] = useState({
    customer_name: '', mobile: '', alternative_mobile: '', address: '', product_id: '',
    barcode: '', problem_description: '', comments: '',
  });
  const [errors, setErrors] = useState({});
  const [done, setDone] = useState(null);
  const [sending, setSending] = useState(false);

  const get = async (p) => {
    const sep = p.includes('?') ? '&' : '?';
    const r = await fetch(`${base}${p}${sep}wpsd_nonce=${encodeURIComponent(nonce)}`);
    const j = await r.json();
    return j && j.data ? j.data : j;
  };

  // Debounced product search against the live WooCommerce catalog.
  useEffect(() => {
    const t = setTimeout(() => {
      get(`lookups/products?search=${encodeURIComponent(productSearch)}`)
        .then((d) => { setProducts(d.items || []); setProductActive(0); })
        .catch(() => null);
    }, 300);
    return () => clearTimeout(t);
  }, [productSearch]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  function selectProduct(p) {
    setProductSearch(productLabel(p));
    setProductOpen(false);
    setForm((f) => ({ ...f, product_id: String(p.id) }));
  }

  function onProductKeyDown(e) {
    if (!productOpen || products.length === 0) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); setProductActive((i) => Math.min(i + 1, products.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setProductActive((i) => Math.max(i - 1, 0)); }
    else if (e.key === 'Enter') { e.preventDefault(); selectProduct(products[productActive]); }
    else if (e.key === 'Escape') { setProductOpen(false); }
  }

  async function submit(e) {
    e.preventDefault();
    setSending(true);
    setErrors({});
    try {
      const res = await fetch(`${base}tickets`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...form, wpsd_nonce: nonce }),
      });
      const json = await res.json();
      if (!res.ok || json.success === false) {
        const fields = json && json.data && json.data.fields ? json.data.fields : {};
        setErrors(fields);
        if (!Object.keys(fields).length) setErrors({ general: (json.error && json.error.message) || 'Submission failed.' });
      } else {
        setDone(json.data.ticket);
      }
    } catch (err) {
      setErrors({ general: 'Network error. Please try again.' });
    } finally {
      setSending(false);
    }
  }

  if (done) {
    return (
      <div className="wpsd-card wpsd-success" role="status">
        <div className="wpsd-check">
          <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5" /></svg>
        </div>
        <h3>Request received</h3>
        <p>Your ticket number is</p>
        <div className="wpsd-ticket-no">{done.ticket_number}</div>
        <p className="wpsd-card-desc">Save it to check the status later.</p>
      </div>
    );
  }

  const field = (name, label, node) => (
    <label className="wpsd-field">
      <span>{label}</span>
      {node}
      {errors[name] && <em className="wpsd-error">{errors[name]}</em>}
    </label>
  );

  return (
    <form className="wpsd-form" onSubmit={submit} noValidate>
      <div className="wpsd-card">
        <div className="wpsd-card-header">
          <h3 className="wpsd-card-title">Basic information</h3>
          <p className="wpsd-card-desc">Your contact details and address.</p>
        </div>
        <div className="wpsd-card-content wpsd-form-grid">
      {errors.general && <p className="wpsd-error">{errors.general}</p>}
      {field('customer_name', 'Name *', <input className="wpsd-input" value={form.customer_name} onChange={set('customer_name')} maxLength={150} />)}
      {field('mobile', 'Mobile *', <input className="wpsd-input" value={form.mobile} onChange={set('mobile')} inputMode="tel" placeholder="01712345678" />)}
      {field('alternative_mobile', 'Alternative mobile', <input className="wpsd-input" value={form.alternative_mobile} onChange={set('alternative_mobile')} inputMode="tel" />)}
      {field('address', 'Address *', <textarea className="wpsd-input" value={form.address} onChange={set('address')} rows={3} maxLength={500} />)}
        </div>
      </div>

      <div className="wpsd-card">
        <div className="wpsd-card-header">
          <h3 className="wpsd-card-title">Service information</h3>
          <p className="wpsd-card-desc">Product, barcode, and the problem you are experiencing.</p>
        </div>
        <div className="wpsd-card-content wpsd-form-grid">
      {field('product_id', 'Product *', (
        <div className="wpsd-combobox">
          <input
            className="wpsd-input"
            role="combobox"
            aria-expanded={productOpen}
            value={productSearch}
            onChange={(e) => { setProductSearch(e.target.value); setProductOpen(true); if (form.product_id) setForm((f) => ({ ...f, product_id: '' })); }}
            onFocus={() => setProductOpen(true)}
            onBlur={() => setProductOpen(false)}
            onKeyDown={onProductKeyDown}
            placeholder="Search and select a product…"
            autoComplete="off"
          />
          {productOpen && (
            <div className="wpsd-combobox-panel">
              {products.length === 0 ? (
                <div className="wpsd-combobox-empty">{productSearch ? 'No matching products.' : 'Type to search…'}</div>
              ) : products.map((p, i) => (
                <div
                  key={p.id}
                  className={`wpsd-combobox-option${i === productActive ? ' is-active' : ''}${String(p.id) === form.product_id ? ' is-selected' : ''}`}
                  onMouseDown={(e) => { e.preventDefault(); selectProduct(p); }}
                  onMouseEnter={() => setProductActive(i)}
                >
                  {productLabel(p)}
                </div>
              ))}
            </div>
          )}
        </div>
      ))}
      {field('barcode', 'Barcode (optional)', <input className="wpsd-input" value={form.barcode} onChange={set('barcode')} maxLength={120} />)}
      {field('problem_description', 'Describe your problem *', <input className="wpsd-input" value={form.problem_description} onChange={set('problem_description')} maxLength={500} placeholder="e.g. Screen won't turn on" />)}
      {field('comments', 'Comments (optional)', <textarea className="wpsd-input" value={form.comments} onChange={set('comments')} rows={4} maxLength={2000} />)}
        </div>
      </div>

      <button className="wpsd-btn wpsd-btn-primary wpsd-btn-block" type="submit" disabled={sending}>
        {sending ? 'Submitting…' : 'Submit request'}
      </button>
    </form>
  );
}
