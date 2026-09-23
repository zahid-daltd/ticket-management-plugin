import React, { useEffect, useState } from 'react';

export function Dashboard({ client }) {
  const [stats, setStats] = useState(null);
  const [error, setError] = useState('');
  useEffect(() => {
    client.get('admin/stats').then(setStats).catch((e) => setError(e.message));
  }, []);
  if (error) return <p className="wpsd-error">{error}</p>;
  if (!stats) return <p>Loading…</p>;
  return (
    <div className="wpsd-grid">
      <div className="wpsd-card"><h3>Total</h3><p className="wpsd-stat">{stats.total}</p></div>
      {Object.entries(stats.by_status || {}).map(([s, c]) => (
        <div className="wpsd-card" key={s}><h3>{s}</h3><p className="wpsd-stat">{c}</p></div>
      ))}
      <div className="wpsd-card">
        <h3>Avg resolution (hrs)</h3>
        <p className="wpsd-stat">{stats.avg_resolution_hours ?? '—'}</p>
      </div>
    </div>
  );
}
