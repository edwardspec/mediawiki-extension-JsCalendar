## JsCalendar

NOTE: this is based on another extension: https://github.com/improper/mediawiki-extensions-yasec
... but doesn't aim to maintain backward compatibility with it.

Outputs a tabular calendar filled with events automatically generated
from page titles in a certain namespace. Based on the [intersection extension][1]
and the [FullCalendar jQuery plugin][2].

Demo: [FoodHackingBase Events][3]

### Usage

Assuming that event pages are called `Event:Today_in_History/April,_12` (where Event is a namespace), the following wikitext will display a calendar of these events:

    <EventCalendar>
    namespace = Event
    aspectratio = 1.35
    prefix = Today_in_History/
    suffix = 
    dateFormat = F_j
    </EventCalendar>
    
... here F_j is the format from https://www.php.net/manual/ru/datetime.createfromformat.php - "F" means "name of month", and "j" means "day (with leading zero)".

`aspectratio` is optional and defaults to 1.6. CSS `max-width` is set to 800px and can be overridden in `MediaWiki:Common.css`.

#### Category-based coloring

The following parameters within `<EventCalendar>` will change the color of events to red/green based on the category into which the Event: page is included.
Pages not included into any listed categories would have default color.

    categorycolor.Cat-related events = red
    categorycolor.Dogs = green

### Requirements

* MediaWiki 1.34
* MySQL (not tested with other databases).

### Installation

1. Deploy the files to `extensions/JsCalendar`.
2. Edit your `LocalSettings.php`:
    * Load the extension:

      ```php
      wfLoadExtension( 'JsCalendar' );
      ```

    * Setup your namespace:

      ```php
      $wgExtraNamespaces = array(
          100 => "Event",
          101 => "Event_talk",
      );
      $wgNamespacesToBeSearchedDefault = array(
          NS_MAIN => true,
          100     => true,
      );
      ```

    * For testing you might want to disable the cache:

      ```php
      # How long to cache pages using EventCalendar in seconds. Default to 1 day.
      # Set to false to use the normal amount of page caching (most efficient),
      # set to 0 to disable cache altogether (inefficient, but results will never
      # be outdated)
      $wgECMaxCacheTime = 60*60*24;   // How long to cache pages in seconds
      ```

  [1]: http://www.mediawiki.org/wiki/Extension:DynamicPageList_(Wikimedia)
  [2]: http://arshaw.com/fullcalendar/
  [3]: https://foodhackingbase.org/wiki/Events
  
