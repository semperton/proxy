<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Semperton\Proxy\Proxy;

final class ProxyTest extends TestCase
{
	public function testHttp(): void
	{
		$proxy = new Proxy('http://httpbin.org/get');
		$response = $proxy->execute(true);

		$this->assertEquals('HTTP', $response['info']['scheme']);
	}

	public function testHttps(): void
	{
		$proxy = new Proxy('https://httpbin.org/get');
		$response = $proxy->execute(true);

		$this->assertEquals('HTTPS', $response['info']['scheme']);
	}

	public function testEncoding(): void
	{
		$proxy = new Proxy('https://httpbin.org/gzip');
		$response = $proxy->execute(true);

		$this->assertEquals('gzip', $response['header']['content-encoding']);

		$body = gzdecode($response['body']);
		$data = json_decode($body, true);

		$this->assertTrue($data['gzipped']);
	}
}
