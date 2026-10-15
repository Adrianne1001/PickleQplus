<?php

namespace App\Services;

use App\Domain\PlayerList\ReclubEvent;
use App\Domain\PlayerList\ReclubPageParser;
use App\Domain\PlayerList\ReclubParseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Fetches a Reclub event page and reads its confirmed players (Phase 16). There is no official
 * Reclub API, so this reads the public page and can break if Reclub changes it.
 *
 * Only `https://reclub.co/...` and `https://www.reclub.co/...` are fetched (no other host, no IP,
 * no port, no credentials), with a 10 second timeout, a 2 MB cap and no redirects (a redirect is
 * an error). At most 10 fetches per minute per subject (a user, or a guest club). Every error is a
 * ValidationException under the key `reclub`.
 */
class ReclubFetcher
{
    /** @var list<string> */
    public const HOSTS = ['reclub.co', 'www.reclub.co'];

    public const MAX_BYTES = 2 * 1024 * 1024;

    public const TIMEOUT_SECONDS = 10;

    public const PER_MINUTE = 10;

    public const MAX_URL_LENGTH = 2048;

    public const UNREADABLE = "Couldn't read players from that Reclub link.";

    /**
     * @param  string  $subject  Who the rate limit is kept for, e.g. "user:12" or "club:7".
     *
     * @throws ValidationException
     */
    public function fetch(string $url, string $subject): ReclubEvent
    {
        $clean = $this->validatedUrl($url);

        $key = "reclub:fetch:{$subject}";
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw $this->error('Too many Reclub imports. Please wait a minute and try again.');
        }
        RateLimiter::hit($key, 60);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                // Identity encoding and no automatic decoding, so the cap below counts the bytes on the
                // wire and a compressed body cannot expand past it.
                ->withHeaders(['Accept' => 'text/html', 'Accept-Encoding' => 'identity', 'User-Agent' => 'PickleQ+ player list import'])
                ->withOptions([
                    'allow_redirects' => false,
                    'decode_content' => false,
                    // Stream the body: it is read in chunks below and the read stops at the cap.
                    'stream' => true,
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                            throw new RuntimeException('Response too large.');
                        }
                    },
                ])
                ->get($clean);
        } catch (Throwable) {
            throw $this->error("Couldn't reach Reclub. Check the link and try again.");
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            throw $this->error('That Reclub link redirects somewhere else. Open it in your browser and copy the final link.');
        }
        if (! $response->successful()) {
            throw $this->error(self::UNREADABLE);
        }

        $body = $this->readCapped($response->toPsrResponse()->getBody());

        try {
            return ReclubPageParser::parse($body);
        } catch (ReclubParseException) {
            throw $this->error(self::UNREADABLE);
        }
    }

    /**
     * Read the body in chunks and give up as soon as it is over the cap, so an endless or
     * chunked body is never downloaded in full.
     *
     * @throws ValidationException
     */
    private function readCapped(StreamInterface $stream): string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        try {
            while (! $stream->eof()) {
                $body .= $stream->read(8192);
                if (strlen($body) > self::MAX_BYTES) {
                    break;
                }
            }
        } catch (Throwable) {
            throw $this->error("Couldn't reach Reclub. Check the link and try again.");
        }

        if (strlen($body) > self::MAX_BYTES) {
            throw $this->error('That page is too large to read.');
        }

        return $body;
    }

    /**
     * The normalised URL, or a ValidationException when it isn't an https reclub.co link.
     *
     * @throws ValidationException
     */
    public function validatedUrl(string $url): string
    {
        $url = trim($url);
        $invalid = 'Enter a Reclub event link (https://reclub.co/...).';

        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw $this->error($invalid);
        }

        $parts = parse_url($url);
        $host = $parts === false ? '' : strtolower($parts['host'] ?? '');
        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($host, self::HOSTS, true)) {
            throw $this->error($invalid);
        }

        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return 'https://'.$host.($path === '' ? '/' : $path).$query;
    }

    private function error(string $message): ValidationException
    {
        return ValidationException::withMessages(['reclub' => $message]);
    }
}
