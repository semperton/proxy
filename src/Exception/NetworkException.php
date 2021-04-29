<?php

declare(strict_types=1);

namespace Semperton\Proxy\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Exception;

class NetworkException extends Exception implements NetworkExceptionInterface
{
	use ExceptionTrait;
}
