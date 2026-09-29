import React, { useState } from 'react';

/**
 * Public ticket form: address and problem are free-text fields. POSTs to
 * wpsd/v1/tickets. Client validation is UX only; the REST layer is authoritative.
 */
export function TicketForm({ config }) {
  const base = (config && config.restUrl) || '/wp-json/wpsd/v1/';
  const nonce = (config && config.nonce) || '';
  const [form, setForm] = useState({
    customer_name: '', mobile: '', alternative_mobile: '', address: '',
    warranty_id: '', problem_description: '', comments: '',
  });
  const [errors, setErrors] = useState({});
  const [done, setDone] = useState(null);
  const [sending, setSending] = useState(false);

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
          <p className="wpsd-card-desc">Warranty/barcode and the problem you are experiencing.</p>
        </div>
        <div className="wpsd-card-content wpsd-form-grid">
      {field('warranty_id', 'Barcode/Warranty ID (optional)', <input className="wpsd-input" value={form.warranty_id} onChange={set('warranty_id')} maxLength={120} />)}
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
