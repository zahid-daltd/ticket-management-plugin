import React from 'react';

const STATUS_BADGE = {
  new: 'wpsd-b-blue',
  assigned: 'wpsd-b-violet',
  in_progress: 'wpsd-b-amber',
  resolved: 'wpsd-b-green',
  closed: 'wpsd-b-gray',
  cancelled: 'wpsd-b-red',
};

const PRIORITY_BADGE = {
  low: 'wpsd-b-gray',
  med: 'wpsd-b-amber',
  high: 'wpsd-b-red',
};

export function StatusBadge({ value }) {
  return <span className={`wpsd-badge ${STATUS_BADGE[value] || 'wpsd-b-gray'}`}>{value}</span>;
}

export function PriorityBadge({ value }) {
  return <span className={`wpsd-badge ${PRIORITY_BADGE[value] || 'wpsd-b-gray'}`}>{value}</span>;
}

export function ActiveBadge({ value }) {
  const on = Number(value);
  return <span className={`wpsd-badge ${on ? 'wpsd-b-green' : 'wpsd-b-gray'}`}>{on ? 'Active' : 'Inactive'}</span>;
}

export function Skeleton() {
  return (
    <div className="wpsd-skeleton" aria-live="polite">
      <div className="wpsd-skeleton-line" />
      <div className="wpsd-skeleton-line" />
      <div className="wpsd-skeleton-line short" />
    </div>
  );
}
