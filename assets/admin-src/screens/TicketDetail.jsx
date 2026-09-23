import React, { useEffect, useState } from 'react';

export function TicketDetail({ client, id, onBack, onEdit, config }) {
  const [ticket, setTicket] = useState(null);
  const [replies, setReplies] = useState([]);
  const [attachments, setAttachments] = useState([]);
  const [message, setMessage] = useState('');
  const [internal, setInternal] = useState(false);
  const [status, setStatus] = useState('');
  const [priority, setPriority] = useState('');
  const [agent, setAgent] = useState('');
  const [error, setError] = useState('');

  async function load() {
    setError('');
    try {
      const data = await client.get(`tickets/by-id/${id}`);
      setTicket(data.ticket);
      setReplies(data.replies || []);
      setAttachments(data.attachments || []);
    } catch (e) {
      setError(e.message);
    }
  }

  useEffect(() => { load(); }, [id]);
  if (error) return <div><button className="wpsd-btn" onClick={onBack}>← Back</button><p className="wpsd-error">{error}</p></div>;
  if (!ticket) return <p>Loading…</p>;

  return (
    <div className="wpsd-flex wpsd-flex-col wpsd-gap-4">
      <div className="wpsd-flex wpsd-gap-2">
        <button className="wpsd-btn" onClick={onBack}>← Back to list</button>
        <button className="wpsd-btn" onClick={onEdit}>Edit ticket</button>
      </div>
      <div className="wpsd-card">
        <h3>{ticket.ticket_number}</h3>
        <p><strong>{ticket.customer_name}</strong> · {ticket.mobile}
          {ticket.alternative_mobile ? ` · alt: ${ticket.alternative_mobile}` : ''}</p>
        <p>{ticket.district_name} / {ticket.thana_name} / {ticket.route_name} / {ticket.service_center_name}</p>
        <p>Address: {ticket.address}</p>
        <p>Product: {ticket.product_label || `${ticket.brand_snapshot} — ${ticket.product_name_snapshot}`} · Problem: {ticket.problem_label}</p>
        {ticket.barcode && <p>Barcode: {ticket.barcode}</p>}
        {ticket.comments && <p>Comments: {ticket.comments}</p>}
        <p>Status: {ticket.status} · Priority: {ticket.priority} · Source: {ticket.source}</p>
      </div>

      <div className="wpsd-card">
        <h3>Replies</h3>
        <ul>
          {(replies || []).map((r) => (
            <li key={r.id}><strong>{r.author_type}{r.is_internal_note ? ' (internal)' : ''}:</strong> {r.message}</li>
          ))}
        </ul>
        {attachments.length > 0 && (
          <div><h4>Attachments</h4><ul>
            {attachments.map((a) => <li key={a.id}><a href={a.file_url} target="_blank" rel="noreferrer">{a.original_filename}</a></li>)}
          </ul></div>
        )}
        {config && config.canManage && (
          <div className="wpsd-flex wpsd-flex-col wpsd-gap-2">
            <textarea className="wpsd-input" value={message} onChange={(e) => setMessage(e.target.value)} rows={3} />
            <label><input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} /> Internal note</label>
            <button
              className="wpsd-btn"
              onClick={async () => {
                try {
                  const r = await client.post(`tickets/${id}/replies`, { message, is_internal_note: internal });
                  setReplies([...replies, r.reply]);
                  setMessage('');
                } catch (e) { setError(e.message); }
              }}
            >Send reply</button>
          </div>
        )}
      </div>

      {config && config.canManage && (
        <div className="wpsd-card wpsd-flex wpsd-flex-col wpsd-gap-2">
          <h3>Quick update</h3>
          <label>Status
            <select className="wpsd-input" value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">—</option>
              {['new', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled'].map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </label>
          <label>Priority
            <select className="wpsd-input" value={priority} onChange={(e) => setPriority(e.target.value)}>
              <option value="">—</option>
              {['low', 'med', 'high'].map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
          </label>
          {config.canAssign && (
            <label>Assign agent (user id)
              <input className="wpsd-input" value={agent} onChange={(e) => setAgent(e.target.value)} inputMode="numeric" />
            </label>
          )}
          <button
            className="wpsd-btn wpsd-btn-primary"
            onClick={async () => {
              try {
                const body = {};
                if (status) body.status = status;
                if (priority) body.priority = priority;
                if (agent !== '') body.assigned_agent_id = Number(agent);
                const d = await client.patch(`tickets/${id}`, body);
                setTicket(d.ticket);
                setStatus(''); setPriority(''); setAgent('');
              } catch (e) { setError(e.message); }
            }}
          >Save</button>
        </div>
      )}
    </div>
  );
}
