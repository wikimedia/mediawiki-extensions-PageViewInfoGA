<?php

use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthManager;

class FemiwikiPreAuthenticationProvider extends AbstractPreAuthenticationProvider {

	/**
	 * @see AbstractPreAuthenticationProvider::getAuthenticationRequests()
	 *
	 * @param string $action
	 * @param array $options
	 * @return AuthenticationRequest[]
	 */
	public function getAuthenticationRequests( $action, array $options ) {
		if ( $action === AuthManager::ACTION_CREATE ) {
			return [ new FemiwikiAuthenticationRequest() ];
		} else {
			return [];
		}
	}

	/**
	 * @see AbstractPreAuthenticationProvider::testForAccountCreation()
	 *
	 * @param User $user
	 * @param User $creator
	 * @param AuthenticationRequest[] $reqs
	 * @return StatusValue
	 */
	public function testForAccountCreation( $user, $creator, array $reqs ) {
		/** @var FemiwikiAuthenticationRequest $req */
		$req = AuthenticationRequest::getRequestByClass( $reqs, FemiwikiAuthenticationRequest::class );

		if ( self::testInternal( $req->femiwikiOpenSesame ) ) {
			return Status::newGood();
		} else {
			return Status::newFatal( 'unifiedextensionforfemiwiki-createaccount-fail' );
		}
	}

	/**
	 * @param string $phrase
	 * @return bool
	 */
	private static function testInternal( $phrase ) {
		$phrase = strtolower( $phrase );
		$pattern = '/.*[나저]는\s*페미니스트\s*(?:입니다|이?다).*'
			. '|.*i(?:\s*am|\'m)\s*(?:an?\s*)?feminist.*/u';
		return preg_match( $pattern, $phrase ) !== 0;
	}
}
