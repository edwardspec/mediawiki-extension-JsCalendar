<?php
/*

 Yet Another Simple Event Calendar

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

use Parser;

class Hooks {
	/**
	 * Set up the <EventCalendar> tag.
	 *
	 * @param Parser $parser
	 * @return true
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'EventCalendar', '\MediaWiki\JsCalendar\Hooks::parserFunction' );
		return true;
	}

	/**
	 * The callback function for converting the input text to HTML output
	 * @param string $input
	 * @param mixed $args
	 * @param Parser $parser
	 * @return array|string
	 */
	public static function parserFunction( $input, $args, Parser $parser ) {
		return EventCalendar::renderEventCalendar( $input, $parser );
	}
}
