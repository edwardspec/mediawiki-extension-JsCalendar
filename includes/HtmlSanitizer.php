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

use Wikimedia\RemexHtml\Serializer\HtmlFormatter;
use Wikimedia\RemexHtml\Serializer\Serializer;
use Wikimedia\RemexHtml\Tokenizer\Tokenizer;
use Wikimedia\RemexHtml\TreeBuilder\Dispatcher;
use Wikimedia\RemexHtml\TreeBuilder\TreeBuilder;

/** @phan-file-suppress PhanUndeclaredClassMethod */

class HtmlSanitizer {
	/**
	 * Remove invalid/non-matching/truncated HTML tags and return correct (sanitized) HTML.
	 * @param string $html
	 * @return string
	 */
	public static function sanitizeHTML( $html ) {
		if ( !class_exists( HtmlFormatter::class ) ) {
			// MediaWiki 1.35-1.36
			$formatter = new \RemexHtml\Serializer\HtmlFormatter;
			$serializer = new \RemexHtml\Serializer\Serializer( $formatter );
			$treeBuilder = new \RemexHtml\TreeBuilder\TreeBuilder( $serializer );
			$dispatcher = new \RemexHtml\TreeBuilder\Dispatcher( $treeBuilder );
			$tokenizer = new \RemexHtml\Tokenizer\Tokenizer( $dispatcher, $html );
		} else {
			// MediaWiki 1.37+
			$formatter = new HtmlFormatter;
			$serializer = new Serializer( $formatter );
			$treeBuilder = new TreeBuilder( $serializer );
			$dispatcher = new Dispatcher( $treeBuilder );
			$tokenizer = new Tokenizer( $dispatcher, $html );
		}

		$tokenizer->execute();
		$html = $serializer->getResult();

		// Remove doctype, <head>, etc.: everything outside the <body> tag.
		// TODO: this can probably be implemented by subclassing HtmlFormatter class.
		$html = preg_replace( '@^.*<body>(.*)</body>.*$@', '$1', $html );

		return $html;
	}
}
