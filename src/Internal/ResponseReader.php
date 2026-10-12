<?php

declare(strict_types=1);

namespace Semperton\Proxy\Internal;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function array_map;
use function array_slice;
use function array_unique;
use function count;
use function ctype_digit;
use function end;
use function explode;
use function filter_var;
use function implode;
use function ltrim;
use function preg_match;
use function reset;
use function str_replace;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const FILTER_VALIDATE_INT;

/**
 * Reads a response (RFC 9112 §4 – §6).
 *
 * @internal
 */
final class ResponseReader
{
	private const MAX_LINE_LENGTH = 65536;

	private const MAX_HEAD_SIZE = 262144;

	private const MAX_INTERIM_RESPONSES = 10;

	/** Content codings that are removed when decoding is enabled */
	private const DECODABLE_CODINGS = ['gzip' => 'gzip', 'x-gzip' => 'gzip', 'deflate' => 'deflate'];

	public function __construct(
		private readonly ResponseFactoryInterface $responseFactory,
		private readonly int $bufferSize,
		private readonly bool $decodeContent,
	) {}

	/**
	 * Reads the final response, its body is read on demand.
	 */
	public function read(Connection $connection, RequestInterface $request): ResponseInterface
	{
		// interim 1xx responses are skipped (RFC 9110 §15.2)
		$interim = 0;

		do {
			if ($interim++ > self::MAX_INTERIM_RESPONSES) {
				throw new RuntimeException('Received more than ' . self::MAX_INTERIM_RESPONSES . ' interim responses');
			}

			[$version, $status, $reason, $fields] = $this->readHead($connection);
		} while ($status < 200 && $status !== 101);

		if ($status === 101) {
			throw new RuntimeException('Unexpected protocol switch, upgrades are not supported');
		}

		$response = $this->responseFactory
			->createResponse($status, $reason)
			->withProtocolVersion($version);

		foreach ($fields as $name => $values) {
			$response = $response->withHeader($name, $values);
		}

		$response = $this->attachBody($connection, $request, $response);

		return $this->decodeContent ? $this->decodeBody($response) : $response;
	}

	/**
	 * Removes a single gzip or deflate content coding, and adjusts the fields to match (PSR-18).
	 */
	private function decodeBody(ResponseInterface $response): ResponseInterface
	{
		$coding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
		$body = $response->getBody();

		// other or stacked codings are left as they are, as are responses without content
		if (!isset(self::DECODABLE_CODINGS[$coding]) || $body->getSize() === 0) {
			return $response;
		}

		return $response
			->withoutHeader('Content-Encoding')
			->withoutHeader('Content-Length')
			->withBody(new DecodedBody($body, self::DECODABLE_CODINGS[$coding], $this->bufferSize));
	}

	/**
	 * @return array{string, int, string, array<string, list<string>>} version, status, reason, fields
	 */
	private function readHead(Connection $connection): array
	{
		$statusLine = $connection->readLine(self::MAX_LINE_LENGTH);

		if (preg_match('#^HTTP/(1\.\d)[ \t]+([1-5]\d\d)(?:[ \t]+(.*))?$#', $statusLine, $match) !== 1) {
			throw new RuntimeException('Received an invalid status line');
		}

		$lines = [];
		$headSize = strlen($statusLine);

		while (($line = $connection->readLine(self::MAX_LINE_LENGTH)) !== '') {
			$headSize += strlen($line);

			if ($headSize > self::MAX_HEAD_SIZE) {
				throw new RuntimeException('Response head exceeds ' . self::MAX_HEAD_SIZE . ' bytes');
			}

			if ($line[0] !== ' ' && $line[0] !== "\t") {
				$lines[] = $line;
			} elseif ($lines !== []) {
				// obsolete line folding continues the previous line (RFC 9112 §5.2),
				// whitespace before the first field line is ignored (RFC 9112 §2.2)
				$lines[count($lines) - 1] .= ' ' . trim($line, " \t");
			}
		}

		$names = [];
		$fields = [];

		foreach ($lines as $line) {
			$colon = strpos($line, ':');
			$name = $colon === false ? '' : substr($line, 0, $colon);

			// malformed field lines are ignored
			if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9a-zA-Z]+$/', $name) !== 1) {
				continue;
			}

			// bare CRs must not be interpreted (RFC 9112 §2.2)
			$value = str_replace("\r", ' ', trim(substr($line, (int) $colon + 1), " \t"));
			$fields[$names[strtolower($name)] ??= $name][] = $value;
		}

		return [$match[1], (int) $match[2], trim($match[3] ?? ''), $fields];
	}

	/**
	 * Determines the length of the response body (RFC 9112 §6.3).
	 */
	private function attachBody(Connection $connection, RequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$status = $response->getStatusCode();

		if ($request->getMethod() === 'HEAD' || $status === 204 || $status === 304) {
			$connection->close();

			return $response->withBody(ResponseBody::empty());
		}

		if ($response->hasHeader('Transfer-Encoding')) {
			if ($response->getProtocolVersion() === '1.0') {
				throw new RuntimeException('Received an HTTP/1.0 response with Transfer-Encoding');
			}

			$codings = array_map('trim', explode(',', strtolower($response->getHeaderLine('Transfer-Encoding'))));

			if (end($codings) !== 'chunked') {
				return $response->withBody(ResponseBody::untilClose($connection, $this->bufferSize));
			}

			// the chunked coding is removed while reading, keep the headers consistent (RFC 9112 §7.1.3)
			$codings = array_slice($codings, 0, -1);
			$response = $codings === []
				? $response->withoutHeader('Transfer-Encoding')
				: $response->withHeader('Transfer-Encoding', implode(', ', $codings));

			return $response
				->withoutHeader('Content-Length')
				->withBody(ResponseBody::chunked($connection, $this->bufferSize));
		}

		if ($response->hasHeader('Content-Length')) {
			$length = $this->parseContentLength($response->getHeaderLine('Content-Length'));

			if ($length === null) {
				throw new RuntimeException('Received an invalid Content-Length');
			}

			return $response
				->withHeader('Content-Length', (string) $length)
				->withBody(ResponseBody::sized($connection, $length, $this->bufferSize));
		}

		return $response->withBody(ResponseBody::untilClose($connection, $this->bufferSize));
	}

	/**
	 * Accepts a single non-negative integer, or a list of identical ones (RFC 9112 §6.3).
	 */
	private function parseContentLength(string $value): ?int
	{
		$values = array_unique(array_map('trim', explode(',', $value)));

		if (count($values) !== 1) {
			return null;
		}

		$value = reset($values);

		if (!ctype_digit($value)) {
			return null;
		}

		// rejects values that would overflow
		$length = filter_var(ltrim($value, '0') ?: '0', FILTER_VALIDATE_INT);

		return $length === false ? null : $length;
	}
}
