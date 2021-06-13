<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki\Hooks;

use Config;
use ExtensionRegistry;
use MediaWiki\MediaWikiServices;
use Title;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\SelectQueryBuilder;

class RelatedArticlesHandler implements
	\MediaWiki\Hook\OutputPageParserOutputHook
	{

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var ILoadBalancer
	 */
	private $loadBalancer;

	/**
	 * @param Config $config
	 * @param ILoadBalancer $loadBalancer
	 */
	public function __construct( Config $config, ILoadBalancer $loadBalancer ) {
		$this->config = $config;
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * @param Title $title
	 * @return bool
	 */
	private static function isDisambiguationPage( Title $title ) {
		return \ExtensionRegistry::getInstance()->isLoaded( 'Disambiguator' ) &&
			\MediaWiki\Extension\Disambiguator\Hooks::isDisambiguationPage( $title );
	}

	/**
	 * @inheritDoc
	 */
	public function onOutputPageParserOutput( $out, $parserOutput ) : void {
		$title = $out->getTitle();
		$action = $out->getRequest()->getText( 'action', 'view' );
		if (
			!ExtensionRegistry::getInstance()->isLoaded( 'RelatedArticles' ) ||
			!$title->inNamespace( NS_MAIN ) ||
			// T120735
			$action !== 'view' ||
			$title->isMainPage() ||
			self::isDisambiguationPage( $title ) ||
			$title->isRedirect()
		) {
			return;
		}
		$limit = $this->config->get( 'RelatedArticlesCardLimit' );
		$related = $parserOutput->getExtensionData( 'RelatedArticles' );

		if ( $related ) {
			$added = $this->getRelatedTitles( $title, $limit - count( $related ) );
			$related = array_merge( $related, $added );
		} else {
			$added = $this->getRelatedTitles( $title, $limit );
			$related = $added;
		}
		$out->setProperty( 'RelatedArticles', $related );
	}

	/**
	 * Returns links from here and linked to here.
	 * @param Title $title
	 * @param int $limit
	 * @return array
	 */
	private function getRelatedTitles( Title $title, $limit ): array {
		$dbr = $this->loadBalancer->getConnectionRef( ILoadBalancer::DB_REPLICA );

		// Make a UNION query
		// $select1, $select2,
		$union = $dbr->unionQueries( [
			$this->makeTitlesFromHereSQL( $title, $dbr ),
			$this->makeRedirectedTitlesFromHereSQL( $title, $dbr ),
			$this->makeTitlesToHereSQL( $title, $dbr ),
			$this->makeTitlesToRedirectsOfHereSQL( $title, $dbr ),
		], $dbr::UNION_DISTINCT ) .
			' ORDER BY page_touched ' . SelectQueryBuilder::SORT_DESC;
		$sql = $dbr->limitResult( $union, $limit );
		// Do not use dbr->select() because the core does not have a method for UNION followed by ORDER BY.
		// phpcs:ignore MediaWiki.Usage.DbrQueryUsage.DbrQueryFound
		$res = $dbr->query( $sql, __METHOD__ );

		$retVal = [];
		if ( $res->numRows() ) {
			$linkCache = MediaWikiServices::getInstance()->getLinkCache();
			foreach ( $res as $row ) {
				$titleObj = Title::makeTitle( $row->page_namespace, $row->page_title );
				if ( $titleObj ) {
					$linkCache->addGoodLinkObjFromRow( $titleObj, $row );
					$retVal[] = $titleObj->getPrefixedText();
				}
			}
		}

		return $retVal;
	}

	/**
	 * Make a SELECT query for links from this title excepts redirects
	 *
	 * @param Title $title
	 * @param DBConnRef $dbr
	 * @return string
	 */
	private function makeTitlesFromHereSQL( Title $title, DBConnRef $dbr ): string {
		return $dbr->newSelectQueryBuilder()
			->table( 'pagelinks' )
			->conds( [ 'pl_from' => $title->getArticleId() ] )
			->leftJoin( 'page', 'page', [
				'page_namespace = pl_namespace',
				'page_title = pl_title',
			] )
			->leftJoin( 'redirect', 'redirect', [ 'rd_from = page_id' ] )
			->conds( [
				// Hide red links
				'page_id != 0',
				// Hide redirects
				'rd_from' => null,
			] )
			->fields( [
				'page_namespace',
				'page_title',
				'page_touched',
			] )
			->getSQL();
	}

	/**
	 * Make a SELECT query for titles redirected from links on the given title
	 *
	 * @param Title $title
	 * @param DBConnRef $dbr
	 * @return string
	 */
	private function makeRedirectedTitlesFromHereSQL( Title $title, DBConnRef $dbr ): string {
		return $dbr->newSelectQueryBuilder()
			->table( 'pagelinks' )
			->conds( [ 'pl_from' => $title->getArticleId() ] )
			->leftJoin( 'page', 'link_target', [
				'link_target.page_namespace = pl_namespace',
				'link_target.page_title = pl_title',
			] )
			->conds( [
				// Hide red links
				'link_target.page_id != 0',
			] )
			->leftJoin( 'redirect', 'redirect', [ 'rd_from = link_target.page_id' ] )
			->leftJoin( 'page', 'target', [
				'target.page_namespace = rd_namespace',
				'target.page_title = rd_title',
			] )
			->conds( [
				// Hide redirects
				'rd_from != 0',
			] )
			->fields( [
				'page_namespace' => 'target.page_namespace',
				'page_title' => 'target.page_title',
				'page_touched' => 'target.page_touched',
			] )
			->getSQL();
	}

	/**
	 * Make a SELECT query for links to this title excepts redirects
	 *
	 * @param Title $title
	 * @param DBConnRef $dbr
	 * @return string
	 */
	private function makeTitlesToHereSQL( Title $title, DBConnRef $dbr ): string {
		return $dbr->newSelectQueryBuilder()
			->table( 'pagelinks' )
			->leftJoin( 'redirect', 'redirect', [ 'rd_from = pl_from' ] )
			->leftJoin( 'page', 'page', [
				'page_id = pl_from'
			] )
			->conds( [
				'pl_namespace' => $title->getNamespace(),
				'pl_title' => $title->getDBkey(),
				// Hide redirects
				'rd_from' => null,
			] )
			->fields( [
				'page_namespace',
				'page_title',
				'page_touched',
			] )
			->getSQL();
	}

	/**
	 * Make a SELECT query for links to all redirect of this title
	 *
	 * @param Title $title
	 * @param DBConnRef $dbr
	 * @return string
	 */
	private function makeTitlesToRedirectsOfHereSQL( Title $title, DBConnRef $dbr ): string {
		$redirects = $dbr->newSelectQueryBuilder()
			->table( 'redirect', 'redirect' )
			->leftJoin( 'page', 'page', [
				'page_id = rd_from',
			] )
			->conds( [
				'rd_title' => $title->getDBkey(),
			] )
			->fields( [
				'page_namespace',
				'page_title',
			] );

		return $dbr->newSelectQueryBuilder()
			->table( $redirects, 'redirects' )
			->leftJoin( 'pagelinks', 'pagelinks', [
				'pl_namespace = page_namespace',
				'pl_title = page_title',
			] )
			->leftJoin( 'page', 'redirect_page', [
				'page_id = pl_from',
			] )
			->fields( [
				'page_namespace' => 'redirect_page.page_namespace',
				'page_title' => 'redirect_page.page_title',
				'page_touched',
			] )
			->getSQL();
	}
}
