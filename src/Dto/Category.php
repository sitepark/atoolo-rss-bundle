<?php

declare(strict_types=1);

namespace Atoolo\Rss\Dto;

class Category
{
    public function __construct(
        public readonly string $name,
        /** identifies the taxonomy the category belongs to */
        public readonly ?string $domain = null,
    ) {}
}
