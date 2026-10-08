<?php

use App\Services\Share\PodiumData;
use App\Services\Share\PodiumEntry;
use App\Services\Share\PodiumImageRenderer;

function podiumOf(array $entries, string $club = 'Sunrise Pickleball Club'): PodiumData
{
    return new PodiumData($club, 'Thursday Open Play', 'October 9, 2026', $entries);
}

dataset('podiums', [
    'one entry' => [[new PodiumEntry(1, 'Maria Santos', 3, 0, 100)]],
    'two entries' => [[new PodiumEntry(1, 'Maria Santos', 3, 0, 100), new PodiumEntry(2, 'Bob Smith', 2, 1, 67)]],
    'three entries' => [[new PodiumEntry(1, 'Maria Santos', 6, 0, 100), new PodiumEntry(2, 'Bob Smith', 4, 2, 67), new PodiumEntry(3, 'Ace', 3, 3, 50)]],
    'ties' => [[new PodiumEntry(1, 'Ana', 5, 1, 83), new PodiumEntry(1, 'Ben', 5, 1, 83), new PodiumEntry(3, 'Cy', 4, 2, 67)]],
    'empty name' => [[new PodiumEntry(1, '', 2, 0, 100), new PodiumEntry(2, '   ', 1, 1, 50)]],
    'emoji name' => [[new PodiumEntry(1, 'Ace 🏓🔥', 3, 0, 100), new PodiumEntry(2, '🎾', 1, 2, 33)]],
    'long accented names' => [[
        new PodiumEntry(1, 'José Álvarez-Fernández de la Cruz y Montenegro', 6, 0, 100),
        new PodiumEntry(2, 'Zoë Müller-Łukasiewicz', 4, 2, 67),
        new PodiumEntry(3, str_repeat('Wolfeschlegelsteinhausenbergerdorff', 3), 3, 3, 50),
    ]],
]);

test('it renders a 1080 square PNG', function (array $entries) {
    $png = (new PodiumImageRenderer)->png(podiumOf($entries));
    $info = getimagesizefromstring($png);

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and($info[0])->toBe(1080)->and($info[1])->toBe(1080)->and($info['mime'])->toBe('image/png');
})->with('podiums');

test('it renders an 800 square looping GIF', function (array $entries) {
    $gif = (new PodiumImageRenderer)->gif(podiumOf($entries));
    $info = getimagesizefromstring($gif);

    expect(substr($gif, 0, 6))->toBe('GIF89a')
        ->and($gif)->toContain('NETSCAPE2.0')
        ->and(ord($gif[strlen($gif) - 1]))->toBe(0x3B)
        ->and(substr_count($gif, "\x21\xF9\x04"))->toBeGreaterThan(10)
        ->and($info[0])->toBe(800)->and($info[1])->toBe(800)->and($info['mime'])->toBe('image/gif')
        ->and(strlen($gif))->toBeLessThan(1_500_000);
})->with('podiums');

test('the podium hash changes with the scores and not otherwise', function () {
    $a = podiumOf([new PodiumEntry(1, 'Ana', 5, 1, 83)]);
    $b = podiumOf([new PodiumEntry(1, 'Ana', 5, 1, 83)]);
    $c = podiumOf([new PodiumEntry(1, 'Ana', 5, 2, 71)]);

    expect($a->hash())->toBe($b->hash())->and($a->hash())->not->toBe($c->hash());
});
