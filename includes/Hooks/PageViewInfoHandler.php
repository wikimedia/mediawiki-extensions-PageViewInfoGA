<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Hooks;

use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GoogleAnalyticsPageViewService;
use MediaWiki\Extensions\PageViewInfo\PageViewService;
use MediaWiki\MediaWikiServices;

class PageViewInfoHandler {
	/**
	 * @param PageViewService &$service
	 * @return bool|void
	 */
	public static function onPageViewInfoAfterPageViewService( &$service ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$credentialsFile = $config->get( 'UnifiedExtensionForFemiwikiGoogleAnalyticsCredentialsFile' );
		$profileId = $config->get( 'UnifiedExtensionForFemiwikiGoogleAnalyticsProfileId' );

		$service = new GoogleAnalyticsPageViewService( [
			'credentialsFile' => $credentialsFile,
			'profileId' => $profileId
		] );

		return false;
	}
}
