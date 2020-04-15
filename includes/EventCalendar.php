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

use DateTime;
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
		$prefix = $opt['prefix'] ?? '';
		$suffix = $opt['suffix'] ?? '';

		$dateFormat = $opt['dateFormat'] ?? 'Y/m/d';
		$limit = $opt['limit'] ?? 500;

		// build the SQL query
		$dbr = wfGetDB( DB_REPLICA );
		$tables = [ 'page' ];
		$fields = [ 'page_title' ];
		$where = [];
		$options = [];

		$where['page_namespace'] = $namespaceIdx;

		if ( $prefix || $suffix ) {
			$where[] = 'page_title ' . $dbr->buildLike( $prefix, $dbr->anyString(), $suffix );
		}

		// 5000 should limit output volume to about 300 KiB assuming 60 bytes per entry.
		$defaultLimit = 5000;
		$options['LIMIT'] = $opt['limit'] ?? $defaultLimit;

		// process the query
		$res = $dbr->select( $tables, $fields, $where, __METHOD__, $options );

		$eventmap = [];
		foreach ( $res as $row ) {
			// Try to find the date in $pageName by removing $prefix and $suffix.
			$dbKey = $row->page_title;
			$dateString = substr( $dbKey, strlen( $prefix ),
				strlen( $dbKey ) - strlen( $prefix ) - strlen( $suffix ) );

			// Try to parse the date.
			// For example, pages like "Conferences/05_April_2010" will need dateFormat=d/F/Y.
			// See https://www.php.net/manual/ru/datetime.createfromformat.php
			$dateTime = DateTime::createFromFormat( $dateFormat, $dateString );
			if ( !$dateTime ) {
				// Couldn't parse the date (not in correct dateFormat), so ignore this page.
				continue;
			}

			$startdate = $dateTime->format( 'Y-m-d' );

			$dateTime->modify( '+1 day' );
			$enddate = $dateTime->format( 'Y-m-d' );

			$title = Title::makeTitle( $namespaceIdx, $row->page_title );
			$pageName = $title->getFullText();
			$url = $title->getLinkURL();

			if ( !array_key_exists( $pageName, $eventmap ) ) {
				$eventmap[$pageName] = [];
			}

			// look for events with same name on consecutive days
			$last = array_pop( $eventmap[$pageName] );
			if ( $last !== null ) {
				if ( $last['start'] == $enddate ) {
					// conflate multi-day event
					$enddate = $last['end'];
				} else {
					// no match, keep last event
					$eventmap[$pageName][] = $last;
				}
			}

			$eventmap[$pageName][] = [
				'title' => $pageName,
				'start' => $startdate,
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
	 * @param Parser $mwParser
	 * @return array|string
	 */
	public static function renderEventCalendar( $input, Parser $mwParser ) {
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
