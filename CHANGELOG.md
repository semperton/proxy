# Changelog

## 1.0.0

### Breaking changes

- Requires PHP 8.1 or later. Supports `psr/http-message` 1.1 and 2.0.
- `NetworkException` and `RequestException` now extend the abstract `ClientException`. All three are final or abstract, and `ExceptionTrait` was removed.
- `Semperton\Proxy\Stream` was removed. Response bodies are an internal `StreamInterface` implementation.
- `Content-Length` and `Transfer-Encoding: chunked` are removed from a response when its chunked body is decoded.
- Request bodies of unknown size are buffered so they can be sent with a `Content-Length`.
- The default buffer size changed from 4096 to 8192 bytes.
- The timeout now accepts fractions of a second (`float`).
- The default `User-Agent` no longer contains a version.

### Fixed

- Chunked responses are decoded (RFC 9112 §7.1). Before, the chunk framing was part of the body.
- Interim `1xx` responses are skipped, and the final response is returned (PSR-18).
- Responses to `HEAD`, and `204`/`304` responses, have an empty body.
- Truncated bodies are detected. Reading them throws a `RuntimeException` and no longer loops forever.
- Request bodies are written completely, including on partial socket writes.
- The `Host` header is always sent first. `POST`, `PUT` and `PATCH` requests send `Content-Length: 0` when the body is empty.
- Invalid status lines, invalid `Content-Length` values and faulty framing throw a `NetworkException`.
- Unsupported protocol versions, schemes, request targets, `CONNECT` and upgrades throw a `RequestException`.
- All errors thrown by `sendRequest()` implement `ClientExceptionInterface`.
- Obsolete line folding in response headers is unfolded.
- Response bodies can be closed or detached safely more than once, and casting them to string never throws.
- The socket is closed when the TLS handshake fails.
- A timed-out read is reported as a timeout, not as a generic read error.

### Security

- Response heads are limited to 256 KiB, with lines of at most 64 KiB and at most 10 interim responses.
- A request body that does not match its `Content-Length` throws a `RequestException` and is not sent with wrong framing.

### Added

- `maxDuration` limits the total time of a request, including reading the response body.
- `unixSocket` connects to a Unix domain socket instead of the host of the request.
- `decodeContent` requests and decodes `gzip` and `deflate` compressed responses.
- php-cs-fixer, Psalm 6, PHPUnit 10–12 and GitHub Actions CI.
- An offline test suite that runs against a local raw socket server, including TLS with a generated certificate.
