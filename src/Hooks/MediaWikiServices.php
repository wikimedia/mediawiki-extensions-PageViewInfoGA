<?php

namespace MediaWiki\Extension\PageViewInfoGA\Hooks;

use MediaWiki\Extension\PageViewInfoGA\Constants;
use MediaWiki\Extension\PageViewInfoGA\GoogleAnalyticsPageViewService;
use MediaWiki\Extensions\PageViewInfo\CachedPageViewService;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices as MediaWikiMediaWikiServices;
use ObjectCache;

class MediaWikiServices implements \MediaWiki\Hook\MediaWikiServicesHook {

	/**
	 * @inheritDoc
	 */
	public function onMediaWikiServices( $services ) {
		$config = MediaWikiMediaWikiServices::getInstance()->getMainConfig();
		$profileId = $config->get( Constants::CONFIG_KEY_PROFILE_ID );
		if ( !$profileId ) {
			return;
		}
		$credentialsFile = $config->get( Constants::CONFIG_KEY_CREDENTIALS_FILE );
		$customMap = $config->get( Constants::CONFIG_KEY_CUSTOM_MAP );
		$readCustomDimensions = $config->get( Constants::CONFIG_KEY_READ_CUSTOM_DIMENSIONS );
		$cache = ObjectCache::getLocalClusterInstance();
		$logger = LoggerFactory::getInstance( 'PageViewInfoGA' );
		$cachedDays = max( 30, $config->get( 'PageViewApiMaxDays' ) );

		$services->redefineService(
			'PageViewService',
			static function () use (
				$credentialsFile,
				$profileId,
				$customMap,
				$readCustomDimensions,
				$cache,
				$logger,
				$cachedDays
				) {
				$service = new GoogleAnalyticsPageViewService( [
					'credentialsFile' => $credentialsFile,
					'profileId' => $profileId,
					'customMap' => $customMap,
					'readCustomDimensions' => $readCustomDimensions,
				] );

				$cachedService = new CachedPageViewService( $service, $cache );
				$cachedService->setCachedDays( $cachedDays );
				$cachedService->setLogger( $logger );
				return $cachedService;
			}
		);
	}
}
