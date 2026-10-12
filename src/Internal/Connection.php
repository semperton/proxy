<?php

declare(strict_types=1);

namespace Semperton\Proxy\Internal;

use RuntimeException;

use function error_clear_last;
use function error_get_last;
use function fclose;
use function feof;
use function fmod;
use function fread;
use function fwrite;
use function hrtime;
use function is_resource;
use function max;
use function min;
use function rtrim;
use function str_contains;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function strlen;
use function strpos;
use function substr;

use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_ANY_CLIENT;

/**
 * Blocking stream with timeouts, used for a single request/response exchange.
 *
 * Every read is a single fread() call with its own timeout, so that an optional deadline holds
 * even against a server that sends data very slowly. Data is read ahead into a buffer to find
 * line endings, which means a detached resource does not include data that was already buffered.
 *
 * @internal
 */
final class Connection
{
	private const CHUNK_SIZE = 8192;

	/** @var resource|null */
	private $resource;

	private string $buffer = '';

	/** Whether the timeout of the current operation is limited by the deadline */
	private bool $deadlineArmed = false;

	/**
	 * @param resource $resource
	 * @param float|null $timeout Idle timeout in seconds, null to keep the stream's timeout
	 * @param float|null $deadline Point in time (see now()) after which the connection may not be used
	 */
	private function __construct(
		$resource,
		private readonly ?float $timeout,
		private readonly ?float $deadline,
	) {
		$this->resource = $resource;
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * @param string $address Socket address, e.g. "tcp://example.com:80" or "unix:///path/to/socket"
	 * @param array<string, array<string, mixed>> $contextOptions
	 */
	public static function open(string $address, bool $tls, float $timeout, ?float $maxDuration, array $contextOptions): self
	{
		// the deadline starts before connecting, so name resolution and the TCP handshake count towards it
		$deadline = self::deadline($maxDuration);
		$connectTimeout = $maxDuration === null ? $timeout : min($timeout, $maxDuration);
		$context = stream_context_create($contextOptions);
		$errorCode = 0;
		$errorMessage = '';

		$resource = @stream_socket_client($address, $errorCode, $errorMessage, $connectTimeout, STREAM_CLIENT_CONNECT, $context);

		if ($resource === false) {
			throw new RuntimeException("Unable to connect to $address: $errorMessage", $errorCode);
		}

		$connection = new self($resource, $timeout, $deadline);

		if ($tls) {
			$connection->enableTls($address, (int) ($contextOptions['ssl']['crypto_method'] ?? STREAM_CRYPTO_METHOD_ANY_CLIENT));
		}

		return $connection;
	}

	/**
	 * @param resource $resource
	 */
	public static function wrap($resource, ?float $timeout = null, ?float $maxDuration = null): self
	{
		return new self($resource, $timeout, self::deadline($maxDuration));
	}

	/**
	 * Writes all bytes, fwrite() may only write a part of them.
	 */
	public function write(string $data): void
	{
		$resource = $this->resource();
		$length = strlen($data);
		$written = 0;

		while ($written < $length) {
			$this->armTimeout($resource);
			error_clear_last();
			$result = @fwrite($resource, $written === 0 ? $data : substr($data, $written));

			if ($result === false || $result === 0) {
				$this->assertNotTimedOut($resource);

				throw new RuntimeException('Unable to write: ' . (error_get_last()['message'] ?? 'connection closed'));
			}

			$written += $result;
		}
	}

	/**
	 * Reads up to $length bytes, returns an empty string only at the end of the stream.
	 */
	public function read(int $length): string
	{
		if ($length < 1) {
			return '';
		}

		if ($this->buffer === '') {
			return $this->fread($length);
		}

		$data = substr($this->buffer, 0, $length);
		$this->buffer = substr($this->buffer, strlen($data));

		return $data;
	}

	/**
	 * Reads $length bytes, or until the end of the stream if null.
	 */
	public function readAll(?int $length = null): string
	{
		$data = $this->read($length ?? self::CHUNK_SIZE);

		while ($data !== '' && ($length === null || strlen($data) < $length)) {
			$chunk = $this->fread($length === null ? self::CHUNK_SIZE : min(self::CHUNK_SIZE, $length - strlen($data)));

			if ($chunk === '') {
				break;
			}

			$data .= $chunk;
		}

		return $data;
	}

	/**
	 * Reads a line of at most $maxLength bytes and strips its terminator,
	 * a single LF is accepted as terminator (RFC 9112 §2.2).
	 */
	public function readLine(int $maxLength): string
	{
		$end = strpos($this->buffer, "\n");

		while ($end === false) {
			$searched = strlen($this->buffer);

			if ($searched >= $maxLength) {
				throw new RuntimeException("Line exceeds $maxLength bytes");
			}

			$data = $this->fread(self::CHUNK_SIZE);

			if ($data === '') {
				throw new RuntimeException('Connection closed prematurely');
			}

			$this->buffer .= $data;
			$end = strpos($this->buffer, "\n", $searched);
		}

		if ($end >= $maxLength) {
			throw new RuntimeException("Line exceeds $maxLength bytes");
		}

		$line = substr($this->buffer, 0, $end);
		$this->buffer = substr($this->buffer, $end + 1);

		return rtrim($line, "\r");
	}

	public function eof(): bool
	{
		return $this->buffer === '' && feof($this->resource());
	}

	/**
	 * @return array<string, mixed>
	 */
	public function metadata(): array
	{
		return stream_get_meta_data($this->resource());
	}

	/**
	 * @return resource|null
	 */
	public function detach()
	{
		$resource = $this->resource;
		$this->resource = null;
		$this->buffer = '';

		return $resource;
	}

	public function close(): void
	{
		$resource = $this->detach();

		if (is_resource($resource)) {
			fclose($resource);
		}
	}

	private static function now(): float
	{
		return (float) hrtime(true) / 1e9;
	}

	private static function deadline(?float $maxDuration): ?float
	{
		return $maxDuration === null ? null : self::now() + $maxDuration;
	}

	private function enableTls(string $address, int $cryptoMethod): void
	{
		$resource = $this->resource();
		$this->armTimeout($resource);
		error_clear_last();

		if (@stream_socket_enable_crypto($resource, true, $cryptoMethod) !== true) {
			$error = error_get_last()['message'] ?? 'unknown error';
			$this->close();

			// a handshake timeout is only reported in the error message, not in the stream metadata
			if (str_contains($error, 'timed out')) {
				throw $this->timeout();
			}

			throw new RuntimeException("Unable to establish a TLS connection to $address: $error");
		}
	}

	private function fread(int $length): string
	{
		$resource = $this->resource();
		$this->armTimeout($resource);
		$data = fread($resource, $length);

		if ($data === false || $data === '') {
			$this->assertNotTimedOut($resource);
		}

		if ($data === false) {
			throw new RuntimeException('Unable to read');
		}

		return $data;
	}

	/**
	 * Limits the next blocking operation to the idle timeout and the remaining time.
	 *
	 * @param resource $resource
	 */
	private function armTimeout($resource): void
	{
		$timeout = $this->timeout;

		if ($this->deadline !== null) {
			$remaining = $this->deadline - self::now();

			if ($remaining <= 0) {
				throw new RuntimeException('Request exceeded the maximum duration');
			}

			$this->deadlineArmed = $timeout === null || $remaining <= $timeout;
			$timeout = $this->deadlineArmed ? $remaining : $timeout;
		}

		if ($timeout !== null) {
			// a zero timeout would mean "do not wait at all"
			$timeout = max($timeout, 0.000001);
			stream_set_timeout($resource, (int) $timeout, (int) (fmod($timeout, 1.0) * 1e6));
		}
	}

	/**
	 * @param resource $resource
	 */
	private function assertNotTimedOut($resource): void
	{
		if (stream_get_meta_data($resource)['timed_out'] ?? false) {
			throw $this->timeout();
		}
	}

	private function timeout(): RuntimeException
	{
		return new RuntimeException($this->deadlineArmed ? 'Request exceeded the maximum duration' : 'Connection timed out');
	}

	/**
	 * @return resource
	 */
	private function resource()
	{
		if ($this->resource === null) {
			throw new RuntimeException('Connection is closed');
		}

		return $this->resource;
	}
}
