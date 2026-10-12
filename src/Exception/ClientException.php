<?php

declare(strict_types=1);

namespace Semperton\Proxy\Exception;

use Exception;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

abstract class ClientException extends Exception implements ClientExceptionInterface
{
	public function __construct(
		private readonly RequestInterface $request,
		string $message = '',
		int $code = 0,
		?Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	public function getRequest(): RequestInterface
	{
		return $this->request;
	}
}
