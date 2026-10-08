<?php

use App\Models\PlaySession;
use App\Services\CheckInQrService;

it('renders any url as an svg qr code', function (): void {
    $svg = (new CheckInQrService)->svgForUrl('https://example.com/c/club/s/abc');

    expect($svg)->toContain('<svg');
});

it('respects the requested size', function (): void {
    $svg = (new CheckInQrService)->svgForUrl('https://example.com/x', 400);

    expect($svg)->toContain('width="400"')->and($svg)->toContain('height="400"');
});

it('keeps svg() identical to svgForUrl() of the check-in url and null without a token', function (): void {
    $service = new CheckInQrService;
    $session = new PlaySession;

    expect($service->svg($session))->toBeNull();

    $session->checkin_token = 'tok123';
    expect($service->svg($session, 300))->toBe($service->svgForUrl(url('/checkin/tok123'), 300));
});

it('caches the rendered svg per url and size', function (): void {
    $service = new CheckInQrService;
    $url = 'https://example.com/cache-me';
    $key = 'qr:svg:'.sha1($url.'|256');

    expect(Cache::has($key))->toBeFalse();
    $first = $service->svgForUrl($url);
    expect(Cache::has($key))->toBeTrue();

    Cache::put($key, 'cached-marker', 60);
    expect($service->svgForUrl($url))->toBe('cached-marker')
        ->and($first)->toContain('<svg')
        ->and($service->svgForUrl($url, 300))->toContain('<svg');
});
