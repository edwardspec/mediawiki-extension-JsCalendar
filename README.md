## Yet Another Simple Event Calendar

https://github.com/improper/mediawiki-extensions-yasec

Outputs a tabular calendar filled with events automatically generated
from page titles in a certain namespace. Based on the [intersection extension][1]
and the [FullCalendar jQuery plugin][2].

To install, add following to LocalSettings.php

    include("$IP/extensions/yasec/EventCalendar.php");

  [1]: http://www.mediawiki.org/wiki/Extension:DynamicPageList_(Wikimedia)
  [2]: http://arshaw.com/fullcalendar/
