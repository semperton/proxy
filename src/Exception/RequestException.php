<?php

declare(strict_types=1);

namespace Semperton\Proxy\Exception;

use Psr\Http\Client\RequestExceptionInterface;

final class RequestException extends ClientException implements RequestExceptionInterface {}
