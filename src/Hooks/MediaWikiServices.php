<?php

namespace MediaWiki\Extension\PageViewInfoGA\Hooks;

use MediaWiki\Extension\PageViewInfo\CachedPageViewService;
use MediaWiki\Extension\PageViewInfoGA\Constants;
use MediaWiki\Extension\PageViewInfoGA\GoogleAnalyticsPageViewService;
use MediaWiki\Extension\PageViewInfoGA\ServiceAccountTokenProvider;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices as MediaWikiServicesContainer;

class MediaWikiServices implements \MediaWiki\Hook\MediaWikiServicesHook {

	/**
	 * Replace PageViewInfo's Wikimedia backend when a GA4 property is configured.
	 *
	 * @inheritDoc
	 */
	public function onMediaWikiServices( $services ) {
		if ( !$services->getMainConfig()->get( Constants::CONFIG_KEY_PROPERTY_ID ) ) {
			return;
		}

		$services->redefineService(
			'PageViewService',
			static function ( MediaWikiServicesContainer $services ) {
				$config = $services->getMainConfig();
				$logger = LoggerFactory::getInstance( 'PageViewInfoGA' );
				$cache = $services->getObjectCacheFactory()->getLocalClusterInstance();
				$titleFormatter = $services->getTitleFormatter();

				$tokenProvider = new ServiceAccountTokenProvider(
					$services->getHttpRequestFactory(),
					$cache,
					(string)$config->get( Constants::CONFIG_KEY_CREDENTIALS_FILE ),
					GoogleAnalyticsPageViewService::SCOPE
				);
				$tokenProvider->setLogger( $logger );

				$service = new GoogleAnalyticsPageViewService(
					$services->getHttpRequestFactory(),
					$titleFormatter,
					$services->getPageStore(),
					$tokenProvider,
					[
						'propertyId' => $config->get( Constants::CONFIG_KEY_PROPERTY_ID ),
						'siteName' => $config->get( MainConfigNames::Sitename ),
						'readCustomDimensions' => $config->get( Constants::CONFIG_KEY_READ_CUSTOM_DIMENSIONS ),
					]
				);
				$service->setLogger( $logger );

				$cachedService = new CachedPageViewService( $service, $cache, $titleFormatter );
				$cachedService->setCachedDays( max( 30, $config->get( 'PageViewApiMaxDays' ) ) );
				$cachedService->setLogger( $logger );
				return $cachedService;
			}
		);
	}
}
