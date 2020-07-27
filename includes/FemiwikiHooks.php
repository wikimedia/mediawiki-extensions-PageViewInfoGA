<?php

use MediaWiki\Linker\LinkRenderer;
use Wikibase\Client\WikibaseClient;
use Wikibase\Client\Hooks\SkinTemplateOutputPageBeforeExecHandler;

class FemiwikiHooks {

	/**
	 * Add Wikibase item link in toolbox
	 *
	 * - Wikibase/ClientHooks::onBaseTemplateToolbox (REL1_34)
	 * - Wikibase\ClientRepoItemLinkGenerator::getNewItemUrl (REL1_34)
	 *
	 * @param BaseTemplate $baseTemplate
	 * @param array[] &$toolbox
	 */
	public static function onBaseTemplateToolbox( BaseTemplate $baseTemplate, array &$toolbox ) {
		$wikibaseClient = WikibaseClient::getDefaultInstance();
		$skin = $baseTemplate->getSkin();
		$title = $skin->getTitle();
		$idString = $skin->getOutput()->getProperty( 'wikibase_item' );
		$entityId = null;

		if ( $idString !== null ) {
			$entityIdParser = $wikibaseClient->getEntityIdParser();
			$entityId = $entityIdParser->parse( $idString );
		} elseif ( $title && Action::getActionName( $skin ) !== 'view' && $title->exists() ) {
			// Try to load the item ID from Database, but only do so on non-article views,
			// (where the article's OutputPage isn't available to us).
			$entityId = self::getEntityIdForTitle( $title );
		}

		if ( $entityId === null ) {
			$params = [
				'site' => $wikibaseClient->getSettings()->getSetting( 'siteGlobalID' ),
				'page' => $title->getPrefixedText()
			];

			$repoLinker = $wikibaseClient->newRepoLinker();
			$url = $repoLinker->getPageUrl( 'Special:NewItem' );
			$url = $repoLinker->addQueryParams( $url, $params );

			$toolbox['wikibase'] = [
				'text' => $baseTemplate->getMsg( 'wikibase-dataitem' )->text(),
				'href' => $url,
				'id' => 't-wikibase'
			];
		}
	}

	/**
	 * Copied from Wikibase/ClientHooks::getEntityIdForTitle (REL1_34)
	 * @param Title|null $title
	 * @return EntityId|null
	 */
	private static function getEntityIdForTitle( Title $title = null ) {
		if ( $title === null || !WikibaseClient::getDefaultInstance()->getNamespaceChecker()
			->isWikibaseEnabled( $title->getNamespace() ) ) {
			return null;
		}

		$wikibaseClient = WikibaseClient::getDefaultInstance();
		$entityIdLookup = $wikibaseClient->getStore()->getEntityIdLookup();
		return $entityIdLookup->getEntityIdForTitle( $title );
	}

	/**
	 * Add a few links to the footer.
	 *
	 * @param SkinTemplate &$skin
	 * @param QuickTemplate &$template
	 * @return bool Sends a line to the debug log if false.
	 */
	public static function onSkinTemplateOutputPageBeforeExec( &$skin, &$template ) {
		// Add Terms link to the front.
		$template->set( 'femiwiki-terms-label', $template->getSkin()->footerLink( 'femiwiki-terms-label', 'femiwiki-terms-page' ) );
		array_unshift( $template->data['footerlinks']['places'], 'femiwiki-terms-label' );

		// Add Infringement Notification likn.
		$template->set( 'femiwiki-support-label', $template->getSkin()->footerLink( 'femiwiki-support-label', 'femiwiki-support-page' ) );
		$template->data['footerlinks']['places'][] = 'femiwiki-support-label';

		return true;
	}

	/**
	 * Treat external links to FemiWiki as internal links.
	 *
	 * @param string &$url
	 * @param string &$text
	 * @param string &$link
	 * @param string &$attribs
	 * @param string $linktype
	 * @return bool
	 */
	public static function onLinkerMakeExternalLink( &$url, &$text, &$link, &$attribs, $linktype ) {
		global $wgCanonicalServer;

		if ( strpos( $wgCanonicalServer, parse_url( $url, PHP_URL_HOST ) ) === false ) {
			return true;
		}

		$attribs['class'] = str_replace( 'external', '', $attribs['class'] );
		$attribs['href'] = $url;
		unset( $attribs['target'] );

		$link = Html::rawElement( 'a', $attribs, $text );
		return false;
	}

	/**
	 * Treat external links to FemiWiki as internal links in the Sidebar.
	 *
	 * @param Skin $skin
	 * @param Array &$bar
	 * @return bool
	 */
	public static function onSidebarBeforeOutput( Skin $skin, &$bar ) {
		global $wgCanonicalServer;

		foreach ( $bar as $heading => $content ) {
			foreach ( $content as $key => $item ) {
				if ( !isset( $item['href'] ) ) {
					continue;
				}
				$href = strval( parse_url( $item['href'], PHP_URL_HOST ) );
				if ( $href && strpos( $wgCanonicalServer, $href ) !== false ) {
					unset( $bar[$heading][$key]['rel'] );
					unset( $bar[$heading][$key]['target'] );
				}
			}
		}
		return true;
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
	 * @param LinkRenderer $linkRenderer
	 * @param LinkTarget $target
	 * @param string &$text
	 * @param Array &$extraAttribs
	 * @param Array &$query
	 * @param string &$ret
	 * @return bool
	 */
	public static function onHtmlPageLinkRendererBegin( LinkRenderer $linkRenderer, $target, &$text, &$extraAttribs, &$query, &$ret ) {
		// Do not show edit page when user clicks red link
		$title = Title::newFromLinkTarget( $target );
		if ( !$title->isKnown() ) {
			$query['action'] = 'view';
			$query['redlink'] = '1';
		}

		return false;
	}
}
