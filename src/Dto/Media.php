<?php

declare(strict_types=1);

namespace Atoolo\Rss\Dto;

/**
 * A `media:content` entry. `type` and `fileSize` are optional in MRSS.
 */
class Media
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $type = null,
        public readonly ?int $fileSize = null,
    ) {}
}
