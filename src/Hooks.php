<?php

namespace MediaWiki\Extension\PageViewInfoGA;

use Config;
use Html;
use MediaWiki\Extensions\PageViewInfo\CachedPageViewService;
use MediaWiki\Logger\LoggerFactory;
use ObjectCache;

class Hooks implements
	\MediaWiki\Hook\BeforePageDisplayHook,
	\MediaWiki\Hook\MediaWikiServicesHook
	{
	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Add Google Tag Manager to all pages.
	 *
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ) : void {
		$trackingID = $this->config->get( Constants::CONFIG_KEY_TRACKING_ID );
		if ( !$trackingID ) {
			return;
		}

		$config = $this->config;
		$title = $out->getTitle();
		$googleGlobalSiteTag = "<!-- Global site tag (gtag.js) - Google Analytics -->\n";
		$googleGlobalSiteTag .= Html::rawElement( 'script', [
			'async',
			'src' => "https://www.googletagmanager.com/gtag/js?id={$trackingID}",
		] );
		$jsSnippet = 'window.dataLayer=window.dataLayer||[];' .
			'function gtag(){dataLayer.push(arguments);}' .
			"gtag('js',new Date());";

		$pageId = $title->isSpecialPage() ? 0 : $title->getId();
		$pageTitle = $title->getPrefixedDBkey();
		if ( $config->get( Constants::CONFIG_KEY_WRITE_CUSTOM_DIMENSIONS ) ) {
			$customMap = self::makeJsMapString( [
				'custom_map' => $config->get( Constants::CONFIG_KEY_CUSTOM_MAP ),
				'mw:page_id' => $pageId,
				'mw:page_title' => $pageTitle,
			] );
			$jsSnippet .= "gtag('config','{$trackingID}',{$customMap});";
		} else {
			$jsSnippet .= "gtag('config','{$trackingID}')";
		}
		$googleGlobalSiteTag .= Html::rawElement( 'script', [], $jsSnippet );
		$out->addHeadItems( $googleGlobalSiteTag );
	}

	/**
	 * @param array $phpMap
	 * @return string
	 */
	private static function makeJsMapString( $phpMap ) {
		$jsMap = [];
		foreach ( $phpMap as $key => $val ) {
			if ( is_array( $val ) ) {
				$val = self::makeJsMapString( $val );
			} elseif ( is_string( $val ) ) {
				$val = "'$val'";
			}
			$jsMap[] = "'$key':$val";
		}

		$jsMap = implode( ',', $jsMap );
		$jsMap = '{' . $jsMap . '}';
		return $jsMap;
	}

	/**
	 * @inheritDoc
	 */
	public function onMediaWikiServices( $services ) {
		$config = $this->config;
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
