<?php

use App\Support\Gif\AnimatedGifEncoder;

function gdFrame(int $w, int $h, int $r, int $g, int $b): GdImage
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
    imagetruecolortopalette($img, false, 16);

    return $img;
}

test('it writes a looping GIF89a with one image per frame', function () {
    $enc = new AnimatedGifEncoder;
    $enc->addImage(gdFrame(40, 30, 255, 0, 0), 8)
        ->addImage(gdFrame(40, 30, 0, 255, 0), 8)
        ->addImage(gdFrame(40, 30, 0, 0, 255), 300);
    $gif = $enc->encode();
    $size = getimagesizefromstring($gif);

    expect($enc->frameCount())->toBe(3)
        ->and(substr($gif, 0, 6))->toBe('GIF89a')
        ->and($gif)->toContain("\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00")
        ->and(ord($gif[strlen($gif) - 1]))->toBe(0x3B)
        ->and(substr_count($gif, "\x21\xF9\x04"))->toBe(3)
        ->and($size[0])->toBe(40)
        ->and($size[1])->toBe(30);
});

test('each frame has a local colour table and its own delay', function () {
    $enc = new AnimatedGifEncoder;
    $enc->addImage(gdFrame(10, 10, 255, 0, 0), 8)->addImage(gdFrame(10, 10, 0, 0, 255), 300);
    $gif = $enc->encode();

    // Walk the file: header(6) + screen(7) + NETSCAPE(19), then GCE + descriptor per frame.
    $pos = 13 + 19;
    $delays = [];
    $locals = 0;
    $images = 0;
    while (ord($gif[$pos]) !== 0x3B) {
        expect(substr($gif, $pos, 3))->toBe("\x21\xF9\x04");
        $delays[] = unpack('v', substr($gif, $pos + 4, 2))[1];
        $pos += 8;
        expect(ord($gif[$pos]))->toBe(0x2C);
        $packed = ord($gif[$pos + 9]);
        $locals += ($packed & 0x80) ? 1 : 0;
        $images++;
        $pos += 10 + 3 * (1 << (($packed & 7) + 1));
        $pos++; // LZW minimum code size
        while (($len = ord($gif[$pos])) !== 0) {
            $pos += $len + 1;
        }
        $pos++;
    }

    expect($images)->toBe(2)->and($locals)->toBe(2)->and($delays)->toBe([8, 300]);
});

test('it rejects an empty animation and a non-GIF', function () {
    expect(fn () => (new AnimatedGifEncoder)->encode())->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new AnimatedGifEncoder)->addGif('nope', 5))->toThrow(InvalidArgumentException::class);
});

test('a single frame is a valid GIF', function () {
    $enc = new AnimatedGifEncoder;
    $gif = $enc->addImage(gdFrame(12, 9, 10, 200, 30), 50)->encode();

    expect($enc->frameCount())->toBe(1)
        ->and(substr_count($gif, "\x21\xF9\x04"))->toBe(1)
        ->and(ord($gif[strlen($gif) - 1]))->toBe(0x3B)
        ->and(getimagesizefromstring($gif)[0])->toBe(12);
});

test('the interlace bit of a frame is carried over', function () {
    $img = gdFrame(8, 8, 1, 2, 3);
    imageinterlace($img, true);
    $gif = (new AnimatedGifEncoder)->addImage($img, 5)->encode();

    $pos = 13 + 19 + 8; // after the screen descriptor, NETSCAPE and the GCE
    expect(ord($gif[$pos]))->toBe(0x2C)->and(ord($gif[$pos + 9]) & 0x40)->toBe(0x40);
});
