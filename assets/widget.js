/**
 * Dinia Booking Widget – 5-Schritt Buchungs-Wizard
 * Vanilla JS (kein jQuery, keine Dependencies)
 */
(function () {
  'use strict';

  var state = {
    step: 1,
    date: '',
    party_size: 2,
    selected_slot: null,
    name: '',
    email: '',
    phone: '',
    notes: ''
  };

  var tenant, apiBase, clientMode, apiKey;

  // Nächsten 7 Tage als Datums-Buttons anzeigen
  var today = new Date();
  today.setHours(0, 0, 0, 0);

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function formatDate(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function formatDateLong(d) {
    return d.toLocaleDateString('de-DE', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  }

  function fetchWithKey(url, options) {
    options = options || {};
    options.headers = options.headers || {};
    if (apiKey) {
      options.headers['X-API-Key'] = apiKey;
    }
    return fetch(url, options);
  }

  function init() {
    // Tenant ermitteln
    tenant = (typeof DINIA_TENANT !== 'undefined') ? DINIA_TENANT : null;
    if (!tenant) {
      var scripts = document.getElementsByTagName('script');
      for (var i = 0; i < scripts.length; i++) {
        if (scripts[i].src && scripts[i].src.indexOf('widget.js') > -1) {
          tenant = scripts[i].getAttribute('data-tenant');
          break;
        }
      }
    }
    if (!tenant) {
      console.error('[Dinia] DINIA_TENANT oder data-tenant fehlt.');
      return;
    }

    apiBase = (typeof DINIA_API_BASE !== 'undefined') ? DINIA_API_BASE : '/wp-json/dinia/v1/widget/' + encodeURIComponent(tenant);
    apiKey = (typeof DINIA_API_KEY !== 'undefined') ? DINIA_API_KEY : null;
    clientMode = (typeof DINIA_CLIENT_MODE !== 'undefined') ? DINIA_CLIENT_MODE : false;

    // In client mode, use /client/ endpoints without slug in URL
    if (clientMode && !apiKey) {
      console.error('[Dinia] DINIA_CLIENT_MODE erfordert DINIA_API_KEY.');
      return;
    }
    if (clientMode) {
      // apiBase must point to /wp-json/dinia/v1/client (no trailing slash)
      if (apiBase === '/wp-json/dinia/v1/widget/' + encodeURIComponent(tenant)) {
        apiBase = '/wp-json/dinia/v1/client';
      }
    }

    var container = document.getElementById('dinia-widget');
    if (!container) {
      console.error('[Dinia] #dinia-widget nicht gefunden.');
      return;
    }

    container.innerHTML = buildHTML();
    state.date = formatDate(today);
    bindEvents();
    goToStep(1);

    // Restaurant-Name aus Config laden
    fetchWithKey(apiBase + '/config')
      .then(function (r) { return r.json(); })
      .then(function (cfg) {
        var name = (cfg.settings && cfg.settings.restaurant_name) || cfg.restaurant_name || 'Restaurant';
        var h = container.querySelector('.dinia-header h2');
        if (h) h.textContent = name;
      })
      .catch(function () {});
  }

  function buildHTML() {
    return '' +
      '<div class="dinia-wrapper">' +
        '<div class="dinia-header">' +
          '<h2>Restaurant</h2>' +
          '<p>Online Reservierung</p>' +
        '</div>' +
        '<div class="dinia-steps" id="dinia-steps">' +
          '<div class="dinia-step active" data-step="1"><span>1</span> Datum</div>' +
          '<div class="dinia-step" data-step="2"><span>2</span> Personen</div>' +
          '<div class="dinia-step" data-step="3"><span>3</span> Uhrzeit</div>' +
          '<div class="dinia-step" data-step="4"><span>4</span> Daten</div>' +
          '<div class="dinia-step" data-step="5"><span>5</span> Bestätigung</div>' +
        '</div>' +
        '<div class="dinia-body">' +

          '<div class="dinia-panel active" id="dinia-step-1">' +
            '<h3>Wählen Sie ein Datum</h3>' +
            '<p style="margin-bottom:16px;color:#666;">Bitte wählen Sie Ihr Wunschdatum für die Reservierung.</p>' +
            '<input type="date" id="dinia-date" class="dinia-date-input" min="' + formatDate(today) + '">' +
            '<div class="dinia-nav dinia-nav-right"><button type="button" class="dinia-btn dinia-btn-next" data-next="2">Weiter →</button></div>' +
          '</div>' +

          '<div class="dinia-panel" id="dinia-step-2">' +
            '<h3>Für wie viele Personen?</h3>' +
            '<select id="dinia-party-size" class="dinia-select">' +
              '<option value="1">1 Person</option>' +
              '<option value="2" selected>2 Personen</option>' +
              '<option value="3">3 Personen</option>' +
              '<option value="4">4 Personen</option>' +
              '<option value="5">5 Personen</option>' +
              '<option value="6">6 Personen</option>' +
              '<option value="7">7 Personen</option>' +
              '<option value="8">8+ Personen</option>' +
            '</select>' +
            '<div class="dinia-nav"><button type="button" class="dinia-btn dinia-btn-back" data-prev="1">← Zurück</button><button type="button" class="dinia-btn dinia-btn-next" data-next="3">Weiter →</button></div>' +
          '</div>' +

          '<div class="dinia-panel" id="dinia-step-3">' +
            '<h3>Verfügbare Zeiten</h3>' +
            '<div id="dinia-slots-loading" class="dinia-loading" style="display:none;">Lade verfügbare Zeiten …</div>' +
            '<div id="dinia-slots-list" class="dinia-slots-grid"></div>' +
            '<div id="dinia-slots-empty" class="dinia-empty-msg" style="display:none;">Leider keine freien Tische für dieses Datum und diese Personenanzahl.</div>' +
            '<div id="dinia-slots-error" class="dinia-error-msg" style="display:none;"></div>' +
            '<div class="dinia-nav"><button type="button" class="dinia-btn dinia-btn-back" data-prev="2">← Zurück</button></div>' +
          '</div>' +

          '<div class="dinia-panel" id="dinia-step-4">' +
            '<h3>Ihre Kontaktdaten</h3>' +
            '<div class="dinia-summary-box" id="dinia-summary"></div>' +
            '<div class="dinia-form-group"><label for="dinia-name">Name *</label><input type="text" id="dinia-name" placeholder="Vor- und Nachname"></div>' +
            '<div class="dinia-form-group"><label for="dinia-email">E-Mail *</label><input type="email" id="dinia-email" placeholder="ihre@email.de"></div>' +
            '<div class="dinia-form-group"><label for="dinia-phone">Telefon *</label><input type="tel" id="dinia-phone" placeholder="+49 ..."></div>' +
            '<div class="dinia-form-group"><label for="dinia-notes">Bemerkung (optional)</label><textarea id="dinia-notes" rows="3" placeholder="Allergien, Wünsche …"></textarea></div>' +
            '<div class="dinia-nav"><button type="button" class="dinia-btn dinia-btn-back" data-prev="3">← Zurück</button><button type="button" class="dinia-btn dinia-btn-next" data-next="5">Weiter →</button></div>' +
          '</div>' +

          '<div class="dinia-panel" id="dinia-step-5">' +
            '<h3>Reservierung prüfen</h3>' +
            '<div class="dinia-confirm-box" id="dinia-confirm-summary"></div>' +
            '<div id="dinia-booking-error" class="dinia-error-msg" style="display:none;"></div>' +
            '<div class="dinia-nav"><button type="button" class="dinia-btn dinia-btn-back" data-prev="4">← Zurück</button><button type="button" class="dinia-btn dinia-btn-confirm" id="dinia-submit">✓ Reservierung bestätigen</button></div>' +
          '</div>' +

          '<div class="dinia-panel" id="dinia-step-success">' +
            '<div class="dinia-success-icon">✓</div>' +
            '<h3 style="text-align:center;color:#4caf50;">Reservierung bestätigt!</h3>' +
            '<p style="text-align:center;" id="dinia-success-message"></p>' +
            '<p style="text-align:center;color:#999;font-size:14px;">Eine Bestätigungs-E-Mail wurde gesendet.</p>' +
            '<div class="dinia-nav dinia-nav-center"><button type="button" class="dinia-btn dinia-btn-next" onclick="location.reload()">Neue Reservierung</button></div>' +
          '</div>' +

        '</div>' +
        '<div class="dinia-powered">Powered by <a href="https://gofonia.de" target="_blank">GoFonIA</a></div>' +
      '</div>';
  }

  function bindEvents() {
    // Date input change
    document.addEventListener('change', function (e) {
      var dateInput = e.target.closest('#dinia-date');
      if (dateInput) {
        state.date = dateInput.value;
        return;
      }

      // Party size change
      var sel = e.target.closest('#dinia-party-size');
      if (sel) state.party_size = parseInt(sel.value);
    });

    document.addEventListener('click', function (e) {
      var slot = e.target.closest('.dinia-slot-card');
      if (slot) {
        document.querySelectorAll('.dinia-slot-card').forEach(function (s) { s.classList.remove('selected'); });
        slot.classList.add('selected');
        state.selected_slot = {
          start: slot.getAttribute('data-start'),
          end: slot.getAttribute('data-end'),
          table_id: slot.getAttribute('data-table-id'),
          table_ids: slot.getAttribute('data-table-ids') || slot.getAttribute('data-table-id'),
          table_name: slot.getAttribute('data-table-name'),
          seats: slot.getAttribute('data-seats')
        };
        goToStep(4);
        return;
      }

      // Next button
      var nextBtn = e.target.closest('.dinia-btn-next[data-next]');
      if (nextBtn) {
        e.preventDefault();
        var next = parseInt(nextBtn.getAttribute('data-next'));
        if (validateStep(state.step)) goToStep(next);
        return;
      }

      // Back button
      var backBtn = e.target.closest('.dinia-btn-back[data-prev]');
      if (backBtn) {
        e.preventDefault();
        goToStep(parseInt(backBtn.getAttribute('data-prev')));
        return;
      }

      // Submit
      var submitBtn = e.target.closest('#dinia-submit');
      if (submitBtn) {
        e.preventDefault();
        submitBooking();
        return;
      }
    });

    // Party size change
    document.addEventListener('change', function (e) {
      var sel = e.target.closest('#dinia-party-size');
      if (sel) state.party_size = parseInt(sel.value);
    });
  }

  function goToStep(step) {
    state.step = step;

    // Panels
    document.querySelectorAll('.dinia-panel').forEach(function (p) { p.classList.remove('active'); });
    var panel = document.getElementById('dinia-step-' + step);
    if (panel) panel.classList.add('active');

    // Step indicators
    document.querySelectorAll('.dinia-step').forEach(function (s) {
      var num = parseInt(s.getAttribute('data-step'));
      s.classList.remove('active', 'done');
      if (num === step) s.classList.add('active');
      else if (num < step) s.classList.add('done');
    });

    // Step 3: load slots
    if (step === 3) loadSlots();

    // Step 5: show confirmation
    if (step === 5) showConfirmation();

    // Step 4: update summary
    if (step === 4) updateSummary();
  }

  function validateStep(step) {
    if (step === 1) {
      if (!state.date) { alert('Bitte wählen Sie ein Datum.'); return false; }
      return true;
    }
    if (step === 2) {
      state.party_size = parseInt(document.getElementById('dinia-party-size').value);
      return true;
    }
    if (step === 4) {
      state.name = document.getElementById('dinia-name').value.trim();
      state.email = document.getElementById('dinia-email').value.trim();
      state.phone = document.getElementById('dinia-phone').value.trim();
      state.notes = document.getElementById('dinia-notes').value.trim();
      if (!state.name) { alert('Bitte geben Sie Ihren Namen ein.'); return false; }
      if (state.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(state.email)) { alert('Bitte geben Sie eine gültige E-Mail ein oder lassen Sie das Feld leer.'); return false; }
      if (!state.phone) { alert('Bitte geben Sie Ihre Telefonnummer ein.'); return false; }
      return true;
    }
    return true;
  }

  function loadSlots() {
    var list = document.getElementById('dinia-slots-list');
    var loading = document.getElementById('dinia-slots-loading');
    var empty = document.getElementById('dinia-slots-empty');
    var error = document.getElementById('dinia-slots-error');

    list.innerHTML = '';
    empty.style.display = 'none';
    error.style.display = 'none';
    loading.style.display = 'block';

    fetchWithKey(apiBase + '/slots?date=' + encodeURIComponent(state.date) + '&party_size=' + state.party_size)
      .then(function (r) {
        if (!r.ok) throw new Error('Status ' + r.status);
        return r.json();
      })
      .then(function (slots) {
        loading.style.display = 'none';

        if (!slots || slots.length === 0) {
          empty.style.display = 'block';
          return;
        }

        // Nur verfügbare Slots anzeigen
        var available = [];
        // API kann Array (direkte Slots) oder Objekt mit .slots Array sein
        var data = Array.isArray(slots) ? slots : (slots.slots || []);
        data.forEach(function (s) {
          if (s.available !== false) {
            available.push(s);
          }
        });

        if (available.length === 0) {
          empty.style.display = 'block';
          return;
        }

        available.forEach(function (s) {
          var card = document.createElement('div');
          card.className = 'dinia-slot-card';
          card.setAttribute('data-start', s.time);
          card.setAttribute('data-end', s.time_end || '');
          card.setAttribute('data-table-id', s.table_id || '');
          card.setAttribute('data-table-ids', s.table_ids || s.table_id || '');
          card.setAttribute('data-table-name', s.table_name || 'Tisch');
          card.setAttribute('data-seats', s.table_seats || s.party_size || state.party_size);

          var label = s.combined ? 'Kombiniert: ' : '';
          card.innerHTML =
            '<div class="dinia-slot-time">' + s.time + ' – ' + (s.time_end || '') + '</div>' +
            '<div class="dinia-slot-table">' + label + (s.table_name || '') + '</div>';
          list.appendChild(card);
        });
      })
      .catch(function (err) {
        loading.style.display = 'none';
        error.textContent = 'Fehler: ' + err.message;
        error.style.display = 'block';
      });
  }

  function updateSummary() {
    var d = new Date(state.date + 'T00:00:00');
    var dateStr = formatDateLong(d);
    var html =
      '<strong>Datum:</strong> ' + dateStr + '<br>' +
      '<strong>Personen:</strong> ' + state.party_size + '<br>' +
      '<strong>Uhrzeit:</strong> ' + state.selected_slot.start + ' – ' + state.selected_slot.end + '<br>' +
      '<strong>Tisch:</strong> ' + state.selected_slot.table_name;
    document.getElementById('dinia-summary').innerHTML = html;
  }

  function showConfirmation() {
    var d = new Date(state.date + 'T00:00:00');
    var dateStr = formatDateLong(d);
    var html =
      '<strong>Datum:</strong> ' + dateStr + '<br>' +
      '<strong>Uhrzeit:</strong> ' + state.selected_slot.start + ' – ' + state.selected_slot.end + '<br>' +
      '<strong>Tisch:</strong> ' + state.selected_slot.table_name + '<br>' +
      '<strong>Personen:</strong> ' + state.party_size + '<br><br>' +
      '<strong>Name:</strong> ' + state.name + '<br>' +
      '<strong>E-Mail:</strong> ' + state.email + '<br>' +
      '<strong>Telefon:</strong> ' + state.phone + (state.notes ? '<br><strong>Bemerkung:</strong> ' + state.notes : '');
    document.getElementById('dinia-confirm-summary').innerHTML = html;
  }

  function submitBooking() {
    var btn = document.getElementById('dinia-submit');
    var errorDiv = document.getElementById('dinia-booking-error');
    btn.disabled = true;
    btn.textContent = 'Wird gespeichert …';
    errorDiv.style.display = 'none';

    fetchWithKey(apiBase + '/reserve', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        date: state.date,
        time: state.selected_slot.start,
        time_end: state.selected_slot.end,
        party_size: state.party_size,
        guest_name: state.name,
        guest_email: state.email,
        guest_phone: state.phone,
        notes: state.notes,
        table_id: state.selected_slot.table_id,
        table_ids: state.selected_slot.table_ids
      })
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      btn.disabled = false;
      btn.textContent = '✓ Reservierung bestätigen';
      if (res.success) {
        document.querySelectorAll('.dinia-panel').forEach(function (p) { p.classList.remove('active'); });
        document.getElementById('dinia-step-success').classList.add('active');
        document.getElementById('dinia-success-message').textContent = res.message || 'Reservierung #' + res.reservation_id;
        document.querySelectorAll('.dinia-step').forEach(function (s) { s.classList.add('done'); s.classList.remove('active'); });
      } else {
        errorDiv.textContent = res.error || 'Fehler bei der Buchung.';
        errorDiv.style.display = 'block';
      }
    })
    .catch(function () {
      btn.disabled = false;
      btn.textContent = '✓ Reservierung bestätigen';
      errorDiv.textContent = 'Verbindungsfehler. Bitte versuchen Sie es erneut.';
      errorDiv.style.display = 'block';
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
