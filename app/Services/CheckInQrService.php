<?php

namespace App\Services;

use App\Models\PlaySession;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders the check-in QR for a session as an SVG string.
 */
class CheckInQrService
{
    /** Absolute URL the QR encodes, or null when the session has no active token. */
    public function url(PlaySession $session): ?string
    {
        return $session->checkin_token === null ? null : url('/checkin/'.$session->checkin_token);
    }

    public function svg(PlaySession $session, int $size = 256): ?string
    {
        $url = $this->url($session);

        if ($url === null) {
            return null;
        }

        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return $writer->writeString($url);
    }
}
