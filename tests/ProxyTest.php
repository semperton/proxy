<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Semperton\Proxy\Proxy;

final class ProxyTest extends TestCase
{
	public function testResponseCode(): void
	{
		$proxy = new Proxy('https://httpbin.org/get');
		$proxy->execute();

		$this->assertEquals(200, $proxy->getResponseCode());
	}

	public function testEncoding(): void
	{
		$proxy = new Proxy('https://httpbin.org/gzip');
		$proxy->execute();

		$this->assertEquals('gzip', $proxy->getResponseHeader('content-encoding'));

		$body = gzdecode($proxy->getResponseBody());
		$data = json_decode($body, true);

		$this->assertTrue($data['gzipped']);
	}
}
