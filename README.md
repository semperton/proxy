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
	timeout: 5, // seconds to wait for the connection or the server (idle timeout)
	contextOptions: $options, // array of stream context options
	bufferSize: 8192, // bytes to read/write at once
	maxDuration: 30, // seconds the whole exchange may take, null for no limit
	unixSocket: null, // path of a Unix domain socket to connect to instead
	decodeContent: false, // request and decode gzip/deflate responses
);
```

All parameters except the response factory are optional.

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

## Timeouts
There are two independent limits:

- `timeout` is an idle timeout. It applies to connecting and to every single read or write, so a large download never fails as long as data keeps arriving.
- `maxDuration` limits the whole exchange: connecting, sending the request and reading the response, **including the body**. It protects against servers that send data very slowly. When it is exceeded while reading the body, the body read throws a `RuntimeException`.

```php
// fail if the server stalls for 5 seconds, or if everything takes longer than 30 seconds
$client = new Client(new ResponseFactory(), timeout: 5, maxDuration: 30);
```

## Unix domain sockets
Set `unixSocket` to talk to a local service such as the Docker daemon. The host of the request URI is only used for the `Host` header:

```php
$client = new Client(new ResponseFactory(), unixSocket: '/var/run/docker.sock');
$request = $requestFactory->createRequest('GET', 'http://localhost/version');
```

TLS over Unix domain sockets is not supported.

## Compression
With `decodeContent: true` the client sends `Accept-Encoding: gzip, deflate` and decodes such responses while they are read. The `Content-Encoding` and `Content-Length` headers are removed from decoded responses, as PSR-18 requires. This needs the `zlib` extension.

If the request already has an `Accept-Encoding` header, it is kept. Only `gzip` and `deflate` responses are decoded, other codings (e.g. `br`) are left untouched.

## Errors
Responses with 4xx or 5xx status codes are returned as normal responses. Exceptions are only thrown when no valid response could be received:

- `Semperton\Proxy\Exception\RequestException` (`Psr\Http\Client\RequestExceptionInterface`) when the request is invalid or cannot be sent by this client. Examples are an unsupported protocol version, a `CONNECT` request, or a body that cannot be read.
- `Semperton\Proxy\Exception\NetworkException` (`Psr\Http\Client\NetworkExceptionInterface`) when the connection fails, times out, or the response is malformed.

Both extend `Semperton\Proxy\Exception\ClientException`, and `getRequest()` returns the request that was sent.

Reading the response body throws a `RuntimeException` if the body is incomplete, for example when the connection is closed early or a read times out.

## Features and limits
- HTTP/1.0 and HTTP/1.1 over plain TCP or TLS
- Chunked response bodies are decoded. `gzip` and `deflate` content can be decoded on request, see [Compression](#compression)
- Request bodies of unknown size are buffered to send a `Content-Length`. Set a `Transfer-Encoding: chunked` header on the request to stream them chunked instead
- Interim `1xx` responses are skipped
- One connection per request (`Connection: close`), no redirects, no proxies, no protocol upgrades
- Response heads are limited to 256 KiB and 10 interim responses, so a hostile server cannot exhaust memory, and `maxDuration` limits the total time

This is just a simple HTTP client for single requests. If you are going to do heavy API work (concurrent requests, connection reuse, retries, etc.), you should consider using `guzzlehttp/guzzle` or `symfony/http-client`.

## Development
```
composer check     # runs all of the following
composer cs        # coding style (composer cs:fix to fix)
composer psalm     # static analysis
composer test      # offline test suite (includes TLS against a local server)
vendor/bin/phpunit --group network  # tests against httpbin.org
```
