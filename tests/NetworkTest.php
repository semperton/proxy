<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests;

use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\ResponseFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Semperton\Proxy\Client;
use Semperton\Proxy\Exception\NetworkException;

use function explode;
use function json_decode;
use function trim;

/**
 * Runs against a public service, use `vendor/bin/phpunit --group network` to run.
 */
#[Group('network')]
final class NetworkTest extends TestCase
{
	private Client $client;

	private RequestFactory $requestFactory;

	protected function setUp(): void
	{
		$this->client = new Client(new ResponseFactory());
		$this->requestFactory = new RequestFactory();
	}

	public function testPost(): void
	{
		$request = $this->requestFactory->createRequest('POST', 'https://httpbin.org/anything');
		$request->getBody()->write('Hello World');

		$response = $this->send($request);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

		/** @var array{data: string} $body */
		$body = json_decode($response->getBody()->getContents(), true);
		$this->assertSame('Hello World', $body['data']);
	}

	public function testChunkedStream(): void
	{
		$request = $this->requestFactory->createRequest('GET', 'https://httpbin.org/stream/5');

		$response = $this->send($request);
		$this->assertSame(200, $response->getStatusCode());
		$lines = explode("\n", trim($response->getBody()->getContents()));

		$this->assertFalse($response->hasHeader('Transfer-Encoding'));
		$this->assertCount(5, $lines);

		foreach ($lines as $line) {
			$this->assertIsArray(json_decode($line, true));
		}
	}

	public function testErrorStatusIsNotAnException(): void
	{
		$request = $this->requestFactory->createRequest('GET', 'https://httpbin.org/status/503');

		try {
			$response = $this->client->sendRequest($request);
		} catch (NetworkException $e) {
			$this->markTestSkipped('httpbin.org is unavailable: ' . $e->getMessage());
		}

		$this->assertSame(503, $response->getStatusCode());
	}

	/**
	 * Skips the test instead of failing when the public service is down.
	 */
	private function send(RequestInterface $request): ResponseInterface
	{
		try {
			$response = $this->client->sendRequest($request);
		} catch (NetworkException $e) {
			$this->markTestSkipped('httpbin.org is unavailable: ' . $e->getMessage());
		}

		if ($response->getStatusCode() >= 500) {
			$this->markTestSkipped('httpbin.org is unavailable: HTTP ' . $response->getStatusCode());
		}

		return $response;
	}
}
