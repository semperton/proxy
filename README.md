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
Proxy requires PHP 7.1+

## Usage
The client does not come with a PSR-17 request/response factory by itself.
You have to provide one in the constructor.

```php
use HttpSoft\Message\ResponseFactory;
use Semperton\Proxy\Client;

$client new Client(
	new ResponseFactory(), // any PSR-17 compilant response factory
	5, // request timeout in secs
	$options, // array of stream context options
	4096 // buffer size used to read/write request body
);
```

The client exposes only one public method ```sendRequest```:
```php
use HttpSoft\Message\RequestFactory;
use Psr\Http\Message\ResponseInterface;

$requestFactory = new RequestFactory();
$request = $requestFactory->createRequest('GET', 'https://google.com');

$response = $client->sendRequest($request);

$response instanceof ResponseInterface // true

```

## Note
This is just a very simple HTTP client for single requests. If you are going to do heavy API work (multiple asynchronous requests, body parsing, etc.), you should consider using ```guzzlehttp/guzzle``` or ```symfony/http-client```.
