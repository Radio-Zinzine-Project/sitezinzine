<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\InfosSoirRssService;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class InfosSoirRssServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private CacheInterface&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private InfosSoirRssService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(
            HttpClientInterface::class
        );

        $this->cache = $this->createMock(
            CacheInterface::class
        );

        $this->logger = $this->createMock(
            LoggerInterface::class
        );

        $this->service = new InfosSoirRssService(
            $this->httpClient,
            $this->cache,
            $this->logger
        );
    }

    public function testGetLatestSortsItemsByPublicationDateAndAppliesLimit(): void
    {
        $oldest = [
            'title' => 'Ancienne',
            'description' => '',
            'publishedAt' => new \DateTimeImmutable(
                '2026-09-01 18:00:00'
            ),
            'audioUrl' => 'https://example.test/old.mp3',
            'guid' => 'old',
        ];

        $newest = [
            'title' => 'Nouvelle',
            'description' => '',
            'publishedAt' => new \DateTimeImmutable(
                '2026-09-08 18:00:00'
            ),
            'audioUrl' => 'https://example.test/new.mp3',
            'guid' => 'new',
        ];

        $middle = [
            'title' => 'Intermédiaire',
            'description' => '',
            'publishedAt' => new \DateTimeImmutable(
                '2026-09-05 18:00:00'
            ),
            'audioUrl' => 'https://example.test/middle.mp3',
            'guid' => 'middle',
        ];

        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(
                'infos_soir_rss',
                self::isType('callable')
            )
            ->willReturn([
                $oldest,
                $newest,
                $middle,
            ]);

        $this->httpClient
            ->expects(self::never())
            ->method('request');

        $result = $this->service->getLatest(2);

        self::assertCount(2, $result);

        self::assertSame(
            'Nouvelle',
            $result[0]['title']
        );

        self::assertSame(
            'Intermédiaire',
            $result[1]['title']
        );
    }

    public function testGetLatestReturnsAllAvailableItemsWhenLimitIsHigherThanCount(): void
    {
        $items = [
            [
                'title' => 'Émission 1',
                'description' => '',
                'publishedAt' => new \DateTimeImmutable(
                    '2026-09-08 18:00:00'
                ),
                'audioUrl' => 'https://example.test/1.mp3',
                'guid' => '1',
            ],
            [
                'title' => 'Émission 2',
                'description' => '',
                'publishedAt' => new \DateTimeImmutable(
                    '2026-09-07 18:00:00'
                ),
                'audioUrl' => 'https://example.test/2.mp3',
                'guid' => '2',
            ],
        ];

        $this->cache
            ->method('get')
            ->willReturn($items);

        $result = $this->service->getLatest(7);

        self::assertCount(2, $result);
    }

    public function testGetLatestReturnsEmptyArrayAndLogsWhenCacheFails(): void
    {
        $exception = new \RuntimeException(
            'Cache indisponible'
        );

        $this->cache
            ->expects(self::once())
            ->method('get')
            ->willThrowException($exception);

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Impossible de récupérer le flux RSS Infos Soir.',
                [
                    'exception' => $exception,
                ]
            );

        $this->httpClient
            ->expects(self::never())
            ->method('request');

        self::assertSame(
            [],
            $this->service->getLatest()
        );
    }

    public function testCacheMissLoadsAndParsesValidRssFeed(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Infos Soir</title>

        <item>
            <title>  Journal du soir  </title>
            <description><![CDATA[  Les informations du jour.  ]]></description>
            <pubDate>Tue, 08 Sep 2026 18:30:00 +0200</pubDate>
            <enclosure
                url="https://example.test/audio.mp3"
                type="audio/mpeg"
            />
            <guid>  journal-20260908  </guid>
        </item>
    </channel>
</rss>
XML;

        $response = $this->createMock(
            ResponseInterface::class
        );

        $response
            ->expects(self::once())
            ->method('getContent')
            ->willReturn($xml);

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://radiozinzine.org/emissions/INS/2026/lastRecord_INS2026.php',
                [
                    'timeout' => 10,
                ]
            )
            ->willReturn($response);

        $this->makeCacheExecuteCallback();

        $this->logger
            ->expects(self::never())
            ->method('error');

        $result = $this->service->getLatest();

        self::assertCount(1, $result);

        $item = $result[0];

        self::assertSame(
            'Journal du soir',
            $item['title']
        );

        self::assertSame(
            'Les informations du jour.',
            $item['description']
        );

        self::assertSame(
            'https://example.test/audio.mp3',
            $item['audioUrl']
        );

        self::assertSame(
            'journal-20260908',
            $item['guid']
        );

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $item['publishedAt']
        );

        self::assertSame(
            '2026-09-08 18:30:00',
            $item['publishedAt']->format(
                'Y-m-d H:i:s'
            )
        );
    }

    public function testCacheItemExpiresAfterFifteenMinutes(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Infos Soir</title>
    </channel>
</rss>
XML;

        $response = $this->createMock(
            ResponseInterface::class
        );

        $response
            ->method('getContent')
            ->willReturn($xml);

        $this->httpClient
            ->method('request')
            ->willReturn($response);

        $cacheItem = $this->createMock(
            ItemInterface::class
        );

        $cacheItem
            ->expects(self::once())
            ->method('expiresAfter')
            ->with(900)
            ->willReturnSelf();

        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(
                'infos_soir_rss',
                self::isType('callable')
            )
            ->willReturnCallback(
                static function (
                    string $key,
                    callable $callback
                ) use ($cacheItem): array {
                    return $callback($cacheItem);
                }
            );

        self::assertSame(
            [],
            $this->service->getLatest()
        );
    }

    public function testFeedWithoutChannelIsRejectedAndLogged(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
</rss>
XML;

        $this->prepareHttpResponse($xml);
        $this->makeCacheExecuteCallback();

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Impossible de récupérer le flux RSS Infos Soir.',
                self::callback(
                    static function (array $context): bool {
                        return isset($context['exception'])
                            && $context['exception']
                                instanceof \RuntimeException
                            && 'Le flux RSS Infos Soir est invalide.'
                                === $context['exception']
                                    ->getMessage();
                    }
                )
            );

        self::assertSame(
            [],
            $this->service->getLatest()
        );
    }

    public function testMalformedXmlIsRejectedAndLogged(): void
    {
        $xml = <<<'XML'
<rss>
    <channel>
        <item>
            <title>Flux cassé
        </item>
    </channel>
</rss>
XML;

        $this->prepareHttpResponse($xml);
        $this->makeCacheExecuteCallback();

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Impossible de récupérer le flux RSS Infos Soir.',
                self::callback(
                    static function (array $context): bool {
                        return isset($context['exception'])
                            && $context['exception']
                                instanceof \RuntimeException;
                    }
                )
            );

        self::assertSame(
            [],
            $this->service->getLatest()
        );
    }

    public function testItemWithoutEnclosureIsIgnored(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <item>
            <title>Sans audio</title>
            <description>Description</description>
            <pubDate>Tue, 08 Sep 2026 18:30:00 +0200</pubDate>
            <guid>no-audio</guid>
        </item>
    </channel>
</rss>
XML;

        $this->prepareHttpResponse($xml);
        $this->makeCacheExecuteCallback();

        self::assertSame(
            [],
            $this->service->getLatest()
        );
    }

    public function testItemWithEmptyEnclosureUrlIsIgnored(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <item>
            <title>URL vide</title>
            <description>Description</description>
            <pubDate>Tue, 08 Sep 2026 18:30:00 +0200</pubDate>
            <enclosure
                url="   "
                type="audio/mpeg"
            />
            <guid>empty-url</guid>
        </item>
    </channel>
</rss>
XML;

        $this->prepareHttpResponse($xml);
        $this->makeCacheExecuteCallback();

        self::assertSame(
            [],
            $this->service->getLatest()
        );
    }

    public function testItemWithInvalidPublicationDateIsIgnored(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <item>
            <title>Date invalide</title>
            <description>Description</description>
            <pubDate>ceci n'est pas une date</pubDate>
            <enclosure
                url="https://example.test/audio.mp3"
                type="audio/mpeg"
            />
            <guid>invalid-date</guid>
        </item>
    </channel>
</rss>
XML;

        $this->prepareHttpResponse($xml);
        $this->makeCacheExecuteCallback();

        self::assertSame(
            [],
            $this->service->getLatest()
        );
    }

    public function testInvalidItemsDoNotPreventValidItemsFromBeingReturned(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <item>
            <title>Sans audio</title>
            <description>Ignorée</description>
            <pubDate>Tue, 08 Sep 2026 20:00:00 +0200</pubDate>
            <guid>invalid-1</guid>
        </item>

        <item>
            <title>Date invalide</title>
            <description>Ignorée</description>
            <pubDate>pas une date</pubDate>
            <enclosure
                url="https://example.test/invalid.mp3"
                type="audio/mpeg"
            />
            <guid>invalid-2</guid>
        </item>

        <item>
            <title>Valide</title>
            <description>Conservée</description>
            <pubDate>Tue, 08 Sep 2026 18:30:00 +0200</pubDate>
            <enclosure
                url="https://example.test/valid.mp3"
                type="audio/mpeg"
            />
            <guid>valid</guid>
        </item>
    </channel>
</rss>
XML;

        $this->prepareHttpResponse($xml);
        $this->makeCacheExecuteCallback();

        $result = $this->service->getLatest();

        self::assertCount(1, $result);

        self::assertSame(
            'Valide',
            $result[0]['title']
        );

        self::assertSame(
            'https://example.test/valid.mp3',
            $result[0]['audioUrl']
        );
    }

    public function testHttpFailureIsCaughtAndLogged(): void
    {
        $exception = new \RuntimeException(
            'Serveur RSS indisponible'
        );

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willThrowException($exception);

        $this->makeCacheExecuteCallback();

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'Impossible de récupérer le flux RSS Infos Soir.',
                [
                    'exception' => $exception,
                ]
            );

        self::assertSame(
            [],
            $this->service->getLatest()
        );
    }

    private function prepareHttpResponse(
        string $content
    ): void {
        $response = $this->createMock(
            ResponseInterface::class
        );

        $response
            ->method('getContent')
            ->willReturn($content);

        $this->httpClient
            ->method('request')
            ->willReturn($response);
    }

    private function makeCacheExecuteCallback(): void
    {
        $cacheItem = $this->createMock(
            ItemInterface::class
        );

        $cacheItem
            ->method('expiresAfter')
            ->willReturnSelf();

        $this->cache
            ->method('get')
            ->willReturnCallback(
                static function (
                    string $key,
                    callable $callback
                ) use ($cacheItem): array {
                    return $callback($cacheItem);
                }
            );
    }
}