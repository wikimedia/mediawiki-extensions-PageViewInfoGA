<?php

use MediaWiki\Linker\LinkRenderer;

class FemiwikiHooks {

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
