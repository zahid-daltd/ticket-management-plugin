import React, { useEffect, useState } from 'react';
import { Skeleton } from '../lib/badges.jsx';

// The list endpoint never returns secrets — mask the public key so a row
// still shows *something* credential-shaped after a refresh, without ever
// implying the secret itself survived.
function maskKey(key) {
  if (!key) return '—';
  return key.length <= 8 ? key : `${key.slice(0, 4)}••••${key.slice(-4)}`;
}

export function ApiClients({ client }) {
  const [items, setItems] = useState([]);
  const [name, setName] = useState('');
  const [scope, setScope] = useState('create_only');
  const [once, setOnce] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [creating, setCreating] = useState(false);

  async function load() {
    try {
      const data = await client.get('admin/api-clients');
      setItems(data.items || []);
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }

  useEffect(() => { load(); }, []);

  async function createClient() {
    if (!name.trim()) return;
    setCreating(true);
    setError('');
    try {
      const data = await client.post('admin/api-clients', { client_name: name, scope });
      setOnce(data);
      setName('');
      load();
    } catch (e) { setError(e.message); }
    finally { setCreating(false); }
  }

  return (
    <div className="wpsd-flex wpsd-flex-col wpsd-gap-4">
      <div className="wpsd-card">
        <div className="wpsd-card-header">
          <h3 className="wpsd-card-title">New API client</h3>
          <p className="wpsd-card-desc">Issue a key/secret pair for an external integration.</p>
        </div>
        <div className="wpsd-card-content wpsd-form-grid">
          <label className="wpsd-field">
            <span>Client name</span>
            <input className="wpsd-input" placeholder="e.g. Call-center app" value={name} onChange={(e) => setName(e.target.value)} />
          </label>
          <label className="wpsd-field">
            <span>Scope</span>
            <select className="wpsd-input" value={scope} onChange={(e) => setScope(e.target.value)}>
              <option value="create_only">create_only</option>
              <option value="create_and_read">create_and_read</option>
              <option value="full">full</option>
            </select>
          </label>
        </div>
        <div>
          <button className="wpsd-btn wpsd-btn-primary" disabled={creating || !name.trim()} onClick={createClient}>
            {creating ? 'Generating…' : 'Generate key'}
          </button>
        </div>
      </div>

      {once && (
        <div className="wpsd-card wpsd-border-success">
          <div className="wpsd-card-header">
            <h3 className="wpsd-card-title">API secret — copy it now</h3>
            <p className="wpsd-card-desc">Shown once. It cannot be retrieved again — rotate to replace it.</p>
          </div>
          <p>Key: <code>{once.api_key}</code></p>
          <p>Secret: <code>{once.api_secret}</code></p>
        </div>
      )}
      {error && <p className="wpsd-error">{error}</p>}

      {loading ? <Skeleton /> : (
        <table className="wpsd-table">
          <thead><tr><th>Client</th><th>API key</th><th>Scope</th><th>Rate/min</th><th>Last used</th><th>Actions</th></tr></thead>
          <tbody>
            {items.length === 0 && <tr><td colSpan={6} className="wpsd-empty-cell">No API clients yet.</td></tr>}
            {items.map((c) => (
              <tr key={c.id}>
                <td style={{ fontWeight: 500 }}>{c.client_name}</td>
                <td><code title={c.api_key}>{maskKey(c.api_key)}</code></td>
                <td><span className="wpsd-badge wpsd-b-gray">{c.scope}</span></td>
                <td className="wpsd-muted">{c.rate_limit_per_minute}</td>
                <td className="wpsd-muted">{c.last_used_at || '—'}</td>
                <td className="wpsd-flex wpsd-gap-2">
                  <button className="wpsd-btn wpsd-btn-sm" onClick={async () => {
                    try { const d = await client.post(`admin/api-clients/${c.id}`, { action: 'rotate' }); setOnce(d); }
                    catch (e) { setError(e.message); }
                  }}>Rotate</button>
                  <button className="wpsd-btn wpsd-btn-sm wpsd-btn-destructive" onClick={async () => {
                    if (!window.confirm('Revoke this client?')) return;
                    try { await client.del(`admin/api-clients/${c.id}`); load(); }
                    catch (e) { setError(e.message); }
                  }}>Revoke</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
