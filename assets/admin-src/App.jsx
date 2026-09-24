import React, { useEffect, useState } from 'react';
import { api } from './lib/api.js';
import { TicketList } from './screens/TicketList.jsx';
import { TicketDetail } from './screens/TicketDetail.jsx';
import { TicketForm } from './screens/TicketForm.jsx';
import { ApiClients } from './screens/ApiClients.jsx';
import { Dashboard } from './screens/Dashboard.jsx';

const TABS = [['dashboard', 'Dashboard'], ['tickets', 'Tickets'], ['api-clients', 'API Keys']];
const TAB_KEYS = TABS.map(([t]) => t);
const TAB_STORAGE_KEY = 'wpsd_active_tab';

function loadStoredTab() {
  try {
    const saved = window.localStorage.getItem(TAB_STORAGE_KEY);
    return TAB_KEYS.includes(saved) ? saved : 'dashboard';
  } catch (e) {
    return 'dashboard';
  }
}

export function App({ config }) {
  // A WP admin submenu click (Tickets/API Keys) carries an explicit tab and
  // wins; otherwise fall back to whatever the user last had open.
  const [tab, setTab] = useState(() => (config && config.initialTab) || loadStoredTab());
  // view: { name: 'list' } | { name: 'detail', id } | { name: 'new' } | { name: 'edit', id }
  const [view, setView] = useState({ name: 'list' });
  const client = api(config);

  useEffect(() => {
    const summary = document.querySelector('.wpsd-noscript-summary');
    if (summary) summary.style.display = 'none';
  }, []);

  // Keep the remembered tab in sync with an explicit menu navigation, so the
  // generic/Dashboard entry point falls back to it on a later visit too.
  useEffect(() => {
    if (config && config.initialTab) {
      try { window.localStorage.setItem(TAB_STORAGE_KEY, config.initialTab); } catch (e) { /* ignore */ }
    }
  }, []);

  function goTab(t) {
    setTab(t);
    setView({ name: 'list' });
    try { window.localStorage.setItem(TAB_STORAGE_KEY, t); } catch (e) { /* ignore */ }
  }

  async function createTicket(form) {
    const data = await client.post('tickets', form);
    setView({ name: 'detail', id: data.ticket.id });
    return data;
  }

  async function updateTicket(id, form) {
    const body = {
      customer_name: form.customer_name,
      mobile: form.mobile,
      alternative_mobile: form.alternative_mobile || '',
      address: form.address,
      product_id: Number(form.product_id),
      problem_description: form.problem_description,
      barcode: form.barcode || '',
      comments: form.comments || '',
      priority: form.priority,
    };
    const data = await client.patch(`tickets/${id}`, body);
    setView({ name: 'detail', id });
    return data;
  }

  return (
    <div className="wpsd-flex wpsd-flex-col wpsd-gap-4">
      <nav className="wpsd-tabs wpsd-flex wpsd-gap-2" role="tablist">
        {TABS.map(([t, label]) => (
          <button
            key={t}
            role="tab"
            aria-selected={tab === t}
            className={tab === t ? 'wpsd-btn wpsd-btn-primary' : 'wpsd-btn'}
            onClick={() => goTab(t)}
          >
            {label}
          </button>
        ))}
      </nav>
      {tab === 'dashboard' && <Dashboard client={client} />}
      {tab === 'tickets' && view.name === 'list' && (
        <TicketList client={client} onOpen={(id) => setView({ name: 'detail', id })} onNew={() => setView({ name: 'new' })} />
      )}
      {tab === 'tickets' && view.name === 'detail' && (
        <TicketDetail client={client} id={view.id} config={config}
          onBack={() => setView({ name: 'list' })}
          onEdit={() => setView({ name: 'edit', id: view.id })} />
      )}
      {tab === 'tickets' && view.name === 'new' && (
        <TicketForm client={client} submitLabel="Create ticket"
          onSaved={createTicket} onCancel={() => setView({ name: 'list' })} />
      )}
      {tab === 'tickets' && view.name === 'edit' && (
        <TicketEditLoader client={client} id={view.id}
          onSaved={(form) => updateTicket(view.id, form)}
          onCancel={() => setView({ name: 'detail', id: view.id })} />
      )}
      {tab === 'api-clients' && <ApiClients client={client} />}
    </div>
  );
}

function TicketEditLoader({ client, id, onSaved, onCancel }) {
  const [ticket, setTicket] = useState(null);
  const [error, setError] = useState('');
  useEffect(() => {
    client.get(`tickets/by-id/${id}`)
      .then((d) => setTicket(d.ticket))
      .catch((e) => setError(e.message));
  }, [id]);
  if (error) return <p className="wpsd-error">{error}</p>;
  if (!ticket) return <p>Loading…</p>;
  const initial = {
    ...ticket,
    product_id: String(ticket.product_id || ''),
  };
  return <TicketForm client={client} initial={initial} submitLabel="Save changes" onSaved={onSaved} onCancel={onCancel} />;
}
