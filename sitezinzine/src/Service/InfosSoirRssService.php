<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class InfosSoirRssService
{
    private const RSS_URL = 'TON_URL_RSS_ICI';
    private const CACHE_KEY = 'infos_soir_rss';
    private const CACHE_DURATION = 900; // 15 minutes

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLatest(int $limit = 7): array
    {
        try {
            $items = $this->cache->get(
                self::CACHE_KEY,
                function (ItemInterface $item): array {
                    $item->expiresAfter(self::CACHE_DURATION);

                    return $this->loadFeed();
                }
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Impossible de récupérer le flux RSS Infos Soir.',
                [
                    'exception' => $e,
                ]
            );

            return [];
        }

        usort(
            $items,
            static function (array $a, array $b): int {
                return $b['publishedAt'] <=> $a['publishedAt'];
            }
        );

        return array_slice($items, 0, $limit);
    }

    private function loadFeed(): array
    {
        $response = $this->httpClient->request(
            'GET',
            self::RSS_URL,
            [
                'timeout' => 10,
            ]
        );

        $content = $response->getContent();

        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string(
                $content,
                \SimpleXMLElement::class,
                LIBXML_NOCDATA | LIBXML_NONET
            );

            if ($xml === false || !isset($xml->channel)) {
                throw new \RuntimeException(
                    'Le flux RSS Infos Soir est invalide.'
                );
            }

            $items = [];

            foreach ($xml->channel->item as $rssItem) {
                $title = trim((string) $rssItem->title);
                $description = trim((string) $rssItem->description);
                $pubDate = trim((string) $rssItem->pubDate);

                $audioUrl = null;

                if (isset($rssItem->enclosure)) {
                    $attributes = $rssItem->enclosure->attributes();

                    if (
                        $attributes !== null
                        && isset($attributes['url'])
                    ) {
                        $audioUrl = trim(
                            (string) $attributes['url']
                        );
                    }
                }

                if ($audioUrl === null || $audioUrl === '') {
                    continue;
                }

                try {
                    $publishedAt = new \DateTimeImmutable($pubDate);
                } catch (\Throwable) {
                    continue;
                }

                $items[] = [
                    'title' => $title,
                    'description' => $description,
                    'publishedAt' => $publishedAt,
                    'audioUrl' => $audioUrl,
                    'guid' => trim((string) $rssItem->guid),
                ];
            }

            return $items;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors(
                $previousUseInternalErrors
            );
        }
    }
}