<?php

namespace MediaWiki\Extension\PageViewInfoGA\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\PageViewInfoGA\Hooks\Main;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\PageViewInfoGA\Hooks\Main
 */
class MainHooksTest extends MediaWikiUnitTestCase {

	/**
	 * @param array $config
	 * @param array $query
	 * @param int $pageId 0 for a missing page
	 * @param bool $isSpecialPage
	 * @return string The head items added
	 */
	private function render(
		array $config, array $query = [], int $pageId = 42, bool $isSpecialPage = false
	): string {
		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedText' )->willReturn( "Talk:It's </b>" );
		$title->method( 'getId' )->willReturn( $pageId );
		$title->method( 'isSpecialPage' )->willReturn( $isSpecialPage );
		$out = $this->createMock( OutputPage::class );
		$out->method( 'getTitle' )->willReturn( $title );
		$out->method( 'getRequest' )->willReturn( new FauxRequest( $query ) );
		$head = '';
		$out->method( 'addHeadItems' )->willReturnCallback( static function ( $items ) use ( &$head ) {
			$head .= $items;
		} );

		( new Main( new HashConfig( $config + [
			'PageViewInfoGATrackingID' => 'G-TEST',
			'PageViewInfoGAWriteCustomDimensions' => true,
		] ) ) )->onBeforePageDisplay( $out, null );
		return $head;
	}

	public function testNoTagWithoutATrackingId() {
		$this->assertSame( '', $this->render( [ 'PageViewInfoGATrackingID' => false ] ) );
	}

	public static function provideView() {
		yield 'view' => [ [] ];
		yield 'history' => [ [ 'action' => 'history' ] ];
		yield 'diff' => [ [ 'diff' => 'prev', 'oldid' => '1' ] ];
	}

	/**
	 * @dataProvider provideView
	 */
	public function testViewNamesThePage( array $query ) {
		$head = $this->render( [], $query );

		$this->assertStringContainsString( 'src="https://www.googletagmanager.com/gtag/js?id=G-TEST"', $head );
		// The title is a JSON string, with < escaped, so it cannot end the script or the string
		$this->assertStringContainsString(
			'gtag("config","G-TEST",{"page_title":"Talk:It\'s \u003C/b\u003E","mw_page_id":42});', $head );

		$head = $this->render( [ 'PageViewInfoGAWriteCustomDimensions' => false ], $query );
		$this->assertStringContainsString( '{"page_title":"Talk:It\'s \u003C/b\u003E"}', $head );
	}

	public static function provideNotAView() {
		yield 'edit' => [ [ 'action' => 'edit' ] ];
		yield 'preview' => [ [ 'action' => 'submit' ] ];
		yield 'VisualEditor' => [ [ 'veaction' => 'editsource' ] ];
		yield 'special page' => [ [], 0, true ];
		yield 'missing page' => [ [], 0 ];
	}

	/**
	 * @dataProvider provideNotAView
	 */
	public function testOtherRequestsKeepTheDocumentTitle(
		array $query, int $pageId = 42, bool $isSpecialPage = false
	) {
		$head = $this->render( [], $query, $pageId, $isSpecialPage );

		$this->assertStringContainsString( 'gtag("config","G-TEST");', $head );
		$this->assertStringNotContainsString( 'page_title', $head );
	}
}
