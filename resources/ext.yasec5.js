/* This script feeds event data to FullCalendar v5. */

jQuery( document ).ready( function( $ ) {
	if ( typeof window.eventCalendarData === 'object' ) {
		for ( var i = 0; i < window.eventCalendarData.length; i++ ) {
			// render calendar
			var elem = $( "#eventcalendar-" + ( i + 1 ) )[0];
			var attr = {
				events: window.eventCalendarData[i],
				eventContent: function ( arg ) {
					// Allow HTML in event titles.
					return { html: arg.event.title };

				},
				locale: mw.config.get( 'wgUserLanguage' )
			};
			var height = elem.dataset.height,
				aspectRatio = elem.dataset.aspectratio;

			if ( height ) {
				attr.contentHeight = parseInt( height );
			} else if ( aspectRatio ) {
				// Explicitly set width-to-height ratio.
				attr.aspectRatio = aspectRatio;
			} else {
				// Default: no limits on calendar height, no scrollbar.
				attr.contentHeight = 'auto';
			}

			var calendar = new FullCalendar.Calendar( elem, attr );
			calendar.render();
		}
	}
} );
