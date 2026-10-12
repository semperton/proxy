<div align="center">
<a href="https://github.com/semperton">
<img width="140" src="https://raw.githubusercontent.com/semperton/.github/main/readme-logo.svg" alt="Semperton">
</a>
<h1>Semperton Proxy</h1>
<p>Simple PSR-18 HTTP client based on PHP's native stream socket.</p>
</div>

---

## Installation

Just use Composer:

```
composer require semperton/proxy
```
Proxy requires PHP 8.1+ (and the `openssl` extension for HTTPS).

## Usage
The client does not come with a PSR-17 response factory by itself.
You have to provide one in the constructor.

```php
use HttpSoft\Message\ResponseFactory;
use Semperton\Proxy\Client;

$client = new Client(
	new ResponseFactory(), // any PSR-17 compliant response factory
	5, // connect and read/write timeout in seconds (float)
	$options, // array of stream context options
	8192 // buffer size used to read/write bodies
);
```

The context options are passed to [`stream_context_create`](https://www.php.net/manual/en/context.php).
For example, use the `ssl` options to configure certificate verification, or `crypto_method` to choose the TLS version:

```php
$options = [
	'ssl' => [
		'cafile' => '/path/to/ca.pem',
		'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
	],
];
```

The client exposes only one public method, `sendRequest`:
```php
use HttpSoft\Message\RequestFactory;
use Psr\Http\Message\ResponseInterface;

$requestFactory = new RequestFactory();
$request = $requestFactory->createRequest('GET', 'https://example.com');

$response = $client->sendRequest($request);

$response instanceof ResponseInterface; // true
```

The response body is read from the socket on demand, so large responses can be streamed:
```php
$body = $response->getBody();

while (!$body->eof()) {
	echo $body->read(8192);
}
```

## Errors
Responses with 4xx or 5xx status codes are returned as normal responses. Exceptions are only thrown when no valid response could be received:

- `Semperton\Proxy\Exception\RequestException` (`Psr\Http\Client\RequestExceptionInterface`) when the request is invalid or cannot be sent by this client. Examples are an unsupported protocol version, a `CONNECT` request, or a body that cannot be read.
- `Semperton\Proxy\Exception\NetworkException` (`Psr\Http\Client\NetworkExceptionInterface`) when the connection fails, times out, or the response is malformed.

Both extend `Semperton\Proxy\Exception\ClientException`, and `getRequest()` returns the request that was sent.

Reading the response body throws a `RuntimeException` if the body is incomplete, for example when the connection is closed early or a read times out.

## Features and limits
- HTTP/1.0 and HTTP/1.1 over plain TCP or TLS
- Chunked response bodies are decoded, while other transfer and content codings (e.g. `gzip`) are left untouched
- Request bodies of unknown size are buffered to send a `Content-Length`. Set a `Transfer-Encoding: chunked` header on the request to stream them chunked instead
- Interim `1xx` responses are skipped
- One connection per request (`Connection: close`), no redirects, no proxies, no protocol upgrades

This is just a simple HTTP client for single requests. If you are going to do heavy API work (concurrent requests, connection reuse, retries, etc.), you should consider using `guzzlehttp/guzzle` or `symfony/http-client`.

## Development
```
composer check     # runs all of the following
composer cs        # coding style (composer cs:fix to fix)
composer psalm     # static analysis
composer test      # offline test suite
vendor/bin/phpunit --group network  # tests against httpbin.org
```
