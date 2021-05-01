<?php

declare(strict_types=1);

namespace Semperton\Proxy;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class Stream implements StreamInterface
{
	/** @var resource */
	protected $socket;

	/** @var null|int */
	protected $size;

	/** @var int */
	protected $readed = 0;

	/**
	 * @param resource $socket
	 */
	public function __construct($socket, ?int $size = null)
	{
		$this->socket = $socket;
		$this->size = $size;
	}

	public function __toString()
	{
		return $this->getContents();
	}

	public function __destruct()
	{
		$this->close();
	}

	public function close(): void
	{
		/** @psalm-suppress InvalidPropertyAssignmentValue */
		fclose($this->socket);
	}

	/**
	 * @return null|resource
	 */
	public function detach()
	{
		$socket = $this->socket;
		/** @psalm-suppress PossiblyNullPropertyAssignmentValue */
		$this->socket = null;

		return $socket;
	}

	public function getSize(): ?int
	{
		return $this->size;
	}

	public function tell(): int
	{
		return ftell($this->socket);
	}

	public function eof(): bool
	{
		return feof($this->socket);
	}

	public function isSeekable(): bool
	{
		return false;
	}

	/**
	 * @param int $offset
	 * @param int $whence
	 */
	public function seek($offset, $whence = SEEK_SET): void
	{
		throw new RuntimeException('This stream is not seekable');
	}

	public function rewind(): void
	{
		throw new RuntimeException('Can not rewind this stream');
	}

	public function isWritable(): bool
	{
		return false;
	}

	/**
	 * @param string $string
	 */
	public function write($string): int
	{
		throw new RuntimeException('Can not write to this stream');
	}

	public function isReadable(): bool
	{
		return true;
	}

	/**
	 * @param int $length
	 */
	public function read($length): string
	{
		if ($this->size === null) {
			return fread($this->socket, $length);
		}

		if ($this->size === $this->readed) {
			return '';
		}

		$read = fread($this->socket, $length);

		if ($this->getMetadata('timed_out')) {
			throw new RuntimeException('Stream timed out while reading data');
		}

		$this->readed += strlen($read);

		return $read;
	}

	public function getContents(): string
	{
		if ($this->size === null) {
			return stream_get_contents($this->socket);
		}

		$contents = '';

		do {
			$contents .= $this->read($this->size - $this->readed);
		} while ($this->readed < $this->size);

		return $contents;
	}

	/**
	 * @param null|string $key
	 * @return null|array|mixed
	 */
	public function getMetadata($key = null)
	{
		$meta = stream_get_meta_data($this->socket);

		if ($key === null) {
			return $meta;
		}

		return $meta[$key];
	}
}
