<?php

declare(strict_types=1);

namespace Semperton\Proxy;

use Override;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Semperton\Proxy\Internal\Connection;
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
 * Read-only body of a response, read directly from the connection.
 *
 * The body is framed in one of three ways (RFC 9112 §6.3):
 * - sized: exactly $size bytes (Content-Length)
 * - chunked: chunked transfer coding, decoded on the fly (RFC 9112 §7.1)
 * - close-delimited: everything until the server closes the connection
 */
final class Stream implements StreamInterface
{
	private const MAX_LINE_LENGTH = 8192;

	private ?Connection $connection;

	private int $bytesRead = 0;

	private int $chunkRemaining = 0;

	private bool $finished;

	/**
	 * @param int|null $size Body length in bytes, null if chunked or close-delimited
	 */
	public function __construct(
		Connection $connection,
		private readonly ?int $size = null,
		private readonly bool $chunked = false,
		private readonly int $bufferSize = 8192,
	) {
		$this->connection = $connection;
		$this->finished = $size === 0;
	}

	public function __destruct()
	{
		$this->close();
	}

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
	}

	#[Override]
	public function detach()
	{
		$resource = $this->connection?->detach();
		$this->connection = null;

		return $resource;
	}

	#[Override]
	public function getSize(): ?int
	{
		return $this->connection === null ? null : $this->size;
	}

	#[Override]
	public function tell(): int
	{
		$this->connection();

		return $this->bytesRead;
	}

	#[Override]
	public function eof(): bool
	{
		$connection = $this->connection();

		if ($this->finished) {
			return true;
		}

		return $this->size === null && !$this->chunked && $connection->eof();
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
		return $this->connection !== null;
	}

	#[Override]
	public function read(int $length): string
	{
		$connection = $this->connection();

		if ($length < 0) {
			throw new RuntimeException('Length must not be negative');
		}

		if ($length === 0 || $this->finished) {
			return '';
		}

		if ($this->chunked) {
			return $this->readChunked($connection, $length);
		}

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
		$connection = $this->connection();

		if ($this->finished) {
			return '';
		}

		if ($this->chunked) {
			$contents = '';

			while (!$this->finished) {
				$contents .= $this->readChunked($connection, $this->bufferSize);
			}

			return $contents;
		}

		$remaining = $this->size === null ? null : $this->size - $this->bytesRead;
		$contents = $connection->readAll($remaining);
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
		if ($this->connection === null) {
			return $key === null ? [] : null;
		}

		$meta = $this->connection->metadata();

		return $key === null ? $meta : ($meta[$key] ?? null);
	}

	private function readChunked(Connection $connection, int $length): string
	{
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

	private function connection(): Connection
	{
		if ($this->connection === null) {
			throw new RuntimeException('Stream is detached or closed');
		}

		return $this->connection;
	}
}
