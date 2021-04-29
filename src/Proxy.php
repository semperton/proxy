<?php

declare(strict_types=1);

namespace Semperton\Proxy;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Semperton\Proxy\Exception\NetworkException;

final class Proxy implements ClientInterface
{
	/** @var ResponseFactoryInterface */
	protected $responseFactory;

	/** @var int */
	protected $bufferSize = 8192;

	/** @var int */
	protected $sslMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;

	/** @var int */
	protected $timeout = 10;

	protected $config = [
		'remote_socket' => null,
		'timeout' => 1000,
		'stream_context_options' => [],
		'stream_context_param' => [],
		'ssl' => null,
		'write_buffer_size' => 8192,
		'ssl_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
	];

	public function __construct(ResponseFactoryInterface $responseFactory, array $options = [])
	{
		$this->responseFactory = $responseFactory;
		$this->config['stream_context'] = stream_context_create(
			$this->config['stream_context_options'],
			$this->config['stream_context_param'],
		);
	}

	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		if (!$request->hasHeader('Connection')) {
			$request = $request->withHeader('Connection', 'close');
		}

		$address = $this->determineRemoteFromRequest($request);

		$useSsl = ('https' === $request->getUri()->getScheme());

		$socket = $this->createSocket($request, $address, $useSsl);

		try {
			$this->writeRequest($socket, $request,  $this->bufferSize);
			$response = $this->readResponse($socket);
		} catch (\Exception $e) {

			fclose($socket);
			throw $e;
		}

		return $response;
	}

	/**
	 * Create the socket to write request and read response on it.
	 *
	 * @param RequestInterface $request Request for
	 * @param string           $remote  Entrypoint for the connection
	 * @param bool             $useSsl  Whether to use ssl or not
	 *
	 * @throws NetworkException When the connection fail
	 *
	 * @return resource Socket resource
	 */
	protected function createSocket(RequestInterface $request, string $remote, bool $useSsl)
	{
		$errNo = null;
		$errMsg = null;
		$socket = @stream_socket_client($remote, $errNo, $errMsg, floor($this->config['timeout'] / 1000), STREAM_CLIENT_CONNECT, $this->config['stream_context']);

		if ($socket === false) {
			throw new NetworkException($request, $errMsg, $errNo);
		}

		$timeS = (int)floor($this->config['timeout'] / 1000);
		$timeM = $this->config['timeout'] % 1000;
		stream_set_timeout($socket, $timeS, $timeM);

		if ($useSsl && false === @stream_socket_enable_crypto($socket, true, $this->config['ssl_method'])) {
			throw new NetworkException($request, sprintf('Cannot enable tls: %s', error_get_last()['message']));
		}

		return $socket;
	}

	/**
	 * Return remote socket from the request.
	 *
	 * @throws RuntimeException When no remote can be determined from the request
	 *
	 * @return string
	 */
	private function determineRemoteFromRequest(RequestInterface $request)
	{
		if (!$request->hasHeader('Host') && '' === $request->getUri()->getHost()) {
			throw new RuntimeException('Remote is not defined and we cannot determine a connection endpoint for this request (no Host header)');
		}

		$host = $request->getUri()->getHost();
		$port = $request->getUri()->getPort() ?: ('https' === $request->getUri()->getScheme() ? 443 : 80);
		$endpoint = sprintf('%s:%s', $host, $port);

		// If use the host header if present for the endpoint
		if (empty($host) && $request->hasHeader('Host')) {
			$endpoint = $request->getHeaderLine('Host');
		}

		return sprintf('tcp://%s', $endpoint);
	}

	/**
	 * Read a response from a socket.
	 *
	 * @param resource $socket
	 *
	 * @throws RuntimeException    When the socket timed out
	 * @throws RuntimeException When the response cannot be read
	 */
	protected function readResponse($socket): ResponseInterface
	{
		$headers = [];
		$reason = null;

		while (false !== ($line = fgets($socket))) {
			if ('' === rtrim($line)) {
				break;
			}
			$headers[] = trim($line);
		}

		$metadatas = stream_get_meta_data($socket);

		if (array_key_exists('timed_out', $metadatas) && true === $metadatas['timed_out']) {
			throw new RuntimeException('Error while reading response, stream timed out');
		}

		$parts = explode(' ', array_shift($headers), 3);

		if (count($parts) <= 1) {
			throw new RuntimeException('Cannot read the response');
		}

		$protocol = substr($parts[0], -3);
		$status = $parts[1];

		if (isset($parts[2])) {
			$reason = $parts[2];
		}

		// Set the size on the stream if it was returned in the response
		$responseHeaders = [];

		foreach ($headers as $header) {
			$headerParts = explode(':', $header, 2);

			if (!array_key_exists(trim($headerParts[0]), $responseHeaders)) {
				$responseHeaders[trim($headerParts[0])] = [];
			}

			$responseHeaders[trim($headerParts[0])][] = isset($headerParts[1])
				? trim($headerParts[1])
				: '';
		}

		$response = $this->responseFactory->createResponse((int)$status, $reason);
		$response = $response->withProtocolVersion($protocol);

		foreach ($responseHeaders as $name => $value) {
			$response = $response->withHeader($name, $value);
		}

		$stream = $this->createStream($socket, $response);

		return $response->withBody($stream);
	}

	/**
	 * Create the stream.
	 *
	 * @param resource $socket
	 */
	protected function createStream($socket, ResponseInterface $response): StreamInterface
	{
		$size = null;

		if ($response->hasHeader('Content-Length')) {
			$size = (int) $response->getHeaderLine('Content-Length');
		}

		return new SocketStream($socket, $size);
	}

	/**
	 * Write a request to a socket.
	 *
	 * @param resource $socket
	 *
	 * @throws RuntimeException
	 */
	protected function writeRequest($socket, RequestInterface $request, int $bufferSize = 8192)
	{
		if (false === $this->fwrite($socket, $this->transformRequestHeadersToString($request))) {
			throw new RuntimeException('Failed to send request, underlying socket not accessible, (BROKEN EPIPE)');
		}

		if ($request->getBody()->isReadable()) {
			$this->writeBody($socket, $request, $bufferSize);
		}
	}

	/**
	 * Write Body of the request.
	 *
	 * @param resource $socket
	 *
	 * @throws RuntimeException
	 */
	protected function writeBody($socket, RequestInterface $request, int $bufferSize = 8192)
	{
		$body = $request->getBody();

		if ($body->isSeekable()) {
			$body->rewind();
		}

		while (!$body->eof()) {
			$buffer = $body->read($bufferSize);

			if (false === $this->fwrite($socket, $buffer)) {
				throw new RuntimeException('An error occur when writing request to client (BROKEN EPIPE)', $request);
			}
		}
	}

	/**
	 * Produce the header of request as a string based on a PSR Request.
	 */
	protected function transformRequestHeadersToString(RequestInterface $request): string
	{
		$message = vsprintf('%s %s HTTP/%s', [
			strtoupper($request->getMethod()),
			$request->getRequestTarget(),
			$request->getProtocolVersion(),
		]) . "\r\n";

		foreach ($request->getHeaders() as $name => $values) {
			$message .= $name . ': ' . implode(', ', $values) . "\r\n";
		}

		$message .= "\r\n";

		return $message;
	}

	/**
	 * Replace fwrite behavior as api is broken in PHP.
	 *
	 * @see https://secure.phabricator.com/rPHU69490c53c9c2ef2002bc2dd4cecfe9a4b080b497
	 *
	 * @param resource $stream The stream resource
	 *
	 * @return bool|int false if pipe is broken, number of bytes written otherwise
	 */
	private function fwrite($stream, string $bytes)
	{
		if (!strlen($bytes)) {
			return 0;
		}
		$result = @fwrite($stream, $bytes);
		if (0 !== $result) {
			// In cases where some bytes are witten (`$result > 0`) or
			// an error occurs (`$result === false`), the behavior of fwrite() is
			// correct. We can return the value as-is.
			return $result;
		}
		// If we make it here, we performed a 0-length write. Try to distinguish
		// between EAGAIN and EPIPE. To do this, we're going to `stream_select()`
		// the stream, write to it again if PHP claims that it's writable, and
		// consider the pipe broken if the write fails.
		$read = [];
		$write = [$stream];
		$except = [];
		@stream_select($read, $write, $except, 0);
		if (!$write) {
			// The stream isn't writable, so we conclude that it probably really is
			// blocked and the underlying error was EAGAIN. Return 0 to indicate that
			// no data could be written yet.
			return 0;
		}
		// If we make it here, PHP **just** claimed that this stream is writable, so
		// perform a write. If the write also fails, conclude that these failures are
		// EPIPE or some other permanent failure.
		$result = @fwrite($stream, $bytes);
		if (0 !== $result) {
			// The write worked or failed explicitly. This value is fine to return.
			return $result;
		}
		// We performed a 0-length write, were told that the stream was writable, and
		// then immediately performed another 0-length write. Conclude that the pipe
		// is broken and return `false`.
		return false;
	}
}
