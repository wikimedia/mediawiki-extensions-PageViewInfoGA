<?php

namespace MediaWiki\Extension\PageViewInfoGA;

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StatusValue;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * Gets OAuth 2.0 access tokens for a Google service account, using the JWT bearer grant.
 * @see https://developers.google.com/identity/protocols/oauth2/service-account#httprest
 */
class ServiceAccountTokenProvider implements LoggerAwareInterface {

	private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
	private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';
	/** Lifetime of the signed assertion, the most Google accepts */
	private const ASSERTION_TTL = 3600;
	/** Stop using a cached token this long before it expires */
	private const EXPIRY_MARGIN = 300;
	/** Seconds to wait for a token */
	private const TIMEOUT = 5;

	private LoggerInterface $logger;

	/** @var array|null The service account key, once read */
	private ?array $credentials = null;

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param BagOStuff $cache
	 * @param string $credentialsFile Path to a service account key file (JSON). It is read on the
	 *   first token request, so a bad file fails that request instead of the service construction.
	 * @param string $scope
	 */
	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly BagOStuff $cache,
		private readonly string $credentialsFile,
		private readonly string $scope
	) {
		$this->logger = new NullLogger();
	}

	/** @inheritDoc */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * @return StatusValue With the access token as its value when OK
	 */
	public function getAccessToken(): StatusValue {
		$credentialsStatus = $this->loadCredentials();
		if ( !$credentialsStatus->isOK() ) {
			return $credentialsStatus;
		}
		[ 'client_email' => $clientEmail, 'private_key' => $privateKey ] = $this->credentials;
		$tokenUri = $this->credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI;

		// Set by the callback when the cache has no token
		$status = null;
		$token = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'pageviewinfoga-token', sha1( $clientEmail . "\n" . $this->scope ) ),
			BagOStuff::TTL_HOUR,
			function ( &$ttl ) use ( $clientEmail, $privateKey, $tokenUri, &$status ) {
				$status = $this->requestAccessToken( $clientEmail, $privateKey, $tokenUri, $ttl );
				// Nothing is cached when this is false
				return $status->isOK() ? $status->getValue() : false;
			}
		);
		return $status ?? StatusValue::newGood( $token );
	}

	/**
	 * @param string $clientEmail
	 * @param string $privateKey
	 * @param string $tokenUri
	 * @param int &$ttl Set to how long the token can be cached
	 * @return StatusValue With the access token as its value when OK
	 */
	private function requestAccessToken(
		string $clientEmail, string $privateKey, string $tokenUri, &$ttl
	): StatusValue {
		$assertion = $this->makeAssertion( $clientEmail, $privateKey, $tokenUri, time() );
		if ( $assertion === null ) {
			return $this->credentialsError( 'The private key cannot sign' );
		}
		$request = $this->httpRequestFactory->create( $tokenUri, [
			'method' => 'POST',
			'postData' => [
				'grant_type' => self::GRANT_TYPE,
				'assertion' => $assertion,
			],
			'timeout' => self::TIMEOUT,
		], __METHOD__ );
		$status = $request->execute();
		$data = FormatJson::decode( $request->getContent(), true );
		if ( !$status->isOK() || !is_array( $data ) || !isset( $data['access_token'] ) ) {
			// Such as invalid_grant for a disabled key or a skewed clock
			$this->logger->error( 'Failed getting an access token from {tokenuri}: {error}', [
				'tokenuri' => $tokenUri,
				'error' => $data['error_description'] ?? $data['error'] ?? 'no error in the response',
			] );
			$result = StatusValue::newFatal( 'pvi-invalidresponse' );
			$result->merge( $status );
			return $result;
		}

		$ttl = (int)( $data['expires_in'] ?? 0 ) - self::EXPIRY_MARGIN;
		if ( $ttl < 1 ) {
			// A TTL of 0 would cache the token forever, and a negative one stores nothing
			$ttl = -1;
		}
		return StatusValue::newGood( $data['access_token'] );
	}

	private function loadCredentials(): StatusValue {
		if ( $this->credentials === null ) {
			$json = is_readable( $this->credentialsFile ) ? file_get_contents( $this->credentialsFile ) : false;
			$credentials = $json === false ? null : json_decode( $json, true );
			if ( !is_array( $credentials )
				|| ( $credentials['type'] ?? null ) !== 'service_account'
				|| !is_string( $credentials['client_email'] ?? null )
				|| !is_string( $credentials['private_key'] ?? null )
			) {
				return $this->credentialsError( 'Not a readable service account key' );
			}
			$this->credentials = $credentials;
		}
		return StatusValue::newGood();
	}

	/**
	 * Log what is wrong with the key file, and fail without naming the file, as API modules show
	 * the status to readers
	 * @param string $problem
	 * @return StatusValue
	 */
	private function credentialsError( string $problem ): StatusValue {
		$this->logger->error( '{problem}: {file}', [ 'problem' => $problem, 'file' => $this->credentialsFile ] );
		return StatusValue::newFatal( 'pageviewinfoga-error-credentials' );
	}

	/**
	 * @param string $clientEmail
	 * @param string $privateKey PEM
	 * @param string $tokenUri
	 * @param int $now
	 * @return string|null A signed JWT, or null when the private key cannot sign
	 */
	private function makeAssertion( string $clientEmail, string $privateKey, string $tokenUri, int $now ): ?string {
		$header = self::base64UrlEncode( FormatJson::encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
		$claims = self::base64UrlEncode( FormatJson::encode( [
			'iss' => $clientEmail,
			'scope' => $this->scope,
			'aud' => $tokenUri,
			'iat' => $now,
			'exp' => $now + self::ASSERTION_TTL,
		] ) );
		$input = "$header.$claims";
		$signature = '';
		// Parse first, as openssl_sign() warns on a key it cannot parse
		$key = openssl_pkey_get_private( $privateKey );
		if ( !$key || !openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}
		return $input . '.' . self::base64UrlEncode( $signature );
	}

	private static function base64UrlEncode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
