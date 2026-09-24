import React from 'react';
import { createRoot } from 'react-dom/client';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { TicketForm } from './TicketForm.jsx';
import './styles.css';

const schema = z.object({
  customer_name: z.string().min(2).max(150),
  mobile: z.string().min(11),
  address: z.string().min(1).max(50),
});

function LookupApp() {
  return <div className="wpsd-card"><p>Ticket lookup mounts here (code-split chunk).</p></div>;
}

async function mount() {
  const formRoot = document.getElementById('wpsd-public-root');
  if (formRoot) {
    createRoot(formRoot).render(<TicketForm config={window.WPSD_PUBLIC_CONFIG || {}} schema={schema} useForm={useForm} resolver={zodResolver} />);
  }
  const lookupRoot = document.getElementById('wpsd-lookup-root');
  if (lookupRoot) {
    try {
      const mod = await import('./lookup.jsx');
      const Lookup = mod.Lookup || LookupApp;
      createRoot(lookupRoot).render(<Lookup />);
    } catch (e) {
      lookupRoot.textContent = 'Could not load the ticket lookup. Please refresh the page.';
    }
  }
}

mount();
