<?php

namespace App\Support\Gif;

use GdImage;
use InvalidArgumentException;

/**
 * Stitches single-frame GIFs (for example GD's `imagegif()` output) into one
 * looping GIF89a. Each frame keeps its own palette as a local colour table, gets
 * a Graphic Control Extension (delay) and the file carries the NETSCAPE2.0
 * infinite-loop extension. Pure PHP: no framework, no facades.
 */
final class AnimatedGifEncoder
{
    /** @var list<array{delay: int, table: string, bits: int, width: int, height: int, left: int, top: int, interlaced: bool, data: string}> */
    private array $frames = [];

    private int $width = 0;

    private int $height = 0;

    /** Add a GD image (palette image preferred) shown for $delayCs hundredths of a second. */
    public function addImage(GdImage $image, int $delayCs): self
    {
        ob_start();
        imagegif($image);

        return $this->addGif((string) ob_get_clean(), $delayCs);
    }

    /** Add a complete single-frame GIF file. */
    public function addGif(string $gif, int $delayCs): self
    {
        $frame = $this->parse($gif);
        $frame['delay'] = max(0, min(65535, $delayCs));
        if ($this->frames === []) {
            $this->width = $frame['width'];
            $this->height = $frame['height'];
        }
        $this->frames[] = $frame;

        return $this;
    }

    public function frameCount(): int
    {
        return count($this->frames);
    }

    /** @param  int  $loops  0 = loop forever */
    public function encode(int $loops = 0): string
    {
        if ($this->frames === []) {
            throw new InvalidArgumentException('A GIF needs at least one frame.');
        }

        $out = 'GIF89a'.pack('v', $this->width).pack('v', $this->height)."\x00\x00\x00";
        $out .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01".pack('v', $loops)."\x00";

        foreach ($this->frames as $f) {
            // Graphic Control Extension: disposal 1 (leave in place), no transparency.
            $out .= "\x21\xF9\x04\x04".pack('v', $f['delay'])."\x00\x00";
            $out .= "\x2C".pack('v', $f['left']).pack('v', $f['top']).pack('v', $f['width']).pack('v', $f['height'])
                .chr(0x80 | ($f['interlaced'] ? 0x40 : 0) | ($f['bits'] - 1)).$f['table'].$f['data'];
        }

        return $out."\x3B";
    }

    /**
     * @return array{delay: int, table: string, bits: int, width: int, height: int, left: int, top: int, interlaced: bool, data: string}
     */
    private function parse(string $gif): array
    {
        if (strlen($gif) < 13 || ! str_starts_with($gif, 'GIF8')) {
            throw new InvalidArgumentException('Not a GIF.');
        }

        $packed = ord($gif[10]);
        $pos = 13;
        $globalTable = '';
        $globalBits = 0;
        if ($packed & 0x80) {
            $globalBits = ($packed & 7) + 1;
            $len = 3 * (1 << $globalBits);
            $globalTable = substr($gif, $pos, $len);
            $pos += $len;
        }

        $size = strlen($gif);
        while ($pos < $size) {
            $b = ord($gif[$pos]);
            if ($b === 0x21) { // extension: skip its sub-blocks
                $pos += 2;
                $pos = $this->skipSubBlocks($gif, $pos);

                continue;
            }
            if ($b !== 0x2C) {
                break;
            }

            $left = $this->u16($gif, $pos + 1);
            $top = $this->u16($gif, $pos + 3);
            $w = $this->u16($gif, $pos + 5);
            $h = $this->u16($gif, $pos + 7);
            $imgPacked = ord($gif[$pos + 9]);
            $pos += 10;

            if ($imgPacked & 0x80) {
                $bits = ($imgPacked & 7) + 1;
                $len = 3 * (1 << $bits);
                $table = substr($gif, $pos, $len);
                $pos += $len;
            } elseif ($globalTable !== '') {
                $bits = $globalBits;
                $table = $globalTable;
            } else {
                throw new InvalidArgumentException('GIF frame has no colour table.');
            }

            $start = $pos;
            $pos = $this->skipSubBlocks($gif, $pos + 1); // LZW minimum code size byte + data blocks

            return [
                'delay' => 0, 'table' => $table, 'bits' => $bits,
                'width' => $w, 'height' => $h, 'left' => $left, 'top' => $top, 'interlaced' => ($imgPacked & 0x40) !== 0,
                'data' => substr($gif, $start, $pos - $start),
            ];
        }

        throw new InvalidArgumentException('GIF has no image data.');
    }

    private function skipSubBlocks(string $gif, int $pos): int
    {
        $size = strlen($gif);
        while ($pos < $size) {
            $len = ord($gif[$pos]);
            $pos += 1 + $len;
            if ($len === 0) {
                break;
            }
        }

        return $pos;
    }

    private function u16(string $s, int $pos): int
    {
        return ord($s[$pos]) | (ord($s[$pos + 1]) << 8);
    }
}
