<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Emission;
use App\Repository\CategorieTagImageRepository;
use App\Service\Mp3Processor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Monolog\Logger;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelEvents;

abstract class Mp3FailureTestCase extends FunctionalTestCase
{
    protected Emission $subject;
    protected Emission $witness;
    protected Mp3FailureProbe $probe;
    protected array $baseline;
    protected array $input;
    protected ?string $original = null;
    protected ?string $originalHash = null;
    protected string $operation;
    protected string $witnessHash;
    protected string $secret = 'MP3_TEST_AUTH_SECRET_DO_NOT_LOG';
    protected string $personal = 'mp3-private-person@example.test';
    private string $fixtureHash;
    private array $restore = [];
    private array $loggerRestore = [];
    private array $httpListeners = [];

    protected function setUp(): void
    {
        $this->client = static::createClient(['debug' => true]);
        $this->client->disableReboot();
        $this->client->catchExceptions(true);
        $container = self::getContainer();
        self::assertSame(Mp3TestStorage::root(), $container->getParameter('app.mp3_base_dir'));
        self::assertSame(Mp3TestStorage::root(), $container->getParameter('vich_uploader.mappings')['emissionsMp3']['upload_destination']);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        self::assertStringContainsString('_test', (string) $this->entityManager->getConnection()->getDatabase());
        $this->entityManager->getConnection()->beginTransaction();
        $this->marker = 'mp3errors' . bin2hex(random_bytes(8));
        $this->fixtureHash = hash_file('sha256', $this->fixture());
    }

    protected function prepare(string $operation): Form
    {
        $this->operation = $operation;
        $owner = $this->createUser('ROLE_SUPER_ADMIN');
        $owner->setEmail($this->personal);
        $category = $this->createCategory();
        $this->subject = $this->createEmission('Titre initial', $owner, $category);
        $this->subject->setDatepub(new \DateTime('2024-06-15'))->setUrl(null);
        if ($operation !== 'upload') {
            $this->install($this->subject, 'A-' . $this->marker . '.mp3');
            $this->original = $this->subject->getThumbnailMp3();
            $this->originalHash = hash_file('sha256', $this->path($this->original));
        }
        $this->witness = $this->createEmission('Temoin', $owner, $category);
        $this->install($this->witness, 'witness-' . $this->marker . '.mp3');
        $this->witnessHash = hash_file('sha256', $this->path($this->witness->getThumbnailMp3()));
        $newCategory = $this->createCategory();
        $host = $this->createUser();
        $this->entityManager->flush();
        $this->baseline = $this->sqlState();
        $this->input = [
            'emission[titre]' => 'Titre corrige',
            'emission[descriptif]' => 'Description corrigee',
            'emission[keyword]' => 'mot-corrige',
            'emission[duree]' => '45',
            'emission[categorie]' => (string) $newCategory->getId(),
            'emission[users]' => [(string) $host->getId()],
        ];
        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/admin/emission/' . $this->subject->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Sauvegarder')->form($this->input);
        // Datepub is absent from the UI: simulate the managed change at the save boundary.
        $listener = function ($event): void {
            if (!$event->getRequest()->isMethod('POST')) {
                return;
            }
            foreach ($event->getArguments() as $argument) {
                if ($argument instanceof Emission && $argument->getId() === $this->subject->getId()) {
                    $argument->setDatepub(new \DateTime('2025-09-17'));
                }
            }
        };
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(KernelEvents::CONTROLLER_ARGUMENTS, $listener);
        $this->httpListeners[] = [$dispatcher, $listener];
        $this->probe = new Mp3FailureProbe($this->subject->getId(), $this->original);
        $this->entityManager->getEventManager()->addEventListener([Events::onFlush], $this->probe);
        $this->captureLogs();
        return $form;
    }

    protected function arm(string $stage): void
    {
        $this->probe->armedStage = $stage;
        if ($stage === 'metadata') {
            $repository = $this->createMock(CategorieTagImageRepository::class);
            $repository->method('findOneByCategorieAndYear')->willReturnCallback(fn () => $this->probe->fail());
            $this->swap('categorieTagImageRepository', $repository);
        } elseif ($stage === 'move') {
            $filesystem = $this->getMockBuilder(Filesystem::class)->onlyMethods(['rename'])->getMock();
            $filesystem->method('rename')->willReturnCallback(fn () => $this->probe->fail());
            $this->swap('fs', $filesystem);
        }
    }

    protected function disarm(): void
    {
        $this->probe->armedStage = null;
        $this->probe->failLogging = false;
        foreach (array_reverse($this->restore) as [$processor, $property, $original]) {
            $property->setValue($processor, $original);
        }
        $this->restore = [];
    }

    protected function send(Form $form): void
    {
        $files = [];
        if ($this->operation !== 'editorial') {
            $upload = $this->path('request-' . bin2hex(random_bytes(16)) . '.mp3');
            self::assertTrue(copy($this->fixture(), $upload));
            $files = ['emission' => ['thumbnailFileMp3' => new UploadedFile($upload, 'new.mp3', 'audio/mpeg', null, true)]];
        }
        try {
            $this->client->request($form->getMethod(), $form->getUri(), $form->getPhpValues(), $files, [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->secret,
            ]);
            // Both direct rendering and redirect-back with retained values satisfy the contract.
            $response = $this->client->getResponse();
            if ($this->probe->faultCount > 0 && $response->isRedirect()
                && parse_url((string) $response->headers->get('Location'), PHP_URL_PATH) === parse_url($form->getUri(), PHP_URL_PATH)) {
                $this->client->followRedirect();
            }
        } catch (\Throwable $error) {
            // Only deliberately injected faults count as RED behavior, never setup errors.
            if ($error !== $this->probe->origin && $error !== $this->probe->loggingError) {
                throw $error;
            }
        }
    }

    protected function sqlState(?Emission $emission = null): array
    {
        $id = ($emission ?? $this->subject)->getId();
        $connection = $this->entityManager->getConnection();
        $row = $connection->fetchAssociative('SELECT titre, descriptif, keyword, duree, datepub, categorie_id, thumbnail_mp3, url FROM emission WHERE id = ?', [$id]);
        self::assertIsArray($row);
        $row['users'] = $connection->fetchFirstColumn('SELECT user_id FROM emission_user WHERE emission_id = ? ORDER BY user_id', [$id]);
        return $row;
    }

    protected function assertWitness(): void
    {
        $relative = $this->witness->getThumbnailMp3();
        self::assertFileExists($this->path($relative));
        self::assertSame($this->witnessHash, hash_file('sha256', $this->path($relative)));
        $row = $this->sqlState($this->witness);
        self::assertSame($relative, $row['thumbnail_mp3']);
        self::assertSame($this->witness->getUrl(), $row['url']);
    }

    protected function originalState(): array
    {
        $exists = $this->original !== null && is_file($this->path($this->original));
        return ['exists' => $exists, 'hash' => $exists ? hash_file('sha256', $this->path($this->original)) : null];
    }

    protected function formState(): array
    {
        $crawler = $this->client->getCrawler();
        if ($crawler->filter('form input[name="emission[titre]"]')->count() === 0) {
            return [];
        }
        $form = $crawler->selectButton('Sauvegarder')->form();
        $values = [];
        foreach ($this->input as $name => $expected) {
            $value = $form[$name]->getValue();
            $values[$name] = is_array($value) ? array_map('strval', $value) : (string) $value;
        }
        return $values;
    }

    protected function responseText(): string
    {
        return $this->client->getCrawler()->count() ? $this->client->getCrawler()->text() : '';
    }

    protected function controlledResponse(): bool
    {
        $status = $this->client->getResponse()->getStatusCode();
        return ($status >= 200 && $status < 300) || ($status >= 400 && $status < 500);
    }

    protected function path(string $relative): string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
            throw new \LogicException('Unsafe MP3 test path.');
        }
        return Mp3TestStorage::root() . '/' . $relative;
    }

    private function fixture(): string
    {
        return dirname(__DIR__) . '/Fixtures/mp3/test.mp3';
    }

    private function install(Emission $emission, string $name): void
    {
        self::assertTrue(copy($this->fixture(), $this->path($name)));
        $emission->setThumbnailMp3($name);
        self::getContainer()->get(Mp3Processor::class)->process($emission);
    }

    private function swap(string $name, object $double): void
    {
        $processor = self::getContainer()->get(Mp3Processor::class);
        $property = new \ReflectionProperty(Mp3Processor::class, $name);
        $this->restore[] = [$processor, $property, $property->getValue($processor)];
        $property->setValue($processor, $double);
    }

    private function captureLogs(): void
    {
        $container = self::getContainer();
        // TestContainer lists public services separately from accessible private channels.
        $ids = array_unique(array_merge(['logger'], $container->getServiceIds(), array_keys($container->getRemovedIds())));
        foreach ($ids as $id) {
            if ($id !== 'logger' && !str_starts_with($id, 'monolog.logger.')) {
                continue;
            }
            if (!$container->has($id)) {
                continue;
            }
            $logger = $container->get($id);
            if ($logger instanceof Logger && !isset($this->loggerRestore[spl_object_id($logger)])) {
                $this->loggerRestore[spl_object_id($logger)] = [$logger, $logger->getHandlers()];
                $logger->setHandlers([$this->probe]);
            }
        }
        self::assertNotEmpty($this->loggerRestore, 'At least the real Symfony logger must be instrumented.');
        $container->get('logger')->debug('MP3_LOG_CAPTURE_CHECK');
        self::assertNotEmpty($this->probe->records);
        $this->probe->records = [];
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->probe)) {
                $this->disarm();
                $this->entityManager->getEventManager()->removeEventListener([Events::onFlush], $this->probe);
            }
            foreach ($this->loggerRestore as [$logger, $handlers]) {
                $logger->setHandlers($handlers);
            }
            foreach ($this->httpListeners as [$dispatcher, $listener]) {
                $dispatcher->removeListener(KernelEvents::CONTROLLER_ARGUMENTS, $listener);
            }
            // Do not retain Monolog exception/Doctrine contexts across failing test cases.
            if (isset($this->probe)) {
                $this->probe->records = [];
            }
            $this->loggerRestore = [];
            $this->httpListeners = [];
            if (isset($this->fixtureHash)) {
                self::assertSame($this->fixtureHash, hash_file('sha256', $this->fixture()));
            }
        } finally {
            try {
                parent::tearDown();
            } finally {
                unset($this->subject, $this->witness, $this->probe);
            }
        }
    }
}
