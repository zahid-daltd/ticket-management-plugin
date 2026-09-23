import React, { useEffect, useState } from 'react';

/**
 * Public ticket form: cascading District -> Thana -> Route -> Service Center,
 * dynamic Brand -> Product, category-aware problem list. POSTs to wpsd/v1/tickets.
 * Client validation is UX only; the REST layer is authoritative.
 */
export function TicketForm({ config }) {
  const base = (config && config.restUrl) || '/wp-json/wpsd/v1/';
  const nonce = (config && config.nonce) || '';
  const [districts, setDistricts] = useState([]);
  const [thanas, setThanas] = useState([]);
  const [routes, setRoutes] = useState([]);
  const [centers, setCenters] = useState([]);
  const [brands, setBrands] = useState([]);
  const [products, setProducts] = useState([]);
  const [problems, setProblems] = useState([]);
  const [form, setForm] = useState({
    customer_name: '', mobile: '', alternative_mobile: '', district_id: '', thana_id: '',
    route_id: '', service_center_id: '', address: '', brand: '', product_id: '',
    barcode: '', problem_type_id: '', comments: '',
  });
  const [errors, setErrors] = useState({});
  const [done, setDone] = useState(null);
  const [sending, setSending] = useState(false);

  const get = async (p) => {
    const r = await fetch(base + p);
    const j = await r.json();
    return j && j.data ? j.data : j;
  };

  useEffect(() => {
    get('lookups/districts').then((d) => setDistricts(d.items || [])).catch(() => null);
    get('lookups/products').then((d) => { setProducts(d.items || []); setBrands(d.brands || []); }).catch(() => null);
  }, []);

  useEffect(() => {
    setThanas([]); setForm((f) => ({ ...f, thana_id: '', route_id: '', service_center_id: '' }));
    if (form.district_id) get(`lookups/thanas?district_id=${form.district_id}`).then((d) => setThanas(d.items || [])).catch(() => null);
  }, [form.district_id]);

  useEffect(() => {
    setRoutes([]); setForm((f) => ({ ...f, route_id: '', service_center_id: '' }));
    if (form.thana_id) get(`lookups/routes?thana_id=${form.thana_id}`).then((d) => setRoutes(d.items || [])).catch(() => null);
  }, [form.thana_id]);

  useEffect(() => {
    setCenters([]);
    if (form.route_id) get(`lookups/service-centers?route_id=${form.route_id}`).then((d) => {
      const items = d.items || [];
      setCenters(items);
      // Auto-select when exactly one center serves the route (Q1 decision).
      if (items.length === 1) setForm((f) => ({ ...f, service_center_id: String(items[0].id) }));
      else setForm((f) => ({ ...f, service_center_id: '' }));
    }).catch(() => null);
  }, [form.route_id]);

  const visibleProducts = form.brand ? products.filter((p) => p.brand === form.brand) : products;

  useEffect(() => {
    setProblems([]);
    if (form.product_id) get(`lookups/problem-types?product_id=${form.product_id}`).then((d) => setProblems(d.items || [])).catch(() => null);
  }, [form.product_id]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

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
      <div className="wpsd-card" role="status">
        <h3>Request received</h3>
        <p>Your ticket number is <strong>{done.ticket_number}</strong>. Save it to check the status later.</p>
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
      <h3>Basic information</h3>
      {errors.general && <p className="wpsd-error">{errors.general}</p>}
      {field('customer_name', 'Name *', <input className="wpsd-input" value={form.customer_name} onChange={set('customer_name')} maxLength={150} />)}
      {field('mobile', 'Mobile *', <input className="wpsd-input" value={form.mobile} onChange={set('mobile')} inputMode="tel" placeholder="01712345678" />)}
      {field('alternative_mobile', 'Alternative mobile', <input className="wpsd-input" value={form.alternative_mobile} onChange={set('alternative_mobile')} inputMode="tel" />)}
      {field('district_id', 'District *', (
        <select className="wpsd-input" value={form.district_id} onChange={set('district_id')}>
          <option value="">Select district</option>
          {districts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
      ))}
      {field('thana_id', 'Thana *', (
        <select className="wpsd-input" value={form.thana_id} onChange={set('thana_id')} disabled={!form.district_id}>
          <option value="">Select thana</option>
          {thanas.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
      ))}
      {field('route_id', 'Route *', (
        <select className="wpsd-input" value={form.route_id} onChange={set('route_id')} disabled={!form.thana_id}>
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
      {field('brand', 'Brand *', (
        <select className="wpsd-input" value={form.brand} onChange={(e) => setForm((f) => ({ ...f, brand: e.target.value, product_id: '' }))}>
          <option value="">Select brand</option>
          {brands.map((b) => <option key={b} value={b}>{b}</option>)}
        </select>
      ))}
      {field('product_id', 'Product *', (
        <select className="wpsd-input" value={form.product_id} onChange={set('product_id')} disabled={!form.brand}>
          <option value="">Select product</option>
          {visibleProducts.map((p) => <option key={p.id} value={p.id}>{p.brand} — {p.model_name}</option>)}
        </select>
      ))}
      {field('barcode', 'Barcode (optional)', <input className="wpsd-input" value={form.barcode} onChange={set('barcode')} maxLength={120} />)}
      {field('problem_type_id', 'Select your problem *', (
        <select className="wpsd-input" value={form.problem_type_id} onChange={set('problem_type_id')} disabled={!form.product_id}>
          <option value="">Select problem</option>
          {problems.map((p) => <option key={p.id} value={p.id}>{p.label}</option>)}
        </select>
      ))}
      {field('comments', 'Comments (optional)', <textarea className="wpsd-input" value={form.comments} onChange={set('comments')} rows={4} maxLength={2000} />)}

      <button className="wpsd-btn wpsd-btn-primary" type="submit" disabled={sending}>
        {sending ? 'Submitting…' : 'Submit request'}
      </button>
    </form>
  );
}
