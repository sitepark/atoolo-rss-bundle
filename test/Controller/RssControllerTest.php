<?php

declare(strict_types=1);

namespace Atoolo\Rss\Test\Controller;

use Atoolo\Resource\DataBag;
use Atoolo\Resource\Exception\ResourceNotFoundException;
use Atoolo\Resource\Resource;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Resource\ResourceLoader;
use Atoolo\Resource\ResourceLocation;
use Atoolo\Rss\Controller\RssController;
use Atoolo\Rss\Dto\Channel;
use Atoolo\Rss\Dto\Feed;
use Atoolo\Rss\Service\FeedFactory;
use Atoolo\Rss\Service\FeedWriter;
use Atoolo\Search\Dto\Search\Query\SearchQuery;
use Atoolo\Search\Dto\Search\Query\SearchQueryBuilder;
use Atoolo\Search\Dto\Search\Result\SearchResult;
use Atoolo\Search\Search;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(RssController::class)]
class RssControllerTest extends TestCase
{
    private const MAX_ITEMS = 100;

    private Search&MockObject $search;

    private SerializerInterface&MockObject $serializer;

    private ResourceLoader&MockObject $loader;

    private FeedFactory&MockObject $feedFactory;

    private SearchQuery $deserialized;

    private RssController $controller;

    public function setUp(): void
    {
        $this->search = $this->createMock(Search::class);
        $this->search->method('search')->willReturn(
            new SearchResult(0, 10, 0, [], [], null, 0),
        );

        $this->deserialized = (new SearchQueryBuilder())->build();
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->serializer->method('deserialize')->willReturnCallback(
            fn(): SearchQuery => $this->deserialized,
        );

        $this->loader = $this->createMock(ResourceLoader::class);
        $this->loader->method('load')->willReturn($this->createResource());

        $this->feedFactory = $this->createMock(FeedFactory::class);
        $this->feedFactory->method('create')->willReturn(new Feed(new Channel(
            title: 'T',
            link: 'https://www.example.com/',
            selfLink: 'https://www.example.com/feed',
        )));

        $this->controller = new RssController(
            $this->search,
            $this->serializer,
            $this->loader,
            $this->feedFactory,
            new FeedWriter(),
            self::MAX_ITEMS,
        );
    }

    public function testRssBySearchRendersAFeed(): void
    {
        $response = $this->controller->rssBySearch($this->request(['query' => '{}']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'application/rss+xml; charset=utf-8',
            $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString(
            '<rss version="2.0"',
            (string) $response->getContent(),
        );
    }

    public function testRssBySearchRejectsMissingQuery(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->controller->rssBySearch($this->request([]));
    }

    public function testRssBySearchRejectsUnparseableQuery(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->controller->rssBySearch($this->request(['query' => '{bad']));
    }

    /**
     * A JSON list reaches the denormalizer as a php array and would otherwise
     * yield a default query instead of an error.
     */
    public function testRssBySearchRejectsJsonListAsQuery(): void
    {
        $this->serializer->expects($this->never())->method('deserialize');
        $this->expectException(BadRequestHttpException::class);

        $this->controller->rssBySearch($this->request(['query' => '[1,2,3]']));
    }

    public function testRssBySearchRejectsLimitAboveMaximum(): void
    {
        $this->deserialized
            = (new SearchQueryBuilder())->limit(self::MAX_ITEMS + 1)->build();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('limit must not exceed 100 items per feed');

        $this->controller->rssBySearch($this->request(['query' => '{"limit":101}']));
    }

    public function testRssBySearchAcceptsLimitAtMaximum(): void
    {
        $this->deserialized
            = (new SearchQueryBuilder())->limit(self::MAX_ITEMS)->build();

        $response = $this->controller->rssBySearch(
            $this->request(['query' => '{"limit":100}']),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The loader concatenates the location onto the resource directory and
     * `require`s the result, so '..' must never reach it.
     */
    public function testRssBySearchRejectsTraversingLocation(): void
    {
        $this->loader->expects($this->never())->method('load');
        $this->expectException(BadRequestHttpException::class);

        $this->controller->rssBySearch($this->request([
            'query' => '{}',
            'location' => '/../../../../tmp/evil',
        ]));
    }

    public function testRssBySearchAllowsDotsInsideASegment(): void
    {
        $this->loader->expects($this->once())
            ->method('load')
            ->with($this->callback(
                static fn(ResourceLocation $l): bool
                    => $l->location === '/a..b/c.php',
            ))
            ->willReturn($this->createResource());

        $this->controller->rssBySearch($this->request([
            'query' => '{}',
            'location' => '/a..b/c',
        ]));
    }

    public function testRssBySearchAppendsPhpToTheLocation(): void
    {
        $this->loader->expects($this->once())
            ->method('load')
            ->with($this->callback(
                static fn(ResourceLocation $l): bool
                    => $l->location === '/suche.php',
            ))
            ->willReturn($this->createResource());

        $this->controller->rssBySearch($this->request([
            'query' => '{}',
            'location' => 'suche',
        ]));
    }

    public function testRssBySearchWithoutLocationLoadsNothing(): void
    {
        $this->loader->expects($this->never())->method('load');
        $this->feedFactory->expects($this->once())
            ->method('create')
            ->with(null, [], $this->anything())
            ->willReturn(new Feed(new Channel('T', 'https://www.example.com/', 'x')));

        $this->controller->rssBySearch($this->request(['query' => '{}']));
    }

    public function testRssBySearchWithUnknownLocation(): void
    {
        $loader = $this->createMock(ResourceLoader::class);
        $loader->method('load')->willThrowException(
            new ResourceNotFoundException(ResourceLocation::of('/weg.php')),
        );
        $controller = new RssController(
            $this->search,
            $this->serializer,
            $loader,
            $this->feedFactory,
            new FeedWriter(),
            self::MAX_ITEMS,
        );

        $this->expectException(NotFoundHttpException::class);

        $controller->rssBySearch($this->request([
            'query' => '{}',
            'location' => '/weg',
        ]));
    }

    /**
     * @param array<string,string> $query
     */
    private function request(array $query): Request
    {
        return new Request($query);
    }

    private function createResource(): Resource
    {
        return new Resource(
            '/suche.php',
            'id',
            'name',
            'objectType',
            ResourceLanguage::default(),
            new DataBag([]),
        );
    }
}
