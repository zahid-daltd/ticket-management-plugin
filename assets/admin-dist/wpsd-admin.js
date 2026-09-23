/* Affiniti WP Support — admin bundle (compiled output; rebuild with `npm run build`). */
(function () {
  'use strict';

  function cfg() {
    return window.WPSD_ADMIN_CONFIG || {};
  }
  function base() {
    var c = cfg();
    return c.restUrl || '/wp-json/wpsd/v1/';
  }
  function nonce() {
    return cfg().nonce || '';
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
  }

  var ICONS = {
    plus: '<svg class="wpsd-icon" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
    back: '<svg class="wpsd-icon" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>',
    search: '<svg class="wpsd-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></svg>',
    check: '<svg class="wpsd-icon" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>'
  };

  var STATUS_BADGE = {
    new: 'wpsd-b-blue', assigned: 'wpsd-b-violet', in_progress: 'wpsd-b-amber',
    resolved: 'wpsd-b-green', closed: 'wpsd-b-gray', cancelled: 'wpsd-b-red'
  };
  var PRIORITY_BADGE = { low: 'wpsd-b-gray', med: 'wpsd-b-amber', high: 'wpsd-b-red' };

  function statusBadge(s) {
    return '<span class="wpsd-badge ' + (STATUS_BADGE[s] || 'wpsd-b-gray') + '">' + esc(s) + '</span>';
  }
  function priorityBadge(p) {
    return '<span class="wpsd-badge ' + (PRIORITY_BADGE[p] || 'wpsd-b-gray') + '">' + esc(p) + '</span>';
  }
  function skeleton() {
    return '<div class="wpsd-skeleton" aria-live="polite"><div class="wpsd-skeleton-line"></div><div class="wpsd-skeleton-line"></div><div class="wpsd-skeleton-line short"></div></div>';
  }

  async function req(path, opts) {
    opts = opts || {};
    var res = await fetch(base() + path, {
      method: opts.method || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
      body: opts.body ? JSON.stringify(opts.body) : undefined
    });
    var json = null;
    try { json = await res.json(); } catch (e) { /* noop */ }
    if (!res.ok || (json && json.success === false)) {
      var msg = (json && json.error && json.error.message) || ('Request failed (' + res.status + ')');
      var err = new Error(msg);
      err.details = json && json.data;
      err.status = res.status;
      throw err;
    }
    return json ? json.data : null;
  }

  var LOOKUP_COLS = {
    'districts': [['id', 'ID'], ['name', 'Name']],
    'thanas': [['id', 'ID'], ['district_name', 'District'], ['name', 'Thana']],
    'routes': [['id', 'ID'], ['thana_name', 'Thana'], ['name', 'Route']],
    'service-centers': [['id', 'ID'], ['route_name', 'Route'], ['name', 'Center'], ['address', 'Address'], ['contact_phone', 'Phone']],
    'products': [['id', 'ID'], ['brand', 'Brand'], ['model_name', 'Model'], ['category', 'Category'], ['is_active', 'Active']],
    'problem-types': [['id', 'ID'], ['product_category', 'Category'], ['label', 'Problem'], ['is_active', 'Active']]
  };
  var LOOKUP_TYPES = Object.keys(LOOKUP_COLS);

  var state = {
    tab: 'tickets', view: { name: 'list' }, page: 1, status: '', search: '',
    items: [], total: 0, detail: null, replies: [], attachments: [],
    stats: null, lookups: [], lookupType: 'districts',
    lookupParents: { districts: [], thanas: [], routes: [] },
    lookupEditing: null, lookupForm: {},
    tf: null // ticket create/edit form state
  };

  function el(html) {
    var t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstChild;
  }

  function fmtCell(type, key, row) {
    var v = row[key];
    if (key === 'is_active') {
      var on = Number(v);
      return '<span class="wpsd-badge ' + (on ? 'wpsd-b-green' : 'wpsd-b-gray') + '">' + (on ? 'Active' : 'Inactive') + '</span>';
    }
    if (key === 'product_category') return v ? esc(v) : '<span class="wpsd-muted">Global</span>';
    if (v == null || v === '') return '<span class="wpsd-muted">—</span>';
    return esc(v);
  }

  function toolbar(root) {
    var nav = el('<nav class="wpsd-tabs wpsd-flex wpsd-gap-2" role="tablist"></nav>');
    ['dashboard', 'tickets', 'lookups', 'api-clients'].forEach(function (t) {
      var b = el('<button role="tab" class="wpsd-btn' + (state.tab === t ? ' wpsd-btn-primary' : '') + '">' + esc(t) + '</button>');
      b.setAttribute('aria-selected', state.tab === t ? 'true' : 'false');
      b.addEventListener('click', function () { state.tab = t; state.view = { name: 'list' }; render(root); });
      nav.appendChild(b);
    });
    return nav;
  }

  function showError(root, e) {
    var p = root.querySelector('[data-err]');
    if (p) {
      var msg = e.message || 'Error';
      if (e.details && e.details.fields) {
        msg += ': ' + Object.keys(e.details.fields).map(function (k) { return k + ' — ' + e.details.fields[k]; }).join('; ');
      }
      p.textContent = msg;
    }
  }

  // ---------------------------------------------------------------- tickets
  async function loadTickets(root) {
    var q = new URLSearchParams({ page: String(state.page), per_page: '20' });
    if (state.status) q.set('status', state.status);
    if (state.search) q.set('search', state.search);
    try {
      var data = await req('tickets?' + q.toString());
      state.items = data.items || [];
      state.total = data.total || 0;
      state.page = data.page || 1;
    } catch (e) { state.items = []; showError(root, e); }
    renderTicketList(root);
  }

  function renderTicketList(root) {
    var wrap = root.querySelector('[data-view]');
    var rows = state.items.map(function (t) {
      return '<tr><td><button class="wpsd-link" data-open="' + t.id + '">' + esc(t.ticket_number) + '</button></td>' +
        '<td><div style="font-weight:500">' + esc(t.customer_name) + '</div><div class="wpsd-muted" style="font-size:13px">' + esc(t.mobile) + '</div></td>' +
        '<td>' + statusBadge(t.status) + '</td><td>' + priorityBadge(t.priority) + '</td><td class="wpsd-muted">' + esc(t.created_at) + '</td></tr>';
    }).join('');
    wrap.innerHTML =
      '<div class="wpsd-flex wpsd-gap-2">' +
      '<select class="wpsd-input" data-f-status aria-label="Filter by status"><option value="">All statuses</option>' +
      ['new', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled'].map(function (s) {
        return '<option value="' + s + '"' + (state.status === s ? ' selected' : '') + '>' + s + '</option>';
      }).join('') + '</select>' +
      '<input class="wpsd-input" data-f-search placeholder="Search number / name / mobile" value="' + esc(state.search) + '" />' +
      '<button class="wpsd-btn wpsd-btn-primary" data-f-go>' + ICONS.search + 'Search</button>' +
      '<button class="wpsd-btn wpsd-btn-primary" data-new>' + ICONS.plus + 'New ticket</button></div>' +
      '<p data-err class="wpsd-error" aria-live="polite"></p>' +
      '<table class="wpsd-table"><thead><tr><th>Ticket</th><th>Customer</th><th>Status</th><th>Priority</th><th>Created</th></tr></thead>' +
      '<tbody>' + (rows || '<tr><td colspan="5">No tickets.</td></tr>') + '</tbody></table>' +
      '<p>' + state.total + ' tickets · page ' + state.page + '</p>' +
      '<div class="wpsd-flex wpsd-gap-2"><button class="wpsd-btn" data-prev>Prev</button>' +
      '<button class="wpsd-btn" data-next>Next</button></div>';

    wrap.querySelector('[data-f-status]').addEventListener('change', function (e) { state.status = e.target.value; state.page = 1; loadTickets(root); });
    wrap.querySelector('[data-f-go]').addEventListener('click', function () { state.search = wrap.querySelector('[data-f-search]').value; state.page = 1; loadTickets(root); });
    wrap.querySelector('[data-new]').addEventListener('click', function () { startTicketForm(root, 'new'); });
    wrap.querySelector('[data-prev]').addEventListener('click', function () { if (state.page > 1) { state.page--; loadTickets(root); } });
    wrap.querySelector('[data-next]').addEventListener('click', function () { state.page++; loadTickets(root); });
    wrap.querySelectorAll('[data-open]').forEach(function (b) {
      b.addEventListener('click', function () { openDetail(root, parseInt(b.getAttribute('data-open'), 10)); });
    });
  }

  async function openDetail(root, id) {
    var wrap = root.querySelector('[data-view]');
    wrap.innerHTML = skeleton();
    try {
      var data = await req('tickets/by-id/' + id);
      state.detail = data.ticket;
      state.replies = data.replies || [];
      state.attachments = data.attachments || [];
      state.view = { name: 'detail', id: id };
    } catch (e) { wrap.innerHTML = '<p class="wpsd-error">' + esc(e.message) + '</p>'; return; }
    renderDetail(root);
  }

  function renderDetail(root) {
    var t = state.detail;
    var c = cfg();
    var wrap = root.querySelector('[data-view]');
    var replies = state.replies.map(function (r) {
      return '<li><strong>' + esc(r.author_type) + (Number(r.is_internal_note) ? ' (internal)' : '') + ':</strong> ' + esc(r.message) + '</li>';
    }).join('');
    var atts = state.attachments.map(function (a) {
      return '<li><a href="' + esc(a.file_url) + '" target="_blank" rel="noreferrer">' + esc(a.original_filename) + '</a></li>';
    }).join('');
    wrap.innerHTML =
      '<div class="wpsd-flex wpsd-gap-2"><button class="wpsd-btn" data-back>' + ICONS.back + 'Back to list</button>' +
      '<button class="wpsd-btn" data-edit>Edit ticket</button></div>' +
      '<p data-err class="wpsd-error" aria-live="polite"></p>' +
      '<div class="wpsd-card"><div class="wpsd-card-header"><h3 class="wpsd-card-title">' + esc(t.ticket_number) + '</h3>' +
      '<div class="wpsd-flex wpsd-gap-2">' + statusBadge(t.status) + priorityBadge(t.priority) + '<span class="wpsd-badge wpsd-b-gray">' + esc(t.source) + '</span></div></div>' +
      '<p><strong>' + esc(t.customer_name) + '</strong> <span class="wpsd-muted">· ' + esc(t.mobile) + (t.alternative_mobile ? ' · alt: ' + esc(t.alternative_mobile) : '') + '</span></p>' +
      '<p>' + esc(t.district_name || '') + ' / ' + esc(t.thana_name || '') + ' / ' + esc(t.route_name || '') + ' / ' + esc(t.service_center_name || '') + '</p>' +
      '<p>Address: ' + esc(t.address) + '</p>' +
      '<p>Product: ' + esc(t.product_label || ((t.brand_snapshot || '') + ' — ' + (t.product_name_snapshot || ''))) + ' · Problem: ' + esc(t.problem_label || '') + '</p>' +
      (t.barcode ? '<p>Barcode: ' + esc(t.barcode) + '</p>' : '') +
      (t.comments ? '<p>Comments: ' + esc(t.comments) + '</p>' : '') +
      '<p>Status: ' + esc(t.status) + ' · Priority: ' + esc(t.priority) + ' · Source: ' + esc(t.source) + '</p></div>' +
      '<div class="wpsd-card"><div class="wpsd-card-header"><h3 class="wpsd-card-title">Replies</h3>' +
      '<p class="wpsd-card-desc">Conversation thread. Internal notes are hidden from customers.</p></div><ul class="wpsd-replies">' + (replies || '<li>No replies yet.</li>') + '</ul>' +
      (atts ? '<h4>Attachments</h4><ul>' + atts + '</ul>' : '') +
      (c.canManage ? '<div class="wpsd-flex wpsd-flex-col wpsd-gap-2"><textarea class="wpsd-input" data-r-msg rows="3"></textarea>' +
      '<label><input type="checkbox" data-r-internal /> Internal note</label>' +
      '<button class="wpsd-btn" data-r-send>Send reply</button></div>' : '') + '</div>' +
      (c.canManage ? '<div class="wpsd-card"><div class="wpsd-card-header"><h3 class="wpsd-card-title">Quick update</h3>' +
      '<p class="wpsd-card-desc">Change status, priority, or assignment without opening the full editor.</p></div><div class="wpsd-flex wpsd-gap-2">' +
      '<select class="wpsd-input" data-u-status aria-label="Status"><option value="">Status…</option>' +
      ['new', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled'].map(function (s) { return '<option>' + s + '</option>'; }).join('') + '</select>' +
      '<select class="wpsd-input" data-u-priority aria-label="Priority"><option value="">Priority…</option><option>low</option><option>med</option><option>high</option></select>' +
      (c.canAssign ? '<input class="wpsd-input" data-u-agent placeholder="Agent user id" inputmode="numeric" />' : '') +
      '<button class="wpsd-btn wpsd-btn-primary" data-u-save>Save</button></div></div>' +
      (c.canDelete ? '<div><button class="wpsd-btn wpsd-btn-destructive" data-del>Delete ticket</button></div>' : '') : '');

    wrap.querySelector('[data-back]').addEventListener('click', function () { state.view = { name: 'list' }; loadTickets(root); });
    wrap.querySelector('[data-edit]').addEventListener('click', function () { startTicketForm(root, state.detail.id); });
    var sendBtn = wrap.querySelector('[data-r-send]');
    if (sendBtn) {
      sendBtn.addEventListener('click', async function () {
        try {
          var r = await req('tickets/' + t.id + '/replies', { method: 'POST', body: { message: wrap.querySelector('[data-r-msg]').value, is_internal_note: wrap.querySelector('[data-r-internal]').checked } });
          state.replies.push(r.reply);
          renderDetail(root);
        } catch (e) { showError(root, e); }
      });
    }
    var saveBtn = wrap.querySelector('[data-u-save]');
    if (saveBtn) {
      saveBtn.addEventListener('click', async function () {
        var body = {};
        var st = wrap.querySelector('[data-u-status]').value;
        var pr = wrap.querySelector('[data-u-priority]').value;
        if (st) body.status = st;
        if (pr) body.priority = pr;
        var ag = wrap.querySelector('[data-u-agent]');
        if (ag && ag.value !== '') body.assigned_agent_id = parseInt(ag.value, 10);
        try {
          var d = await req('tickets/' + t.id, { method: 'PATCH', body: body });
          state.detail = d.ticket;
          renderDetail(root);
        } catch (e) { showError(root, e); }
      });
    }
    var delBtn = wrap.querySelector('[data-del]');
    if (delBtn) {
      delBtn.addEventListener('click', async function () {
        if (!window.confirm('Delete this ticket permanently?')) return;
        try { await req('tickets/' + t.id, { method: 'DELETE' }); state.view = { name: 'list' }; state.page = 1; loadTickets(root); }
        catch (e) { showError(root, e); }
      });
    }
  }

  // ------------------------------------------------------- ticket create/edit
  function blankTicketForm() {
    return {
      mode: 'new', id: 0,
      customer_name: '', mobile: '', alternative_mobile: '', address: '', barcode: '', comments: '',
      district_id: '', thana_id: '', route_id: '', service_center_id: '',
      brand: '', product_id: '', problem_type_id: '',
      districts: [], thanas: [], routes: [], centers: [], products: [], brands: [], problems: [],
      errors: {}
    };
  }

  async function startTicketForm(root, mode) {
    state.tf = blankTicketForm();
    if (mode !== 'new') {
      state.tf.mode = 'edit';
      state.tf.id = mode;
    }
    state.view = { name: mode === 'new' ? 'new' : 'edit' };
    var wrap = root.querySelector('[data-view]');
    wrap.innerHTML = skeleton();
    try {
      var d = await req('lookups/districts');
      state.tf.districts = (d.items || []);
      var p = await req('lookups/products');
      state.tf.products = (p.items || []);
      state.tf.brands = (p.brands || []);
      if (state.tf.mode === 'edit') {
        var data = await req('tickets/by-id/' + state.tf.id);
        var t = data.ticket;
        ['customer_name', 'mobile', 'alternative_mobile', 'address', 'barcode', 'comments'].forEach(function (k) {
          state.tf[k] = t[k] == null ? '' : String(t[k]);
        });
        state.tf.district_id = String(t.district_id || '');
        state.tf.thana_id = String(t.thana_id || '');
        state.tf.route_id = String(t.route_id || '');
        state.tf.service_center_id = String(t.service_center_id || '');
        state.tf.product_id = String(t.product_id || '');
        state.tf.problem_type_id = String(t.problem_type_id || '');
        state.tf.brand = t.brand_snapshot || '';
        if (state.tf.district_id) {
          var th = await req('lookups/thanas?district_id=' + encodeURIComponent(state.tf.district_id));
          state.tf.thanas = th.items || [];
        }
        if (state.tf.thana_id) {
          var ro = await req('lookups/routes?thana_id=' + encodeURIComponent(state.tf.thana_id));
          state.tf.routes = ro.items || [];
        }
        if (state.tf.route_id) {
          var ce = await req('lookups/service-centers?route_id=' + encodeURIComponent(state.tf.route_id));
          state.tf.centers = ce.items || [];
        }
        if (state.tf.product_id) {
          var pr = await req('lookups/problem-types?product_id=' + encodeURIComponent(state.tf.product_id));
          state.tf.problems = pr.items || [];
        }
      }
    } catch (e) { wrap.innerHTML = '<p class="wpsd-error">' + esc(e.message) + '</p>'; return; }
    renderTicketForm(root);
  }

  function tfOptions(items, valKey, labelFn, selected, placeholder) {
    var html = '<option value="">' + esc(placeholder) + '</option>';
    items.forEach(function (it) {
      var v = String(it[valKey]);
      html += '<option value="' + esc(v) + '"' + (String(selected) === v ? ' selected' : '') + '>' + esc(labelFn(it)) + '</option>';
    });
    return html;
  }

  function tfField(label, inner, err) {
    return '<label class="wpsd-field"><span>' + esc(label) + '</span>' + inner +
      (err ? '<em class="wpsd-error">' + esc(err) + '</em>' : '') + '</label>';
  }

  function renderTicketForm(root) {
    var tf = state.tf;
    var e = tf.errors || {};
    var wrap = root.querySelector('[data-view]');
    var visibleProducts = tf.brand ? tf.products.filter(function (p) { return String(p.brand) === String(tf.brand); }) : tf.products;
    wrap.innerHTML =
      '<button class="wpsd-btn" data-tf-back>' + ICONS.back + (tf.mode === 'new' ? 'Back to list' : 'Back to ticket') + '</button>' +
      '<h2>' + (tf.mode === 'new' ? 'New ticket' : 'Edit ticket') + '</h2>' +
      '<p data-err class="wpsd-error" aria-live="polite"></p>' +
      '<form class="wpsd-form" novalidate>' +
      '<div class="wpsd-card"><div class="wpsd-card-header"><h3 class="wpsd-card-title">Basic information</h3>' +
      '<p class="wpsd-card-desc">Customer and service location.</p></div><div class="wpsd-card-content wpsd-form-grid">' +
      (e.general ? '<p class="wpsd-error">' + esc(e.general) + '</p>' : '') +
      (e.location ? '<p class="wpsd-error">' + esc(e.location) + '</p>' : '') +
      tfField('Name *', '<input class="wpsd-input" data-k="customer_name" maxlength="150" value="' + esc(tf.customer_name) + '">', e.customer_name) +
      tfField('Mobile *', '<input class="wpsd-input" data-k="mobile" inputmode="tel" placeholder="01712345678" value="' + esc(tf.mobile) + '">', e.mobile) +
      tfField('Alternative mobile', '<input class="wpsd-input" data-k="alternative_mobile" inputmode="tel" value="' + esc(tf.alternative_mobile) + '">', e.alternative_mobile) +
      tfField('District *', '<select class="wpsd-input" data-k="district_id">' + tfOptions(tf.districts, 'id', function (x) { return x.name; }, tf.district_id, 'Select district') + '</select>', e.district_id) +
      tfField('Thana *', '<select class="wpsd-input" data-k="thana_id"' + (tf.district_id ? '' : ' disabled') + '>' + tfOptions(tf.thanas, 'id', function (x) { return x.name; }, tf.thana_id, 'Select thana') + '</select>', e.thana_id) +
      tfField('Route *', '<select class="wpsd-input" data-k="route_id"' + (tf.thana_id ? '' : ' disabled') + '>' + tfOptions(tf.routes, 'id', function (x) { return x.name; }, tf.route_id, 'Select route') + '</select>', e.route_id) +
      tfField('Service center *', '<select class="wpsd-input" data-k="service_center_id"' + (tf.route_id ? '' : ' disabled') + '>' + tfOptions(tf.centers, 'id', function (x) { return x.name; }, tf.service_center_id, 'Select service center') + '</select>', e.service_center_id) +
      tfField('Address (House/Road) * — max 50 chars', '<input class="wpsd-input" data-k="address" maxlength="50" value="' + esc(tf.address) + '">', e.address) +
      '</div></div><div class="wpsd-card"><div class="wpsd-card-header"><h3 class="wpsd-card-title">Incident information</h3>' +
      '<p class="wpsd-card-desc">Product, barcode, and problem details.</p></div><div class="wpsd-card-content wpsd-form-grid">' +
      (e.product ? '<p class="wpsd-error">' + esc(e.product) + '</p>' : '') +
      tfField('Brand *', '<select class="wpsd-input" data-k="brand"><option value="">Select brand</option>' +
        tf.brands.map(function (b) { return '<option' + (String(tf.brand) === String(b) ? ' selected' : '') + '>' + esc(b) + '</option>'; }).join('') + '</select>', null) +
      tfField('Product *', '<select class="wpsd-input" data-k="product_id"' + (tf.brand ? '' : ' disabled') + '>' +
        tfOptions(visibleProducts, 'id', function (x) { return x.brand + ' — ' + x.model_name; }, tf.product_id, 'Select product') + '</select>', e.product_id) +
      tfField('Barcode (optional)', '<input class="wpsd-input" data-k="barcode" maxlength="120" value="' + esc(tf.barcode) + '">', e.barcode) +
      tfField('Problem *', '<select class="wpsd-input" data-k="problem_type_id"' + (tf.product_id ? '' : ' disabled') + '>' +
        tfOptions(tf.problems, 'id', function (x) { return x.label; }, tf.problem_type_id, 'Select problem') + '</select>', e.problem_type_id) +
      tfField('Comments (optional)', '<textarea class="wpsd-input" data-k="comments" rows="4" maxlength="2000">' + esc(tf.comments) + '</textarea>', e.comments) +
      '</div></div><div class="wpsd-flex wpsd-gap-2"><button class="wpsd-btn wpsd-btn-primary" type="submit">' +
      (tf.mode === 'new' ? 'Create ticket' : 'Save changes') + '</button></div></form>';

    function syncText() {
      wrap.querySelectorAll('[data-k]').forEach(function (n) {
        var k = n.getAttribute('data-k');
        if (n.tagName === 'INPUT' || n.tagName === 'TEXTAREA') tf[k] = n.value;
      });
    }

    wrap.querySelector('[data-tf-back]').addEventListener('click', function () {
      if (tf.mode === 'new') { state.view = { name: 'list' }; loadTickets(root); }
      else openDetail(root, tf.id);
    });

    wrap.querySelectorAll('select[data-k]').forEach(function (node) {
      node.addEventListener('change', async function () {
        syncText();
        var k = node.getAttribute('data-k');
        tf[k] = node.value;
        try {
          if (k === 'district_id') {
            tf.thana_id = ''; tf.route_id = ''; tf.service_center_id = '';
            tf.thanas = []; tf.routes = []; tf.centers = [];
            if (node.value) {
              var d = await req('lookups/thanas?district_id=' + encodeURIComponent(node.value));
              tf.thanas = d.items || [];
            }
            renderTicketForm(root);
          } else if (k === 'thana_id') {
            tf.route_id = ''; tf.service_center_id = '';
            tf.routes = []; tf.centers = [];
            if (node.value) {
              var r = await req('lookups/routes?thana_id=' + encodeURIComponent(node.value));
              tf.routes = r.items || [];
            }
            renderTicketForm(root);
          } else if (k === 'route_id') {
            tf.service_center_id = '';
            tf.centers = [];
            if (node.value) {
              var s = await req('lookups/service-centers?route_id=' + encodeURIComponent(node.value));
              tf.centers = s.items || [];
              if (tf.centers.length === 1) tf.service_center_id = String(tf.centers[0].id);
            }
            renderTicketForm(root);
          } else if (k === 'brand') {
            tf.product_id = ''; tf.problem_type_id = ''; tf.problems = [];
            renderTicketForm(root);
          } else if (k === 'product_id') {
            tf.problem_type_id = ''; tf.problems = [];
            if (node.value) {
              var p = await req('lookups/problem-types?product_id=' + encodeURIComponent(node.value));
              tf.problems = p.items || [];
            }
            renderTicketForm(root);
          } else {
            tf[node.getAttribute('data-k')] = node.value;
          }
        } catch (err) { showError(root, err); }
      });
    });
    wrap.querySelectorAll('input[data-k], textarea[data-k]').forEach(function (n) {
      n.addEventListener('input', function () { tf[n.getAttribute('data-k')] = n.value; });
    });

    wrap.querySelector('form').addEventListener('submit', async function (ev) {
      ev.preventDefault();
      syncText();
      wrap.querySelectorAll('select[data-k]').forEach(function (n) { tf[n.getAttribute('data-k')] = n.value; });
      var btn = wrap.querySelector('button[type="submit"]');
      btn.disabled = true;
      try {
        if (tf.mode === 'new') {
          var created = await req('tickets', {
            method: 'POST',
            body: {
              customer_name: tf.customer_name, mobile: tf.mobile, alternative_mobile: tf.alternative_mobile,
              district_id: tf.district_id, thana_id: tf.thana_id, route_id: tf.route_id,
              service_center_id: tf.service_center_id, address: tf.address,
              product_id: tf.product_id, problem_type_id: tf.problem_type_id,
              barcode: tf.barcode, comments: tf.comments
            }
          });
          state.tf = null;
          openDetail(root, created.ticket.id);
        } else {
          var updated = await req('tickets/' + tf.id, {
            method: 'PATCH',
            body: {
              customer_name: tf.customer_name, mobile: tf.mobile, alternative_mobile: tf.alternative_mobile || '',
              district_id: Number(tf.district_id), thana_id: Number(tf.thana_id),
              route_id: Number(tf.route_id), service_center_id: Number(tf.service_center_id),
              address: tf.address, product_id: Number(tf.product_id),
              problem_type_id: Number(tf.problem_type_id),
              barcode: tf.barcode || '', comments: tf.comments || ''
            }
          });
          state.detail = updated.ticket;
          state.tf = null;
          openDetail(root, updated.ticket.id);
        }
      } catch (err) {
        btn.disabled = false;
        tf.errors = (err.details && err.details.fields) || { general: err.message };
        renderTicketForm(root);
        showError(root, err);
      }
    });
  }

  // ---------------------------------------------------------------- dashboard
  async function renderDashboard(root) {
    var wrap = root.querySelector('[data-view]');
    wrap.innerHTML = skeleton();
    try {
      state.stats = await req('admin/stats');
      var s = state.stats;
      var cards = Object.keys(s.by_status || {}).map(function (k) {
        return '<div class="wpsd-card"><p class="wpsd-stat-label">' + esc(k) + '</p><p class="wpsd-stat">' + esc(String(s.by_status[k])) + '</p></div>';
      }).join('');
      wrap.innerHTML = '<div class="wpsd-grid"><div class="wpsd-card"><p class="wpsd-stat-label">Total tickets</p><p class="wpsd-stat">' + esc(String(s.total)) + '</p></div>' +
        cards + '<div class="wpsd-card"><p class="wpsd-stat-label">Avg resolution (hrs)</p><p class="wpsd-stat">' + esc(s.avg_resolution_hours == null ? '—' : String(s.avg_resolution_hours)) + '</p></div></div>';
    } catch (e) { wrap.innerHTML = '<p class="wpsd-error">' + esc(e.message) + '</p>'; }
  }

  // ---------------------------------------------------------------- lookups
  async function renderLookups(root) {
    var wrap = root.querySelector('[data-view]');
    wrap.innerHTML = '<div class="wpsd-flex wpsd-gap-2"><select class="wpsd-input" data-l-type aria-label="Lookup table">' +
      LOOKUP_TYPES.map(function (t) {
        return '<option' + (state.lookupType === t ? ' selected' : '') + '>' + t + '</option>';
      }).join('') + '</select><button class="wpsd-btn wpsd-btn-primary" data-l-add>' + ICONS.plus + 'Add</button></div>' +
      '<p data-err class="wpsd-error" aria-live="polite"></p><div data-l-form></div><div data-l-rows">' + skeleton() + '</div>';
    wrap.querySelector('[data-l-type]').addEventListener('change', function (e) {
      state.lookupType = e.target.value;
      state.lookupEditing = null;
      renderLookups(root);
    });
    wrap.querySelector('[data-l-add]').addEventListener('click', function () {      state.lookupEditing = 'new';
      state.lookupForm = defaultLookupForm(state.lookupType);
      renderLookupForm(root);
    });
    await loadLookupRows(root);
  }

  function defaultLookupForm(type) {
    var f = {};
    var fields = lookupFormFields(type);
    fields.forEach(function (fd) { f[fd.key] = fd.kind === 'bool' ? 1 : ''; });
    return f;
  }

  function lookupFormFields(type) {
    if (type === 'districts') return [{ key: 'name', label: 'Name *', kind: 'text' }];
    if (type === 'thanas') return [
      { key: 'district_id', label: 'District *', kind: 'parent', parent: 'districts' },
      { key: 'name', label: 'Thana *', kind: 'text' }
    ];
    if (type === 'routes') return [
      { key: 'thana_id', label: 'Thana *', kind: 'parent', parent: 'thanas' },
      { key: 'name', label: 'Route *', kind: 'text' }
    ];
    if (type === 'service-centers') return [
      { key: 'route_id', label: 'Route *', kind: 'parent', parent: 'routes' },
      { key: 'name', label: 'Center *', kind: 'text' },
      { key: 'address', label: 'Address', kind: 'text' },
      { key: 'contact_phone', label: 'Phone', kind: 'text' }
    ];
    if (type === 'products') return [
      { key: 'brand', label: 'Brand *', kind: 'text' },
      { key: 'model_name', label: 'Model *', kind: 'text' },
      { key: 'category', label: 'Category *', kind: 'text' },
      { key: 'is_active', label: 'Active', kind: 'bool' }
    ];
    return [
      { key: 'product_category', label: 'Category (empty = global)', kind: 'text' },
      { key: 'label', label: 'Problem *', kind: 'text' },
      { key: 'is_active', label: 'Active', kind: 'bool' }
    ];
  }

  async function loadLookupRows(root) {
    var rowsBox = root.querySelector('[data-l-rows]');
    try {
      var data = await req('admin/lookups/' + state.lookupType);
      var rows = data.items || [];
      if (state.lookupType === 'thanas' || state.lookupType === 'routes' || state.lookupType === 'service-centers') {
        var d = await req('admin/lookups/districts').catch(function () { return { items: [] }; });
        var th = await req('admin/lookups/thanas').catch(function () { return { items: [] }; });
        var ro = await req('admin/lookups/routes').catch(function () { return { items: [] }; });
        state.lookupParents = { districts: d.items || [], thanas: th.items || [], routes: ro.items || [] };
        var dById = {}, tById = {}, rById = {};
        state.lookupParents.districts.forEach(function (x) { dById[x.id] = x.name; });
        state.lookupParents.thanas.forEach(function (x) { tById[x.id] = x.name + ' (' + (dById[x.district_id] || '') + ')'; });
        state.lookupParents.routes.forEach(function (x) { rById[x.id] = x.name; });
        rows = rows.map(function (row) {
          var copy = Object.assign({}, row);
          copy.district_name = dById[row.district_id] || ('#' + row.district_id);
          copy.thana_name = tById[row.thana_id] || ('#' + row.thana_id);
          copy.route_name = rById[row.route_id] || ('#' + row.route_id);
          return copy;
        });
      }
      state.lookups = rows;
    } catch (e) { rowsBox.innerHTML = '<p class="wpsd-error">' + esc(e.message) + '</p>'; return; }
    renderLookupTable(root);
    if (state.lookupEditing !== null) renderLookupForm(root);
  }

  function renderLookupTable(root) {
    var rowsBox = root.querySelector('[data-l-rows]');
    if (!rowsBox) return;
    var cols = LOOKUP_COLS[state.lookupType];
    var html = '<table class="wpsd-table"><thead><tr>' +
      cols.map(function (c) { return '<th>' + esc(c[1]) + '</th>'; }).join('') +
      '<th>Actions</th></tr></thead><tbody>';
    if (!state.lookups.length) html += '<tr><td colspan="' + (cols.length + 1) + '">No records.</td></tr>';
    state.lookups.forEach(function (r) {
      html += '<tr>' + cols.map(function (c) { return '<td>' + fmtCell(state.lookupType, c[0], r) + '</td>'; }).join('') +
        '<td><button class="wpsd-btn wpsd-btn-sm" data-l-edit="' + r.id + '">Edit</button> ' +
        '<button class="wpsd-btn wpsd-btn-sm wpsd-btn-destructive" data-l-del="' + r.id + '">Delete</button></td></tr>';
    });
    html += '</tbody></table>';
    rowsBox.innerHTML = html;
    rowsBox.querySelectorAll('[data-l-edit]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = parseInt(b.getAttribute('data-l-edit'), 10);
        var row = state.lookups.filter(function (x) { return x.id === id; })[0];
        if (!row) return;
        state.lookupEditing = id;
        state.lookupForm = Object.assign({}, row);
        delete state.lookupForm.district_name;
        delete state.lookupForm.thana_name;
        delete state.lookupForm.route_name;
        renderLookupForm(root);
      });
    });
    rowsBox.querySelectorAll('[data-l-del]').forEach(function (b) {
      b.addEventListener('click', async function () {
        if (!window.confirm('Delete? Blocked when used by tickets.')) return;
        try { await req('admin/lookups/' + state.lookupType + '/' + b.getAttribute('data-l-del'), { method: 'DELETE' }); await loadLookupRows(root); }
        catch (e) { showError(root, e); }
      });
    });
  }

  function parentLabel(parent, p) {
    if (parent === 'thanas') {
      var dById = {};
      state.lookupParents.districts.forEach(function (x) { dById[x.id] = x.name; });
      return p.name + (p.district_id ? ' (' + (dById[p.district_id] || '') + ')' : '');
    }
    return p.name;
  }

  function renderLookupForm(root) {
    var box = root.querySelector('[data-l-form]');
    if (!box || state.lookupEditing === null) { if (box) box.innerHTML = ''; return; }
    var fields = lookupFormFields(state.lookupType);
    var f = state.lookupForm;
    var html = '<form class="wpsd-card wpsd-flex wpsd-flex-col wpsd-gap-2"><h3>' +
      (state.lookupEditing === 'new' ? 'Add ' + esc(state.lookupType) : 'Edit ' + esc(state.lookupType) + ' #' + state.lookupEditing) + '</h3>';
    fields.forEach(function (fd) {
      html += '<label class="wpsd-field"><span>' + esc(fd.label) + '</span>';
      if (fd.kind === 'parent') {
        html += '<select class="wpsd-input" data-lf="' + fd.key + '"><option value="">Select…</option>' +
          (state.lookupParents[fd.parent] || []).map(function (p) {
            return '<option value="' + p.id + '"' + (String(f[fd.key]) === String(p.id) ? ' selected' : '') + '>' + esc(parentLabel(fd.parent, p)) + '</option>';
          }).join('') + '</select>';
      } else if (fd.kind === 'bool') {
        html += '<input type="checkbox" data-lf="' + fd.key + '"' + (Number(f[fd.key]) ? ' checked' : '') + ' />';
      } else {
        html += '<input class="wpsd-input" data-lf="' + fd.key + '" value="' + esc(f[fd.key] == null ? '' : f[fd.key]) + '" />';
      }
      html += '</label>';
    });
    html += '<div class="wpsd-flex wpsd-gap-2"><button class="wpsd-btn wpsd-btn-primary" type="submit">Save</button>' +
      '<button class="wpsd-btn" type="button" data-l-cancel>Cancel</button></div></form>';
    box.innerHTML = html;
    box.querySelector('[data-l-cancel]').addEventListener('click', function () {
      state.lookupEditing = null;
      box.innerHTML = '';
    });
    box.querySelector('form').addEventListener('submit', async function (ev) {
      ev.preventDefault();
      var payload = {};
      fields.forEach(function (fd) {
        var node = box.querySelector('[data-lf="' + fd.key + '"]');
        if (fd.kind === 'bool') payload[fd.key] = node.checked ? 1 : 0;
        else if (fd.kind === 'parent') payload[fd.key] = Number(node.value);
        else payload[fd.key] = node.value;
      });
      try {
        if (state.lookupEditing === 'new') await req('admin/lookups/' + state.lookupType, { method: 'POST', body: payload });
        else await req('admin/lookups/' + state.lookupType + '/' + state.lookupEditing, { method: 'PUT', body: payload });
        state.lookupEditing = null;
        box.innerHTML = '';
        await loadLookupRows(root);
      } catch (e) { showError(root, e); }
    });
  }

  // ---------------------------------------------------------------- clients
  async function renderClients(root) {
    var wrap = root.querySelector('[data-view]');
    wrap.innerHTML = '<div class="wpsd-card wpsd-flex wpsd-gap-2"><input class="wpsd-input" data-c-name placeholder="Client name" />' +
      '<select class="wpsd-input" data-c-scope><option value="create_only">create_only</option><option value="create_and_read">create_and_read</option><option value="full">full</option></select>' +
      '<button class="wpsd-btn wpsd-btn-primary" data-c-go>Generate key</button></div>' +
      '<div data-c-once></div><div data-c-rows">' + skeleton() + '</div><p data-err class="wpsd-error" aria-live="polite"></p>';
    wrap.querySelector('[data-c-go]').addEventListener('click', async function () {
      try {
        var d = await req('admin/api-clients', { method: 'POST', body: { client_name: wrap.querySelector('[data-c-name]').value, scope: wrap.querySelector('[data-c-scope]').value } });
        wrap.querySelector('[data-c-once]').innerHTML = '<div class="wpsd-card"><p><strong>Copy the secret now — shown once.</strong></p><p>Key: <code>' + esc(d.api_key) + '</code></p><p>Secret: <code>' + esc(d.api_secret) + '</code></p></div>';
        renderClients(root);
      } catch (e) { showError(root, e); }
    });
    try {
      var data = await req('admin/api-clients');
      var clients = data.items || [];
      wrap.querySelector('[data-c-rows]').innerHTML = '<table class="wpsd-table"><thead><tr><th>Client</th><th>Scope</th><th>Rate/min</th><th>Last used</th><th></th></tr></thead><tbody>' +
        clients.map(function (cRow) {
          return '<tr><td>' + esc(cRow.client_name) + '</td><td>' + esc(cRow.scope) + '</td><td>' + esc(String(cRow.rate_limit_per_minute)) + '</td><td>' + esc(cRow.last_used_at || '—') + '</td>' +
            '<td><button class="wpsd-btn wpsd-btn-sm" data-c-rot="' + cRow.id + '">Rotate</button> <button class="wpsd-btn wpsd-btn-sm wpsd-btn-destructive" data-c-rev="' + cRow.id + '">Revoke</button></td></tr>';
        }).join('') + '</tbody></table>';
      wrap.querySelectorAll('[data-c-rot]').forEach(function (b) {
        b.addEventListener('click', async function () {
          try {
            var dd = await req('admin/api-clients/' + b.getAttribute('data-c-rot'), { method: 'POST', body: { action: 'rotate' } });
            wrap.querySelector('[data-c-once]').innerHTML = '<div class="wpsd-card"><p><strong>New secret (shown once):</strong></p><p>Key: <code>' + esc(dd.api_key) + '</code></p><p>Secret: <code>' + esc(dd.api_secret) + '</code></p></div>';
          } catch (e) { showError(root, e); }
        });
      });
      wrap.querySelectorAll('[data-c-rev]').forEach(function (b) {
        b.addEventListener('click', async function () {
          if (!window.confirm('Revoke this client?')) return;
          try { await req('admin/api-clients/' + b.getAttribute('data-c-rev'), { method: 'DELETE' }); renderClients(root); }
          catch (e) { showError(root, e); }
        });
      });
    } catch (e) { wrap.querySelector('[data-c-rows]').innerHTML = '<p class="wpsd-error">' + esc(e.message) + '</p>'; }
  }

  // ---------------------------------------------------------------- shell
  function render(root) {
    var summary = root.querySelector('.wpsd-noscript-summary');
    if (summary) summary.style.display = 'none';
    var view = root.querySelector('[data-view]');
    if (!view) {
      view = document.createElement('div');
      view.setAttribute('data-view', '');
      root.appendChild(view);
    }
    if (!root.querySelector('nav[role="tablist"]')) {
      root.insertBefore(toolbar(root), view);
    } else {
      root.querySelector('nav[role="tablist"]').replaceWith(toolbar(root));
    }
    if (state.tab === 'dashboard') renderDashboard(root);
    else if (state.tab === 'tickets') {
      if (state.view.name === 'new' || state.view.name === 'edit') {
        if (!state.tf) {
          startTicketForm(root, state.view.name === 'new' ? 'new' : state.view.id);
        } else renderTicketForm(root);
      }
      else if (state.view.name === 'detail' && state.detail) renderDetail(root);
      else loadTickets(root);
    }
    else if (state.tab === 'lookups') renderLookups(root);
    else renderClients(root);
  }

  function mount() {
    var root = document.getElementById('wpsd-admin-root');
    if (!root) return;
    render(root);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
