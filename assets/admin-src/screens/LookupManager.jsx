import React, { useEffect, useState } from 'react';

const TYPES = ['districts', 'thanas', 'routes', 'service-centers', 'products', 'problem-types'];

const COLUMNS = {
  'districts': [
    { key: 'id', label: 'ID' },
    { key: 'name', label: 'Name' },
  ],
  'thanas': [
    { key: 'id', label: 'ID' },
    { key: 'district_name', label: 'District' },
    { key: 'name', label: 'Thana' },
  ],
  'routes': [
    { key: 'id', label: 'ID' },
    { key: 'thana_name', label: 'Thana' },
    { key: 'name', label: 'Route' },
  ],
  'service-centers': [
    { key: 'id', label: 'ID' },
    { key: 'route_name', label: 'Route' },
    { key: 'name', label: 'Center' },
    { key: 'address', label: 'Address' },
    { key: 'contact_phone', label: 'Phone' },
  ],
  'products': [
    { key: 'id', label: 'ID' },
    { key: 'brand', label: 'Brand' },
    { key: 'model_name', label: 'Model' },
    { key: 'category', label: 'Category' },
    { key: 'is_active', label: 'Active', format: (v) => (Number(v) ? 'Yes' : 'No') },
  ],
  'problem-types': [
    { key: 'id', label: 'ID' },
    { key: 'product_category', label: 'Category', format: (v) => (v ? v : 'Global') },
    { key: 'label', label: 'Problem' },
    { key: 'is_active', label: 'Active', format: (v) => (Number(v) ? 'Yes' : 'No') },
  ],
};

// Which fields the create/edit form shows per type.
const FORM_FIELDS = {
  'districts': [{ key: 'name', label: 'Name *' }],
  'thanas': [
    { key: 'district_id', label: 'District *', type: 'parent', parent: 'districts' },
    { key: 'name', label: 'Thana *' },
  ],
  'routes': [
    { key: 'thana_id', label: 'Thana *', type: 'parent', parent: 'thanas' },
    { key: 'name', label: 'Route *' },
  ],
  'service-centers': [
    { key: 'route_id', label: 'Route *', type: 'parent', parent: 'routes' },
    { key: 'name', label: 'Center *' },
    { key: 'address', label: 'Address' },
    { key: 'contact_phone', label: 'Phone' },
  ],
  'products': [
    { key: 'brand', label: 'Brand *' },
    { key: 'model_name', label: 'Model *' },
    { key: 'category', label: 'Category *' },
    { key: 'is_active', label: 'Active', type: 'bool' },
  ],
  'problem-types': [
    { key: 'product_category', label: 'Category (empty = global)' },
    { key: 'label', label: 'Problem *' },
    { key: 'is_active', label: 'Active', type: 'bool' },
  ],
};

export function LookupManager({ client }) {
  const [type, setType] = useState('districts');
  const [items, setItems] = useState([]);
  const [parents, setParents] = useState({ districts: [], thanas: [], routes: [] });
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(null); // row or 'new'
  const [form, setForm] = useState({});

  async function load(t = type) {
    setError('');
    setEditing(null);
    try {
      const data = await client.get(`admin/lookups/${t}`);
      let rows = data.items || [];
      // Resolve parent names for display.
      if (t === 'thanas' || t === 'routes' || t === 'service-centers') {
        const [d, th, r] = await Promise.all([
          client.get('admin/lookups/districts').catch(() => ({ items: [] })),
          client.get('admin/lookups/thanas').catch(() => ({ items: [] })),
          client.get('admin/lookups/routes').catch(() => ({ items: [] })),
        ]);
        const pmap = { districts: d.items || [], thanas: th.items || [], routes: r.items || [] };
        setParents(pmap);
        const dById = Object.fromEntries((pmap.districts || []).map((x) => [x.id, x.name]));
        const tById = Object.fromEntries((pmap.thanas || []).map((x) => [x.id, `${x.name} (${dById[x.district_id] || ''})`]));
        const rById = Object.fromEntries((pmap.routes || []).map((x) => [x.id, x.name]));
        rows = rows.map((row) => ({
          ...row,
          district_name: dById[row.district_id] || `#${row.district_id}`,
          thana_name: tById[row.thana_id] || `#${row.thana_id}`,
          route_name: rById[row.route_id] || `#${row.route_id}`,
        }));
      }
      setItems(rows);
    } catch (e) { setError(e.message); }
  }

  useEffect(() => { load(type); }, [type]);

  function startNew() {
    const blank = {};
    (FORM_FIELDS[type] || []).forEach((f) => { blank[f.key] = f.type === 'bool' ? 1 : ''; });
    setForm(blank);
    setEditing('new');
  }
  function startEdit(row) {
    setForm({ ...row });
    setEditing(row.id);
  }

  async function save(e) {
    e.preventDefault();
    setError('');
    const payload = { ...form };
    delete payload.id;
    // Coerce parent ids + bools.
    ['district_id', 'thana_id', 'route_id'].forEach((k) => {
      if (k in payload) payload[k] = Number(payload[k]);
    });
    if ('is_active' in payload) payload.is_active = payload.is_active ? 1 : 0;
    try {
      if (editing === 'new') await client.post(`admin/lookups/${type}`, payload);
      else await client.put(`admin/lookups/${type}/${editing}`, payload);
      load(type);
    } catch (err) {
      setError(err.message);
    }
  }

  const cols = COLUMNS[type] || [];
  const parentOptions = (parent) => parents[parent] || [];

  return (
    <div className="wpsd-flex wpsd-flex-col wpsd-gap-4">
      <div className="wpsd-flex wpsd-gap-2">
        <select className="wpsd-input" value={type} onChange={(e) => setType(e.target.value)} aria-label="Lookup table">
          {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <button className="wpsd-btn wpsd-btn-primary" onClick={startNew}>+ Add</button>
      </div>
      {error && <p className="wpsd-error">{error}</p>}

      {editing !== null && (
        <form className="wpsd-card wpsd-flex wpsd-flex-col wpsd-gap-2" onSubmit={save}>
          <h3>{editing === 'new' ? `Add ${type}` : `Edit ${type} #${editing}`}</h3>
          {(FORM_FIELDS[type] || []).map((f) => (
            <label className="wpsd-field" key={f.key}>
              <span>{f.label}</span>
              {f.type === 'parent' ? (
                <select className="wpsd-input" value={form[f.key] || ''} onChange={(e) => setForm({ ...form, [f.key]: e.target.value })}>
                  <option value="">Select…</option>
                  {parentOptions(f.parent).map((p) => (
                    <option key={p.id} value={p.id}>{p.name}{p.district_id ? ` (district #${p.district_id})` : ''}</option>
                  ))}
                </select>
              ) : f.type === 'bool' ? (
                <input type="checkbox" checked={!!Number(form[f.key])} onChange={(e) => setForm({ ...form, [f.key]: e.target.checked ? 1 : 0 })} />
              ) : (
                <input className="wpsd-input" value={form[f.key] || ''} onChange={(e) => setForm({ ...form, [f.key]: e.target.value })} />
              )}
            </label>
          ))}
          <div className="wpsd-flex wpsd-gap-2">
            <button className="wpsd-btn wpsd-btn-primary" type="submit">Save</button>
            <button className="wpsd-btn" type="button" onClick={() => setEditing(null)}>Cancel</button>
          </div>
        </form>
      )}

      <table className="wpsd-table">
        <thead><tr>{cols.map((c) => <th key={c.key}>{c.label}</th>)}<th>Actions</th></tr></thead>
        <tbody>
          {items.length === 0 && <tr><td colSpan={cols.length + 1}>No records.</td></tr>}
          {items.map((row) => (
            <tr key={row.id}>
              {cols.map((c) => (
                <td key={c.key}>{c.format ? c.format(row[c.key]) : (row[c.key] ?? '—')}</td>
              ))}
              <td className="wpsd-flex wpsd-gap-2">
                <button className="wpsd-btn" onClick={() => startEdit(row)}>Edit</button>
                <button
                  className="wpsd-btn"
                  onClick={async () => {
                    if (!window.confirm('Delete this record? Blocked when used by tickets.')) return;
                    try { await client.del(`admin/lookups/${type}/${row.id}`); load(type); }
                    catch (e) { setError(e.message); }
                  }}
                >Delete</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
