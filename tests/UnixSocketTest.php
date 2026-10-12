<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests;

use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\ResponseFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Semperton\Proxy\Client;
use Semperton\Proxy\Exception\NetworkException;
use Semperton\Proxy\Exception\RequestException;

use function file_exists;
use function getmypid;
use function sys_get_temp_dir;
use function unlink;

#[RequiresOperatingSystem('^(?!WIN)')]
final class UnixSocketTest extends TestCase
{
	use TestServerTrait {
		tearDownAfterClass as private stopServer;
	}

	private static string $socket = '';

	public static function tearDownAfterClass(): void
	{
		self::stopServer();

		if (file_exists(self::$socket)) {
			unlink(self::$socket);
		}
	}

	public function testRequestOverUnixSocket(): void
	{
		$echo = $this->send(self::$socket, 'http://localhost/echo')->getBody()->getContents();

		$this->assertStringStartsWith("GET /echo HTTP/1.1\r\nHost: localhost\r\n", $echo);
	}

	public function testChunkedResponseOverUnixSocket(): void
	{
		$response = $this->send(self::$socket, 'http://localhost/chunked');

		$this->assertSame('Hello, World!', $response->getBody()->getContents());
	}

	public function testMissingSocket(): void
	{
		$this->expectException(NetworkException::class);
		$this->expectExceptionMessage('Unable to connect to unix://');

		$this->send(self::$socket . '.missing', 'http://localhost/echo');
	}

	public function testTlsIsNotSupported(): void
	{
		$this->expectException(RequestException::class);
		$this->expectExceptionMessage('TLS over Unix domain sockets is not supported');

		$this->send(self::$socket, 'https://localhost/echo');
	}

	public function testEmptySocketPath(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Client(new ResponseFactory(), unixSocket: '');
	}

	/**
	 * @return list<string>
	 */
	private static function serverArguments(): array
	{
		self::$socket = sys_get_temp_dir() . '/proxy-test-' . getmypid() . '.sock';

		return ['--unix', self::$socket];
	}

	private function send(string $socket, string $url): ResponseInterface
	{
		$client = new Client(new ResponseFactory(), 2, unixSocket: $socket);

		return $client->sendRequest((new RequestFactory())->createRequest('GET', $url));
	}
}
