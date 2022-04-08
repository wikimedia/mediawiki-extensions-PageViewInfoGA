<?php

namespace MediaWiki\Extension\PageViewInfoGA;

use Google\Client;
use Google\Service\AnalyticsReporting;
use Google\Service\AnalyticsReporting\DateRange;
use Google\Service\AnalyticsReporting\Dimension;
use Google\Service\AnalyticsReporting\DimensionFilter;
use Google\Service\AnalyticsReporting\DimensionFilterClause;
use Google\Service\AnalyticsReporting\GetReportsRequest;
use Google\Service\AnalyticsReporting\Metric;
use Google\Service\AnalyticsReporting\OrderBy;
use Google\Service\AnalyticsReporting\ReportRequest;
use Google\Service\AnalyticsReporting\ReportRow;
use InvalidArgumentException;
use MediaWiki\Extension\PageViewInfo\PageViewService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Status;
use StatusValue;

/**
 * PageViewService implementation for wikis using the Google Analytics
 * @see https://developers.google.com/analytics
 */
class GoogleAnalyticsPageViewService implements PageViewService, LoggerAwareInterface {
	/** @var LoggerInterface */
	protected $logger;

	/** @var AnalyticsReporting */
	protected $analytics;

	/** @var string Profile(View) ID of the Google Analytics View. */
	protected $profileId;

	/** @var array */
	protected $customMap;

	/** @var bool */
	protected $readCustomDimensions;

	/** @var int UNIX timestamp of 0:00 of the last day with complete data */
	protected $lastCompleteDay;

	/** @var array Cache for getEmptyDateRange() */
	protected $range;

	/** Google Analytics API restricts number of requests up to 5. */
	public const MAX_REQUEST = 5;

	/**
	 * @param array $options Associative array.
	 */
	public function __construct( array $options ) {
		$this->verifyApiOptions( $options );

		// Skip the current day for which only partial information is available
		$this->lastCompleteDay = strtotime( '0:0 1 day ago' );

		$this->logger = new NullLogger();

		$client = new Client();
		$client->setApplicationName( 'PageViewInfo' );
		if ( $options['credentialsFile'] ) {
			$client->setAuthConfig( $options['credentialsFile'] );
		}

		$client->addScope( AnalyticsReporting::ANALYTICS_READONLY );
		$this->analytics = new AnalyticsReporting( $client );

		$this->profileId = $options['profileId'] ?? false;
		$this->customMap = $options['customMap'] ?? false;
		$this->readCustomDimensions = $options['readCustomDimensions'] ?? false;
	}

	/**
	 * @inheritDoc
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @inheritDoc
	 */
	public function supports( $metric, $scope ) {
		return in_array( $metric, [ self::METRIC_VIEW, self::METRIC_UNIQUE ] ) &&
			in_array( $scope, [ self::SCOPE_ARTICLE, self::SCOPE_TOP, self::SCOPE_SITE ] );
	}

	/**
	 * @inheritDoc
	 */
	public function getPageData( array $titles, $days, $metric = self::METRIC_VIEW ) {
		if ( !$titles ) {
			return StatusValue::newGood( [] );
		}
		if ( $days <= 0 ) {
			throw new InvalidArgumentException( 'Invalid days: ' . $days );
		}

		$readCustomDimensions = $this->readCustomDimensions;
		$result = [];
		$requests = [];
		foreach ( $titles as $title ) {
			$result[$title->getPrefixedDBkey()] = $this->getEmptyDateRange( $days );

			// Create DateRange
			$dateRange = new DateRange();
			$dateRange->setStartDate( $days . 'daysAgo' );
			$dateRange->setEndDate( "1daysAgo" );

			// Create Metrics
			$gaMetric = new Metric();
			if ( $metric === self::METRIC_VIEW ) {
				$gaMetric->setExpression( 'ga:pageviews' );
			} elseif ( $metric === self::METRIC_UNIQUE ) {
				$gaMetric->setExpression( 'ga:uniquePageviews' );
			} else {
				throw new InvalidArgumentException( 'Invalid metric: ' . $metric );
			}

			// Create DimensionFilter
			$dimensionFilter = new DimensionFilter();
			if ( $readCustomDimensions ) {
				// Use custom dimensions instead of ga:pageTitle
				$dimensionFilter->setDimensionName( $this->getGAName( 'mw:page_title' ) );
				$dimensionFilter->setOperator( 'EXACT' );
				$dimensionFilter->setExpressions( [ $title->getPrefixedDBkey() ] );
			} else {
				// Use regular expression to filter the title.
				// This is not the ideal approach and maybe fails for some titles.
				$dimensionFilter->setDimensionName( 'ga:pageTitle' );
				$dimensionFilter->setOperator( 'REGEXP' );
				$dimensionFilter->setExpressions( [
					'^' . str_replace( '_', ' ', $title->getPrefixedDBkey() ) . ' - [^-]+$' ] );
			}
			// Create DimensionFilterClause
			$dimensionFilterClause = new DimensionFilterClause();
			$dimensionFilterClause->setFilters( [ $dimensionFilter ] );

			// Create ReportRequest
			$request = new ReportRequest();
			$request->setViewId( $this->profileId );
			$request->setDateRanges( [ $dateRange ] );
			$request->setMetrics( [ $gaMetric ] );
			$request->setDimensions( $this->createDimensions( [
				'ga:date',
				$readCustomDimensions ? $this->getGAName( 'mw:page_title' ) : 'ga:pageTitle',
			] ) );
			$request->setDimensionFilterClauses( [ $dimensionFilterClause ] );

			$requests[] = $request;
		}

		$status = StatusValue::newGood();
		for ( $i = 0; $i < count( $requests ); $i += self::MAX_REQUEST ) {
			$reqs = array_slice( $requests, $i, self::MAX_REQUEST );
			$body = new GetReportsRequest();
			$body->setReportRequests( $reqs );

			$reports = [];
			try {
				$reports = $this->analytics->reports->batchGet( $body )->getReports();
			} catch ( \Google\Service\Exception $e ) {
				foreach ( self::extractExpressionsFromRequests( $reqs ) as $exp ) {
					if ( !$readCustomDimensions ) {
						// $exp is a regular expression for title, strip.
						preg_match( '/\^(.+) - \[\^-\]\+\$/', $exp, $matches );
						if ( !$matches ) {
							continue;
						}
						$exp = $matches[1];
					}
					$status->success[$exp] = false;
				}
				$status->error( 'pvi-invalidresponse' );
			}

			foreach ( $reports as $rep ) {
				$rows = $rep->getData()->getRows();
				if ( !$rows || !is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $row ) {
					if ( !( $row instanceof ReportRow ) ) {
						continue;
					}
					$ts = $row->getDimensions()[0];
					$day = substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 );
					$count = (int)$row->getMetrics()[0]->getValues()[0];
					$title = $row->getDimensions()[1];
					if ( !$readCustomDimensions ) {
						$title = $this->pageTitleForMW( $title );
					}
					$result[$title][$day] = $count;
					$status->success[$title] = true;
				}
			}
		}
		$status->successCount = count( array_filter( $status->success ) );
		$status->failCount = count( $status->success ) - $status->successCount;
		$status->setResult( (bool)$status->successCount, $result );
		return $status;
	}

	/**
	 * @inheritDoc
	 */
	public function getSiteData( $days, $metric = self::METRIC_VIEW ) {
		if ( $metric !== self::METRIC_VIEW && $metric !== self::METRIC_UNIQUE ) {
			throw new InvalidArgumentException( 'Invalid metric: ' . $metric );
		}
		if ( $days <= 0 ) {
			throw new InvalidArgumentException( 'Invalid days: ' . $days );
		}
		$result = $this->getEmptyDateRange( $days );

		// Create the DateRange object.
		$dateRange = new DateRange();
		$dateRange->setStartDate( $days . 'daysAgo' );
		$dateRange->setEndDate( '1daysAgo' );

		// Create the Metrics object.
		$gaMetric = new Metric();
		if ( $metric === self::METRIC_VIEW ) {
			$gaMetric->setExpression( 'ga:pageviews' );
		} elseif ( $metric === self::METRIC_UNIQUE ) {
			$gaMetric->setExpression( 'ga:uniquePageviews' );
		} else {
			throw new InvalidArgumentException( 'Invalid metric: ' . $metric );
		}

		// Create the Dimension object.
		$dimension = new Dimension();
		$dimension->setName( 'ga:date' );

		// Create the ReportRequest object.
		$request = new ReportRequest();
		$request->setViewId( $this->profileId );
		$request->setDateRanges( [ $dateRange ] );
		$request->setMetrics( [ $gaMetric ] );
		$request->setDimensions( [ $dimension ] );

		$body = new GetReportsRequest();
		$body->setReportRequests( [ $request ] );

		$status = Status::newGood();
		try {
			$data = $this->analytics->reports->batchGet( $body );
			$rows = $data->getReports()[0]->getData()->getRows();

			foreach ( $rows as $row ) {
				$ts = $row->dimensions[0];
				$day = substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 );
				$count = (int)$row->metrics[0]->values[0];
				$result[$day] = $count;
			}
			$status->setResult( $status->isOK(), $result );
		} catch ( RuntimeException $e ) {
			$status->fatal( 'pvi-invalidresponse' );
		}
		return $status;
	}

	/**
	 * @inheritDoc
	 */
	public function getTopPages( $metric = self::METRIC_VIEW ) {
		$result = [];
		if ( !in_array( $metric, [ self::METRIC_VIEW, self::METRIC_UNIQUE ] ) ) {
			throw new InvalidArgumentException( 'Invalid metric: ' . $metric );
		}

		// Create the DateRange object.
		$dateRange = new DateRange();
		$dateRange->setStartDate( '2daysAgo' );
		$dateRange->setEndDate( '1daysAgo' );

		// Create the Metrics object and OrderBy object.
		$gaMetric = new Metric();
		$orderBy = new OrderBy();
		$orderBy->setSortOrder( 'DESCENDING' );
		if ( $metric === self::METRIC_VIEW ) {
			$gaMetric->setExpression( 'ga:pageviews' );
			$orderBy->setFieldName( 'ga:pageviews' );
		} elseif ( $metric === self::METRIC_UNIQUE ) {
			$gaMetric->setExpression( 'ga:uniquePageviews' );
			$orderBy->setFieldName( 'ga:uniquePageviews' );
		}

		// Create the Dimension object.
		$dimension = new Dimension();
		$dimension->setName( $this->readCustomDimensions ? $this->getGAName( 'mw:page_title' ) : 'ga:pageTitle' );

		// Create the ReportRequest object.
		$request = new ReportRequest();
		$request->setViewId( $this->profileId );
		$request->setDateRanges( [ $dateRange ] );
		$request->setMetrics( [ $gaMetric ] );
		$request->setDimensions( [ $dimension ] );
		$request->setOrderBys( [ $orderBy ] );

		$body = new GetReportsRequest();
		$body->setReportRequests( [ $request ] );

		$status = Status::newGood();
		try {
			$data = $this->analytics->reports->batchGet( $body );
			$rows = $data->getReports()[0]->getData()->getRows();

			foreach ( $rows as $row ) {
				$title = $row->dimensions[0];
				$title = $this->pageTitleForMW( $title );
				$count = (int)$row->metrics[0]->values[0];
				$result[$title] = $count;
			}
			$status->setResult( $status->isOK(), $result );
		} catch ( RuntimeException $e ) {
			$status->fatal( 'pvi-invalidresponse' );
		}
		return $status;
	}

	/**
	 * @param ReportRequest[] $requests
	 * @return string[]
	 */
	protected static function extractExpressionsFromRequests( $requests ) {
		$exps = [];
		foreach ( $requests as $req ) {
			foreach ( $req->getDimensionFilterClauses() as $clause ) {
				foreach ( $clause->getFilters() as $filter ) {
					foreach ( $filter->getExpressions() as $exp ) {
						$exps[] = $exp;
					}
				}
			}
		}
		return $exps;
	}

	/**
	 * @param string $mwName
	 * @return string
	 */
	protected function getGAName( $mwName ) {
		$flipped = array_flip( $this->customMap );
		return 'ga:' . $flipped[$mwName];
	}

	/**
	 * @inheritDoc
	 */
	public function getCacheExpiry( $metric, $scope ) {
		// data is valid until the end of the day
		$endOfDay = strtotime( '0:0 next day' );
		return $endOfDay - time();
	}

	/**
	 * @param array $apiOptions
	 * @throws InvalidArgumentException
	 */
	protected function verifyApiOptions( array $apiOptions ) {
		if ( !isset( $apiOptions['credentialsFile'] ) ) {
			throw new InvalidArgumentException( "'credentialsFile' is required" );
		} elseif ( !isset( $apiOptions['profileId'] ) ) {
			throw new InvalidArgumentException( "'profileId' is required" );
		}
	}

	/**
	 * The API omits dates if there is no data. Fill it with nulls to make client-side
	 * processing easier.
	 * @param int $days
	 * @return array YYYY-MM-DD => null
	 */
	protected function getEmptyDateRange( $days ) {
		if ( !$this->range ) {
			$this->range = [];
			// we only care about the date part, so add some hours to avoid errors when there is a
			// leap second or some other weirdness
			$end = $this->lastCompleteDay + 12 * 3600;
			$start = $end - ( $days - 1 ) * 24 * 3600;
			for ( $ts = $start; $ts <= $end; $ts += 24 * 3600 ) {
				$this->range[gmdate( 'Y-m-d', $ts )] = null;
			}
		}
		return $this->range;
	}

	/**
	 * Get start and end timestamp in YYYYMMDDHH format
	 * @param int $days
	 * @return string[]
	 */
	protected function getStartEnd( $days ) {
		$end = $this->lastCompleteDay + 12 * 3600;
		$start = $end - ( $days - 1 ) * 24 * 3600;
		return [ gmdate( 'Ymd', $start ) . '00', gmdate( 'Ymd', $end ) . '00' ];
	}

	/**
	 * @param string $gaTitle
	 * @return string title text converted MediaWiki-friendly
	 */
	protected static function pageTitleForMW( $gaTitle ) {
		// TODO: Use "pagetitle" and "pagetitle-view-mainpage" messages
		$title = preg_replace( '/ - [^-]+$/', '', $gaTitle );
		$title = preg_replace( '/ /', '_', $title );

		return $title;
	}

	/**
	 * @param string[] $names
	 * @return Dimension[]
	 */
	protected function createDimensions( $names ) {
		$dimensions = [];
		foreach ( $names as $name ) {
			$dimension = new Dimension();
			$dimension->setName( $name );
			$dimensions[] = $dimension;
		}
		return $dimensions;
	}
}
