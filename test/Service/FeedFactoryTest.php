<?php

declare(strict_types=1);

namespace Atoolo\Rss\Test\Service;

use Atoolo\Resource\DataBag;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Rss\Dto\Channel;
use Atoolo\Rss\Dto\Item;
use Atoolo\Rss\Service\ChannelFactory;
use Atoolo\Rss\Service\FeedFactory;
use Atoolo\Rss\Service\ItemFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(FeedFactory::class)]
class FeedFactoryTest extends TestCase
{
    private ChannelFactory&MockObject $channelFactory;

    private ItemFactory&MockObject $itemFactory;

    private FeedFactory $factory;

    public function setUp(): void
    {
        $this->channelFactory = $this->createMock(ChannelFactory::class);
        $this->channelFactory->method('create')->willReturn(new Channel(
            title: 'T',
            link: 'https://www.example.com/',
            selfLink: 'https://www.example.com/feed',
        ));

        $this->itemFactory = $this->createMock(ItemFactory::class);

        $this->factory = new FeedFactory(
            $this->channelFactory,
            $this->itemFactory,
        );
    }

    public function testCreateMapsEveryHit(): void
    {
        $this->itemFactory->method('create')->willReturnCallback(
            fn(Resource $resource): Item => $this->createItem($resource->id),
        );

        $feed = $this->factory->create(
            null,
            [$this->createResource('a'), $this->createResource('b')],
            'https://www.example.com/feed',
        );

        $this->assertCount(2, $feed->items);
        $this->assertSame('a', $feed->items[0]->title);
        $this->assertSame('b', $feed->items[1]->title);
    }

    public function testCreateSkipsUnusableHits(): void
    {
        $this->itemFactory->method('create')->willReturnCallback(
            fn(Resource $resource): ?Item => $resource->id === 'b'
                ? null
                : $this->createItem($resource->id),
        );

        $feed = $this->factory->create(
            null,
            [
                $this->createResource('a'),
                $this->createResource('b'),
                $this->createResource('c'),
            ],
            'https://www.example.com/feed',
        );

        $this->assertCount(2, $feed->items);
        $this->assertSame(['a', 'c'], array_map(
            static fn(Item $item): string => $item->title,
            $feed->items,
        ));
    }

    public function testCreatePassesTheFeedResourceToTheChannelFactory(): void
    {
        $resource = $this->createResource('page');

        $this->channelFactory->expects($this->once())
            ->method('create')
            ->with($resource, 'https://www.example.com/feed');

        $this->factory->create(
            $resource,
            [],
            'https://www.example.com/feed',
        );
    }

    public function testCreateWithoutHits(): void
    {
        $feed = $this->factory->create(null, [], 'https://www.example.com/feed');

        $this->assertSame([], $feed->items);
        $this->assertNull($feed->getLastBuildDate());
    }

    private function createItem(string $title): Item
    {
        return new Item(
            $title,
            'https://www.example.com/' . $title,
            'https://www.example.com/' . $title,
        );
    }

    private function createResource(string $id): Resource
    {
        return new Resource(
            '/' . $id . '.php',
            $id,
            $id,
            'objectType',
            ResourceLanguage::default(),
            new DataBag([]),
        );
    }
}
