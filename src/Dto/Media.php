<?php

declare(strict_types=1);

namespace Atoolo\Rss\Dto;

/**
 * A `media:content` entry. `type`, `fileSize` and `medium` are optional in
 * MRSS.
 */
class Media
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $type = null,
        public readonly ?int $fileSize = null,
        /** the MRSS `medium`: image, audio, video, document or executable */
        public readonly ?string $medium = null,
    ) {}
}
