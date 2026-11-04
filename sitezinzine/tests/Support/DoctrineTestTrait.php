<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

trait DoctrineTestTrait
{
    protected EntityManagerInterface $entityManager;

    protected function bootDoctrine(): void
    {
        if (!$this instanceof KernelTestCase) {
            throw new \LogicException(
                sprintf(
                    '%s doit être utilisé dans une classe qui étend KernelTestCase.',
                    self::class
                )
            );
        }

        static::bootKernel();

        $this->entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $connection = $this->entityManager->getConnection();

        if (!$connection->isTransactionActive()) {
            $connection->beginTransaction();
        }
    }

    protected function clearEntityManager(): void
    {
        $this->entityManager->clear();
    }

    protected function closeEntityManager(): void
    {
        if (!isset($this->entityManager)) {
            return;
        }

        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        if ($this->entityManager->isOpen()) {
            $this->entityManager->close();
        }
    }
}