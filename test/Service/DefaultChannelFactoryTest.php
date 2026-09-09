<?php

declare(strict_types=1);

namespace Atoolo\Rss\Test\Service;

use Atoolo\Resource\DataBag;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceTenant;
use Atoolo\Rewrite\Dto\UrlRewriteOptions;
use Atoolo\Rewrite\Dto\UrlRewriteType;
use Atoolo\Rewrite\Service\UrlRewriter;
use Atoolo\Rss\Dto\Channel;
use Atoolo\Rss\Service\DefaultChannelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultChannelFactory::class)]
class DefaultChannelFactoryTest extends TestCase
{
    private const SELF_LINK = 'https://www.example.com/api/rss/search?query=%7B%7D';

    public function testCreateUsesTeaserHeadline(): void
    {
        $channel = $this->create($this->createResource([
            'base' => [
                'teaser' => ['headline' => 'Pressemitteilungen'],
                'title' => 'Titel',
            ],
            'metadata' => ['headline' => 'Metadaten-Schlagzeile'],
        ]));

        $this->assertSame('Pressemitteilungen', $channel->title);
    }

    public function testCreateFallsBackToMetadataHeadline(): void
    {
        $channel = $this->create($this->createResource([
            'base' => ['title' => 'Titel'],
            'metadata' => ['headline' => 'Metadaten-Schlagzeile'],
        ]));

        $this->assertSame('Metadaten-Schlagzeile', $channel->title);
    }

    public function testCreateFallsBackToTitle(): void
    {
        $channel = $this->create($this->createResource([
            'base' => ['title' => 'Titel'],
        ]));

        $this->assertSame('Titel', $channel->title);
    }

    public function testCreateFallsBackToSiteName(): void
    {
        $channel = $this->create($this->createResource([]));

        $this->assertSame('Musterstadt', $channel->title);
    }

    /**
     * The image title must follow the same fallback as the channel title, or a
     * resource without a headline yields an empty <image><title>.
     */
    public function testCreateImageTitleFollowsChannelTitle(): void
    {
        $channel = $this->create($this->createResource([
            'metadata' => ['image' => ['sources' => [['url' => '/logo.png']]]],
        ]));

        $this->assertNotNull($channel->image);
        $this->assertSame($channel->title, $channel->image->title);
        $this->assertSame('Musterstadt', $channel->image->title);
    }

    public function testCreateImage(): void
    {
        $channel = $this->create($this->createResource([
            'base' => ['title' => 'Titel'],
            'metadata' => ['image' => ['sources' => [['url' => '/logo.png']]]],
        ]));

        $this->assertNotNull($channel->image);
        $this->assertSame('https://www.example.com/logo.png', $channel->image->url);
        $this->assertSame($channel->link, $channel->image->link);
    }

    public function testCreateWithoutImage(): void
    {
        $channel = $this->create($this->createResource([
            'base' => ['title' => 'Titel'],
        ]));

        $this->assertNull($channel->image);
    }

    public function testCreateUsesResourceDescription(): void
    {
        $channel = $this->create($this->createResource([
            'base' => ['title' => 'Titel'],
            'metadata' => ['description' => 'Meldungen der Stadt'],
        ]));

        $this->assertSame('Meldungen der Stadt', $channel->description);
    }

    public function testCreateFallsBackToConfiguredDescription(): void
    {
        $channel = $this->create($this->createResource([
            'base' => ['title' => 'Titel'],
        ]));

        $this->assertSame('Aktuelles aus Musterstadt', $channel->description);
    }

    public function testCreateLinksToTheResource(): void
    {
        $channel = $this->create(
            $this->createResource(['base' => ['title' => 'Titel']]),
        );

        $this->assertSame('https://www.example.com/presse.php', $channel->link);
        $this->assertSame(self::SELF_LINK, $channel->selfLink);
    }

    public function testCreateWithoutResourceUsesSiteMetadata(): void
    {
        $channel = $this->create(null);

        $this->assertSame('Musterstadt', $channel->title);
        $this->assertSame('https://www.example.com/', $channel->link);
        $this->assertSame(self::SELF_LINK, $channel->selfLink);
        $this->assertSame('Aktuelles aus Musterstadt', $channel->description);
        $this->assertNull($channel->image);
    }

    /**
     * The resource channel carries a full locale, RSS wants the language.
     */
    public function testCreateWithoutResourceDerivesLanguageFromLocale(): void
    {
        $this->assertSame('de', $this->create(null)->language);
    }

    public function testCreateWithoutResourceAndWithoutLocale(): void
    {
        $channel = $this->create(null, locale: '');

        $this->assertNull($channel->language);
    }

    public function testCreatePassesThroughConfiguredValues(): void
    {
        $channel = $this->create(null);

        $this->assertSame('IES', $channel->generator);
        $this->assertSame('Copyright 2026', $channel->copyright);
    }

    private function create(?Resource $resource, string $locale = 'de_DE'): Channel
    {
        $urlRewriter = $this->createMock(UrlRewriter::class);
        $urlRewriter->method('rewrite')->willReturnCallback(
            static fn(
                UrlRewriteType $type,
                string $origin,
                UrlRewriteOptions $options,
            ): string => 'https://www.example.com' . $origin,
        );

        $factory = new DefaultChannelFactory(
            $urlRewriter,
            $this->createResourceChannel($locale),
            'IES',
            'Copyright 2026',
            'Aktuelles aus Musterstadt',
        );

        return $factory->create($resource, self::SELF_LINK);
    }

    private function createResourceChannel(string $locale): ResourceChannel
    {
        /** @var ResourceTenant $tenant */
        $tenant = $this->createStub(ResourceTenant::class);
        return new ResourceChannel(
            id: '',
            name: 'Musterstadt',
            anchor: '',
            serverName: 'www.example.com',
            isPreview: false,
            nature: '',
            locale: $locale,
            baseDir: '',
            resourceDir: '',
            configDir: '',
            searchIndex: '',
            translationLocales: [],
            attributes: new DataBag([]),
            tenant: $tenant,
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function createResource(array $data): Resource
    {
        return new Resource(
            '/presse.php',
            'id',
            'name',
            'objectType',
            ResourceLanguage::default(),
            new DataBag($data),
        );
    }
}
