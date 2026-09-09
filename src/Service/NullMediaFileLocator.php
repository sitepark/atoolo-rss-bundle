<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

/**
 * @codeCoverageIgnore
 */
class NullMediaFileLocator implements MediaFileLocator
{
    public function locate(string $url): ?string
    {
        return null;
    }
}
