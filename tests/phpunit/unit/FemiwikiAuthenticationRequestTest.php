<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\FemiwikiAuthenticationRequest;
use MediaWikiUnitTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 */
class FemiwikiAuthenticationRequestTest extends MediaWikiUnitTestCase {

	/**
	 * @covers \MediaWiki\Extension\UnifiedExtensionForFemiwiki\FemiwikiAuthenticationRequest::getFieldInfo
	 */
	public function testGetFieldInfo() {
		$req = new FemiwikiAuthenticationRequest( 'foo', wfMessage( 'bar' ), wfMessage( 'baz' ) );
		$this->assertArrayHasKey( 'femiwikiOpenSesame', $req->getFieldInfo() );
	}

}
