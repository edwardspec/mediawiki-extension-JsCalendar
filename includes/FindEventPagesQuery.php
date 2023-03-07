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

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use ObjectCache;
use Wikimedia\Rdbms\FakeResultWrapper;

class FindEventPagesQuery {

	/**
	 * Limit of 5000 selected rows should limit output volume to about 300 KiB,
	 * assuming 60 bytes per entry.
	 */
	protected const MAX_LIMIT = 5000;

	/**
	 * @var \Wikimedia\Rdbms\IDatabase
	 */
	protected $dbr;

	/**
	 * @var string[]
	 */
	protected $tables = [ 'page' ];

	/**
	 * @var string[]
	 */
	protected $fields = [ 'page_title AS title', 'page_latest AS latest' ];

	/**
	 * @var array
	 */
	protected $where = [];

	/**
	 * @var array
	 */
	protected $options = [
		'GROUP BY' => [
			'page_title',
			'page_latest'
		],
		'LIMIT' => 5000
	];

	/**
	 * @var array
	 */
	protected $joinConds = [];

	public function __construct() {
		$this->dbr = wfGetDB( DB_REPLICA );
	}

	/**
	 * Restrict the namespace of event pages.
	 * @param int $namespaceIndex
	 */
	public function setNamespace( $namespaceIndex ) {
		$this->where['page_namespace'] = $namespaceIndex;
	}

	/**
	 * Restrict the start/end of the pagename of event pages.
	 * @param string $prefix
	 * @param string $suffix
	 */
	public function setPrefixAndSuffix( $prefix, $suffix ) {
		if ( !$prefix && !$suffix ) {
			return;
		}

		$this->where[] = 'page_title ' .
			$this->dbr->buildLike( $prefix, $this->dbr->anyString(), $suffix );
	}

	/**
	 * Find the event pages by a regex (for RLIKE) that matches PageName of event page.
	 * @param string $regex
	 */
	public function setTitleRegex( $regex ) {
		if ( !$regex ) {
			// Not used. Prefix and suffix are considered to be fixed strings,
			// and everything except them is considered date.
			return;
		}

		$this->where[] = 'page_title RLIKE ' . $this->dbr->addQuotes( $regex );
	}

	/**
	 * @param int $limit
	 */
	public function setLimit( $limit ) {
		if ( $limit <= 0 ) {
			return;
		}

		if ( $limit > self::MAX_LIMIT ) {
			$limit = self::MAX_LIMIT;
		}

		$this->options['LIMIT'] = $limit;
	}

	/**
	 * Select the name of category from $categoryNames to which each event page belongs (if any).
	 * @param string[] $categoryNames
	 */
	public function detectCategories( array $categoryNames ) {
		if ( !$categoryNames ) {
			return;
		}

		$this->tables[] = 'categorylinks';
		$this->fields[] = 'cl_to AS category';
		$this->joinConds['categorylinks'] = [
			'LEFT JOIN',
			[
				'cl_from=page_id',
				'cl_to' => $categoryNames
			]
		];

		// If the page belongs to 2+ colored categories, only one of them will affect the color.
		// Currently we don't care which category's color will be applied.
		$this->options['GROUP BY'][] = 'cl_to';
	}

	/**
	 * Enable the selection of the raw wikitext for every selected page.
	 */
	public function obtainWikitext() {
		$this->tables[] = 'revision';
		$this->tables[] = 'slot_roles';
		$this->tables[] = 'slots';
		$this->tables[] = 'content';
		$this->fields[] = 'content_address';

		// There is only 1 row, but not having this GROUP BY would be an error in ONLY_FULL_GROUP_BY mode.
		$this->options['GROUP BY'][] = 'content_address';

		$this->joinConds['revision'] = [
			'INNER JOIN',
			[ 'rev_id=page_latest' ]
		];
		$this->joinConds['slot_roles'] = [
			'INNER JOIN',
			[ 'role_name' => SlotRecord::MAIN ]
		];
		$this->joinConds['slots'] = [
			'INNER JOIN',
			[
				'slot_revision_id=rev_id',
				'slot_role_id=role_id'
			]
		];
		$this->joinConds['content'] = [
			'INNER JOIN',
			[ 'content_id=slot_content_id' ]
		];
	}

	/**
	 * Enable the selection of already generated/cached HTML snippet for every selected page.
	 */
	public function obtainCachedSnippet() {
		$this->tables[] = 'objectcache';
		$this->fields[] = 'value AS snippet';

		// There is only 1 row, but not having this GROUP BY would be an error in ONLY_FULL_GROUP_BY mode.
		$this->options['GROUP BY'][] = 'value';

		$cachePrefix = ObjectCache::getInstance( CACHE_DB )->makeKey( 'jscalendar-snippet-' );

		$this->joinConds['objectcache'] = [
			'LEFT JOIN',
			[ 'keyname=CONCAT(' . $this->dbr->addQuotes( $cachePrefix ) . ',page_latest)' ]
		];
	}

	/**
	 * Actually execute the SQL query and return its result (as iterator of $row objects).
	 * @return \Wikimedia\Rdbms\IResultWrapper
	 */
	public function getResult() {
		$res = $this->dbr->select(
			$this->tables,
			$this->fields,
			$this->where,
			__METHOD__,
			$this->options,
			$this->joinConds
		);

		if ( isset( $this->joinConds['content'] ) ) {
			// Need to convert $row->content_address to $row->text for all rows.
			$blobAddresses = [];
			foreach ( $res as $row ) {
				$blobAddresses[] = $row->content_address;
			}

			$status = MediaWikiServices::getInstance()->getBlobStore()->getBlobBatch( $blobAddresses );
			if ( $status->isOK() ) {
				$modifiedRows = [];
				foreach ( $res as $row ) {
					$row->text = $status->value[$row->content_address];
					$modifiedRows[] = $row;
				}

				$res = new FakeResultWrapper( $modifiedRows );
			}
		}

		return $res;
	}
}
