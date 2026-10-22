<?php

declare(strict_types=1);

namespace Atoolo\Rss\Test\Service;

use Atoolo\Rss\Service\UrlResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UrlResolver::class)]
class UrlResolverTest extends TestCase
{
    private const BASE = 'http://a/b/c/d;p?q';

    /**
     * The examples of RFC 3986, section 5.4.
     *
     * @return array<string, array{string, string}>
     */
    public static function rfcExamples(): array
    {
        return [
            'g:h' => ['g:h', 'g:h'],
            'g' => ['g', 'http://a/b/c/g'],
            './g' => ['./g', 'http://a/b/c/g'],
            'g/' => ['g/', 'http://a/b/c/g/'],
            '/g' => ['/g', 'http://a/g'],
            '//g' => ['//g', 'http://g'],
            '?y' => ['?y', 'http://a/b/c/d;p?y'],
            'g?y' => ['g?y', 'http://a/b/c/g?y'],
            '#s' => ['#s', 'http://a/b/c/d;p?q#s'],
            'g#s' => ['g#s', 'http://a/b/c/g#s'],
            'g?y#s' => ['g?y#s', 'http://a/b/c/g?y#s'],
            ';x' => [';x', 'http://a/b/c/;x'],
            'g;x' => ['g;x', 'http://a/b/c/g;x'],
            'g;x?y#s' => ['g;x?y#s', 'http://a/b/c/g;x?y#s'],
            'empty' => ['', 'http://a/b/c/d;p?q'],
            '.' => ['.', 'http://a/b/c/'],
            './' => ['./', 'http://a/b/c/'],
            '..' => ['..', 'http://a/b/'],
            '../' => ['../', 'http://a/b/'],
            '../g' => ['../g', 'http://a/b/g'],
            '../..' => ['../..', 'http://a/'],
            '../../' => ['../../', 'http://a/'],
            '../../g' => ['../../g', 'http://a/g'],
            '../../../g' => ['../../../g', 'http://a/g'],
            '../../../../g' => ['../../../../g', 'http://a/g'],
            '/./g' => ['/./g', 'http://a/g'],
            '/../g' => ['/../g', 'http://a/g'],
            'g.' => ['g.', 'http://a/b/c/g.'],
            '.g' => ['.g', 'http://a/b/c/.g'],
            'g..' => ['g..', 'http://a/b/c/g..'],
            '..g' => ['..g', 'http://a/b/c/..g'],
            './../g' => ['./../g', 'http://a/b/g'],
            './g/.' => ['./g/.', 'http://a/b/c/g/'],
            'g/./h' => ['g/./h', 'http://a/b/c/g/h'],
            'g/../h' => ['g/../h', 'http://a/b/c/h'],
            'g;x=1/./y' => ['g;x=1/./y', 'http://a/b/c/g;x=1/y'],
            'g;x=1/../y' => ['g;x=1/../y', 'http://a/b/c/y'],
            'g?y/./x' => ['g?y/./x', 'http://a/b/c/g?y/./x'],
            'g?y/../x' => ['g?y/../x', 'http://a/b/c/g?y/../x'],
            'g#s/./x' => ['g#s/./x', 'http://a/b/c/g#s/./x'],
            'g#s/../x' => ['g#s/../x', 'http://a/b/c/g#s/../x'],
            'http:g' => ['http:g', 'http:g'],
        ];
    }

    #[DataProvider('rfcExamples')]
    public function testResolve(string $reference, string $expected): void
    {
        $this->assertSame(
            $expected,
            (new UrlResolver())->resolve(self::BASE, $reference),
        );
    }

    public function testResolveAgainstBaseWithoutPath(): void
    {
        $this->assertSame(
            'https://example.com/a.jpg',
            (new UrlResolver())->resolve('https://example.com', 'a.jpg'),
        );
    }

    public function testResolveAgainstRelativeBase(): void
    {
        $this->assertSame(
            'a.jpg',
            (new UrlResolver())->resolve('feed.xml', 'a.jpg'),
            'without an absolute base the reference should stay as it is',
        );
    }
}
