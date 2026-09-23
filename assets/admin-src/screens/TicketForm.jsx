import React, { useEffect, useState } from 'react';

const EMPTY = {
  customer_name: '', mobile: '', alternative_mobile: '', district_id: '', thana_id: '',
  route_id: '', service_center_id: '', address: '', brand: '', product_id: '',
  barcode: '', problem_type_id: '', comments: '',
};

/**
 * Staff ticket form — used for creating tickets and editing them.
 * Cascading District -> Thana -> Route -> Service Center, dynamic
 * Brand -> Product, category-aware problems. Server validation is
 * authoritative; per-field errors render inline.
 */
export function TicketForm({ client, initial, onSaved, onCancel, submitLabel }) {
  const [districts, setDistricts] = useState([]);
  const [thanas, setThanas] = useState([]);
  const [routes, setRoutes] = useState([]);
  const [centers, setCenters] = useState([]);
  const [brands, setBrands] = useState([]);
  const [products, setProducts] = useState([]);
  const [problems, setProblems] = useState([]);
  const [form, setForm] = useState({ ...EMPTY, ...(initial || {}) });
  const [errors, setErrors] = useState({});
  const [sending, setSending] = useState(false);

  useEffect(() => {
    client.get('lookups/districts').then((d) => setDistricts(d.items || [])).catch(() => null);
    client.get('lookups/products').then((d) => { setProducts(d.items || []); setBrands(d.brands || []); }).catch(() => null);
  }, []);

  // When editing, hydrate the cascade for the saved ids.
  useEffect(() => {
    (async () => {
      if (!initial) return;
      try {
        if (initial.district_id) {
          const t = await client.get(`lookups/thanas?district_id=${initial.district_id}`);
          setThanas(t.items || []);
        }
        if (initial.thana_id) {
          const r = await client.get(`lookups/routes?thana_id=${initial.thana_id}`);
          setRoutes(r.items || []);
        }
        if (initial.route_id) {
          const c = await client.get(`lookups/service-centers?route_id=${initial.route_id}`);
          setCenters(c.items || []);
        }
        if (initial.product_id) {
          const p = await client.get(`lookups/problem-types?product_id=${initial.product_id}`);
          setProblems(p.items || []);
        }
      } catch (e) { void e; }
    })();
  }, []);

  async function onDistrict(v) {
    setForm((f) => ({ ...f, district_id: v, thana_id: '', route_id: '', service_center_id: '' }));
    setThanas([]); setRoutes([]); setCenters([]);
    if (v) {
      try { const d = await client.get(`lookups/thanas?district_id=${v}`); setThanas(d.items || []); } catch (e) { void e; }
    }
  }
  async function onThana(v) {
    setForm((f) => ({ ...f, thana_id: v, route_id: '', service_center_id: '' }));
    setRoutes([]); setCenters([]);
    if (v) {
      try { const d = await client.get(`lookups/routes?thana_id=${v}`); setRoutes(d.items || []); } catch (e) { void e; }
    }
  }
  async function onRoute(v) {
    setForm((f) => ({ ...f, route_id: v, service_center_id: '' }));
    setCenters([]);
    if (v) {
      try {
        const d = await client.get(`lookups/service-centers?route_id=${v}`);
        const items = d.items || [];
        setCenters(items);
        if (items.length === 1) setForm((f) => ({ ...f, service_center_id: String(items[0].id) }));
      } catch (e) { void e; }
    }
  }
  async function onProduct(v) {
    setForm((f) => ({ ...f, product_id: v, problem_type_id: '' }));
    setProblems([]);
    if (v) {
      try { const d = await client.get(`lookups/problem-types?product_id=${v}`); setProblems(d.items || []); } catch (e) { void e; }
    }
  }

  const visibleProducts = form.brand ? products.filter((p) => String(p.brand) === String(form.brand)) : products;
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

  const field = (name, label, node) => (
    <label className="wpsd-field" key={name}>
      <span>{label}</span>
      {node}
      {errors[name] && <em className="wpsd-error">{errors[name]}</em>}
    </label>
  );

  return (
    <form className="wpsd-form" onSubmit={submit} noValidate>
      <h3>Basic information</h3>
      {errors.general && <p className="wpsd-error">{errors.general}</p>}
      {errors.location && <p className="wpsd-error">{errors.location}</p>}
      {field('customer_name', 'Name *', <input className="wpsd-input" value={form.customer_name} onChange={set('customer_name')} maxLength={150} />)}
      {field('mobile', 'Mobile *', <input className="wpsd-input" value={form.mobile} onChange={set('mobile')} inputMode="tel" placeholder="01712345678" />)}
      {field('alternative_mobile', 'Alternative mobile', <input className="wpsd-input" value={form.alternative_mobile || ''} onChange={set('alternative_mobile')} inputMode="tel" />)}
      {field('district_id', 'District *', (
        <select className="wpsd-input" value={form.district_id} onChange={(e) => onDistrict(e.target.value)}>
          <option value="">Select district</option>
          {districts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
      ))}
      {field('thana_id', 'Thana *', (
        <select className="wpsd-input" value={form.thana_id} onChange={(e) => onThana(e.target.value)} disabled={!form.district_id}>
          <option value="">Select thana</option>
          {thanas.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
      ))}
      {field('route_id', 'Route *', (
        <select className="wpsd-input" value={form.route_id} onChange={(e) => onRoute(e.target.value)} disabled={!form.thana_id}>
          <option value="">Select route</option>
          {routes.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
      ))}
      {field('service_center_id', 'Service center *', (
        <select className="wpsd-input" value={form.service_center_id} onChange={set('service_center_id')} disabled={!form.route_id}>
          <option value="">Select service center</option>
          {centers.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
      ))}
      {field('address', 'Address (House/Road) * — max 50 chars', <input className="wpsd-input" value={form.address} onChange={set('address')} maxLength={50} />)}

      <h3>Incident information</h3>
      {errors.product && <p className="wpsd-error">{errors.product}</p>}
      {field('brand', 'Brand *', (
        <select className="wpsd-input" value={form.brand || ''} onChange={(e) => setForm((f) => ({ ...f, brand: e.target.value, product_id: '', problem_type_id: '' }))}>
          <option value="">Select brand</option>
          {brands.map((b) => <option key={b} value={b}>{b}</option>)}
        </select>
      ))}
      {field('product_id', 'Product *', (
        <select className="wpsd-input" value={form.product_id} onChange={(e) => onProduct(e.target.value)} disabled={!form.brand}>
          <option value="">Select product</option>
          {visibleProducts.map((p) => <option key={p.id} value={p.id}>{p.brand} — {p.model_name}</option>)}
        </select>
      ))}
      {field('barcode', 'Barcode (optional)', <input className="wpsd-input" value={form.barcode || ''} onChange={set('barcode')} maxLength={120} />)}
      {field('problem_type_id', 'Problem *', (
        <select className="wpsd-input" value={form.problem_type_id} onChange={set('problem_type_id')} disabled={!form.product_id}>
          <option value="">Select problem</option>
          {problems.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
        </select>
      ))}
      {field('comments', 'Comments (optional)', <textarea className="wpsd-input" value={form.comments || ''} onChange={set('comments')} rows={4} maxLength={2000} />)}

      <div className="wpsd-flex wpsd-gap-2">
        <button className="wpsd-btn wpsd-btn-primary" type="submit" disabled={sending}>
          {sending ? 'Saving…' : (submitLabel || 'Save ticket')}
        </button>
        <button className="wpsd-btn" type="button" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}
