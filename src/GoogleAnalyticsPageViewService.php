<?php

namespace MediaWiki\Extension\PageViewInfoGA;

use InvalidArgumentException;
use MediaWiki\Extension\PageViewInfo\PageViewService;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\PageSelectQueryBuilder;
use MediaWiki\Page\PageStore;
use MediaWiki\Title\TitleFormatter;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StatusValue;
use Wikimedia\Message\MessageParam;

/**
 * PageViewService implementation for wikis using Google Analytics 4, through the Google Analytics Data API
 *
 * GA4 counts days in the time zone of the property, which should be the wiki's $wgLocaltimezone.
 * Like PageViewInfo's Wikimedia backend, the data ends with yesterday, whose counts can still
 * grow while GA4 processes late events.
 *
 * @see https://developers.google.com/analytics/devguides/reporting/data/v1
 */
class GoogleAnalyticsPageViewService implements PageViewService, LoggerAwareInterface {

	public const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
	private const ENDPOINT = 'https://analyticsdata.googleapis.com/v1beta';

	/** Separators of the "pagetitle" message across languages: hyphen, en dash, em dash, middle dot */
	private const SITE_NAME_SEPARATOR = '[-–—·]';

	/** The most rows one report returns */
	private const ROW_LIMIT = 250000;
	/** Pages per report, to keep each request and its filter small */
	private const PAGES_PER_REPORT = 50;
	/** Seconds to wait for a report */
	private const TIMEOUT = 10;

	private LoggerInterface $logger;

	/** @var string GA4 property ID, digits only */
	private string $propertyId;

	private string $siteName;

	private bool $readCustomDimensions;

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param TitleFormatter $titleFormatter
	 * @param PageStore $pageStore
	 * @param CredentialsFileTokenProvider $tokenProvider
	 * @param array $options
	 *   - propertyId: (string|int) GA4 property ID, like 123456789 or "properties/123456789"
	 *   - siteName: (string) $wgSitename, which events from before the Google tag sent page_title
	 *     carry in the document title
	 *   - readCustomDimensions: (bool, default false) Match pages by the mw_page_id event
	 *     parameter, registered as an event-scoped custom dimension, instead of by the page title.
	 *     Page IDs follow a page when it is moved.
	 * @phan-param array{propertyId:string|int,siteName:string,readCustomDimensions?:bool} $options
	 * @throws InvalidArgumentException When propertyId is not a GA4 property ID
	 */
	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly TitleFormatter $titleFormatter,
		private readonly PageStore $pageStore,
		private readonly CredentialsFileTokenProvider $tokenProvider,
		array $options
	) {
		// Accept "properties/123" as the API shows it
		$propertyId = preg_replace( '/^properties\//', '', (string)( $options['propertyId'] ?? '' ) );
		if ( !ctype_digit( $propertyId ) ) {
			throw new InvalidArgumentException( "'propertyId' must be a GA4 property ID" );
		}
		$this->propertyId = $propertyId;
		$this->siteName = (string)( $options['siteName'] ?? '' );
		$this->readCustomDimensions = (bool)( $options['readCustomDimensions'] ?? false );
		$this->logger = new NullLogger();
	}

	/** @inheritDoc */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/** @inheritDoc */
	public function supports( $metric, $scope ) {
		return in_array( $metric, [ self::METRIC_VIEW, self::METRIC_UNIQUE ] ) &&
			in_array( $scope, [ self::SCOPE_ARTICLE, self::SCOPE_TOP, self::SCOPE_SITE ] );
	}

	/**
	 * @inheritDoc
	 *
	 * For METRIC_UNIQUE, the users of a page under page_title and under its document title are
	 * added up, so a user counted under both counts twice.
	 */
	public function getPageData( array $titles, $days, $metric = self::METRIC_VIEW ) {
		$gaMetric = self::getGAMetric( $metric );
		if ( $days <= 0 ) {
			throw new InvalidArgumentException( 'Invalid days: ' . $days );
		}
		if ( !$titles ) {
			return StatusValue::newGood( [] );
		}

		$dates = $this->getDates( $days );
		$status = StatusValue::newGood();
		$result = [];
		foreach ( array_chunk( $titles, self::PAGES_PER_REPORT ) as $chunk ) {
			$chunkStatus = $this->getChunkData( $chunk, $dates, $gaMetric );
			$status->merge( $chunkStatus );
			$status->success += $chunkStatus->success;
			$result += $chunkStatus->getValue();
		}
		$status->successCount = count( array_filter( $status->success ) );
		$status->failCount = count( $status->success ) - $status->successCount;
		$status->setResult( (bool)$status->successCount, $result );
		return $status;
	}

	/**
	 * @param PageReference[] $titles
	 * @param string[] $dates YYYY-MM-DD, oldest first
	 * @param string $gaMetric
	 * @return StatusValue With per-title success, and the data of getPageData() as its value
	 */
	private function getChunkData( array $titles, array $dates, string $gaMetric ): StatusValue {
		$dbKeys = array_map( [ $this->titleFormatter, 'getPrefixedDBkey' ], $titles );
		$titleDimension = $this->getTitleDimension();
		// The page as the report names it => prefixed DB key
		if ( $this->readCustomDimensions ) {
			$dbKeysByGATitle = $this->getDbKeysByPageIdOfTitles( $titles );
			$filter = [ 'filter' => [
				'fieldName' => $titleDimension,
				'inListFilter' => [ 'values' => array_map( 'strval', array_keys( $dbKeysByGATitle ) ) ],
			] ];
		} else {
			$dbKeysByGATitle = array_combine(
				array_map( [ $this->titleFormatter, 'getPrefixedText' ], $titles ),
				$dbKeys
			);
			$filter = [ 'orGroup' => [ 'expressions' => array_map( fn ( $text ) => [
				'filter' => [
					'fieldName' => $titleDimension,
					'stringFilter' => [
						'matchType' => 'FULL_REGEXP',
						'value' => $this->getPageTitleRegex( (string)$text ),
						'caseSensitive' => true,
					],
				],
			], array_keys( $dbKeysByGATitle ) ) ] ];
		}

		// A page with no row on a day had no views that day
		$counts = array_fill_keys( $dbKeys, array_fill_keys( $dates, 0 ) );
		if ( $dbKeysByGATitle ) {
			$status = $this->runReport( [
				'dateRanges' => [ [ 'startDate' => reset( $dates ), 'endDate' => end( $dates ) ] ],
				'dimensions' => [ [ 'name' => 'date' ], [ 'name' => $titleDimension ] ],
				'metrics' => [ [ 'name' => $gaMetric ] ],
				'dimensionFilter' => $filter,
				'limit' => self::ROW_LIMIT,
			] );
			if ( !$status->isOK() ) {
				$status->success = array_fill_keys( $dbKeys, false );
				$status->setResult( false, array_fill_keys( $dbKeys, array_fill_keys( $dates, null ) ) );
				return $status;
			}
			foreach ( $status->getValue() as [ $date, $gaTitle, $count ] ) {
				// Since the Google tag sends page_title, it is the page alone, as asked for. Older
				// events carry the document title with the site name.
				$dbKey = $dbKeysByGATitle[$gaTitle] ?? (
					$this->readCustomDimensions ? null : $dbKeysByGATitle[$this->stripSiteName( $gaTitle )] ?? null
				);
				$day = self::formatDate( $date );
				if ( $dbKey !== null && isset( $counts[$dbKey][$day] ) ) {
					$counts[$dbKey][$day] += $count;
				}
			}
		}

		$status = StatusValue::newGood();
		$status->success = array_fill_keys( $dbKeys, true );
		$status->setResult( true, $counts );
		return $status;
	}

	/** @inheritDoc */
	public function getSiteData( $days, $metric = self::METRIC_VIEW ) {
		$gaMetric = self::getGAMetric( $metric );
		if ( $days <= 0 ) {
			throw new InvalidArgumentException( 'Invalid days: ' . $days );
		}

		$dates = $this->getDates( $days );
		$status = $this->runReport( [
			'dateRanges' => [ [ 'startDate' => reset( $dates ), 'endDate' => end( $dates ) ] ],
			'dimensions' => [ [ 'name' => 'date' ] ],
			'metrics' => [ [ 'name' => $gaMetric ] ],
		] );
		if ( !$status->isOK() ) {
			return $status;
		}

		$result = array_fill_keys( $dates, 0 );
		foreach ( $status->getValue() as [ $date, $count ] ) {
			$day = self::formatDate( $date );
			if ( isset( $result[$day] ) ) {
				$result[$day] = $count;
			}
		}
		$status->setResult( true, $result );
		return $status;
	}

	/** @inheritDoc */
	public function getTopPages( $metric = self::METRIC_VIEW ) {
		$gaMetric = self::getGAMetric( $metric );
		$yesterday = $this->getDates( 1 )[0];

		$status = $this->runReport( [
			'dateRanges' => [ [ 'startDate' => $yesterday, 'endDate' => $yesterday ] ],
			'dimensions' => [ [ 'name' => $this->getTitleDimension() ] ],
			'metrics' => [ [ 'name' => $gaMetric ] ],
			'orderBys' => [ [ 'metric' => [ 'metricName' => $gaMetric ], 'desc' => true ] ],
		] );
		if ( !$status->isOK() ) {
			return $status;
		}

		$rows = array_filter( $status->getValue(),
			// GA4 names events without the dimension "(not set)", and the rows past its cardinality
			// limits "(other)"
			static fn ( $row ) => !in_array( $row[0], [ '', '(not set)', '(other)' ], true )
		);
		$dbKeysByPageId = [];
		if ( $this->readCustomDimensions ) {
			// Pages by their current title, which follows moves. Deleted pages drop out.
			$dbKeysByPageId = $this->getDbKeysByPageId( array_column( $rows, 0 ) );
		}
		$result = [];
		foreach ( $rows as [ $gaTitle, $count ] ) {
			// Any page can be on top, so turn the title back into a DB key
			$dbKey = $this->readCustomDimensions ?
				$dbKeysByPageId[$gaTitle] ?? null :
				str_replace( ' ', '_', $this->stripSiteName( $gaTitle ) );
			if ( $dbKey !== null ) {
				$result[$dbKey] = ( $result[$dbKey] ?? 0 ) + $count;
			}
		}
		arsort( $result );
		$status->setResult( true, $result );
		return $status;
	}

	/** @inheritDoc */
	public function getCacheExpiry( $metric, $scope ) {
		// data is valid until the end of the day
		$endOfDay = strtotime( '0:0 next day' );
		return $endOfDay - time();
	}

	/**
	 * Run one report
	 * @param array $request RunReportRequest
	 * @return StatusValue With a list of rows as its value when OK; each row is the dimension values
	 *   followed by the first metric value as an integer
	 */
	private function runReport( array $request ): StatusValue {
		$tokenStatus = $this->tokenProvider->getAccessToken();
		if ( !$tokenStatus->isOK() ) {
			$this->logger->error( 'Failed getting an access token for Google Analytics: {error}', [
				'error' => self::describe( $tokenStatus ),
			] );
			return $tokenStatus;
		}

		$url = self::ENDPOINT . "/properties/{$this->propertyId}:runReport";
		$httpRequest = $this->httpRequestFactory->create( $url, [
			'method' => 'POST',
			'postData' => FormatJson::encode( $request ),
			'timeout' => self::TIMEOUT,
		], __METHOD__ );
		$httpRequest->setHeader( 'Content-Type', 'application/json' );
		$httpRequest->setHeader( 'Authorization', 'Bearer ' . $tokenStatus->getValue() );
		$httpStatus = $httpRequest->execute();
		$data = FormatJson::decode( $httpRequest->getContent(), true );
		if ( !$httpStatus->isOK() || !is_array( $data ) ) {
			$this->logger->error( 'Failed fetching {requesturl}: {error}', [
				'requesturl' => $url,
				'error' => $data['error']['message'] ?? self::describe( $httpStatus ),
			] );
			$status = StatusValue::newFatal( 'pvi-invalidresponse' );
			$status->merge( $httpStatus );
			return $status;
		}

		$rows = [];
		foreach ( $data['rows'] ?? [] as $row ) {
			$values = array_column( $row['dimensionValues'] ?? [], 'value' );
			$values[] = (int)( $row['metricValues'][0]['value'] ?? 0 );
			$rows[] = $values;
		}
		return StatusValue::newGood( $rows );
	}

	/**
	 * Message keys and parameters of a status for the log, which needs no message lookup
	 * @param StatusValue $status
	 * @return string
	 */
	private static function describe( StatusValue $status ): string {
		return implode( '; ', array_map(
			static fn ( $message ) => $message->getKey() . ' ' . FormatJson::encode( array_map(
				static fn ( $param ) => $param instanceof MessageParam ? $param->getValue() : $param,
				$message->getParams()
			) ),
			$status->getMessages()
		) );
	}

	/**
	 * @param string $metric One of the METRIC_* constants
	 * @return string GA4 metric name
	 * @throws InvalidArgumentException
	 */
	private static function getGAMetric( $metric ): string {
		if ( $metric === self::METRIC_VIEW ) {
			return 'screenPageViews';
		} elseif ( $metric === self::METRIC_UNIQUE ) {
			return 'totalUsers';
		}
		throw new InvalidArgumentException( 'Invalid metric: ' . $metric );
	}

	private function getTitleDimension(): string {
		return $this->readCustomDimensions ?
			'customEvent:' . Constants::EVENT_PARAM_PAGE_ID :
			'pageTitle';
	}

	/**
	 * @param PageReference[] $titles
	 * @return string[] Prefixed DB key by page ID, for the pages that exist
	 */
	private function getDbKeysByPageIdOfTitles( array $titles ): array {
		$byNamespace = [];
		foreach ( $titles as $title ) {
			$byNamespace[$title->getNamespace()][] = $title->getDBkey();
		}
		$dbKeys = [];
		foreach ( $byNamespace as $namespace => $namespaceDbKeys ) {
			$dbKeys += $this->fetchDbKeysByPageId(
				$this->pageStore->newSelectQueryBuilder()->whereTitles( $namespace, $namespaceDbKeys )
			);
		}
		return $dbKeys;
	}

	/**
	 * @param string[] $pageIds Page IDs as GA4 reports them
	 * @return string[] Current prefixed DB key by page ID, for the pages that still exist
	 */
	private function getDbKeysByPageId( array $pageIds ): array {
		$pageIds = array_map( 'intval', array_filter( $pageIds, 'ctype_digit' ) );
		if ( !$pageIds ) {
			return [];
		}
		return $this->fetchDbKeysByPageId( $this->pageStore->newSelectQueryBuilder()->wherePageIds( $pageIds ) );
	}

	/**
	 * @param PageSelectQueryBuilder $query
	 * @return string[]
	 */
	private function fetchDbKeysByPageId( PageSelectQueryBuilder $query ): array {
		$dbKeys = [];
		foreach ( $query->fetchPageRecords() as $record ) {
			$dbKeys[$record->getId()] = $this->titleFormatter->getPrefixedDBkey( $record );
		}
		return $dbKeys;
	}

	/**
	 * Match the page title as the Google tag sends it in page_title, or as GA4 took it from the
	 * document title, "<page> - <site name>", before the tag sent page_title. The separator
	 * depends on the interface language. This does not match a document title from a page with
	 * {{DISPLAYTITLE:}}, from the main page or from a customized MediaWiki:Pagetitle.
	 *
	 * @param string $prefixedText The page as TitleFormatter::getPrefixedText() gives it
	 * @return string A regular expression in the RE2 syntax GA4 uses
	 */
	private function getPageTitleRegex( string $prefixedText ): string {
		return preg_quote( $prefixedText ) .
			'( ' . self::SITE_NAME_SEPARATOR . ' ' . preg_quote( $this->siteName ) . ')?';
	}

	/**
	 * @param string $title A page title, or a document title "<page> - <site name>"
	 * @return string The page part
	 */
	private function stripSiteName( string $title ): string {
		return preg_replace(
			'/ ' . self::SITE_NAME_SEPARATOR . ' ' . preg_quote( $this->siteName, '/' ) . '$/u', '', $title
		) ?? $title;
	}

	/**
	 * @param string $date YYYYMMDD
	 * @return string YYYY-MM-DD
	 */
	private static function formatDate( string $date ): string {
		return substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
	}

	/**
	 * The days to report, in the wiki's time zone. The current day is left out, as only partial
	 * information is available for it.
	 *
	 * @param int $days
	 * @return string[] YYYY-MM-DD, oldest first, ending with yesterday
	 */
	private function getDates( int $days ): array {
		$dates = [];
		for ( $i = $days; $i >= 1; $i-- ) {
			$dates[] = date( 'Y-m-d', strtotime( "today -$i days" ) );
		}
		return $dates;
	}
}
