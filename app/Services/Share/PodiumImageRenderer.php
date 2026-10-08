<?php

namespace App\Services\Share;

use App\Support\Gif\AnimatedGifEncoder;
use GdImage;
use RuntimeException;

/**
 * Draws the podium share image with GD and the bundled Plus Jakarta Sans font.
 * Pure PHP (no framework): plain data in, image bytes out. The design is laid out
 * on a 1080 px square and scaled to the requested size.
 *
 * @phpstan-type Rgb array{int, int, int}
 */
class PodiumImageRenderer
{
    /** Bump when the look changes, so cached images are drawn again. */
    public const RENDERER_VERSION = 2;

    public const PNG_SIZE = 1080;

    public const GIF_SIZE = 800;

    private const BASE = 1080;

    /** Animation frames (the last one is the final picture and is held). */
    private const FRAMES = 22;

    private const FRAME_DELAY_CS = 8;

    private const HOLD_DELAY_CS = 300;

    private const GIF_COLOURS = 96;

    private const CONFETTI = 54;

    // Brand tokens (resources/css/app.css).
    private const BRAND_300 = [0x86, 0xEF, 0xAC];

    private const BRAND_400 = [0x4A, 0xDE, 0x80];

    private const BRAND_600 = [0x16, 0xA3, 0x4A];

    private const BRAND_700 = [0x15, 0x80, 0x3D];

    private const BRAND_800 = [0x16, 0x65, 0x34];

    private const BRAND_900 = [0x14, 0x53, 0x2D];

    private const BALL = [0xD9, 0xF0, 0x4A];

    private const CONFETTI_COLOURS = [
        [0xD9, 0xF0, 0x4A], [0xF8, 0xC8, 0x2C], [0x4A, 0xDE, 0x80], [0xFF, 0xFF, 0xFF], [0x86, 0xEF, 0xAC], [0xE0, 0x94, 0x58],
    ];

    private const MEDALS = [
        1 => ['fill' => [0xF8, 0xC8, 0x2C], 'rim' => [0xB7, 0x86, 0x0B]],
        2 => ['fill' => [0xD5, 0xDB, 0xE4], 'rim' => [0x8B, 0x96, 0xA6]],
        3 => ['fill' => [0xE0, 0x94, 0x58], 'rim' => [0x8F, 0x52, 0x20]],
    ];

    private string $fontDir;

    /** @var array<string, GdImage> */
    private array $sprites = [];

    public function __construct(?string $fontDir = null)
    {
        $this->fontDir = $fontDir ?? dirname(__DIR__, 3).'/resources/fonts';
    }

    public function png(PodiumData $data, int $size = self::PNG_SIZE): string
    {
        $img = $this->frame($data, $size, 1.0);
        ob_start();
        imagepng($img, null, 6);

        return (string) ob_get_clean();
    }

    public function gif(PodiumData $data, int $size = self::GIF_SIZE): string
    {
        $encoder = new AnimatedGifEncoder;
        for ($i = 0; $i < self::FRAMES; $i++) {
            $img = $this->frame($data, $size, $i / (self::FRAMES - 1));
            imagetruecolortopalette($img, false, self::GIF_COLOURS);
            $encoder->addImage($img, $i === self::FRAMES - 1 ? self::HOLD_DELAY_CS : self::FRAME_DELAY_CS);
        }

        return $encoder->encode();
    }

    // ------------------------------------------------------------------ frame

    /** One picture of the animation. $p is the progress, 0 (empty stage) to 1 (final picture). */
    private function frame(PodiumData $data, int $size, float $p): GdImage
    {
        $s = $size / self::BASE;
        $size = max(1, $size);
        $img = imagecreatetruecolor($size, $size);
        if ($img === false) {
            throw new RuntimeException('GD could not create an image.');
        }
        imagealphablending($img, true);
        imageantialias($img, true);

        $this->background($img, $size);
        $this->confetti($img, $s, $p);
        $this->header($img, $data, $s);
        $this->podium($img, $data, $s, $p);
        $this->footer($img, $s);

        return $img;
    }

    private function background(GdImage $img, int $size): void
    {
        $top = [0x05, 0x14, 0x0B];
        $bottom = [0x0B, 0x2A, 0x19];
        for ($y = 0; $y < $size; $y++) {
            $t = $y / max(1, $size - 1);
            imagefilledrectangle($img, 0, $y, $size, $y, $this->rgb($img, $this->mix($top, $bottom, $t)));
        }
        // Soft glow behind the podium: concentric translucent ellipses.
        $glow = (int) $this->colour($img, self::BRAND_600, 123);
        for ($i = 10; $i >= 1; $i--) {
            imagefilledellipse($img, intdiv($size, 2), (int) ($size * 0.64), (int) ($size * 0.1 * $i), (int) ($size * 0.075 * $i), $glow);
        }
    }

    private function header(GdImage $img, PodiumData $data, float $s): void
    {
        $cx = $this->mid($s);
        $w = (int) (940 * $s);
        $this->text($img, $cx, (int) (108 * $s), 'SESSION RESULTS', 'SemiBold', 26, self::BRAND_400, $s, $w);
        $this->text($img, $cx, (int) (178 * $s), $data->clubName, 'ExtraBold', 60, [255, 255, 255], $s, $w);
        $this->text($img, $cx, (int) (234 * $s), $data->sessionName, 'Bold', 38, self::BRAND_300, $s, $w);
        $this->text($img, $cx, (int) (282 * $s), $data->dateLabel, 'Regular', 28, [0xA3, 0xA3, 0xA3], $s, $w);
    }

    private function footer(GdImage $img, float $s): void
    {
        $font = $this->font('Bold');
        $pt = 30 * $s * 0.75;
        $bb = imagettfbbox($pt, 0, $font, 'PickleQ+');
        $textW = $bb === false ? 0 : (int) ($bb[2] - $bb[0]);
        $ball = (int) (34 * $s);
        $gap = (int) (12 * $s);
        $x = (int) ((self::BASE * $s - ($ball + $gap + $textW)) / 2);
        $y = (int) (1030 * $s);
        $r = intdiv($ball, 2);
        $cy = $y - (int) (11 * $s);

        $this->disc($img, $x + $r, $cy, $r, self::BALL);
        $dot = $this->rgb($img, [0x8A, 0x9A, 0x1C]);
        $d = max(2, (int) (5 * $s));
        foreach ([[-0.35, -0.2], [0.3, -0.3], [0.05, 0.1], [-0.25, 0.4], [0.4, 0.35]] as [$dx, $dy]) {
            imagefilledellipse($img, (int) ($x + $r + $dx * $r), (int) ($cy + $dy * $r), $d, $d, $dot);
        }
        imagettftext($img, $pt, 0, $x + $ball + $gap, $y, $this->rgb($img, [255, 255, 255]), $font, 'PickleQ+');
    }

    // --------------------------------------------------------------- confetti

    private function confetti(GdImage $img, float $s, float $p): void
    {
        $seed = 20261009;
        $rand = function () use (&$seed): float {
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;

            return $seed / 0x7FFFFFFF;
        };
        $size = self::BASE * $s;

        for ($i = 0; $i < self::CONFETTI; $i++) {
            $xf = $rand() * $size;
            $yf = $rand() * $size * 0.96;
            $delay = $rand();
            $w = (8 + $rand() * 12) * $s;
            $angle = $rand() * M_PI;
            $spin = (2 + $rand() * 4) * (($i % 2) ? 1 : -1);
            $phase = $rand() * 6.28;
            $colour = self::CONFETTI_COLOURS[$i % count(self::CONFETTI_COLOURS)];

            $q = $this->window($p, 0.3 + $delay * 0.35, 0.65 + $delay * 0.35);
            if ($q <= 0) {
                continue;
            }
            $e = $this->ease($q, 'out');
            $x = $xf + sin($q * 9 + $phase) * 26 * $s * (1 - $e);
            $y = $yf * $e - 60 * $s * (1 - $e);
            $a = $angle + $q * $spin;

            // Keep confetti off the header text and the footer, in every frame.
            $margin = $w;
            if ($y > 60 * $s - $margin && $y < 300 * $s + $margin || $y > 985 * $s - $margin) {
                continue;
            }

            $hw = $w / 2;
            $hh = $w / 4;
            $pts = [];
            foreach ([[-$hw, -$hh], [$hw, -$hh], [$hw, $hh], [-$hw, $hh]] as [$px, $py]) {
                $pts[] = (int) round($x + $px * cos($a) - $py * sin($a));
                $pts[] = (int) round($y + $px * sin($a) + $py * cos($a));
            }
            imagefilledpolygon($img, $pts, (int) $this->colour($img, $colour, 40));
        }
    }

    // ----------------------------------------------------------------- podium

    private function podium(GdImage $img, PodiumData $data, float $s, float $p): void
    {
        $entries = array_slice($data->entries, 0, 3);
        $n = count($entries);
        if ($n === 0) {
            return;
        }

        $colW = 280 * $s;
        $gap = 20 * $s;
        $baseY = 940 * $s;
        $scale = $n === 3 ? 1.0 : 0.88;
        $heights = [1 => 440, 2 => 360, 3 => 290];
        $mid = $this->mid($s);
        $step = $colW + $gap;

        // Entry 0 is the middle, 1 left, 2 right. With two entries: 1 left, 0 right.
        $centres = match ($n) {
            1 => [$mid],
            2 => [$mid + $step / 2, $mid - $step / 2],
            default => [$mid, $mid - $step, $mid + $step],
        };

        // Shortest first, so the tallest block and its medal sit on top.
        $order = array_keys($entries);
        usort($order, fn (int $a, int $b): int => $entries[$b]->rank <=> $entries[$a]->rank);

        foreach ($order as $i) {
            $e = $entries[$i];
            $rank = max(1, min(3, $e->rank));
            $st = (3 - $rank) * 0.12;
            $fullH = $heights[$rank] * $scale * $s;
            $rise = $this->ease($this->window($p, $st, $st + 0.46), 'out');
            $pop = $this->ease($this->window($p, $st + 0.38, $st + 0.58), 'back');
            $drop = $this->window($p, $st + 0.5, $st + 0.74);

            $this->block($img, $e, $centres[$i], $baseY, $colW, $fullH, $fullH * $rise, $rank, $s, $pop, $drop);
        }
    }

    private function block(GdImage $img, PodiumEntry $e, float $cx, float $baseY, float $colW, float $fullH, float $h, int $rank, float $s, float $pop, float $drop): void
    {
        if ($h < 2) {
            return;
        }
        $x1 = (int) round($cx - $colW / 2);
        $x2 = (int) round($cx + $colW / 2) - 1;
        $top = (int) round($baseY - $h);
        $bottom = (int) round($baseY);

        [$c1, $c2] = $rank === 1 ? [self::BRAND_600, self::BRAND_800] : [self::BRAND_700, self::BRAND_900];
        $r = max(6, (int) (22 * $s));
        for ($y = $top + $r; $y <= $bottom; $y++) {
            imagefilledrectangle($img, $x1, $y, $x2, $y, $this->rgb($img, $this->mix($c1, $c2, ($y - $top) / max(1, $bottom - $top))));
        }
        imagefilledrectangle($img, $x1 + $r, $top, $x2 - $r, $top + $r, $this->rgb($img, $c1));
        $this->corners($img, $x1, $x2, $top, $r, $c1);
        imagefilledrectangle($img, $x1 + $r, $top, $x2 - $r, $top + max(1, (int) (4 * $s)), $this->rgb($img, $rank === 1 ? self::BRAND_400 : self::BRAND_600));

        // Rank number, faint, at the foot of the block.
        $numFont = $this->font('ExtraBold');
        $numPt = 88 * $s * 0.75;
        $bb = imagettfbbox($numPt, 0, $numFont, (string) $rank);
        if ($bb !== false) {
            imagettftext($img, $numPt, 0, (int) ($cx - ($bb[2] - $bb[0]) / 2 - $bb[0]), $bottom - (int) (22 * $s), (int) $this->colour($img, [255, 255, 255], 108), $numFont, (string) $rank);
        }

        if ($pop > 0) {
            $this->medal($img, (int) round($cx), $top, $rank, $s, $pop);
        }

        if ($drop > 0 && $h >= $fullH - 1) {
            $alpha = (int) round(127 - 127 * min(1.0, $drop * 1.6));
            $offset = (int) ((1 - $this->ease($drop, 'out')) * -40 * $s);
            $inner = (int) ($colW - 36 * $s);
            $this->text($img, (int) round($cx), $top + (int) (116 * $s) + $offset, $e->name, 'ExtraBold', 34, [255, 255, 255], $s, $inner, $alpha);
            $this->text($img, (int) round($cx), $top + (int) (162 * $s) + $offset, $e->wins.'–'.$e->losses.' · '.$e->winPct.'%', 'SemiBold', 28, [0xDC, 0xFC, 0xE7], $s, $inner, $alpha);
        }
    }

    private function medal(GdImage $img, int $cx, int $cy, int $rank, float $s, float $pop): void
    {
        $r = (int) round(50 * $s * max(0.05, $pop));
        if ($r < 3) {
            return;
        }
        $this->disc($img, $cx, $cy, $r, self::MEDALS[$rank]['rim']);
        $this->disc($img, $cx, $cy, (int) round($r * 0.84), self::MEDALS[$rank]['fill']);
        if ($pop >= 0.6) {
            $this->text($img, $cx, $cy + (int) round($r * 0.34), (string) $rank, 'ExtraBold', 46 * $pop, [0x2A, 0x1A, 0x05], $s, (int) ($r * 1.2));
        }
    }

    // ------------------------------------------------------------- primitives

    /**
     * Centred text with a baseline at $y. Shrinks to fit $maxW (down to 60%),
     * then truncates with an ellipsis. $alpha is GD's 0 (opaque) to 127.
     *
     * @param  Rgb  $rgb
     */
    private function text(GdImage $img, int $cx, int $y, string $text, string $weight, float $basePx, array $rgb, float $s, int $maxW, int $alpha = 0): void
    {
        $font = $this->font($weight);
        $px = $basePx * $s;
        $min = $px * 0.6;
        $width = fn (string $t, float $size): float => $this->textWidth($t, $size, $font);

        while ($width($text, $px) > $maxW && $px > $min) {
            $px = max($min, $px * 0.94);
        }
        if ($width($text, $px) > $maxW) {
            $chars = mb_str_split($text);
            while (count($chars) > 1 && $width(rtrim(implode('', $chars)).'…', $px) > $maxW) {
                array_pop($chars);
            }
            $text = rtrim(implode('', $chars)).'…';
        }

        $pt = $px * 0.75;
        $bb = imagettfbbox($pt, 0, $font, $text);
        if ($bb === false) {
            return;
        }
        $x = (int) round($cx - ($bb[2] - $bb[0]) / 2 - $bb[0]);
        imagettftext($img, $pt, 0, $x, $y, (int) $this->colour($img, $rgb, $alpha), $font, $text);
    }

    private function textWidth(string $text, float $px, string $font): float
    {
        $bb = imagettfbbox($px * 0.75, 0, $font, $text);

        return $bb === false ? 0.0 : (float) ($bb[2] - $bb[0]);
    }

    /**
     * Anti-aliased filled circle, drawn 4x and scaled down.
     *
     * @param  Rgb  $rgb
     */
    private function disc(GdImage $img, int $cx, int $cy, int $r, array $rgb): void
    {
        $d = max(2, $r * 2);
        $sprite = $this->sprite($d, $rgb);
        imagecopy($img, $sprite, $cx - intdiv($d, 2), $cy - intdiv($d, 2), 0, 0, $d, $d);
    }

    /**
     * @param  Rgb  $rgb
     */
    private function corners(GdImage $img, int $x1, int $x2, int $top, int $r, array $rgb): void
    {
        $sprite = $this->sprite($r * 2, $rgb);
        imagecopy($img, $sprite, $x1, $top, 0, 0, $r, $r);
        imagecopy($img, $sprite, $x2 - $r + 1, $top, $r, 0, $r, $r);
    }

    /**
     * @param  Rgb  $rgb
     */
    private function sprite(int $d, array $rgb): GdImage
    {
        $key = $d.':'.implode(',', $rgb);
        if (isset($this->sprites[$key])) {
            return $this->sprites[$key];
        }

        $k = 4;
        $d = max(1, $d);
        $big = imagecreatetruecolor($d * $k, $d * $k);
        $small = imagecreatetruecolor($d, $d);
        if ($big === false || $small === false) {
            throw new RuntimeException('GD could not create an image.');
        }
        foreach ([$big, $small] as $canvas) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, $this->colour($canvas, [0, 0, 0], 127));
        }
        imagefilledellipse($big, intdiv($d * $k, 2), intdiv($d * $k, 2), $d * $k - 1, $d * $k - 1, $this->colour($big, $rgb));
        imagecopyresampled($small, $big, 0, 0, 0, 0, $d, $d, $d * $k, $d * $k);

        return $this->sprites[$key] = $small;
    }

    private function font(string $weight): string
    {
        return $this->fontDir.'/PlusJakartaSans-'.$weight.'.ttf';
    }

    /**
     * @param  Rgb  $rgb
     */
    private function rgb(GdImage $img, array $rgb): int
    {
        return $this->colour($img, $rgb);
    }

    /**
     * Allocate a colour, clamped to GD's ranges. $alpha is 0 (opaque) to 127.
     *
     * @param  Rgb  $rgb
     */
    private function colour(GdImage $img, array $rgb, int $alpha = 0): int
    {
        return (int) imagecolorallocatealpha(
            $img,
            max(0, min(255, $rgb[0])),
            max(0, min(255, $rgb[1])),
            max(0, min(255, $rgb[2])),
            max(0, min(127, $alpha)),
        );
    }

    private function mid(float $s): int
    {
        return (int) round(self::BASE * $s / 2);
    }

    /**
     * @param  Rgb  $a
     * @param  Rgb  $b
     * @return Rgb
     */
    private function mix(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    private function window(float $p, float $from, float $to): float
    {
        return max(0.0, min(1.0, ($p - $from) / ($to - $from)));
    }

    private function ease(float $t, string $kind): float
    {
        if ($kind === 'back') {
            $c1 = 1.70158;
            $t1 = $t - 1;

            return $t <= 0 ? 0.0 : 1 + ($c1 + 1) * $t1 ** 3 + $c1 * $t1 ** 2;
        }

        return 1 - (1 - $t) ** 3;
    }
}
