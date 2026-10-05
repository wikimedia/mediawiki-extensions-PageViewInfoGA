<?php

namespace MediaWiki\Extension\PageViewInfoGA\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use MediaWiki\Extension\PageViewInfoGA\CredentialsFileTokenProvider;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWikiUnitTestCase;
use Psr\Http\Message\RequestInterface;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\PageViewInfoGA\CredentialsFileTokenProvider
 */
class CredentialsFileTokenProviderTest extends MediaWikiUnitTestCase {

	private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

	private static string $privateKey;

	/** @var string[] */
	private array $files = [];

	/** @var RequestInterface[] Sent by the last provider from newProvider() */
	private array $requests = [];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $key, $pem );
		self::$privateKey = $pem;
	}

	protected function setUp(): void {
		parent::setUp();
		ConvertibleTimestamp::setFakeTime( '2026-10-05T03:00:00Z' );
	}

	protected function tearDown(): void {
		array_map( 'unlink', array_filter( $this->files, 'is_file' ) );
		ConvertibleTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	private function writeFile( string $content ): string {
		$file = tempnam( sys_get_temp_dir(), 'pviga' );
		file_put_contents( $file, $content );
		$this->files[] = $file;
		return $file;
	}

	/**
	 * @param string $file
	 * @param Response[] $responses In the order the requests are sent
	 * @return CredentialsFileTokenProvider
	 */
	private function newProvider( string $file, array $responses ): CredentialsFileTokenProvider {
		$this->requests = [];
		$handler = function ( RequestInterface $request ) use ( &$responses ) {
			$this->requests[] = $request;
			$this->assertNotEmpty( $responses, 'Unexpected request to ' . $request->getUri() );
			return array_shift( $responses );
		};
		return new CredentialsFileTokenProvider( $this->createMock( HttpRequestFactory::class ),
			new HashBagOStuff(), $file, self::SCOPE, $handler );
	}

	/**
	 * A credential configuration as `gcloud iam workload-identity-pools create-cred-config` writes it
	 * @return string The file
	 */
	private function externalAccountFile(): string {
		return $this->writeFile( FormatJson::encode( [
			'type' => 'external_account',
			'audience' => '//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/p/providers/q',
			'subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt',
			'token_url' => 'https://sts.googleapis.com/v1/token',
			'service_account_impersonation_url' => 'https://iamcredentials.googleapis.com/v1/projects/-/' .
				'serviceAccounts/reader@example.iam.gserviceaccount.com:generateAccessToken',
			'credential_source' => [ 'file' => $this->writeFile( 'subject.jwt' ) ],
		] ) );
	}

	private static function json( array $body, int $status = 200 ): Response {
		return new Response( $status, [ 'Content-Type' => 'application/json' ], FormatJson::encode( $body ) );
	}

	private static function stsResponse(): Response {
		return self::json( [ 'access_token' => 'federated', 'expires_in' => 3599 ] );
	}

	private static function impersonationResponse(): Response {
		return self::json( [ 'accessToken' => 'impersonated', 'expireTime' => '2026-10-05T04:00:00Z' ] );
	}

	public function testServiceAccountKey() {
		$file = $this->writeFile( FormatJson::encode( [
			'type' => 'service_account',
			'client_email' => 'reader@example.iam.gserviceaccount.com',
			'private_key' => self::$privateKey,
		] ) );
		$provider = $this->newProvider( $file, [
			self::json( [ 'access_token' => 'from-key', 'expires_in' => 3599 ] ),
		] );

		$this->assertSame( 'from-key', $provider->getAccessToken()->getValue() );
		// Served from the cache, as there is one response
		$this->assertSame( 'from-key', $provider->getAccessToken()->getValue() );
	}

	public function testExternalAccountWithImpersonation() {
		$provider = $this->newProvider( $this->externalAccountFile(), [
			self::stsResponse(),
			self::impersonationResponse(),
		] );

		$this->assertSame( 'impersonated', $provider->getAccessToken()->getValue() );
		// Served from the cache, as there are two responses
		$this->assertSame( 'impersonated', $provider->getAccessToken()->getValue() );
	}

	public function testImpersonationScopes() {
		$provider = $this->newProvider( $this->externalAccountFile(), [
			self::stsResponse(),
			self::impersonationResponse(),
		] );
		$provider->getAccessToken();

		[ $sts, $impersonation ] = $this->requests;
		parse_str( (string)$sts->getBody(), $stsBody );
		// The STS token must be allowed to call the IAM Credentials API
		$this->assertSame(
			'https://www.googleapis.com/auth/cloud-platform ' . self::SCOPE,
			$stsBody['scope']
		);
		$this->assertContains( self::SCOPE, json_decode( (string)$impersonation->getBody(), true )['scope'] );
	}

	public function testFailureIsNotCached() {
		$provider = $this->newProvider( $this->externalAccountFile(), [
			self::stsResponse(),
			self::json( [ 'error' => [ 'code' => 403, 'message' => 'Permission denied' ] ], 403 ),
			self::stsResponse(),
			self::impersonationResponse(),
		] );

		$this->assertFalse( $provider->getAccessToken()->isOK() );
		$this->assertSame( 'impersonated', $provider->getAccessToken()->getValue() );
	}

	public static function provideUnusableFiles() {
		yield 'a directory' => [ static fn ( $test ) => sys_get_temp_dir() ];
		yield 'not JSON' => [ static fn ( $test ) => $test->writeFile( 'not json' ) ];
		yield 'unknown type' => [ static fn ( $test ) => $test->writeFile( '{"type":"unknown"}' ) ];
	}

	/**
	 * @dataProvider provideUnusableFiles
	 */
	public function testUnusableFile( callable $makeFile ) {
		$status = $this->newProvider( $makeFile( $this ), [] )->getAccessToken();

		$this->assertTrue( $status->hasMessage( 'pageviewinfoga-error-credentials' ) );
		$this->assertSame( [], $status->getMessages()[0]->getParams(), 'The status does not name the file' );
	}
}
