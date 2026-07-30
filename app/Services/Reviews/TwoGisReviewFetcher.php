<?php

namespace App\Services\Reviews;

use App\Models\ReviewClaim;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches branch reviews from 2GIS public reviews API when firm id is configured.
 */
class TwoGisReviewFetcher implements ReviewSourceInterface
{
    public function source(): string
    {
        return ReviewClaim::SOURCE_2GIS;
    }

    public function isConfigured(): bool
    {
        return filled(config('club.reviews.twogis_firm_id'));
    }

    public function fetch(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $firmId = (string) config('club.reviews.twogis_firm_id');
        $apiKey = (string) config('club.reviews.twogis_api_key', '');
        $pages = max(1, (int) config('club.reviews.fetch_pages', 5));
        $pageSize = max(1, min(50, (int) config('club.reviews.page_size', 50)));

        $reviews = [];
        $offset = 0;

        try {
            for ($page = 1; $page <= $pages; $page++) {
                $query = [
                    'limit' => $pageSize,
                    'offset' => $offset,
                    'is_advertiser' => 'false',
                    'fields' => 'meta.providers,meta.branch_rating,meta.branch_reviews_count,meta.total_count,reviews.hiding_reason,reviews.is_verified,reviews.likes_count,reviews.official_answer,reviews.photos',
                ];
                if ($apiKey !== '') {
                    $query['key'] = $apiKey;
                }

                $url = "https://public-api.reviews.2gis.com/2.0/branches/{$firmId}/reviews";
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->withHeaders(['User-Agent' => 'Sector0451ReviewBot/1.0'])
                    ->get($url, $query);

                if (! $response->successful()) {
                    Log::warning('2GIS reviews fetch failed', [
                        'status' => $response->status(),
                        'body' => mb_substr($response->body(), 0, 300),
                    ]);
                    break;
                }

                $items = data_get($response->json(), 'reviews', []);
                if (! is_array($items) || $items === []) {
                    break;
                }

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $text = trim((string) ($item['text'] ?? ''));
                    if ($text === '') {
                        continue;
                    }

                    $id = (string) ($item['id'] ?? '');
                    if ($id === '') {
                        $id = '2gis_'.substr(sha1($text), 0, 16);
                    }

                    $rating = (float) ($item['rating'] ?? 0);
                    $author = data_get($item, 'user.name') ?? data_get($item, 'author_name');
                    $authorId = data_get($item, 'user.public_id')
                        ?? data_get($item, 'user.id')
                        ?? data_get($item, 'user_id');

                    $reviews[] = new RemoteReview(
                        id: $id,
                        text: $text,
                        rating: $rating,
                        source: ReviewClaim::SOURCE_2GIS,
                        url: config('club.reviews.twogis_url') ?: "https://2gis.ru/firm/{$firmId}",
                        author: is_string($author) ? $author : null,
                        authorId: is_scalar($authorId) ? (string) $authorId : null,
                    );
                }

                if (count($items) < $pageSize) {
                    break;
                }

                $offset += $pageSize;
            }
        } catch (\Throwable $e) {
            Log::error('2GIS reviews exception', ['message' => $e->getMessage()]);
        }

        return $reviews;
    }
}
