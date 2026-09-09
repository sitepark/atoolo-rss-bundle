<?php

declare(strict_types=1);

namespace Atoolo\Rss\Service;

use Atoolo\Resource\Exception\InvalidResourceException;
use Atoolo\Resource\Exception\ResourceNotFoundException;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLoader;
use Atoolo\Resource\ResourceLocation;
use Atoolo\Rewrite\Dto\UrlRewriteOptions;
use Atoolo\Rewrite\Dto\UrlRewriteType;
use Atoolo\Rewrite\Service\UrlRewriter;
use Atoolo\Rss\Dto\Category;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Resolves a resource's categories, each with the url of its primary parent as
 * the taxonomy `domain`. A category that cannot be loaded is skipped.
 */
class CategoryFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ResourceLoader $loader,
        private readonly UrlRewriter $urlRewriter,
    ) {}

    /**
     * @return list<Category>
     */
    public function create(Resource $resource): array
    {
        $categories = [];
        $raws = $resource->data->getAssociativeArray('metadata.categories');
        foreach ($raws as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $url = $raw['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            $category = $this->load($url, $resource);
            if ($category === null) {
                continue;
            }
            $name = $category->data->getString(
                'base.title',
                $category->data->getString('name'),
            );
            if ($name === '') {
                continue;
            }
            $categories[] = new Category(
                $name,
                $this->primaryParentUrl($category),
            );
        }
        return $categories;
    }

    private function load(string $url, Resource $context): ?Resource
    {
        try {
            return $this->loader->load(
                ResourceLocation::of($url, $context->lang),
            );
        } catch (ResourceNotFoundException|InvalidResourceException $e) {
            $this->logger?->warning(
                'category resource could not be loaded for the rss feed',
                ['url' => $url, 'exception' => $e],
            );
            return null;
        }
    }

    private function primaryParentUrl(Resource $category): ?string
    {
        $parents = $category->data->getAssociativeArray(
            'base.trees.category.parents',
        );
        foreach ($parents as $parent) {
            if (!is_array($parent) || ($parent['isPrimary'] ?? false) !== true) {
                continue;
            }
            $url = $parent['url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $this->urlRewriter->rewrite(
                    UrlRewriteType::LINK,
                    $url,
                    UrlRewriteOptions::builder()
                        ->toFullyQualifiedUrl(true)
                        ->lang($category->lang->code)
                        ->build(),
                );
            }
        }
        return null;
    }
}
