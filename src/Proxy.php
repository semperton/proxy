<?php

declare(strict_types=1);

namespace Semperton\Proxy;

use InvalidArgumentException;

final class Proxy
{
	/** @var string */
	protected $requestUrl = '';

	/** @var string */
	protected $requestMethod = 'GET';

	/** @var string */
	protected $requestBody = '';

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
		$this->setRequestUrl($url);
		$this->setRequestMethod($method);
		$this->setRequestBody($body);
		$this->setRequestHeaders($headers);

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
			$proxy->setRequestMethod((string)$_SERVER['REQUEST_METHOD']);
		}

		if (in_array($proxy->getRequestMethod(), ['POST', 'PUT', 'PATCH'])) {
			$data = file_get_contents('php://input');
			$proxy->setRequestBody($data);
		}

		$headers = function_exists('getallheaders') ? getallheaders() : self::getallheaders();
		$proxy->setRequestHeaders($headers);

		if (isset($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'])) {

			$uri = (string)$_SERVER['REQUEST_URI'];
			$script = (string)$_SERVER['SCRIPT_NAME'];

			$pos = strpos($uri, $script);

			if ($pos !== false) {
				$url = substr($uri, $pos + mb_strlen($script) + 1);
				$proxy->setRequestUrl($url)->removeRequestHeader('host');
			}
		}

		return $proxy;
	}

	public function setRequestUrl(string $url): self
	{
		$this->requestUrl = $url;

		return $this;
	}

	public function getRequestUrl(): string
	{
		return $this->requestUrl;
	}

	public function setRequestMethod(string $method): self
	{
		$method = strtoupper($method);

		if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])) {
			throw new InvalidArgumentException("Method < $method > is not supported");
		}

		$this->requestMethod = $method;

		return $this;
	}

	public function getRequestMethod(): string
	{
		return $this->requestMethod;
	}

	public function setRequestBody(string $data): self
	{
		$this->responseBody = $data;

		return $this;
	}

	public function getRequestBody(): string
	{
		return $this->requestBody;
	}

	/**
	 * @param array<string, string> $headers
	 */
	public function setRequestHeaders(array $headers): self
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

	public function followRedirect(bool $flag): self
	{
		$this->followRedirect = $flag;
		return $this;
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
			CURLOPT_CUSTOMREQUEST => $this->requestMethod,
			CURLOPT_URL => $this->requestUrl,
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

		if (in_array($this->requestMethod, ['POST', 'PUT', 'PATCH'])) {
			$options[CURLOPT_POSTFIELDS] = $this->requestBody;
		} else if ($this->requestMethod === 'HEAD') {
			$options[CURLOPT_NOBODY] = true;
		}

		return $options;
	}

	/**
	 * @param resource $handle
	 */
	protected function onCurlWrite($handle, string $data): int
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
	 * @param resource $handle
	 */
	protected function onCurlHeader($handle, string $header): int
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
	 * https://github.com/ralouphie/getallheaders/blob/develop/src/getallheaders.php
	 * @return array<string, string>
	 */
	protected static function getallheaders(): array
	{
		$headers = [];

		$copy_server = [
			'CONTENT_TYPE'   => 'Content-Type',
			'CONTENT_LENGTH' => 'Content-Length',
			'CONTENT_MD5'    => 'Content-Md5',
		];

		foreach ($_SERVER as $key => $value) {
			$key = (string)$key;
			if (substr($key, 0, 5) === 'HTTP_') {
				$key = substr($key, 5);
				if (!isset($copy_server[$key]) || !isset($_SERVER[$key])) {
					$key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
					$headers[$key] = $value;
				}
			} elseif (isset($copy_server[$key])) {
				$headers[$copy_server[$key]] = $value;
			}
		}

		if (!isset($headers['Authorization'])) {
			if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
				$headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			} elseif (isset($_SERVER['PHP_AUTH_USER'])) {
				$basic_pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
				$headers['Authorization'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $basic_pass);
			} elseif (isset($_SERVER['PHP_AUTH_DIGEST'])) {
				$headers['Authorization'] = $_SERVER['PHP_AUTH_DIGEST'];
			}
		}

		return $headers;
	}
}

// allow standalone usage
if (get_included_files()[0] === __FILE__) {

	Proxy::createFromGlobals()->execute(true);
}
