<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests;

use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\ResponseFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Semperton\Proxy\Client;

use function gzencode;
use function str_repeat;
use function strlen;

#[RequiresPhpExtension('zlib')]
final class DecodingTest extends TestCase
{
	use TestServerTrait;

	private const PAYLOAD = 'Hello compression! ';

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function encodedResponseProvider(): iterable
	{
		yield 'gzip' => ['/gzip'];
		yield 'deflate' => ['/deflate'];
		yield 'chunked x-gzip' => ['/gzip-chunked'];
	}

	#[DataProvider('encodedResponseProvider')]
	public function testResponseIsDecoded(string $path): void
	{
		$response = $this->send($this->get($path));

		$this->assertSame(self::payload(), $response->getBody()->getContents());
		$this->assertFalse($response->hasHeader('Content-Encoding'));
		$this->assertFalse($response->hasHeader('Content-Length'));
		$this->assertNull($response->getBody()->getSize());
	}

	public function testDecodingInSmallReads(): void
	{
		$body = $this->send($this->get('/gzip'))->getBody();
		$contents = '';

		while (!$body->eof()) {
			$contents .= $body->read(3);
		}

		$this->assertSame(self::payload(), $contents);
		$this->assertSame(strlen(self::payload()), $body->tell());
	}

	public function testAcceptEncodingIsSent(): void
	{
		$echo = $this->send($this->get('/echo'))->getBody()->getContents();

		$this->assertStringContainsString("\r\nAccept-Encoding: gzip, deflate\r\n", $echo);
	}

	public function testOwnAcceptEncodingIsKept(): void
	{
		$echo = $this->send($this->get('/echo')->withHeader('Accept-Encoding', 'br'))->getBody()->getContents();

		$this->assertStringContainsString("\r\nAccept-Encoding: br\r\n", $echo);
		$this->assertStringNotContainsString('deflate', $echo);
	}

	public function testDecodingIsDisabledByDefault(): void
	{
		$client = new Client(new ResponseFactory(), 2);

		$response = $client->sendRequest($this->get('/gzip'));
		$echo = $client->sendRequest($this->get('/echo'))->getBody()->getContents();

		$this->assertSame(gzencode(self::payload()), $response->getBody()->getContents());
		$this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
		$this->assertStringNotContainsString('Accept-Encoding', $echo);
	}

	public function testUnsupportedCodingIsKept(): void
	{
		$response = $this->send($this->get('/brotli'));

		$this->assertSame('raw brotli bytes', $response->getBody()->getContents());
		$this->assertSame('br', $response->getHeaderLine('Content-Encoding'));
	}

	public function testHeadResponseIsNotDecoded(): void
	{
		$response = $this->send((new RequestFactory())->createRequest('HEAD', self::url('/gzip')));

		$this->assertSame('', $response->getBody()->getContents());
		$this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function invalidEncodingProvider(): iterable
	{
		yield 'truncated' => ['/gzip-truncated', 'Incomplete compressed response body'];
		yield 'corrupt' => ['/gzip-corrupt', 'Unable to decode the response body'];
	}

	#[DataProvider('invalidEncodingProvider')]
	public function testInvalidEncoding(string $path, string $message): void
	{
		$body = $this->send($this->get($path))->getBody();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		$body->getContents();
	}

	private static function payload(): string
	{
		return str_repeat(self::PAYLOAD, 2000);
	}

	private function get(string $path): RequestInterface
	{
		return (new RequestFactory())->createRequest('GET', self::url($path));
	}

	private function send(RequestInterface $request): ResponseInterface
	{
		return (new Client(new ResponseFactory(), 2, decodeContent: true))->sendRequest($request);
	}
}
