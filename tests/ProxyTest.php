<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Semperton\Proxy\Proxy;

final class ProxyTest extends TestCase
{
	public function testResponseCode(): void
	{
		$proxy = new Proxy('https://httpbin.org/status/200');
		$proxy->execute();

		$this->assertEquals(200, $proxy->getResponseCode());

		$proxy->setRequestUrl('https://httpbin.org/status/404');
		$proxy->execute();

		$this->assertEquals(404, $proxy->getResponseCode());

		$proxy->setRequestUrl('https://httpbin.org/status/301');
		$proxy->followRedirect(false)->execute();

		$this->assertEquals(301, $proxy->getResponseCode());
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
