# HTTP Response Test Harness

A lightweight, single-file PHP service for generating deliberate HTTP responses for testing monitors, clients, retry logic, redirects, rate limiting, error handling, content negotiation and other HTTP-aware software.

It is inspired by Aaron Powell's excellent [httpstat.us](https://github.com/aaronpowell/httpstatus) project, taking the same wonderfully simple idea:

```text
/200
/404
/500
```

and extending it with dynamic headers, randomised values, weighted random responses, configurable delays, multiple response representations, obscure and historical status codes, and a few Easter eggs.

## Features

* Single-file PHP application
* Clean status-code URLs such as `/404` and `/503`
* Standard, historical, obsolete and commonly encountered non-standard HTTP status codes
* Random responses
* Weighted random responses
* Random status classes such as `2xx`, `4xx` and `5xx`
* Realistic status-specific headers
* Randomised values such as:

  * `Retry-After`
  * `Location`
  * `ETag`
  * `Last-Modified`
  * rate-limit values
  * authentication realms
  * request IDs
  * content ranges
* Repeated HTTP headers where appropriate
* Configurable response delays
* Optional body suppression
* Browser-based User's Guide and URL builder
* Human and machine-readable output
* Easter eggs for selected status codes
* No framework, database or container required

## Requirements

* PHP 8.1 or later recommended
* Apache 2.4+
* `mod_rewrite`
* `mod_headers` recommended but not required

The application itself is contained in:

```text
index.php
```

An accompanying `.htaccess` provides clean URLs.

## Installation

Copy the files into a directory served by Apache:

```text
index.php
.htaccess
```

Then visit the root URL in a browser:

```text
https://example.com/
```

The root page provides documentation, the complete status catalogue and an interactive test URL builder.

## Basic Usage

Request a specific HTTP status:

```text
/200
/301
/404
/418
/420
/429
/500
/503
```

For example:

```text
https://example.com/503
```

returns an HTTP `503 Service Unavailable` response with appropriate dynamically generated metadata.

## Random Responses

Return a random status from the available catalogue:

```text
/random
```

Choose from a specific set:

```text
/random/200,404,500
```

Repeated values provide weighting:

```text
/random/200,200,200,500
```

In that example, `200` is three times as likely to be selected as `500`.

Status classes can also be used:

```text
/random/2xx,4xx,5xx
```

or mixed with specific values:

```text
/random/2xx,429,503
```

## Delays

Some status codes, particularly timeout-related responses, have a small random delay by default.

Override the delay with the `delay` query parameter.

Disable delay:

```text
/504?delay=0
```

Force a 1500 ms delay:

```text
/200?delay=1500
```

Use a random delay:

```text
/200?delay=random
```

Explicit delays are capped to prevent excessively long-running requests.

## Response Bodies

By default, status endpoints return a useful response body.

Suppress it with:

```text
/404?body=0
```

`HEAD` requests also return no body, as expected.

## Dynamic Headers

Where a status code has meaningful associated headers, the harness generates realistic values.

For example, a `429 Too Many Requests` response may include:

```http
Retry-After: 173
X-RateLimit-Limit: 2500
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1786879421
```

The values change between requests so clients can be tested against the values they actually receive rather than a hard-coded fixture.

Other examples include:

* redirects with varying `Location` values
* changing ETags
* varying `Last-Modified` timestamps
* random authentication realms
* repeated `WWW-Authenticate` headers
* multiple `Set-Cookie` headers
* changing byte ranges
* dynamically generated request IDs

## Content Negotiation

The service is intended to support multiple response representations based on the HTTP `Accept` header.

Useful formats include:

```text
text/html
application/json
text/markdown
text/plain
```

This makes the service useful for browsers, command-line tools, monitoring systems, APIs and agentic or LLM-based clients.

An explicit format override may also be used where supported:

```text
/429?format=json
/429?format=html
/429?format=markdown
/429?format=text
```

When representations vary by `Accept`, responses should include:

```http
Vary: Accept
```

## Examples

### curl

```bash
curl -i https://example.com/429
```

Request JSON:

```bash
curl -i \
  -H "Accept: application/json" \
  https://example.com/429
```

Request Markdown:

```bash
curl -i \
  -H "Accept: text/markdown" \
  https://example.com/429
```

Test a randomly failing service:

```bash
curl -i \
  https://example.com/random/200,200,200,500
```

Test delayed responses:

```bash
curl -i \
  https://example.com/504?delay=random
```

## Monitoring Tests

The harness is useful for testing software such as:

* Uptime Kuma
* UptimeRobot
* reverse proxies
* API clients
* retry handlers
* load balancers
* health checks
* monitoring systems
* HTTP libraries
* automated tests

For example, this URL represents a service that is normally healthy but occasionally fails:

```text
/random/200,200,200,200,500
```

This can be useful for testing retry thresholds, state transitions and alerting behaviour.

## Status Catalogue

The browser interface includes a catalogue of supported status codes with links to appropriate reference material.

The catalogue includes:

* registered IANA HTTP status codes
* deprecated or obsolete codes
* WebDAV extensions
* historical codes
* nginx-specific codes
* Microsoft/IIS codes
* Cloudflare codes
* AWS codes
* other implementation-specific values encountered in the wild

Unassigned three-digit codes can also be requested directly for client-behaviour testing.

For example:

```text
/430
/550
```

Clients should generally handle unknown status codes according to their status class.

## Easter Eggs

A handful of unusual status codes contain deliberately silly response messages.

They are intended to reward curiosity without changing the status semantics being tested.

Some are stateful, so repeated requests may not produce the same body every time.

No spoilers here.

## Limitations

This is an application-level HTTP response simulator, not a complete network protocol simulator.

Some behaviours cannot be reproduced faithfully from PHP behind a conventional web server.

Examples include:

* `1xx` informational responses normally preceding a final response
* nginx `444`, which normally closes the connection without sending an HTTP response
* real TCP connection failures
* genuine TLS handshake failures
* network-level timeouts
* malformed HTTP framing

For those cases, the harness can reproduce the status value or approximate behaviour, but not necessarily the exact wire-level condition.

## Security

This tool deliberately generates unusual and failing HTTP responses.

It should not be treated as an authentication, proxy or security service.

If exposing it publicly:

* keep PHP and Apache patched
* avoid adding arbitrary user-controlled response headers without validation
* cap delays and response sizes
* do not allow arbitrary file access
* consider rate limiting if the endpoint attracts significant automated traffic

## Project Structure

```text
.
├── index.php
└── .htaccess
```

That's it.

No Composer install.

No Node.js.

No database.

No container required.

Even the .htaccess is optional. Don't upload it? Don't already have one in this directory? The index.php file will create it for you!

## Credits

This project was inspired by [Aaron Powell's `httpstat.us` project](https://github.com/aaronpowell/httpstatus).

The original project popularised the simple and extremely useful pattern of requesting a URL containing an HTTP status code and receiving that status in response.

This PHP implementation is independent and adds its own behaviour, interface and response catalogue.

All rights to the original project remain with its respective author and contributors.

## References

Useful authoritative and implementation-specific references include:

* IANA HTTP Status Code Registry
* RFC 9110: HTTP Semantics
* RFC 2324: Hyper Text Coffee Pot Control Protocol
* WebDAV specifications
* nginx documentation and source
* Cloudflare HTTP status documentation
* vendor documentation for implementation-specific codes

Links to relevant references are also provided directly in the browser-based status catalogue.

## Licence

MIT Licence.
