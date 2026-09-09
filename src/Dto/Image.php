<?php

declare(strict_types=1);

namespace Atoolo\Rss\Dto;

class Image
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $link,
    ) {}
}
