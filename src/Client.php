<?php

declare(strict_types=1);

namespace Semperton\Proxy;

use InvalidArgumentException;
use Override;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Semperton\Proxy\Exception\NetworkException;
use Semperton\Proxy\Exception\RequestException;
use Throwable;

use function array_map;
use function array_slice;
use function array_unique;
use function count;
use function ctype_digit;
use function end;
use function error_clear_last;
use function error_get_last;
use function explode;
use function fclose;
use function feof;
use function fgets;
use function filter_var;
use function fmod;
use function fopen;
use function ftell;
use function fwrite;
use function implode;
use function in_array;
use function ltrim;
use function parse_url;
use function preg_match;
use function reset;
use function rewind;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function strlen;
use function strpbrk;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const FILTER_VALIDATE_INT;
use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_ANY_CLIENT;

final class Client implements ClientInterface
{
	private const USER_AGENT = 'SempertonProxy/1.0 (+https://github.com/semperton/proxy)';

	private const MAX_LINE_LENGTH = 65536;

	private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

	/** Methods whose semantics anticipate request content (RFC 9110 §8.6) */
	private const CONTENT_METHODS = ['POST', 'PUT', 'PATCH'];

	/**
	 * @param float $timeout Connect and read/write timeout in seconds
	 * @param array<string, array<string, mixed>> $contextOptions Stream context options, see https://www.php.net/manual/en/context.php
	 * @param int $bufferSize Number of bytes to read/write at once
	 */
	public function __construct(
		private readonly ResponseFactoryInterface $responseFactory,
		private readonly float $timeout = 10,
		private readonly array $contextOptions = [],
		private readonly int $bufferSize = 8192,
	) {
		if ($timeout <= 0) {
			throw new InvalidArgumentException('Timeout must be greater than zero');
		}

		if ($bufferSize < 1) {
			throw new InvalidArgumentException('Buffer size must be greater than zero');
		}
	}

	#[Override]
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$request = $this->prepareRequest($request);
		$socket = $this->connect($request);

		try {
			$this->writeRequest($socket, $request);

			return $this->readResponse($socket, $request);
		} catch (ClientExceptionInterface $e) {
			fclose($socket);
			throw $e;
		} catch (Throwable $e) {
			fclose($socket);
			throw new NetworkException($request, $e->getMessage(), 0, $e);
		}
	}

	private function prepareRequest(RequestInterface $request): RequestInterface
	{
		$version = $request->getProtocolVersion();

		if ($version !== '1.1' && $version !== '1.0') {
			throw new RequestException($request, "HTTP/$version is not supported");
		}

		$uri = $request->getUri();
		$scheme = $uri->getScheme();

		if ($scheme !== '' && !isset(self::DEFAULT_PORTS[$scheme])) {
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

		try {
			return $this->frameBody($request);
		} catch (RequestException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new RequestException($request, 'Unable to read the request body: ' . $e->getMessage(), 0, $e);
		}
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
				throw new RequestException($request, 'Content-Length does not match the size of the request body');
			}

			return $request;
		}

		// a client must not send chunked requests without knowing that the server supports HTTP/1.1,
		// so bodies of unknown size are buffered to determine their length
		if ($size === null) {
			$body = $this->bufferBody($body);
			$size = (int) $body->getSize();
			$request = $request->withBody($body);
		}

		if ($size > 0 || in_array($request->getMethod(), self::CONTENT_METHODS, true)) {
			return $request->withHeader('Content-Length', (string) $size);
		}

		return $request;
	}

	private function bufferBody(StreamInterface $body): StreamInterface
	{
		$buffer = fopen('php://temp', 'r+b');

		if ($buffer === false) {
			throw new RuntimeException('Unable to create a buffer');
		}

		if ($body->isSeekable()) {
			$body->rewind();
		}

		while (!$body->eof()) {
			fwrite($buffer, $body->read($this->bufferSize));
		}

		$size = (int) ftell($buffer);
		rewind($buffer);

		return new Stream($buffer, $size, false, $this->bufferSize);
	}

	/**
	 * @return resource
	 */
	private function connect(RequestInterface $request)
	{
		$address = $this->getRemoteAddress($request);
		$context = stream_context_create($this->contextOptions);
		$errorCode = 0;
		$errorMessage = '';

		$socket = @stream_socket_client($address, $errorCode, $errorMessage, $this->timeout, STREAM_CLIENT_CONNECT, $context);

		if ($socket === false) {
			throw new NetworkException($request, "Unable to connect to $address: $errorMessage", $errorCode);
		}

		stream_set_timeout($socket, (int) $this->timeout, (int) (fmod($this->timeout, 1.0) * 1e6));

		if ($request->getUri()->getScheme() === 'https') {
			$cryptoMethod = (int) ($this->contextOptions['ssl']['crypto_method'] ?? STREAM_CRYPTO_METHOD_ANY_CLIENT);

			error_clear_last();

			if (@stream_socket_enable_crypto($socket, true, $cryptoMethod) !== true) {
				$error = error_get_last()['message'] ?? 'unknown error';
				fclose($socket);

				throw new NetworkException($request, "Unable to establish a TLS connection to $address: $error");
			}
		}

		return $socket;
	}

	private function getRemoteAddress(RequestInterface $request): string
	{
		$uri = $request->getUri();
		$host = $uri->getHost();
		$port = $uri->getPort();

		// a request without URI authority is routed by its Host header
		if ($host === '') {
			$authority = parse_url('//' . $request->getHeaderLine('Host'));
			$host = $authority['host'] ?? '';
			$port = $authority['port'] ?? null;
		}

		if ($host === '') {
			throw new RequestException($request, 'Unable to determine the host of the request');
		}

		$port ??= self::DEFAULT_PORTS[$uri->getScheme() ?: 'http'];

		return "tcp://$host:$port";
	}

	/**
	 * @param resource $socket
	 */
	private function writeRequest($socket, RequestInterface $request): void
	{
		$head = $this->serializeHead($request);
		$body = $request->getBody();
		$size = $body->getSize();
		$chunked = $request->hasHeader('Transfer-Encoding');

		try {
			if ($size === 0) {
				$this->write($socket, $request, $chunked ? $head . "0\r\n\r\n" : $head);
				return;
			}

			if ($body->isSeekable()) {
				$body->rewind();
			}

			// small bodies are sent together with the head in a single write
			if (!$chunked && $size !== null && $size <= $this->bufferSize) {
				$this->write($socket, $request, $head . $body->getContents());
				return;
			}

			$this->write($socket, $request, $head);

			while (!$body->eof()) {
				$data = $body->read($this->bufferSize);

				if ($data !== '') {
					$this->write($socket, $request, $chunked ? sprintf("%x\r\n%s\r\n", strlen($data), $data) : $data);
				}
			}

			if ($chunked) {
				$this->write($socket, $request, "0\r\n\r\n");
			}
		} catch (NetworkException $e) {
			throw $e;
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

	/**
	 * Writes all bytes, fwrite() may only write a part of them.
	 *
	 * @param resource $socket
	 */
	private function write($socket, RequestInterface $request, string $data): void
	{
		$length = strlen($data);
		$written = 0;

		while ($written < $length) {
			error_clear_last();
			$result = @fwrite($socket, $written === 0 ? $data : substr($data, $written));

			if ($result === false || $result === 0) {
				$reason = stream_get_meta_data($socket)['timed_out']
					? 'timed out'
					: (error_get_last()['message'] ?? 'connection closed');

				throw new NetworkException($request, "Unable to send the request: $reason");
			}

			$written += $result;
		}
	}

	/**
	 * @param resource $socket
	 */
	private function readResponse($socket, RequestInterface $request): ResponseInterface
	{
		// interim 1xx responses are skipped (RFC 9110 §15.2)
		do {
			[$version, $status, $reason, $fields] = $this->readHead($socket, $request);
		} while ($status < 200 && $status !== 101);

		if ($status === 101) {
			throw new NetworkException($request, 'Unexpected protocol switch, upgrades are not supported');
		}

		$response = $this->responseFactory
			->createResponse($status, $reason)
			->withProtocolVersion($version);

		foreach ($fields as $name => $values) {
			$response = $response->withHeader($name, $values);
		}

		return $this->attachBody($socket, $request, $response);
	}

	/**
	 * @param resource $socket
	 * @return array{string, int, string, array<string, list<string>>} version, status, reason, fields
	 */
	private function readHead($socket, RequestInterface $request): array
	{
		$statusLine = $this->readLine($socket, $request);

		if (preg_match('#^HTTP/(1\.\d)[ \t]+([1-5]\d\d)(?:[ \t]+(.*))?$#', $statusLine, $match) !== 1) {
			throw new NetworkException($request, 'Received an invalid status line');
		}

		$lines = [];

		while (($line = $this->readLine($socket, $request)) !== '') {
			if ($line[0] !== ' ' && $line[0] !== "\t") {
				$lines[] = $line;
			} elseif ($lines !== []) {
				// obsolete line folding continues the previous line (RFC 9112 §5.2),
				// whitespace before the first field line is ignored (RFC 9112 §2.2)
				$lines[count($lines) - 1] .= ' ' . trim($line, " \t");
			}
		}

		$names = [];
		$fields = [];

		foreach ($lines as $line) {
			$colon = strpos($line, ':');
			$name = $colon === false ? '' : substr($line, 0, $colon);

			// malformed field lines are ignored
			if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9a-zA-Z]+$/', $name) !== 1) {
				continue;
			}

			// bare CRs must not be interpreted (RFC 9112 §2.2)
			$value = str_replace("\r", ' ', trim(substr($line, (int) $colon + 1), " \t"));
			$fields[$names[strtolower($name)] ??= $name][] = $value;
		}

		return [$match[1], (int) $match[2], trim($match[3] ?? ''), $fields];
	}

	/**
	 * @param resource $socket
	 */
	private function readLine($socket, RequestInterface $request): string
	{
		$line = fgets($socket, self::MAX_LINE_LENGTH);

		if ($line !== false && str_ends_with($line, "\n")) {
			return rtrim($line, "\r\n");
		}

		if (stream_get_meta_data($socket)['timed_out']) {
			throw new NetworkException($request, 'Timed out while reading the response');
		}

		if ($line === false || feof($socket)) {
			throw new NetworkException($request, 'Connection closed before the response was received');
		}

		throw new NetworkException($request, 'Response header line exceeds ' . self::MAX_LINE_LENGTH . ' bytes');
	}

	/**
	 * Determines the length of the response body (RFC 9112 §6.3).
	 *
	 * @param resource $socket
	 */
	private function attachBody($socket, RequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$status = $response->getStatusCode();

		if ($request->getMethod() === 'HEAD' || $status === 204 || $status === 304) {
			return $response->withBody(new Stream($socket, 0, false, $this->bufferSize));
		}

		if ($response->hasHeader('Transfer-Encoding')) {
			if ($response->getProtocolVersion() === '1.0') {
				throw new NetworkException($request, 'Received an HTTP/1.0 response with Transfer-Encoding');
			}

			$codings = array_map('trim', explode(',', strtolower($response->getHeaderLine('Transfer-Encoding'))));

			if (end($codings) !== 'chunked') {
				return $response->withBody(new Stream($socket, null, false, $this->bufferSize));
			}

			// the chunked coding is removed while reading, keep the headers consistent (RFC 9112 §7.1.3)
			$codings = array_slice($codings, 0, -1);
			$response = $codings === []
				? $response->withoutHeader('Transfer-Encoding')
				: $response->withHeader('Transfer-Encoding', implode(', ', $codings));

			return $response
				->withoutHeader('Content-Length')
				->withBody(new Stream($socket, null, true, $this->bufferSize));
		}

		if ($response->hasHeader('Content-Length')) {
			$length = $this->parseContentLength($response->getHeaderLine('Content-Length'));

			if ($length === null) {
				throw new NetworkException($request, 'Received an invalid Content-Length');
			}

			return $response
				->withHeader('Content-Length', (string) $length)
				->withBody(new Stream($socket, $length, false, $this->bufferSize));
		}

		return $response->withBody(new Stream($socket, null, false, $this->bufferSize));
	}

	/**
	 * Accepts a single non-negative integer, or a list of identical ones (RFC 9112 §6.3).
	 */
	private function parseContentLength(string $value): ?int
	{
		$values = array_unique(array_map('trim', explode(',', $value)));

		if (count($values) !== 1) {
			return null;
		}

		$value = reset($values);

		if (!ctype_digit($value)) {
			return null;
		}

		// rejects values that would overflow
		$length = filter_var(ltrim($value, '0') ?: '0', FILTER_VALIDATE_INT);

		return $length === false ? null : $length;
	}
}
