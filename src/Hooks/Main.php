<?php

namespace MediaWiki\Extension\PageViewInfoGA\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\PageViewInfoGA\Constants;
use MediaWiki\Html\Html;
use MediaWiki\Output\OutputPage;

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
	 * Add the Google tag (gtag.js) to all pages.
	 *
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$trackingID = $this->config->get( Constants::CONFIG_KEY_TRACKING_ID );
		if ( !$trackingID ) {
			return;
		}

		$title = $out->getTitle();
		$googleTag = "<!-- Google tag (gtag.js) -->\n";
		$googleTag .= Html::rawElement( 'script', [
			'async',
			'src' => 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $trackingID ),
		] );
		$jsSnippet = 'window.dataLayer=window.dataLayer||[];' .
			'function gtag(){dataLayer.push(arguments);}' .
			"gtag('js',new Date());";

		// GA4 sends these with every event of this tag, page_view included. page_title replaces
		// the document title, so reports show the page without the site name.
		$params = [];
		if ( self::isPageview( $out ) ) {
			$params['page_title'] = $title->getPrefixedText();
			if ( $this->config->get( Constants::CONFIG_KEY_WRITE_CUSTOM_DIMENSIONS ) ) {
				$params[Constants::EVENT_PARAM_PAGE_ID] = $title->getId();
			}
		}
		$jsSnippet .= Html::encodeJsCall( 'gtag', $params ?
			[ 'config', $trackingID, $params ] :
			[ 'config', $trackingID ] );
		$googleTag .= Html::inlineScript( $jsSnippet );
		$out->addHeadItems( $googleTag );
	}

	/**
	 * Whether the request views an existing page, as Wikimedia's pageview definition counts one:
	 * histories and diffs included, edits, previews and special pages not. Unlike Wikimedia's, a
	 * view through a redirect counts for its target.
	 *
	 * @see https://gerrit.wikimedia.org/g/analytics/refinery/source/+/6699ee6c82ea0d6419722f34d98a7014e2a8f125/refinery-core/src/main/java/org/wikimedia/analytics/refinery/core/PageviewDefinition.java
	 * @param OutputPage $out
	 * @return bool
	 */
	private static function isPageview( OutputPage $out ): bool {
		$title = $out->getTitle();
		$request = $out->getRequest();
		return !$title->isSpecialPage()
			// A missing page is a 404, which is not a pageview
			&& $title->getId() > 0
			&& !in_array( $request->getRawVal( 'action' ), [ 'edit', 'submit' ], true )
			// VisualEditor, opened by URL
			&& $request->getRawVal( 'veaction' ) === null;
	}
}
