/* This script feeds event data to FullCalendar v2. */

jQuery( document ).ready( function( $ ) {
	if ( typeof window.eventCalendarData === 'object' ) {
		for ( var i = 0; i < window.eventCalendarData.length; i++ ) {
			// render calendar
			var attr = {
				events: window.eventCalendarData[i],
				eventRender: function( eventObj, $el ) {
					// Allow HTML in event titles.
					$el.html( $el.text() );
				},
				lang: mw.config.get( 'wgUserLanguage' )
			};
			var $elem = $( "#eventcalendar-" + ( i + 1 ) ),
				height = $elem.data( 'height' ),
				aspectRatio = $elem.data( 'aspectratio' );

			if ( height ) {
				attr.contentHeight = height;
			} else if ( aspectRatio ) {
				// Explicitly set width-to-height ratio.
				attr.aspectRatio = aspectRatio;
			} else {
				// Default: no limits on calendar height, no scrollbar.
				attr.contentHeight = 'auto';
			}

			$elem.fullCalendar( attr );

			// HACK sometimes init is called too early, it seems, so rerender to be sure
			( function( i ) {
				window.setTimeout( function() {
					$( "#eventcalendar-" + ( i + 1 ) ).fullCalendar( 'render' );
				}, 0 );
			} )( i );
		}
	}
});
