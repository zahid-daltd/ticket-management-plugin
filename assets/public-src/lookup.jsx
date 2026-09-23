import React, { useState } from 'react';

/** Guest status lookup: ticket number + mobile (ownership-checked server-side). */
export function Lookup() {
  const base = (window.WPSD_PUBLIC_CONFIG && window.WPSD_PUBLIC_CONFIG.restUrl) || '/wp-json/wpsd/v1/';
  const [number, setNumber] = useState('');
  const [mobile, setMobile] = useState('');
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  async function check(e) {
    e.preventDefault();
    setError('');
    setResult(null);
    try {
      const r = await fetch(`${base}tickets/${encodeURIComponent(number)}?mobile=${encodeURIComponent(mobile)}`);
      const j = await r.json();
      if (!r.ok || j.success === false) throw new Error((j.error && j.error.message) || 'Lookup failed.');
      setResult(j.data.ticket);
    } catch (err) {
      setError(err.message);
    }
  }

  return (
    <div className="wpsd-card">
      <h3>Check ticket status</h3>
      <form className="wpsd-form" onSubmit={check}>
        <label className="wpsd-field"><span>Ticket number</span>
          <input className="wpsd-input" value={number} onChange={(e) => setNumber(e.target.value)} placeholder="TKT-2026-00123" />
        </label>
        <label className="wpsd-field"><span>Mobile used on the ticket</span>
          <input className="wpsd-input" value={mobile} onChange={(e) => setMobile(e.target.value)} inputMode="tel" />
        </label>
        <button className="wpsd-btn wpsd-btn-primary" type="submit">Check status</button>
      </form>
      {error && <p className="wpsd-error">{error}</p>}
      {result && (
        <div role="status">
          <p><strong>{result.ticket_number}</strong> — {result.status}</p>
          <p>{result.problem_label || ''}</p>
        </div>
      )}
    </div>
  );
}
