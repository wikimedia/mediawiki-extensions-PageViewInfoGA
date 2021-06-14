<?php

namespace MediaWiki\Extension\UnifiedExtensionForFemiwiki;

use Google_Client;
use Google_Service_AnalyticsReporting;
use Google_Service_AnalyticsReporting_DateRange;
use Google_Service_AnalyticsReporting_Dimension;
use Google_Service_AnalyticsReporting_DimensionFilter;
use Google_Service_AnalyticsReporting_DimensionFilterClause;
use Google_Service_AnalyticsReporting_GetReportsRequest;
use Google_Service_AnalyticsReporting_Metric;
use Google_Service_AnalyticsReporting_OrderBy;
use Google_Service_AnalyticsReporting_ReportRequest;
use InvalidArgumentException;
use MediaWiki\Extensions\PageViewInfo\PageViewService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Status;
use StatusValue;
use Title;
use WebRequest;

/**
 * PageViewService implementation for wikis using the Google Analytics
 * @see https://developers.google.com/analytics
 */
class GoogleAnalyticsPageViewService implements PageViewService, LoggerAwareInterface {
	/** @var LoggerInterface */
	protected $logger;

	/** @var Google_Service_AnalyticsReporting */
	protected $analytics;

	/** @var string Profile(View) ID of the Google Analytics View. */
	protected $profileId;

	/** @var int UNIX timestamp of 0:00 of the last day with complete data */
	protected $lastCompleteDay;

	/** @var array Cache for getEmptyDateRange() */
	protected $range;

	/** @var WebRequest|string[] The request that asked for this data; see the originalRequest
	 *    parameter of Http::request()
	 */
	protected $originalRequest;

	/**
	 * @param array $options Associative array.
	 */
	public function __construct( array $options ) {
		$this->verifyApiOptions( $options );

		// Skip the current day for which only partial information is available
		$this->lastCompleteDay = strtotime( '0:0 1 day ago' );

		$this->logger = new NullLogger();

		$client = new Google_Client();
		$client->setApplicationName( 'PageViewInfo' );
		if ( $options['credentialsFile'] ) {
			$client->setAuthConfig( $options['credentialsFile'] );
		}
		$client->addScope( Google_Service_AnalyticsReporting::ANALYTICS_READONLY );

		$this->analytics = new Google_Service_AnalyticsReporting( $client );

		$this->profileId = $options['profileId'];
	}

	/**
	 * @inheritDoc
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param WebRequest|string[] $originalRequest See the 'originalRequest' parameter of
	 *   Http::request().
	 */
	public function setOriginalRequest( $originalRequest ) {
		$this->originalRequest = $originalRequest;
	}

	/**
	 * @inheritDoc
	 */
	public function supports( $metric, $scope ) {
		return true;
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

		$status = StatusValue::newGood();
		$result = [];
		foreach ( $titles as $title ) {
			/** @var Title $title */
			$result[$title->getPrefixedDBkey()] = $this->getEmptyDateRange( $days );

			// Create the DateRange object.
			$dateRange = new Google_Service_AnalyticsReporting_DateRange();
			$dateRange->setStartDate( $days . 'daysAgo' );
			$dateRange->setEndDate( "1daysAgo" );

			// Create the Metrics object.
			$gaMetric = new Google_Service_AnalyticsReporting_Metric();
			if ( $metric === self::METRIC_VIEW ) {
				$gaMetric->setExpression( 'ga:pageviews' );
			} elseif ( $metric === self::METRIC_UNIQUE ) {
				$gaMetric->setExpression( 'ga:uniquePageviews' );
			} else {
				throw new InvalidArgumentException( 'Invalid metric: ' . $metric );
			}

			// Create the Dimension object.
			$dimension = new Google_Service_AnalyticsReporting_Dimension();
			$dimension->setName( 'ga:date' );

			// Create the DimensionFilterClause object.
			// TODO Use unique custom dimension instead of ga:pageTitle and provide the instruction to the end-users.
			$dimensionFilter = new Google_Service_AnalyticsReporting_DimensionFilter();
			$dimensionFilter->setDimensionName( 'ga:pageTitle' );
			$dimensionFilter->setOperator( 'REGEXP' );
			$dimensionFilter->setExpressions( [
				'^' . str_replace( '_', ' ', $title->getPrefixedDBkey() ) . ' - [^-]+$' ] );
			$dimensionFilterClause = new Google_Service_AnalyticsReporting_DimensionFilterClause();
			$dimensionFilterClause->setFilters( [ $dimensionFilter ] );

			// Create the ReportRequest object.
			$request = new Google_Service_AnalyticsReporting_ReportRequest();
			$request->setViewId( $this->profileId );
			$request->setDateRanges( [ $dateRange ] );
			$request->setMetrics( [ $gaMetric ] );
			$request->setDimensions( [ $dimension ] );
			$request->setDimensionFilterClauses( [ $dimensionFilterClause ] );

			$body = new Google_Service_AnalyticsReporting_GetReportsRequest();
			$body->setReportRequests( [ $request ] );

			try {
				$data = $this->analytics->reports->batchGet( $body );
				$rows = $data->getReports()[0]->getData()->getRows();
				foreach ( $rows as $row ) {
					$ts = $row->dimensions[0];
					$day = substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 );
					$count = (int)$row->metrics[0]->values[0];
					$result[$title->getPrefixedDBkey()][$day] = $count;
				}
				$status->success[$title->getPrefixedDBkey()] = true;
			} catch ( RuntimeException $e ) {
				$status->error( 'pvi-invalidresponse' );
				$status->success[$title->getPrefixedDBkey()] = false;
				continue;
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
		$dateRange = new Google_Service_AnalyticsReporting_DateRange();
		$dateRange->setStartDate( $days . 'daysAgo' );
		$dateRange->setEndDate( '1daysAgo' );

		// Create the Metrics object.
		$gaMetric = new Google_Service_AnalyticsReporting_Metric();
		if ( $metric === self::METRIC_VIEW ) {
			$gaMetric->setExpression( 'ga:pageviews' );
		} elseif ( $metric === self::METRIC_UNIQUE ) {
			$gaMetric->setExpression( 'ga:uniquePageviews' );
		} else {
			throw new InvalidArgumentException( 'Invalid metric: ' . $metric );
		}

		// Create the Dimension object.
		$dimension = new Google_Service_AnalyticsReporting_Dimension();
		$dimension->setName( 'ga:date' );

		// Create the ReportRequest object.
		$request = new Google_Service_AnalyticsReporting_ReportRequest();
		$request->setViewId( $this->profileId );
		$request->setDateRanges( [ $dateRange ] );
		$request->setMetrics( [ $gaMetric ] );
		$request->setDimensions( [ $dimension ] );

		$body = new Google_Service_AnalyticsReporting_GetReportsRequest();
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
		$dateRange = new Google_Service_AnalyticsReporting_DateRange();
		$dateRange->setStartDate( '2daysAgo' );
		$dateRange->setEndDate( '1daysAgo' );

		// Create the Metrics object and OrderBy object.
		$gaMetric = new Google_Service_AnalyticsReporting_Metric();
		$orderBy = new Google_Service_AnalyticsReporting_OrderBy();
		$orderBy->setSortOrder( 'DESCENDING' );
		if ( $metric === self::METRIC_VIEW ) {
			$gaMetric->setExpression( 'ga:pageviews' );
			$orderBy->setFieldName( 'ga:pageviews' );
		} elseif ( $metric === self::METRIC_UNIQUE ) {
			$gaMetric->setExpression( 'ga:uniquePageviews' );
			$orderBy->setFieldName( 'ga:uniquePageviews' );
		}

		// Create the Dimension object.
		$dimension = new Google_Service_AnalyticsReporting_Dimension();
		$dimension->setName( 'ga:pageTitle' );

		// Create the ReportRequest object.
		$request = new Google_Service_AnalyticsReporting_ReportRequest();
		$request->setViewId( $this->profileId );
		$request->setDateRanges( [ $dateRange ] );
		$request->setMetrics( [ $gaMetric ] );
		$request->setDimensions( [ $dimension ] );
		$request->setOrderBys( [ $orderBy ] );

		$body = new Google_Service_AnalyticsReporting_GetReportsRequest();
		$body->setReportRequests( [ $request ] );

		$status = Status::newGood();
		try {
			$data = $this->analytics->reports->batchGet( $body );
			$rows = $data->getReports()[0]->getData()->getRows();

			foreach ( $rows as $row ) {
				$title = $row->dimensions[0];
				$title = preg_replace( '/ - [^-]+$/', '', $title );
				$title = preg_replace( '/ /', '_', $title );
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
}
