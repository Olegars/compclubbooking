<?php

namespace App\Services;

use App\Models\MapReview;
use App\Models\ReviewClaim;
use App\Models\ReviewSetting;
use App\Models\Transaction;
use App\Services\Reviews\RemoteReview;
use App\Services\Reviews\ReviewSourceInterface;
use App\Services\Reviews\TwoGisReviewFetcher;
use App\Services\Reviews\YandexMapsReviewFetcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReviewBonusService
{
    /**
     * @return list<ReviewSourceInterface>
     */
    public function sources(): array
    {
        return [
            app(YandexMapsReviewFetcher::class),
            app(TwoGisReviewFetcher::class),
        ];
    }

    public function settings(): ReviewSetting
    {
        return ReviewSetting::current();
    }

    public function bonusAmount(): float
    {
        return (float) $this->settings()->bonus_amount;
    }

    public function updateBonusAmount(float $amount): ReviewSetting
    {
        $settings = $this->settings();
        $settings->update([
            'bonus_amount' => max(0, round($amount, 2)),
        ]);

        return $settings->fresh();
    }

    public function updateSiteSettings(?int $limit = null, ?bool $showOnSite = null): ReviewSetting
    {
        $settings = $this->settings();
        $payload = [];
        if ($limit !== null) {
            $payload['site_reviews_limit'] = max(1, min(24, $limit));
        }
        if ($showOnSite !== null) {
            $payload['show_on_site'] = $showOnSite;
        }
        if ($payload !== []) {
            $settings->update($payload);
        }

        return $settings->fresh();
    }

    public function matchThreshold(): float
    {
        return (float) config('club.reviews.match_threshold', 0.9);
    }

    public function minTextLength(): int
    {
        return (int) config('club.reviews.min_text_length', 40);
    }

    public function submitClaim(int $userId, string $text): ReviewClaim
    {
        $text = trim($text);
        $normalized = ReviewClaim::normalizeText($text);
        $minLen = $this->minTextLength();

        if (mb_strlen($normalized) < $minLen) {
            throw new \InvalidArgumentException(
                "Текст отзыва слишком короткий (минимум {$minLen} символов)."
            );
        }

        $hasPending = ReviewClaim::where('user_id', $userId)
            ->where('status', ReviewClaim::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            throw new \RuntimeException('У вас уже есть заявка на проверке.');
        }

        $ttlDays = max(1, (int) config('club.reviews.pending_ttl_days', 30));

        return ReviewClaim::create([
            'user_id' => $userId,
            'review_text' => $text,
            'bonus_amount' => $this->bonusAmount(),
            'status' => ReviewClaim::STATUS_PENDING,
            'expires_at' => now()->addDays($ttlDays),
        ]);
    }

    public function verifyManually(ReviewClaim $claim, string $status): ReviewClaim
    {
        if ($status === ReviewClaim::STATUS_APPROVED) {
            return $this->approve(
                $claim,
                new RemoteReview(
                    id: 'manual_'.$claim->id.'_'.time(),
                    text: (string) $claim->review_text,
                    rating: 5,
                    source: $claim->source ?: 'manual',
                    url: $claim->review_link,
                    author: null,
                    authorId: $claim->external_author_id,
                ),
                matchedScore: 1.0
            );
        }

        $claim->update(['status' => ReviewClaim::STATUS_REJECTED]);

        return $claim->fresh();
    }

    public function approve(ReviewClaim $claim, RemoteReview $remote, float $matchedScore): ReviewClaim
    {
        return DB::transaction(function () use ($claim, $remote, $matchedScore) {
            /** @var ReviewClaim|null $locked */
            $locked = ReviewClaim::query()->whereKey($claim->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== ReviewClaim::STATUS_PENDING) {
                return $claim->fresh() ?? $claim;
            }

            if ($this->isReviewAlreadyPaid($remote->id)) {
                Log::info('Review bonus skipped: external_review_id already paid', [
                    'claim_id' => $locked->id,
                    'external_review_id' => $remote->id,
                ]);
                $locked->update(['status' => ReviewClaim::STATUS_REJECTED]);

                return $locked->fresh();
            }

            if ($remote->authorId && $this->isAuthorAlreadyPaid($remote->authorId)) {
                Log::info('Review bonus skipped: author already rewarded', [
                    'claim_id' => $locked->id,
                    'external_author_id' => $remote->authorId,
                ]);
                $locked->update([
                    'status' => ReviewClaim::STATUS_REJECTED,
                    'external_author_id' => $remote->authorId,
                ]);

                return $locked->fresh();
            }

            $user = $locked->user;
            if (! $user) {
                throw new \RuntimeException('User missing for review claim '.$locked->id);
            }

            $user->syncBalanceToWallet();
            $wallet = $user->wallet()->firstOrCreate(['user_id' => $user->id]);
            $amount = (float) $locked->bonus_amount;

            if ($amount > 0) {
                $wallet->creditSpendable($amount);
                Transaction::create([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'type' => 'deposit',
                    'source' => 'review_bonus',
                    'description' => 'Бонус за отзыв',
                    'payload' => [
                        'review_claim_id' => $locked->id,
                        'external_review_id' => $remote->id,
                        'external_author_id' => $remote->authorId,
                        'source' => $remote->source,
                        'matched_score' => $matchedScore,
                    ],
                ]);
            }

            $locked->update([
                'status' => ReviewClaim::STATUS_APPROVED,
                'source' => $remote->source,
                'external_review_id' => $remote->id,
                'external_author_id' => $remote->authorId,
                'matched_score' => $matchedScore,
                'review_link' => $remote->url ?: $locked->review_link,
                'verified_at' => now(),
            ]);

            MapReview::query()->updateOrCreate(
                [
                    'source' => $remote->source,
                    'external_review_id' => $remote->id,
                ],
                [
                    'external_author_id' => $remote->authorId,
                    'author_name' => $remote->author,
                    'text' => $remote->text,
                    'rating' => $remote->rating,
                    'url' => $remote->url,
                    'reviewed_at' => $remote->reviewedAt,
                    'rewarded_user_id' => $user->id,
                    'review_claim_id' => $locked->id,
                ]
            );

            return $locked->fresh();
        });
    }

    /**
     * @return array{fetched:int, matched:int, expired:int, synced:int}
     */
    public function processPendingClaims(): array
    {
        $expired = $this->expireStaleClaims();
        $remote = $this->fetchAllRemoteReviews();
        $synced = $this->syncMapReviews($remote);

        $pending = ReviewClaim::query()
            ->where('status', ReviewClaim::STATUS_PENDING)
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            return [
                'fetched' => count($remote),
                'matched' => 0,
                'expired' => $expired,
                'synced' => $synced,
            ];
        }

        $usedIds = ReviewClaim::query()
            ->where('status', ReviewClaim::STATUS_APPROVED)
            ->whereNotNull('external_review_id')
            ->pluck('external_review_id')
            ->all();
        $usedLookup = array_fill_keys($usedIds, true);

        $paidAuthors = ReviewClaim::query()
            ->where('status', ReviewClaim::STATUS_APPROVED)
            ->whereNotNull('external_author_id')
            ->pluck('external_author_id')
            ->all();
        $paidAuthorLookup = array_fill_keys($paidAuthors, true);

        $threshold = $this->matchThreshold();
        $requireFive = (bool) config('club.reviews.require_five_stars', true);
        $matched = 0;

        foreach ($pending as $claim) {
            $best = null;
            $bestScore = 0.0;

            foreach ($remote as $review) {
                if (isset($usedLookup[$review->id])) {
                    continue;
                }
                if ($review->authorId && isset($paidAuthorLookup[$review->authorId])) {
                    continue;
                }
                if ($requireFive && $review->rating < 5) {
                    continue;
                }

                $score = ReviewClaim::similarity((string) $claim->review_text, $review->text);
                if ($score >= $threshold && $score > $bestScore) {
                    $best = $review;
                    $bestScore = $score;
                }
            }

            if (! $best) {
                continue;
            }

            $this->approve($claim, $best, $bestScore);
            $usedLookup[$best->id] = true;
            if ($best->authorId) {
                $paidAuthorLookup[$best->authorId] = true;
            }
            $matched++;
        }

        return [
            'fetched' => count($remote),
            'matched' => $matched,
            'expired' => $expired,
            'synced' => $synced,
        ];
    }

    /**
     * @param  list<RemoteReview>  $remote
     */
    public function syncMapReviews(array $remote): int
    {
        $count = 0;
        foreach ($remote as $review) {
            $row = MapReview::query()->firstOrNew([
                'source' => $review->source,
                'external_review_id' => $review->id,
            ]);

            $row->fill([
                'external_author_id' => $review->authorId ?: $row->external_author_id,
                'author_name' => $review->author ?: $row->author_name,
                'text' => $review->text,
                'rating' => $review->rating,
                'url' => $review->url ?: $row->url,
                'reviewed_at' => $review->reviewedAt ?: $row->reviewed_at,
            ]);
            $row->save();
            $count++;
        }

        return $count;
    }

    /**
     * @return list<RemoteReview>
     */
    public function fetchAllRemoteReviews(): array
    {
        $all = [];
        $seen = [];

        foreach ($this->sources() as $source) {
            if (! $source->isConfigured()) {
                continue;
            }
            foreach ($source->fetch() as $review) {
                $key = $review->source.':'.$review->id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $all[] = $review;
            }
        }

        return $all;
    }

    public function expireStaleClaims(): int
    {
        return ReviewClaim::query()
            ->where('status', ReviewClaim::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => ReviewClaim::STATUS_EXPIRED]);
    }

    public function isReviewAlreadyPaid(string $externalReviewId): bool
    {
        return ReviewClaim::where('external_review_id', $externalReviewId)
            ->where('status', ReviewClaim::STATUS_APPROVED)
            ->exists()
            || MapReview::where('external_review_id', $externalReviewId)
                ->whereNotNull('rewarded_user_id')
                ->exists();
    }

    public function isAuthorAlreadyPaid(string $externalAuthorId): bool
    {
        return ReviewClaim::where('external_author_id', $externalAuthorId)
            ->where('status', ReviewClaim::STATUS_APPROVED)
            ->exists()
            || MapReview::where('external_author_id', $externalAuthorId)
                ->whereNotNull('rewarded_user_id')
                ->exists();
    }

    /**
     * Reviews for landing page.
     *
     * @return Collection<int, MapReview>
     */
    public function siteReviews(): Collection
    {
        $settings = $this->settings();
        if (! $settings->show_on_site) {
            return collect();
        }

        return MapReview::query()
            ->where('rating', '>=', 4)
            ->whereNotNull('text')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->limit(max(1, (int) $settings->site_reviews_limit))
            ->get();
    }

    /**
     * @return array{yandex_maps_url:?string, twogis_url:?string, bonus_amount:float, min_text_length:int}
     */
    public function clientMeta(): array
    {
        return [
            'yandex_maps_url' => config('club.reviews.yandex_maps_url') ?: null,
            'twogis_url' => config('club.reviews.twogis_url') ?: null,
            'bonus_amount' => $this->bonusAmount(),
            'min_text_length' => $this->minTextLength(),
        ];
    }
}
