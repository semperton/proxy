<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests;

use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\ResponseFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Semperton\Proxy\Client;
use Semperton\Proxy\Exception\NetworkException;
use Semperton\Proxy\Exception\RequestException;
use Semperton\Proxy\Tests\Fixture\UnknownSizeStream;

use function fclose;
use function microtime;
use function str_repeat;
use function stream_socket_get_name;
use function stream_socket_server;

final class ClientTest extends TestCase
{
	use TestServerTrait;

	private Client $client;

	private RequestFactory $requestFactory;

	protected function setUp(): void
	{
		$this->client = new Client(new ResponseFactory(), 2);
		$this->requestFactory = new RequestFactory();
	}

	public function testGetRequest(): void
	{
		$response = $this->send('GET', '/echo?foo=bar');
		$port = self::$port;

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('1.1', $response->getProtocolVersion());
		$this->assertStringStartsWith(
			"GET /echo?foo=bar HTTP/1.1\r\nHost: 127.0.0.1:$port\r\n",
			$response->getBody()->getContents(),
		);
	}

	public function testRequiredHeadersAreSent(): void
	{
		$request = $this->requestFactory->createRequest('GET', self::url('/echo'))->withoutHeader('Host');
		$echo = $this->client->sendRequest($request)->getBody()->getContents();

		$this->assertStringContainsString("\r\nHost: 127.0.0.1:" . self::$port . "\r\n", $echo);
		$this->assertStringContainsString("\r\nConnection: close\r\n", $echo);
		$this->assertStringContainsString("\r\nUser-Agent: SempertonProxy (+https://github.com/semperton/proxy)\r\n", $echo);
		$this->assertStringNotContainsString('Content-Length', $echo);
	}

	public function testCustomUserAgentIsKept(): void
	{
		$request = $this->requestFactory->createRequest('GET', self::url('/echo'))->withHeader('User-Agent', 'Custom/1.0');
		$echo = $this->client->sendRequest($request)->getBody()->getContents();

		$this->assertStringContainsString("\r\nUser-Agent: Custom/1.0\r\n", $echo);
		$this->assertStringNotContainsString('SempertonProxy', $echo);
	}

	public function testPostWithBody(): void
	{
		$echo = $this->send('POST', '/echo', 'Hello World')->getBody()->getContents();

		$this->assertStringContainsString("\r\nContent-Length: 11\r\n", $echo);
		$this->assertStringEndsWith("\r\n\r\nHello World", $echo);
	}

	public function testPostWithEmptyBody(): void
	{
		$echo = $this->send('POST', '/echo')->getBody()->getContents();

		$this->assertStringContainsString("\r\nContent-Length: 0\r\n", $echo);
	}

	public function testLargeBodyIsStreamed(): void
	{
		$data = str_repeat('0123456789', 100_000);
		$echo = $this->send('PUT', '/echo', $data)->getBody()->getContents();

		$this->assertStringContainsString("\r\nContent-Length: 1000000\r\n", $echo);
		$this->assertStringEndsWith("\r\n\r\n" . $data, $echo);
	}

	public function testBodyOfUnknownSizeIsBuffered(): void
	{
		$request = $this->requestFactory
			->createRequest('POST', self::url('/echo'))
			->withBody(new UnknownSizeStream('Hello World'));

		$echo = $this->client->sendRequest($request)->getBody()->getContents();

		$this->assertStringContainsString("\r\nContent-Length: 11\r\n", $echo);
		$this->assertStringNotContainsString('Transfer-Encoding', $echo);
		$this->assertStringEndsWith("\r\n\r\nHello World", $echo);
	}

	public function testExplicitChunkedRequest(): void
	{
		$request = $this->requestFactory
			->createRequest('POST', self::url('/echo'))
			->withHeader('Transfer-Encoding', 'chunked')
			->withHeader('Content-Length', '5')
			->withBody(new UnknownSizeStream('Hello'));

		$echo = $this->client->sendRequest($request)->getBody()->getContents();

		$this->assertStringContainsString("\r\nTransfer-Encoding: chunked\r\n", $echo);
		$this->assertStringNotContainsString('Content-Length', $echo);
		$this->assertStringEndsWith("\r\n\r\n5\r\nHello\r\n0\r\n\r\n", $echo);
	}

	public function testEmptyChunkedRequest(): void
	{
		$request = $this->requestFactory
			->createRequest('POST', self::url('/echo'))
			->withHeader('Transfer-Encoding', 'chunked');

		$echo = $this->client->sendRequest($request)->getBody()->getContents();

		$this->assertStringEndsWith("\r\n\r\n0\r\n\r\n", $echo);
	}

	public function testRequestIsRoutedByHostHeader(): void
	{
		$host = '127.0.0.1:' . self::$port;
		$request = $this->requestFactory->createRequest('GET', '/echo')->withHeader('Host', $host);

		$echo = $this->client->sendRequest($request)->getBody()->getContents();

		$this->assertStringStartsWith("GET /echo HTTP/1.1\r\nHost: $host\r\n", $echo);
	}

	public function testAsteriskFormRequest(): void
	{
		$request = $this->requestFactory->createRequest('OPTIONS', self::url('/'))->withRequestTarget('*');

		$echo = $this->client->sendRequest($request)->getBody()->getContents();

		$this->assertStringStartsWith("OPTIONS * HTTP/1.1\r\n", $echo);
	}

	public function testAbsoluteFormRequest(): void
	{
		$request = $this->requestFactory->createRequest('GET', self::url('/echo'))->withRequestTarget(self::url('/echo'));

		$echo = $this->client->sendRequest($request)->getBody()->getContents();

		$this->assertStringStartsWith('GET ' . self::url('/echo') . " HTTP/1.1\r\n", $echo);
	}

	public function testCookiesAreJoinedBySemicolons(): void
	{
		$request = $this->requestFactory->createRequest('GET', self::url('/echo'))->withHeader('Cookie', ['a=1', 'b=2']);

		$echo = $this->client->sendRequest($request)->getBody()->getContents();

		$this->assertStringContainsString("\r\nCookie: a=1; b=2\r\n", $echo);
	}

	public function testChunkedResponseIsDecoded(): void
	{
		$response = $this->send('GET', '/chunked');

		$this->assertSame('Hello, World!', $response->getBody()->getContents());
		$this->assertFalse($response->hasHeader('Transfer-Encoding'));
		$this->assertFalse($response->hasHeader('Content-Length'));
		$this->assertFalse($response->hasHeader('X-Trailer'));
	}

	public function testOnlyChunkedCodingIsRemoved(): void
	{
		$response = $this->send('GET', '/chunked-gzip');

		$this->assertSame('abc', $response->getBody()->getContents());
		$this->assertSame('gzip', $response->getHeaderLine('Transfer-Encoding'));
	}

	public function testNonChunkedTransferCodingIsReadUntilClose(): void
	{
		$response = $this->send('GET', '/gzip-close');

		$this->assertSame('raw', $response->getBody()->getContents());
		$this->assertSame('gzip', $response->getHeaderLine('Transfer-Encoding'));
	}

	public function testTruncatedChunkedBodyIsIncomplete(): void
	{
		$body = $this->send('GET', '/chunked-truncated')->getBody();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('closed prematurely');

		$body->getContents();
	}

	public function testInterimResponsesAreSkipped(): void
	{
		$response = $this->send('GET', '/interim');

		$this->assertSame(200, $response->getStatusCode());
		$this->assertFalse($response->hasHeader('Link'));
		$this->assertSame('ok', $response->getBody()->getContents());
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function bodilessResponseProvider(): iterable
	{
		yield 'HEAD request' => ['HEAD', '/length'];
		yield '204 No Content' => ['GET', '/no-content'];
		yield '304 Not Modified' => ['GET', '/not-modified'];
	}

	public function testMaximumInterimResponses(): void
	{
		$this->assertSame('ok', $this->send('GET', '/interim-max')->getBody()->getContents());
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function contentLengthMismatchProvider(): iterable
	{
		yield 'body too long' => ['3'];
		yield 'body too short' => ['10'];
	}

	#[DataProvider('contentLengthMismatchProvider')]
	public function testContentLengthMismatchOfUnknownSizeBody(string $length): void
	{
		$request = $this->requestFactory
			->createRequest('POST', self::url('/echo'))
			->withHeader('Content-Length', $length)
			->withBody(new UnknownSizeStream('Hello'));

		$this->expectException(RequestException::class);
		$this->expectExceptionMessage('Content-Length does not match');

		$this->client->sendRequest($request);
	}

	#[DataProvider('bodilessResponseProvider')]
	public function testResponsesWithoutBody(string $method, string $path): void
	{
		$body = $this->send($method, $path)->getBody();

		$this->assertTrue($body->eof());
		$this->assertSame('', $body->getContents());
	}

	public function testContentLength(): void
	{
		$response = $this->send('GET', '/length');

		$this->assertSame(5, $response->getBody()->getSize());
		$this->assertSame('Hello', $response->getBody()->getContents());
	}

	public function testDuplicateContentLengthIsCollapsed(): void
	{
		$response = $this->send('GET', '/duplicate-length');

		$this->assertSame('2', $response->getHeaderLine('Content-Length'));
		$this->assertSame('ok', $response->getBody()->getContents());
	}

	public function testTruncatedBodyIsIncomplete(): void
	{
		$body = $this->send('GET', '/truncated')->getBody();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('closed prematurely');

		$body->getContents();
	}

	public function testCloseDelimitedBody(): void
	{
		$response = $this->send('GET', '/close-delimited');

		$this->assertSame('1.0', $response->getProtocolVersion());
		$this->assertNull($response->getBody()->getSize());
		$this->assertSame('until close', $response->getBody()->getContents());
	}

	public function testHeaderParsing(): void
	{
		$response = $this->send('GET', '/headers');

		$this->assertSame(404, $response->getStatusCode());
		$this->assertSame('Not Found', $response->getReasonPhrase());
		$this->assertSame('foo bar', $response->getHeaderLine('X-Folded'));
		$this->assertSame(['a=1', 'b=2'], $response->getHeader('Set-Cookie'));
		$this->assertTrue($response->hasHeader('X-Empty'));
		$this->assertFalse($response->hasHeader('X-Ignored'));
		$this->assertFalse($response->hasHeader('Bad Name'));
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function invalidResponseProvider(): iterable
	{
		yield 'malformed status line' => ['/malformed', 'invalid status line'];
		yield 'unsupported version' => ['/http2', 'invalid status line'];
		yield 'incomplete head' => ['/incomplete-head', 'Connection closed'];
		yield 'conflicting Content-Length' => ['/conflicting-length', 'invalid Content-Length'];
		yield 'negative Content-Length' => ['/invalid-length', 'invalid Content-Length'];
		yield 'overflowing Content-Length' => ['/overflow-length', 'invalid Content-Length'];
		yield 'HTTP/1.0 with Transfer-Encoding' => ['/http10-chunked', 'Transfer-Encoding'];
		yield 'protocol switch' => ['/switching', 'upgrades are not supported'];
		yield 'too many interim responses' => ['/interim-flood', 'more than 10 interim responses'];
		yield 'oversized head' => ['/huge-head', 'Response head exceeds'];
	}

	#[DataProvider('invalidResponseProvider')]
	public function testInvalidResponses(string $path, string $message): void
	{
		$this->expectException(NetworkException::class);
		$this->expectExceptionMessage($message);

		$this->send('GET', $path);
	}

	public function testReadTimeout(): void
	{
		$client = new Client(new ResponseFactory(), 0.1);

		$this->expectException(NetworkException::class);
		$this->expectExceptionMessage('timed out');

		$client->sendRequest($this->requestFactory->createRequest('GET', self::url('/slow')));
	}

	public function testMaxDurationLimitsSlowHead(): void
	{
		$client = new Client(new ResponseFactory(), 1, maxDuration: 0.2);
		$start = microtime(true);

		try {
			$client->sendRequest($this->requestFactory->createRequest('GET', self::url('/drip-head')));
			$this->fail('Expected a NetworkException');
		} catch (NetworkException $e) {
			$this->assertStringContainsString('maximum duration', $e->getMessage());
		}

		$this->assertLessThan(0.4, microtime(true) - $start);
	}

	public function testMaxDurationIncludesReadingTheBody(): void
	{
		$client = new Client(new ResponseFactory(), 1, maxDuration: 0.2);
		$body = $client->sendRequest($this->requestFactory->createRequest('GET', self::url('/drip')))->getBody();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('maximum duration');

		$body->getContents();
	}

	public function testSlowResponseWithoutMaxDuration(): void
	{
		$client = new Client(new ResponseFactory(), 1);
		$response = $client->sendRequest($this->requestFactory->createRequest('GET', self::url('/drip')));

		$this->assertSame(str_repeat('x', 20), $response->getBody()->getContents());
	}

	public function testConnectionRefused(): void
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertNotFalse($socket);
		$address = (string) stream_socket_get_name($socket, false);
		fclose($socket);

		try {
			$this->client->sendRequest($this->requestFactory->createRequest('GET', "http://$address/"));
			$this->fail('Expected a NetworkException');
		} catch (NetworkException $e) {
			$this->assertStringContainsString('Unable to connect', $e->getMessage());
			$this->assertGreaterThan(0, $e->getCode());
		}
	}

	/**
	 * @return iterable<string, array{callable(RequestInterface): RequestInterface, string}>
	 */
	public static function invalidRequestProvider(): iterable
	{
		yield 'HTTP/2' => [
			fn(RequestInterface $r) => $r->withProtocolVersion('2'),
			'HTTP/2 is not supported',
		];
		yield 'CONNECT' => [
			fn(RequestInterface $r) => $r->withMethod('CONNECT')->withRequestTarget('example.com:443'),
			'is not supported',
		];
		yield 'upgrade' => [
			fn(RequestInterface $r) => $r->withHeader('Upgrade', 'websocket'),
			'upgrades are not supported',
		];
		yield 'Content-Length mismatch' => [
			fn(RequestInterface $r) => $r->withHeader('Content-Length', '3'),
			'Content-Length does not match',
		];
		yield 'unsupported Transfer-Encoding' => [
			fn(RequestInterface $r) => $r->withHeader('Transfer-Encoding', 'gzip'),
			'Transfer-Encoding must end with "chunked"',
		];
		yield 'chunked HTTP/1.0' => [
			fn(RequestInterface $r) => $r->withHeader('Transfer-Encoding', 'chunked')->withProtocolVersion('1.0'),
			'Transfer-Encoding requires HTTP/1.1',
		];
	}

	/**
	 * @param callable(RequestInterface): RequestInterface $modify
	 */
	#[DataProvider('invalidRequestProvider')]
	public function testInvalidRequests(callable $modify, string $message): void
	{
		$request = $this->requestFactory->createRequest('POST', self::url('/echo'));
		$request->getBody()->write('Hello');
		$request = $modify($request);

		try {
			$this->client->sendRequest($request);
			$this->fail('Expected a RequestException');
		} catch (RequestException $e) {
			$this->assertStringContainsString($message, $e->getMessage());
			$this->assertSame((string) $request->getUri(), (string) $e->getRequest()->getUri());
		}
	}

	public function testUnsupportedScheme(): void
	{
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getScheme')->willReturn('ftp');
		$uri->method('getHost')->willReturn('localhost');

		$request = $this->requestFactory->createRequest('GET', self::url('/'))->withUri($uri, true);

		$this->expectException(RequestException::class);
		$this->expectExceptionMessage('Scheme "ftp" is not supported');

		$this->client->sendRequest($request);
	}

	public function testInvalidTimeout(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Client(new ResponseFactory(), 0);
	}

	public function testInvalidMaxDuration(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Client(new ResponseFactory(), maxDuration: 0);
	}

	public function testInvalidBufferSize(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new Client(new ResponseFactory(), 1, [], 0);
	}

	private function send(string $method, string $path, string $body = ''): ResponseInterface
	{
		$request = $this->requestFactory->createRequest($method, self::url($path));

		if ($body !== '') {
			$request->getBody()->write($body);
		}

		return $this->client->sendRequest($request);
	}
}
