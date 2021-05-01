<?php

declare(strict_types=1);

use HttpSoft\Message\RequestFactory;
use HttpSoft\Message\ResponseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Semperton\Proxy\Client;

final class ClientTest extends TestCase
{
	/** @var ResponseFactoryInterface */
	protected $responseFactory;

	/** @var RequestFactoryInterface */
	protected $requestFactory;

	public function setUp(): void
	{
		$this->responseFactory = new ResponseFactory();
		$this->requestFactory = new RequestFactory();
	}

	public function testPost(): void
	{
		$client = new Client($this->responseFactory);
		$request = $this->requestFactory->createRequest('POST', 'https://httpbin.org/anything');

		$request->getBody()->write('Hello World');

		$response = $client->sendRequest($request);

		$this->assertTrue($response->hasHeader('Content-Type'));
		$this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));

		$body = json_decode((string)$response->getBody(), true);
		$this->assertEquals('Hello World', $body['data']);
	}

	public function testStream(): void
	{
		$client = new Client($this->responseFactory);
		$request = $this->requestFactory->createRequest('GET', 'https://httpbin.org/stream/5');

		$response = $client->sendRequest($request);
		$this->assertTrue($response->hasHeader('Transfer-Encoding'));
		$this->assertEquals('chunked', $response->getHeaderLine('Transfer-Encoding'));
	}
}
