<?php

declare(strict_types=1);

namespace Semperton\Proxy\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use Exception;

class ClientException extends Exception implements ClientExceptionInterface
{
}
