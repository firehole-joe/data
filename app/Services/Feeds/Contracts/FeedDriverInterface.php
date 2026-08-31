<?php

namespace App\Services\Feeds\Contracts;

use App\Models\Distributor;
use App\Services\Feeds\DTOs\FeedItemDTO;

interface FeedDriverInterface
{
    /**
     * Retrieve the distributor's feed and return the path to a local
     * temporary file. The caller owns the returned file and is
     * responsible for deleting it.
     */
    public function downloadFeed(Distributor $distributor): string;

    /**
     * Parse a previously downloaded feed file, yielding one
     * {@see FeedItemDTO} per catalog row.
     *
     * Implemented as a generator so large feeds never have to be held
     * in memory all at once.
     *
     * @return \Generator<int, FeedItemDTO>
     */
    public function parseFeed(string $filePath): \Generator;

    /**
     * Verify that the configured transport can reach the distributor.
     */
    public function testConnection(Distributor $distributor): bool;
}
