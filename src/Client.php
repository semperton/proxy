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
use Semperton\Proxy\Exception\NetworkException;
use Semperton\Proxy\Exception\RequestException;
use Semperton\Proxy\Internal\Connection;
use Semperton\Proxy\Internal\RequestWriter;
use Semperton\Proxy\Internal\ResponseReader;
use Throwable;

use function parse_url;

final class Client implements ClientInterface
{
	private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

	private readonly RequestWriter $writer;

	private readonly ResponseReader $reader;

	/**
	 * @param float $timeout Connect and read/write timeout in seconds
	 * @param array<string, array<string, mixed>> $contextOptions Stream context options, see https://www.php.net/manual/en/context.php
	 * @param int $bufferSize Number of bytes to read/write at once
	 */
	public function __construct(
		ResponseFactoryInterface $responseFactory,
		private readonly float $timeout = 10,
		private readonly array $contextOptions = [],
		int $bufferSize = 8192,
	) {
		if ($timeout <= 0) {
			throw new InvalidArgumentException('Timeout must be greater than zero');
		}

		if ($bufferSize < 1) {
			throw new InvalidArgumentException('Buffer size must be greater than zero');
		}

		$this->writer = new RequestWriter($bufferSize);
		$this->reader = new ResponseReader($responseFactory, $bufferSize);
	}

	#[Override]
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$request = $this->writer->prepare($request);
		$connection = null;

		try {
			[$host, $port, $tls] = $this->getEndpoint($request);
			$connection = Connection::open($host, $port, $tls, $this->timeout, $this->contextOptions);

			$this->writer->write($connection, $request);

			return $this->reader->read($connection, $request);
		} catch (ClientExceptionInterface $e) {
			$connection?->close();
			throw $e;
		} catch (Throwable $e) {
			$connection?->close();
			throw new NetworkException($request, $e->getMessage(), (int) $e->getCode(), $e);
		}
	}

	/**
	 * @return array{string, int, bool} host, port, TLS
	 */
	private function getEndpoint(RequestInterface $request): array
	{
		$uri = $request->getUri();
		$scheme = $uri->getScheme() ?: 'http';
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

		return [$host, $port ?? self::DEFAULT_PORTS[$scheme], $scheme === 'https'];
	}
}
