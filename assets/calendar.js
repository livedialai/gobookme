/**
 * Dinia FullCalendar Integration – Tenant Calendar View
 * Wird auf der "Mein Konto"-Seite geladen
 */
document.addEventListener('DOMContentLoaded', function () {
	var container = document.getElementById('dinia-calendar');
	if (!container) return;

	var calendar = new FullCalendar.Calendar(container, {
		// Locale
		locale: 'de',
		// Standard-Ansichten: Monat, Woche, Tag
		initialView: 'dayGridMonth',
		headerToolbar: {
			left: 'prev,next today',
			center: 'title',
			right: 'dayGridMonth,timeGridWeek,timeGridDay'
		},
		buttonText: {
			today: 'Heute',
			month: 'Monat',
			week: 'Woche',
			day: 'Tag'
		},
		// Zeiten
		firstDay: 1, // Montag
		slotMinTime: '08:00',
		slotMaxTime: '23:00',
		height: 'auto',
		// Kein neuer Event via Klick (nur Anzeige)
		selectable: false,
		editable: false,
		// Events via REST API laden
		events: {
			url: diniaCalendar.rest_url,
			method: 'GET',
			extraParams: {
				_wpnonce: diniaCalendar.nonce
			},
			failure: function () {
				console.error('[Dinia Calendar] Fehler beim Laden der Buchungen');
			}
		},
		eventTimeFormat: {
			hour: '2-digit',
			minute: '2-digit',
			hour12: false
		},
		// Kein Scroll auf Event beim ersten Laden
		scrollTime: '08:00',
		// Event-Tooltip beim Hovern
		eventDidMount: function (info) {
			var desc = info.event.extendedProps.description;
			if (desc) {
				info.el.title = desc;
			}
		}
	});

	calendar.render();
});
