<?php

namespace MediaWiki\Extension\PageViewInfoGA;

use Exception;
use Google\Auth\CredentialsLoader;
use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use StatusValue;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Gets access tokens with google/auth from a JSON credentials file, such as a service account key or
 * a Workload Identity Federation configuration from `gcloud iam workload-identity-pools create-cred-config`.
 * The file is read on the first token request, so a bad file fails only that request.
 */
class CredentialsFileTokenProvider implements LoggerAwareInterface {
	use LoggerAwareTrait;

	/** Stop using a cached token this long before it expires */
	private const EXPIRY_MARGIN = 300;
	/** Seconds to wait for a Google endpoint or a URL credential source */
	private const TIMEOUT = 5;
	private const CONNECT_TIMEOUT = 2;

	/** @var callable Sends a PSR-7 request and returns the response, as google/auth expects */
	private $httpHandler;

	private ?FetchAuthTokenInterface $credentials = null;

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param BagOStuff $cache
	 * @param string $credentialsFile
	 * @param string $scope
	 * @param callable|null $httpHandler For tests; the default is core's Guzzle client
	 */
	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		private readonly BagOStuff $cache,
		private readonly string $credentialsFile,
		private readonly string $scope,
		?callable $httpHandler = null
	) {
		$this->logger = new NullLogger();
		$this->httpHandler = $httpHandler ?? HttpHandlerFactory::build( $httpRequestFactory->createGuzzleClient( [
			'timeout' => self::TIMEOUT,
			'connect_timeout' => self::CONNECT_TIMEOUT,
		] ) );
	}

	/**
	 * @return StatusValue With the access token as its value when OK
	 */
	public function getAccessToken(): StatusValue {
		if ( $this->credentials === null ) {
			$problem = $this->loadCredentials();
			if ( $problem !== null ) {
				// The file is named in the log only, as API modules show the status to readers
				$this->logger->error( 'Unusable Google credentials in {file}: {problem}', [
					'file' => $this->credentialsFile,
					'problem' => $problem,
				] );
				return StatusValue::newFatal( 'pageviewinfoga-error-credentials' );
			}
		}
		$credentials = $this->credentials;

		// Set by the callback when the cache has no token
		$status = null;
		$token = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'pageviewinfoga-token', sha1( $credentials->getCacheKey() . "\n" . $this->scope ) ),
			BagOStuff::TTL_HOUR,
			function ( &$ttl ) use ( $credentials, &$status ) {
				$status = $this->fetchToken( $credentials, $ttl );
				// Nothing is cached when this is false
				return $status->isOK() ? $status->getValue() : false;
			}
		);
		return $status ?? StatusValue::newGood( $token );
	}

	/**
	 * @return string|null What makes the file unusable, or null once $this->credentials is set
	 */
	private function loadCredentials(): ?string {
		$file = $this->credentialsFile;
		if ( !is_file( $file ) || !is_readable( $file ) ) {
			return 'not a readable file';
		}
		$status = FormatJson::parse( (string)file_get_contents( $file ), FormatJson::FORCE_ASSOC );
		if ( !$status->isOK() || !is_array( $status->getValue() ) ) {
			return 'not a JSON object';
		}
		try {
			$this->credentials = CredentialsLoader::makeCredentials( $this->scope, $status->getValue() );
		} catch ( Exception $e ) {
			return $e->getMessage();
		}
		return null;
	}

	/**
	 * @param FetchAuthTokenInterface $credentials
	 * @param int &$ttl Set to how long the token can be cached
	 * @return StatusValue With the access token as its value when OK
	 */
	private function fetchToken( FetchAuthTokenInterface $credentials, &$ttl ): StatusValue {
		try {
			$token = $credentials->fetchAuthToken( $this->httpHandler );
		} catch ( Exception $e ) {
			// Such as invalid_grant for a disabled key or a skewed clock, a denied impersonation, or a
			// missing subject token file
			// The message only: a trace's arguments could hold the key or a token
			$this->logger->error( 'Failed getting an access token for Google Analytics: {error}', [
				'error' => $e->getMessage(),
			] );
			return StatusValue::newFatal( 'pvi-invalidresponse' );
		}
		if ( !is_string( $token['access_token'] ?? null ) ) {
			$this->logger->error( 'Google returned no access token' );
			return StatusValue::newFatal( 'pvi-invalidresponse' );
		}

		// Impersonated service accounts give expires_at, the rest expires_in
		$lifetime = isset( $token['expires_at'] ) ?
			(int)$token['expires_at'] - (int)ConvertibleTimestamp::time() :
			(int)( $token['expires_in'] ?? 0 );
		$ttl = $lifetime - self::EXPIRY_MARGIN;
		if ( $ttl < 1 ) {
			// A TTL of 0 would cache the token forever, and a negative one stores nothing
			$ttl = -1;
		}
		return StatusValue::newGood( $token['access_token'] );
	}
}
