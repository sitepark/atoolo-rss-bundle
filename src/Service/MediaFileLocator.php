<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

/**
 * Resolves a media url to a file on disk, to fill the optional `type` and
 * `fileSize` attributes of `media:content`. Where media lives depends on how
 * the site publishes, so the bundle ships only a no-op.
 */
interface MediaFileLocator
{
    public function locate(string $url): ?string;
}
