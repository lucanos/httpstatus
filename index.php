<?php

declare(strict_types=1);

/**
 * HTTP Response Test Harness
 *
 * A lightweight HTTP response simulator inspired by Aaron Powell's
 * httpstatus project:
 *   https://github.com/aaronpowell/httpstatus
 *
 * Project:
 *   https://github.com/lucanos/httpstatus
 *
 * Licensed under the MIT License.
 *
 * Documentation:
 *   README.md
 *   or visit the application root in a browser.
 *
 * Quick examples:
 *
 *   /200
 *   /404
 *   /503
 *
 *   /200,404,200,302
 *   /200x5,404,500x2
 *   /200,404,200,302?reset=1
 *
 *   /random
 *   /random/200,404,500
 *   /random/200,200,404
 *   /random/200x4,404
 *   /random/200x95,404x3,500x2
 *
 * Common options:
 *
 *   ?delay=1500
 *   ?delay=random
 *   ?body=0
 *   ?format=json
 *   ?format=html
 *   ?format=markdown
 *   ?format=text
 *   ?reset=1
 *
 * See README.md or the browser-based User's Guide for full documentation.
 */

session_start();

/* --------------------------------------------------------------------------
 * Self-install Apache rewrite configuration when possible.
 *
 * Existing .htaccess files are never overwritten.
 * ----------------------------------------------------------------------- */

$htaccessPath = __DIR__ . '/.htaccess';
$htaccessPresent = file_exists($htaccessPath);
$htaccessCreated = false;
$htaccessWritable = is_writable(__DIR__);

if (!$htaccessPresent && $htaccessWritable) {
    $defaultHtaccess = <<<'HTACCESS'
Options -Indexes

RewriteEngine On

RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

RewriteRule ^ index.php [QSA,L]

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "no-referrer"
</IfModule>

<Files ".htaccess">
    Require all denied
</Files>
HTACCESS;

    $htaccessCreated = @file_put_contents(
        $htaccessPath,
        $defaultHtaccess . PHP_EOL,
        LOCK_EX
    ) !== false;

    $htaccessPresent = file_exists($htaccessPath);
}

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

function parseWeightedRandomSelector(string $selector, array $statuses): array
{
    $weighted = [];

    foreach (explode(',', $selector) as $part) {
        $part = strtolower(trim($part));

        if ($part === '') {
            continue;
        }

        $weight = 1;

        if (preg_match('/^([1-9][0-9]{2})x([1-9][0-9]{0,4})$/', $part, $m)) {
            $part = $m[1];
            $weight = min((int) $m[2], 10000);
        }

        foreach (expandSelector($part, $statuses) as $code) {
            $weighted[] = [
                'code' => $code,
                'weight' => $weight,
            ];
        }
    }

    return $weighted;
}

function chooseWeightedRandomStatus(?string $selector, array $statuses): int
{
    if ($selector === null || trim($selector) === '') {
        $weighted = [];

        foreach (array_keys($statuses) as $code) {
            if ($code >= 200 && $code <= 599) {
                $weighted[] = ['code' => $code, 'weight' => 1];
            }
        }
    } else {
        $weighted = parseWeightedRandomSelector($selector, $statuses);
    }

    if ($weighted === []) {
        return 400;
    }

    $total = array_sum(array_column($weighted, 'weight'));
    $pick = randomInt(1, $total);

    foreach ($weighted as $entry) {
        $pick -= $entry['weight'];

        if ($pick <= 0) {
            return $entry['code'];
        }
    }

    return $weighted[array_key_last($weighted)]['code'];
}

function parseSequentialSelector(string $selector): array
{
    $runs = [];
    $length = 0;

    foreach (explode(',', $selector) as $part) {
        $part = strtolower(trim($part));

        if ($part === '') {
            return [];
        }

        $count = 1;

        if (preg_match('/^([1-9][0-9]{2})x([1-9][0-9]{0,4})$/', $part, $m)) {
            $code = (int) $m[1];
            $count = min((int) $m[2], 10000);

        } elseif (preg_match('/^[1-9][0-9]{2}$/', $part)) {
            $code = (int) $part;

        } else {
            return [];
        }

        $runs[] = [
            'code' => $code,
            'count' => $count,
        ];

        $length += $count;
    }

    return [
        'expression' => $selector,
        'runs' => $runs,
        'length' => $length,
    ];
}

function sequenceCodeAtPosition(array $sequence, int $positionZero): int
{
    $remaining = $positionZero;

    foreach ($sequence['runs'] as $run) {
        if ($remaining < $run['count']) {
            return $run['code'];
        }

        $remaining -= $run['count'];
    }

    return $sequence['runs'][array_key_last($sequence['runs'])]['code'];
}

function chooseSequentialStatus(string $selector): array
{
    $sequence = parseSequentialSelector($selector);

    if ($sequence === []) {
        return [
            'code' => 400,
            'meta' => null,
        ];
    }

    $key = 'sequence_' . hash('sha256', $sequence['expression']);

    if (isset($_GET['reset']) && $_GET['reset'] === '1') {
        unset($_SESSION[$key]);
    }

    $index = (int) ($_SESSION[$key] ?? 0);
    $length = $sequence['length'];
    $positionZero = $index % $length;

    $code = sequenceCodeAtPosition($sequence, $positionZero);
    $next = sequenceCodeAtPosition(
        $sequence,
        ($positionZero + 1) % $length
    );

    $meta = [
        'expression' => $sequence['expression'],
        'position' => $positionZero + 1,
        'length' => $length,
        'loop' => intdiv($index, $length) + 1,
        'request' => $index + 1,
        'next' => $next,
    ];

    $_SESSION[$key] = $index + 1;

    return [
        'code' => $code,
        'meta' => $meta,
    ];
}

function parseAcceptHeader(string $header): array
{
    $items = [];

    foreach (explode(',', $header) as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        $pieces = array_map('trim', explode(';', $part));
        $type = strtolower(array_shift($pieces));
        $q = 1.0;

        foreach ($pieces as $piece) {
            if (preg_match('/^q=([0-9.]+)$/i', $piece, $m)) {
                $q = max(0.0, min(1.0, (float) $m[1]));
            }
        }

        $items[] = ['type' => $type, 'q' => $q];
    }

    usort($items, fn(array $a, array $b): int => $b['q'] <=> $a['q']);

    return $items;
}

function negotiateFormat(): string
{
    if (isset($_GET['format'])) {
        $requested = strtolower(trim((string) $_GET['format']));
        $aliases = [
            'html' => 'html',
            'json' => 'json',
            'markdown' => 'markdown',
            'md' => 'markdown',
            'text' => 'text',
            'txt' => 'text',
        ];

        if (isset($aliases[$requested])) {
            return $aliases[$requested];
        }
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '*/*';

    foreach (parseAcceptHeader($accept) as $item) {
        if ($item['q'] <= 0) {
            continue;
        }

        $type = $item['type'];

        if ($type === 'text/html' || $type === 'application/xhtml+xml') {
            return 'html';
        }

        if ($type === 'text/markdown' || $type === 'text/x-markdown') {
            return 'markdown';
        }

        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            return 'json';
        }

        if ($type === 'text/plain') {
            return 'text';
        }

        if ($type === '*/*') {
            return 'json';
        }
    }

    return 'json';
}

function formatContentType(string $format): string
{
    return match ($format) {
        'html' => 'text/html; charset=utf-8',
        'markdown' => 'text/markdown; charset=utf-8',
        'text' => 'text/plain; charset=utf-8',
        default => 'application/json; charset=utf-8',
    };
}

function flattenHeaderValues(array $headers): array
{
    $flat = [];

    foreach ($headers as $name => $values) {
        $flat[$name] = count($values) === 1 ? $values[0] : $values;
    }

    return $flat;
}

function referenceForStatus(int $code, array $definition): array
{
    $classification = $definition['standard'] ?? '';

    if ($code === 418) {
        return [
            'label' => 'RFC 2324',
            'url' => 'https://www.rfc-editor.org/rfc/rfc2324.html',
        ];
    }

    if (str_contains($classification, 'Cloudflare')) {
        return [
            'label' => 'Cloudflare docs',
            'url' => 'https://developers.cloudflare.com/support/troubleshooting/http-status-codes/cloudflare-5xx-errors/',
        ];
    }

    if (str_contains($classification, 'nginx')) {
        return [
            'label' => 'nginx source',
            'url' => 'https://github.com/nginx/nginx/blob/master/src/http/ngx_http_request.h',
        ];
    }

    if (str_starts_with($classification, 'IANA')) {
        return [
            'label' => 'IANA registry',
            'url' => 'https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml',
        ];
    }

    return [
        'label' => 'Unofficial references',
        'url' => 'https://en.wikipedia.org/wiki/List_of_HTTP_status_codes#Unofficial_codes',
    ];
}

function renderHtmlResponse(
    int $code,
    array $definition,
    array $headers,
    int $delayMs,
    ?array $sequenceMeta,
    ?string $message
): string {
    $rows = '';

    foreach ($headers as $name => $values) {
        foreach ($values as $value) {
            $rows .= '<tr><th>' . h($name) . '</th><td><code>' . h($value) . '</code></td></tr>';
        }
    }

    $sequence = '';

    if ($sequenceMeta !== null) {
        $sequence = '<h2>Sequence</h2><ul>'
            . '<li>Values: <code>' . h($sequenceMeta['expression']) . '</code></li>'
            . '<li>Position: <code>' . $sequenceMeta['position'] . '/' . $sequenceMeta['length'] . '</code></li>'
            . '<li>Loop: <code>' . $sequenceMeta['loop'] . '</code></li>'
            . '<li>Request: <code>' . $sequenceMeta['request'] . '</code></li>'
            . '<li>Next: <code>' . $sequenceMeta['next'] . '</code></li>'
            . '</ul>';
    }

    $messageHtml = $message !== null
        ? '<p class="message">' . nl2br(h($message)) . '</p>'
        : '';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $code . ' ' . h($definition['reason']) . '</title>'
        . '<style>body{max-width:900px;margin:40px auto;padding:0 20px;font-family:system-ui,sans-serif;line-height:1.5}'
        . 'code{background:#eee;color:#111;padding:2px 5px;border-radius:4px}'
        . 'table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:7px 9px;border-bottom:1px solid #ccc}'
        . '.message{font-size:1.15rem;padding:14px 16px;border-left:4px solid #777;background:#f4f4f4;color:#111}</style>'
        . '</head><body>'
        . '<h1>' . $code . ' ' . h($definition['reason']) . '</h1>'
        . '<p><strong>Classification:</strong> ' . h($definition['standard'] ?? '') . '</p>'
        . $messageHtml
        . '<p><strong>Delay:</strong> ' . $delayMs . ' ms</p>'
        . $sequence
        . '<h2>Response headers</h2><table>' . $rows . '</table>'
        . '</body></html>';
}

function renderMarkdownResponse(
    int $code,
    array $definition,
    array $headers,
    int $delayMs,
    ?array $sequenceMeta,
    ?string $message
): string {
    $out = '# ' . $code . ' ' . $definition['reason'] . "\n\n";
    $out .= '- Classification: `' . ($definition['standard'] ?? '') . "`\n";
    $out .= '- Delay: `' . $delayMs . " ms`\n";

    if ($message !== null) {
        $out .= "\n" . $message . "\n";
    }

    if ($sequenceMeta !== null) {
        $out .= "\n## Sequence\n\n";
        $out .= '- Values: `' . $sequenceMeta['expression'] . "`\n";
        $out .= '- Position: `' . $sequenceMeta['position'] . '/' . $sequenceMeta['length'] . "`\n";
        $out .= '- Loop: `' . $sequenceMeta['loop'] . "`\n";
        $out .= '- Request: `' . $sequenceMeta['request'] . "`\n";
        $out .= '- Next: `' . $sequenceMeta['next'] . "`\n";
    }

    $out .= "\n## Response headers\n\n";

    foreach ($headers as $name => $values) {
        foreach ($values as $value) {
            $out .= '- ' . $name . ': `' . str_replace('`', '\`', $value) . "`\n";
        }
    }

    return $out;
}

function renderTextResponse(
    int $code,
    array $definition,
    array $headers,
    int $delayMs,
    ?array $sequenceMeta,
    ?string $message
): string {
    $out = $code . ' ' . $definition['reason'] . "\n";
    $out .= 'Classification: ' . ($definition['standard'] ?? '') . "\n";
    $out .= 'Delay: ' . $delayMs . " ms\n";

    if ($message !== null) {
        $out .= "\n" . $message . "\n";
    }

    if ($sequenceMeta !== null) {
        $out .= "\nSequence: " . $sequenceMeta['expression'] . "\n";
        $out .= 'Sequence-Position: ' . $sequenceMeta['position'] . '/' . $sequenceMeta['length'] . "\n";
        $out .= 'Sequence-Loop: ' . $sequenceMeta['loop'] . "\n";
        $out .= 'Sequence-Request: ' . $sequenceMeta['request'] . "\n";
        $out .= 'Sequence-Next: ' . $sequenceMeta['next'] . "\n";
    }

    $out .= "\n";

    foreach ($headers as $name => $values) {
        foreach ($values as $value) {
            $out .= $name . ': ' . $value . "\n";
        }
    }

    return $out;
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

    <h3>Fixed response</h3>
    <pre><?= h($baseUrl) ?>/200
<?= h($baseUrl) ?>/404
<?= h($baseUrl) ?>/503</pre>

    <h3>Sequenced responses</h3>
    <pre><?= h($baseUrl) ?>/200,404,200,302
<?= h($baseUrl) ?>/200x5,404,500x2
<?= h($baseUrl) ?>/200,404,200,302?reset=1</pre>

    <p>
        Without the <code>/random/</code> prefix, a comma-separated list is a
        deterministic sequence. Numeric multipliers repeat a status in-place, so
        <code>/200x5,404,500x2</code> is equivalent to five 200 responses, then 404,
        then two 500 responses. Each request advances to the next logical status and
        the sequence repeats after the final item. Sequence state is tracked per
        client session and per sequence definition.
    </p>

    <p>
        <code>?reset=1</code> resets that sequence before returning the first item.
    </p>

    <h3>Random responses</h3>
    <pre><?= h($baseUrl) ?>/random
<?= h($baseUrl) ?>/random/200,404,500
<?= h($baseUrl) ?>/random/2xx,4xx,5xx</pre>

    <h3>Weighted random responses</h3>
    <pre><?= h($baseUrl) ?>/random/200,200,404
<?= h($baseUrl) ?>/random/200x2,404
<?= h($baseUrl) ?>/random/200x4,404
<?= h($baseUrl) ?>/random/200x95,404x3,500x2</pre>

    <p>
        Repetition and multipliers both control probability. For example,
        <code>/random/200,200,404</code> and <code>/random/200x2,404</code>
        are equivalent. The multiplier form is easier to read for larger
        distributions such as <code>/random/200x95,404x3,500x2</code>.
    </p>

    <p>
        Numeric multipliers are supported up to <code>x10000</code>.
        Status classes such as <code>2xx</code> can be repeated for weighting.
    </p>

    <h2>Build a test URL</h2>

    <div class="builder">

        <div class="field third">
            <label for="mode">Mode</label>
            <select id="mode">
                <option value="single">Single status</option>
                <option value="sequence">Sequenced responses</option>
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

        <div class="field third" id="sequenceField" hidden>
            <label for="sequenceCodes">Sequence</label>
            <input
                id="sequenceCodes"
                type="text"
                value="200x5,404,500x2"
                placeholder="200x5,404,500x2"
            >
        </div>

        <div class="field third" id="randomField" hidden>
            <label for="randomCodes">Random selector</label>
            <input
                id="randomCodes"
                type="text"
                value="200x95,404x3,500x2"
                placeholder="200,404,500 or 200x95,404x3,500x2"
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
            <label for="format">Format</label>
            <select id="format">
                <option value="">Use Accept header</option>
                <option value="html">HTML</option>
                <option value="json">JSON</option>
                <option value="markdown">Markdown</option>
                <option value="text">Plain text</option>
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
            <td><code>delay=0</code></td>
            <td><code>/504?delay=0</code></td>
            <td>Disable any status-specific simulated delay.</td>
        </tr>
        <tr>
            <td><code>delay=&lt;ms&gt;</code></td>
            <td><code>/200?delay=1500</code></td>
            <td>Force a delay in milliseconds, capped at 30 seconds.</td>
        </tr>
        <tr>
            <td><code>delay=random</code></td>
            <td><code>/200?delay=random</code></td>
            <td>Random delay between 50 and 5000 ms.</td>
        </tr>
        <tr>
            <td><code>body=0</code></td>
            <td><code>/404?body=0</code></td>
            <td>Suppress the response body.</td>
        </tr>
        <tr>
            <td><code>format=json</code></td>
            <td><code>/429?format=json</code></td>
            <td>Force JSON.</td>
        </tr>
        <tr>
            <td><code>format=html</code></td>
            <td><code>/429?format=html</code></td>
            <td>Force HTML.</td>
        </tr>
        <tr>
            <td><code>format=markdown</code></td>
            <td><code>/429?format=markdown</code></td>
            <td>Force Markdown.</td>
        </tr>
        <tr>
            <td><code>format=text</code></td>
            <td><code>/429?format=text</code></td>
            <td>Force plain text.</td>
        </tr>
        <tr>
            <td><code>reset=1</code></td>
            <td><code>/200,404,500?reset=1</code></td>
            <td>Reset the current client's sequence and return its first item.</td>
        </tr>
        </tbody>
    </table>

    <h2>Content negotiation</h2>

    <p>
        Without a <code>?format=</code> override, the service uses the request's
        <code>Accept</code> header and supports:
    </p>

    <pre>text/html
application/json
text/markdown
text/plain</pre>

    <p>
        Normal browser navigation prefers HTML. <code>Accept: */*</code> defaults
        to JSON. Content-negotiated responses include <code>Vary: Accept</code>.
    </p>

    <h2>Sequence diagnostics</h2>

    <pre>X-Test-Sequence
X-Test-Sequence-Position
X-Test-Sequence-Length
X-Test-Sequence-Loop
X-Test-Sequence-Request
X-Test-Sequence-Next</pre>

    <p>
        Positions and loop counters are one-based, so the first pass through a
        sequence is loop <code>1</code>.
    </p>

    <h2>What varies between requests?</h2>

    <p>
        Where appropriate, values such as <code>Retry-After</code>,
        <code>Location</code>, ETags, rate-limit metadata, request IDs,
        authentication realms, content ranges and simulated delays are generated
        dynamically. This lets test clients act on the values they actually receive.
    </p>

    <h2>Important limitations</h2>

    <p>
        This is an application-level simulator. Informational 1xx responses,
        nginx 444 connection-closing behaviour, genuine TCP failures, TLS handshake
        failures and true network-level timeouts cannot all be reproduced perfectly
        from PHP behind a conventional web server.
    </p>

    <h2>Status catalogue</h2>

    <table>
        <thead>
        <tr>
            <th>Code</th>
            <th>Reason</th>
            <th>Classification</th>
            <th>Reference</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($statuses as $code => $definition): ?>
            <?php $reference = referenceForStatus((int) $code, $definition); ?>
            <tr>
                <td><a href="/<?= (int) $code ?>"><?= (int) $code ?></a></td>
                <td><?= h($definition['reason']) ?></td>
                <td><span class="tag"><?= h($definition['standard'] ?? '') ?></span></td>
                <td>
                    <a href="<?= h($reference['url']) ?>" rel="noopener noreferrer">
                        <?= h($reference['label']) ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Credits</h2>

    <p>
        Project:
        <a href="https://github.com/lucanos/httpstatus" rel="noopener noreferrer">
            github.com/lucanos/httpstatus
        </a>
    </p>

    <p>
        Inspired by
        <a href="https://github.com/aaronpowell/httpstatus" rel="noopener noreferrer">
            Aaron Powell's httpstatus project
        </a>,
        which is also distributed under the MIT License.
    </p>

</main>

<script>
(() => {
    const base = <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>;
    const mode = document.getElementById('mode');
    const statusCode = document.getElementById('statusCode');
    const sequenceCodes = document.getElementById('sequenceCodes');
    const randomCodes = document.getElementById('randomCodes');
    const singleField = document.getElementById('singleField');
    const sequenceField = document.getElementById('sequenceField');
    const randomField = document.getElementById('randomField');
    const delay = document.getElementById('delay');
    const format = document.getElementById('format');
    const body = document.getElementById('body');
    const resultUrl = document.getElementById('resultUrl');
    const testButton = document.getElementById('testButton');
    const copyButton = document.getElementById('copyButton');

    function buildUrl() {
        let path;

        if (mode.value === 'random') {
            const selector = randomCodes.value.trim();
            path = selector ? '/random/' + selector : '/random';
        } else if (mode.value === 'sequence') {
            const selector = sequenceCodes.value.trim();
            path = '/' + (selector || '200x5,404,500x2');
        } else {
            path = '/' + statusCode.value;
        }

        const params = new URLSearchParams();

        if (delay.value !== '') {
            params.set('delay', delay.value);
        }

        if (format.value !== '') {
            params.set('format', format.value);
        }

        if (body.value !== '') {
            params.set('body', body.value);
        }

        const query = params.toString();
        resultUrl.value = base + path + (query ? '?' + query : '');
    }

    function syncMode() {
        singleField.hidden = mode.value !== 'single';
        sequenceField.hidden = mode.value !== 'sequence';
        randomField.hidden = mode.value !== 'random';
        buildUrl();
    }

    [mode, statusCode, sequenceCodes, randomCodes, delay, format, body].forEach(el => {
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
$sequenceMeta = null;

if ($path === 'random') {
    $code = chooseWeightedRandomStatus(null, $statuses);

} elseif (str_starts_with($path, 'random/')) {
    $selector = substr($path, strlen('random/'));
    $code = chooseWeightedRandomStatus($selector, $statuses);

} elseif (preg_match('/^[1-9][0-9]{2}(?:x[1-9][0-9]{0,4})?(?:,[1-9][0-9]{2}(?:x[1-9][0-9]{0,4})?)+$/i', $path)) {
    $sequence = chooseSequentialStatus($path);
    $code = $sequence['code'];
    $sequenceMeta = $sequence['meta'];

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
$headers['Vary'][] = 'Accept';

if ($sequenceMeta !== null) {
    $headers['X-Test-Sequence'][] = $sequenceMeta['expression'];
    $headers['X-Test-Sequence-Position'][] = (string) $sequenceMeta['position'];
    $headers['X-Test-Sequence-Length'][] = (string) $sequenceMeta['length'];
    $headers['X-Test-Sequence-Loop'][] = (string) $sequenceMeta['loop'];
    $headers['X-Test-Sequence-Request'][] = (string) $sequenceMeta['request'];
    $headers['X-Test-Sequence-Next'][] = (string) $sequenceMeta['next'];
}

if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

$protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
header($protocol . ' ' . $code . ' ' . $definition['reason'], true);

if ($code >= 100 && $code <= 599) {
    http_response_code($code);
}

$format = negotiateFormat();

if ($code !== 207 && $code !== 208) {
    $headers['Content-Type'] = [formatContentType($format)];
} elseif (!isset($headers['Content-Type'])) {
    $headers['Content-Type'][] = 'application/xml; charset=utf-8';
}

emitHeaders($headers);

$bodySuppressed =
    ($definition['noBody'] ?? false)
    || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD'
    || (isset($_GET['body']) && $_GET['body'] === '0');

if ($bodySuppressed) {
    exit;
}

$message = isset($definition['body'])
    ? (string) resolveValue($definition['body'])
    : null;

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

if ($format === 'html') {
    echo renderHtmlResponse($code, $definition, $headers, $delayMs, $sequenceMeta, $message);
    exit;
}

if ($format === 'markdown') {
    echo renderMarkdownResponse($code, $definition, $headers, $delayMs, $sequenceMeta, $message);
    exit;
}

if ($format === 'text') {
    echo renderTextResponse($code, $definition, $headers, $delayMs, $sequenceMeta, $message);
    exit;
}

$body = [
    'status' => $code,
    'reason' => $definition['reason'],
    'classification' => $definition['standard'] ?? null,
    'message' => $message,
    'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'delayMs' => $delayMs,
    'request' => [
        'id' => $headers['X-Test-Request-ID'][0],
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'uri' => $_SERVER['REQUEST_URI'] ?? '/',
        'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? null,
        'remoteAddress' => $_SERVER['REMOTE_ADDR'] ?? null,
        'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'accept' => $_SERVER['HTTP_ACCEPT'] ?? null,
    ],
    'responseHeaders' => flattenHeaderValues($headers),
];

if ($sequenceMeta !== null) {
    $body['sequence'] = $sequenceMeta;
}

if (isset($definition['variants'])) {
    $body['knownVariants'] = $definition['variants'];
}

echo json_encode(
    $body,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
) . "\n";
