import React, { useState } from 'react';

const EMPTY = {
  customer_name: '', mobile: '', alternative_mobile: '', address: '',
  warranty_id: '', problem_description: '', comments: '', priority: 'med',
};

/**
 * Staff ticket form — used for creating tickets and editing them.
 * Address and problem are free-text fields. Server validation is
 * authoritative; per-field errors render inline.
 */
export function TicketForm({ client, initial, onSaved, onCancel, submitLabel }) {
  const [form, setForm] = useState({ ...EMPTY, ...(initial || {}) });
  const [errors, setErrors] = useState({});
  const [sending, setSending] = useState(false);

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
          <p className="wpsd-card-desc">Warranty/barcode and problem details.</p>
        </div>
        <div className="wpsd-card-content wpsd-form-grid">
      {field('warranty_id', 'Barcode/Warranty ID', <input className="wpsd-input" value={form.warranty_id || ''} onChange={set('warranty_id')} maxLength={120} />)}
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
