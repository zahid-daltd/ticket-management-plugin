import React, { useEffect, useState } from 'react';

export function TicketList({ client, onOpen, onNew }) {
  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [error, setError] = useState('');

  async function load(p = page) {
    setError('');
    try {
      const q = new URLSearchParams({ page: String(p), per_page: '20' });
      if (status) q.set('status', status);
      if (search) q.set('search', search);
      const data = await client.get(`tickets?${q.toString()}`);
      setItems(data.items || []);
      setTotal(data.total || 0);
      setPage(data.page || p);
    } catch (e) {
      setError(e.message);
    }
  }

  useEffect(() => { load(1); }, [status]);

  return (
    <div className="wpsd-flex wpsd-flex-col wpsd-gap-4">
      <div className="wpsd-flex wpsd-gap-2">
        <select className="wpsd-input" value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Filter by status">
          <option value="">All statuses</option>
          {['new', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled'].map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </select>
        <input className="wpsd-input" placeholder="Search number / name / mobile" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button className="wpsd-btn wpsd-btn-primary" onClick={() => load(1)}>Search</button>
        <button className="wpsd-btn wpsd-btn-primary" onClick={onNew}>+ New ticket</button>
      </div>
      {error && <p className="wpsd-error">{error}</p>}
      <table className="wpsd-table">
        <thead><tr><th>Ticket</th><th>Customer</th><th>Status</th><th>Priority</th><th>Created</th></tr></thead>
        <tbody>
          {items.map((t) => (
            <tr key={t.id}>
              <td><button className="wpsd-link" onClick={() => onOpen(t.id)}>{t.ticket_number}</button></td>
              <td>{t.customer_name} ({t.mobile})</td>
              <td>{t.status}</td>
              <td>{t.priority}</td>
              <td>{t.created_at}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p>{total} tickets · page {page}</p>
      <div className="wpsd-flex wpsd-gap-2">
        <button className="wpsd-btn" disabled={page <= 1} onClick={() => load(page - 1)}>Prev</button>
        <button className="wpsd-btn" onClick={() => load(page + 1)}>Next</button>
      </div>
    </div>
  );
}
