<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests;

use RuntimeException;

use function fgets;
use function is_resource;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function trim;

use const PHP_BINARY;

trait TestServerTrait
{
	/** @var resource|null */
	private static $server;

	private static int $port;

	public static function setUpBeforeClass(): void
	{
		$process = proc_open([PHP_BINARY, __DIR__ . '/Server/server.php'], [1 => ['pipe', 'w']], $pipes);

		if (!is_resource($process)) {
			throw new RuntimeException('Unable to start the test server');
		}

		self::$server = $process;
		self::$port = (int) trim((string) fgets($pipes[1]));
	}

	public static function tearDownAfterClass(): void
	{
		if (is_resource(self::$server)) {
			proc_terminate(self::$server);
			proc_close(self::$server);
		}

		self::$server = null;
	}

	private static function url(string $path): string
	{
		return 'http://127.0.0.1:' . self::$port . $path;
	}
}
