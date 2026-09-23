import React from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App.jsx';
import './styles.css';

const rootEl = document.getElementById('wpsd-admin-root');
if (rootEl) {
  createRoot(rootEl).render(<App config={window.WPSD_ADMIN_CONFIG || {}} />);
}
