<?php
/*

 Yet Another Simple Event Calendar
 https://github.com/improper/mediawiki-extensions-yasec

 Outputs a tabular calendar filled with events automatically generated
 from page titles in a certain namespace. Based on the intersection extension.


 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 http://www.gnu.org/copyleft/gpl.html

*/

namespace MediaWiki\JsCalendar;

use FormatJson;
use Html;
use MediaWiki\MediaWikiServices;
use Parser;
use Title;

class EventCalendar {

	/**
	 * @var int
	 * For multiple calendars on the same page: they will be named Calendar1, Calendar2, etc.
	 */
	protected static $calendarsCounter = 0;

	/**
	 * Calculate an array of events in the format expected by JavaScript side of the calendar.
	 * @param array $opt Parameters obtained from contents of <eventcalendar> tag.
	 * @return array
	 */
	public static function findEvents( array $opt ) {
		$namespaceIdx = $opt['namespace'] ?? NS_MAIN;

		// build the SQL query
		$dbr = wfGetDB( DB_REPLICA );
		$tables = [ 'page' ];
		$fields = [ 'page_title' ];
		$where = [];
		$options = [];

		$where['page_namespace'] = $namespaceIdx;

		$title_pattern = '^[0-9]{4}/[0-9]{2}/[0-9]{2}_[[:alnum:]]';

		if ( $dbr->getType() != 'sqlite' ) {

			if ( $dbr->getType() == 'postgres' ) {
				$regexp_op = '~';
			} else {
				$regexp_op = 'REGEXP';
			}

			$where[] = "page_title " . $regexp_op . " '" . $title_pattern . "'";
		}

		$options['ORDER BY'] = 'page_title DESC';
		$options['LIMIT'] = 5000; // should limit output volume to about 300 KiB
					// assuming 60 bytes per entry

		// process the query
		$res = $dbr->select( $tables, $fields, $where, __METHOD__, $options );

		$eventmap = [];
		foreach ( $res as $row ) {
			if ( $dbr->getType() == 'sqlite' ) {
				if ( !preg_match( "@" . $title_pattern . "@", $row->page_title ) ) {
					continue;  // Ignoring page titles that don't follow the
						// pattern of event pages
				}
			}

			$date = str_replace( '/', '-', substr( $row->page_title, 0, 10 ) );
			$title = str_replace( '_', ' ', substr( $row->page_title, 11 ) );
			$url = Title::makeTitle( $namespaceIdx, $row->page_title )->getLinkURL();

			if ( !array_key_exists( $title, $eventmap ) ) {
				$eventmap[$title] = [];
			}

			// minimal interval is one day
			$tempdate = date_create( $date );
			date_add( $tempdate, date_interval_create_from_date_string( '1 day' ) );
			$enddate = date_format( $tempdate, 'Y-m-d' );

			// look for events with same name on consecutive days
			$last = array_pop( $eventmap[$title] );
			if ( $last !== null ) {
				if ( $last['start'] == $enddate ) {
					// conflate multi-day event
					$enddate = $last['end'];
				} else {
					// no match, keep last event
					$eventmap[$title][] = $last;
				}
			}

			$eventmap[$title][] = [
				'title' => $title,
				'start' => $date,
				'end' => $enddate,
				'url' => $url,
			];
		}

		// concatenate all events to single list
		$events = [];
		foreach ( $eventmap as $entries ) {
			$events = array_merge( $events, $entries );
		}

		return $events;
	}

	/**
	 * The callback function for converting the input text to HTML output
	 * @param string $input
	 * @param mixed $_
	 * @param Parser $mwParser
	 * @return array|string
	 */
	public static function renderEventCalendar( $input, $_, Parser $mwParser ) {
		// config variables
		global $wgECMaxCacheTime;

		$mwParser->getOutput()->addModules( 'ext.yasec' );

		if ( $wgECMaxCacheTime !== false ) {
			$mwParser->getOutput()->updateCacheExpiry( $wgECMaxCacheTime );
		}

		// Parse the contents of the tag ($input string) for parameters.
		$options = [];
		$lines = explode( "\n", $input );
		foreach ( $lines as $param ) {
			$keyval = explode( '=', $param, 2 );
			if ( count( $keyval ) < 2 ) {
				continue;
			}

			$key = trim( $keyval[0] );
			$val = trim( $keyval[1] );

			if ( $key == 'aspectratio' ) {
				$val = floatval( $val );
			} elseif ( $key == 'namespace' ) {
				$val = MediaWikiServices::getInstance()->getContentLanguage()->getNsIndex( $val );
			}

			$options[$key] = $val;
		}

		$events = self::findEvents( array_filter( $options ) );

		// calendar container and data array
		$scriptHtml = "if ( typeof window.eventCalendarAspectRatio !== 'object' ) " .
			"{ window.eventCalendarAspectRatio = []; }\n" .
			"window.eventCalendarAspectRatio.push( " . floatval( $options['aspectratio'] ?? 1.6 ) . ");\n" .
			"if ( typeof window.eventCalendarData !== 'object' ) { window.eventCalendarData = []; }\n" .
			"window.eventCalendarData.push( " . FormatJson::encode( $events ) . " );\n";

		$resultHtml = Html::element( 'div', [
			'id' => 'eventcalendar-' . ( ++ self::$calendarsCounter )
		] );
		$resultHtml .= Html::rawElement( 'script', [], $scriptHtml );

		return [ $resultHtml, 'markerType' => 'nowiki' ];
	}
}
