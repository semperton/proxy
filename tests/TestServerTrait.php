<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests;

use RuntimeException;

use function array_merge;
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
		$command = array_merge([PHP_BINARY, __DIR__ . '/Server/server.php'], self::serverArguments());
		$process = proc_open($command, [1 => ['pipe', 'w']], $pipes);

		if (!is_resource($process)) {
			throw new RuntimeException('Unable to start the test server');
		}

		self::$server = $process;
		$address = trim((string) fgets($pipes[1]));

		if ($address === '') {
			self::tearDownAfterClass();

			throw new RuntimeException('The test server did not report its address');
		}

		// a TCP port, or the socket path in Unix socket mode
		self::$port = (int) $address;
	}

	public static function tearDownAfterClass(): void
	{
		if (is_resource(self::$server)) {
			proc_terminate(self::$server);
			proc_close(self::$server);
		}

		self::$server = null;
	}

	/**
	 * @return list<string>
	 */
	private static function serverArguments(): array
	{
		return [];
	}

	private static function url(string $path, string $scheme = 'http'): string
	{
		return "$scheme://127.0.0.1:" . self::$port . $path;
	}
}
