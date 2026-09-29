import React, { useEffect, useState } from 'react';
import { StatusBadge, PriorityBadge, Skeleton } from '../lib/badges.jsx';

function DetailItem({ label, value, wide }) {
  if (!value) return null;
  return (
    <div className={`wpsd-detail-item${wide ? ' wpsd-span-2' : ''}`}>
      <span className="wpsd-detail-label">{label}</span>
      <span>{value}</span>
    </div>
  );
}

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
  if (!ticket) return <Skeleton />;

  return (
    <div className="wpsd-flex wpsd-flex-col wpsd-gap-4">
      <div className="wpsd-flex wpsd-gap-2">
        <button className="wpsd-btn" onClick={onBack}>← Back to list</button>
        <button className="wpsd-btn" onClick={onEdit}>Edit ticket</button>
      </div>
      <div className="wpsd-card">
        <div className="wpsd-card-header">
          <div className="wpsd-toolbar">
            <h3 className="wpsd-card-title">{ticket.ticket_number}</h3>
            <div className="wpsd-flex wpsd-gap-2">
              <StatusBadge value={ticket.status} />
              <PriorityBadge value={ticket.priority} />
              <span className="wpsd-badge wpsd-b-gray">{ticket.source}</span>
            </div>
          </div>
          <p className="wpsd-card-desc">
            Created {ticket.created_at}
            {ticket.updated_at && ticket.updated_at !== ticket.created_at ? ` · updated ${ticket.updated_at}` : ''}
          </p>
        </div>
        <div className="wpsd-detail-grid">
          <DetailItem label="Customer" value={ticket.customer_name} />
          <DetailItem label="Mobile" value={ticket.alternative_mobile ? `${ticket.mobile} (alt: ${ticket.alternative_mobile})` : ticket.mobile} />
          <DetailItem label="Address" value={ticket.address} wide />
          <DetailItem label="Problem" value={ticket.problem_description} />
          <DetailItem label="Barcode/Warranty ID" value={ticket.warranty_id} />
          <DetailItem label="Comments" value={ticket.comments} wide />
        </div>
      </div>

      <div className="wpsd-card">
        <div className="wpsd-card-header">
          <h3 className="wpsd-card-title">Replies</h3>
          <p className="wpsd-card-desc">Conversation thread. Internal notes are hidden from customers.</p>
        </div>
        <ul className="wpsd-replies">
          {(replies || []).map((r) => (
            <li key={r.id}><strong>{r.author_type}{r.is_internal_note ? ' (internal)' : ''}:</strong> {r.message}</li>
          ))}
        </ul>
        {attachments.length > 0 && (
          <div>
            <h4>Attachments</h4>
            <ul>
              {attachments.map((a) => <li key={a.id}><a href={a.file_url} target="_blank" rel="noreferrer">{a.original_filename}</a></li>)}
            </ul>
          </div>
        )}
        {config && config.canManage && (
          <div className="wpsd-flex wpsd-flex-col wpsd-gap-2">
            <textarea className="wpsd-input" value={message} onChange={(e) => setMessage(e.target.value)} rows={3} placeholder="Write a reply…" />
            <label className="wpsd-flex wpsd-gap-2">
              <input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} />
              <span className="wpsd-muted">Internal note</span>
            </label>
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
        <div className="wpsd-card">
          <div className="wpsd-card-header">
            <h3 className="wpsd-card-title">Quick update</h3>
            <p className="wpsd-card-desc">Change status, priority, or assignment without opening the full editor.</p>
          </div>
          <div className="wpsd-card-content wpsd-form-grid">
            <label className="wpsd-field">
              <span>Status</span>
              <select className="wpsd-input" value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">—</option>
                {['new', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled'].map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
            </label>
            <label className="wpsd-field">
              <span>Priority</span>
              <select className="wpsd-input" value={priority} onChange={(e) => setPriority(e.target.value)}>
                <option value="">—</option>
                {['low', 'med', 'high'].map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
            </label>
            {config.canAssign && (
              <label className="wpsd-field">
                <span>Assign agent (user id)</span>
                <input className="wpsd-input" value={agent} onChange={(e) => setAgent(e.target.value)} inputMode="numeric" />
              </label>
            )}
          </div>
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
