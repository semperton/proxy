<?php

declare(strict_types=1);

namespace Semperton\Proxy\Internal;

use RuntimeException;

use function error_clear_last;
use function error_get_last;
use function fclose;
use function feof;
use function fgets;
use function fmod;
use function fread;
use function fwrite;
use function is_resource;
use function rtrim;
use function str_ends_with;
use function stream_context_create;
use function stream_get_contents;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function strlen;
use function substr;

use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_ANY_CLIENT;

/**
 * Blocking stream with timeouts, used for a single request/response exchange.
 *
 * @internal
 */
final class Connection
{
	/** @var resource|null */
	private $resource;

	/**
	 * @param resource $resource
	 */
	private function __construct($resource)
	{
		$this->resource = $resource;
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * @param array<string, array<string, mixed>> $contextOptions
	 */
	public static function open(string $host, int $port, bool $tls, float $timeout, array $contextOptions): self
	{
		$address = "tcp://$host:$port";
		$context = stream_context_create($contextOptions);
		$errorCode = 0;
		$errorMessage = '';

		$resource = @stream_socket_client($address, $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT, $context);

		if ($resource === false) {
			throw new RuntimeException("Unable to connect to $address: $errorMessage", $errorCode);
		}

		stream_set_timeout($resource, (int) $timeout, (int) (fmod($timeout, 1.0) * 1e6));
		$connection = new self($resource);

		if ($tls) {
			$cryptoMethod = (int) ($contextOptions['ssl']['crypto_method'] ?? STREAM_CRYPTO_METHOD_ANY_CLIENT);

			error_clear_last();

			if (@stream_socket_enable_crypto($resource, true, $cryptoMethod) !== true) {
				$connection->close();
				$error = error_get_last()['message'] ?? 'unknown error';

				throw new RuntimeException("Unable to establish a TLS connection to $address: $error");
			}
		}

		return $connection;
	}

	/**
	 * @param resource $resource
	 */
	public static function wrap($resource): self
	{
		return new self($resource);
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
		$resource = $this->resource();
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
	 * Reads $length bytes, or until the end of the stream if null.
	 */
	public function readAll(?int $length = null): string
	{
		$resource = $this->resource();
		$data = stream_get_contents($resource, $length);
		$this->assertNotTimedOut($resource);

		if ($data === false) {
			throw new RuntimeException('Unable to read');
		}

		return $data;
	}

	/**
	 * Reads a line of at most $maxLength bytes and strips its terminator,
	 * a single LF is accepted as terminator (RFC 9112 §2.2).
	 */
	public function readLine(int $maxLength): string
	{
		$resource = $this->resource();
		$line = fgets($resource, $maxLength + 1);

		if ($line !== false && str_ends_with($line, "\n")) {
			return rtrim($line, "\r\n");
		}

		$this->assertNotTimedOut($resource);

		if ($line === false || feof($resource)) {
			throw new RuntimeException('Connection closed prematurely');
		}

		throw new RuntimeException("Line exceeds $maxLength bytes");
	}

	public function eof(): bool
	{
		return feof($this->resource());
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

		return $resource;
	}

	public function close(): void
	{
		$resource = $this->detach();

		if (is_resource($resource)) {
			fclose($resource);
		}
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

	/**
	 * @param resource $resource
	 */
	private function assertNotTimedOut($resource): void
	{
		if (stream_get_meta_data($resource)['timed_out'] ?? false) {
			throw new RuntimeException('Connection timed out');
		}
	}
}
