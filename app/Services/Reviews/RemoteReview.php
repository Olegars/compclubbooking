<?php

namespace App\Services\Reviews;

/**
 * Normalized remote review from maps providers.
 */
class RemoteReview
{
    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly float $rating,
        public readonly string $source,
        public readonly ?string $url = null,
        public readonly ?string $author = null,
        public readonly ?string $authorId = null,
        public readonly ?\DateTimeInterface $reviewedAt = null,
    ) {}
}
