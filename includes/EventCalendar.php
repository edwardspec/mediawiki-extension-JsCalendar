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
use MediaWiki\Revision\SlotRecord;
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
	 * @param Parser $recursiveParser Parser object that is being used to render <EventCalendar>.
	 * @return array
	 */
	public static function findEvents( array $opt, Parser $recursiveParser ) {
		$namespaceIdx = $opt['namespace'] ?? NS_MAIN;
		$prefix = $opt['prefix'] ?? '';
		$suffix = $opt['suffix'] ?? '';

		$dateFormat = $opt['dateFormat'] ?? 'Y/m/d';
		$limit = $opt['limit'] ?? 500;

		// build the SQL query
		$dbr = wfGetDB( DB_REPLICA );
		$tables = [ 'page' ];
		$fields = [ 'page_title AS title' ];
		$where = [];
		$options = [];
		$joinConds = [];

		$where['page_namespace'] = $namespaceIdx;

		if ( $prefix || $suffix ) {
			$where[] = 'page_title ' . $dbr->buildLike( $prefix, $dbr->anyString(), $suffix );
		}

		// 5000 should limit output volume to about 300 KiB assuming 60 bytes per entry.
		$defaultLimit = 5000;
		$maxLimit = 5000;
		$options['LIMIT'] = min( intval( $opt['limit'] ?? $defaultLimit ), $maxLimit );

		// Find "categorycolor.<SOMETHING>" keys in $opt.
		// If found, then determine whether each of selected pages is included into those categories.
		$coloredCategories = []; // E.g. [ 'Dogs' => 'green', 'Big_cats' => 'red', ... ]
		foreach ( $opt as $key => $val ) {
			$matches = null;
			if ( preg_match( '/^categorycolor\.(.+)$/', $key, $matches ) ) {
				$categoryName = strtr( $matches[1], ' ', '_' );
				$coloredCategories[$categoryName] = $val;
			}
		}

		$options['GROUP BY'] = [ 'page_title' ];

		if ( $coloredCategories ) {
			$tables[] = 'categorylinks';
			$fields[] = 'cl_to AS category';
			$joinConds['categorylinks'] = [
				'LEFT JOIN',
				[
					'cl_from=page_id',
					'cl_to' => array_keys( $coloredCategories )
				]
			];

			// If the page belongs to 2+ colored categories only one of them will affect the color.
			// Currently we don't care which category's color will be applied.
			$options['GROUP BY'][] = 'cl_to';
		}

		// Find "keywordcolor.<SOMETHING>" keys in $opt.
		// These are matched against the text of event pages.
		$coloredKeywords = []; // E.g. [ 'statistically' => 'green', 'arctic' => 'red', ... ]
		foreach ( $opt as $key => $val ) {
			$matches = null;
			if ( preg_match( '/^keywordcolor\.(.+)$/', $key, $matches ) ) {
				$categoryName = strtr( $matches[1], ' ', '_' );
				$coloredKeywords[$categoryName] = $val;
			}
		}

		// If symbols=N parameter is present, additionally load full text of each event page.
		$maxSymbols = intval( $opt['symbols'] ?? 0 );
		if ( $maxSymbols > 0 ) {
			$tables[] = 'revision';
			$tables[] = 'slot_roles';
			$tables[] = 'slots';
			$tables[] = 'content';
			$tables[] = 'text';
			$fields[] = 'old_text AS text';

			$joinConds['revision'] = [
				'INNER JOIN',
				[ 'rev_id=page_latest' ]
			];
			$joinConds['slot_roles'] = [
				'INNER JOIN',
				[ 'role_name' => SlotRecord::MAIN ]
			];
			$joinConds['slots'] = [
				'INNER JOIN',
				[
					'slot_revision_id=rev_id',
					'slot_role_id=role_id'
				]
			];
			$joinConds['content'] = [
				'INNER JOIN',
				[
					'content_id=slot_content_id',
					'content_address ' . $dbr->buildLike( 'tt:', $dbr->anyString() )
				]
			];
			$joinConds['text'] = [
				'INNER JOIN',
				[ 'old_id=SUBSTR(content_address,4)' ] // Strip tt: suffix from content_address
			];
		}

		// process the query
		$res = $dbr->select( $tables, $fields, $where, __METHOD__, $options, $joinConds );

		$eventmap = [];
		foreach ( $res as $row ) {
			// Try to find the date in $pageName by removing $prefix and $suffix.
			$dbKey = $row->title;
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

			$title = Title::makeTitle( $namespaceIdx, $row->title );
			$pageName = $title->getText(); // Without namespace
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

			// By default we display the page name as event name.
			$textToDisplay = $pageName;
			if ( $maxSymbols > 0 ) {
				// Full text of the page (no more than N first symbols) was requested.
				// NOTE: we can't use getParserOutput() here, because we are already inside Parser::parse().
				// TODO: cache the results.
				$parsedHtml = $recursiveParser->recursiveTagParseFully( $row->text );

				// Remove the image tags: in 99,9% of cases they are too wide to be included into the calendar.
				// TODO: properly remove <div class="thumb"> with all contents (currently hidden by CSS).
				$parsedHtml = preg_replace( '/<img[^>]+>/', '', $parsedHtml );

				// TODO: remove truncated HTML tags (if any) from $parsedHtml.
				$textToDisplay = mb_substr( $parsedHtml, 0, $maxSymbols );
			}

			// Form the EventObject descriptor (as expected by JavaScript library),
			// see [resources/fullcalendar/changelog.txt]
			$eventObject = [
				'title' => $textToDisplay,
				'start' => $startdate,
				'end' => $enddate,
				'url' => $url
			];

			// Determine the color. First try the category coloring.
			$color = $coloredCategories[$row->category] ?? null;
			if ( !$color ) {
				// Check whether the text of the page has keywords associated with color.
				// This is case-insensitive matching ("Arctic" and "arctic" are the same keyword).
				foreach ( $coloredKeywords as $keyword => $keywordColor ) {
					if ( stripos( $row->text, $keyword ) !== false ) {
						$color = $keywordColor;
						break;
					}
				}
			}

			if ( $color ) {
				$eventObject['color'] = $color;
			}

			$eventmap[$pageName][] = $eventObject;
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
	 * @param Parser $parser
	 * @return array|string
	 */
	public static function renderEventCalendar( $input, Parser $parser ) {
		// config variables
		global $wgECMaxCacheTime;

		$parser->getOutput()->addModules( 'ext.yasec' );

		if ( $wgECMaxCacheTime !== false ) {
			$parser->getOutput()->updateCacheExpiry( $wgECMaxCacheTime );
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

		$events = self::findEvents( array_filter( $options ), $parser );

		// calendar container and data array
		$scriptHtml = "if ( typeof window.eventCalendarAspectRatio !== 'object' ) " .
			"{ window.eventCalendarAspectRatio = []; }\n" .
			"window.eventCalendarAspectRatio.push( " . floatval( $options['aspectratio'] ?? 1.6 ) . ");\n" .
			"if ( typeof window.eventCalendarData !== 'object' ) { window.eventCalendarData = []; }\n" .
			"window.eventCalendarData.push( " . FormatJson::encode( $events ) . " );\n";

		$resultHtml = Html::element( 'div', [
			'class' => 'eventcalendar',
			'id' => 'eventcalendar-' . ( ++ self::$calendarsCounter )
		] );
		$resultHtml .= Html::rawElement( 'script', [], $scriptHtml );

		return [ $resultHtml, 'markerType' => 'nowiki' ];
	}
}
