# HTTP Response Test Harness

A lightweight, single-file PHP service for generating deliberate HTTP responses for testing monitors, clients, retry logic, redirects, rate limiting, error handling, content negotiation, sequence handling and other HTTP-aware software.

The project is inspired by Aaron Powell's [httpstatus](https://github.com/aaronpowell/httpstatus) project and retains the same wonderfully simple core idea:

```text
/200
/404
/500
```

This implementation extends that idea with dynamic headers, randomised values, weighted random responses, deterministic response sequences, content negotiation, configurable delays, obscure and historical status codes, and a few Easter eggs.

## Features

- Single-file PHP application
- Clean status-code URLs such as `/404` and `/503`
- Deterministic repeating response sequences
- Random responses
- Weighted random responses by repetition or multiplier
- Random status classes such as `2xx`, `4xx` and `5xx`
- Realistic status-specific headers
- Randomised values such as:
  - `Retry-After`
  - `Location`
  - `ETag`
  - `Last-Modified`
  - rate-limit values
  - authentication realms
  - request IDs
  - content ranges
- Repeated HTTP headers where appropriate
- Configurable response delays
- Optional body suppression
- Content negotiation using `Accept`
- HTML, JSON, Markdown and plain-text representations
- Browser-based User's Guide and URL builder
- Standard, obsolete, historical and commonly encountered non-standard status codes
- Reference links in the browser status catalogue
- Easter eggs for selected status codes
- Automatic `.htaccess` creation when possible
- No framework, database, Composer install, Node.js runtime or container required

## Requirements

- PHP 8.1 or later recommended
- Apache 2.4+
- `mod_rewrite`
- `mod_headers` recommended but not required

## Project Structure

```text
.
├── index.php
├── .htaccess
├── README.md
└── LICENSE
```

The application logic is entirely contained in `index.php`.

## Installation

Copy the project files into a directory served by Apache.

At minimum, `index.php` is required.

When `index.php` starts, it checks whether `.htaccess` exists. If it does not exist and the directory is writable, the application creates the recommended rewrite configuration automatically.

An existing `.htaccess` is never overwritten.

Then visit the root URL in a browser:

```text
https://example.com/
```

The root page provides the User's Guide, complete status catalogue, reference links, installation status and an interactive URL builder.

## Fixed Responses

Request a status code directly:

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

returns `503 Service Unavailable` along with status-appropriate test metadata.

## Deterministic Sequences

A comma-separated list without the `/random/` prefix walks through the supplied statuses in order:

```text
/200,404,200,302
```

Successive requests from the same session return:

```text
Request 1 -> 200
Request 2 -> 404
Request 3 -> 200
Request 4 -> 302
Request 5 -> 200
```

The sequence loops indefinitely.

Sequence state is tracked per client session and per sequence definition, so different sequences maintain independent positions.

Reset a sequence:

```text
/200,404,200,302?reset=1
```

Sequence responses expose diagnostic headers:

```text
X-Test-Sequence: 200,404,200,302
X-Test-Sequence-Position: 2
X-Test-Sequence-Length: 4
X-Test-Sequence-Loop: 3
X-Test-Sequence-Request: 10
X-Test-Sequence-Next: 200
```

`X-Test-Sequence-Loop` is one-based. The first pass is loop `1`.

## Random Responses

Return a random status from the available catalogue:

```text
/random
```

Choose from a specific set:

```text
/random/200,404,500
```

Status classes can also be used:

```text
/random/2xx,4xx,5xx
```

## Weighted Random Responses

### Repetition

Repeated values increase their probability:

```text
/random/200,200,404
```

This gives:

```text
200 -> 2/3 probability
404 -> 1/3 probability
```

### Multipliers

The same weighting can be written more compactly:

```text
/random/200x2,404
```

The two URLs above are equivalent.

Multipliers become particularly useful for realistic distributions:

```text
/random/200x95,404x3,500x2
```

This represents:

```text
200 -> 95%
404 -> 3%
500 -> 2%
```

Numeric status multipliers are supported up to `x10000`.

Status classes such as `2xx` can be repeated for weighting:

```text
/random/2xx,2xx,5xx
```

## Delays

Some timeout-related status codes have a random simulated delay by default.

Disable delay:

```text
/504?delay=0
```

Force a delay in milliseconds:

```text
/200?delay=1500
```

Request a random delay:

```text
/200?delay=random
```

Random delay mode chooses a value between 50 and 5000 ms.

Explicit delays are capped at 30 seconds.

## Response Bodies

Status endpoints return a useful response body by default.

Suppress the body:

```text
/404?body=0
```

`HEAD` requests also return no body.

## Content Negotiation

The service examines the request's `Accept` header and can return:

```text
text/html
application/json
text/markdown
text/plain
```

Examples:

```bash
curl -i \
  -H "Accept: application/json" \
  https://example.com/429
```

```bash
curl -i \
  -H "Accept: text/markdown" \
  https://example.com/429
```

```bash
curl -i \
  -H "Accept: text/plain" \
  https://example.com/429
```

Normal browser navigation prefers HTML.

Clients sending `Accept: */*` receive JSON by default.

Responses include:

```text
Vary: Accept
```

so HTTP caches can distinguish representations correctly.

### Explicit Format Override

The requested representation can also be forced in the URL:

```text
/429?format=json
/429?format=html
/429?format=markdown
/429?format=text
```

The explicit `format` parameter takes precedence over the `Accept` header.

## Dynamic Headers

Where a status code has meaningful associated headers, the harness generates realistic values.

For example, a `429 Too Many Requests` response may include:

```text
Retry-After: 173
X-RateLimit-Limit: 2500
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1786879421
```

These values vary between requests so clients can be tested against the values they actually receive instead of fixed fixtures.

Other dynamic examples include:

- redirects with varying `Location` values
- changing ETags
- varying `Last-Modified` timestamps
- random authentication realms
- repeated `WWW-Authenticate` headers
- multiple `Set-Cookie` headers
- changing byte ranges
- dynamically generated request IDs
- rate-limit reset times
- timeout delays

## Diagnostic Headers

Responses include test metadata such as:

```text
X-Test-Status-Code
X-Test-Request-ID
X-Test-Delay-Ms
```

Sequence responses additionally include:

```text
X-Test-Sequence
X-Test-Sequence-Position
X-Test-Sequence-Length
X-Test-Sequence-Loop
X-Test-Sequence-Request
X-Test-Sequence-Next
```

## Query Options

| Option | Example | Behaviour |
|---|---|---|
| `delay=0` | `/504?delay=0` | Disable any simulated delay |
| `delay=1500` | `/200?delay=1500` | Force a delay in milliseconds |
| `delay=random` | `/200?delay=random` | Random 50-5000 ms delay |
| `body=0` | `/404?body=0` | Suppress the response body |
| `format=json` | `/429?format=json` | Force JSON |
| `format=html` | `/429?format=html` | Force HTML |
| `format=markdown` | `/429?format=markdown` | Force Markdown |
| `format=text` | `/429?format=text` | Force plain text |
| `reset=1` | `/200,404?reset=1` | Reset a deterministic sequence |

## Monitoring Tests

The harness is useful for testing software such as:

- Uptime Kuma
- UptimeRobot
- reverse proxies
- API clients
- retry handlers
- load balancers
- health checks
- monitoring systems
- HTTP libraries
- automated tests
- agentic and LLM-based clients

For example, a service that is normally healthy but occasionally fails:

```text
/random/200x95,500x5
```

A deterministic flap:

```text
/200,200,500,500,200
```

A retry-after test:

```text
/429
```

A timeout-style test:

```text
/504?delay=random
```

## Status Catalogue

The browser interface includes a catalogue of supported status codes with links to relevant reference material.

The catalogue includes:

- IANA-registered HTTP status codes
- deprecated and obsolete codes
- WebDAV extensions
- historical codes
- nginx-specific codes
- Microsoft/IIS codes
- Cloudflare codes
- AWS codes
- other implementation-specific values encountered in the wild

Unassigned three-digit codes can also be requested directly:

```text
/430
/550
```

This is useful for checking that clients handle unknown status codes according to their status class.

## Easter Eggs

A handful of unusual status codes contain deliberately silly response messages.

Some are stateful, so repeated requests may not produce the same body every time.

The Easter eggs do not change the HTTP status semantics being tested.

No spoilers here.

## Limitations

This is an application-level HTTP response simulator, not a complete network protocol simulator.

Some behaviours cannot be reproduced faithfully from PHP behind a conventional web server.

Examples include:

- `1xx` informational responses normally preceding a final response
- nginx `444`, which normally closes the connection without sending an HTTP response
- genuine TCP connection failures
- real TLS handshake failures
- network-level timeouts
- malformed HTTP framing

For those cases, the harness can reproduce the status value or approximate behaviour, but not necessarily the exact wire-level condition.

## Security

This tool deliberately generates unusual and failing HTTP responses.

If exposing it publicly:

- keep PHP and Apache patched
- do not add arbitrary unvalidated response headers
- cap delays and response sizes
- do not expose arbitrary files
- consider rate limiting if the service attracts significant automated traffic

The included implementation caps explicit delays and does not provide arbitrary header injection.

## Credits

This project was inspired by Aaron Powell's `httpstatus` project:

https://github.com/aaronpowell/httpstatus

The original project popularised the simple and extremely useful pattern of requesting a URL containing an HTTP status code and receiving that status in response.

This PHP implementation is independent and adds its own behaviour, interface, response catalogue, sequencing, weighting, content negotiation and Easter eggs.

The original project is also distributed under the MIT License.

## References

Useful reference sources include:

- IANA HTTP Status Code Registry
- RFC 9110: HTTP Semantics
- RFC 2324: Hyper Text Coffee Pot Control Protocol
- WebDAV specifications
- nginx source and documentation
- Cloudflare HTTP status documentation
- vendor documentation for implementation-specific codes

Relevant reference links are provided directly in the browser-based status catalogue.

## Licence

This project is licensed under the MIT License.

See [LICENSE](LICENSE) for details.
