<?php

declare(strict_types=1);

namespace Semperton\Proxy\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Exception;

class RequestException extends Exception implements RequestExceptionInterface
{
	use ExceptionTrait;
}
