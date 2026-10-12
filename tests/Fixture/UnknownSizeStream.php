<?php

declare(strict_types=1);

namespace Semperton\Proxy\Tests\Fixture;

use HttpSoft\Message\StreamTrait;
use Psr\Http\Message\StreamInterface;

/**
 * A readable stream that does not know its size, like a generated or piped body.
 */
final class UnknownSizeStream implements StreamInterface
{
	use StreamTrait;

	public function __construct(string $contents)
	{
		$this->init('php://temp', 'wb+');
		$this->write($contents);
		$this->rewind();
	}

	public function getSize(): ?int
	{
		return null;
	}
}
