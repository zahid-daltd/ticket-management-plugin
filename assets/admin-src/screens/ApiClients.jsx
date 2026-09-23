import React, { useEffect, useState } from 'react';

export function ApiClients({ client }) {
  const [items, setItems] = useState([]);
  const [name, setName] = useState('');
  const [scope, setScope] = useState('create_only');
  const [once, setOnce] = useState(null);
  const [error, setError] = useState('');

  async function load() {
    try {
      const data = await client.get('admin/api-clients');
      setItems(data.items || []);
    } catch (e) { setError(e.message); }
  }

  useEffect(() => { load(); }, []);

  return (
    <div className="wpsd-flex wpsd-flex-col wpsd-gap-4">
      <div className="wpsd-card wpsd-flex wpsd-gap-2">
        <input className="wpsd-input" placeholder="Client name (e.g. Call-center app)" value={name} onChange={(e) => setName(e.target.value)} />
        <select className="wpsd-input" value={scope} onChange={(e) => setScope(e.target.value)}>
          <option value="create_only">create_only</option>
          <option value="create_and_read">create_and_read</option>
          <option value="full">full</option>
        </select>
        <button
          className="wpsd-btn wpsd-btn-primary"
          onClick={async () => {
            try {
              const data = await client.post('admin/api-clients', { client_name: name, scope });
              setOnce(data);
              setName('');
              load();
            } catch (e) { setError(e.message); }
          }}
        >Generate key</button>
      </div>
      {once && (
        <div className="wpsd-card">
          <p><strong>Copy the secret now — it is shown once.</strong></p>
          <p>Key: <code>{once.api_key}</code></p>
          <p>Secret: <code>{once.api_secret}</code></p>
        </div>
      )}
      {error && <p className="wpsd-error">{error}</p>}
      <table className="wpsd-table">
        <thead><tr><th>Client</th><th>Scope</th><th>Rate/min</th><th>Last used</th><th>Actions</th></tr></thead>
        <tbody>
          {items.map((c) => (
            <tr key={c.id}>
              <td>{c.client_name}</td>
              <td>{c.scope}</td>
              <td>{c.rate_limit_per_minute}</td>
              <td>{c.last_used_at || '—'}</td>
              <td className="wpsd-flex wpsd-gap-2">
                <button className="wpsd-btn" onClick={async () => {
                  try { const d = await client.post(`admin/api-clients/${c.id}`, { action: 'rotate' }); setOnce(d); }
                  catch (e) { setError(e.message); }
                }}>Rotate</button>
                <button className="wpsd-btn" onClick={async () => {
                  if (!window.confirm('Revoke this client?')) return;
                  try { await client.del(`admin/api-clients/${c.id}`); load(); }
                  catch (e) { setError(e.message); }
                }}>Revoke</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
