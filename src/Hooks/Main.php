<?php

namespace MediaWiki\Extension\PageViewInfoGA\Hooks;

use Config;
use Html;
use MediaWiki\Extension\PageViewInfoGA\Constants;

class Main implements
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
}
