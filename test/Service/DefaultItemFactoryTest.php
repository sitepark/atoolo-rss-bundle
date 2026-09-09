<?php

declare(strict_types=1);

namespace Atoolo\Rss\Test\Service;

use Atoolo\Resource\DataBag;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Rewrite\Dto\UrlRewriteOptions;
use Atoolo\Rewrite\Dto\UrlRewriteType;
use Atoolo\Rewrite\Service\UrlRewriter;
use Atoolo\Rss\Service\CategoryFactory;
use Atoolo\Rss\Service\DefaultItemFactory;
use Atoolo\Rss\Service\MediaFileLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultItemFactory::class)]
class DefaultItemFactoryTest extends TestCase
{
    private MediaFileLocator&MockObject $mediaFileLocator;

    private DefaultItemFactory $factory;

    /** @var list<string> */
    private array $tempFiles = [];

    public function setUp(): void
    {
        $urlRewriter = $this->createMock(UrlRewriter::class);
        $urlRewriter->method('rewrite')->willReturnCallback(
            static fn(
                UrlRewriteType $type,
                string $origin,
                UrlRewriteOptions $options,
            ): string => 'https://www.example.com' . $origin,
        );

        $this->mediaFileLocator = $this->createMock(MediaFileLocator::class);

        $categoryFactory = $this->createMock(CategoryFactory::class);
        $categoryFactory->method('create')->willReturn([]);

        $this->factory = new DefaultItemFactory(
            $urlRewriter,
            $this->mediaFileLocator,
            $categoryFactory,
        );
    }

    public function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * The headline the feed shows must be the one the site shows, so the
     * teaser headline wins over the metadata headline and the title.
     */
    public function testCreateUsesTeaserHeadline(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => [
                'teaser' => ['headline' => 'Teaser-Schlagzeile'],
                'title' => 'Titel',
            ],
            'metadata' => ['headline' => 'Metadaten-Schlagzeile'],
        ]));

        $this->assertNotNull($item);
        $this->assertSame('Teaser-Schlagzeile', $item->title);
    }

    public function testCreateFallsBackToMetadataHeadline(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel'],
            'metadata' => ['headline' => 'Metadaten-Schlagzeile'],
        ]));

        $this->assertNotNull($item);
        $this->assertSame('Metadaten-Schlagzeile', $item->title);
    }

    public function testCreateFallsBackToTitle(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel'],
        ]));

        $this->assertNotNull($item);
        $this->assertSame('Titel', $item->title);
    }

    public function testCreateReturnsNullWithoutTitle(): void
    {
        $this->assertNull(
            $this->factory->create($this->createResource([])),
            'a hit without any title should be skipped, not become an empty item',
        );
    }

    public function testCreateUsesLinkAsGuid(): void
    {
        $item = $this->factory->create($this->createResource(
            ['base' => ['title' => 'Titel']],
            '/a.php',
        ));

        $this->assertNotNull($item);
        $this->assertSame('https://www.example.com/a.php', $item->link);
        $this->assertSame($item->link, $item->guid);
    }

    public function testCreateUsesTeaserText(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel', 'teaser' => ['text' => 'Anrisstext']],
            'metadata' => ['description' => 'Beschreibung'],
        ]));

        $this->assertNotNull($item);
        $this->assertSame('Anrisstext', $item->description);
    }

    public function testCreateFallsBackToMetadataDescription(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel'],
            'metadata' => ['description' => 'Beschreibung'],
        ]));

        $this->assertNotNull($item);
        $this->assertSame('Beschreibung', $item->description);
    }

    public function testCreateWithoutDescription(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel'],
        ]));

        $this->assertNotNull($item);
        $this->assertNull($item->description, 'an empty description should be null');
    }

    public function testCreateUsesBaseDate(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel', 'date' => 1772366400],
            'created' => 1600000000,
        ]));

        $this->assertNotNull($item);
        $this->assertNotNull($item->pubDate);
        $this->assertSame(1772366400, $item->pubDate->getTimestamp());
    }

    public function testCreateFallsBackToCreated(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel'],
            'created' => 1600000000,
        ]));

        $this->assertNotNull($item);
        $this->assertNotNull($item->pubDate);
        $this->assertSame(1600000000, $item->pubDate->getTimestamp());
    }

    /**
     * A resource without a date must not become 1970: that would sort it to
     * the bottom of every reader for good.
     */
    public function testCreateWithoutDateHasNoPubDate(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel'],
        ]));

        $this->assertNotNull($item);
        $this->assertNull($item->pubDate);
    }

    public function testCreateWithZeroDateHasNoPubDate(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel', 'date' => 0],
            'created' => 0,
        ]));

        $this->assertNotNull($item);
        $this->assertNull($item->pubDate, 'a zero timestamp is absent, not 1970');
    }

    public function testCreateWithoutImageHasNoMedia(): void
    {
        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel'],
        ]));

        $this->assertNotNull($item);
        $this->assertNull($item->media);
    }

    public function testCreateWithUnlocatableMediaOmitsOptionalAttributes(): void
    {
        $this->mediaFileLocator->method('locate')->willReturn(null);

        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel'],
            'metadata' => ['image' => ['sources' => [['url' => '/i.jpg']]]],
        ]));

        $this->assertNotNull($item);
        $this->assertNotNull($item->media);
        $this->assertSame('https://www.example.com/i.jpg', $item->media->url);
        $this->assertNull($item->media->type);
        $this->assertNull($item->media->fileSize);
    }

    public function testCreateWithLocatableMediaFillsOptionalAttributes(): void
    {
        $this->mediaFileLocator->method('locate')->willReturn($this->createJpeg());

        $item = $this->factory->create($this->createResource([
            'base' => ['title' => 'Titel'],
            'metadata' => ['image' => ['sources' => [['url' => '/i.jpg']]]],
        ]));

        $this->assertNotNull($item);
        $this->assertNotNull($item->media);
        $this->assertSame('image/jpeg', $item->media->type);
        $this->assertSame(22, $item->media->fileSize);
    }

    private function createJpeg(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'atoolo-rss-test');
        $this->assertNotFalse($file);
        $this->tempFiles[] = $file;
        file_put_contents(
            $file,
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
        );
        return $file;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function createResource(
        array $data,
        string $location = '/a.php',
    ): Resource {
        return new Resource(
            $location,
            'id',
            'name',
            'objectType',
            ResourceLanguage::default(),
            new DataBag($data),
        );
    }
}
