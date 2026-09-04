<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoSetting;
use App\Services\QuickFoxApiClient;
use App\Services\StoreSupplierCatalogImageService;

class StoreAvitoFeedService
{
    public function xml(?StoreAvitoSetting $settings = null): string
    {
        $settings ??= StoreAvitoSetting::current();
        $ads = StoreAvitoAd::query()->active()->orderByDesc('id')->get();
        $body = '';
        foreach ($ads as $ad) {
            $body .= $this->adXml($ad, $settings);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<Ads formatVersion="3" target="Avito.ru">'.$body.'</Ads>';
    }

    public function adXml(StoreAvitoAd $ad, ?StoreAvitoSetting $settings = null): string
    {
        $settings ??= StoreAvitoSetting::current();
        $xml = is_array($ad->xml) ? $ad->xml : [];
        $out = '<Ad>';
        $out .= $this->tag('Id', 'pc-'.$ad->config_id);
        if (filled($settings->address)) {
            $out .= $this->tag('Address', (string) $settings->address);
        }
        if (filled($settings->contact_phone)) {
            $out .= $this->tag('ContactPhone', (string) $settings->contact_phone);
        }
        if (filled($settings->manager_name)) {
            $out .= $this->tag('ManagerName', (string) $settings->manager_name);
        }
        foreach ([
            'AdType', 'Condition', 'Title', 'Description', 'Price',
            'Category', 'GoodsSubType', 'Brand', 'Type',
            'BrandProcessor', 'ModelProcessor', 'CodeProcessor',
            'BrandMotherboard', 'ModelMotherboard',
            'BrandVideocard', 'ModelVideocard', 'CodeVideocard',
            'RamSize',
        ] as $key) {
            $value = match ($key) {
                'Title' => $ad->title,
                'Description' => $ad->description,
                'Price' => (string) $ad->price,
                default => $xml[$key] ?? null,
            };
            if ($value === null || $value === '') {
                continue;
            }
            $out .= $this->tag($key, (string) $value, cdata: $key === 'Description');
        }
        $out .= $this->imagesXml($ad, $settings);
        $out .= '<Delivery><Option>Выключена</Option></Delivery>';
        $out .= '</Ad>';

        return $out;
    }

    public function publicImageUrl(StoreAvitoSetting $settings, string $configId, int $sku, int $index = 0): string
    {
        $token = (string) $settings->feed_token;

        return rtrim((string) config('app.url'), '/').'/avito/'.$token.'/img/'.$configId.'/'.$sku.'/'.$index;
    }

    public function streamCatalogImage(int $sku, int $index = 0)
    {
        $images = app(StoreSupplierCatalogImageService::class);
        $api = app(QuickFoxApiClient::class);
        $paths = $images->pathsForSku($sku);
        if ($paths === [] || ! isset($paths[$index])) {
            abort(404);
        }
        $file = $api->downloadProductImage((string) $paths[$index], 'large');

        return response($file['body'], 200, [
            'Content-Type' => $file['content_type'] ?? 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function imagesXml(StoreAvitoAd $ad, StoreAvitoSetting $settings): string
    {
        $images = is_array($ad->images) ? $ad->images : [];
        if ($images === []) {
            return '';
        }
        $tags = '';
        foreach ($images as $i => $image) {
            $sku = (int) ($image['sku'] ?? 0);
            if ($sku <= 0) {
                continue;
            }
            $url = $this->publicImageUrl($settings, $ad->config_id, $sku, (int) ($image['i'] ?? 0));
            $tags .= '<Image url="'.$this->esc($url).'"/>';
            if ($i >= 9) {
                break;
            }
        }

        return $tags === '' ? '' : '<Images>'.$tags.'</Images>';
    }

    private function tag(string $name, string $value, bool $cdata = false): string
    {
        if ($cdata) {
            $safe = str_replace(']]>', ']]]]><![CDATA[>', $value);

            return '<'.$name.'><![CDATA['.$safe.']]></'.$name.'>';
        }

        return '<'.$name.'>'.$this->esc($value).'</'.$name.'>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
