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

/**
 * Integration test of <eventcalendar> tag.
 *
 * @group Database
 * @covers \MediaWiki\JsCalendar\EventCalendar
 */
class EventCalendarTest extends MediaWikiIntegrationTestCase {
	/**
	 * Feed various wikitext with <eventcalendar> tag to the Parser and check if the output is correct.
	 * @dataProvider dataProvider
	 * @param array[] $pages Pages to precreate before the test, e.g. [ 'title1' => 'text1', ... ]
	 * @param string $wikitext Contents of <eventcalendar> tag (without the tag itself).
	 * @param array $expectedData Data that is expected to provided to JavaScript library.
	 *
	 * @phan-param array<string,string> $pages
	 */
	public function testEventCalendar(
		array $pages,
		$wikitext,
		array $expectedData
	) {
		// Precreate the articles.
		foreach ( $pages as $pageName => $pageText ) {
			$this->insertPage( $pageName, $pageText );
		}

		// Parse the wikitext.
		$parser = $this->getServiceContainer()->getParser();
		$title = Title::newFromText( 'Title of page with the calendar itself' );
		$popt = ParserOptions::newFromAnon();

		$pout = $parser->parse( "<eventcalendar>$wikitext</eventcalendar>", $title, $popt );
		$pout->clearWrapperDivClass();
		$parsedHTML = $pout->getText();

		$this->assertSame( [ 'ext.yasec' ], $pout->getModules(),
			'ParserOutput: necessary JavaScript module wasn\'t added.' );

		$matches = null;
		$matchResult = preg_match( '@window.eventCalendarData.push\( (.*) \);\s*</script>@',
			$parsedHTML, $matches );
		$this->assertSame( 1, $matchResult, 'No calendar data found in the HTML.' );

		$status = FormatJson::parse( $matches[1], FormatJson::FORCE_ASSOC );
		$this->assertTrue( $status->isGood(),
			'Failed to parse the JSON of calendar data: ' . $status->getMessage()->plain() );

		$actualData = $status->getValue();

		// We are not comparing $actualData and $expectedData directly with assertEquals,
		// because PHPUnit will truncate the output if the multi-level arrays are different,
		// and truncated outputs are useless for troubleshooting.
		$this->assertEquals(
			FormatJson::encode( $expectedData, true, FormatJson::ALL_OK ),
			FormatJson::encode( $actualData, true, FormatJson::ALL_OK ),
			'Unexpected data was provided to the JavaScript that renders the calendar.' );
	}

	/**
	 *
	 * Provides datasets for testEventCalendar().
	 */
	public function dataProvider() {
		yield 'calendar without any pages' => [
			[ 'Page1' => 'Contents of page 1', 'Page2' => 'Contents of page2' ],
			'',
			[]
		];

		yield 'calendar with prefix' => [
			[
				'Template:Today in History/April, 12' => 'Events on April 12',
				'Page 1, unrelated to the calendar' => 'Text 1',
				'Template:Today in History/May, 1' => 'Events on May 1',
				'Template:Today in History/Wrong Date Format' => 'Events that won\'t be shown in the calendar',
				'Template:Today in History/December, 25' => 'Events on December 25',
				'Today in History/December, 27' => 'Page in the wrong namespace, won\'t be shown in the calendar',
				'Template:Today in History/December, 31' => 'New Year Eve',
				'Page 2, unrelated to the calendar' => 'Text 2'
			],
			"namespace = Template\naspectratio = 1.35\nprefix = Today_in_History/\nsuffix =\ndateFormat = F,_j",
			[
				[
					'title' => 'Today in History/April, 12',
					'start' => '2022-04-12',
					'end' => '2022-04-13',
					'url' => '/wiki/Template:Today_in_History/April,_12'
				],
				[
					'title' => 'Today in History/December, 25',
					'start' => '2022-12-25',
					'end' => '2022-12-26',
					'url' => '/wiki/Template:Today_in_History/December,_25'
				],
				[
					'title' => 'Today in History/December, 31',
					'start' => '2022-12-31',
					'end' => '2023-01-01',
					'url' => '/wiki/Template:Today_in_History/December,_31'
				],
				[
					// Order of titles in this array is alphabetic, so May entries are after December.
					'title' => 'Today in History/May, 1',
					'start' => '2022-05-01',
					'end' => '2022-05-02',
					'url' => '/wiki/Template:Today_in_History/May,_1'
				]
			]
		];
	}
}
