<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests\Internal;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Semperton\Proxy\Internal\Connection;
use Semperton\Proxy\Internal\DecodedBody;
use Semperton\Proxy\Internal\ResponseBody;

use function fopen;
use function fwrite;
use function gzcompress;
use function gzencode;
use function is_resource;
use function rewind;
use function str_repeat;
use function strlen;
use function substr;

#[RequiresPhpExtension('zlib')]
final class DecodedBodyTest extends TestCase
{
	public function testGzip(): void
	{
		$body = $this->createBody((string) gzencode('Hello World'), 'gzip');

		$this->assertNull($body->getSize());
		$this->assertFalse($body->eof());
		$this->assertSame('Hello', $body->read(5));
		$this->assertSame(5, $body->tell());
		$this->assertSame(' World', $body->getContents());
		$this->assertTrue($body->eof());
		$this->assertSame('', $body->read(5));
	}

	public function testDeflate(): void
	{
		$this->assertSame('Hello World', $this->createBody((string) gzcompress('Hello World'), 'deflate')->getContents());
	}

	public function testEmptyPayload(): void
	{
		$body = $this->createBody((string) gzencode(''), 'gzip');

		$this->assertSame('', $body->read(10));
		$this->assertTrue($body->eof());
	}

	public function testTruncationIsDetectedInReadLoop(): void
	{
		$body = $this->createBody(substr((string) gzencode(str_repeat('a', 1000)), 0, -8), 'gzip');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Incomplete compressed response body');

		while (!$body->eof()) {
			$body->read(100);
		}
	}

	public function testToStringDoesNotThrow(): void
	{
		$this->assertSame('', (string) $this->createBody('not compressed', 'gzip'));
		$this->assertSame('abc', (string) $this->createBody((string) gzencode('abc'), 'gzip'));
	}

	public function testNotSeekableOrWritable(): void
	{
		$body = $this->createBody((string) gzencode('abc'), 'gzip');

		$this->assertFalse($body->isSeekable());
		$this->assertFalse($body->isWritable());

		foreach ([fn() => $body->seek(0), fn() => $body->rewind(), fn() => $body->write('x')] as $operation) {
			try {
				$operation();
				$this->fail('Expected a RuntimeException');
			} catch (RuntimeException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testDelegatesToInnerBody(): void
	{
		$body = $this->createBody((string) gzencode('abc'), 'gzip');

		$this->assertTrue($body->isReadable());
		$this->assertSame('php://temp', $body->getMetadata('uri'));
		$this->assertTrue(is_resource($body->detach()));
		$this->assertFalse($body->isReadable());
	}

	private function createBody(string $compressed, string $coding): DecodedBody
	{
		$resource = fopen('php://temp', 'r+b');
		$this->assertNotFalse($resource);
		fwrite($resource, $compressed);
		rewind($resource);

		$inner = ResponseBody::sized(Connection::wrap($resource), strlen($compressed), 8192);

		return new DecodedBody($inner, $coding, 8192);
	}
}
