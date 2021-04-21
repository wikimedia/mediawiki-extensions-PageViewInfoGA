<?php

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use Wikibase\Client\ClientHooks;
use Wikibase\Client\WikibaseClient;

class FemiwikiHooks {

	/**
	 * Add a few links to the footer.
	 *
	 * @param Skin $skin
	 * @param string $key
	 * @param array &$footerlinks
	 * @return bool Sends a line to the debug log if false.
	 */
	public static function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerlinks ) {
		if ( $key !== 'places' ) {
			return true;
		}

		$footerlinks =
			// Prepend terms link
			[ 'femiwiki-terms-label' => $skin->footerLink( 'femiwiki-terms-label', 'femiwiki-terms-page' ) ] +
			$footerlinks +
			// Append Infringement Notification link
			[ 'femiwiki-support-label' => $skin->footerLink( 'femiwiki-support-label', 'femiwiki-support-page' ) ];

		return true;
	}

	/**
	 * Treat external links to FemiWiki as internal links.
	 *
	 * @param string &$url
	 * @param string &$text
	 * @param string &$link
	 * @param string[] &$attribs
	 * @param string $linktype
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public static function onLinkerMakeExternalLink( &$url, &$text, &$link, &$attribs, $linktype ) {
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			return true;
		}
		$canonicalServer = RequestContext::getMain()->getConfig()->get( 'CanonicalServer' );
		if ( strpos( $canonicalServer, parse_url( $url, PHP_URL_HOST ) ) === false ) {
			return true;
		}

		$attribs['class'] = str_replace( 'external', '', $attribs['class'] );
		$attribs['href'] = $url;
		unset( $attribs['target'] );

		$link = Html::rawElement( 'a', $attribs, $text );
		return false;
	}

	/**
	 * @param Skin $skin
	 * @param array &$sidebar Sidebar content. Modify $sidebar to add or modify sidebar portlets.
	 * @return void This hook must not abort; it must not return value.
	 */
	public static function onSidebarBeforeOutput( Skin $skin, &$sidebar ): void {
		self::addWikibaseNewItemLink( $skin, $sidebar );
		self::sidebarConvertLinks( $sidebar );
	}

	/**
	 * Add a link to create new Wikibase item in toolbox when the title is not linked with any item.
	 *
	 * - Wikibase\Client\Hooks\SidebarHookHandler::onSidebarBeforeOutput (REL1_35)
	 * - Wikibase\Client\ClientHooks::onBaseTemplateToolbox (REL1_35)
	 * - Wikibase\Client\RepoItemLinkGenerator::getNewItemUrl (REL1_35)
	 *
	 * @param Skin $skin
	 * @param array &$bar
	 * @return void
	 */
	private static function addWikibaseNewItemLink( Skin $skin, &$bar ): void {
		if ( ClientHooks::buildWikidataItemLink( $skin ) ) {
			return;
		}
		$title = $skin->getTitle();
		$repoLinker = WikibaseClient::getRepoLinker();

		$params = [
			'site' => WikibaseClient::getSettings()->getSetting( 'siteGlobalID' ),
			'page' => $title->getPrefixedText()
		];

		$url = $repoLinker->getPageUrl( 'Special:NewItem' );
		$url = $repoLinker->addQueryParams( $url, $params );

		$bar['TOOLBOX']['wikibase'] = [
			'text' => $skin->msg( 'wikibase-dataitem' )->text(),
			'href' => $url,
			'id' => 't-wikibase'
		];
	}

	/**
	 * Treat external links to FemiWiki as internal links in the Sidebar.
	 * @param array &$bar
	 * @return void
	 */
	private static function sidebarConvertLinks( &$bar ): void {
		$canonicalServer = RequestContext::getMain()->getConfig()->get( 'CanonicalServer' );

		foreach ( $bar as $heading => $content ) {
			foreach ( $content as $key => $item ) {
				if ( !isset( $item['href'] ) ) {
					continue;
				}
				$href = strval( parse_url( $item['href'], PHP_URL_HOST ) );
				if ( $href && strpos( $canonicalServer, $href ) !== false ) {
					unset( $bar[$heading][$key]['rel'] );
					unset( $bar[$heading][$key]['target'] );
				}
			}
		}
	}

	/**
	 * Add Google Tag Manager to all pages.
	 *
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 * @return bool
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		global $wgGoogleAnalyticsTrackingID;

			if ( $wgGoogleAnalyticsTrackingID == '' ) {
				return true;
			}
			$googleGlobalSiteTag = <<<EOF
<!-- Global site tag (gtag.js) - Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$wgGoogleAnalyticsTrackingID}"></script>
<script>
	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());

	gtag('config', '{$wgGoogleAnalyticsTrackingID}');
</script>
EOF;
		$out->addHeadItems( $googleGlobalSiteTag );

		return true;
	}

	/**
	 * Do not show edit page when user clicks red link
	 * @param LinkRenderer $linkRenderer
	 * @param LinkTarget $target
	 * @param string &$text
	 * @param array &$extraAttribs
	 * @param array &$query
	 * @param string &$ret
	 * @return bool
	 */
	public static function onHtmlPageLinkRendererBegin( LinkRenderer $linkRenderer, LinkTarget $target, &$text,
		&$extraAttribs, &$query, &$ret ) {
		// See https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/23
		if ( defined( 'MW_PHPUNIT_TEST' ) && ( $target == 'Rights Page' || $target == 'Parser test' ) ) {
			return true;
		}

		$title = Title::newFromLinkTarget( $target );
		if ( !$title->isKnown() ) {
			$query['action'] = 'view';
			$query['redlink'] = '1';
		}

		return false;
	}
}
