<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Integration;

use Exception;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GoogleAnalyticsPageViewService;
use MediaWikiIntegrationTestCase;
use RuntimeException;
use Status;
use Title;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\GoogleAnalyticsPageViewService
 */
class GoogleAnalyticsPageViewServiceTest extends MediaWikiIntegrationTestCase {
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

	public function testGetPageData() {
		$service = new GoogleAnalyticsPageViewService( [
			'credentialsFile' => false,
			'profileId' => '123456'
		] );
		$this->mockDate( $service, '2000-01-05' );

		// valid request
		foreach ( [ 'Foo', 'Bar' ] as $page ) {
			$this->mockNextBatchGet( $service, [
				(object)[
					'dimensions' => [ '20000101' ],
					'metrics' => [ (object)[ 'values' => [ $page === 'Foo' ? '1000' : '500' ] ] ]
				],
				(object)[
					'dimensions' => [ '20000102' ],
					'metrics' => [ (object)[ 'values' => [ $page === 'Foo' ? '100' : '50' ] ] ]
				],
				(object)[
					'dimensions' => [ '20000104' ],
					'metrics' => [ (object)[ 'values' => [ $page === 'Foo' ? '10' : '5' ] ] ]
				],
			] );
		}

		$status = $service->getPageData( [
			Title::newFromText( 'Foo' ),
			Title::newFromText( 'Bar' )
		], 5 );
		if ( !$status->isGood() ) {
			$this->fail( Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'Foo' => [
				'2000-01-01' => 1000,
				'2000-01-02' => 100,
				'2000-01-03' => null,
				'2000-01-04' => 10,
				'2000-01-05' => null,
			],
			'Bar' => [
				'2000-01-01' => 500,
				'2000-01-02' => 50,
				'2000-01-03' => null,
				'2000-01-04' => 5,
				'2000-01-05' => null,
			],
		], $status->getValue() );
		$this->assertSame( [ 'Foo' => true, 'Bar' => true ], $status->success );
		$this->assertSame( 2, $status->successCount );
		$this->assertSame( 0, $status->failCount );

		$this->mockDate( $service, '2000-01-01' );
		// valid, no result and error, combined
		self::$batches = [];
		$this->mockNextBatchGet( $service, [
			(object)[
				'dimensions' => [ '20000101' ],
				'metrics' => [ (object)[ 'values' => [ '1' ] ] ]
			]
		] );
		$this->mockNextBatchGet( $service, [] );
		$this->mockNextBatchGet( $service, false );
		$status = $service->getPageData( [ Title::newFromText( 'A' ),
			Title::newFromText( 'B' ), Title::newFromText( 'C' ) ], 1 );
		$this->assertFalse( $status->isGood() );
		if ( !$status->isOK() ) {
			$this->fail( Status::wrap( $status )->getWikiText() );
		}
		$this->assertSame( [
			'A' => [
				'2000-01-01' => 1,
			],
			'B' => [
				'2000-01-01' => null,
			],
			'C' => [
				'2000-01-01' => null,
			],
		], $status->getValue() );
		$this->assertSame( [ 'A' => true, 'B' => true, 'C' => false ], $status->success );
		$this->assertSame( 2, $status->successCount );
		$this->assertSame( 1, $status->failCount );

		// all error out
		self::$batches = [];
		$this->mockNextBatchGet( $service, false );
		$this->mockNextBatchGet( $service, false );
		$status = $service->getPageData( [ Title::newFromText( 'A' ), Title::newFromText( 'B' ) ], 1 );
		$this->assertFalse( $status->isOK() );
		$this->assertSame( [ 'A' => false, 'B' => false ], $status->success );
		$this->assertSame( 0, $status->successCount );
		$this->assertSame( 2, $status->failCount );
	}
}
