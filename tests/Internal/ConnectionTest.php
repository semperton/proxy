<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests\Internal;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Semperton\Proxy\Internal\Connection;

use function fclose;
use function fwrite;
use function is_resource;
use function microtime;
use function str_repeat;
use function stream_get_contents;
use function stream_set_timeout;
use function stream_socket_pair;
use function usleep;

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

final class ConnectionTest extends TestCase
{
	/** @var resource */
	private $local;

	/** @var resource */
	private $remote;

	private Connection $connection;

	protected function setUp(): void
	{
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$this->assertNotFalse($pair);
		[$this->local, $this->remote] = $pair;

		stream_set_timeout($this->local, 0, 100_000);
		$this->connection = Connection::wrap($this->local);
	}

	protected function tearDown(): void
	{
		$this->connection->close();

		if (is_resource($this->remote)) {
			fclose($this->remote);
		}
	}

	public function testReadLine(): void
	{
		fwrite($this->remote, "first\r\nsecond\n\r\n");

		$this->assertSame('first', $this->connection->readLine(100));
		$this->assertSame('second', $this->connection->readLine(100));
		$this->assertSame('', $this->connection->readLine(100));
	}

	public function testReadLineTooLong(): void
	{
		fwrite($this->remote, "0123456789\r\n");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Line exceeds 5 bytes');

		$this->connection->readLine(5);
	}

	public function testReadLineClosedPrematurely(): void
	{
		fwrite($this->remote, 'partial');
		fclose($this->remote);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('closed prematurely');

		$this->connection->readLine(100);
	}

	public function testReadLineTimeout(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('timed out');

		$this->connection->readLine(100);
	}

	public function testReadTimeout(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('timed out');

		$this->connection->read(10);
	}

	public function testReadAllTimeout(): void
	{
		fwrite($this->remote, 'abc');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('timed out');

		$this->connection->readAll(10);
	}

	public function testExpiredMaxDuration(): void
	{
		$connection = Connection::wrap($this->local, 5, 0.000001);
		usleep(1000);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('maximum duration');

		$connection->read(1);
	}

	public function testMaxDurationWinsOverIdleTimeout(): void
	{
		$connection = Connection::wrap($this->local, 5, 0.1);
		$start = microtime(true);

		try {
			$connection->readLine(100);
			$this->fail('Expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('maximum duration', $e->getMessage());
		}

		$this->assertLessThan(1, microtime(true) - $start);
	}

	public function testReadLineAcrossReads(): void
	{
		fwrite($this->remote, 'first part, ');
		fwrite($this->remote, "second part\r\nnext");

		$this->assertSame('first part, second part', $this->connection->readLine(100));
		$this->assertSame('next', $this->connection->read(100));
	}

	public function testReadUntilEnd(): void
	{
		fwrite($this->remote, 'Hello World');
		fclose($this->remote);

		$this->assertSame('Hello', $this->connection->read(5));
		$this->assertSame(' World', $this->connection->readAll());
		$this->assertSame('', $this->connection->read(5));
		$this->assertTrue($this->connection->eof());
	}

	public function testWrite(): void
	{
		$data = str_repeat('x', 65536);
		$this->connection->write($data);
		$this->connection->close();

		$this->assertSame($data, stream_get_contents($this->remote));
	}

	public function testWriteToClosedPeer(): void
	{
		fclose($this->remote);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unable to write');

		$this->connection->write('data');
	}

	public function testCloseIsIdempotent(): void
	{
		$this->connection->close();
		$this->connection->close();

		$this->assertFalse(is_resource($this->local));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Connection is closed');

		$this->connection->read(1);
	}

	public function testDetach(): void
	{
		$this->assertSame($this->local, $this->connection->detach());
		$this->assertNull($this->connection->detach());
		$this->assertTrue(is_resource($this->local));

		fclose($this->local);
	}

	public function testOpenFails(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unable to connect to tcp://127.0.0.1:1');

		Connection::open('tcp://127.0.0.1:1', false, 1, null, []);
	}
}
