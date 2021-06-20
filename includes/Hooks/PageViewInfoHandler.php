<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Hooks;

use Config;
use MediaWiki\Extension\UnifiedExtensionForFemiwiki\GoogleAnalyticsPageViewService;
use MediaWiki\Extensions\PageViewInfo\PageViewService;
use MediaWiki\MediaWikiServices;

class PageViewInfoHandler implements
	\MediaWiki\Hook\BeforePageDisplayHook
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
		$trackingID = $this->config->get( 'GoogleAnalyticsTrackingID' );
		if ( $trackingID == '' ) {
			return;
		}

		$title = $out->getTitle();
		$pageId = $title->isSpecialPage() ? 0 : $title->getId();
		$pageTitle = $title->getPrefixedDBkey();
		$googleGlobalSiteTag = <<<EOF
<!-- Global site tag (gtag.js) - Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$trackingID}"></script>
<script>
	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());

	gtag('config', '{$trackingID}', {
		'custom_map': {
			'dimension1': 'mw:page_id',
			'dimension2': 'mw:page_title'
		},
		'mw:page_id': {$pageId},
		'mw:page_title': '{$pageTitle}'
	});
</script>
EOF;
		$out->addHeadItems( $googleGlobalSiteTag );
	}

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
