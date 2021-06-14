<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Hooks;

use Config;
use Html;
use RequestContext;
use Skin;
use Title;
use Wikibase\Client\ClientHooks;
use Wikibase\Client\WikibaseClient;

class Handler implements
	\MediaWiki\Hook\BeforePageDisplayHook,
	\MediaWiki\Hook\LinkerMakeExternalLinkHook,
	\MediaWiki\Hook\SidebarBeforeOutputHook,
	\MediaWiki\Hook\SkinAddFooterLinksHook,
	\MediaWiki\Linker\Hook\HtmlPageLinkRendererBeginHook
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
	 * Add a few links to the footer.
	 *
	 * @inheritDoc
	 */
	public function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerItems ) {
		if ( $key !== 'places' ) {
			return true;
		}

		$footerItems =
			// Prepend terms link
			[ 'femiwiki-terms-label' => $skin->footerLink( 'femiwiki-terms-label', 'femiwiki-terms-page' ) ] +
			$footerItems +
			// Append Infringement Notification link
			[ 'femiwiki-support-label' => $skin->footerLink( 'femiwiki-support-label', 'femiwiki-support-page' ) ];

		return true;
	}

	/**
	 * Treat external links to FemiWiki as internal links.
	 *
	 * @inheritDoc
	 */
	public function onLinkerMakeExternalLink( &$url, &$text, &$link, &$attribs,
		$linkType
	) {
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
	 * @inheritDoc
	 */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		$this->addWikibaseNewItemLink( $skin, $sidebar );
		$this->sidebarConvertLinks( $sidebar );
	}

	/**
	 * Add a link to create new Wikibase item in toolbox when the title is not linked with any item.
	 *
	 * - Wikibase\Client\Hooks\SidebarHookHandler::onSidebarBeforeOutput (REL1_35)
	 * - Wikibase\Client\ClientHooks::onBaseTemplateToolbox (REL1_35)
	 * - Wikibase\Client\RepoItemLinkGenerator::getNewItemUrl (REL1_35)
	 *
	 * @param Skin $skin
	 * @param array &$sidebar
	 * @return void
	 */
	private function addWikibaseNewItemLink( $skin, &$sidebar ): void {
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

		$sidebar['TOOLBOX']['wikibase'] = [
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
	private function sidebarConvertLinks( &$bar ): void {
		$canonicalServer = $this->config->get( 'CanonicalServer' );

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
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ) : void {
		global $wgGoogleAnalyticsTrackingID;

			if ( $wgGoogleAnalyticsTrackingID == '' ) {
				return;
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
	}

	/**
	 * Do not show edit page when user clicks red link
	 * @inheritDoc
	 */
	public function onHtmlPageLinkRendererBegin( $linkRenderer, $target, &$text,
		&$customAttribs, &$query, &$ret
	) {
		// See https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/23
		if ( defined( 'MW_PHPUNIT_TEST' ) && in_array( $target, [
				'Link to nowhere',
				'Link',
				'Parser test',
				'Rights Page',
			] ) ) {
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
