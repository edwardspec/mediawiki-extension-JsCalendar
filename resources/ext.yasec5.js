/* This script feeds event data to FullCalendar v5. */

jQuery( document ).ready( function( $ ) {
	if ( typeof window.eventCalendarData === 'object' ) {
		for ( var i = 0; i < window.eventCalendarData.length; i++ ) {
			// render calendar
			var elem = $( "#eventcalendar-" + ( i + 1 ) )[0];
			var calendar = new FullCalendar.Calendar( elem, {
				aspectRatio: window.eventCalendarAspectRatio[i],
				events: window.eventCalendarData[i],
				eventContent: function ( arg ) {
					// Allow HTML in event titles.
					return { html: arg.event.title };

				},
				contentHeight: 'auto',
				locale: mw.config.get( 'wgUserLanguage' )
			} );
			calendar.render();
		}
	}
} );
