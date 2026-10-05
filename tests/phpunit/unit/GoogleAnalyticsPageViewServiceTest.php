<?php

namespace MediaWiki\Extension\PageViewInfoGA\Tests\Unit;

use ArrayIterator;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use MediaWiki\Extension\PageViewInfo\PageViewService;
use MediaWiki\Extension\PageViewInfoGA\CredentialsFileTokenProvider;
use MediaWiki\Extension\PageViewInfoGA\GoogleAnalyticsPageViewService;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Page\PageSelectQueryBuilder;
use MediaWiki\Page\PageStore;
use MediaWiki\Title\TitleFormatter;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\PageViewInfoGA\GoogleAnalyticsPageViewService
 */
class GoogleAnalyticsPageViewServiceTest extends MediaWikiUnitTestCase {

	/** @var array[] Requests sent, as [ url, decoded body ] */
	private array $sent = [];

	/** @var string[] Pages in the wiki, DB key by page ID */
	private const PAGES = [ 10 => 'Foo_moved', 20 => 'Bar' ];

	private function newService(
		array $responses, array $options = [], ?StatusValue $tokenStatus = null
	): GoogleAnalyticsPageViewService {
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->method( 'create' )->willReturnCallback(
			function ( string $url, array $options ) use ( &$responses ) {
				$this->sent[] = [ $url, FormatJson::decode( $options['postData'], true ) ];
				[ $status, $body ] = array_shift( $responses );
				$request = $this->createMock( MWHttpRequest::class );
				$request->method( 'execute' )->willReturn( $status );
				$request->method( 'getContent' )->willReturn( $body );
				return $request;
			}
		);
		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->method( 'getPrefixedDBkey' )->willReturnCallback(
			static fn ( $page ) => $page->getDBkey()
		);
		$titleFormatter->method( 'getPrefixedText' )->willReturnCallback(
			static fn ( $page ) => strtr( $page->getDBkey(), '_', ' ' )
		);
		$pageStore = $this->createMock( PageStore::class );
		$pageStore->method( 'newSelectQueryBuilder' )->willReturnCallback( function () {
			$pages = self::PAGES;
			$query = $this->createMock( PageSelectQueryBuilder::class );
			$query->method( 'whereTitles' )->willReturnCallback(
				static function ( $namespace, $dbKeys ) use ( &$pages, $query ) {
					$pages = array_intersect( $pages, $dbKeys );
					return $query;
				}
			);
			$query->method( 'wherePageIds' )->willReturnCallback(
				static function ( $ids ) use ( &$pages, $query ) {
					$pages = array_intersect_key( $pages, array_flip( $ids ) );
					return $query;
				}
			);
			$query->method( 'fetchPageRecords' )->willReturnCallback( static function () use ( &$pages ) {
				$records = [];
				foreach ( $pages as $id => $dbKey ) {
					$records[] = new PageIdentityValue( $id, NS_MAIN, $dbKey, PageIdentityValue::LOCAL );
				}
				return new ArrayIterator( $records );
			} );
			return $query;
		} );
		$tokenProvider = $this->createMock( CredentialsFileTokenProvider::class );
		$tokenProvider->method( 'getAccessToken' )->willReturn( $tokenStatus ?? StatusValue::newGood( 'token' ) );

		return new GoogleAnalyticsPageViewService( $httpRequestFactory, $titleFormatter, $pageStore,
			$tokenProvider, $options + [ 'propertyId' => '123', 'siteName' => 'Wiki' ] );
	}

	private static function report( array $rows ): array {
		return [ StatusValue::newGood(), FormatJson::encode( [ 'rows' => array_map( static fn ( $row ) => [
			'dimensionValues' => array_map( static fn ( $v ) => [ 'value' => $v ], array_slice( $row, 0, -1 ) ),
			'metricValues' => [ [ 'value' => (string)end( $row ) ] ],
		], $rows ) ] ) ];
	}

	private static function day( int $daysAgo ): string {
		return ( new DateTimeImmutable( 'today' ) )->sub( new DateInterval( "P{$daysAgo}D" ) )->format( 'Y-m-d' );
	}

	private static function gaDate( int $daysAgo ): string {
		return str_replace( '-', '', self::day( $daysAgo ) );
	}

	private static function page( string $dbKey ): PageReferenceValue {
		return PageReferenceValue::localReference( NS_MAIN, $dbKey );
	}

	public function testConstructorRejectsAViewId() {
		$this->expectException( InvalidArgumentException::class );
		$this->newService( [], [ 'propertyId' => 'UA-82072330-1' ] );
	}

	public function testGetPageDataByTitle() {
		$service = $this->newService( [ self::report( [
			// Sent as page_title by the Google tag
			[ self::gaDate( 1 ), 'Foo (bar)', 7 ],
			[ self::gaDate( 1 ), '페미위키 대문', 3 ],
			// Older events, with the document title
			[ self::gaDate( 2 ), 'Foo (bar) - 페미위키', 2 ],
			[ self::gaDate( 2 ), 'A - B – 페미위키', 4 ],
			[ self::gaDate( 1 ), '123 - 페미위키', 1 ],
			[ self::gaDate( 1 ), 'Unrelated - 페미위키', 99 ],
		] ) ], [ 'siteName' => '페미위키' ] );
		$status = $service->getPageData( [
			self::page( 'Foo_(bar)' ), self::page( 'A_-_B' ), self::page( '페미위키_대문' ), self::page( '123' ),
		], 2 );

		$this->assertTrue( $status->isGood() );
		$this->assertSame( [ 'Foo_(bar)' => true, 'A_-_B' => true, '페미위키_대문' => true, '123' => true ],
			$status->success );
		$this->assertSame( [
			'Foo_(bar)' => [ self::day( 2 ) => 2, self::day( 1 ) => 7 ],
			'A_-_B' => [ self::day( 2 ) => 4, self::day( 1 ) => 0 ],
			'페미위키_대문' => [ self::day( 2 ) => 0, self::day( 1 ) => 3 ],
			'123' => [ self::day( 2 ) => 0, self::day( 1 ) => 1 ],
		], $status->getValue() );

		$request = $this->sent[0][1];
		$this->assertSame( [ [ 'startDate' => self::day( 2 ), 'endDate' => self::day( 1 ) ] ], $request['dateRanges'] );
		$this->assertSame( [ [ 'name' => 'screenPageViews' ] ], $request['metrics'] );
		$this->assertSame( 'pageTitle', $request['dimensions'][1]['name'] );
		$expressions = $request['dimensionFilter']['orGroup']['expressions'];
		$this->assertSame( 'Foo \(bar\)( [-–—·] 페미위키)?', $expressions[0]['filter']['stringFilter']['value'] );
		// A numeric title stays a string in the request
		$this->assertSame( '123( [-–—·] 페미위키)?', $expressions[3]['filter']['stringFilter']['value'] );
	}

	public function testGetPageDataDoesNotCountALongerTitle() {
		// The filter for "A" also matches the page_title "A - B", as the site name is unknown to GA4
		$service = $this->newService( [ self::report( [
			[ self::gaDate( 1 ), 'A - B', 5 ],
			[ self::gaDate( 1 ), 'A - Wiki', 1 ],
		] ) ] );
		$status = $service->getPageData( [ self::page( 'A' ) ], 1 );

		$this->assertSame( [ 'A' => [ self::day( 1 ) => 1 ] ], $status->getValue() );
	}

	public function testGetPageDataInChunks() {
		$titles = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$titles[] = self::page( "P$i" );
		}
		$service = $this->newService( [
			[ StatusValue::newFatal( 'http-bad-status', 500, 'Error' ), '{}' ],
			self::report( [ [ self::gaDate( 1 ), 'P59', 4 ] ] ),
		] );
		$status = $service->getPageData( $titles, 1 );

		$this->assertCount( 2, $this->sent );
		$this->assertCount( 50, $this->sent[0][1]['dimensionFilter']['orGroup']['expressions'] );
		$this->assertCount( 10, $this->sent[1][1]['dimensionFilter']['orGroup']['expressions'] );
		$this->assertTrue( $status->isOK(), 'OK while some pages have data' );
		$this->assertSame( 10, $status->successCount );
		$this->assertSame( 50, $status->failCount );
		$this->assertFalse( $status->success['P0'] );
		$this->assertSame( [ self::day( 1 ) => null ], $status->getValue()['P0'] );
		$this->assertSame( [ self::day( 1 ) => 4 ], $status->getValue()['P59'] );
	}

	public function testGetPageDataWithoutAToken() {
		$service = $this->newService( [], [], StatusValue::newFatal( 'pageviewinfoga-error-credentials' ) );
		$status = $service->getPageData( [ self::page( 'Foo' ) ], 1 );

		$this->assertFalse( $status->isOK() );
		$this->assertSame( [ 'Foo' => false ], $status->success );
		$this->assertSame( [], $this->sent );
	}

	public function testGetPageDataByPageId() {
		$service = $this->newService( [ self::report( [ [ self::gaDate( 1 ), '10', 5 ] ] ) ],
			[ 'readCustomDimensions' => true ] );
		$status = $service->getPageData( [ self::page( 'Foo_moved' ), self::page( 'Missing' ) ], 1,
			PageViewService::METRIC_UNIQUE );

		$this->assertSame( [ 'Foo_moved' => true, 'Missing' => true ], $status->success );
		$this->assertSame( [
			'Foo_moved' => [ self::day( 1 ) => 5 ],
			'Missing' => [ self::day( 1 ) => 0 ],
		], $status->getValue() );
		$request = $this->sent[0][1];
		$this->assertSame( [ [ 'name' => 'totalUsers' ] ], $request['metrics'] );
		$this->assertSame( [
			'fieldName' => 'customEvent:mw_page_id',
			'inListFilter' => [ 'values' => [ '10' ] ],
		], $request['dimensionFilter']['filter'] );
	}

	public function testGetPageDataOfMissingPagesByPageId() {
		$service = $this->newService( [], [ 'readCustomDimensions' => true ] );
		$status = $service->getPageData( [ self::page( 'Missing' ) ], 1 );

		$this->assertSame( [ 'Missing' => [ self::day( 1 ) => 0 ] ], $status->getValue() );
		$this->assertSame( [], $this->sent, 'No report for pages without an ID' );
	}

	public function testGetPageDataFailure() {
		$service = $this->newService( [ [ StatusValue::newFatal( 'http-bad-status', 403, 'Forbidden' ),
			'{"error":{"message":"User does not have sufficient permissions"}}' ] ] );
		$status = $service->getPageData( [ self::page( 'Foo' ) ], 1 );

		$this->assertFalse( $status->isOK() );
		$this->assertSame( [ 'Foo' => false ], $status->success );
		$this->assertSame( [ 'Foo' => [ self::day( 1 ) => null ] ], $status->getValue() );
	}

	public function testGetSiteData() {
		// The property ID as the API names it
		$service = $this->newService( [ self::report( [ [ self::gaDate( 2 ), 40 ] ] ) ],
			[ 'propertyId' => 'properties/123' ] );
		$status = $service->getSiteData( 2 );

		$this->assertSame( [ self::day( 2 ) => 40, self::day( 1 ) => 0 ], $status->getValue() );
		$this->assertStringEndsWith( '/properties/123:runReport', $this->sent[0][0] );
	}

	public function testGetTopPagesByTitle() {
		$service = $this->newService( [ self::report( [
			[ 'Foo', 9 ],
			[ '(not set)', 4 ],
			[ '스타워즈 - 새로운 희망', 3 ],
			[ 'Bar baz - Wiki', 3 ],
			[ '(other)', 2 ],
		] ) ] );
		$status = $service->getTopPages();

		$this->assertSame( [ 'Foo' => 9, '스타워즈_-_새로운_희망' => 3, 'Bar_baz' => 3 ], $status->getValue() );
		$this->assertSame( [ [ 'metric' => [ 'metricName' => 'screenPageViews' ], 'desc' => true ] ],
			$this->sent[0][1]['orderBys'] );
	}

	public function testGetTopPagesByPageId() {
		$service = $this->newService( [ self::report( [
			[ '10', 9 ],
			[ '(not set)', 4 ],
			// Deleted
			[ '99', 3 ],
			[ '20', 2 ],
		] ) ], [ 'readCustomDimensions' => true ] );
		$status = $service->getTopPages();

		// By the current title of each page
		$this->assertSame( [ 'Foo_moved' => 9, 'Bar' => 2 ], $status->getValue() );
	}
}
