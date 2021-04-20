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
	protected $headers = [
		'user-agent' => 'SempertonProxy/1.0.0 (+https://github.com/semperton/proxy)'
	];

	/** @var array<string, string> */
	protected $responseHeaders = [];

	/** @var bool */
	protected $echo = false;

	/** @var bool */
	protected $isHttp1 = false;

	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(string $url = '', string $method = 'GET', string $body = '', array $headers = [])
	{
		$this->setUrl($url);
		$this->setMethod($method);
		$this->setBody($body);
		$this->setHeaders($headers);

		$this->isHttp1 = self::getServerHttpVersion() === 1;
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
		$proxy->setHeaders($headers);

		if (isset($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'])) {
			$url = substr((string)$_SERVER['REQUEST_URI'], strlen((string)$_SERVER['SCRIPT_NAME']) + 1);
			$proxy->setUrl($url)->removeHeader('host');
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
	public function setHeaders(array $headers): self
	{
		foreach ($headers as $key => $val) {

			$key = strtolower($key);

			$this->headers[$key] = $val;
		}

		return $this;
	}

	public function getHeader(string $name): ?string
	{
		$name = strtolower($name);

		return isset($this->headers[$name]) ? $this->headers[$name] : null;
	}

	/**
	 * @return array<string, string>
	 */
	public function getAllHeaders(): array
	{
		return $this->headers;
	}

	/**
	 * @param string|string[] $header
	 */
	public function removeHeader($header): self
	{
		if (!is_array($header)) {

			$header = [$header];
		}

		foreach ($header as $key) {

			$key = strtolower($key);

			unset($this->headers[$key]);
		}

		return $this;
	}

	/**
	 * @return array|void
	 */
	public function execute(bool $echo = false)
	{
		$this->echo = $echo;
		$this->responseHeaders = [];

		$ch = curl_init($this->url);

		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method);

		if (in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $this->body);
		}

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);

		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaderArray());

		curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$this, 'onCurlHeader']);

		if ($this->echo) {

			curl_setopt($ch, CURLOPT_WRITEFUNCTION, [$this, 'onCurlWrite']);

			if ($this->isHttp1) {
				header('Transfer-Encoding: chunked');
			}
		}

		$responseBody = curl_exec($ch);
		$responseInfo = curl_getinfo($ch);

		curl_close($ch);

		if ($this->echo && $this->isHttp1) {
			echo "0\r\n\r\n";
			flush();
		}

		return [

			'info' => $responseInfo,
			'header' => $this->responseHeaders,
			'body' => $responseBody
		];
	}

	/**
	 * @param resource $ch
	 */
	protected function onCurlWrite($ch, string $data): int
	{
		$length = strlen($data);

		if ($this->isHttp1) {
			echo dechex($length) . "\r\n$data\r\n";
		} else {
			echo $data;
		}

		flush();
		return $length;
	}

	/**
	 * @param resource $ch
	 */
	protected function onCurlHeader($ch, string $header): int
	{
		// we follow redirects, so we need to reset the headers...
		if (stripos($header, 'http') === 0) {

			$this->responseHeaders = [];
		} else {

			if ($this->echo) {
				header($header);
			} else {
				$col = strpos($header, ':');

				if ($col) { // not false and > 0

					$key = strtolower(substr($header, 0, $col));
					$val = substr($header, $col + 1);

					$this->responseHeaders[trim($key)] = trim($val);
				}
			}
		}

		return strlen($header);
	}

	/**
	 * @return string[]
	 */
	protected function getHeaderArray(): array
	{
		$header = [];

		foreach ($this->headers as $key => $val) {

			$header[] = $key . ':' . $val;
		}

		return $header;
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
