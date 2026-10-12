<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests;

use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\ResponseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Semperton\Proxy\Client;
use Semperton\Proxy\Exception\NetworkException;

use function file_put_contents;
use function is_file;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Runs against the local test server with a self-signed certificate for "proxy.test".
 */
final class TlsTest extends TestCase
{
	use TestServerTrait {
		tearDownAfterClass as private stopServer;
	}

	private static string $certificate = '';

	public static function tearDownAfterClass(): void
	{
		self::stopServer();

		if (is_file(self::$certificate)) {
			unlink(self::$certificate);
		}
	}

	public function testTrustedCertificate(): void
	{
		$response = $this->send(['cafile' => self::$certificate, 'peer_name' => 'proxy.test'], '/echo');

		$this->assertSame(200, $response->getStatusCode());
		$this->assertStringStartsWith("GET /echo HTTP/1.1\r\n", $response->getBody()->getContents());
	}

	public function testChunkedResponseOverTls(): void
	{
		$response = $this->send(['cafile' => self::$certificate, 'peer_name' => 'proxy.test'], '/chunked');

		$this->assertSame('Hello, World!', $response->getBody()->getContents());
	}

	public function testUntrustedCertificate(): void
	{
		$this->expectException(NetworkException::class);
		$this->expectExceptionMessage('certificate verify failed');

		$this->send([], '/echo');
	}

	public function testPeerNameMismatch(): void
	{
		$this->expectException(NetworkException::class);
		$this->expectExceptionMessage('did not match expected');

		$this->send(['cafile' => self::$certificate, 'peer_name' => 'other.test'], '/echo');
	}

	/**
	 * @return list<string>
	 */
	private static function serverArguments(): array
	{
		self::$certificate = self::createCertificate();

		return ['--tls', self::$certificate];
	}

	/**
	 * Creates a PEM file with a self-signed certificate and its private key.
	 */
	private static function createCertificate(): string
	{
		$file = tempnam(sys_get_temp_dir(), 'proxy-test-');
		$key = openssl_pkey_new(['private_key_bits' => 2048]);

		if ($file === false || $key === false) {
			throw new RuntimeException('Unable to create a test certificate');
		}

		$options = ['digest_alg' => 'sha256'];
		$csr = openssl_csr_new(['commonName' => 'proxy.test'], $key, $options);
		$certificate = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 1, $options);

		if (
			$certificate === false
			|| !openssl_x509_export($certificate, $certificatePem)
			|| !openssl_pkey_export($key, $keyPem)
		) {
			throw new RuntimeException('Unable to create a test certificate');
		}

		file_put_contents($file, $certificatePem . $keyPem);

		return $file;
	}

	/**
	 * @param array<string, mixed> $sslOptions
	 */
	private function send(array $sslOptions, string $path): ResponseInterface
	{
		$client = new Client(new ResponseFactory(), 2, ['ssl' => $sslOptions]);

		return $client->sendRequest((new RequestFactory())->createRequest('GET', self::url($path, 'https')));
	}
}
