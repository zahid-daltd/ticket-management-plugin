import React, { useEffect, useState } from 'react';
import { api } from './lib/api.js';
import { TicketList } from './screens/TicketList.jsx';
import { TicketDetail } from './screens/TicketDetail.jsx';
import { TicketForm } from './screens/TicketForm.jsx';
import { LookupManager } from './screens/LookupManager.jsx';
import { ApiClients } from './screens/ApiClients.jsx';
import { Dashboard } from './screens/Dashboard.jsx';

const TABS = ['dashboard', 'tickets', 'lookups', 'api-clients'];

export function App({ config }) {
  const [tab, setTab] = useState('tickets');
  // view: { name: 'list' } | { name: 'detail', id } | { name: 'new' } | { name: 'edit', id }
  const [view, setView] = useState({ name: 'list' });
  const client = api(config);

  useEffect(() => {
    const summary = document.querySelector('.wpsd-noscript-summary');
    if (summary) summary.style.display = 'none';
  }, []);

  function goTab(t) {
    setTab(t);
    setView({ name: 'list' });
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
      district_id: Number(form.district_id),
      thana_id: Number(form.thana_id),
      route_id: Number(form.route_id),
      service_center_id: Number(form.service_center_id),
      address: form.address,
      product_id: Number(form.product_id),
      problem_type_id: Number(form.problem_type_id),
      barcode: form.barcode || '',
      comments: form.comments || '',
    };
    const data = await client.patch(`tickets/${id}`, body);
    setView({ name: 'detail', id });
    return data;
  }

  return (
    <div className="wpsd-flex wpsd-flex-col wpsd-gap-4">
      <nav className="wpsd-flex wpsd-gap-2" role="tablist">
        {TABS.map((t) => (
          <button
            key={t}
            role="tab"
            aria-selected={tab === t}
            className={tab === t ? 'wpsd-btn wpsd-btn-primary' : 'wpsd-btn'}
            onClick={() => goTab(t)}
          >
            {t}
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
      {tab === 'lookups' && <LookupManager client={client} />}
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
    district_id: String(ticket.district_id || ''),
    thana_id: String(ticket.thana_id || ''),
    route_id: String(ticket.route_id || ''),
    service_center_id: String(ticket.service_center_id || ''),
    product_id: String(ticket.product_id || ''),
    problem_type_id: String(ticket.problem_type_id || ''),
    brand: ticket.brand_snapshot || '',
  };
  return <TicketForm client={client} initial={initial} submitLabel="Save changes" onSaved={onSaved} onCancel={onCancel} />;
}
