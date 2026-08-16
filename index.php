<?php

declare(strict_types=1);

/**
 * HTTP Response Test Harness
 *
 * A lightweight single-file PHP test service inspired by Aaron Powell's
 * httpstat.us project:
 *   https://github.com/aaronpowell/httpstatus
 *
 * Original project copyright and licensing remain with their respective
 * authors. This implementation is an independent PHP reimplementation with
 * additional test behaviour, dynamic headers, randomised values and Easter eggs.
 *
 * Examples:
 *   /200
 *   /404
 *   /420
 *   /503
 *   /random
 *   /random/200,404,500
 *   /random/200,200,200,429,503
 *   /random/2xx,4xx,5xx
 *
 * Query options:
 *   ?delay=0
 *   ?delay=1500
 *   ?delay=random
 *   ?body=0
 */

session_start();

/* --------------------------------------------------------------------------
 * Helpers
 * ----------------------------------------------------------------------- */

function randomInt(int $min, int $max): int
{
    return random_int($min, $max);
}

function randomHex(int $bytes = 8): string
{
    return bin2hex(random_bytes($bytes));
}

function randomToken(int $bytes = 8): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function randomBool(): bool
{
    return random_int(0, 1) === 1;
}

function randomChoice(array $values): mixed
{
    return $values[array_rand($values)];
}

function randomHttpDate(int $minimumOffsetSeconds = -86400, int $maximumOffsetSeconds = 86400): string
{
    return gmdate(
        'D, d M Y H:i:s',
        time() + randomInt($minimumOffsetSeconds, $maximumOffsetSeconds)
    ) . ' GMT';
}

function randomFutureHttpDate(int $minimumSeconds = 10, int $maximumSeconds = 600): string
{
    return gmdate(
        'D, d M Y H:i:s',
        time() + randomInt($minimumSeconds, $maximumSeconds)
    ) . ' GMT';
}

function randomRetryAfter(int $minimumSeconds = 5, int $maximumSeconds = 300): string
{
    if (randomBool()) {
        return (string) randomInt($minimumSeconds, $maximumSeconds);
    }

    return randomFutureHttpDate($minimumSeconds, $maximumSeconds);
}

function randomEtag(): string
{
    $tag = randomHex(8);

    return randomBool()
        ? '"' . $tag . '"'
        : 'W/"' . $tag . '"';
}

function randomRealm(): string
{
    return randomChoice([
        'Test Area',
        'Test API',
        'Restricted',
        'Development',
        'Status Harness',
    ]) . '-' . randomInt(1, 999);
}

function randomRedirectLocation(): string
{
    return '/' . randomChoice([200, 201, 202, 204, 418, 420])
        . '?redirected=' . randomToken(6);
}

function randomRateLimit(): int
{
    return randomInt(10, 5000);
}

function get420Body(): string
{
    $lines = [
        "Who is it?",
        "Dave's not here, man.",
    ];

    $index = $_SESSION['status_420_index'] ?? 0;
    $body = $lines[$index % count($lines)];
    $_SESSION['status_420_index'] = $index + 1;

    return $body;
}

function get444Body(): string
{
    $count = $_SESSION['status_444_count'] ?? 0;
    $_SESSION['status_444_count'] = $count + 1;

    return $count === 0
        ? 'No'
        : "I said 'No'!";
}

function resolveValue(mixed $value): mixed
{
    return $value instanceof Closure ? $value() : $value;
}

function resolveHeaders(array $definition): array
{
    $resolved = [];

    foreach ($definition['headers'] ?? [] as $name => $values) {
        $values = is_array($values) ? $values : [$values];

        foreach ($values as $value) {
            $resolved[$name][] = (string) resolveValue($value);
        }
    }

    return $resolved;
}

function emitHeaders(array $headers): void
{
    foreach ($headers as $name => $values) {
        foreach ($values as $value) {
            header($name . ': ' . $value, false);
        }
    }
}

function resolveDelay(array $definition): int
{
    if (isset($_GET['delay'])) {
        $requested = strtolower((string) $_GET['delay']);

        if ($requested === '0' || $requested === 'none') {
            return 0;
        }

        if ($requested === 'random') {
            return randomInt(50, 5000);
        }

        if (ctype_digit($requested)) {
            return min((int) $requested, 30000);
        }
    }

    if (!isset($definition['delay'])) {
        return 0;
    }

    [$min, $max] = $definition['delay'];

    return randomInt($min, $max);
}

function expandSelector(string $selector, array $statuses): array
{
    $selector = strtolower(trim($selector));

    if (preg_match('/^[1-9][0-9]{2}$/', $selector)) {
        return [(int) $selector];
    }

    if (preg_match('/^([1-5])xx$/', $selector, $matches)) {
        $class = (int) $matches[1];

        return array_values(array_filter(
            array_keys($statuses),
            fn(int $code): bool => intdiv($code, 100) === $class
        ));
    }

    return [];
}

function chooseRandomStatus(?string $selector, array $statuses): int
{
    if ($selector === null || trim($selector) === '') {
        $candidates = array_values(array_filter(
            array_keys($statuses),
            fn(int $code): bool => $code >= 200 && $code <= 599
        ));

        return randomChoice($candidates);
    }

    $candidates = [];

    foreach (explode(',', $selector) as $part) {
        foreach (expandSelector($part, $statuses) as $code) {
            $candidates[] = $code;
        }
    }

    return $candidates === [] ? 400 : randomChoice($candidates);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* --------------------------------------------------------------------------
 * Status catalogue
 * ----------------------------------------------------------------------- */

$statuses = [

    100 => [
        'reason' => 'Continue',
        'standard' => 'IANA',
    ],

    101 => [
        'reason' => 'Switching Protocols',
        'standard' => 'IANA',
        'headers' => [
            'Connection' => 'Upgrade',
            'Upgrade' => fn() => randomChoice([
                'websocket',
                'h2c',
                'test-protocol/' . randomInt(1, 9),
            ]),
        ],
    ],

    102 => [
        'reason' => 'Processing',
        'standard' => 'IANA / WebDAV',
    ],

    103 => [
        'reason' => 'Early Hints',
        'standard' => 'IANA',
        'headers' => [
            'Link' => [
                '</assets/app.css>; rel=preload; as=style',
                '</assets/app.js>; rel=preload; as=script',
            ],
        ],
    ],

    104 => [
        'reason' => 'Upload Resumption Supported',
        'standard' => 'IANA temporary registration',
        'headers' => [
            'Upload-Limit' => fn() => 'max-size=' . randomInt(1048576, 1073741824),
        ],
    ],

    200 => [
        'reason' => 'OK',
        'standard' => 'IANA',
        'headers' => [
            'ETag' => fn() => randomEtag(),
            'Last-Modified' => fn() => randomHttpDate(-604800, -1),
            'Set-Cookie' => [
                fn() => 'test_a=' . randomToken(6) . '; Path=/; HttpOnly; SameSite=Lax',
                fn() => 'test_b=' . randomToken(6) . '; Path=/; Secure; SameSite=None',
            ],
        ],
    ],

    201 => [
        'reason' => 'Created',
        'standard' => 'IANA',
        'headers' => [
            'Location' => fn() => '/resource/' . randomInt(1000, 999999),
            'ETag' => fn() => randomEtag(),
        ],
    ],

    202 => [
        'reason' => 'Accepted',
        'standard' => 'IANA',
        'headers' => [
            'Location' => fn() => '/jobs/' . randomToken(8),
            'Retry-After' => fn() => randomRetryAfter(1, 30),
        ],
    ],

    203 => [
        'reason' => 'Non-Authoritative Information',
        'standard' => 'IANA',
        'headers' => [
            'Via' => fn() => '1.1 proxy-' . randomInt(1, 20) . '.example.test',
        ],
    ],

    204 => [
        'reason' => 'No Content',
        'standard' => 'IANA',
        'noBody' => true,
        'headers' => [
            'ETag' => fn() => randomEtag(),
        ],
    ],

    205 => [
        'reason' => 'Reset Content',
        'standard' => 'IANA',
        'noBody' => true,
    ],

    206 => [
        'reason' => 'Partial Content',
        'standard' => 'IANA',
        'headers' => [
            'Accept-Ranges' => 'bytes',
            'Content-Range' => function (): string {
                $total = randomInt(10000, 10000000);
                $start = randomInt(0, (int) ($total / 2));
                $length = randomInt(1, min(65536, $total - $start));
                $end = $start + $length - 1;

                return "bytes {$start}-{$end}/{$total}";
            },
        ],
    ],

    207 => [
        'reason' => 'Multi-Status',
        'standard' => 'IANA / WebDAV',
        'headers' => [
            'Content-Type' => 'application/xml; charset=utf-8',
        ],
    ],

    208 => [
        'reason' => 'Already Reported',
        'standard' => 'IANA / WebDAV',
        'headers' => [
            'Content-Type' => 'application/xml; charset=utf-8',
        ],
    ],

    218 => [
        'reason' => 'This Is Fine',
        'standard' => 'Non-standard / Apache',
        'body' => "This is fine.",
    ],

    226 => [
        'reason' => 'IM Used',
        'standard' => 'IANA',
        'headers' => [
            'IM' => 'feed',
            'ETag' => fn() => randomEtag(),
        ],
    ],

    300 => [
        'reason' => 'Multiple Choices',
        'standard' => 'IANA',
        'headers' => [
            'Location' => fn() => randomRedirectLocation(),
        ],
    ],

    301 => [
        'reason' => 'Moved Permanently',
        'standard' => 'IANA',
        'headers' => [
            'Location' => fn() => randomRedirectLocation(),
            'Cache-Control' => fn() => 'public, max-age=' . randomInt(300, 86400),
        ],
    ],

    302 => [
        'reason' => 'Found',
        'standard' => 'IANA',
        'headers' => [
            'Location' => fn() => randomRedirectLocation(),
            'Cache-Control' => 'no-store',
        ],
    ],

    303 => [
        'reason' => 'See Other',
        'standard' => 'IANA',
        'headers' => [
            'Location' => fn() => randomRedirectLocation(),
        ],
    ],

    304 => [
        'reason' => 'Not Modified',
        'standard' => 'IANA',
        'noBody' => true,
        'headers' => [
            'ETag' => fn() => randomEtag(),
            'Last-Modified' => fn() => randomHttpDate(-604800, -60),
            'Cache-Control' => fn() => 'public, max-age=' . randomInt(60, 86400),
        ],
    ],

    305 => [
        'reason' => 'Use Proxy',
        'standard' => 'IANA / deprecated',
        'headers' => [
            'Location' => fn() => 'http://proxy-' . randomInt(1, 20) . '.example.test:' . randomInt(8000, 8999),
        ],
    ],

    306 => [
        'reason' => 'Switch Proxy',
        'standard' => 'Unused / historical',
    ],

    307 => [
        'reason' => 'Temporary Redirect',
        'standard' => 'IANA',
        'headers' => [
            'Location' => fn() => randomRedirectLocation(),
            'Cache-Control' => 'no-store',
        ],
    ],

    308 => [
        'reason' => 'Permanent Redirect',
        'standard' => 'IANA',
        'headers' => [
            'Location' => fn() => randomRedirectLocation(),
            'Cache-Control' => fn() => 'public, max-age=' . randomInt(300, 86400),
        ],
    ],

    400 => [
        'reason' => 'Bad Request',
        'standard' => 'IANA',
    ],

    401 => [
        'reason' => 'Unauthorized',
        'standard' => 'IANA',
        'headers' => [
            'WWW-Authenticate' => [
                fn() => 'Basic realm="' . randomRealm() . '", charset="UTF-8"',
                fn() => 'Bearer realm="' . randomRealm() . '", error="invalid_token"',
            ],
        ],
    ],

    402 => [
        'reason' => 'Payment Required',
        'standard' => 'IANA / reserved semantics',
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => fn() => randomChoice([
            'You got my money?',
            'Where is my money?',
            'This request appears to have an outstanding balance.',
        ]),
    ],

    403 => [
        'reason' => 'Forbidden',
        'standard' => 'IANA',
    ],

    404 => [
        'reason' => 'Not Found',
        'standard' => 'IANA',
        'headers' => [
            'Cache-Control' => fn() => randomChoice([
                'no-store',
                'public, max-age=' . randomInt(30, 600),
            ]),
        ],
    ],

    405 => [
        'reason' => 'Method Not Allowed',
        'standard' => 'IANA',
        'headers' => [
            'Allow' => fn() => randomChoice([
                'GET, HEAD',
                'GET, HEAD, POST',
                'GET, HEAD, PUT, DELETE',
                'OPTIONS, GET, HEAD',
            ]),
        ],
    ],

    406 => [
        'reason' => 'Not Acceptable',
        'standard' => 'IANA',
        'headers' => [
            'Vary' => 'Accept, Accept-Encoding',
        ],
    ],

    407 => [
        'reason' => 'Proxy Authentication Required',
        'standard' => 'IANA',
        'headers' => [
            'Proxy-Authenticate' => [
                fn() => 'Basic realm="' . randomRealm() . '"',
                fn() => 'Bearer realm="' . randomRealm() . '"',
            ],
        ],
    ],

    408 => [
        'reason' => 'Request Timeout',
        'standard' => 'IANA',
        'delay' => [250, 2500],
        'headers' => [
            'Connection' => 'close',
        ],
    ],

    409 => [
        'reason' => 'Conflict',
        'standard' => 'IANA',
        'headers' => [
            'ETag' => fn() => randomEtag(),
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => fn() => randomChoice([
            'Do you want to fight about it?',
            "Them's fighting words.",
            'We appear to have a disagreement.',
            'Conflict detected. Choose your next words carefully.',
        ]),
    ],

    410 => [
        'reason' => 'Gone',
        'standard' => 'IANA',
        'headers' => [
            'Cache-Control' => fn() => 'public, max-age=' . randomInt(300, 86400),
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => "It was here. It isn't anymore.",
    ],

    411 => [
        'reason' => 'Length Required',
        'standard' => 'IANA',
    ],

    412 => [
        'reason' => 'Precondition Failed',
        'standard' => 'IANA',
        'headers' => [
            'ETag' => fn() => randomEtag(),
        ],
    ],

    413 => [
        'reason' => 'Content Too Large',
        'standard' => 'IANA',
        'headers' => [
            'Retry-After' => fn() => randomRetryAfter(10, 600),
        ],
    ],

    414 => [
        'reason' => 'URI Too Long',
        'standard' => 'IANA',
    ],

    415 => [
        'reason' => 'Unsupported Media Type',
        'standard' => 'IANA',
        'headers' => [
            'Accept' => fn() => randomChoice([
                'application/json',
                'application/xml',
                'text/plain',
            ]),
        ],
    ],

    416 => [
        'reason' => 'Range Not Satisfiable',
        'standard' => 'IANA',
        'headers' => [
            'Accept-Ranges' => 'bytes',
            'Content-Range' => fn() => 'bytes */' . randomInt(1000, 10000000),
        ],
    ],

    417 => [
        'reason' => 'Expectation Failed',
        'standard' => 'IANA',
    ],

    418 => [
        'reason' => "I'm a Teapot",
        'standard' => 'Historical / RFC 2324; IANA currently marks 418 unused',
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Teapot' => fn() => randomChoice([
                'short-and-stout',
                'brew-refused',
                'coffee-prohibited',
            ]),
        ],
        'body' => implode("\n", [
            'A little teapot sits here instead,',
            'Short, stout, and serving HTTP.',
            'Tip the request and pour it out:',
            'Coffee support is not included.',
        ]),
    ],

    419 => [
        'reason' => 'Page Expired',
        'standard' => 'Non-standard / Laravel',
        'headers' => [
            'Cache-Control' => 'no-store',
        ],
    ],

    420 => [
        'reason' => 'Enhance Your Calm',
        'standard' => 'Non-standard / historical Twitter',
        'variants' => [
            'Enhance Your Calm - historical Twitter API',
            'Method Failure - historical WebDAV/Spring usage',
        ],
        'headers' => [
            'Retry-After' => fn() => randomRetryAfter(10, 420),
            'X-RateLimit-Limit' => fn() => (string) randomRateLimit(),
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => fn() => (string) (time() + randomInt(30, 420)),
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => fn() => get420Body(),
    ],

    421 => [
        'reason' => 'Misdirected Request',
        'standard' => 'IANA',
    ],

    422 => [
        'reason' => 'Unprocessable Content',
        'standard' => 'IANA',
    ],

    423 => [
        'reason' => 'Locked',
        'standard' => 'IANA / WebDAV',
        'headers' => [
            'Lock-Token' => fn() => '<opaquelocktoken:' . randomHex(16) . '>',
        ],
    ],

    424 => [
        'reason' => 'Failed Dependency',
        'standard' => 'IANA / WebDAV',
    ],

    425 => [
        'reason' => 'Too Early',
        'standard' => 'IANA',
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => "That's what she said.",
    ],

    426 => [
        'reason' => 'Upgrade Required',
        'standard' => 'IANA',
        'headers' => [
            'Upgrade' => fn() => randomChoice([
                'HTTP/2.0',
                'HTTP/3.0',
                'websocket',
            ]),
            'Connection' => 'Upgrade',
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => fn() => randomChoice([
            'Old and busted: your client. New hotness: the required protocol.',
            'Your current protocol is old and busted. Please upgrade to the new hotness.',
        ]),
    ],

    428 => [
        'reason' => 'Precondition Required',
        'standard' => 'IANA',
        'headers' => [
            'Cache-Control' => 'no-store',
        ],
    ],

    429 => [
        'reason' => 'Too Many Requests',
        'standard' => 'IANA',
        'headers' => [
            'Retry-After' => fn() => randomRetryAfter(5, 300),
            'X-RateLimit-Limit' => fn() => (string) randomRateLimit(),
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => fn() => (string) (time() + randomInt(5, 300)),
        ],
    ],

    431 => [
        'reason' => 'Request Header Fields Too Large',
        'standard' => 'IANA',
    ],

    440 => [
        'reason' => 'Login Time-out',
        'standard' => 'Non-standard / Microsoft IIS',
        'headers' => [
            'Cache-Control' => 'no-store',
        ],
    ],

    444 => [
        'reason' => 'No Response',
        'standard' => 'Non-standard / nginx',
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => fn() => get444Body(),
    ],

    449 => [
        'reason' => 'Retry With',
        'standard' => 'Non-standard / Microsoft IIS',
        'headers' => [
            'Retry-After' => fn() => randomRetryAfter(1, 60),
        ],
    ],

    450 => [
        'reason' => 'Blocked by Windows Parental Controls',
        'standard' => 'Non-standard / Microsoft',
    ],

    451 => [
        'reason' => 'Unavailable For Legal Reasons',
        'standard' => 'IANA',
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Link' => fn() => '<https://authority-' . randomInt(1, 99) . '.example.test/>; rel="blocked-by"',
        ],
        'body' => 'Your request has been reported to Big Brother.',
    ],

    460 => [
        'reason' => 'Client Closed Connection',
        'standard' => 'Non-standard / AWS',
    ],

    463 => [
        'reason' => 'Too Many Forwarded IP Addresses',
        'standard' => 'Non-standard / AWS',
    ],

    494 => [
        'reason' => 'Request Header Too Large',
        'standard' => 'Non-standard / nginx',
    ],

    495 => [
        'reason' => 'SSL Certificate Error',
        'standard' => 'Non-standard / nginx',
    ],

    496 => [
        'reason' => 'SSL Certificate Required',
        'standard' => 'Non-standard / nginx',
    ],

    497 => [
        'reason' => 'HTTP Request Sent to HTTPS Port',
        'standard' => 'Non-standard / nginx',
        'headers' => [
            'Location' => fn() => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'),
        ],
    ],

    498 => [
        'reason' => 'Invalid Token',
        'standard' => 'Non-standard / ArcGIS',
        'headers' => [
            'WWW-Authenticate' => 'Bearer error="invalid_token"',
        ],
    ],

    499 => [
        'reason' => 'Client Closed Request',
        'standard' => 'Non-standard / nginx',
        'variants' => [
            'Client Closed Request - nginx',
            'Token Required - ArcGIS',
        ],
    ],

    500 => [
        'reason' => 'Internal Server Error',
        'standard' => 'IANA',
        'headers' => [
            'X-Request-ID' => fn() => randomHex(12),
        ],
    ],

    501 => [
        'reason' => 'Not Implemented',
        'standard' => 'IANA',
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => 'TODO',
    ],

    502 => [
        'reason' => 'Bad Gateway',
        'standard' => 'IANA',
        'headers' => [
            'Via' => fn() => '1.1 gateway-' . randomInt(1, 20) . '.example.test',
        ],
    ],

    503 => [
        'reason' => 'Service Unavailable',
        'standard' => 'IANA',
        'headers' => [
            'Retry-After' => fn() => randomRetryAfter(5, 600),
            'X-Request-ID' => fn() => randomHex(12),
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => 'Gone to lunch.',
    ],

    504 => [
        'reason' => 'Gateway Timeout',
        'standard' => 'IANA',
        'delay' => [250, 3000],
        'headers' => [
            'Via' => fn() => '1.1 gateway-' . randomInt(1, 20) . '.example.test',
        ],
    ],

    505 => [
        'reason' => 'HTTP Version Not Supported',
        'standard' => 'IANA',
    ],

    506 => [
        'reason' => 'Variant Also Negotiates',
        'standard' => 'IANA',
        'headers' => [
            'TCN' => 'list',
        ],
    ],

    507 => [
        'reason' => 'Insufficient Storage',
        'standard' => 'IANA / WebDAV',
    ],

    508 => [
        'reason' => 'Loop Detected',
        'standard' => 'IANA / WebDAV',
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => '508 Loop Detected. See: 508 Loop Detected.',
    ],

    509 => [
        'reason' => 'Bandwidth Limit Exceeded',
        'standard' => 'Non-standard',
        'headers' => [
            'Retry-After' => fn() => randomRetryAfter(60, 3600),
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => "You've used up all the internet.",
    ],

    510 => [
        'reason' => 'Not Extended',
        'standard' => 'IANA / obsolete',
    ],

    511 => [
        'reason' => 'Network Authentication Required',
        'standard' => 'IANA',
        'headers' => [
            'Location' => fn() => '/login?session=' . randomToken(8),
            'Cache-Control' => 'no-store',
        ],
    ],

    520 => [
        'reason' => 'Web Server Returned an Unknown Error',
        'standard' => 'Non-standard / Cloudflare',
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => "¯\\_(ツ)_/¯",
    ],

    521 => [
        'reason' => 'Web Server Is Down',
        'standard' => 'Non-standard / Cloudflare',
        'headers' => [
            'Content-Type' => 'text/plain; charset=utf-8',
        ],
        'body' => 'Have you tried turning it on?',
    ],

    522 => [
        'reason' => 'Connection Timed Out',
        'standard' => 'Non-standard / Cloudflare',
        'delay' => [250, 3000],
    ],

    523 => [
        'reason' => 'Origin Is Unreachable',
        'standard' => 'Non-standard / Cloudflare',
    ],

    524 => [
        'reason' => 'A Timeout Occurred',
        'standard' => 'Non-standard / Cloudflare',
        'delay' => [1000, 5000],
    ],

    525 => [
        'reason' => 'SSL Handshake Failed',
        'standard' => 'Non-standard / Cloudflare',
    ],

    526 => [
        'reason' => 'Invalid SSL Certificate',
        'standard' => 'Non-standard / Cloudflare',
    ],

    527 => [
        'reason' => 'Railgun Error',
        'standard' => 'Non-standard / historical Cloudflare',
    ],

    529 => [
        'reason' => 'Site Is Overloaded',
        'standard' => 'Non-standard',
        'headers' => [
            'Retry-After' => fn() => randomRetryAfter(5, 300),
            'X-Queue-Position' => fn() => (string) randomInt(10000, 999999),
        ],
    ],

    530 => [
        'reason' => 'Site Is Frozen',
        'standard' => 'Non-standard / Pantheon',
    ],

    540 => [
        'reason' => 'Temporarily Disabled',
        'standard' => 'Non-standard / Shopify',
        'headers' => [
            'Retry-After' => fn() => randomRetryAfter(30, 1800),
        ],
    ],

    561 => [
        'reason' => 'Unauthorized',
        'standard' => 'Non-standard / AWS',
        'headers' => [
            'WWW-Authenticate' => 'Bearer error="invalid_token"',
        ],
    ],

    598 => [
        'reason' => 'Network Read Timeout Error',
        'standard' => 'Non-standard / proxy convention',
        'delay' => [250, 3000],
    ],

    599 => [
        'reason' => 'Network Connect Timeout Error',
        'standard' => 'Non-standard / proxy convention',
        'delay' => [250, 3000],
    ],

    783 => [
        'reason' => 'Unexpected Token',
        'standard' => 'Non-standard / Shopify',
    ],

    999 => [
        'reason' => 'Request Denied',
        'standard' => 'Non-standard / LinkedIn',
    ],
];

/* --------------------------------------------------------------------------
 * Routing
 * ----------------------------------------------------------------------- */

$path = trim(
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '',
    '/'
);

/* --------------------------------------------------------------------------
 * Root guide
 * ----------------------------------------------------------------------- */

if ($path === '') {
    header('Content-Type: text/html; charset=utf-8');

    $baseUrl =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://'
        . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HTTP Response Test Harness</title>

    <style>
        :root {
            color-scheme: light dark;
            --bg: #111318;
            --panel: #1a1e26;
            --panel2: #222835;
            --text: #eef1f5;
            --muted: #aab2c0;
            --line: #343c4a;
            --accent: #72a7ff;
            --ok: #82d993;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.55;
        }

        main {
            max-width: 1120px;
            margin: 0 auto;
            padding: 40px 20px 80px;
        }

        h1, h2, h3 {
            line-height: 1.15;
        }

        h1 {
            margin-bottom: 8px;
            font-size: clamp(2rem, 4vw, 3.2rem);
        }

        h2 {
            margin-top: 44px;
        }

        a {
            color: var(--accent);
        }

        code, pre, input, select, button {
            font: inherit;
        }

        code {
            background: var(--panel2);
            padding: 2px 6px;
            border-radius: 5px;
        }

        pre {
            overflow-x: auto;
            padding: 16px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 10px;
        }

        .lead {
            color: var(--muted);
            max-width: 780px;
            font-size: 1.08rem;
        }

        .credit {
            margin-top: 18px;
            padding: 14px 16px;
            border-left: 4px solid var(--accent);
            background: var(--panel);
        }

        .builder {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 14px;
            padding: 20px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        .field {
            grid-column: span 6;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field.third {
            grid-column: span 4;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-size: .92rem;
        }

        input, select, button {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px 12px;
            background: var(--panel2);
            color: var(--text);
        }

        button {
            cursor: pointer;
            font-weight: 650;
        }

        button.primary {
            background: var(--accent);
            color: #07111f;
            border-color: transparent;
        }

        .output {
            display: flex;
            gap: 10px;
            align-items: stretch;
        }

        .output input {
            flex: 1;
        }

        .output button {
            width: auto;
            white-space: nowrap;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        th, td {
            text-align: left;
            vertical-align: top;
            padding: 9px 10px;
            border-bottom: 1px solid var(--line);
        }

        th {
            color: var(--muted);
            position: sticky;
            top: 0;
            background: var(--bg);
        }

        .muted {
            color: var(--muted);
        }

        .tag {
            display: inline-block;
            padding: 2px 7px;
            border: 1px solid var(--line);
            border-radius: 999px;
            font-size: .78rem;
            color: var(--muted);
        }

        @media (max-width: 760px) {
            .field,
            .field.third {
                grid-column: 1 / -1;
            }

            .output {
                flex-direction: column;
            }

            .output button {
                width: 100%;
            }
        }
    </style>
</head>

<body>
<main>

    <h1>HTTP Response Test Harness</h1>

    <p class="lead">
        Generate deliberate HTTP responses for testing monitors, clients, retry logic,
        redirects, rate limits, error handling and other HTTP-aware software.
    </p>

    <div class="credit">
        Inspired by and credited to
        <a href="https://github.com/aaronpowell/httpstatus" rel="noopener noreferrer">
            Aaron Powell's <strong>httpstat.us</strong> project
        </a>,
        which provides the original simple idea of requesting a status-code URL and
        receiving that status in response. This is an independent PHP implementation
        with additional dynamic headers, weighted random responses and test behaviour.
    </div>

    <h2>Quick start</h2>

    <pre><?= h($baseUrl) ?>/200
<?= h($baseUrl) ?>/404
<?= h($baseUrl) ?>/420
<?= h($baseUrl) ?>/503

<?= h($baseUrl) ?>/random
<?= h($baseUrl) ?>/random/200,404,500
<?= h($baseUrl) ?>/random/200,200,200,429,503
<?= h($baseUrl) ?>/random/2xx,4xx,5xx</pre>

    <p>
        Repeating a status in a random selector weights it. For example,
        <code>/random/200,200,200,500</code> makes 200 three times as likely as 500.
    </p>

    <h2>Build a test URL</h2>

    <div class="builder">

        <div class="field third">
            <label for="mode">Mode</label>
            <select id="mode">
                <option value="single">Single status</option>
                <option value="random">Random selection</option>
            </select>
        </div>

        <div class="field third" id="singleField">
            <label for="statusCode">Status code</label>
            <select id="statusCode">
                <?php foreach ($statuses as $code => $definition): ?>
                    <option value="<?= (int) $code ?>">
                        <?= (int) $code ?> <?= h($definition['reason']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field third" id="randomField" hidden>
            <label for="randomCodes">Random selector</label>
            <input
                id="randomCodes"
                type="text"
                value="200,200,200,429,503"
                placeholder="200,404,500 or 2xx,4xx,5xx"
            >
        </div>

        <div class="field third">
            <label for="delay">Delay</label>
            <select id="delay">
                <option value="">Status default</option>
                <option value="0">No delay</option>
                <option value="random">Random 50-5000 ms</option>
                <option value="250">250 ms</option>
                <option value="1000">1 second</option>
                <option value="2500">2.5 seconds</option>
                <option value="5000">5 seconds</option>
            </select>
        </div>

        <div class="field third">
            <label for="body">Response body</label>
            <select id="body">
                <option value="">Normal</option>
                <option value="0">Suppress body</option>
            </select>
        </div>

        <div class="field third">
            <label>&nbsp;</label>
            <button class="primary" id="testButton" type="button">Open test URL</button>
        </div>

        <div class="field full">
            <label for="resultUrl">Generated URL</label>
            <div class="output">
                <input id="resultUrl" readonly>
                <button id="copyButton" type="button">Copy</button>
            </div>
        </div>

    </div>

    <h2>Query controls</h2>

    <table>
        <thead>
        <tr>
            <th>Parameter</th>
            <th>Example</th>
            <th>Behaviour</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><code>delay</code></td>
            <td><code>?delay=1500</code></td>
            <td>Force a delay in milliseconds, capped at 30 seconds.</td>
        </tr>
        <tr>
            <td><code>delay=random</code></td>
            <td><code>?delay=random</code></td>
            <td>Choose a random delay between 50 and 5000 ms.</td>
        </tr>
        <tr>
            <td><code>delay=0</code></td>
            <td><code>?delay=0</code></td>
            <td>Disable even the status code's built-in simulated delay.</td>
        </tr>
        <tr>
            <td><code>body=0</code></td>
            <td><code>?body=0</code></td>
            <td>Return the status and headers without the normal response body.</td>
        </tr>
        </tbody>
    </table>

    <h2>What varies between requests?</h2>

    <p>
        Where appropriate, values such as <code>Retry-After</code>,
        <code>Location</code>, ETags, rate-limit metadata, request IDs,
        authentication realms, content ranges and simulated delays are generated
        dynamically. This lets test clients read and respond to the values they
        actually receive instead of relying on fixed fixtures.
    </p>

    <h2>Important limitations</h2>

    <p>
        Some status codes describe behaviour that application-level PHP cannot
        faithfully reproduce. Informational 1xx responses normally precede a final
        response. nginx 444 normally closes a connection without sending an HTTP
        response at all. TLS handshake failures and true network timeouts happen
        below the PHP application layer. Those entries are still useful for testing
        status parsing, but they are not perfect wire-level simulations.
    </p>

    <h2>Status catalogue</h2>

    <table>
        <thead>
        <tr>
            <th>Code</th>
            <th>Reason</th>
            <th>Classification</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($statuses as $code => $definition): ?>
            <tr>
                <td><a href="/<?= (int) $code ?>"><?= (int) $code ?></a></td>
                <td><?= h($definition['reason']) ?></td>
                <td>
                    <span class="tag"><?= h($definition['standard'] ?? '') ?></span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Credits</h2>

    <p>
        Concept credit:
        <a href="https://github.com/aaronpowell/httpstatus" rel="noopener noreferrer">
            Aaron Powell / httpstat.us
        </a>.
        The original project describes itself as a simple way to test HTTP status
        codes by appending the desired code to the service URL.
    </p>

</main>

<script>
(() => {
    const base = <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>;
    const mode = document.getElementById('mode');
    const statusCode = document.getElementById('statusCode');
    const randomCodes = document.getElementById('randomCodes');
    const singleField = document.getElementById('singleField');
    const randomField = document.getElementById('randomField');
    const delay = document.getElementById('delay');
    const body = document.getElementById('body');
    const resultUrl = document.getElementById('resultUrl');
    const testButton = document.getElementById('testButton');
    const copyButton = document.getElementById('copyButton');

    function buildUrl() {
        let path;

        if (mode.value === 'random') {
            const selector = randomCodes.value.trim();
            path = selector ? '/random/' + selector : '/random';
        } else {
            path = '/' + statusCode.value;
        }

        const params = new URLSearchParams();

        if (delay.value !== '') {
            params.set('delay', delay.value);
        }

        if (body.value !== '') {
            params.set('body', body.value);
        }

        const query = params.toString();
        resultUrl.value = base + path + (query ? '?' + query : '');
    }

    function syncMode() {
        const random = mode.value === 'random';
        singleField.hidden = random;
        randomField.hidden = !random;
        buildUrl();
    }

    [mode, statusCode, randomCodes, delay, body].forEach(el => {
        el.addEventListener('input', buildUrl);
        el.addEventListener('change', buildUrl);
    });

    mode.addEventListener('change', syncMode);

    testButton.addEventListener('click', () => {
        buildUrl();
        window.open(resultUrl.value, '_blank', 'noopener');
    });

    copyButton.addEventListener('click', async () => {
        buildUrl();

        try {
            await navigator.clipboard.writeText(resultUrl.value);
            const original = copyButton.textContent;
            copyButton.textContent = 'Copied';
            setTimeout(() => copyButton.textContent = original, 1000);
        } catch {
            resultUrl.select();
            document.execCommand('copy');
        }
    });

    syncMode();
})();
</script>

</body>
</html>
    <?php

    exit;
}

/* --------------------------------------------------------------------------
 * Determine requested status
 * ----------------------------------------------------------------------- */

$code = null;

if ($path === 'random') {
    $code = chooseRandomStatus(null, $statuses);

} elseif (str_starts_with($path, 'random/')) {
    $selector = substr($path, strlen('random/'));
    $code = chooseRandomStatus($selector, $statuses);

} elseif (preg_match('/^[1-9][0-9]{2}$/', $path)) {
    $code = (int) $path;
}

if ($code === null) {
    $code = 404;
}

if (!isset($statuses[$code])) {
    $statuses[$code] = [
        'reason' => 'Unassigned Status Code',
        'standard' => ($code >= 100 && $code <= 599)
            ? 'Unassigned'
            : 'Non-standard / unknown',
    ];
}

$definition = $statuses[$code];
$headers = resolveHeaders($definition);
$delayMs = resolveDelay($definition);

$headers['X-Test-Status-Code'][] = (string) $code;
$headers['X-Test-Request-ID'][] = randomHex(12);
$headers['X-Test-Delay-Ms'][] = (string) $delayMs;

if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

/*
 * PHP and some SAPIs reject status codes outside 100-599 in http_response_code().
 * For those historical/non-standard examples, use the raw status line only.
 */
$protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
header($protocol . ' ' . $code . ' ' . $definition['reason'], true);

if ($code >= 100 && $code <= 599) {
    http_response_code($code);
}

if (!isset($headers['Content-Type'])) {
    $headers['Content-Type'][] = 'application/json; charset=utf-8';
}

emitHeaders($headers);

$bodySuppressed =
    ($definition['noBody'] ?? false)
    || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD'
    || (isset($_GET['body']) && $_GET['body'] === '0');

if ($bodySuppressed) {
    exit;
}

if (isset($definition['body'])) {
    echo (string) resolveValue($definition['body']);
    echo "\n";
    exit;
}

if ($code === 207 || $code === 208) {
    $resourceId = randomInt(1000, 9999);

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<d:multistatus xmlns:d="DAV:">' . "\n";
    echo '  <d:response>' . "\n";
    echo '    <d:href>/resource/' . $resourceId . '</d:href>' . "\n";
    echo '    <d:status>HTTP/1.1 ' . $code . ' ' . h($definition['reason']) . '</d:status>' . "\n";
    echo '  </d:response>' . "\n";
    echo '</d:multistatus>' . "\n";
    exit;
}

$body = [
    'status' => $code,
    'reason' => $definition['reason'],
    'classification' => $definition['standard'] ?? null,
    'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'delayMs' => $delayMs,
    'request' => [
        'id' => $headers['X-Test-Request-ID'][0],
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'uri' => $_SERVER['REQUEST_URI'] ?? '/',
        'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? null,
        'remoteAddress' => $_SERVER['REMOTE_ADDR'] ?? null,
        'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ],
    'responseHeaders' => $headers,
];

if (isset($definition['variants'])) {
    $body['knownVariants'] = $definition['variants'];
}

echo json_encode(
    $body,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
) . "\n";
