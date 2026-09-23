/* Affiniti WP Support — public bundle (compiled output; rebuild with `npm run build`). */
(function () {
  'use strict';

  var ICONS = {
    back: '<svg class="wpsd-icon" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>',
    check: '<svg class="wpsd-icon" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>'
  };

  var STATUS_BADGE = {
    new: 'wpsd-b-blue', assigned: 'wpsd-b-violet', in_progress: 'wpsd-b-amber',
    resolved: 'wpsd-b-green', closed: 'wpsd-b-gray', cancelled: 'wpsd-b-red'
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
      var map = { '&': '&', '<': '<', '>': '>', '"': '"', "'": "'" };
      return map[m];
    });
  }
  function statusBadge(s) {
    return '<span class="wpsd-badge ' + (STATUS_BADGE[s] || 'wpsd-b-gray') + '">' + esc(s) + '</span>';
  }
  function skeleton() {
    return '<div class="wpsd-skeleton" aria-live="polite"><div class="wpsd-skeleton-line"></div><div class="wpsd-skeleton-line"></div><div class="wpsd-skeleton-line short"></div></div>';
  }
  async function getJSON(url) {
    var res = await fetch(url);
    return res.json();
  }

  function pubCfg(root) {
    if (window.WPSD_PUBLIC_CONFIG) return window.WPSD_PUBLIC_CONFIG;
    return { restUrl: root.getAttribute('data-rest-url') || '/wp-json/wpsd/v1/', nonce: root.getAttribute('data-nonce') || '' };
  }

  function options(items, valKey, labelFn, selected, placeholder) {
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

  function mountForm(root) {
    var c = pubCfg(root);
    var base = c.restUrl;
    var st = {
      districts: [], thanas: [], routes: [], centers: [], products: [], brands: [], problems: [],
      form: { customer_name: '', mobile: '', alternative_mobile: '', district_id: '', thana_id: '', route_id: '', service_center_id: '', address: '', brand: '', product_id: '', barcode: '', problem_type_id: '', comments: '' },
      errors: {}
    };

    function render() {
      var f = st.form;
      var e = st.errors;
      var visibleProducts = f.brand ? st.products.filter(function (p) { return String(p.brand) === String(f.brand); }) : st.products;
      root.innerHTML =
        '<form class="wpsd-form" novalidate>' +
        '<div class="wpsd-card"><div class="wpsd-card-header"><h3 class="wpsd-card-title">Basic information</h3>' +
        '<p class="wpsd-card-desc">Your contact details and the service center location.</p></div><div class="wpsd-card-content wpsd-form-grid">' +
        (e.general ? '<p class="wpsd-alert wpsd-alert-error wpsd-span-2">' + esc(e.general) + '</p>' : '') +
        tfField('Name *', '<input class="wpsd-input" data-k="customer_name" maxlength="150" value="' + esc(f.customer_name) + '">', e.customer_name) +
        tfField('Mobile *', '<input class="wpsd-input" data-k="mobile" inputmode="tel" placeholder="01712345678" value="' + esc(f.mobile) + '">', e.mobile) +
        tfField('Alternative mobile', '<input class="wpsd-input" data-k="alternative_mobile" inputmode="tel" value="' + esc(f.alternative_mobile) + '">', e.alternative_mobile) +
        tfField('District *', '<select class="wpsd-input" data-k="district_id">' + options(st.districts, 'id', function (d) { return d.name; }, f.district_id, 'Select district') + '</select>', e.district_id) +
        tfField('Thana *', '<select class="wpsd-input" data-k="thana_id"' + (f.district_id ? '' : ' disabled') + '>' + options(st.thanas, 'id', function (d) { return d.name; }, f.thana_id, 'Select thana') + '</select>', e.thana_id) +
        tfField('Route *', '<select class="wpsd-input" data-k="route_id"' + (f.thana_id ? '' : ' disabled') + '>' + options(st.routes, 'id', function (d) { return d.name; }, f.route_id, 'Select route') + '</select>', e.route_id) +
        tfField('Service center *', '<select class="wpsd-input" data-k="service_center_id"' + (f.route_id ? '' : ' disabled') + '>' + options(st.centers, 'id', function (d) { return d.name; }, f.service_center_id, 'Select service center') + '</select>', e.service_center_id) +
        tfField('Address (House/Road) * — max 50 chars', '<input class="wpsd-input" data-k="address" maxlength="50" value="' + esc(f.address) + '">', e.address) +
        '</div></div>' +
        '<div class="wpsd-card"><div class="wpsd-card-header"><h3 class="wpsd-card-title">Incident information</h3>' +
        '<p class="wpsd-card-desc">Product, barcode, and the problem you are experiencing.</p></div><div class="wpsd-card-content wpsd-form-grid">' +
        (e.product ? '<p class="wpsd-alert wpsd-alert-error wpsd-span-2">' + esc(e.product) + '</p>' : '') +
        tfField('Brand *', '<select class="wpsd-input" data-k="brand"><option value="">Select brand</option>' +
          st.brands.map(function (b) { return '<option' + (String(f.brand) === String(b) ? ' selected' : '') + '>' + esc(b) + '</option>'; }).join('') + '</select>', e.product_id) +
        tfField('Product *', '<select class="wpsd-input" data-k="product_id"' + (f.brand ? '' : ' disabled') + '>' +
          options(visibleProducts, 'id', function (p) { return p.brand + ' — ' + p.model_name; }, f.product_id, 'Select product') + '</select>', e.product_id) +
        tfField('Barcode (optional)', '<input class="wpsd-input" data-k="barcode" maxlength="120" value="' + esc(f.barcode) + '">', e.barcode) +
        tfField('Select your problem *', '<select class="wpsd-input" data-k="problem_type_id"' + (f.product_id ? '' : ' disabled') + '>' +
          options(st.problems, 'id', function (p) { return p.label; }, f.problem_type_id, 'Select problem') + '</select>', e.problem_type_id) +
        tfField('Comments (optional)', '<textarea class="wpsd-input" data-k="comments" rows="4" maxlength="2000">' + esc(f.comments) + '</textarea>', e.comments) +
        '</div></div>' +
        '<button class="wpsd-btn wpsd-btn-primary wpsd-btn-block" type="submit">Submit request</button></form>';

      root.querySelectorAll('[data-k]').forEach(function (node) {
        node.addEventListener('change', function () { onChange(node.getAttribute('data-k'), node.value); });
        if (node.tagName === 'INPUT' || node.tagName === 'TEXTAREA') {
          node.addEventListener('input', function () { st.form[node.getAttribute('data-k')] = node.value; });
        }
      });
      root.querySelector('form').addEventListener('submit', submit);
    }

    async function onChange(k, v) {
      st.form[k] = v;
      if (k === 'district_id') {
        st.form.thana_id = ''; st.form.route_id = ''; st.form.service_center_id = '';
        st.thanas = []; st.routes = []; st.centers = [];
        if (v) {
          try { var d = await getJSON(base + 'lookups/thanas?district_id=' + encodeURIComponent(v)); st.thanas = (d.data && d.data.items) || d.items || []; } catch (err) { /* noop */ }
        }
        render();
      } else if (k === 'thana_id') {
        st.form.route_id = ''; st.form.service_center_id = '';
        st.routes = []; st.centers = [];
        if (v) {
          try { var r = await getJSON(base + 'lookups/routes?thana_id=' + encodeURIComponent(v)); st.routes = (r.data && r.data.items) || r.items || []; } catch (err) { /* noop */ }
        }
        render();
      } else if (k === 'route_id') {
        st.form.service_center_id = '';
        st.centers = [];
        if (v) {
          try {
            var s = await getJSON(base + 'lookups/service-centers?route_id=' + encodeURIComponent(v));
            st.centers = (s.data && s.data.items) || s.items || [];
            if (st.centers.length === 1) st.form.service_center_id = String(st.centers[0].id);
          } catch (err) { /* noop */ }
        }
        render();
      } else if (k === 'brand') {
        st.form.product_id = ''; st.form.problem_type_id = ''; st.problems = [];
        render();
      } else if (k === 'product_id') {
        st.form.problem_type_id = ''; st.problems = [];
        if (v) {
          try { var p = await getJSON(base + 'lookups/problem-types?product_id=' + encodeURIComponent(v)); st.problems = (p.data && p.data.items) || p.items || []; } catch (err) { /* noop */ }
        }
        render();
      }
    }

    async function submit(ev) {
      ev.preventDefault();
      root.querySelectorAll('[data-k]').forEach(function (n) { st.form[n.getAttribute('data-k')] = n.value; });
      var btn = root.querySelector('button[type="submit"]');
      btn.disabled = true;
      btn.textContent = 'Submitting…';
      try {
        var payload = Object.assign({}, st.form, { wpsd_nonce: c.nonce });
        var res = await fetch(base + 'tickets', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        var json = await res.json();
        if (!res.ok || json.success === false) {
          var fields = (json && json.data && json.data.fields) || {};
          st.errors = fields;
          if (!Object.keys(fields).length) st.errors = { general: (json.error && json.error.message) || 'Submission failed.' };
          render();
          btn.disabled = false;
          btn.textContent = 'Submit request';
        } else {
          var t = json.data.ticket;
          root.innerHTML =
            '<div class="wpsd-card wpsd-success" role="status">' +
            '<div class="wpsd-check">' + ICONS.check + '</div>' +
            '<h3>Request received</h3>' +
            '<p>Your ticket number is</p>' +
            '<div class="wpsd-ticket-no">' + esc(t.ticket_number) + '</div>' +
            '<p class="wpsd-card-desc">Save it to check the status later.</p></div>';
        }
      } catch (err) {
        st.errors = { general: 'Network error. Please try again.' };
        btn.disabled = false;
        btn.textContent = 'Submit request';
        render();
      }
    }

    render();
    root.innerHTML = skeleton();
    getJSON(base + 'lookups/districts').then(function (d) { st.districts = (d.data && d.data.items) || d.items || []; render(); }).catch(function () {});
    getJSON(base + 'lookups/products').then(function (d) {
      var data = d.data || d;
      st.products = data.items || []; st.brands = data.brands || []; render();
    }).catch(function () {});
  }

  function mountLookup(root) {
    var c = pubCfg(root);
    root.innerHTML =
      '<div class="wpsd-card"><div class="wpsd-card-header"><h3 class="wpsd-card-title">Check ticket status</h3>' +
      '<p class="wpsd-card-desc">Enter your ticket number and the mobile used when submitting.</p></div>' +
      '<form class="wpsd-form" novalidate>' +
      tfField('Ticket number', '<input class="wpsd-input" data-l-num placeholder="TKT-2026-00123">', null) +
      tfField('Mobile used on the ticket', '<input class="wpsd-input" data-l-mob inputmode="tel">', null) +
      '<button class="wpsd-btn wpsd-btn-primary wpsd-btn-block" type="submit">Check status</button></form>' +
      '<div data-l-out aria-live="polite"></div></div>';

    root.querySelector('form').addEventListener('submit', async function (ev) {
      ev.preventDefault();
      var out = root.querySelector('[data-l-out]');
      out.innerHTML = '<p class="wpsd-loading">Checking…</p>';
      try {
        var num = root.querySelector('[data-l-num]').value;
        var mob = root.querySelector('[data-l-mob]').value;
        var res = await fetch(c.restUrl + 'tickets/' + encodeURIComponent(num) + '?mobile=' + encodeURIComponent(mob));
        var json = await res.json();
        if (!res.ok || json.success === false) throw new Error((json.error && json.error.message) || 'Lookup failed.');
        var t = json.data.ticket;
        out.innerHTML =
          '<div class="wpsd-card wpsd-success" role="status">' +
          '<div class="wpsd-check">' + ICONS.check + '</div>' +
          '<div class="wpsd-status-row">' +
          '<span class="wpsd-ticket-no">' + esc(t.ticket_number) + '</span>' +
          statusBadge(t.status) +
          '</div>' +
          (t.problem_label ? '<p class="wpsd-muted" style="margin-top:8px;font-size:14px">' + esc(t.problem_label) + '</p>' : '') +
          '<p class="wpsd-card-desc" style="margin-top:12px">You will receive SMS updates on status changes.</p></div>';
      } catch (err) {
        out.innerHTML = '<p class="wpsd-alert wpsd-alert-error">' + esc(err.message) + '</p>';
      }
    });
  }

  function mount() {
    var form = document.getElementById('wpsd-public-root');
    if (form) mountForm(form);
    var lookup = document.getElementById('wpsd-lookup-root');
    if (lookup) mountLookup(lookup);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();