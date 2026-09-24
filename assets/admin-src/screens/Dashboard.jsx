import React, { useEffect, useState } from 'react';
import { Skeleton } from '../lib/badges.jsx';

const STATUS_ACCENT = {
  new: 'wpsd-accent-blue',
  assigned: 'wpsd-accent-violet',
  in_progress: 'wpsd-accent-amber',
  resolved: 'wpsd-accent-green',
  closed: 'wpsd-accent-gray',
  cancelled: 'wpsd-accent-red',
};

function statusLabel(s) {
  return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function Dashboard({ client }) {
  const [stats, setStats] = useState(null);
  const [error, setError] = useState('');
  useEffect(() => {
    client.get('admin/stats').then(setStats).catch((e) => setError(e.message));
  }, []);
  if (error) return <p className="wpsd-error">{error}</p>;
  if (!stats) return <Skeleton />;
  return (
    <div className="wpsd-flex wpsd-flex-col wpsd-gap-4">
      <div>
        <h2>Overview</h2>
        <p className="wpsd-muted">Ticket volume and turnaround at a glance.</p>
      </div>
      <div className="wpsd-grid">
        <div className="wpsd-card wpsd-stat-card">
          <p className="wpsd-stat-label">Total tickets</p>
          <p className="wpsd-stat">{stats.total}</p>
        </div>
        {Object.entries(stats.by_status || {}).map(([s, c]) => (
          <div className={`wpsd-card wpsd-stat-card ${STATUS_ACCENT[s] || 'wpsd-accent-gray'}`} key={s}>
            <p className="wpsd-stat-label">{statusLabel(s)}</p>
            <p className="wpsd-stat">{c}</p>
          </div>
        ))}
        <div className="wpsd-card wpsd-stat-card">
          <p className="wpsd-stat-label">Avg resolution (hrs)</p>
          <p className="wpsd-stat">{stats.avg_resolution_hours ?? '—'}</p>
        </div>
      </div>
    </div>
  );
}
