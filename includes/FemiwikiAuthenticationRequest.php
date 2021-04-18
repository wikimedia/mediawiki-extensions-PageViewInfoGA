<?php

use MediaWiki\Auth\AuthenticationRequest;

class FemiwikiAuthenticationRequest extends AuthenticationRequest {
	/** @var string */
	public $femiwikiOpenSesame;

	/**
	 * @see AuthenticationRequest::getFieldInfo()
	 * @return array
	 */
	public function getFieldInfo() {
		return [
			'femiwikiOpenSesame' => [
				'type' => 'string',
				'label' => wfMessage( 'unifiedextensionforfemiwiki-createaccount' ),
				'help' => wfMessage( 'captcha-info-help' ),
			],
		];
	}
}
