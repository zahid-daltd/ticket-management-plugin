import React from 'react';

const STATUS_BADGE = {
  new: 'wpsd-b-blue',
  assigned: 'wpsd-b-violet',
  in_progress: 'wpsd-b-amber',
  resolved: 'wpsd-b-green',
  closed: 'wpsd-b-gray',
  cancelled: 'wpsd-b-red',
};

export function StatusBadge({ value }) {
  return <span className={`wpsd-badge ${STATUS_BADGE[value] || 'wpsd-b-gray'}`}>{value}</span>;
}
