import React, { useEffect, useState } from 'react';
import { StatusBadge, PriorityBadge, Skeleton } from '../lib/badges.jsx';

const PER_PAGE = 20;

export function TicketList({ client, onOpen, onNew }) {
  const [items, setItems] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  async function load(p = page) {
    setError('');
    setLoading(true);
    try {
      const q = new URLSearchParams({ page: String(p), per_page: String(PER_PAGE) });
      if (status) q.set('status', status);
      if (search) q.set('search', search);
      const data = await client.get(`tickets?${q.toString()}`);
      setItems(data.items || []);
      setTotal(data.total || 0);
      setPage(data.page || p);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(1); }, [status]);

  const lastPage = Math.max(1, Math.ceil(total / PER_PAGE));

  return (
    <div className="wpsd-flex wpsd-flex-col wpsd-gap-4">
      <div className="wpsd-toolbar">
        <form className="wpsd-toolbar-filters" onSubmit={(e) => { e.preventDefault(); load(1); }}>
          <select className="wpsd-input wpsd-input-auto" value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Filter by status">
            <option value="">All statuses</option>
            {['new', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled'].map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>
          <input className="wpsd-input wpsd-input-search" placeholder="Search number / name / mobile" value={search} onChange={(e) => setSearch(e.target.value)} />
          <button className="wpsd-btn" type="submit">Search</button>
        </form>
        <button className="wpsd-btn wpsd-btn-primary" onClick={onNew}>+ New ticket</button>
      </div>
      {error && <p className="wpsd-error">{error}</p>}
      {loading ? <Skeleton /> : (
        <>
          <table className="wpsd-table">
            <thead><tr><th>Ticket</th><th>Customer</th><th>Status</th><th>Priority</th><th>Created</th></tr></thead>
            <tbody>
              {items.length === 0 && <tr><td colSpan={5} className="wpsd-empty-cell">No tickets match these filters.</td></tr>}
              {items.map((t) => (
                <tr key={t.id}>
                  <td><button className="wpsd-link" onClick={() => onOpen(t.id)}>{t.ticket_number}</button></td>
                  <td><div style={{ fontWeight: 500 }}>{t.customer_name}</div><div className="wpsd-muted" style={{ fontSize: 13 }}>{t.mobile}</div></td>
                  <td><StatusBadge value={t.status} /></td>
                  <td><PriorityBadge value={t.priority} /></td>
                  <td className="wpsd-muted">{t.created_at}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="wpsd-pagination">
            <p className="wpsd-muted">{total} ticket{total === 1 ? '' : 's'} · page {page} of {lastPage}</p>
            <div className="wpsd-flex wpsd-gap-2">
              <button className="wpsd-btn wpsd-btn-sm" disabled={page <= 1} onClick={() => load(page - 1)}>Prev</button>
              <button className="wpsd-btn wpsd-btn-sm" disabled={page >= lastPage} onClick={() => load(page + 1)}>Next</button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
