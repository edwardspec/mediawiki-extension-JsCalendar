<?php

/**
 * Implements the JsCalendar extension for MediaWiki.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\JsCalendar;

use DateTime;
use FormatJson;
use Html;
use MediaWiki\MediaWikiServices;
use ObjectCache;
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
		$titleRegex = $opt['titleRegex'] ?? '';

		$dateFormat = $opt['dateFormat'] ?? 'Y/m/d';
		$limit = $opt['limit'] ?? 500;

		// build the SQL query

		$query = new FindEventPagesQuery();
		$query->setNamespace( $namespaceIdx );
		$query->setPrefixAndSuffix( $prefix, $suffix );
		$query->setTitleRegex( $titleRegex );
		$query->setLimit( intval( $opt['limit'] ?? 0 ) );

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

		$query->detectCategories( array_keys( $coloredCategories ) );

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

		// If either symbols=N or "keywordcolor" parameters are present,
		// additionally load full text of each event page.
		$maxSymbols = intval( $opt['symbols'] ?? 0 );
		if ( $maxSymbols > 0 || $coloredKeywords ) {
			$query->obtainWikitext();
		}

		if ( $maxSymbols > 0 ) {
			$query->obtainCachedSnippet();
		}

		// process the query
		$res = $query->getResult();

		// Obtain connections to DB/cache that may or may not be used for storing snippets in cache.
		$dbw = wfGetDB( DB_MASTER );
		$dbCache = ObjectCache::getInstance( CACHE_DB );

		$eventmap = [];
		foreach ( $res as $row ) {
			// Try to find the date in $pageName.
			$dbKey = $row->title;
			$dateString = $dbKey;

			if ( $prefix || $suffix ) {
				// Remove $prefix and $suffix, which are both fixed strings surrounding the date.
				$dateString = substr( $dateString, strlen( $prefix ),
					strlen( $dateString ) - strlen( $prefix ) - strlen( $suffix ) );
			}

			if ( $titleRegex ) {
				// If regex is used, date should be within the first braces symbols: between "(" and ")".
				// Obtain it with preg_match().
				$matches = [];
				if ( !preg_match(
					'/' . str_replace( '/', '\\/', $titleRegex ) . '/',
					$dateString,
					$matches
				) ) {
					// Not found.
					continue;
				}

				$dateString = $matches[1];
			}

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

			if ( $maxSymbols > 0 ) {
				// Use cached HTML snippet if possible.
				$snippet = $row->snippet;
				if ( !$snippet ) {
					// Full text of the page (no more than N first symbols) was requested.
					// NOTE: we can't use getParserOutput() here, because we are already inside Parser::parse().
					$parsedHtml = $recursiveParser->recursiveTagParseFully( $row->text );

					// Remove the image tags: in 99,9% of cases they are too wide to be included into the calendar.
					// TODO: properly remove <div class="thumb"> with all contents (currently hidden by CSS).
					$parsedHtml = preg_replace( '/<img[^>]+>/', '', $parsedHtml );
					$snippet = mb_substr( $parsedHtml, 0, $maxSymbols );

					// Remove truncated HTML tags (if any).
					$snippet = HtmlSanitizer::sanitizeHTML( $snippet );

					// Store the snippet in cache.
					// NOTE: the reason why we don't use $dbCache->set() here is that SqlBagOStuff::set() will do
					// both compression and serialization, and decoding this is in protected methods.
					// We don't want all that code duplication here, so we store HTML snippet in raw form.
					$dbw->insert( 'objectcache', [
						'keyname' => $dbCache->makeKey( 'jscalendar-snippet-' . $row->latest ),
						'value' => $snippet,
						'exptime' => $dbw->timestamp( time() + 604800 ) // 7 days
					], __METHOD__ );
				}

				$textToDisplay = $snippet;
			} else {
				// By default we display the page title as event name, but remove the date from it.
				$textToDisplay = $pageName;
				$textToDisplay = str_replace( $dateString, '', $textToDisplay );
			}

			$textToDisplay = trim( $textToDisplay );

			// Form the EventObject descriptor (as expected by JavaScript library),
			// see [resources/fullcalendar/changelog.txt]
			$eventObject = [
				'title' => $textToDisplay,
				'start' => $startdate,
				'end' => $enddate,
				'url' => $url
			];

			// Determine the color. First try the category coloring.
			$color = null;
			$category = $row->category ?? null;
			if ( $category ) {
				$color = $coloredCategories[$row->category] ?? null;
			}

			if ( !$color && $coloredKeywords ) {
				// Check whether the title OR text of the page have keywords associated with color.
				// This is case-insensitive matching ("Arctic" and "arctic" are the same keyword).
				$titleAndText = $pageName . "\n" . $row->text;
				foreach ( $coloredKeywords as $keyword => $keywordColor ) {
					if ( stripos( $titleAndText, $keyword ) !== false ) {
						$color = $keywordColor;
						break;
					}
				}
			}

			if ( $color ) {
				$eventObject['color'] = $color;
			}

			// Events are grouped by $textToDisplay (event name or HTML snippet),
			// so that multi-day events that span several consecutive days can be joined into one event.
			$eventmap[$textToDisplay][] = $eventObject;
		}

		// concatenate all events to single list
		$events = [];
		foreach ( $eventmap as $eventKey => $entries ) {
			// Sort $entries by starting date, from earliest to latest.
			usort( $entries, function ( $event1, $event2 ) {
				return strcmp( $event1['start'], $event2['start'] );
			} );

			// Look for events that should be joined (e.g. 21-22 May and 22-23 May becomes 21-23 May).
			for ( $i = 0; $i < count( $entries ) - 1; $i++ ) {
				for ( $j = $i + 1; $j < count( $entries ); $j++ ) {
					if ( $entries[$i]['end'] != $entries[$j]['start'] ) {
						// Event $j is not on consecutive days with the previous event.
						break;
					}

					$entries[$i]['end'] = $entries[$j]['end'];
					$entries[$j] = null;
				}

				// Remove events that were set to NULL (they are already merged into a previous event)
				// and restore indices of $entries array.
				$entries = array_values( array_filter( $entries ) );
			}

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

		$parser->getOutput()->addModules( [ 'ext.yasec' ] );

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
		$scriptHtml = '';
		$scriptHtml .=
			'if ( !window.eventCalendarAspectRatio ) { window.eventCalendarAspectRatio = []; }';
		$scriptHtml .=
			'window.eventCalendarAspectRatio.push( ' . floatval( $options['aspectratio'] ?? 1.6 ) . ' );';
		$scriptHtml .= 'if ( !window.eventCalendarData ) { window.eventCalendarData = []; }';
		$scriptHtml .= 'window.eventCalendarData.push( ' . FormatJson::encode( $events ) . " );\n";

		$resultHtml = Html::element( 'div', [
			'class' => 'eventcalendar',
			'id' => 'eventcalendar-' . ( ++ self::$calendarsCounter )
		] );
		$resultHtml .= Html::rawElement( 'script', [], $scriptHtml );

		return [ $resultHtml, 'markerType' => 'nowiki' ];
	}
}
