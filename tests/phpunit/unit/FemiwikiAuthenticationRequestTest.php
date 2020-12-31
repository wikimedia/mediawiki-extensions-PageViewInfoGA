<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Tests\Unit;

use FemiwikiAuthenticationRequest;
use MediaWikiUnitTestCase;

/**
 * @group UnifiedExtensionForFemiwiki
 */
class FemiwikiAuthenticationRequestTest extends MediaWikiUnitTestCase {

	/**
	 * @covers \FemiwikiAuthenticationRequest::getFieldInfo
	 */
	public function testGetFieldInfo() {
		$req = new FemiwikiAuthenticationRequest( 'foo', wfMessage( 'bar' ), wfMessage( 'baz' ) );
		$this->assertArrayHasKey( 'femiwikiOpenSesame', $req->getFieldInfo() );
	}

}
