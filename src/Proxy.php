<?php

declare(strict_types=1);

namespace Semperton\Proxy;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Proxy implements ClientInterface
{
	protected $responseFactory;

	protected $streamFactory;

	public function __construct(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory)
	{
		$this->responseFactory = $responseFactory;
		$this->streamFactory = $streamFactory;
	}

	public function sendRequest(RequestInterface $request): ResponseInterface
	{

		$method = $request->getMethod();
		$protoVersion = $request->getProtocolVersion();
		$headers = $request->getHeaders();

		$uri = $request->getUri();
		$scheme = $uri->getScheme();
		$proto = $scheme === 'https' ? 'ssl' : 'tcp';
		$host = $uri->getHost();
		$port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
		$path = $uri->getPath() . '?' . $uri->getQuery();

		$address = $proto . '://' . $host . ':' . $port;
		$socket = stream_socket_client($address);

		if(!is_resource($socket)){
			// trow
		}

		fwrite($socket, "$method $path HTTP/$protoVersion\r\n");

		foreach($headers as $name => $values){
			foreach($values as $value){
				fwrite($socket, "$name: $value\r\n");
			}
		}

		fwrite($socket, "\r\n");

		

		$response = $this->responseFactory->createResponse();
		$stream = $this->streamFactory->createStreamFromResource($socket);

		return $response->withBody($stream);
	}
}