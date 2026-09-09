<?php

declare(strict_types=1);

namespace Atoolo\Rss\Test\Service;

use Atoolo\Resource\DataBag;
use Atoolo\Resource\Exception\ResourceNotFoundException;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceLoader;
use Atoolo\Resource\ResourceLocation;
use Atoolo\Rewrite\Dto\UrlRewriteOptions;
use Atoolo\Rewrite\Dto\UrlRewriteType;
use Atoolo\Rewrite\Service\UrlRewriter;
use Atoolo\Rss\Service\CategoryFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryFactory::class)]
class CategoryFactoryTest extends TestCase
{
    private ResourceLoader&MockObject $loader;

    private CategoryFactory $factory;

    public function setUp(): void
    {
        $this->loader = $this->createMock(ResourceLoader::class);

        $urlRewriter = $this->createMock(UrlRewriter::class);
        $urlRewriter->method('rewrite')->willReturnCallback(
            static fn(
                UrlRewriteType $type,
                string $origin,
                UrlRewriteOptions $options,
            ): string => 'https://www.example.com' . $origin,
        );

        $this->factory = new CategoryFactory($this->loader, $urlRewriter);
    }

    public function testCreateUsesThePrimaryParentAsDomain(): void
    {
        $this->loadReturns([
            '/kategorien/rat.php' => $this->createCategory('Rat', [
                ['url' => '/kategorien/allgemein.php', 'isPrimary' => false],
                ['url' => '/kategorien/gremien.php', 'isPrimary' => true],
            ]),
        ]);

        $categories = $this->factory->create(
            $this->createResourceWithCategories(['/kategorien/rat.php']),
        );

        $this->assertCount(1, $categories);
        $this->assertSame('Rat', $categories[0]->name);
        $this->assertSame(
            'https://www.example.com/kategorien/gremien.php',
            $categories[0]->domain,
        );
    }

    public function testCreateWithoutPrimaryParentHasNoDomain(): void
    {
        $this->loadReturns([
            '/kategorien/rat.php' => $this->createCategory('Rat', [
                ['url' => '/kategorien/allgemein.php', 'isPrimary' => false],
            ]),
        ]);

        $categories = $this->factory->create(
            $this->createResourceWithCategories(['/kategorien/rat.php']),
        );

        $this->assertCount(1, $categories);
        $this->assertNull($categories[0]->domain);
    }

    /**
     * One broken reference should cost a single category, not the whole feed.
     */
    public function testCreateSkipsUnloadableCategories(): void
    {
        $this->loader->method('load')->willReturnCallback(
            function (ResourceLocation $location): Resource {
                if ($location->location === '/kategorien/weg.php') {
                    throw new ResourceNotFoundException($location);
                }
                return $this->createCategory('Rat', []);
            },
        );

        $categories = $this->factory->create(
            $this->createResourceWithCategories([
                '/kategorien/weg.php',
                '/kategorien/rat.php',
            ]),
        );

        $this->assertCount(1, $categories);
        $this->assertSame('Rat', $categories[0]->name);
    }

    public function testCreateSkipsCategoriesWithoutUrl(): void
    {
        $this->loader->expects($this->never())->method('load');

        $resource = $this->createResource([
            'metadata' => ['categories' => [['name' => 'ohne url']]],
        ]);

        $this->assertSame([], $this->factory->create($resource));
    }

    public function testCreateSkipsUnnamedCategories(): void
    {
        $this->loadReturns([
            '/kategorien/rat.php' => $this->createCategory('', []),
        ]);

        $this->assertSame([], $this->factory->create(
            $this->createResourceWithCategories(['/kategorien/rat.php']),
        ));
    }

    public function testCreateWithoutCategories(): void
    {
        $this->assertSame([], $this->factory->create($this->createResource([])));
    }

    /**
     * @param array<string,Resource> $byLocation
     */
    private function loadReturns(array $byLocation): void
    {
        $this->loader->method('load')->willReturnCallback(
            static function (ResourceLocation $location) use ($byLocation): Resource {
                if (!isset($byLocation[$location->location])) {
                    throw new ResourceNotFoundException($location);
                }
                return $byLocation[$location->location];
            },
        );
    }

    /**
     * @param list<array<string,mixed>> $parents
     */
    private function createCategory(string $title, array $parents): Resource
    {
        return $this->createResource([
            'base' => [
                'title' => $title,
                'trees' => ['category' => ['parents' => $parents]],
            ],
        ]);
    }

    /**
     * @param list<string> $urls
     */
    private function createResourceWithCategories(array $urls): Resource
    {
        return $this->createResource([
            'metadata' => [
                'categories' => array_map(
                    static fn(string $url): array => ['url' => $url],
                    $urls,
                ),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function createResource(array $data): Resource
    {
        return new Resource(
            '/a.php',
            'id',
            'name',
            'objectType',
            ResourceLanguage::default(),
            new DataBag($data),
        );
    }
}
