<?php

declare(strict_types=1);

namespace Semperton\Proxy\Exception;

use Psr\Http\Message\RequestInterface;
use Throwable;

trait ExceptionTrait
{
	/** @var RequestInterface */
	protected $request;

	public function __construct(RequestInterface $request, string $message = '', int $code = 0, ?Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
		$this->request = $request;
	}

	public function getRequest(): RequestInterface
	{
		return $this->request;
	}
}
