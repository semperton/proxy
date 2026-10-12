<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Semperton\Proxy\Stream;

use function fopen;
use function fwrite;
use function is_resource;
use function rewind;

final class StreamTest extends TestCase
{
	public function testSizedStream(): void
	{
		$stream = $this->createStream('Hello World', 5);

		$this->assertSame(5, $stream->getSize());
		$this->assertSame('He', $stream->read(2));
		$this->assertSame(2, $stream->tell());
		$this->assertFalse($stream->eof());
		$this->assertSame('llo', $stream->read(100));
		$this->assertTrue($stream->eof());
		$this->assertSame('', $stream->read(100));
		$this->assertSame(5, $stream->tell());
	}

	public function testSizedStreamGetContents(): void
	{
		$stream = $this->createStream('Hello World', 5);
		$stream->read(1);

		$this->assertSame('ello', $stream->getContents());
		$this->assertTrue($stream->eof());
		$this->assertSame('', $stream->getContents());
	}

	public function testIncompleteSizedStream(): void
	{
		$stream = $this->createStream('Hello', 10);
		$stream->read(5);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Incomplete response body');

		$stream->read(5);
	}

	public function testCloseDelimitedStream(): void
	{
		$stream = $this->createStream('Hello World');

		$this->assertNull($stream->getSize());
		$this->assertSame('Hello', $stream->read(5));
		$this->assertSame(' World', $stream->getContents());
		$this->assertTrue($stream->eof());
		$this->assertSame(11, $stream->tell());
	}

	public function testChunkedStream(): void
	{
		$stream = $this->createStream("5\r\nHello\r\n7;ext=\"v\"\r\n, World\r\n0\r\nTrailer: x\r\n\r\nignored", null, true);

		$this->assertNull($stream->getSize());
		$this->assertSame('Hel', $stream->read(3));
		$this->assertSame('lo', $stream->read(100));
		$this->assertSame(', World', $stream->getContents());
		$this->assertTrue($stream->eof());
		$this->assertSame(12, $stream->tell());
	}

	public function testChunkedStreamReadLoop(): void
	{
		$stream = $this->createStream("3\r\nabc\r\n3\r\ndef\r\n0\r\n\r\n", null, true);
		$contents = '';

		while (!$stream->eof()) {
			$contents .= $stream->read(2);
		}

		$this->assertSame('abcdef', $contents);
	}

	public function testChunkSizeWithLeadingZeros(): void
	{
		$stream = $this->createStream("00000000000000000003\r\nabc\r\n000\r\n\r\n", null, true);

		$this->assertSame('abc', $stream->getContents());
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function invalidChunkedProvider(): iterable
	{
		yield 'missing last chunk' => ["3\r\nabc\r\n", 'Incomplete response body'];
		yield 'truncated chunk' => ["5\r\nabc", 'Incomplete response body'];
		yield 'invalid size' => ["xyz\r\nabc\r\n0\r\n\r\n", 'malformed chunk size'];
		yield 'overflowing size' => ["fffffffffffffffff\r\n", 'chunk size too large'];
		yield 'missing CRLF' => ["3\r\nabcdef\r\n0\r\n\r\n", 'missing CRLF'];
	}

	#[DataProvider('invalidChunkedProvider')]
	public function testInvalidChunkedStream(string $contents, string $message): void
	{
		$stream = $this->createStream($contents, null, true);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		$stream->getContents();
	}

	public function testToStringDoesNotThrow(): void
	{
		$this->assertSame('', (string) $this->createStream('abc', 10));
		$this->assertSame('abc', (string) $this->createStream('abc', 3));
	}

	public function testNotSeekableOrWritable(): void
	{
		$stream = $this->createStream('abc');

		$this->assertTrue($stream->isReadable());
		$this->assertFalse($stream->isSeekable());
		$this->assertFalse($stream->isWritable());

		foreach ([fn() => $stream->seek(0), fn() => $stream->rewind(), fn() => $stream->write('x')] as $operation) {
			try {
				$operation();
				$this->fail('Expected a RuntimeException');
			} catch (RuntimeException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testMetadata(): void
	{
		$stream = $this->createStream('abc');

		$this->assertSame('php://temp', $stream->getMetadata('uri'));
		$this->assertIsArray($stream->getMetadata());
		$this->assertNull($stream->getMetadata('unknown'));
	}

	public function testDetach(): void
	{
		$stream = $this->createStream('abc', 3);
		$resource = $stream->detach();

		$this->assertTrue(is_resource($resource));
		$this->assertNull($stream->detach());
		$this->assertNull($stream->getSize());
		$this->assertFalse($stream->isReadable());
		$this->assertSame([], $stream->getMetadata());
		$this->assertNull($stream->getMetadata('uri'));
		$this->assertSame('', (string) $stream);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('detached');

		$stream->read(1);
	}

	public function testCloseIsIdempotent(): void
	{
		$resource = fopen('php://temp', 'r+b');
		$this->assertNotFalse($resource);
		$stream = new Stream($resource);

		$stream->close();
		$stream->close();

		$this->assertFalse(is_resource($resource));
		$this->assertFalse($stream->isReadable());

		$this->expectException(RuntimeException::class);
		$stream->eof();
	}

	private function createStream(string $contents, ?int $size = null, bool $chunked = false): Stream
	{
		$resource = fopen('php://temp', 'r+b');
		$this->assertNotFalse($resource);
		fwrite($resource, $contents);
		rewind($resource);

		return new Stream($resource, $size, $chunked);
	}
}
