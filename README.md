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
    prefix = Today_in_History/
    suffix = 
    dateFormat = F,_j
    </EventCalendar>
    
... here F_j is the format from https://www.php.net/manual/ru/datetime.createfromformat.php - "F" means "name of month", and "j" means "day (with leading zero)".

Everything between "prefix" and "suffix" should be a date.

#### titleRegex

Alternatively, `titleRegex` parameter can be used to find event pages. For example, the following wikitext will find pages like `Event:2020/05/15_Name_of_some_event`:

    <EventCalendar>
    namespace = Event
    titleRegex = ^([0-9]{4,4}/[0-9][0-9]/[0-9][0-9])_.*
    dateFormat = Y/m/d
    </EventCalendar>
    
When using `titleRegex`, the date part (in example above - `[0-9]{4,4}/[0-9][0-9]/[0-9][0-9]`) **must be surrounded in "(" and ")" symbols** (otherwise the calendar wouldn't know "which part of the title is the date").

It's also possible to match both the first and last day of the event.  For example, the following wikitext will find pages like `2022/05/10:2022/04/15_Name_of_some_event`:

    <EventCalendar>
    titleRegex = ^([0-9]{4,4}/[0-9][0-9]/[0-9][0-9]):?([0-9]{4,4}/[0-9][0-9]/[0-9][0-9])?_.*
    dateFormat = Y/m/d
    </EventCalendar>
    
Both date parts (start date and end date)  **must be surrounded in "(" and ")" symbols**.

##### Excluding the last day

When using `titleRegex` to determine the end date, the last day is considered to be a part of the event (so the event `2022/06/20:2022/06/22_Name` would be shown on June 20, June 21 and June 22).

This is different from standard behavior of FullCalendar library (where last day is not included). You can exclude the last day by adding `excludeLastDay=1` parameter:
    <EventCalendar>
    titleRegex = ^([0-9]{4,4}/[0-9][0-9]/[0-9][0-9]):?([0-9]{4,4}/[0-9][0-9]/[0-9][0-9])?_.*
    dateFormat = Y/m/d
    excludeLastDay = 1
    </EventCalendar>

##### Regex troubleshooting
If your regex is complex, and you need to use "(" and ")" symbols for other purposes, you must add `?:` after "(" symbols that are not used to match the date.
For example, if you want to match pages like `Cat Event 1 2022/04/20:2022/04/29`,
but only if they start with "Cat Event" or "Dog Event", then the following wikitext will find them:

    <EventCalendar>
    titleRegex = ^(?:Cat|Dog)_Event.*?([0-9]{4,4}/[0-9][0-9]/[0-9][0-9]):?([0-9]{4,4}/[0-9][0-9]/[0-9][0-9])?$
    dateFormat = Y/m/d
    </EventCalendar>
    
Alternatively, you can use syntax `(?<start>something)` and `(?<end>something)` to select the braces that contain the start/end date.
For example, pages like `Cat Event 1 2022/04/29//2022/04/20` (where the end date is first) can be found by the following wikitext:

    <EventCalendar>
    titleRegex = ^(Cat|Dog)_Event.*?(?<end>[0-9]{4,4}/[0-9][0-9]/[0-9][0-9])//(?<start>[0-9]{4,4}/[0-9][0-9]/[0-9][0-9])$
    dateFormat = Y/m/d
    </EventCalendar>

#### Styling
    
##### Height of the calendar

By default, the calendar will assume natural height (will increase in height if it's needed for all content to fit), and there won't be any scrollbars.

Alternatively, you can configure the calendar to have a fixed height (in pixels):

    <EventCalendar>
    height=300
    </EventCalendar>
    
Alternatively, you can configure the calendar to have a chosen width-to-height ratio (e.g. "1.5" means "width is 1.5 times bigger than height"):

    <EventCalendar>
    aspectratio=1.5
    </EventCalendar>
    
If `height` is set, then `aspectratio` will be ignored.

##### Width of the calendar

Under most circumstances, calendar width shouldn't be changed. By default, it is limited to 800px (via CSS `max-width`),
but you can override it either in `MediaWiki:Common.css` or via Extension:TemplateStyles.

#### Category-based coloring

The following parameters within `<EventCalendar>` will change the color of events to red/green based on the category into which the Event: page is included.
Pages not included into any listed categories would have default color.

    categorycolor.Cat-related events = red
    categorycolor.Dogs = green
    
#### Keyword-based coloring

The following parameter within `<EventCalendar>` will change the color of event to yellow if the page contains the word "arctic" (the match is case-insensitive, so "Arctic" will work too), and to lightgreen if the page contains the word "statistically":

    keywordcolor.arctic = yellow
    keywordcolor.statistically = lightgreen

### Requirements

* MediaWiki 1.35+
* MySQL (this extension doesn't support PostgreSQL. Patches that add PostgreSQL support are very welcome, but maintainter of this extension won't be implementing this himself).

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
  
