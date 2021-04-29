<?php

declare(strict_types=1);

namespace Semperton\Proxy;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class SocketStream implements StreamInterface
{
	/** @var resource Underlying socket */
	private $socket;

	/**
	 * @var bool Is stream detached
	 */
	private $isDetached = false;

	/**
	 * @var int|null Size of the stream, so we know what we must read, null if not available (i.e. a chunked stream)
	 */
	private $size;

	/**
	 * @var int Size of the stream readed, to avoid reading more than available and have the user blocked
	 */
	private $readed = 0;

	/**
	 * Create the stream.
	 *
	 * @param resource $socket
	 */
	public function __construct($socket, ?int $size = null)
	{
		$this->socket = $socket;
		$this->size = $size;
	}

	/**
	 * {@inheritdoc}
	 */
	public function __toString()
	{
		try {
			return $this->getContents();
		} catch (\Exception $e) {
			return '';
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function close()
	{
		fclose($this->socket);
	}

	/**
	 * {@inheritdoc}
	 */
	public function detach()
	{
		$this->isDetached = true;
		$socket = $this->socket;
		$this->socket = null;

		return $socket;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getSize()
	{
		return $this->size;
	}

	/**
	 * {@inheritdoc}
	 */
	public function tell()
	{
		return ftell($this->socket);
	}

	/**
	 * {@inheritdoc}
	 */
	public function eof()
	{
		return feof($this->socket);
	}

	/**
	 * {@inheritdoc}
	 */
	public function isSeekable()
	{
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function seek($offset, $whence = SEEK_SET)
	{
		throw new RuntimeException('This stream is not seekable');
	}

	/**
	 * {@inheritdoc}
	 */
	public function rewind()
	{
		throw new RuntimeException('This stream is not seekable');
	}

	/**
	 * {@inheritdoc}
	 */
	public function isWritable()
	{
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function write($string)
	{
		throw new RuntimeException('This stream is not writable');
	}

	/**
	 * {@inheritdoc}
	 */
	public function isReadable()
	{
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function read($length)
	{
		if (null === $this->getSize()) {
			return fread($this->socket, $length);
		}

		if ($this->getSize() === $this->readed) {
			return '';
		}

		// Even if we request a length a non blocking stream can return less data than asked
		$read = fread($this->socket, $length);

		if ($this->getMetadata('timed_out')) {
			throw new RuntimeException('Stream timed out while reading data');
		}

		$this->readed += strlen($read);

		return $read;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getContents()
	{
		if (null === $this->getSize()) {
			return stream_get_contents($this->socket);
		}

		$contents = '';

		do {
			$contents .= $this->read($this->getSize() - $this->readed);
		} while ($this->readed < $this->getSize());

		return $contents;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMetadata($key = null)
	{
		$meta = stream_get_meta_data($this->socket);

		if (null === $key) {
			return $meta;
		}

		return $meta[$key];
	}
}
