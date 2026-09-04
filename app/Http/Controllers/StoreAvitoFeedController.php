<?php

namespace App\Http\Controllers;

use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoSetting;
use App\Services\StoreAvito\StoreAvitoFeedService;

class StoreAvitoFeedController extends Controller
{
    public function feed(string $token, StoreAvitoFeedService $feed)
    {
        $settings = StoreAvitoSetting::current();
        abort_unless(filled($settings->feed_token) && hash_equals($settings->feed_token, $token), 404);

        return response($feed->xml($settings), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function image(string $token, string $configId, int $sku, int $index, StoreAvitoFeedService $feed)
    {
        $settings = StoreAvitoSetting::current();
        abort_unless(filled($settings->feed_token) && hash_equals($settings->feed_token, $token), 404);
        abort_unless(StoreAvitoAd::query()->where('config_id', $configId)->exists(), 404);

        return $feed->streamCatalogImage($sku, $index);
    }
}
