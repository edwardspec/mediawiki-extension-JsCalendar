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
	/** @var string[] */
	protected $tablesUsed = [ 'page' ];

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
			var_export( $expectedData, true ),
			var_export( $actualData, true ),
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

		yield 'calendar with prefix, namespace=Template' => [
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
			"namespace = Template\nprefix = Today_in_History/\ndateFormat = F,_j",
			[
				[
					'title' => 'Today in History',
					'start' => '2022-04-12',
					'end' => '2022-04-13',
					'url' => '/wiki/Template:Today_in_History/April,_12'
				],
				[
					'title' => 'Today in History',
					'start' => '2022-05-01',
					'end' => '2022-05-02',
					'url' => '/wiki/Template:Today_in_History/May,_1'
				],
				[
					'title' => 'Today in History',
					'start' => '2022-12-25',
					'end' => '2022-12-26',
					'url' => '/wiki/Template:Today_in_History/December,_25'
				],
				[
					'title' => 'Today in History',
					'start' => '2022-12-31',
					'end' => '2023-01-01',
					'url' => '/wiki/Template:Today_in_History/December,_31'
				]
			]
		];

		yield 'calendar with suffix, default namespace (NS_MAIN)' => [
			[
				'1 May (events)' => 'Events on May 1',
				'Page 1, unrelated to the calendar' => 'Text 1',
				'3 May (events)' => 'Events on May 3',
				'5 May (events)' => 'Events on May 5',
				'3, May (events)' => 'Wrong date format, won\'t be shown in the calendar',
				'Events/3 May' => 'No suffix, won\'t be shown in the calendar',
				'Page 2, unrelated to the calendar' => 'Text 2',
				'25 December (events)' => 'Events on December 25'
			],
			"suffix = _(events)\ndateFormat = j_F",
			[
				[
					'title' => '(events)',
					'start' => '2022-05-01',
					'end' => '2022-05-02',
					'url' => '/wiki/1_May_(events)'
				],
				[
					'title' => '(events)',
					'start' => '2022-05-03',
					'end' => '2022-05-04',
					'url' => '/wiki/3_May_(events)'
				],
				[
					'title' => '(events)',
					'start' => '2022-05-05',
					'end' => '2022-05-06',
					'url' => '/wiki/5_May_(events)'
				],
				[
					'title' => '(events)',
					'start' => '2022-12-25',
					'end' => '2022-12-26',
					'url' => '/wiki/25_December_(events)'
				]
			]
		];

		yield 'calendar with titleRegex' => [
			[
				'Category:2022/01/15 Name1' => 'Text1',
				'Page 1, unrelated to the calendar' => 'Text 1',
				'Category:2022/02/16 Name2' => 'Text2',
				'Category:25 December 2022 Wrong Date Format' => 'Won\'t be shown in the calendar',
				'Category:2022/03/17 Name3' => 'Text3',
				'Category:2022/04/18 Name4' => 'Text4',
				'Page 2, unrelated to the calendar' => 'Text 2'
			],
			"namespace = Category\ntitleRegex = ^([0-9]{4,4}/[0-9][0-9]/[0-9][0-9])_.*\ndateFormat = Y/m/d",
			[
				[
					'title' => 'Name1',
					'start' => '2022-01-15',
					'end' => '2022-01-16',
					'url' => '/wiki/Category:2022/01/15_Name1'
				],
				[
					'title' => 'Name2',
					'start' => '2022-02-16',
					'end' => '2022-02-17',
					'url' => '/wiki/Category:2022/02/16_Name2'
				],
				[
					'title' => 'Name3',
					'start' => '2022-03-17',
					'end' => '2022-03-18',
					'url' => '/wiki/Category:2022/03/17_Name3'
				],
				[
					'title' => 'Name4',
					'start' => '2022-04-18',
					'end' => '2022-04-19',
					'url' => '/wiki/Category:2022/04/18_Name4'
				]
			]
		];

		yield 'calendar with categorycolor' => [
			[
				'Munchkin cat adoption 01.01' => 'Events on January 1. [[Category:Cat events]]',
				'Dachshung dog adoption 15.01' => 'Events on January 15. [[Category:Dogs]]',
				'Released the recovered eagles 16.01' => 'Events on January 16.',
				'Bought extra food for bears 31.07' => 'Events on July 31. [[Category:Food purchase]]',
				'Sphinx cat adoption 25.12' => 'Events on December 25. [[Category:Cat events]]',
				'Ferret adoption 31.12' => 'Events on December 31. [[Category:Ferrets]]'
			],
			"titleRegex = .*([0-9][0-9]\.[0-9][0-9])$\ndateFormat = d.m\ncategorycolor.Cat events = green\n" .
				"categorycolor.Dogs = red\ncategorycolor.Ferrets = yellow",
			[
				[
					'title' => 'Bought extra food for bears',
					'start' => '2022-07-31',
					'end' => '2022-08-01',
					'url' => '/wiki/Bought_extra_food_for_bears_31.07'
					// no color: no "categorycolor" parameter for this category
				],
				[
					'title' => 'Dachshung dog adoption',
					'start' => '2022-01-15',
					'end' => '2022-01-16',
					'url' => '/wiki/Dachshung_dog_adoption_15.01',
					'color' => 'red'
				],
				[
					'title' => 'Ferret adoption',
					'start' => '2022-12-31',
					'end' => '2023-01-01',
					'url' => '/wiki/Ferret_adoption_31.12',
					'color' => 'yellow'
				],
				[
					'title' => 'Munchkin cat adoption',
					'start' => '2022-01-01',
					'end' => '2022-01-02',
					'url' => '/wiki/Munchkin_cat_adoption_01.01',
					'color' => 'green'
				],
				[
					'title' => 'Released the recovered eagles',
					'start' => '2022-01-16',
					'end' => '2022-01-17',
					'url' => '/wiki/Released_the_recovered_eagles_16.01'
					// no color: this page doesn't have any categories
				],
				[
					'title' => 'Sphinx cat adoption',
					'start' => '2022-12-25',
					'end' => '2022-12-26',
					'url' => '/wiki/Sphinx_cat_adoption_25.12',
					'color' => 'green'
				]
			]
		];

		yield 'calendar with keywordcolor (both title and text can cause a match, case-insensitive)' => [
			[
				'Munchkin cat adoption 01.01' => 'Events on January 1.',
				'Dachshung dog adoption 15.01' => 'Events on January 15.',
				'Bought extra food for bears 31.07' => 'Events on July 31.',
				'Sphinx adoption 25.12' =>
					'December 25: have the word "cat" in page text, but not in page title: still used by keywordcolor.',
				'German Shepherd Dog adoption 26.12' => 'Events on December 26.',
				'Unknown animal brought 31.12' =>
					'December 31: somebody brought an unknown animal, possibly a ferret.'
			],
			"titleRegex = .*([0-9][0-9]\.[0-9][0-9])$\ndateFormat = d.m\nkeywordcolor.Cat = green\n" .
				"keywordcolor.dog = red\nkeywordcolor.Ferret = yellow",
			[
				[
					'title' => 'Bought extra food for bears',
					'start' => '2022-07-31',
					'end' => '2022-08-01',
					'url' => '/wiki/Bought_extra_food_for_bears_31.07'
					// no color: neither title nor text match any of the keywords
				],
				[
					'title' => 'Dachshung dog adoption',
					'start' => '2022-01-15',
					'end' => '2022-01-16',
					'url' => '/wiki/Dachshung_dog_adoption_15.01',
					'color' => 'red'
				],
				[
					'title' => 'German Shepherd Dog adoption',
					'start' => '2022-12-26',
					'end' => '2022-12-27',
					'url' => '/wiki/German_Shepherd_Dog_adoption_26.12',
					'color' => 'red'
				],
				[
					'title' => 'Munchkin cat adoption',
					'start' => '2022-01-01',
					'end' => '2022-01-02',
					'url' => '/wiki/Munchkin_cat_adoption_01.01',
					'color' => 'green'
				],
				[
					'title' => 'Sphinx adoption',
					'start' => '2022-12-25',
					'end' => '2022-12-26',
					'url' => '/wiki/Sphinx_adoption_25.12',
					'color' => 'green'
				],
				[
					'title' => 'Unknown animal brought',
					'start' => '2022-12-31',
					'end' => '2023-01-01',
					'url' => '/wiki/Unknown_animal_brought_31.12',
					'color' => 'yellow'

				]
			]
		];
	}
}
