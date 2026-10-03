<?php

namespace MediaWiki\Extension\PageViewInfoGA\Tests\Unit;

use MediaWiki\Extension\PageViewInfoGA\ServiceAccountTokenProvider;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Json\FormatJson;
use MediaWikiUnitTestCase;
use StatusValue;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \MediaWiki\Extension\PageViewInfoGA\ServiceAccountTokenProvider
 */
class ServiceAccountTokenProviderTest extends MediaWikiUnitTestCase {

	/** @var string[] */
	private array $keyFiles = [];

	protected function tearDown(): void {
		array_map( 'unlink', $this->keyFiles );
		parent::tearDown();
	}

	private function writeKeyFile( array $credentials ): string {
		$file = tempnam( sys_get_temp_dir(), 'pviga' );
		file_put_contents( $file, FormatJson::encode( $credentials ) );
		$this->keyFiles[] = $file;
		return $file;
	}

	private function newKeyFile(): string {
		$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $key, $pem );
		return $this->writeKeyFile( [ 'type' => 'service_account', 'client_email' => 'a@b', 'private_key' => $pem ] );
	}

	public function testShortLivedTokenIsNotCached() {
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->expects( $this->exactly( 2 ) )->method( 'create' )->willReturnCallback( function () {
			$request = $this->createMock( MWHttpRequest::class );
			$request->method( 'execute' )->willReturn( StatusValue::newGood() );
			// What is left after the expiry margin is 0, which would mean "cache forever"
			$request->method( 'getContent' )->willReturn( '{"access_token":"abc","expires_in":300}' );
			return $request;
		} );
		$provider = new ServiceAccountTokenProvider( $httpRequestFactory, new HashBagOStuff(), $this->newKeyFile(),
			'scope' );

		$this->assertSame( 'abc', $provider->getAccessToken()->getValue() );
		$this->assertSame( 'abc', $provider->getAccessToken()->getValue() );
	}

	public static function provideBadKeyFiles() {
		yield 'missing file' => [ null ];
		yield 'another key type' => [ [ 'type' => 'authorized_user' ] ];
		yield 'no private key' => [ [ 'type' => 'service_account', 'client_email' => 'a@b' ] ];
		yield 'unparsable private key' => [
			[ 'type' => 'service_account', 'client_email' => 'a@b', 'private_key' => 'not a key' ]
		];
	}

	/**
	 * @dataProvider provideBadKeyFiles
	 */
	public function testBadKeyFileFailsTheTokenRequest( ?array $credentials ) {
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->expects( $this->never() )->method( 'create' );
		$file = $credentials === null ? '/nonexistent/key.json' : $this->writeKeyFile( $credentials );
		$provider = new ServiceAccountTokenProvider( $httpRequestFactory, new HashBagOStuff(), $file, 'scope' );

		$status = $provider->getAccessToken();
		$this->assertFalse( $status->isOK() );
		$this->assertTrue( $status->hasMessage( 'pageviewinfoga-error-credentials' ) );
		$this->assertSame( [], $status->getMessages()[0]->getParams(), 'The status does not name the file' );
	}

	public function testGetsAndCachesAToken() {
		$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $key, $pem );
		$public = openssl_pkey_get_details( $key )['key'];
		$file = $this->writeKeyFile( [
			'type' => 'service_account',
			'client_email' => 'reader@example.iam.gserviceaccount.com',
			'private_key' => $pem,
			'token_uri' => 'https://oauth2.example/token',
		] );

		$posted = [];
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->expects( $this->once() )->method( 'create' )->willReturnCallback(
			function ( string $url, array $options ) use ( &$posted ) {
				$posted = [ $url, $options['postData'] ];
				$request = $this->createMock( MWHttpRequest::class );
				$request->method( 'execute' )->willReturn( StatusValue::newGood() );
				$request->method( 'getContent' )->willReturn( '{"access_token":"abc","expires_in":3599}' );
				return $request;
			}
		);
		$provider = new ServiceAccountTokenProvider( $httpRequestFactory, new HashBagOStuff(), $file, 'scope-a' );

		$this->assertSame( 'abc', $provider->getAccessToken()->getValue() );
		// Served from the cache, as create() is expected once
		$this->assertSame( 'abc', $provider->getAccessToken()->getValue() );

		[ $url, $postData ] = $posted;
		$this->assertSame( 'https://oauth2.example/token', $url );
		$this->assertSame( 'urn:ietf:params:oauth:grant-type:jwt-bearer', $postData['grant_type'] );
		[ $header, $claims, $signature ] = explode( '.', $postData['assertion'] );
		$decode = static fn ( $part ) => base64_decode( strtr( $part, '-_', '+/' ) );
		$this->assertSame( [ 'alg' => 'RS256', 'typ' => 'JWT' ], FormatJson::decode( $decode( $header ), true ) );
		$claims = FormatJson::decode( $decode( $claims ), true );
		$this->assertSame( 'reader@example.iam.gserviceaccount.com', $claims['iss'] );
		$this->assertSame( 'scope-a', $claims['scope'] );
		$this->assertSame( 'https://oauth2.example/token', $claims['aud'] );
		$this->assertSame( 3600, $claims['exp'] - $claims['iat'] );
		$this->assertSame( 1, openssl_verify( "{$header}." . explode( '.', $postData['assertion'] )[1],
			$decode( $signature ), $public, OPENSSL_ALGO_SHA256 ) );
	}

	public function testFailedTokenRequest() {
		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->method( 'create' )->willReturnCallback( function () {
			$request = $this->createMock( MWHttpRequest::class );
			$request->method( 'execute' )->willReturn( StatusValue::newFatal( 'http-bad-status', 400, 'Bad' ) );
			$request->method( 'getContent' )->willReturn( '{"error":"invalid_grant"}' );
			return $request;
		} );
		$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		openssl_pkey_export( $key, $pem );
		$provider = new ServiceAccountTokenProvider( $httpRequestFactory, new HashBagOStuff(),
			$this->writeKeyFile( [ 'type' => 'service_account', 'client_email' => 'a@b', 'private_key' => $pem ] ),
			'scope' );

		$this->assertFalse( $provider->getAccessToken()->isOK() );
	}
}
