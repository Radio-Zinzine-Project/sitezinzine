<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Repository\CategorieTagImageRepository;
use App\Service\Mp3Processor;
use App\Tests\Support\Mp3FailureTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class Mp3ErrorRecoveryTest extends Mp3FailureTestCase
{
    public function testRetryAfterTheFailedOperationClosedDoctrine(): void
    {
        $form = $this->prepare('replacement');
        $this->arm('metadata');
        $processor = self::getContainer()->get(Mp3Processor::class);
        $property = new \ReflectionProperty(Mp3Processor::class, 'categorieTagImageRepository');
        $armedRepository = $property->getValue($processor);
        $repository = $this->createMock(CategorieTagImageRepository::class);
        $repository->method('findOneByCategorieAndYear')->willReturnCallback(function (): never {
            $this->entityManager->close();
            $this->probe->fail();
        });
        $property->setValue($processor, $repository);
        try {
            $this->send($form);
        } finally {
            $property->setValue($processor, $armedRepository);
        }
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($this->entityManager->isOpen());
        self::assertTrue($this->controlledResponse());
        self::assertSame($this->input, $this->formState());
        self::assertSame($this->baseline, $this->sqlState());
        self::assertSame(['exists' => true, 'hash' => $this->originalHash], $this->originalState());
        $this->assertWitness();
        $retry = $this->client->getCrawler()->selectButton('Sauvegarder')->form();
        $this->disarm();
        $this->send($retry);
        self::assertResponseRedirects();
        $row = $this->sqlState();
        self::assertSame('Titre corrige', $row['titre']);
        self::assertFileExists($this->path($row['thumbnail_mp3']));
        $this->assertWitness();
    }

    public function testLoggerOutageStillAllowsRetryFromTheReturnedForm(): void
    {
        $form = $this->prepare('editorial');
        $this->arm('metadata');
        $this->probe->failLogging = true;
        $this->send($form);
        self::assertTrue($this->probe->loggingFailed);
        self::assertTrue($this->controlledResponse());
        self::assertSame($this->input, $this->formState());
        self::assertSame($this->baseline, $this->sqlState());
        self::assertSame(['exists' => true, 'hash' => $this->originalHash], $this->originalState());
        $retry = $this->client->getCrawler()->selectButton('Sauvegarder')->form();
        $this->disarm();
        $this->send($retry);
        self::assertResponseRedirects();
        $row = $this->sqlState();
        self::assertSame('Titre corrige', $row['titre']);
        self::assertFileExists($this->path($row['thumbnail_mp3']));
        $this->assertWitness();
    }
}
