<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use Exception;
use InvalidArgumentException;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GoogleAnalyticsPageViewService;
use MediaWiki\Extensions\PageViewInfo\PageViewService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Status;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\GoogleAnalyticsPageViewService
 */
class GoogleAnalyticsPageViewServiceTest extends TestCase {
	/** @var [ stdClass ] */
	public static $batches = [];

	public function setUp() : void {
		parent::setUp();
		self::$batches = [];
	}

	protected function assertThrows( $class, callable $test ) {
		try {
			$test();
		} catch ( Exception $e ) {
			$this->assertInstanceOf( $class, $e );
			return;
		}
		$this->fail( 'No exception was thrown, expected ' . $class );
	}

	/**
	 * Prepare the mock \Google_Service_Analytics which will be used for the next call
	 * @param GoogleAnalyticsPageViewService $service
	 * @param array|false $rows If false error is thrown
	 * @throws RuntimeException
	 */
	protected function mockNextBatchGet(
		GoogleAnalyticsPageViewService $service, $rows
	) {
		self::$batches[] = $rows;
		$wrapper = TestingAccessWrapper::newFromObject( $service );
		$wrapper->analytics = (object)[
			'reports' => new class {
				public function batchGet() {
					return new class {
						public function getReports() {
							return [
								new class {
									public function getData() {
										return new class {
											public function getRows() {
												$batch = array_shift( GoogleAnalyticsPageViewServiceTest::$batches );
												if ( $batch === false ) {
													throw new RuntimeException();
												}
												return $batch;
											}
										};
									}
								}
							];
						}
					};
				}
			}
		];
	}

	/**
	 * Changes the start/end dates
	 * @param GoogleAnalyticsPageViewService $service
	 * @param string $end YYYY-MM-DD
	 */
	protected function mockDate( GoogleAnalyticsPageViewService $service, $end ) {
		$wrapper = TestingAccessWrapper::newFromObject( $service );
		$wrapper->lastCompleteDay = strtotime( $end . 'T00:00Z' );
		$wrapper->range = null;
	}

	public function testConstructor() {
		$this->assertThrows( InvalidArgumentException::class, static function () {
			new GoogleAnalyticsPageViewService( [] );
		} );
		$this->assertThrows( InvalidArgumentException::class, static function () {
			new GoogleAnalyticsPageViewService( [
				'credentialsFile' => 'non-exist-file.json',
				'profileId' => 'foobar'
			] );
		} );
		new GoogleAnalyticsPageViewService( [
			'credentialsFile' => false,
			'profileId' => '123456'
		] );
	}

	public function testGetSiteData() {
		$service = new GoogleAnalyticsPageViewService( [
			'credentialsFile' => false,
			'profileId' => '123456'
		] );
		$this->mockDate( $service, '2000-01-05' );

		// valid request
		$this->mockNextBatchGet( $service, [
			(object)[
				'dimensions' => [ '20000101' ],
				'metrics' => [ (object)[ 'values' => [ '1000' ] ] ]
			],
			(object)[
				'dimensions' => [ '20000102' ],
				'metrics' => [ (object)[ 'values' => [ '100' ] ] ]
			],
			(object)[
				'dimensions' => [ '20000104' ],
				'metrics' => [ (object)[ 'values' => [ '10' ] ] ]
			]
		] );
		$status = $service->getSiteData( 5 );
		if ( !$status->isGood() ) {
			$this->fail( Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'2000-01-01' => 1000,
			'2000-01-02' => 100,
			'2000-01-03' => null,
			'2000-01-04' => 10,
			'2000-01-05' => null,
		], $status->getValue() );

		// no result
		self::$batches = [];
		$this->mockNextBatchGet( $service, [] );
		$status = $service->getSiteData( 5 );
		if ( !$status->isGood() ) {
			$this->fail( Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'2000-01-01' => null,
			'2000-01-02' => null,
			'2000-01-03' => null,
			'2000-01-04' => null,
			'2000-01-05' => null,
		], $status->getValue() );

		// genuine error
		self::$batches = [];
		$this->mockNextBatchGet( $service, false );
		$status = $service->getSiteData( 5 );
		$this->assertFalse( $status->isOK() );
	}

	public function testGetSiteData_unique() {
		$service = new GoogleAnalyticsPageViewService( [
			'credentialsFile' => false,
			'profileId' => '123456'
		] );
		$this->mockDate( $service, '2000-01-05' );

		// valid request
		$this->mockNextBatchGet( $service, [
			(object)[
				'dimensions' => [ '20000101' ],
				'metrics' => [ (object)[ 'values' => [ '1000' ] ] ]
			],
			(object)[
				'dimensions' => [ '20000102' ],
				'metrics' => [ (object)[ 'values' => [ '100' ] ] ]
			],
			(object)[
				'dimensions' => [ '20000104' ],
				'metrics' => [ (object)[ 'values' => [ '10' ] ] ]
			]
		] );
		$status = $service->getSiteData( 5, PageViewService::METRIC_UNIQUE );
		if ( !$status->isGood() ) {
			$this->fail( Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'2000-01-01' => 1000,
			'2000-01-02' => 100,
			'2000-01-03' => null,
			'2000-01-04' => 10,
			'2000-01-05' => null,
		], $status->getValue() );

		// no result
		self::$batches = [];
		$this->mockNextBatchGet( $service, [] );
		$status = $service->getSiteData( 5, PageViewService::METRIC_UNIQUE );
		if ( !$status->isGood() ) {
			$this->fail( Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'2000-01-01' => null,
			'2000-01-02' => null,
			'2000-01-03' => null,
			'2000-01-04' => null,
			'2000-01-05' => null,
		], $status->getValue() );

		// genuine error
		self::$batches = [];
		$this->mockNextBatchGet( $service, false );
		$status = $service->getSiteData( 5, PageViewService::METRIC_UNIQUE );
		$this->assertFalse( $status->isOK() );
	}

	public function testGetTopPages() {
		$service = new GoogleAnalyticsPageViewService( [
			'credentialsFile' => false,
			'profileId' => '123456'
		] );
		$this->mockDate( $service, '2000-01-05' );

		// valid request
		$this->mockNextBatchGet( $service, [
			(object)[
				'dimensions' => [ "Main Page - ExampleWiki" ],
				'metrics' => [ (object)[ 'values' => [ '1000' ] ] ]
			],
			(object)[
				'dimensions' => [ 'Special:Search - ExampleWiki' ],
				'metrics' => [ (object)[ 'values' => [ '100' ] ] ]
			],
			(object)[
				'dimensions' => [ '404.php' ],
				'metrics' => [ (object)[ 'values' => [ '10' ] ] ]
			]
		] );
		$status = $service->getTopPages();
		if ( !$status->isGood() ) {
			$this->fail( Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'Main_Page' => 1000,
			'Special:Search' => 100,
			'404.php' => 10,
		], $status->getValue() );

		// no result
		self::$batches = [];
		$this->mockNextBatchGet( $service, [] );
		$status = $service->getTopPages();
		if ( !$status->isGood() ) {
			$this->fail( Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [], $status->getValue() );

		// genuine error
		self::$batches = [];
		$this->mockNextBatchGet( $service, false );
		$status = $service->getTopPages();
		$this->assertFalse( $status->isOK() );
	}
}
