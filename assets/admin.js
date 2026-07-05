/**
 * Dinia Admin JavaScript
 * Tisch-CRUD, Brevo/CalDAV-Test, Öffnungszeiten, Farbe
 */
(function($) {
  'use strict';

  // Tisch-CRUD
  $(document).on('click', '.dinia-add-table', function() {
    var row = $('#dinia-tables-table tbody tr:last').clone();
    row.find('input').val('');
    row.find('input[name="name"]').val('Neuer Tisch');
    row.appendTo('#dinia-tables-table tbody');
  });

  $(document).on('click', '.dinia-save-tables', function() {
    var tables = [];
    $('#dinia-tables-table tbody tr').each(function() {
      var tds = $(this).find('td');
      tables.push({
        id: $(tds[0]).find('input').val() || 0,
        name: $(tds[1]).find('input').val(),
        seats: $(tds[2]).find('input').val(),
        position: $(tds[3]).find('select').val(),
        active: $(tds[4]).find('input[type="checkbox"]').is(':checked') ? 1 : 0,
        combinable: $(tds[5]).find('input[type="checkbox"]').is(':checked') ? 1 : 0,
      });
    });
    // Batch-Save via REST
    tables.forEach(function(t) {
      var method = t.id ? 'PUT' : 'POST';
      var url = ajaxurl.replace('/admin-ajax.php', '/dinia/v1/admin/tables' + (t.id ? '/' + t.id : ''));
      $.ajax({ url: url, method: method, data: t, beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); } })
        .done(function() { location.reload(); })
        .fail(function(r) { alert('Fehler: ' + r.responseJSON.message); });
    });
  });

  // Test-E-Mail
  $(document).on('click', '.dinia-test-email', function() {
    var email = $('#dinia-sender-email').val() || prompt('E-Mail für Test:');
    if (!email) return;
    var btn = $(this); btn.prop('disabled', true).text('Sende …');
    $.ajax({
      url: ajaxurl.replace('/admin-ajax.php', '/dinia/v1/admin/test-email'),
      method: 'POST', data: { email: email },
      beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); }
    }).done(function(r) {
      alert(r.message || (r.success ? 'Testmail gesendet!' : 'Fehler'));
    }).fail(function(r) {
      alert('Fehler: ' + (r.responseJSON && r.responseJSON.message ? r.responseJSON.message : 'Verbindungsfehler'));
    }).always(function() { btn.prop('disabled', false).text('Testmail senden'); });
  });

  // CalDAV-Test
  $(document).on('click', '.dinia-test-caldav', function() {
    var btn = $(this); btn.prop('disabled', true).text('Teste …');
    $.ajax({
      url: ajaxurl.replace('/admin-ajax.php', '/dinia/v1/admin/test-caldav'),
      method: 'POST',
      beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); }
    }).done(function(r) {
      alert(r.message || (r.success ? 'CalDAV-Verbindung OK!' : 'Fehler: ' + (r.message || 'unbekannt')));
    }).fail(function() {
      alert('Verbindungsfehler');
    }).always(function() { btn.prop('disabled', false).text('CalDAV testen'); });
  });

  // Öffnungszeiten speichern
  $(document).on('click', '.dinia-save-hours', function() {
    var days = {};
    $('#dinia-hours-table tbody tr').each(function() {
      var tds = $(this).find('td');
      var day = $(tds[0]).text().toLowerCase().substring(0,3);
      days[day] = {
        open: $(tds[1]).find('input').val(),
        close: $(tds[2]).find('input').val(),
        open2: $(tds[3]).find('input').val(),
        close2: $(tds[4]).find('input').val(),
        closed: $(tds[5]).find('input[type="checkbox"]').is(':checked') ? 1 : 0,
      };
    });
    var btn = $(this); btn.prop('disabled', true).text('Speichere …');
    $.ajax({
      url: ajaxurl.replace('/admin-ajax.php', '/dinia/v1/admin/hours'),
      method: 'POST', data: { days: days },
      beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); }
    }).done(function(r) {
      alert(r.success ? 'Öffnungszeiten gespeichert!' : 'Fehler');
    }).fail(function() {
      alert('Speicherfehler');
    }).always(function() { btn.prop('disabled', false).text('Öffnungszeiten speichern'); });
  });

  // Reservierungs-Status
  $(document).on('change', '.dinia-reservation-status', function() {
    var id = $(this).data('id');
    var status = $(this).val();
    $.ajax({
      url: ajaxurl.replace('/admin-ajax.php', '/dinia/v1/admin/reservations/' + id + '/status'),
      method: 'PUT', data: { status: status },
      beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); }
    }).fail(function() { alert('Fehler beim Status-Update'); });
  });

  // Provider-Presets für CalDAV
  $(document).on('change', '#dinia-caldav-provider', function() {
    var val = $(this).val();
    var presets = {
      infomaniak: { url: 'https://sync.infomaniak.com/calendars/GO01132/', user: 'GO01132', cal: 'dbc10e70-7fd9-4184-bba7-0eebf969bb41' },
      google: { url: 'https://apidata.googleusercontent.com/caldav/v2', user: '', cal: 'default' },
      gmx: { url: 'https://caldav.gmx.net', user: '', cal: 'default' },
      webde: { url: 'https://caldav.web.de', user: '', cal: 'default' },
      icloud: { url: 'https://caldav.icloud.com/', user: '', cal: 'default' },
    };
    var p = presets[val];
    if (p) {
      $('#dinia-caldav-url').val(p.url);
      $('#dinia-caldav-username').val(p.user);
      $('#dinia-caldav-calendar').val(p.cal);
    }
  });

  // Copy-Buttons für Einbettungscode
  $(document).on('click', '.dinia-copy-code', function() {
    var code = $(this).data('code');
    navigator.clipboard.writeText(code).then(function() {
      alert('Code kopiert!');
    });
  });

})(jQuery);
