<?php

declare(strict_types=1);

namespace Semperton\Proxy;

use Override;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use function fclose;
use function feof;
use function fgets;
use function fread;
use function hexdec;
use function is_resource;
use function ltrim;
use function min;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function stream_get_contents;
use function stream_get_meta_data;
use function strlen;
use function strpos;
use function substr;
use function trim;

use const PHP_INT_SIZE;
use const SEEK_SET;

/**
 * Read-only body of a response, read directly from the socket.
 *
 * The body is framed in one of three ways (RFC 9112 §6.3):
 * - sized: exactly $size bytes (Content-Length)
 * - chunked: chunked transfer coding, decoded on the fly (RFC 9112 §7.1)
 * - close-delimited: everything until the server closes the connection
 */
final class Stream implements StreamInterface
{
	private const MAX_LINE_LENGTH = 8192;

	/** @var resource|null */
	private $socket;

	private int $bytesRead = 0;

	private int $chunkRemaining = 0;

	private bool $finished;

	/**
	 * @param resource $socket
	 * @param int|null $size Body length in bytes, null if chunked or close-delimited
	 */
	public function __construct(
		$socket,
		private readonly ?int $size = null,
		private readonly bool $chunked = false,
		private readonly int $bufferSize = 8192,
	) {
		$this->socket = $socket;
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
		$socket = $this->socket;
		$this->socket = null;

		if (is_resource($socket)) {
			fclose($socket);
		}
	}

	#[Override]
	public function detach()
	{
		$socket = $this->socket;
		$this->socket = null;

		return $socket;
	}

	#[Override]
	public function getSize(): ?int
	{
		return $this->socket === null ? null : $this->size;
	}

	#[Override]
	public function tell(): int
	{
		$this->getSocket();

		return $this->bytesRead;
	}

	#[Override]
	public function eof(): bool
	{
		$socket = $this->getSocket();

		if ($this->finished) {
			return true;
		}

		return $this->size === null && !$this->chunked && feof($socket);
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
		return $this->socket !== null;
	}

	#[Override]
	public function read(int $length): string
	{
		$socket = $this->getSocket();

		if ($length < 0) {
			throw new RuntimeException('Length must not be negative');
		}

		if ($length === 0 || $this->finished) {
			return '';
		}

		if ($this->chunked) {
			return $this->readChunked($socket, $length);
		}

		if ($this->size === null) {
			$data = $this->fread($socket, $length);
			$this->bytesRead += strlen($data);
			$this->finished = $data === '' && feof($socket);

			return $data;
		}

		$data = $this->fread($socket, min($length, $this->size - $this->bytesRead));

		if ($data === '' && feof($socket)) {
			throw $this->incomplete();
		}

		$this->bytesRead += strlen($data);
		$this->finished = $this->bytesRead === $this->size;

		return $data;
	}

	#[Override]
	public function getContents(): string
	{
		$socket = $this->getSocket();

		if ($this->finished) {
			return '';
		}

		if ($this->chunked) {
			$contents = '';

			while (!$this->finished) {
				$contents .= $this->readChunked($socket, $this->bufferSize);
			}

			return $contents;
		}

		$remaining = $this->size === null ? null : $this->size - $this->bytesRead;
		$contents = stream_get_contents($socket, $remaining);

		if ($contents === false) {
			throw new RuntimeException('Unable to read from stream');
		}

		$this->assertNotTimedOut($socket);
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
		if ($this->socket === null) {
			return $key === null ? [] : null;
		}

		$meta = stream_get_meta_data($this->socket);

		return $key === null ? $meta : ($meta[$key] ?? null);
	}

	/**
	 * @param resource $socket
	 */
	private function readChunked($socket, int $length): string
	{
		if ($this->chunkRemaining === 0) {
			$this->chunkRemaining = $this->readChunkSize($socket);

			if ($this->chunkRemaining === 0) {
				// the trailer section is discarded (RFC 9112 §7.1.2)
				do {
					$trailer = $this->readLine($socket);
				} while ($trailer !== '');

				$this->finished = true;

				return '';
			}
		}

		$data = $this->fread($socket, min($length, $this->chunkRemaining));

		if ($data === '' && feof($socket)) {
			throw $this->incomplete();
		}

		$this->bytesRead += strlen($data);
		$this->chunkRemaining -= strlen($data);

		if ($this->chunkRemaining === 0 && $this->readLine($socket) !== '') {
			throw new RuntimeException('Invalid chunked transfer coding: missing CRLF after chunk data');
		}

		return $data;
	}

	/**
	 * @param resource $socket
	 */
	private function readChunkSize($socket): int
	{
		$line = $this->readLine($socket);

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

	/**
	 * @param resource $socket
	 */
	private function readLine($socket): string
	{
		$line = fgets($socket, self::MAX_LINE_LENGTH);

		if ($line !== false && str_ends_with($line, "\n")) {
			return rtrim($line, "\r\n");
		}

		$this->assertNotTimedOut($socket);

		if ($line === false || feof($socket)) {
			throw $this->incomplete();
		}

		throw new RuntimeException('Invalid chunked transfer coding: line too long');
	}

	/**
	 * @param resource $socket
	 */
	private function fread($socket, int $length): string
	{
		$data = fread($socket, $length);

		if ($data === false) {
			throw new RuntimeException('Unable to read from stream');
		}

		if ($data === '') {
			$this->assertNotTimedOut($socket);
		}

		return $data;
	}

	/**
	 * @param resource $socket
	 */
	private function assertNotTimedOut($socket): void
	{
		if (stream_get_meta_data($socket)['timed_out'] ?? false) {
			throw new RuntimeException('Timed out while reading the response body');
		}
	}

	private function incomplete(): RuntimeException
	{
		return new RuntimeException('Incomplete response body, the connection was closed prematurely');
	}

	/**
	 * @return resource
	 */
	private function getSocket()
	{
		if ($this->socket === null) {
			throw new RuntimeException('Stream is detached or closed');
		}

		return $this->socket;
	}
}
