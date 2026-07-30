<?php

namespace App\Services\Reviews;

use App\Models\ReviewClaim;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches org reviews from the public Yandex Maps org page.
 *
 * Primary path: parse embedded state-view JSON on /maps/org/{id}/reviews/
 * (fetchReviews currently returns 400 without browser signing).
 */
class YandexMapsReviewFetcher implements ReviewSourceInterface
{
    public function source(): string
    {
        return ReviewClaim::SOURCE_YANDEX;
    }

    public function isConfigured(): bool
    {
        return filled(config('club.reviews.yandex_org_id'));
    }

    public function fetch(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $businessId = (string) config('club.reviews.yandex_org_id');
        $url = "https://yandex.ru/maps/org/{$businessId}/reviews/";

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'ru-RU,ru;q=0.9',
                ])
                ->get($url);

            if (! $response->successful()) {
                Log::warning('Yandex org reviews page failed', [
                    'status' => $response->status(),
                    'url' => $url,
                ]);

                return [];
            }

            $state = $this->extractStateView($response->body());
            if ($state === null) {
                Log::warning('Yandex org reviews: state-view not found', ['url' => $url]);

                return [];
            }

            $rawItems = $this->extractReviewItems($state);

            return $this->mapReviews($rawItems, $businessId);
        } catch (\Throwable $e) {
            Log::error('Yandex org reviews exception', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractStateView(string $html): ?array
    {
        if (! preg_match('/<script[^>]*class="state-view"[^>]*type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $m)
            && ! preg_match('/<script[^>]*type="application\/json"[^>]*class="state-view"[^>]*>(.*?)<\/script>/s', $html, $m)) {
            return null;
        }

        $decoded = json_decode($m[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function extractReviewItems(array $payload): array
    {
        $candidates = [
            data_get($payload, 'stack.0.results.items.0.reviewResults.reviews'),
            data_get($payload, 'reviews.items'),
            data_get($payload, 'reviews.list'),
            data_get($payload, 'data.reviews'),
            data_get($payload, 'reviews'),
        ];

        foreach ($candidates as $list) {
            if (is_array($list) && $list !== [] && array_is_list($list) && is_array($list[0] ?? null)) {
                if (isset($list[0]['text']) || isset($list[0]['reviewId']) || isset($list[0]['rating'])) {
                    return $list;
                }
            }
        }

        return [];
    }

    /**
     * @param  list<mixed>  $rawItems
     * @return list<RemoteReview>
     */
    private function mapReviews(array $rawItems, string $businessId): array
    {
        $out = [];

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $id = (string) ($item['reviewId'] ?? $item['id'] ?? '');
            if ($id === '') {
                $id = 'yandex_'.substr(sha1($text), 0, 16);
            }

            $rating = (float) ($item['rating'] ?? $item['stars'] ?? 0);
            $author = data_get($item, 'author.name') ?? data_get($item, 'authorName');
            if (is_array($author)) {
                $author = $author['name'] ?? null;
            }

            $authorId = data_get($item, 'author.publicId')
                ?? data_get($item, 'author.id')
                ?? data_get($item, 'authorPublicId');

            $reviewedAt = null;
            $updated = $item['updatedTime'] ?? $item['updated_at'] ?? $item['date'] ?? null;
            if (is_numeric($updated)) {
                $reviewedAt = \Carbon\Carbon::createFromTimestamp((int) $updated);
            } elseif (is_string($updated) && $updated !== '') {
                try {
                    $reviewedAt = \Carbon\Carbon::parse($updated);
                } catch (\Throwable) {
                    $reviewedAt = null;
                }
            }

            $out[] = new RemoteReview(
                id: $id,
                text: $text,
                rating: $rating,
                source: ReviewClaim::SOURCE_YANDEX,
                url: "https://yandex.ru/maps/org/{$businessId}/reviews/",
                author: is_string($author) ? $author : null,
                authorId: is_string($authorId) && $authorId !== '' ? $authorId : null,
                reviewedAt: $reviewedAt,
            );
        }

        return $out;
    }
}
