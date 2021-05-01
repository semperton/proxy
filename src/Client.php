<?php

declare(strict_types=1);

namespace Semperton\Proxy;

use Exception;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Semperton\Proxy\Exception\NetworkException;
use Semperton\Proxy\Exception\RequestException;

final class Client implements ClientInterface
{
	/** @var string */
	protected $userAgent = 'SempertonProxy/1.0 (+https://github.com/semperton/proxy)';

	/** @var int */
	protected $sslMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;

	/** @var int */
	protected $bufferSize = 4096;

	/** @var ResponseFactoryInterface */
	protected $responseFactory;

	/** @var int */
	protected $timeout;

	/** @var array */
	protected $contextOptions;

	public function __construct(
		ResponseFactoryInterface $responseFactory,
		int $timeout = 10,
		array $contextOptions = []
	) {
		$this->responseFactory = $responseFactory;
		$this->timeout = $timeout;
		$this->contextOptions = $contextOptions;
	}

	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		// keep-alive not supported for now
		$request = $request->withHeader('Connection', 'close');

		if (!$request->hasHeader('User-Agent')) {
			$request = $request->withHeader('User-Agent', $this->userAgent);
		}

		$address = $this->getRemoteAddress($request);
		$socket = $this->createSocket($request, $address);

		try {
			$this->writeRequest($socket, $request);
			$response = $this->readResponse($request, $socket);
		} catch (Exception $e) {

			fclose($socket);
			throw $e;
		}

		return $response;
	}

	/**
	 * @return resource
	 */
	protected function createSocket(RequestInterface $request, string $remote)
	{
		$errNo = null;
		$errMsg = null;

		$options = stream_context_create($this->contextOptions);
		$socket = @stream_socket_client($remote, $errNo, $errMsg, $this->timeout, STREAM_CLIENT_CONNECT, $options);

		if ($socket === false) {
			throw new NetworkException($request, $errMsg, $errNo);
		}

		stream_set_timeout($socket, $this->timeout);

		if ($request->getUri()->getScheme() === 'https') {

			if (false === @stream_socket_enable_crypto($socket, true, $this->sslMethod)) {
				$error = error_get_last();
				throw new NetworkException($request, 'Cannot enable tls: ' . (isset($error) ? $error['message'] : ''));
			}
		}

		return $socket;
	}

	protected function getRemoteAddress(RequestInterface $request): string
	{
		$host = $request->getUri()->getHost();

		if ($host === '' && !$request->hasHeader('Host')) {
			throw new RequestException($request, 'Unable to determine host of request');
		}

		if (!empty($host)) {
			$port = $request->getUri()->getPort() ?: ($request->getUri()->getScheme() === 'https' ? 443 : 80);
			$host .= ":$port";
		} else {
			$host = $request->getHeaderLine('Host');
		}

		return "tcp://$host";
	}

	/**
	 * @param resource $socket
	 */
	protected function readResponse(RequestInterface $request, $socket): ResponseInterface
	{
		$headers = [];

		while (($line = fgets($socket)) !== false) {
			$line = trim($line);
			if ($line === '') {
				break;
			}
			$headers[] = $line;
		}

		if (empty($headers)) {
			throw new NetworkException($request, 'Cannot read the response, no headers');
		}

		$meta = stream_get_meta_data($socket);

		if (isset($meta['timed_out']) && $meta['timed_out'] === true) {
			throw new NetworkException($request, 'Error while reading response, stream timed out');
		}

		$parts = explode(' ', array_shift($headers), 3);

		if (!isset($parts[1])) {
			throw new NetworkException($request, 'Cannot read the response');
		}

		$protocol = substr($parts[0], -3);
		$status = $parts[1];
		$reason = $parts[2] ?? '';

		$response = $this->responseFactory->createResponse((int)$status, $reason);
		$response = $response->withProtocolVersion($protocol);

		foreach ($headers as $header) {

			$parts = explode(':', $header, 2);

			$name = trim($parts[0]);
			$value = isset($parts[1]) ? trim($parts[1]) : '';

			$response = $response->withAddedHeader($name, $value);
		}

		$stream = $this->createStream($socket, $response);

		return $response->withBody($stream);
	}

	/**
	 * @param resource $socket
	 */
	protected function createStream($socket, ResponseInterface $response): StreamInterface
	{
		$size = null;

		if ($response->hasHeader('Content-Length')) {
			$size = (int)$response->getHeaderLine('Content-Length');
		}

		return new Stream($socket, $size);
	}

	/**
	 * @param resource $socket
	 */
	protected function writeRequest($socket, RequestInterface $request): void
	{
		$size = $request->getBody()->getSize();

		if ($size !== null && $size > 0 && !$request->hasHeader('Content-Length')) {
			$request = $request->withHeader('Content-Length', (string)$size);
		}

		// if ($request->getBody()->isReadable() && !$request->hasHeader('Content-Length')) {
		// 	$request = $request->withHeader('Transfer-Encoding', 'chunked');
		// }

		$headers = $this->getRequestHeaders($request);

		if (false === $this->fwrite($socket, $headers)) {
			throw new NetworkException($request, 'Failed to send request, could not write headers to socket');
		}

		if ($request->getBody()->isReadable()) {
			$this->writeBody($socket, $request);
		}
	}

	/**
	 * @param resource $socket
	 */
	protected function writeBody($socket, RequestInterface $request): void
	{
		$body = $request->getBody();

		if ($body->isSeekable()) {
			$body->rewind();
		}

		while (!$body->eof()) {

			$buffer = $body->read($this->bufferSize);

			if (false === $this->fwrite($socket, $buffer)) {
				throw new NetworkException($request, 'Unable to write request body to socket');
			}
		}
	}

	protected function getRequestHeaders(RequestInterface $request): string
	{
		$method = $request->getMethod();
		$target = $request->getRequestTarget();
		$protocol = $request->getProtocolVersion();

		$message = "$method $target HTTP/$protocol\r\n";

		$headers = $request->getHeaders();

		foreach ($headers as $name => $values) {
			$message .= $name . ': ' . implode(', ', $values) . "\r\n";
		}

		$message .= "\r\n";

		return $message;
	}

	/**
	 * @see https://secure.phabricator.com/rPHU69490c53c9c2ef2002bc2dd4cecfe9a4b080b497
	 * @param resource $stream
	 * @return bool|int
	 */
	protected function fwrite($stream, string $bytes)
	{
		if (!strlen($bytes)) {
			return 0;
		}

		$result = @fwrite($stream, $bytes);

		if ($result !== 0) {
			return $result;
		}

		$write = [$stream];
		@stream_select([], $write, [], 0);

		if (!$write) {
			return 0;
		}

		$result = @fwrite($stream, $bytes);

		if ($result !== 0) {
			return $result;
		}

		return false;
	}
}
