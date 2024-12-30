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
use Wikimedia\RemexHtml\Serializer\SerializerNode;
use Wikimedia\RemexHtml\Tokenizer\Tokenizer;
use Wikimedia\RemexHtml\TreeBuilder\Dispatcher;
use Wikimedia\RemexHtml\TreeBuilder\TreeBuilder;

class HtmlSanitizer {
	/**
	 * Remove unwanted/invalid/non-matching/truncated HTML tags and return correct (sanitized) HTML.
	 * @param string $html
	 * @return string
	 */
	public static function sanitizeSnippet( $html ) {
		$formatter = new class () extends HtmlFormatter {
			/** @inheritDoc */
			public function startDocument( $fragmentNamespace, $fragmentName ) {
				// Remove DOCTYPE.
				return '';
			}

			/** @inheritDoc */
			public function element( SerializerNode $parent, SerializerNode $node, $contents ) {
				$typeof = $node->attrs['typeof'] ?? null;
				$classes = explode( ' ', $node->attrs['class'] ?? '' );

				switch ( $node->name ) {
					// Remove everything outside the <body> tag.
					case 'head':
						return '';

					case 'html':
					case 'body':
						return $contents;

					case 'a':
						// MediaWiki 1.39 only: remove the links around non-thumbnail images.
						if ( in_array( 'image', $classes ) ) {
							return '';
						}
						break;

					case 'div':
						// MediaWiki 1.39 only: remove wrapper around the thumbnail.
						if ( in_array( 'thumb', $classes ) ) {
							return '';
						}
						break;

					case 'span':
						// MediaWiki 1.40+: remove the wrappers around non-thumbnail images.
						if ( $typeof === 'mw:File' ) {
							return '';
						}
						break;

					case 'figure':
						// MediaWiki 1.40+: remove wrapper around the thumbnail.
						if ( $typeof === 'mw:File/Thumb' ) {
							return '';
						}
						break;

					case 'p':
						// Remove trailing newline inside <p> tags.
						$contents = trim( $contents );
				}

				return parent::element( $parent, $node, $contents );
			}
		};

		$serializer = new Serializer( $formatter );
		$treeBuilder = new TreeBuilder( $serializer );
		$dispatcher = new Dispatcher( $treeBuilder );
		$tokenizer = new Tokenizer( $dispatcher, $html );

		$tokenizer->execute();
		$html = $serializer->getResult();

		return $html;
	}
}
