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
	protected $data = '';

	/** @var array<string, string> */
	protected $header = [
		'user-agent' => 'SempertonProxy/1.0.0 (+https://github.com/semperton/proxy)'
	];

	/** @var array<string, string> */
	protected $responseHeader = [];

	/** @var bool */
	protected $return = false;

	/** @var bool */
	protected $isHttp2 = false;

	/**
	 * @param array<string, string> $header
	 */
	public function __construct(string $url = '', string $method = 'GET', string $data = '', array $header = [])
	{
		$this->setUrl($url);
		$this->setMethod($method);
		$this->setData($data);
		$this->setHeader($header);

		if (
			php_sapi_name() === 'cli' ||
			(isset($_SERVER['SERVER_PROTOCOL']) && stripos($_SERVER['SERVER_PROTOCOL'], 'http/2') !== false)
		) {
			$this->isHttp2 = true;
		}
	}

	public static function createFromGlobals(): Proxy
	{
		if (php_sapi_name() === 'cli') {
			return new self();
		}

		$url = substr($_SERVER['REQUEST_URI'], strlen($_SERVER['SCRIPT_NAME']) + 1);
		$method = $_SERVER['REQUEST_METHOD'];
		$data = @file_get_contents('php://input');
		$header = getallheaders();

		$proxy = new self($url, $method, $data, $header);
		$proxy->removeHeader('host');

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

	public function setData(string $data): self
	{
		$this->data = $data;

		return $this;
	}

	public function getData(): string
	{
		return $this->data;
	}

	/**
	 * @param array<string, string> $header
	 */
	public function setHeader(array $header): self
	{
		foreach ($header as $key => $val) {

			$key = strtolower($key);

			$this->header[$key] = $val;
		}

		return $this;
	}

	public function getHeader(string $name): ?string
	{
		$name = strtolower($name);

		return isset($this->header[$name]) ? $this->header[$name] : null;
	}

	/**
	 * @return array<string, string>
	 */
	public function getAllHeaders(): array
	{
		return $this->header;
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

			unset($this->header[$key]);
		}

		return $this;
	}

	/**
	 * @return array|void
	 */
	public function execute(bool $return = false)
	{
		$this->return = $return;
		$this->responseHeader = [];

		$ch = curl_init($this->url);

		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method);

		if (in_array($this->method, ['POST', 'PUT', 'PATCH'])) {

			curl_setopt($ch, CURLOPT_POSTFIELDS, $this->data);
		}

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);

		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaderArray());

		curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$this, 'onCurlHeader']);

		if (!$this->return) {

			curl_setopt($ch, CURLOPT_WRITEFUNCTION, [$this, 'onCurlWrite']);

			if (!$this->isHttp2) {

				header('Transfer-Encoding: chunked');
			}
		}

		$responseBody = curl_exec($ch);
		$responseInfo = curl_getinfo($ch);

		curl_close($ch);

		if (!$this->return) {

			if (!$this->isHttp2) {

				echo "0\r\n\r\n";
				flush();
			}

			die();
		}

		return [

			'info' => $responseInfo,
			'header' => $this->responseHeader,
			'body' => $responseBody
		];
	}

	/**
	 * @param resource $ch
	 */
	protected function onCurlWrite($ch, string $data): int
	{
		$length = strlen($data);

		if ($this->isHttp2) {
			echo $data;
		} else {
			echo dechex($length) . "\r\n$data\r\n";
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

			$this->responseHeader = [];
		} else {

			if ($this->return) {

				$col = strpos($header, ':');

				if ($col) { // not false and > 0

					$key = strtolower(substr($header, 0, $col));
					$val = substr($header, $col + 1);

					$this->responseHeader[trim($key)] = trim($val);
				}
			} else {

				header($header);
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

		foreach ($this->header as $key => $val) {

			$header[] = $key . ':' . $val;
		}

		return $header;
	}
}

// allow standalone usage
if (get_included_files()[0] === __FILE__) {

	Proxy::createFromGlobals()->execute();
}
