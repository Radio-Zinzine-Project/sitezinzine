<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\AudioLibrary;

use App\Controller\Admin\AudioLibrary\InventoryController;
use App\Service\AudioLibrary\Inventory\AudioInventoryReportDiscovery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class InventoryControllerTest extends TestCase
{
    private const ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function controller(AudioInventoryReportDiscovery $reader, bool $allowed = true): InventoryController
    {
        $controller = new InventoryController($reader);
        $container = new Container();
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->with('ROLE_SUPER_ADMIN')->willReturn($allowed);
        $container->set('security.authorization_checker', $auth);
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['admin/admin.html.twig' => '<main>{% block body %}{% endblock %}</main>']),
            new FilesystemLoader(dirname(__DIR__, 4).'/templates'),
        ]), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('path', static function (string $route, array $params = []): string {
            if ($route === 'admin.emission.show') { return '/admin/emission/'.$params['id']; }
            return '/admin/audio-library/inventory/'.($params['profile'] ?? '').'/'.($params['inventoryId'] ?? '').'?page='.($params['page'] ?? 1);
        }));
        $container->set('twig', $twig); $controller->setContainer($container);
        return $controller;
    }

    private function reader(): AudioInventoryReportDiscovery
    {
        $reader = $this->createMock(AudioInventoryReportDiscovery::class);
        $reader->method('getProfiles')->willReturn(['fixture']);
        return $reader;
    }

    private function manifest(int $pages = 3): array
    {
        return ['inventory_id' => self::ID, 'page_count' => $pages, 'result_count' => $pages ? 3 : 0,
            'physical_paths' => 1, 'referenced_paths' => 2, 'association_count' => 4,
            'classification_counts' => ['associated' => 0, 'multiple_associations' => 1, 'orphan_candidate' => 1, 'broken_reference' => 0, 'indeterminate' => 1],
            'report' => ['scope' => 'test_subset', 'collection_mode' => 'observation_only',
                'restrictions' => ['storage_certified' => false, 'absence_conclusions_allowed' => false,
                    'references_complete' => false, 'physical_collection_complete' => false],
                'preparation' => ['physical_errors' => [['relative_path' => '<script>diagnostic</script>', 'reason' => 'read_failed']],
                    'sql_export' => ['uncertainties' => [['emission_id' => 8, 'field' => 'url', 'reason' => 'uncertain_local_url']]],
                    'secondary_errors' => [['exception_class' => 'SafeClass', 'code' => 7]],
                    'private_root' => '/secret/root', 'message' => 'secret password']]];
    }

    private function published(AudioInventoryReportDiscovery $reader, int $pages = 3): void
    {
        $reader->expects(self::once())->method('readManifest')->with('fixture', self::ID)->willReturn([
            'status' => 'published', 'manifest' => $this->manifest($pages), 'inventory_date' => null]);
    }

    public function testAuthorizationPrecedesEvenProfileEnumeration(): void
    {
        $reader = $this->createMock(AudioInventoryReportDiscovery::class);
        foreach (['getProfiles', 'discover', 'readManifest', 'readPage'] as $method) { $reader->expects(self::never())->method($method); }
        $controller = $this->controller($reader, false);
        foreach ([fn () => $controller->index(Request::create('/')), fn () => $controller->show(Request::create('/'), 'fixture', self::ID)] as $action) {
            try { $action(); self::fail('Access was granted.'); }
            catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) { self::assertTrue(true); }
        }
    }

    public function testEmptyProfilesDoNotReadStorage(): void
    {
        $reader = $this->createMock(AudioInventoryReportDiscovery::class);
        $reader->method('getProfiles')->willReturn([]); $reader->expects(self::never())->method('discover');
        $response = $this->controller($reader)->index(Request::create('/'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Aucun profil', $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function testPartialDiscoveryShowsRestrictionsAndNoPrivatePaths(): void
    {
        $reader = $this->reader(); $m = $this->manifest();
        $reader->expects(self::once())->method('discover')->with('fixture')->willReturn([
            'status' => 'partial', 'partial' => true, 'reason' => 'entry_budget_reached', 'entries_examined' => 2, 'unpublished_count' => 1,
            'reports' => [['inventory_id' => self::ID, 'inventory_date' => null, 'scope' => 'test_subset', 'collection_mode' => 'observation_only',
                'result_count' => 3, 'classification_counts' => $m['classification_counts'], 'restrictions' => $m['report']['restrictions']]],
            'anomalies' => [['inventory_id' => str_repeat('b', 32), 'status' => 'corrupt_or_unreadable', 'reason' => '<script>bad</script>']]]);
        $reader->expects(self::never())->method('readPage');
        $response = $this->controller($reader)->index(Request::create('/?profile=fixture'));
        $html = $response->getContent();
        self::assertSame(200, $response->getStatusCode()); self::assertStringContainsString('Liste partielle', $html);
        self::assertStringContainsString('Date non enregistrée', $html); self::assertStringContainsString('observation_only', $html);
        self::assertStringContainsString('non certifié', $html); self::assertStringContainsString('corrupt_or_unreadable', $html);
        self::assertStringNotContainsString('<script>bad', $html);
    }

    public function testReadsOnlyRequestedPageAndPreservesAssociationsWithSafeRendering(): void
    {
        $reader = $this->reader(); $this->published($reader);
        $association = ['emission_id' => 17, 'origin' => 'thumbnailMp3', 'thumbnail_missing' => false];
        $reader->expects(self::once())->method('readPage')->with('fixture', self::ID, 2)->willReturn([
            'next_page' => 3, 'results' => [
                ['relative_path' => '<script>alert(1)</script>.mp3', 'classification' => 'orphan_candidate', 'present' => true, 'associations' => []],
                ['relative_path' => "binary\xff\n.mp3", 'classification' => 'indeterminate', 'present' => null, 'associations' => [$association, $association]],
                ['relative_path' => '/secret/root/file.mp3', 'classification' => 'indeterminate', 'present' => null, 'associations' => []],
            ]]);
        $response = $this->controller($reader)->show(Request::create('/?page=2'), 'fixture', self::ID);
        $html = $response->getContent();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Inventaire non certifié', $html); self::assertStringContainsString("aucune conclusion d'absence autorisée", $html);
        self::assertStringContainsString('orphelin potentiel', $html); self::assertStringContainsString('présence non déterminée', $html);
        self::assertStringContainsString('&lt;script&gt;', $html); self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('hex:', $html); self::assertStringNotContainsString('/secret/root', $html);
        self::assertStringNotContainsString('secret password', $html);
        self::assertSame(2, substr_count($html, 'href="/admin/emission/17"'));
        self::assertStringContainsString('Précédent', $html); self::assertStringContainsString('Suivant', $html);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testEmptyReportDoesNotAttemptToReadPageOne(): void
    {
        $reader = $this->reader(); $this->published($reader, 0); $reader->expects(self::never())->method('readPage');
        $response = $this->controller($reader)->show(Request::create('/'), 'fixture', self::ID);
        self::assertSame(200, $response->getStatusCode()); self::assertStringContainsString('Aucun résultat', $response->getContent());
    }

    /** @dataProvider badPages */
    public function testMalformedPageIsRefusedBeforeReadingManifest(mixed $page): void
    {
        $reader = $this->reader(); $reader->expects(self::never())->method('readManifest'); $reader->expects(self::never())->method('readPage');
        $response = $this->controller($reader)->show(Request::create('/', 'GET', ['page' => $page]), 'fixture', self::ID);
        self::assertSame(400, $response->getStatusCode());
    }
    public static function badPages(): array { return [['0'], ['-1'], ['01'], ['1.5'], ['1e2'], [[]], [str_repeat('9', 30)]]; }

    public function testOutOfRangePageDoesNotReadResults(): void
    {
        $reader = $this->reader(); $this->published($reader, 1); $reader->expects(self::never())->method('readPage');
        self::assertSame(404, $this->controller($reader)->show(Request::create('/?page=2'), 'fixture', self::ID)->getStatusCode());
    }

    public function testUnknownProfileAndInvalidIdAreRefusedWithoutFileReads(): void
    {
        $reader = $this->reader(); $reader->expects(self::never())->method('readManifest'); $reader->expects(self::never())->method('discover');
        $controller = $this->controller($reader);
        self::assertSame(404, $controller->index(Request::create('/?profile=unknown'))->getStatusCode());
        self::assertSame(404, $controller->show(Request::create('/'), 'fixture', '../outside')->getStatusCode());
    }

    /** @dataProvider unavailableStates */
    public function testNonPublishedReportsAreNotRenderedAsEmpty(string $status, int $code): void
    {
        $reader = $this->reader(); $reader->method('readManifest')->willReturn(['status' => $status, 'manifest' => null]);
        $reader->expects(self::never())->method('readPage');
        $response = $this->controller($reader)->show(Request::create('/'), 'fixture', self::ID);
        self::assertSame($code, $response->getStatusCode()); self::assertStringNotContainsString('Aucun résultat', $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
    public static function unavailableStates(): array { return [['unpublished', 404], ['unavailable', 503], ['corrupt_or_unreadable', 409]]; }

    public function testPageCorruptionDoesNotLeakRawException(): void
    {
        $reader = $this->reader(); $this->published($reader);
        $reader->method('readPage')->willThrowException(new \UnexpectedValueException('/secret/password'));
        $response = $this->controller($reader)->show(Request::create('/'), 'fixture', self::ID);
        self::assertSame(409, $response->getStatusCode()); self::assertStringNotContainsString('/secret', $response->getContent());
    }

    public function testAudioLibraryMenuIsVisibleOnlyForSuperAdministrator(): void
    {
        foreach (['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] as $role) {
            $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4).'/templates'), ['autoescape' => 'html', 'strict_variables' => true]);
            $twig->addGlobal('app', (object)['request' => Request::create('/')]);
            $twig->addFunction(new TwigFunction('path', static fn ($route) => '/'.$route));
            $twig->addFunction(new TwigFunction('is_granted', static fn ($required) => $role === 'ROLE_SUPER_ADMIN'
                || ($role === 'ROLE_ADMIN' && in_array($required, ['ROLE_ADMIN', 'ROLE_EDITOR', 'ROLE_USER'], true))
                || ($role === 'ROLE_USER' && $required === 'ROLE_USER')));
            $html = $twig->render('admin/navbar.html.twig');
            self::assertSame($role === 'ROLE_SUPER_ADMIN', str_contains($html, 'Bibliothèque audio'));
            self::assertSame($role === 'ROLE_SUPER_ADMIN', str_contains($html, '/admin.audio_library.inventory.index'));
        }
    }

    public function testPrivateHeadersAlsoCoverInventoryUrlsRejectedByRouter(): void
    {
        $kernel = $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class);
        foreach (['/admin/audio-library/inventory/fixture/invalid', '/unrelated'] as $url) {
            $response = new \Symfony\Component\HttpFoundation\Response();
            $event = new \Symfony\Component\HttpKernel\Event\ResponseEvent($kernel, Request::create($url),
                \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, $response);
            (new \App\EventSubscriber\AudioInventoryResponseSubscriber())->makePrivate($event);
            self::assertSame($url !== '/unrelated', $response->headers->hasCacheControlDirective('no-store'));
        }
    }
}
