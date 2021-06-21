<?php

namespace MediaWiki\Extension\PageViewInfoGA\Tests\Unit;

use Exception;
use InvalidArgumentException;
use MediaWiki\Extension\PageViewInfoGA\GoogleAnalyticsPageViewService;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\PageViewInfoGA\GoogleAnalyticsPageViewService
 */
class GoogleAnalyticsPageViewServiceTest extends MediaWikiUnitTestCase {

	protected function assertThrows( $class, callable $test ) {
		try {
			$test();
		} catch ( Exception $e ) {
			$this->assertInstanceOf( $class, $e );
			return;
		}
		$this->fail( 'No exception was thrown, expected ' . $class );
	}

	public function testConstructor() {
		$this->assertThrows( InvalidArgumentException::class, static function () {
			new GoogleAnalyticsPageViewService( [] );
		} );
		$this->assertThrows( InvalidArgumentException::class, static function () {
			new GoogleAnalyticsPageViewService( [
				'credentialsFile' => 'non-exist-file.json',
				'profileId' => 'foo-1'
			] );
		} );
		new GoogleAnalyticsPageViewService( [
			'credentialsFile' => false,
			'profileId' => 'foo-1'
		] );
	}

}
