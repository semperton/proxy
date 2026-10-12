<?php

declare(strict_types=1);

namespace Semperton\Proxy\Exception;

use Psr\Http\Client\NetworkExceptionInterface;

final class NetworkException extends ClientException implements NetworkExceptionInterface {}
