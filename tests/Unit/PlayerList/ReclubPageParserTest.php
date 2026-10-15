<?php

use App\Domain\PlayerList\ReclubPageParser;
use App\Domain\PlayerList\ReclubParseException;

function reclubFixture(): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/reclub-event.html');
}

/** A minimal page around a devalue payload. */
function reclubPage(array $payload): string
{
    return '<html><body><script type="application/json" id="__NUXT_DATA__">'.json_encode($payload).'</script></body></html>';
}

test('it reads the confirmed players of the synthetic page in sign-up order', function () {
    $event = ReclubPageParser::parse(reclubFixture());

    expect($event->players)->toBe(['Ana Reyes', 'Ben Cruz', 'Cara Lim', 'Fay Gomez', 'José Álvarez'])
        ->and($event->waitlisted)->toBe(1)
        ->and($event->cancelled)->toBe(1);
});

test('the fixture only holds invented names', function () {
    $html = reclubFixture();

    expect($html)->toContain('Synthetic Sunday Open Play')->and($html)->toContain('synthetic-5001');
});

test('a page without the payload script fails', function () {
    expect(fn () => ReclubPageParser::parse('<html><script>window.participants = []</script></html>'))
        ->toThrow(ReclubParseException::class);
});

test('a payload that is not a devalue array fails', function () {
    expect(fn () => ReclubPageParser::parse('<script id="__NUXT_DATA__">{"a":1}</script>'))->toThrow(ReclubParseException::class)
        ->and(fn () => ReclubPageParser::parse('<script id="__NUXT_DATA__">not json</script>'))->toThrow(ReclubParseException::class)
        ->and(fn () => ReclubPageParser::parse('<script id="__NUXT_DATA__"></script>'))->toThrow(ReclubParseException::class);
});

test('a payload without a participants object fails', function () {
    expect(fn () => ReclubPageParser::parse(reclubPage([['Reactive', 1], ['id' => 2], 7])))->toThrow(ReclubParseException::class);
});

test('zero confirmed players fails', function () {
    // 0 root, 1 event, 2 participants list, 3 participant, 4 status 4 (waitlist), 5 user id, 6 user, 7 name
    $page = reclubPage([
        ['Reactive', 1],
        ['participants' => 2],
        [3],
        ['status' => 4, 'referenceId' => 5],
        4,
        5001,
        ['userId' => 5, 'name' => 7],
        'Nobody Confirmed',
    ]);

    expect(fn () => ReclubPageParser::parse($page))->toThrow(ReclubParseException::class, 'No confirmed players');
});

test('wrappers, shared value indices and reactive lists are followed', function () {
    // 0 root, 1 event, 2 wrapped list, 3 list, 4 participant, 5 status 1, 6 user id, 7 user, 8 name
    $page = reclubPage([
        ['ShallowReactive', 1],
        ['participants' => 2],
        ['Reactive', 3],
        [4],
        ['status' => 5, 'referenceId' => 6],
        1,
        9001,
        ['userId' => 6, 'name' => 8],
        'Wrapped Wendy',
    ]);

    expect(ReclubPageParser::parse($page)->players)->toBe(['Wrapped Wendy']);
});

test('a participant whose user is unknown is skipped, and a missing status is ignored', function () {
    // 0 event, 1 list, 2 p1 (unknown user), 3 p2 (no status), 4 status 1, 5 id 111, 6 id 222, 7 user, 8 name
    $page = reclubPage([
        ['participants' => 1],
        [2, 3],
        ['status' => 4, 'referenceId' => 5],
        ['referenceId' => 6],
        1,
        111,
        222,
        ['userId' => 6, 'name' => 8],
        'Only Known',
    ]);

    expect(fn () => ReclubPageParser::parse($page))->toThrow(ReclubParseException::class);
});

test('references that point outside the payload or loop do not hang or crash', function () {
    $page = reclubPage([
        ['participants' => 1],
        [2],
        ['status' => 99, 'referenceId' => -1],
        ['Reactive', 3],
    ]);

    expect(fn () => ReclubPageParser::parse($page))->toThrow(ReclubParseException::class);
});

test('a script tag with attributes in a different order and extra spacing is found', function () {
    $html = str_replace('<script type="application/json" data-nuxt-data="nuxt-app" data-ssr="true" id="__NUXT_DATA__">', "<script id='__NUXT_DATA__' data-ssr=\"true\"\n  type=\"application/json\">", reclubFixture());

    expect(ReclubPageParser::parse($html)->players)->toHaveCount(5);
});

test('a payload of a couple of megabytes is cut out without regex backtracking limits', function () {
    $payload = json_encode([
        ['participants' => 1],
        [2],
        ['status' => 3, 'referenceId' => 4],
        1,
        7001,
        ['userId' => 4, 'name' => 6],
        'Big Page Bea',
        str_repeat('<b>x</b> ', 200000),
    ]);
    $html = '<html><script>var a = 1;</script><script id="__NUXT_DATA__" type="application/json">'.$payload.'</script><script>var b = 2;</script></html>';

    expect(strlen($html))->toBeGreaterThan(1500000)
        ->and(ReclubPageParser::parse($html)->players)->toBe(['Big Page Bea']);
});

test('an unterminated payload script fails cleanly', function () {
    expect(fn () => ReclubPageParser::parse('<script id="__NUXT_DATA__">[{"participants":1}'))->toThrow(ReclubParseException::class);
});
