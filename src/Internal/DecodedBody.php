<?php

declare(strict_types=1);

namespace Semperton\Proxy\Internal;

use InflateContext;
use Override;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use function inflate_add;
use function inflate_get_status;
use function inflate_init;
use function strlen;
use function substr;

use const SEEK_SET;
use const ZLIB_ENCODING_DEFLATE;
use const ZLIB_ENCODING_GZIP;
use const ZLIB_STREAM_END;
use const ZLIB_SYNC_FLUSH;

/**
 * Response body with its gzip or deflate content coding removed while reading (RFC 9110 §8.4.1).
 *
 * @internal
 */
final class DecodedBody implements StreamInterface
{
	private InflateContext $context;

	private string $buffer = '';

	private int $position = 0;

	private bool $finished = false;

	/**
	 * @param string $coding "gzip" or "deflate"
	 */
	public function __construct(
		private readonly StreamInterface $body,
		string $coding,
		private readonly int $bufferSize,
	) {
		$context = inflate_init($coding === 'deflate' ? ZLIB_ENCODING_DEFLATE : ZLIB_ENCODING_GZIP);

		if ($context === false) {
			throw new RuntimeException('Unable to initialize the decompression');
		}

		$this->context = $context;
	}

	#[Override]
	public function __toString(): string
	{
		try {
			return $this->getContents();
		} catch (Throwable) {
			return '';
		}
	}

	#[Override]
	public function close(): void
	{
		$this->body->close();
	}

	#[Override]
	public function detach()
	{
		return $this->body->detach();
	}

	#[Override]
	public function getSize(): ?int
	{
		return null;
	}

	#[Override]
	public function tell(): int
	{
		return $this->position;
	}

	/**
	 * Only true once the end of the compressed data has been verified.
	 */
	#[Override]
	public function eof(): bool
	{
		return $this->finished && $this->buffer === '';
	}

	#[Override]
	public function isSeekable(): bool
	{
		return false;
	}

	#[Override]
	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		throw new RuntimeException('Stream is not seekable');
	}

	#[Override]
	public function rewind(): void
	{
		throw new RuntimeException('Stream is not seekable');
	}

	#[Override]
	public function isWritable(): bool
	{
		return false;
	}

	#[Override]
	public function write(string $string): int
	{
		throw new RuntimeException('Stream is not writable');
	}

	#[Override]
	public function isReadable(): bool
	{
		return $this->body->isReadable();
	}

	#[Override]
	public function read(int $length): string
	{
		if ($length < 0) {
			throw new RuntimeException('Length must not be negative');
		}

		if ($length === 0) {
			return '';
		}

		while ($this->buffer === '' && !$this->finished) {
			$this->decode();
		}

		$data = substr($this->buffer, 0, $length);
		$this->buffer = substr($this->buffer, strlen($data));
		$this->position += strlen($data);

		return $data;
	}

	#[Override]
	public function getContents(): string
	{
		$contents = '';

		while (!$this->eof()) {
			$contents .= $this->read($this->bufferSize * 4);
		}

		return $contents;
	}

	#[Override]
	public function getMetadata(?string $key = null)
	{
		return $this->body->getMetadata($key);
	}

	private function decode(): void
	{
		$data = $this->body->read($this->bufferSize);

		if ($data === '' && $this->body->eof()) {
			if (inflate_get_status($this->context) !== ZLIB_STREAM_END) {
				throw new RuntimeException('Incomplete compressed response body');
			}

			$this->finished = true;

			return;
		}

		$decoded = @inflate_add($this->context, $data, ZLIB_SYNC_FLUSH);

		if ($decoded === false) {
			throw new RuntimeException('Unable to decode the response body');
		}

		$this->buffer .= $decoded;
	}
}
