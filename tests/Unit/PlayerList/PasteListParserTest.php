<?php

use App\Domain\PlayerList\PasteListParser;

/** @return list<string> */
function pastedNames(string $text): array
{
    return array_map(fn ($p): string => $p->name, PasteListParser::parse($text)->players);
}

test('one player per line, blank lines skipped, line numbers kept', function () {
    $result = PasteListParser::parse("Ana Reyes\n\n  \nBen Cruz\r\nCara Lim");

    expect($result->error)->toBeNull()
        ->and(array_map(fn ($p) => [$p->line, $p->name], $result->players))->toBe([[1, 'Ana Reyes'], [4, 'Ben Cruz'], [5, 'Cara Lim']]);
});

test('numbering and bullets are stripped', function (string $line) {
    expect(pastedNames($line))->toBe(['Ana Reyes']);
})->with([
    '1. Ana Reyes',
    '1) Ana Reyes',
    '12) Ana Reyes',
    '1 - Ana Reyes',
    '#1 Ana Reyes',
    '# 1. Ana Reyes',
    '- Ana Reyes',
    '* Ana Reyes',
    '• Ana Reyes',
    '- 1. Ana Reyes',
    '   3.    Ana Reyes   ',
    'Ana Reyes',
]);

test('names that merely contain digits or dashes are kept', function () {
    expect(pastedNames("Mary-Jane Cruz\nJ2 Lee\n3 Amigos"))->toBe(['Mary-Jane Cruz', 'J2 Lee', '3 Amigos']);
});

test('whitespace is trimmed and collapsed', function () {
    expect(pastedNames("  Ana \t  Maria   Reyes  "))->toBe(['Ana Maria Reyes']);
});

test('details after the name are read, with any key case and the accepted aliases', function () {
    $players = PasteListParser::parse("1. Ana Reyes | rating=4.2 | duprId=ab12cd\nBen | RATING = 3.5 | dupr_id=XY99ZZ\nCara | duprid=QQ11WW\nDan | dupr=ZZ00YY")->players;

    expect($players[0]->name)->toBe('Ana Reyes')->and($players[0]->rating)->toBe('4.2')->and($players[0]->duprId)->toBe('ab12cd')
        ->and($players[1]->rating)->toBe('3.5')->and($players[1]->duprId)->toBe('XY99ZZ')
        ->and($players[2]->duprId)->toBe('QQ11WW')
        ->and($players[3]->duprId)->toBe('ZZ00YY')
        ->and($players[0]->warnings)->toBe([]);
});

test('unknown details and segments without a key are ignored with a warning', function () {
    $player = PasteListParser::parse('Ana | color=red | nonsense | rating=4.0 | ')->players[0];

    expect($player->rating)->toBe('4.0')
        ->and($player->warnings)->toHaveCount(2)
        ->and($player->warnings[0])->toContain('color')
        ->and($player->warnings[1])->toContain('nonsense');
});

test('empty values stay empty and a line with only a bullet has an empty name', function () {
    $players = PasteListParser::parse("Ana | rating=\n-\n")->players;

    expect($players[0]->rating)->toBe('')->and($players[1]->name)->toBe('');
});

test('a paste with no players is an error', function () {
    expect(PasteListParser::parse('')->error)->not->toBeNull()
        ->and(PasteListParser::parse("  \n \n")->error)->not->toBeNull();
});

test('more than 200 non-blank lines is an error, exactly 200 is fine, blank lines do not count', function () {
    $ok = implode("\n\n", array_map(fn (int $i): string => "Player {$i}", range(1, 200)));
    $tooMany = implode("\n", array_map(fn (int $i): string => "Player {$i}", range(1, 201)));

    expect(PasteListParser::parse($ok)->error)->toBeNull()
        ->and(count(PasteListParser::parse($ok)->players))->toBe(200)
        ->and(PasteListParser::parse($tooMany)->error)->toContain('200');
});

test('more than 20 KB is an error', function () {
    expect(PasteListParser::parse(str_repeat('a', PasteListParser::MAX_BYTES + 1))->error)->toContain('20 KB')
        ->and(PasteListParser::parse(str_repeat('a', PasteListParser::MAX_BYTES))->error)->toBeNull();
});

test('text that is not UTF-8 is an error and a BOM is ignored', function () {
    expect(PasteListParser::parse("Ana\xFF\xFE")->error)->toContain('UTF-8')
        ->and(pastedNames("\xEF\xBB\xBFJosé Álvarez"))->toBe(['José Álvarez']);
});

// --- gender (P16.8) ---

test('gender is read from the gender, g and sex keys, in any case', function () {
    $players = PasteListParser::parse("Ana | gender=woman\nBen | G=man\nCara | SEX = Woman\nDan | Gender=MAN")->players;

    expect(array_map(fn ($p): string => $p->gender, $players))->toBe(['woman', 'man', 'woman', 'man'])
        ->and($players[0]->warnings)->toBe([]);
});

test('gender values are normalised to man or woman', function () {
    $genders = fn (string $value): string => PasteListParser::parse("Ana | gender={$value}")->players[0]->gender;

    foreach (['man', 'm', 'male', 'MALE', ' M '] as $value) {
        expect($genders($value))->toBe('man');
    }
    foreach (['woman', 'w', 'f', 'female', 'Female', 'W'] as $value) {
        expect($genders($value))->toBe('woman');
    }
});

test('an unrecognised gender keeps its raw text for the review to flag, and an absent or blank one is empty', function () {
    $players = PasteListParser::parse("Ana | gender=other\nBen | rating=4\nCara | gender=")->players;

    expect($players[0]->gender)->toBe('other')
        ->and($players[0]->warnings)->toBe([])
        ->and($players[1]->gender)->toBe('')
        ->and($players[2]->gender)->toBe('');
});

test('gender combines with the other details in any order', function () {
    $p = PasteListParser::parse('1. Ana Reyes | gender=f | rating=4.2 | duprId=ABC123')->players[0];

    expect($p->name)->toBe('Ana Reyes')->and($p->gender)->toBe('woman')->and($p->rating)->toBe('4.2')->and($p->duprId)->toBe('ABC123');
});
