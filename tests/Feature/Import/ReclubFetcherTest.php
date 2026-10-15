<?php

use App\Services\ReclubFetcher;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\StreamInterface;

function reclubHtml(): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/reclub-event.html');
}

function reclubError(Closure $fn): ?string
{
    try {
        $fn();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('reclub');

        return $e->errors()['reclub'][0];
    }

    return null;
}

beforeEach(function () {
    Http::preventStrayRequests();
    RateLimiter::clear('reclub:fetch:user:1');
});

test('it fetches the page and returns the confirmed players', function () {
    Http::fake(['reclub.co/*' => Http::response(reclubHtml())]);

    $event = app(ReclubFetcher::class)->fetch('https://reclub.co/m/synth1', 'user:1');

    expect($event->players)->toHaveCount(5)->and($event->players[0])->toBe('Ana Reyes');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://reclub.co/m/synth1'
        && $r->method() === 'GET'
        && ($r->toPsrRequest()->getHeaderLine('Accept') === 'text/html'));
});

test('www.reclub.co is allowed, the host is case-insensitive and the query is kept', function () {
    Http::fake(['www.reclub.co/*' => Http::response(reclubHtml())]);

    app(ReclubFetcher::class)->fetch('  HTTPS://WWW.Reclub.CO/m/synth1?ref=x  ', 'user:1');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://www.reclub.co/m/synth1?ref=x');
});

test('anything but an https reclub.co link is refused without a request', function (string $url) {
    Http::fake();

    $message = reclubError(fn () => app(ReclubFetcher::class)->fetch($url, 'user:1'));

    expect($message)->toContain('Reclub event link');
    Http::assertNothingSent();
})->with([
    'http' => 'http://reclub.co/m/x',
    'other host' => 'https://example.com/m/x',
    'lookalike' => 'https://reclub.co.evil.com/m/x',
    'subdomain' => 'https://evil.reclub.co/m/x',
    'suffix' => 'https://notreclub.co/m/x',
    'userinfo' => 'https://reclub.co@evil.com/m/x',
    'userinfo ok host' => 'https://user:pass@reclub.co/m/x',
    'port' => 'https://reclub.co:443/m/x',
    'odd port' => 'https://reclub.co:8443/m/x',
    'ip' => 'https://127.0.0.1/m/x',
    'ip with host header trick' => 'https://93.184.216.34@reclub.co.evil.com/',
    'localhost' => 'https://localhost/',
    'no scheme' => 'reclub.co/m/x',
    'ftp' => 'ftp://reclub.co/x',
    'javascript' => 'javascript:alert(1)',
    'empty' => '',
    'spaces' => 'https://reclub.co/m/a b',
    'newline' => "https://reclub.co/m/x\nhttps://evil.com",
    'trailing dot' => 'https://reclub.co./m/x',
    'backslash' => 'https://evil.com\\@reclub.co/m/x',
    'too long' => 'https://reclub.co/'.str_repeat('a', 2100),
]);

test('a redirect is an error and is never followed', function () {
    Http::fake(['reclub.co/*' => Http::response('', 302, ['Location' => 'https://evil.example/'])]);

    $message = reclubError(fn () => app(ReclubFetcher::class)->fetch('https://reclub.co/m/x', 'user:1'));

    expect($message)->toContain('redirects');
    Http::assertSentCount(1);
});

test('an error status is a friendly error', function (int $status) {
    Http::fake(['reclub.co/*' => Http::response('nope', $status)]);

    expect(reclubError(fn () => app(ReclubFetcher::class)->fetch('https://reclub.co/m/x', 'user:1')))
        ->toBe("Couldn't read players from that Reclub link.");
})->with([404, 403, 500, 503]);

test('a page that is not an event is the friendly parse error', function (string $body) {
    Http::fake(['reclub.co/*' => Http::response($body)]);

    expect(reclubError(fn () => app(ReclubFetcher::class)->fetch('https://reclub.co/m/x', 'user:1')))
        ->toBe("Couldn't read players from that Reclub link.");
})->with([
    'plain' => '<html><body>Hello</body></html>',
    'broken payload' => '<script id="__NUXT_DATA__">[[[</script>',
    'no participants' => '<script id="__NUXT_DATA__">[{"a":1}]</script>',
    'empty' => '',
]);

test('a page over 2 MB is refused', function () {
    Http::fake(['reclub.co/*' => Http::response(str_repeat('a', ReclubFetcher::MAX_BYTES + 1))]);

    expect(reclubError(fn () => app(ReclubFetcher::class)->fetch('https://reclub.co/m/x', 'user:1')))->toContain('too large');
});

test('an oversized streamed body is refused and the read stops at the cap', function () {
    $stream = new class implements StreamInterface
    {
        public int $served = 0;

        public bool $closed = false;

        public function __toString(): string
        {
            return '';
        }

        public function close(): void
        {
            $this->closed = true;
        }

        public function detach()
        {
            return null;
        }

        public function getSize(): ?int
        {
            return null; // chunked: no length known
        }

        public function tell(): int
        {
            return $this->served;
        }

        public function eof(): bool
        {
            return false; // never ends
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            return 0;
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function read(int $length): string
        {
            $this->served += $length;

            return str_repeat('a', $length);
        }

        public function getContents(): string
        {
            return '';
        }

        public function getMetadata(?string $key = null)
        {
            return null;
        }
    };
    Http::fake(['reclub.co/*' => fn () => Create::promiseFor(new Response(200, [], $stream))]);

    expect(reclubError(fn () => app(ReclubFetcher::class)->fetch('https://reclub.co/m/x', 'user:1')))->toContain('too large')
        ->and($stream->served)->toBeGreaterThan(ReclubFetcher::MAX_BYTES)
        ->and($stream->served)->toBeLessThan(ReclubFetcher::MAX_BYTES + 16384);
});

test('a page of exactly 2 MB is still read', function () {
    $filler = str_repeat(' ', ReclubFetcher::MAX_BYTES - strlen(reclubHtml()));
    Http::fake(['reclub.co/*' => Http::response($filler.reclubHtml())]);

    expect(app(ReclubFetcher::class)->fetch('https://reclub.co/m/x', 'user:1')->players)->toHaveCount(5);
});

test('a connection failure is a friendly error', function () {
    Http::fake(['reclub.co/*' => Http::failedConnection('timeout')]);

    expect(reclubError(fn () => app(ReclubFetcher::class)->fetch('https://reclub.co/m/x', 'user:1')))->toContain("Couldn't reach Reclub");
})->skip(
    fn () => extension_loaded('xdebug') && function_exists('xdebug_info') && xdebug_info('mode') !== [],
    'An exception thrown through the HTTP client segfaults PHP under xdebug here; run with XDEBUG_MODE=off (CI has no xdebug).',
);

test('the request has a 10 second timeout, no redirects, identity encoding and streams the body', function () {
    $options = null;
    Http::fake(function (Request $request, array $o) use (&$options) {
        $options = $o;

        return Http::response(reclubHtml());
    });

    app(ReclubFetcher::class)->fetch('https://reclub.co/m/x', 'user:1');

    expect($options['timeout'])->toBe(10)
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['stream'])->toBeTrue()
        ->and($options['decode_content'])->toBeFalse()
        ->and($options['on_headers'])->toBeCallable();
    Http::assertSent(fn (Request $r) => $r->toPsrRequest()->getHeaderLine('Accept-Encoding') === 'identity');

    $headers = new Response(200, ['Content-Length' => (string) (ReclubFetcher::MAX_BYTES + 1)]);
    expect(fn () => ($options['on_headers'])($headers))->toThrow(RuntimeException::class);
    ($options['on_headers'])(new Response(200, ['Content-Length' => '100']));
});

test('at most 10 fetches a minute per user, and users are counted separately', function () {
    Http::fake(['reclub.co/*' => Http::response(reclubHtml())]);
    RateLimiter::clear('reclub:fetch:user:2');
    $fetcher = app(ReclubFetcher::class);

    for ($i = 0; $i < ReclubFetcher::PER_MINUTE; $i++) {
        $fetcher->fetch('https://reclub.co/m/x', 'user:1');
    }

    expect(reclubError(fn () => $fetcher->fetch('https://reclub.co/m/x', 'user:1')))->toContain('Too many')
        ->and($fetcher->fetch('https://reclub.co/m/x', 'user:2')->players)->toHaveCount(5);
    Http::assertSentCount(ReclubFetcher::PER_MINUTE + 1);

    $this->travel(61)->seconds();
    expect($fetcher->fetch('https://reclub.co/m/x', 'user:1')->players)->toHaveCount(5);
});
