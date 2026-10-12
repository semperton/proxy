<?php

declare(strict_types=1);

namespace Semperton\Proxy\Internal;

use Override;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use function hexdec;
use function ltrim;
use function min;
use function preg_match;
use function strlen;
use function strpos;
use function substr;
use function trim;

use const PHP_INT_SIZE;
use const SEEK_SET;

/**
 * Read-only response body, read from the connection on demand.
 *
 * The body is framed in one of three ways (RFC 9112 §6.3):
 * - sized: exactly the number of bytes given by Content-Length
 * - chunked: chunked transfer coding, decoded while reading (RFC 9112 §7.1)
 * - until close: everything until the server closes the connection
 *
 * @internal
 */
final class ResponseBody implements StreamInterface
{
	private const MAX_LINE_LENGTH = 8192;

	private bool $closed = false;

	private bool $finished;

	private int $bytesRead = 0;

	private int $chunkRemaining = 0;

	private function __construct(
		private ?Connection $connection,
		private readonly ?int $size,
		private readonly bool $chunked,
		private readonly int $bufferSize,
	) {
		$this->finished = $size === 0;
	}

	public function __destruct()
	{
		$this->close();
	}

	public static function empty(): self
	{
		return new self(null, 0, false, 1);
	}

	public static function sized(Connection $connection, int $length, int $bufferSize): self
	{
		return new self($connection, $length, false, $bufferSize);
	}

	public static function chunked(Connection $connection, int $bufferSize): self
	{
		return new self($connection, null, true, $bufferSize);
	}

	public static function untilClose(Connection $connection, int $bufferSize): self
	{
		return new self($connection, null, false, $bufferSize);
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
		$this->connection?->close();
		$this->connection = null;
		$this->closed = true;
	}

	#[Override]
	public function detach()
	{
		$resource = $this->connection?->detach();
		$this->connection = null;
		$this->closed = true;

		return $resource;
	}

	#[Override]
	public function getSize(): ?int
	{
		return $this->closed ? null : $this->size;
	}

	#[Override]
	public function tell(): int
	{
		$this->assertOpen();

		return $this->bytesRead;
	}

	#[Override]
	public function eof(): bool
	{
		$this->assertOpen();

		if ($this->finished) {
			return true;
		}

		return $this->size === null && !$this->chunked && $this->connection()->eof();
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
		return !$this->closed;
	}

	#[Override]
	public function read(int $length): string
	{
		$this->assertOpen();

		if ($length < 0) {
			throw new RuntimeException('Length must not be negative');
		}

		if ($length === 0 || $this->finished) {
			return '';
		}

		if ($this->chunked) {
			return $this->readChunked($length);
		}

		$connection = $this->connection();

		if ($this->size === null) {
			$data = $connection->read($length);
			$this->bytesRead += strlen($data);
			$this->finished = $data === '';

			return $data;
		}

		$data = $connection->read(min($length, $this->size - $this->bytesRead));

		if ($data === '') {
			throw $this->incomplete();
		}

		$this->bytesRead += strlen($data);
		$this->finished = $this->bytesRead === $this->size;

		return $data;
	}

	#[Override]
	public function getContents(): string
	{
		$this->assertOpen();

		if ($this->finished) {
			return '';
		}

		if ($this->chunked) {
			$contents = '';

			while (!$this->finished) {
				$contents .= $this->readChunked($this->bufferSize);
			}

			return $contents;
		}

		$remaining = $this->size === null ? null : $this->size - $this->bytesRead;
		$contents = $this->connection()->readAll($remaining);
		$this->bytesRead += strlen($contents);

		if ($remaining !== null && strlen($contents) < $remaining) {
			throw $this->incomplete();
		}

		$this->finished = true;

		return $contents;
	}

	#[Override]
	public function getMetadata(?string $key = null)
	{
		$meta = $this->connection?->metadata() ?? [];

		return $key === null ? $meta : ($meta[$key] ?? null);
	}

	private function readChunked(int $length): string
	{
		$connection = $this->connection();

		if ($this->chunkRemaining === 0) {
			$this->chunkRemaining = $this->readChunkSize($connection);

			if ($this->chunkRemaining === 0) {
				// the trailer section is discarded (RFC 9112 §7.1.2)
				do {
					$trailer = $connection->readLine(self::MAX_LINE_LENGTH);
				} while ($trailer !== '');

				$this->finished = true;

				return '';
			}
		}

		$data = $connection->read(min($length, $this->chunkRemaining));

		if ($data === '') {
			throw $this->incomplete();
		}

		$this->bytesRead += strlen($data);
		$this->chunkRemaining -= strlen($data);

		if ($this->chunkRemaining === 0 && $connection->readLine(self::MAX_LINE_LENGTH) !== '') {
			throw new RuntimeException('Invalid chunked transfer coding: missing CRLF after chunk data');
		}

		return $data;
	}

	private function readChunkSize(Connection $connection): int
	{
		$line = $connection->readLine(self::MAX_LINE_LENGTH);

		// chunk extensions are ignored (RFC 9112 §7.1.1)
		$extension = strpos($line, ';');
		$size = trim($extension === false ? $line : substr($line, 0, $extension), " \t");

		if (preg_match('/^[0-9a-fA-F]+$/', $size) !== 1) {
			throw new RuntimeException('Invalid chunked transfer coding: malformed chunk size');
		}

		$size = ltrim($size, '0');

		// guard against integer overflow (RFC 9112 §7.1)
		if (strlen($size) >= PHP_INT_SIZE * 2) {
			throw new RuntimeException('Invalid chunked transfer coding: chunk size too large');
		}

		return $size === '' ? 0 : (int) hexdec($size);
	}

	private function incomplete(): RuntimeException
	{
		return new RuntimeException('Incomplete response body, the connection was closed prematurely');
	}

	private function assertOpen(): void
	{
		if ($this->closed) {
			throw new RuntimeException('Stream is detached or closed');
		}
	}

	/**
	 * Only called while the body is not finished, which implies a connection.
	 */
	private function connection(): Connection
	{
		return $this->connection ?? throw new RuntimeException('Stream has no connection');
	}
}
