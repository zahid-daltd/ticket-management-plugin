import React, { useEffect, useState } from 'react';

const EMPTY = {
  customer_name: '', mobile: '', alternative_mobile: '', address: '', product_id: '',
  barcode: '', problem_description: '', comments: '', priority: 'med',
};

const productLabel = (p) => p.name + (p.sku ? ` (${p.sku})` : '');

/**
 * Staff ticket form — used for creating tickets and editing them.
 * Address is a single free-text field. Product is a live search against
 * the WooCommerce catalog. Server validation is authoritative; per-field
 * errors render inline.
 */
export function TicketForm({ client, initial, onSaved, onCancel, submitLabel }) {
  const [productSearch, setProductSearch] = useState((initial && initial.product_name_snapshot) || '');
  const [products, setProducts] = useState([]);
  const [productOpen, setProductOpen] = useState(false);
  const [productActive, setProductActive] = useState(0);
  const [form, setForm] = useState({ ...EMPTY, ...(initial || {}) });
  const [errors, setErrors] = useState({});
  const [sending, setSending] = useState(false);

  // Debounced product search against the live WooCommerce catalog.
  useEffect(() => {
    const t = setTimeout(() => {
      client.get(`lookups/products?search=${encodeURIComponent(productSearch)}`)
        .then((d) => { setProducts(d.items || []); setProductActive(0); })
        .catch(() => null);
    }, 300);
    return () => clearTimeout(t);
  }, [productSearch]);

  // When editing, seed the picker with the saved product so it shows
  // selected even before any search term narrows the live catalog list.
  useEffect(() => {
    if (initial && initial.product_id) {
      setProducts((prev) => [
        { id: initial.product_id, name: initial.product_name_snapshot || 'Current product', sku: '' },
        ...prev,
      ]);
    }
  }, []);

  function onProduct(v) {
    setForm((f) => ({ ...f, product_id: v }));
  }

  function selectProduct(p) {
    setProductSearch(productLabel(p));
    setProductOpen(false);
    onProduct(String(p.id));
  }

  function onProductKeyDown(e) {
    if (!productOpen || products.length === 0) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); setProductActive((i) => Math.min(i + 1, products.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setProductActive((i) => Math.max(i - 1, 0)); }
    else if (e.key === 'Enter') { e.preventDefault(); selectProduct(products[productActive]); }
    else if (e.key === 'Escape') { setProductOpen(false); }
  }

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  async function submit(e) {
    e.preventDefault();
    setSending(true);
    setErrors({});
    try {
      const saved = await onSaved(form);
      void saved;
    } catch (err) {
      setErrors((err.details && err.details.fields) || { general: err.message });
    } finally {
      setSending(false);
    }
  }

  const field = (name, label, node, help) => (
    <label className="wpsd-field" key={name}>
      <span>{label}</span>
      {node}
      {help && !errors[name] && <span className="wpsd-field-help">{help}</span>}
      {errors[name] && <em className="wpsd-error">{errors[name]}</em>}
    </label>
  );

  return (
    <form className="wpsd-form" onSubmit={submit} noValidate>
      <div className="wpsd-card">
        <div className="wpsd-card-header">
          <h3 className="wpsd-card-title">Basic information</h3>
          <p className="wpsd-card-desc">Customer contact and address.</p>
        </div>
        <div className="wpsd-card-content wpsd-form-grid">
      {errors.general && <p className="wpsd-error">{errors.general}</p>}
      {field('customer_name', 'Name *', <input className="wpsd-input" value={form.customer_name} onChange={set('customer_name')} maxLength={150} />)}
      {field('mobile', 'Mobile *', <input className="wpsd-input" value={form.mobile} onChange={set('mobile')} inputMode="tel" placeholder="01712345678" />)}
      {field('alternative_mobile', 'Alternative mobile', <input className="wpsd-input" value={form.alternative_mobile || ''} onChange={set('alternative_mobile')} inputMode="tel" />)}
      {field('address', 'Address *', <textarea className="wpsd-input" value={form.address} onChange={set('address')} rows={3} maxLength={500} />, `${form.address.length}/500 characters`)}
        </div>
      </div>

      <div className="wpsd-card">
        <div className="wpsd-card-header">
          <h3 className="wpsd-card-title">Service information</h3>
          <p className="wpsd-card-desc">Product, barcode, and problem details.</p>
        </div>
        <div className="wpsd-card-content wpsd-form-grid">
      {errors.product && <p className="wpsd-error">{errors.product}</p>}
      {field('product_id', 'Product *', (
        <div className="wpsd-combobox">
          <input
            className="wpsd-input"
            role="combobox"
            aria-expanded={productOpen}
            value={productSearch}
            onChange={(e) => { setProductSearch(e.target.value); setProductOpen(true); if (form.product_id) onProduct(''); }}
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
      ), 'Type to search the WooCommerce catalog, then pick a result.')}
      {field('barcode', 'Barcode (optional)', <input className="wpsd-input" value={form.barcode || ''} onChange={set('barcode')} maxLength={120} />)}
      {field('problem_description', 'Problem *', <input className="wpsd-input" value={form.problem_description || ''} onChange={set('problem_description')} maxLength={500} placeholder="Describe the problem" />)}
      {field('priority', 'Priority', (
        <select className="wpsd-input" value={form.priority || 'med'} onChange={set('priority')}>
          {['low', 'med', 'high'].map((p) => <option key={p} value={p}>{p}</option>)}
        </select>
      ))}
      {field('comments', 'Comments (optional)', <textarea className="wpsd-input" value={form.comments || ''} onChange={set('comments')} rows={4} maxLength={2000} />)}
        </div>
      </div>

      <div className="wpsd-flex wpsd-gap-2">
        <button className="wpsd-btn wpsd-btn-primary" type="submit" disabled={sending}>
          {sending ? 'Saving…' : (submitLabel || 'Save ticket')}
        </button>
        <button className="wpsd-btn" type="button" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}
