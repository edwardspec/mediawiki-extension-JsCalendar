for (var i = 0; i < window.eventCalendarData.length; i++) {
    $("#eventcalendar-" + (i + 1)).fullCalendar({
        aspectRatio: window.eventCalendarAspectRatio[i],
        events: window.eventCalendarData[i]
    });
}
