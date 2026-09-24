import React, { useState } from 'react';
import { StatusBadge } from './lib/badges.jsx';

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
      <div className="wpsd-card-header">
        <h3 className="wpsd-card-title">Check ticket status</h3>
        <p className="wpsd-card-desc">Enter your ticket number and the mobile used when submitting.</p>
      </div>
      <form className="wpsd-form" onSubmit={check}>
        <label className="wpsd-field"><span>Ticket number</span>
          <input className="wpsd-input" value={number} onChange={(e) => setNumber(e.target.value)} placeholder="TKT-2026-00123" />
        </label>
        <label className="wpsd-field"><span>Mobile used on the ticket</span>
          <input className="wpsd-input" value={mobile} onChange={(e) => setMobile(e.target.value)} inputMode="tel" />
        </label>
        <button className="wpsd-btn wpsd-btn-primary wpsd-btn-block" type="submit">Check status</button>
      </form>
      {error && <p className="wpsd-alert wpsd-alert-error">{error}</p>}
      {result && (
        <div className="wpsd-card wpsd-success" role="status">
          <div className="wpsd-status-row">
            <span className="wpsd-ticket-no">{result.ticket_number}</span>
            <StatusBadge value={result.status} />
          </div>
          {result.problem_description ? <p className="wpsd-card-desc">{result.problem_description}</p> : null}
        </div>
      )}
    </div>
  );
}
