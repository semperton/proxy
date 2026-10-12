<?php

declare(strict_types=1);

/**
 * Minimal raw HTTP server for the test suite.
 *
 * Prints its TCP port or Unix socket path, then answers each connection
 * with a canned (sometimes deliberately malformed) response chosen by path.
 *
 * Usage: php server.php [--tls <pem file with certificate and key> | --unix <socket path>]
 */

$mode = $argv[1] ?? '';
$option = $argv[2] ?? '';

$address = match ($mode) {
	'--tls' => 'tls://127.0.0.1:0',
	'--unix' => "unix://$option",
	default => 'tcp://127.0.0.1:0',
};

if ($mode === '--unix' && file_exists($option)) {
	unlink($option);
}

$context = stream_context_create($mode === '--tls' ? ['ssl' => ['local_cert' => $option]] : []);
$flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

$server = stream_socket_server($address, $errorCode, $errorMessage, $flags, $context);

if ($server === false) {
	fwrite(STDERR, "Unable to start server: $errorMessage\n");
	exit(1);
}

$name = (string) stream_socket_get_name($server, false);
echo ($mode === '--unix' ? $option : substr($name, strrpos($name, ':') + 1)) . "\n";

/**
 * @param resource $connection
 * @return array{string, string, string} method, path, raw request
 */
function readRequest($connection): array
{
	$head = '';

	while (($line = fgets($connection)) !== false) {
		$head .= $line;

		if ($line === "\r\n") {
			break;
		}
	}

	[$method, $path] = explode(' ', $head, 3) + ['', '', ''];
	$body = '';

	if (preg_match('/^Content-Length: *(\d+)/mi', $head, $match) === 1 && (int) $match[1] > 0) {
		$body = (string) stream_get_contents($connection, (int) $match[1]);
	} elseif (preg_match('/^Transfer-Encoding: *chunked/mi', $head) === 1) {
		do {
			$sizeLine = (string) fgets($connection);
			$size = (int) hexdec(trim($sizeLine));
			$body .= $sizeLine . ($size > 0 ? stream_get_contents($connection, $size) : '') . fgets($connection);
		} while ($size > 0);
	}

	return [$method, $path, $head . $body];
}

/**
 * The client sends nothing after its request, so a readable connection means it was closed.
 *
 * @param resource $connection
 */
function clientIsGone($connection): bool
{
	$read = [$connection];
	$write = $except = null;

	return @stream_select($read, $write, $except, 0) !== 0;
}

/**
 * Builds a 200 response with a Content-Length.
 */
function respond(string $fields, string $body): string
{
	return "HTTP/1.1 200 OK\r\n{$fields}Content-Length: " . strlen($body) . "\r\n\r\n" . $body;
}

// compressed responses, the payload matches DecodingTest::PAYLOAD
$payload = str_repeat('Hello compression! ', 2000);
$gzip = (string) gzencode($payload);

$responses = [
	'/gzip' => respond("Content-Encoding: gzip\r\n", $gzip),
	'/deflate' => respond("Content-Encoding: deflate\r\n", (string) gzcompress($payload)),
	'/gzip-chunked' => "HTTP/1.1 200 OK\r\nContent-Encoding: x-gzip\r\nTransfer-Encoding: chunked\r\n\r\n"
		. sprintf("%x\r\n%s\r\n", 100, substr($gzip, 0, 100))
		. sprintf("%x\r\n%s\r\n0\r\n\r\n", strlen($gzip) - 100, substr($gzip, 100)),
	'/gzip-truncated' => respond("Content-Encoding: gzip\r\n", substr($gzip, 0, -8)),
	'/gzip-corrupt' => respond("Content-Encoding: gzip\r\n", 'definitely not gzip'),
	'/brotli' => respond("Content-Encoding: br\r\n", 'raw brotli bytes'),
	'/chunked' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Length: 999\r\n\r\n"
		. "5;name=value\r\nHello\r\n8\r\n, World!\r\n0\r\nX-Trailer: yes\r\n\r\n",
	'/chunked-truncated' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nHello\r\n",
	'/chunked-gzip' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: gzip, chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n",
	'/gzip-close' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: gzip\r\n\r\nraw",
	'/http10-chunked' => "HTTP/1.0 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n",
	'/interim' => "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 103 Early Hints\r\nLink: </style.css>\r\n\r\n"
		. "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok",
	'/interim-max' => str_repeat("HTTP/1.1 100 Continue\r\n\r\n", 10) . "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok",
	'/interim-flood' => str_repeat("HTTP/1.1 100 Continue\r\n\r\n", 11) . "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok",
	'/huge-head' => "HTTP/1.1 200 OK\r\n" . str_repeat('X-Filler: ' . str_repeat('a', 1000) . "\r\n", 300) . "\r\n",
	'/switching' => "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\n\r\n",
	// bodiless responses are followed by junk that a correct client never reads
	'/no-content' => "HTTP/1.1 204 No Content\r\n\r\nIGNORED",
	'/not-modified' => "HTTP/1.1 304 Not Modified\r\nContent-Length: 10\r\n\r\nIGNORED",
	'/length' => "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nHello",
	'/duplicate-length' => "HTTP/1.1 200 OK\r\nContent-Length: 2, 2\r\n\r\nok",
	'/conflicting-length' => "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nContent-Length: 3\r\n\r\nok",
	'/invalid-length' => "HTTP/1.1 200 OK\r\nContent-Length: -1\r\n\r\nok",
	'/overflow-length' => "HTTP/1.1 200 OK\r\nContent-Length: 99999999999999999999\r\n\r\nok",
	'/truncated' => "HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\nshort",
	'/close-delimited' => "HTTP/1.0 200 OK\r\n\r\nuntil close",
	'/headers' => "HTTP/1.1 404 Not Found\n X-Ignored: first\nX-Folded: foo\r\n \t bar\r\nset-cookie: a=1\r\nSet-Cookie: b=2\r\n"
		. "Bad Name: x\r\nno colon\r\nX-Empty:\r\nContent-Length: 0\r\n\r\n",
	'/malformed' => "HTTP/1.1 OK\r\n\r\n",
	'/http2' => "HTTP/2 200\r\n\r\n",
	'/incomplete-head' => "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n",
];

while (true) {
	$connection = @stream_socket_accept($server, -1);

	if ($connection === false) {
		continue;
	}

	[$method, $path, $request] = readRequest($connection);

	// waits up to 0.5 s before answering, or until the client gives up
	for ($i = 0; $path === '/slow' && $i < 10 && !clientIsGone($connection); $i++) {
		usleep(50_000);
	}

	// sends one byte every 25 ms, within any idle timeout, for 0.5 s in total
	if ($path === '/drip' || $path === '/drip-head') {
		@fwrite($connection, $path === '/drip' ? "HTTP/1.1 200 OK\r\nContent-Length: 20\r\n\r\n" : "HTTP/1.1 200 OK\r\nX-Slow: ");

		// stops early when the client has given up, so the next test is not delayed
		for ($i = 0; $i < 20 && !clientIsGone($connection) && @fwrite($connection, 'x') !== false; $i++) {
			usleep(25_000);
		}

		@fwrite($connection, $path === '/drip' ? '' : "\r\nContent-Length: 0\r\n\r\n");
		fclose($connection);

		continue;
	}

	$response = $responses[$path]
		?? "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($request) . "\r\n\r\n"
		. ($method === 'HEAD' ? '' : $request);

	@fwrite($connection, $response);
	fclose($connection);
}
