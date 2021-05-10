<?php
/**
 * Implement for https://phabricator.wikimedia.org/T4306
 */
class SpecialOrderedWhatlinkshere extends SpecialWhatLinksHere {

	/**
	 * Copied from REL1_35 without modification to replace private function showIndirectLinks.
	 *
	 * @param string $par
	 */
	public function execute( $par ) {
		$out = $this->getOutput();

		$this->setHeaders();
		$this->outputHeader();
		$this->addHelpLink( 'Help:What links here' );

		$opts = new FormOptions();

		$opts->add( 'target', '' );
		$opts->add( 'namespace', '', FormOptions::INTNULL );
		$opts->add( 'limit', $this->getConfig()->get( 'QueryPageDefaultLimit' ) );
		$opts->add( 'from', 0 );
		$opts->add( 'back', 0 );
		$opts->add( 'hideredirs', false );
		$opts->add( 'hidetrans', false );
		$opts->add( 'hidelinks', false );
		$opts->add( 'hideimages', false );
		$opts->add( 'invert', false );

		$opts->fetchValuesFromRequest( $this->getRequest() );
		$opts->validateIntBounds( 'limit', 0, 5000 );

		// Give precedence to subpage syntax
		if ( $par !== null ) {
			$opts->setValue( 'target', $par );
		}

		// Bind to member variable
		$this->opts = $opts;

		$this->target = Title::newFromText( $opts->getValue( 'target' ) );
		if ( !$this->target ) {
			if ( !$this->including() ) {
				$out->addHTML( $this->whatlinkshereForm() );
			}

			return;
		}

		$this->getSkin()->setRelevantTitle( $this->target );

		$this->selfTitle = $this->getPageTitle( $this->target->getPrefixedDBkey() );

		$out->setPageTitle( $this->msg( 'whatlinkshere-title', $this->target->getPrefixedText() ) );
		$out->addBacklinkSubtitle( $this->target );
		$this->showIndirectLinks(
			0,
			$this->target,
			$opts->getValue( 'limit' ),
			$opts->getValue( 'from' ),
			$opts->getValue( 'back' )
		);
	}

	/**
	 * Copied from REL1_35 with modification (marked as ***Message*** below)
	 * @param int $level Recursion level
	 * @param Title $target Target title
	 * @param int $limit Number of entries to display
	 * @param int $from Display from this article ID (default: 0)
	 * @param int $back Display from this article ID at backwards scrolling (default: 0)
	 */
	private function showIndirectLinks( $level, $target, $limit, $from = 0, $back = 0 ) {
		$out = $this->getOutput();
		$dbr = wfGetDB( DB_REPLICA );

		$hidelinks = $this->opts->getValue( 'hidelinks' );
		$hideredirs = $this->opts->getValue( 'hideredirs' );
		$hidetrans = $this->opts->getValue( 'hidetrans' );
		$hideimages = $target->getNamespace() != NS_FILE || $this->opts->getValue( 'hideimages' );

		// For historical reasons `pagelinks` always contains an entry for the redirect target.
		// So we only need to query `redirect` if `pagelinks` isn't being queried.
		$fetchredirs = $hidelinks && !$hideredirs;

		// Build query conds in concert for all four tables...
		$conds = [];
		$conds['redirect'] = [
			'rd_namespace' => $target->getNamespace(),
			'rd_title' => $target->getDBkey(),
		];
		$conds['pagelinks'] = [
			'pl_namespace' => $target->getNamespace(),
			'pl_title' => $target->getDBkey(),
		];
		$conds['templatelinks'] = [
			'tl_namespace' => $target->getNamespace(),
			'tl_title' => $target->getDBkey(),
		];
		$conds['imagelinks'] = [
			'il_to' => $target->getDBkey(),
		];

		$namespace = $this->opts->getValue( 'namespace' );
		$invert = $this->opts->getValue( 'invert' );
		$nsComparison = ( $invert ? '!= ' : '= ' ) . $dbr->addQuotes( $namespace );
		if ( is_int( $namespace ) ) {
			$conds['redirect'][] = "page_namespace $nsComparison";
			$conds['pagelinks'][] = "pl_from_namespace $nsComparison";
			$conds['templatelinks'][] = "tl_from_namespace $nsComparison";
			$conds['imagelinks'][] = "il_from_namespace $nsComparison";
		}

		/* ***Removed***
		if ( $from ) {
			$conds['redirect'][] = "rd_from >= $from";
			$conds['templatelinks'][] = "tl_from >= $from";
			$conds['pagelinks'][] = "pl_from >= $from";
			$conds['imagelinks'][] = "il_from >= $from";
		}
		*/

		if ( $hideredirs ) {
			// For historical reasons `pagelinks` always contains an entry for the redirect target.
			// So we hide that link when $hideredirs is set. There's unfortunately no way to tell when a
			// redirect's content also links to the target.
			$conds['pagelinks']['rd_from'] = null;
		}

		$queryFunc = static function ( IDatabase $dbr, $table, $fromCol ) use (
			$conds, $target, $limit
		) {
			// Read an extra row as an at-end check
			/* ***Removed***
			$queryLimit = $limit + 1;
			*/
			$on = [
				"rd_from = $fromCol",
				'rd_title' => $target->getDBkey(),
				'rd_interwiki = ' . $dbr->addQuotes( '' ) . ' OR rd_interwiki IS NULL'
			];
			$on['rd_namespace'] = $target->getNamespace();
			// Inner LIMIT is 2X in case of stale backlinks with wrong namespaces
			$subQuery = $dbr->newSelectQueryBuilder()
				->table( $table )
				->fields( [ $fromCol, 'rd_from', 'rd_fragment' ] )
				->conds( $conds[$table] )
				->orderBy( $fromCol )
				/* ***Replaced***
				->limit( 2 * $queryLimit )
				*/
				->leftJoin( 'redirect', 'redirect', $on )
				->join( 'page', 'page', "$fromCol = page_id" );

			return $dbr->newSelectQueryBuilder()
				->table( $subQuery, 'temp_backlink_range' )
				->join( 'page', 'page', "$fromCol = page_id" )
				->fields( [ 'page_id', 'page_namespace', 'page_title',
					'rd_from', 'rd_fragment', 'page_is_redirect' ] )
				->orderBy( 'page_id' )
				/* ***Replaced***
				->limit( $queryLimit )
				*/
				->caller( __CLASS__ . '::showIndirectLinks' )
				->fetchResultSet();
		};

		if ( $fetchredirs ) {
			$rdRes = $dbr->select(
				[ 'redirect', 'page' ],
				[ 'page_id', 'page_namespace', 'page_title', 'rd_from', 'rd_fragment', 'page_is_redirect' ],
				$conds['redirect'],
				__METHOD__,
				[ 'ORDER BY' => 'rd_from', 'LIMIT' => $limit + 1 ],
				[ 'page' => [ 'JOIN', 'rd_from = page_id' ] ]
			);
		}

		if ( !$hidelinks ) {
			$plRes = $queryFunc( $dbr, 'pagelinks', 'pl_from' );
		}

		if ( !$hidetrans ) {
			$tlRes = $queryFunc( $dbr, 'templatelinks', 'tl_from' );
		}

		if ( !$hideimages ) {
			$ilRes = $queryFunc( $dbr, 'imagelinks', 'il_from' );
		}

		if ( ( !$fetchredirs || !$rdRes->numRows() )
			&& ( $hidelinks || !$plRes->numRows() )
			&& ( $hidetrans || !$tlRes->numRows() )
			&& ( $hideimages || !$ilRes->numRows() )
		) {
			if ( $level == 0 && !$this->including() ) {
				$out->addHTML( $this->whatlinkshereForm() );

				// Show filters only if there are links
				if ( $hidelinks || $hidetrans || $hideredirs || $hideimages ) {
					$out->addHTML( $this->getFilterPanel() );
				}
				$msgKey = is_int( $namespace ) ? 'nolinkshere-ns' : 'nolinkshere';
				$link = $this->getLinkRenderer()->makeLink(
					$this->target,
					null,
					[],
					$this->target->isRedirect() ? [ 'redirect' => 'no' ] : []
				);

				$errMsg = $this->msg( $msgKey )
					->params( $this->target->getPrefixedText() )
					->rawParams( $link )
					->parseAsBlock();
				$out->addHTML( $errMsg );
				$out->setStatusCode( 404 );
			}

			return;
		}

		// Read the rows into an array and remove duplicates
		// templatelinks comes third so that the templatelinks row overwrites the
		// pagelinks/redirect row, so we get (inclusion) rather than nothing
		$rows = [];
		if ( $fetchredirs ) {
			foreach ( $rdRes as $row ) {
				$row->is_template = 0;
				$row->is_image = 0;
				$rows[$row->page_id] = $row;
			}
		}
		if ( !$hidelinks ) {
			foreach ( $plRes as $row ) {
				$row->is_template = 0;
				$row->is_image = 0;
				$rows[$row->page_id] = $row;
			}
		}
		if ( !$hidetrans ) {
			foreach ( $tlRes as $row ) {
				$row->is_template = 1;
				$row->is_image = 0;
				$rows[$row->page_id] = $row;
			}
		}
		if ( !$hideimages ) {
			foreach ( $ilRes as $row ) {
				$row->is_template = 0;
				$row->is_image = 1;
				$rows[$row->page_id] = $row;
			}
		}

		// Sort by key and then change the keys to 0-based indices
		/* ***Replaced***
		ksort( $rows );
		*/
		usort( $rows, static function ( $a, $b ) {
			if ( isset( $a->page_title ) && isset( $b->page_title ) ) {
				return strcasecmp( $a->page_title, $b->page_title );
			} else {
				return 0;
			}
		} );
		// ***Replacement ends***
		$rows = array_values( $rows );

		$numRows = count( $rows );

		// Work out the start and end IDs, for prev/next links
		/* ***Replaced***
		if ( $numRows > $limit ) {
		 */
		if ( $numRows - $from > $limit ) {
			// ***Replacement ends***
			// More rows available after these ones
			// Get the ID from the last row in the result set
			/* ***Replaced***
			$nextId = $rows[$limit]->page_id;
			 */
			$nextNumber = $from + $limit;
			// ***Replacement ends***
			// Remove undisplayed rows
			/* ***Replaced***
			$rows = array_slice( $rows, 0, $limit );
			*/
			$rows = array_slice( $rows, $from, $limit );
			// ***Replacement ends***
		} else {
			// No more rows after
			$nextId = false;
			// ***Added***
			$rows = array_slice( $rows, $from );
			// ***Added ends***
		}
		/* ***Replaced***
		$prevId = $from;
		*/
		$prevNumber = $from == 0 ? null : $from - $limit;
		// ***Replacement ends***

		// use LinkBatch to make sure, that all required data (associated with Titles)
		// is loaded in one query
		$lb = new LinkBatch();
		foreach ( $rows as $row ) {
			$lb->add( $row->page_namespace, $row->page_title );
		}
		$lb->execute();

		if ( $level == 0 && !$this->including() ) {
			$out->addHTML( $this->whatlinkshereForm() );
			$out->addHTML( $this->getFilterPanel() );

			$link = $this->getLinkRenderer()->makeLink(
				$this->target,
				null,
				[],
				$this->target->isRedirect() ? [ 'redirect' => 'no' ] : []
			);

			$msg = $this->msg( 'linkshere' )
				->params( $this->target->getPrefixedText() )
				->rawParams( $link )
				->parseAsBlock();
			$out->addHTML( $msg );

			$out->addWikiMsg( 'whatlinkshere-count', Message::numParam( count( $rows ) ) );

			/* ***Replaced***
			$prevnext = $this->getPrevNext( $prevId, $nextId );
			*/
			$prevnext = $this->getPrevNext( $prevNumber, $nextNumber ?? null );
				// ***Replacement ends**
			$out->addHTML( $prevnext );
		}
		$out->addHTML( $this->listStart( $level ) );
		foreach ( $rows as $row ) {
			$nt = Title::makeTitle( $row->page_namespace, $row->page_title );

			if ( $row->rd_from && $level < 2 ) {
				$out->addHTML( $this->listItem( $row, $nt, $target, true ) );
				$this->showIndirectLinks(
					$level + 1,
					$nt,
					$this->getConfig()->get( 'MaxRedirectLinksRetrieved' )
				);
				$out->addHTML( Xml::closeElement( 'li' ) );
			} else {
				$out->addHTML( $this->listItem( $row, $nt, $target ) );
			}
		}

		$out->addHTML( $this->listEnd() );

		if ( $level == 0 && !$this->including() ) {
			$out->addHTML( $prevnext );
		}
	}

	/**
	 * Copied from REL1_35 with modification (marked as ***Message*** below)
	 * @param int|null $prevNumber ***Renamed***
	 * @param int|null $nextNumber ***Renamed***
	 * @return string
	 */
	private function getPrevNext( $prevNumber, $nextNumber ) {
		$currentLimit = $this->opts->getValue( 'limit' );
		$prev = $this->msg( 'whatlinkshere-prev' )->numParams( $currentLimit )->escaped();
		$next = $this->msg( 'whatlinkshere-next' )->numParams( $currentLimit )->escaped();

		$changed = $this->opts->getChangedValues();
		unset( $changed['target'] );

		/* ***Replaced***
		if ( $prevId != 0 ) {
			$overrides = [ 'from' => $this->opts->getValue( 'back' ) ];
		*/
		if ( $prevNumber !== null ) {
			$overrides = [ 'from' => $prevNumber ];
			// ***Replacement ends***
			$prev = $this->makeSelfLink( $prev, array_merge( $changed, $overrides ) );
		}
		/* ***Replaced***
		if ( $nextId != 0 ) {
			$overrides = [ 'from' => $nextId, 'back' => $prevId ];
		*/
		if ( $nextNumber != 0 ) {
			$overrides = [ 'from' => $nextNumber ];
			// ***Replacement ends***
			$next = $this->makeSelfLink( $next, array_merge( $changed, $overrides ) );
		}

		$limitLinks = [];
		$lang = $this->getLanguage();
		foreach ( $this->limits as $limit ) {
			$prettyLimit = htmlspecialchars( $lang->formatNum( $limit ) );
			$overrides = [ 'limit' => $limit ];
			$limitLinks[] = $this->makeSelfLink( $prettyLimit, array_merge( $changed, $overrides ) );
		}

		$nums = $lang->pipeList( $limitLinks );

		return $this->msg( 'viewprevnext' )->rawParams( $prev, $next, $nums )->escaped();
	}

	/**
	 * Copied from REL1_35
	 * @return string
	 */
	private function whatlinkshereForm() {
		// We get nicer value from the title object
		$this->opts->consumeValue( 'target' );
		// Reset these for new requests
		$this->opts->consumeValues( [ 'back', 'from' ] );

		$target = $this->target ? $this->target->getPrefixedText() : '';
		$namespace = $this->opts->consumeValue( 'namespace' );
		$nsinvert = $this->opts->consumeValue( 'invert' );

		# Build up the form
		$f = Xml::openElement( 'form', [ 'action' => wfScript() ] );

		# Values that should not be forgotten
		$f .= Html::hidden( 'title', $this->getPageTitle()->getPrefixedText() );
		foreach ( $this->opts->getUnconsumedValues() as $name => $value ) {
			$f .= Html::hidden( $name, $value );
		}

		$f .= Xml::fieldset( $this->msg( 'whatlinkshere' )->text() );

		# Target input (.mw-searchInput enables suggestions)
		$f .= Xml::inputLabel( $this->msg( 'whatlinkshere-page' )->text(), 'target',
			'mw-whatlinkshere-target', 40, $target, [ 'class' => 'mw-searchInput' ] );

		$f .= ' ';

		# Namespace selector
		$f .= Html::namespaceSelector(
			[
				'selected' => $namespace,
				'all' => '',
				'label' => $this->msg( 'namespace' )->text(),
				'in-user-lang' => true,
			], [
				'name' => 'namespace',
				'id' => 'namespace',
				'class' => 'namespaceselector',
			]
		);

		$f .= "\u{00A0}" .
			Xml::checkLabel(
				$this->msg( 'invert' )->text(),
				'invert',
				'nsinvert',
				$nsinvert,
				[ 'title' => $this->msg( 'tooltip-whatlinkshere-invert' )->text() ]
			);

		$f .= ' ';

		# Submit
		$f .= Xml::submitButton( $this->msg( 'whatlinkshere-submit' )->text() );

		# Close
		$f .= Xml::closeElement( 'fieldset' ) . Xml::closeElement( 'form' ) . "\n";

		return $f;
	}

	/**
	 * Copied from REL1_35
	 * @return string
	 */
	private function getFilterPanel() {
		$show = $this->msg( 'show' )->escaped();
		$hide = $this->msg( 'hide' )->escaped();

		$changed = $this->opts->getChangedValues();
		unset( $changed['target'] );

		$links = [];
		$types = [ 'hidetrans', 'hidelinks', 'hideredirs' ];
		if ( $this->target->getNamespace() == NS_FILE ) {
			$types[] = 'hideimages';
		}

		// Combined message keys: 'whatlinkshere-hideredirs', 'whatlinkshere-hidetrans',
		// 'whatlinkshere-hidelinks', 'whatlinkshere-hideimages'
		// To be sure they will be found by grep
		foreach ( $types as $type ) {
			$chosen = $this->opts->getValue( $type );
			$msg = $chosen ? $show : $hide;
			$overrides = [ $type => !$chosen ];
			$links[] = $this->msg( "whatlinkshere-{$type}" )->rawParams(
				$this->makeSelfLink( $msg, array_merge( $changed, $overrides ) ) )->escaped();
		}

		return Xml::fieldset(
			$this->msg( 'whatlinkshere-filters' )->text(),
			$this->getLanguage()->pipeList( $links )
		);
	}

	/**
	 * Copied from REL1_35
	 * @param string|null $text
	 * @param string $query
	 * @return string
	 */
	private function makeSelfLink( $text, $query ) {
		if ( $text !== null ) {
			$text = new HtmlArmor( $text );
		}

		return $this->getLinkRenderer()->makeKnownLink(
			$this->selfTitle,
			$text,
			[],
			$query
		);
	}
}
