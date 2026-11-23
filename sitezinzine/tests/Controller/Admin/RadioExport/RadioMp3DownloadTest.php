<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\RadioExport;

use App\Entity\User;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationCopier;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationPublisher;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationWorkspace;
use App\Service\Mp3StorageLock;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RadioMp3DownloadTest extends WebTestCase
{
    /** @dataProvider roles */
    public function testDownloadUsesVerifiedPrivateBytes(?string $role): void
    {
        $base = Mp3TestStorage::root();
        $root = $base . '/http-download-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $testTransfers = $role === 'ROLE_ADMIN';
        $destinationBefore = $_ENV['RADIO_EXPORT_DESTINATION'] ?? null;
        if ($testTransfers) {
            $_ENV['RADIO_EXPORT_DESTINATION'] = 'synthetic';
        }
        $hadRoot = array_key_exists('RADIO_EXPORT_ROOT', $_ENV);
        $oldRoot = $_ENV['RADIO_EXPORT_ROOT'] ?? null;
        $_ENV['RADIO_EXPORT_ROOT'] = $root;
        $relative = basename($root) . '.mp3';
        $source = Mp3TestStorage::library() . '/' . $relative;
        try {
            $client = self::createClient();
            $client->disableReboot();
            $container = self::getContainer();
            $container->set('clock', new \Symfony\Component\Clock\MockClock('2026-11-23T23:59:59+01:00'));
            if ($role !== null) {
                $provider = $this->createMock(EntityUserProvider::class);
                $provider->method('supportsClass')->willReturn(true);
                $provider->method('refreshUser')->willReturnCallback(static fn (User $user): User => $user);
                $container->set('security.user.provider.concrete.app_user_provider', $provider);
                $user = (new User())->setUsername('download-test')->setPassword('synthetic')
                    ->setRoles([$role])->setVerified(true)->setApproved(true);
                (new \ReflectionProperty(User::class, 'id'))->setValue($user, 12);
                $client->loginUser($user);
            }
            $db = $this->createMock(Connection::class);
            $inserted = [];
            if ($testTransfers) {
                $db->method('isAutoCommit')->willReturn(true);
                $db->method('fetchAllAssociative')->willReturnCallback(
                    static function (string $sql) use ($relative, &$inserted): array {
                        if (str_contains($sql, 'radio_transfer_confirmation')) {
                            return array_values($inserted);
                        }

                        return [['validated' => 1, 'manual_revision' => '1', 'diffusion_id' => 1, 'emission_id' => 5,
                            'emission_title' => 'Radio', 'category_code' => 'MUS', 'horaire_diffusion' => '2026-11-17 00:00:00',
                            'thumbnail_mp3' => $relative, 'url' => null, 'is_live' => 0, 'deleted' => 0]];
                    }
                );
                $db->method('fetchAssociative')->willReturnCallback(static function (string $sql, array $params) use (&$inserted): array|false {
                    return $inserted[$params[0]] ?? false;
                });
                $db->expects(self::once())->method('insert')->willReturnCallback(
                    static function (string $table, array $data) use (&$inserted): int {
                        $inserted[$data['request_id']] = ['id' => 1] + $data;
                        return 1;
                    }
                );
            } else {
                $db->expects(self::never())->method('fetchAllAssociative');
                $db->expects(self::never())->method('insert');
            }
            $db->expects(self::never())->method('executeStatement');
            $container->set('doctrine.dbal.default_connection', $db);

            $week = new \DateTimeImmutable('2026-11-17', new \DateTimeZone('Europe/Paris'));
            $roots = ['library' => Mp3TestStorage::library(), 'work' => Mp3TestStorage::work(),
                'trash' => $base . '/trash', 'public' => dirname(__DIR__, 4) . '/public'];
            file_put_contents($source, 'synthetic radio bytes');
            $workspace = RadioPreparationWorkspace::open($root, $roots,
                ['weekStart' => $week, 'weekEnd' => $week->modify('+7 days'), 'entries' => [[
                    'diffusionId' => 1, 'startsAt' => $week, 'filename' => 'Radio.mp3', 'mp3Path' => $relative,
                    'localUrlPath' => null, 'audioKind' => 'local_reference', 'issues' => [], 'live' => false, 'deleted' => false,
                ]]], 'historical');
            (new RadioPreparationCopier($container->get(Mp3StorageLock::class)))->prepare($workspace);
            $publisher = new RadioPreparationPublisher();
            $revision = $testTransfers ? (new \App\Service\GridWeekValidation($db))->read($week)['revision'] : str_repeat('a', 64);
            $publisher->publish($workspace, $revision);
            $folder = $workspace->directory();
            $url = '/admin/radio-mp3/2026-11-17/historical/download/';
            $client->request('GET', $url . 'zip');
            if ($role === null || $role === 'ROLE_USER') {
                self::assertSame(302, $client->getResponse()->getStatusCode());
                self::assertFalse($client->getResponse()->headers->has('Content-Disposition'));
                return;
            }
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $headers = $client->getResponse()->headers;
            self::assertSame('application/zip', $headers->get('Content-Type'));
            self::assertTrue($headers->hasCacheControlDirective('no-store'));
            self::assertTrue($headers->hasCacheControlDirective('private'));
            self::assertStringContainsString('attachment; filename=radio-mp3-2026-11-17-historical.zip', $headers->get('Content-Disposition'));
            self::assertSame(file_get_contents($folder . '/audio.zip'), $client->getInternalResponse()->getContent());

            $client->request('GET', $url . 'report');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame(file_get_contents($folder . '/result.json'), $client->getInternalResponse()->getContent());

            if ($testTransfers) {
                self::assertSame([], $inserted, 'Downloads must not record a transfer.');
                if (getenv('RADIO_TRANSFER_TEST_TIMINGS') === '1') {
                    fwrite(STDOUT, sprintf("\nTransfer HTTP fixture: source=%d copy=%d zip=%d report=%d bytes\n",
                        filesize($source), filesize($folder . '/Radio.mp3'), filesize($folder . '/audio.zip'), filesize($folder . '/result.json')));
                }
                $page = '/admin/radio-mp3?week=2026-11-17';
                $crawler = $client->request('GET', $page);
                $form = $crawler->filter('form[action$="/transfer/1"]')->form();
                file_put_contents($source, 'changed radio bytes');
                $client->submit($form);
                self::assertSame(409, $client->getResponse()->getStatusCode());
                self::assertSame([], $inserted);
                file_put_contents($source, 'synthetic radio bytes');
                $client->submit($form);
                self::assertSame(303, $client->getResponse()->getStatusCode());
                self::assertSame($page, $client->getResponse()->headers->get('Location'));
                $event = reset($inserted);
                self::assertSame(['synthetic', 'Radio.mp3', 12, hash('sha256', 'synthetic radio bytes')],
                    [$event['destination_key'], $event['filename'], $event['actor_id'], $event['sha256']]);
                $client->submit($form);
                self::assertSame(303, $client->getResponse()->getStatusCode());
                self::assertCount(1, $inserted);
                $crawler = $client->request('GET', $page);
                self::assertCount(0, $crawler->filter('form[action$="/transfer/1"]'));
            }

            $empty = RadioPreparationWorkspace::open($root, $roots, [
                'weekStart' => $week, 'weekEnd' => $week->modify('+7 days'), 'entries' => [],
            ], 'report-only');
            $empty->finishReportOnly();
            $publisher->publish($empty);
            $client->request('GET', '/admin/radio-mp3/2026-11-17/report-only/download/report');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame(file_get_contents($empty->directory() . '/result.json'), $client->getInternalResponse()->getContent());
            $client->request('GET', '/admin/radio-mp3/2026-11-17/report-only/download/zip');
            self::assertSame(404, $client->getResponse()->getStatusCode());
            self::assertStringNotContainsString($root, $client->getInternalResponse()->getContent());
            $client->request('GET', '/admin/radio-mp3/2026-11-18/historical/download/zip');
            self::assertSame(404, $client->getResponse()->getStatusCode());
        } finally {
            unset($_ENV['RADIO_EXPORT_ROOT'], $_ENV['RADIO_EXPORT_DESTINATION']);
            if ($destinationBefore !== null) {
                $_ENV['RADIO_EXPORT_DESTINATION'] = $destinationBefore;
            }
            if ($hadRoot) {
                $_ENV['RADIO_EXPORT_ROOT'] = $oldRoot;
            }
            (new Filesystem())->remove([$root, $source]);
        }
    }

    public static function roles(): iterable
    {
        yield 'admin' => ['ROLE_ADMIN'];
        yield 'super admin' => ['ROLE_SUPER_ADMIN'];
        yield 'user' => ['ROLE_USER'];
        yield 'anonymous' => [null];
    }
}
