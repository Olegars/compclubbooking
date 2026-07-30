<?php

namespace App\Services\Reviews;

interface ReviewSourceInterface
{
    /**
     * @return list<RemoteReview>
     */
    public function fetch(): array;

    public function source(): string;

    public function isConfigured(): bool;
}
