<?php

namespace App\Tests\Repository;

use App\Entity\Page;
use App\Repository\PageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PageRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PageRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(PageRepository::class);

        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }

        $this->em->close();

        parent::tearDown();
    }

    public function testFindOneBySlugReturnsMatchingPage(): void
    {
        $page = $this->createPage(
            'page-test-' . $this->unique(),
            'Page de test'
        );

        $this->em->flush();

        $result = $this->repository->findOneBySlug($page->getSlug());

        self::assertNotNull($result);
        self::assertSame($page->getId(), $result->getId());
        self::assertSame($page->getSlug(), $result->getSlug());
    }

    public function testFindOneBySlugReturnsNullWhenSlugDoesNotExist(): void
    {
        $result = $this->repository->findOneBySlug(
            'slug-inexistant-' . $this->unique()
        );

        self::assertNull($result);
    }

    public function testFindOneBySlugReturnsCorrectPageAmongSeveralPages(): void
    {
        $first = $this->createPage(
            'premiere-' . $this->unique(),
            'Première page'
        );

        $expected = $this->createPage(
            'recherchee-' . $this->unique(),
            'Page recherchée'
        );

        $third = $this->createPage(
            'troisieme-' . $this->unique(),
            'Troisième page'
        );

        $this->em->flush();

        $result = $this->repository->findOneBySlug($expected->getSlug());

        self::assertNotNull($result);
        self::assertSame($expected->getId(), $result->getId());
        self::assertNotSame($first->getId(), $result->getId());
        self::assertNotSame($third->getId(), $result->getId());
    }

    private function createPage(string $slug, string $title): Page
    {
        $page = (new Page())
            ->setSlug($slug)
            ->setTitle($title)
            ->setContent('<p>Contenu de test</p>');

        $this->em->persist($page);

        return $page;
    }

    private function unique(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}