<?php

declare(strict_types=1);

namespace Semperton\Proxy;

use InvalidArgumentException;

class Proxy
{
	/** @var string */
	protected $url = '';

	/** @var string */
	protected $method = 'GET';

	/** @var string */
	protected $body = '';

	/** @var array<string, string> */
	protected $requestHeaders = [
		'user-agent' => 'SempertonProxy/1.0.0 (+https://github.com/semperton/proxy)'
	];

	/** @var string */
	protected $responseBody = '';

	/** @var array<string, string> */
	protected $responseHeaders = [];

	/** @var int */
	protected $responseCode = 0;

	/** @var bool */
	protected $followRedirect = true;

	/** @var bool */
	protected $emit = false;

	/** @var bool */
	protected $isHttp1 = false;

	/** @var null|resource */
	protected $curlHandle = null;

	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(string $url = '', string $method = 'GET', string $body = '', array $headers = [])
	{
		$this->setUrl($url);
		$this->setMethod($method);
		$this->setBody($body);
		$this->addRequestHeaders($headers);

		$this->isHttp1 = self::getServerHttpVersion() === 1;
	}

	public function __destruct()
	{
		if (is_resource($this->curlHandle)) {
			curl_close($this->curlHandle);
		}
	}

	public static function createFromGlobals(): Proxy
	{
		$proxy = new self();

		if (isset($_SERVER['REQUEST_METHOD'])) {
			$proxy->setMethod((string)$_SERVER['REQUEST_METHOD']);
		}

		if (in_array($proxy->getMethod(), ['POST', 'PUT', 'PATCH'])) {
			$data = file_get_contents('php://input');
			$proxy->setBody($data);
		}

		$headers = function_exists('getallheaders') ? getallheaders() : self::getServerHeaders();
		$proxy->addRequestHeaders($headers);

		if (isset($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'])) {
			$url = substr((string)$_SERVER['REQUEST_URI'], strlen((string)$_SERVER['SCRIPT_NAME']) + 1);
			$proxy->setUrl($url)->removeRequestHeader('host');
		}

		return $proxy;
	}

	public function setUrl(string $url): self
	{
		$this->url = $url;

		return $this;
	}

	public function getUrl(): string
	{
		return $this->url;
	}

	public function setMethod(string $method): self
	{
		$method = strtoupper($method);

		if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])) {
			throw new InvalidArgumentException("Method < $method > is not supported");
		}

		$this->method = $method;

		return $this;
	}

	public function getMethod(): string
	{
		return $this->method;
	}

	public function setBody(string $data): self
	{
		$this->body = $data;

		return $this;
	}

	public function getBody(): string
	{
		return $this->body;
	}

	/**
	 * @param array<string, string> $headers
	 */
	public function addRequestHeaders(array $headers): self
	{
		foreach ($headers as $key => $val) {

			$key = strtolower($key);
			$this->requestHeaders[$key] = $val;
		}

		return $this;
	}

	public function getRequestHeader(string $name): ?string
	{
		$name = strtolower($name);

		return isset($this->requestHeaders[$name]) ? $this->requestHeaders[$name] : null;
	}

	/**
	 * @return array<string, string>
	 */
	public function getAllRequestHeaders(): array
	{
		return $this->requestHeaders;
	}

	/**
	 * @param string|string[] $header
	 */
	public function removeRequestHeader($header): self
	{
		if (!is_array($header)) {
			$header = [$header];
		}

		foreach ($header as $key) {

			$key = strtolower($key);
			unset($this->requestHeaders[$key]);
		}

		return $this;
	}

	public function followRedirect(bool $flag): self
	{
		$this->followRedirect = $flag;
		return $this;
	}

	public function getResponseHeader(string $name): ?string
	{
		$name = strtolower($name);

		return $this->responseHeaders[$name] ?? null;
	}

	public function getAllResponseHeaders(): array
	{
		return $this->responseHeaders;
	}

	public function getResponseBody(): string
	{
		return $this->responseBody;
	}

	public function getResponseCode(): int
	{
		return $this->responseCode;
	}

	public function execute(bool $emit = false): bool
	{
		$this->emit = $emit;
		$this->responseBody = '';
		$this->responseHeaders = [];
		$this->responseCode = 0;

		if (!is_resource($this->curlHandle)) {
			$this->curlHandle = curl_init();
		}

		curl_reset($this->curlHandle);
		curl_setopt_array($this->curlHandle, $this->getCurlOptions());

		$success = curl_exec($this->curlHandle);

		$this->responseCode = (int)curl_getinfo($this->curlHandle, CURLINFO_RESPONSE_CODE);

		if ($this->emit && $this->isHttp1) {
			echo "0\r\n\r\n";
			flush();
		}

		return (bool)$success;
	}

	protected function getCurlOptions(): array
	{
		$options = [
			CURLOPT_CUSTOMREQUEST => $this->method,
			CURLOPT_URL => $this->url,
			CURLOPT_HTTPHEADER => $this->getRequestHeaderArray(),

			CURLOPT_CONNECTTIMEOUT => 100,
			CURLOPT_TIMEOUT => 100,

			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,

			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_HEADER => false,

			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,

			CURLOPT_WRITEFUNCTION => [$this, 'onCurlWrite'],
			CURLOPT_HEADERFUNCTION => [$this, 'onCurlHeader']
		];

		if ($this->followRedirect) {
			$options[CURLOPT_FOLLOWLOCATION] = true;
			// $options[CURLOPT_MAXREDIRS] = 3;
		}

		if (in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
			$options[CURLOPT_POSTFIELDS] = $this->body;
		}

		if ($this->method === 'HEAD') {
			$options[CURLOPT_NOBODY] = true;
		}

		return $options;
	}

	/**
	 * @param resource $ch
	 */
	protected function onCurlWrite($ch, string $data): int
	{
		$length = strlen($data);

		if ($this->emit) {

			if (!headers_sent()) {
				foreach ($this->responseHeaders as $key => $val) {
					header("$key: $val", false);
				}
				if ($this->isHttp1) {
					header('Transfer-Encoding: chunked');
				}
			}

			if ($this->isHttp1) {
				echo dechex($length) . "\r\n$data\r\n";
			} else {
				echo $data;
			}

			flush();
		} else {
			$this->responseBody .= $data;
		}

		return $length;
	}

	/**
	 * @param resource $ch
	 */
	protected function onCurlHeader($ch, string $header): int
	{
		$length = strlen($header);
		$header = trim($header);

		if (stripos($header, 'http') === 0) {

			$this->responseHeaders = [];
			$this->responseBody = '';
		} else if (!empty($header)) {
			$this->storeResponseHeader($header);
		}

		return $length;
	}

	protected function storeResponseHeader(string $header): void
	{
		$split = explode(':', $header, 2);

		if (isset($split[1])) {
			$key = strtolower(trim($split[0]));
			$val = trim($split[1]);

			$this->responseHeaders[$key] = $val;
		}
	}

	/**
	 * @return string[]
	 */
	protected function getRequestHeaderArray(): array
	{
		$headers = [];
		foreach ($this->requestHeaders as $key => $val) {
			$headers[] = "$key: $val";
		}

		return $headers;
	}

	protected static function getServerHttpVersion(): int
	{
		if (isset($_SERVER['SERVER_PROTOCOL'])) {

			$proto = (string)$_SERVER['SERVER_PROTOCOL'];
			$pos = stripos($proto, 'http/');

			if ($pos !== false) {
				return (int)substr($proto, $pos + 1, 1);
			}
		}

		return 0;
	}

	/**
	 * laminas-diactoros/src/functions/marshal_headers_from_sapi.php
	 * @return array<string, string>
	 */
	protected static function getServerHeaders(): array
	{
		$headers = [];
		foreach ($_SERVER as $key => $value) {

			$key = (string)$key;

			if (0 === strpos($key, 'REDIRECT_')) {
				$key = substr($key, 9);

				if (array_key_exists($key, $_SERVER)) {
					continue;
				}
			}

			if ($value && 0 === strpos($key, 'HTTP_')) {
				$name = strtr(strtolower(substr($key, 5)), '_', '-');
				$headers[$name] = (string)$value;
				continue;
			}

			if ($value && 0 === strpos($key, 'CONTENT_')) {
				$name = 'content-' . strtolower(substr($key, 8));
				$headers[$name] = (string)$value;
				continue;
			}
		}

		return $headers;
	}
}

// allow standalone usage
if (get_included_files()[0] === __FILE__) {

	Proxy::createFromGlobals()->execute(true);
}
