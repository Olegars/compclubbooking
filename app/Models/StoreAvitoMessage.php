<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreAvitoMessage extends Model
{
    protected $fillable = [
        'chat_id', 'avito_message_id', 'author_id', 'type', 'content',
        'from_us', 'admin_id', 'read', 'avito_created_at',
    ];

    protected $casts = [
        'content' => 'array',
        'from_us' => 'boolean',
        'read' => 'boolean',
        'avito_created_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(StoreAvitoChat::class, 'chat_id', 'chat_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function text(): string
    {
        $content = $this->content;
        if (! is_array($content)) {
            return '';
        }

        return trim((string) ($content['text'] ?? ''));
    }

    /**
     * @return array<string, string>
     */
    public function imageSizes(): array
    {
        $content = is_array($this->content) ? $this->content : [];
        $sizes = data_get($content, 'image.sizes', data_get($content, 'images'));
        if (! is_array($sizes)) {
            return [];
        }
        $out = [];
        foreach ($sizes as $key => $url) {
            if (is_string($url) && $url !== '') {
                $out[(string) $key] = $url;
            }
        }

        return $out;
    }

    public function imageUrl(bool $thumb = false): ?string
    {
        $sizes = $this->imageSizes();
        if ($sizes === []) {
            return null;
        }
        $order = $thumb
            ? ['140x105', '32x32', '640x480', '1280x960']
            : ['1280x960', '640x480', '140x105', '32x32'];
        foreach ($order as $key) {
            if (! empty($sizes[$key])) {
                return $sizes[$key];
            }
        }
        $best = null;
        $bestW = -1;
        foreach ($sizes as $dim => $url) {
            $w = (int) explode('x', (string) $dim)[0];
            if ($w >= $bestW) {
                $bestW = $w;
                $best = $url;
            }
        }

        return $best ?: (string) reset($sizes);
    }

    /**
     * @return array{title:?string, url:?string, price:?string, image:?string}|null
     */
    public function itemPreview(): ?array
    {
        $item = data_get($this->content, 'item');
        if (! is_array($item)) {
            return null;
        }

        $image = $item['image_url'] ?? $item['image'] ?? null;
        if (is_array($image)) {
            $image = $image['640x480'] ?? $image['140x105'] ?? reset($image);
        }

        return [
            'title' => isset($item['title']) ? (string) $item['title'] : null,
            'url' => (string) ($item['item_url'] ?? $item['url'] ?? ''),
            'price' => isset($item['price_string']) ? (string) $item['price_string'] : null,
            'image' => is_string($image) && $image !== '' ? $image : null,
        ];
    }

    /**
     * @return array{title:?string, url:?string, description:?string, image:?string}|null
     */
    public function linkPreview(): ?array
    {
        $link = data_get($this->content, 'link');
        if (! is_array($link)) {
            return null;
        }
        $preview = is_array($link['preview'] ?? null) ? $link['preview'] : [];
        $images = is_array($preview['images'] ?? null) ? $preview['images'] : [];
        $title = (string) ($preview['title'] ?? $link['text'] ?? $preview['domain'] ?? '');
        $url = (string) ($link['url'] ?? $preview['url'] ?? '');
        if ($title === '' && $url === '') {
            return null;
        }

        return [
            'title' => $title,
            'url' => $url,
            'description' => isset($preview['description']) ? (string) $preview['description'] : null,
            'image' => (string) ($images['640x480'] ?? $images['1280x960'] ?? $images['140x105'] ?? ''),
        ];
    }

    public function isVoice(): bool
    {
        return $this->type === 'voice' || is_array(data_get($this->content, 'voice'));
    }

    public function voiceId(): ?string
    {
        $id = data_get($this->content, 'voice.voice_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function locationText(): ?string
    {
        $location = data_get($this->content, 'location');
        if (! is_array($location)) {
            return $this->type === 'location' ? 'Геометка' : null;
        }
        $text = trim((string) ($location['title'] ?? $location['text'] ?? ''));
        if ($text !== '') {
            return $text;
        }

        return $this->type === 'location' ? 'Геометка' : null;
    }

    public function callLabel(): ?string
    {
        if ($this->type !== 'call' && $this->type !== 'appCall' && ! is_array(data_get($this->content, 'call'))) {
            return null;
        }
        $status = (string) data_get($this->content, 'call.status', '');

        return $status === 'missed' ? 'Пропущенный звонок' : 'Звонок Avito';
    }
}
