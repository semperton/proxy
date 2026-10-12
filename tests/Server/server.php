<?php

declare(strict_types=1);

/**
 * Minimal raw HTTP server for the test suite.
 *
 * Prints the port it listens on, then answers each connection
 * with a canned (sometimes deliberately malformed) response chosen by path.
 */

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

if ($server === false) {
	fwrite(STDERR, "Unable to start server: $errorMessage\n");
	exit(1);
}

$address = (string) stream_socket_get_name($server, false);
echo substr($address, strrpos($address, ':') + 1) . "\n";

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

$responses = [
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

	if ($path === '/slow') {
		usleep(500_000);
	}

	$response = $responses[$path]
		?? "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($request) . "\r\n\r\n"
		. ($method === 'HEAD' ? '' : $request);

	@fwrite($connection, $response);
	fclose($connection);
}
