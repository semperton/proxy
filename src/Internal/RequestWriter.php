<?php

declare(strict_types=1);

namespace Semperton\Proxy\Internal;

use Generator;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Semperton\Proxy\Exception\RequestException;
use Throwable;

use function ctype_digit;
use function end;
use function explode;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fwrite;
use function implode;
use function in_array;
use function preg_match;
use function rewind;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function strtolower;
use function trim;

/**
 * Prepares and sends a request (RFC 9112 §3, §6).
 *
 * @internal
 */
final class RequestWriter
{
	private const USER_AGENT = 'SempertonProxy/1.0 (+https://github.com/semperton/proxy)';

	private const SCHEMES = ['http' => true, 'https' => true];

	/** Methods whose semantics anticipate request content (RFC 9110 §8.6) */
	private const CONTENT_METHODS = ['POST', 'PUT', 'PATCH'];

	public function __construct(
		private readonly int $bufferSize,
	) {}

	/**
	 * Validates the request and adds the fields needed to send it, without any I/O.
	 */
	public function prepare(RequestInterface $request): RequestInterface
	{
		$version = $request->getProtocolVersion();

		if ($version !== '1.1' && $version !== '1.0') {
			throw new RequestException($request, "HTTP/$version is not supported");
		}

		$uri = $request->getUri();
		$scheme = $uri->getScheme();

		if ($scheme !== '' && !isset(self::SCHEMES[$scheme])) {
			throw new RequestException($request, "Scheme \"$scheme\" is not supported");
		}

		$method = $request->getMethod();
		$target = $request->getRequestTarget();

		// origin-form, absolute-form or asterisk-form (RFC 9112 §3.2), no tunnels
		$validTarget = str_starts_with($target, '/')
			|| ($target === '*' && $method === 'OPTIONS')
			|| preg_match('#^https?://#i', $target) === 1;

		if (!$validTarget || $method === 'CONNECT') {
			throw new RequestException($request, "Request target \"$target\" is not supported");
		}

		if ($request->hasHeader('Upgrade')) {
			throw new RequestException($request, 'Protocol upgrades are not supported');
		}

		if (!$request->hasHeader('Host')) {
			if ($uri->getHost() === '') {
				throw new RequestException($request, 'Unable to determine the host of the request');
			}

			$port = $uri->getPort();
			$request = $request->withHeader('Host', $uri->getHost() . ($port === null ? '' : ":$port"));
		}

		if (!$request->hasHeader('User-Agent')) {
			$request = $request->withHeader('User-Agent', self::USER_AGENT);
		}

		// persistent connections are not supported (RFC 9112 §9.3)
		$request = $request->withHeader('Connection', 'close');

		return $this->frameBody($request);
	}

	/**
	 * Sends the request head and body.
	 */
	public function write(Connection $connection, RequestInterface $request): void
	{
		$chunks = $this->readBody($request);
		$chunked = $request->hasHeader('Transfer-Encoding');

		// a client must not send chunked requests without knowing that the server supports HTTP/1.1
		// (RFC 9112 §6.3), so bodies of unknown size are buffered to determine their length
		if (!$chunked && $request->getBody()->getSize() === null && !$request->hasHeader('Content-Length')) {
			[$chunks, $size] = $this->bufferBody($chunks);
			$request = $this->withContentLength($request, $size);
		}

		$buffer = $this->serializeHead($request);
		$length = $chunked ? null : (int) $request->getHeaderLine('Content-Length');
		$written = 0;

		// output is coalesced, so the head and a small body are sent in a single write
		foreach ($chunks as $data) {
			$written += strlen($data);

			// a body that does not match its Content-Length would corrupt the message framing
			if ($length !== null && $written > $length) {
				throw $this->lengthMismatch($request);
			}

			$buffer .= $chunked ? sprintf("%x\r\n%s\r\n", strlen($data), $data) : $data;

			if (strlen($buffer) >= $this->bufferSize) {
				$connection->write($buffer);
				$buffer = '';
			}
		}

		if ($length !== null && $written < $length) {
			throw $this->lengthMismatch($request);
		}

		$connection->write($chunked ? $buffer . "0\r\n\r\n" : $buffer);
	}

	private function lengthMismatch(RequestInterface $request): RequestException
	{
		return new RequestException($request, 'Content-Length does not match the size of the request body');
	}

	/**
	 * Ensures the request content is delimited (RFC 9112 §6.3).
	 */
	private function frameBody(RequestInterface $request): RequestInterface
	{
		$body = $request->getBody();
		$size = $body->getSize();

		if (!$body->isReadable() && $size !== 0) {
			throw new RequestException($request, 'Request body is not readable');
		}

		// the caller explicitly opted into chunked transfer coding
		if ($request->hasHeader('Transfer-Encoding')) {
			$codings = explode(',', $request->getHeaderLine('Transfer-Encoding'));

			if (strtolower(trim(end($codings))) !== 'chunked') {
				throw new RequestException($request, 'Transfer-Encoding must end with "chunked"');
			}

			if ($request->getProtocolVersion() === '1.0') {
				throw new RequestException($request, 'Transfer-Encoding requires HTTP/1.1');
			}

			return $request->withoutHeader('Content-Length');
		}

		if ($request->hasHeader('Content-Length')) {
			$length = $request->getHeaderLine('Content-Length');

			if (!ctype_digit($length) || ($size !== null && (int) $length !== $size)) {
				throw $this->lengthMismatch($request);
			}

			return $request;
		}

		// bodies of unknown size get their Content-Length when they are buffered
		return $size === null ? $request : $this->withContentLength($request, $size);
	}

	private function withContentLength(RequestInterface $request, int $size): RequestInterface
	{
		if ($size > 0 || in_array($request->getMethod(), self::CONTENT_METHODS, true)) {
			return $request->withHeader('Content-Length', (string) $size);
		}

		return $request;
	}

	/**
	 * @param iterable<string> $chunks
	 * @return array{Generator<int, string>, int} buffered chunks, size
	 */
	private function bufferBody(iterable $chunks): array
	{
		$buffer = fopen('php://temp', 'r+b');

		if ($buffer === false) {
			throw new RuntimeException('Unable to create a buffer for the request body');
		}

		$size = 0;

		foreach ($chunks as $data) {
			fwrite($buffer, $data);
			$size += strlen($data);
		}

		rewind($buffer);

		return [$this->readBuffer($buffer), $size];
	}

	/**
	 * @param resource $buffer
	 * @return Generator<int, string>
	 */
	private function readBuffer($buffer): Generator
	{
		try {
			while (!feof($buffer)) {
				$data = (string) fread($buffer, $this->bufferSize);

				if ($data !== '') {
					yield $data;
				}
			}
		} finally {
			fclose($buffer);
		}
	}

	/**
	 * @return Generator<int, string>
	 */
	private function readBody(RequestInterface $request): Generator
	{
		$body = $request->getBody();

		if ($body->getSize() === 0) {
			return;
		}

		try {
			if ($body->isSeekable()) {
				$body->rewind();
			}

			while (!$body->eof()) {
				$data = $body->read($this->bufferSize);

				if ($data !== '') {
					yield $data;
				}
			}
		} catch (Throwable $e) {
			throw new RequestException($request, 'Unable to read the request body: ' . $e->getMessage(), 0, $e);
		}
	}

	private function serializeHead(RequestInterface $request): string
	{
		$requestLine = $request->getMethod() . ' ' . $request->getRequestTarget() . ' HTTP/' . $request->getProtocolVersion();

		// Host should be the first field (RFC 9110 §7.2)
		$lines = [$requestLine, 'Host: ' . $request->getHeaderLine('Host')];

		foreach ($request->getHeaders() as $name => $values) {
			$name = (string) $name;
			$normalized = strtolower($name);

			if ($normalized !== 'host') {
				// multiple cookies are joined by semicolons (RFC 6265 §5.4)
				$lines[] = $name . ': ' . implode($normalized === 'cookie' ? '; ' : ', ', $values);
			}
		}

		foreach ($lines as $line) {
			if (strpbrk($line, "\r\n\0") !== false) {
				throw new RequestException($request, 'Request contains invalid characters');
			}
		}

		return implode("\r\n", $lines) . "\r\n\r\n";
	}
}
