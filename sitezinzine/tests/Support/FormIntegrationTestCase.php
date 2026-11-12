<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\{Categories, Editeur, Emission, InviteOldAnimateur, Theme, User};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\{FormFactoryInterface, FormInterface};

/** Vrais formulaires et validation Symfony ; fixtures SQL annulées après chaque test. */
abstract class FormIntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected FormFactoryInterface $factory;
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->factory = static::getContainer()->get('form.factory');
        $request = \Symfony\Component\HttpFoundation\Request::create('/');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        ));
        static::getContainer()->get('request_stack')->push($request);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
        } finally {
            try {
                foreach ($this->temporaryFiles as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            } finally {
                try {
                    parent::tearDown();
                } finally {
                    unset($this->em, $this->factory);
                }
            }
        }
    }

    protected function form(string $type, mixed $data = null, array $options = []): FormInterface
    {
        return $this->factory->create($type, $data, $options + ['csrf_protection' => false]);
    }

    protected function assertValidForm(FormInterface $form): void
    {
        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), (string) $form->getErrors(true, false));
    }

    protected function save(object $entity): mixed
    {
        $this->em->persist($entity);
        $this->em->flush();
        return $entity;
    }

    protected function user(array $roles = ['ROLE_USER']): User
    {
        $id = bin2hex(random_bytes(6));
        return $this->save((new User())->setUsername('form_' . $id)->setEmail($id . '@example.com')
            ->setPassword('hashed-test-password')->setRoles($roles));
    }

    protected function editor(): Editeur
    {
        return $this->save((new Editeur())->setName('Form editor ' . uniqid())->setUpdateAt(new \DateTime()));
    }

    protected function category(bool $active = true, bool $deleted = false): Categories
    {
        do {
            $slug = chr(random_int(65, 90)) . chr(random_int(65, 90)) . chr(random_int(65, 90));
        } while (null !== $this->em->getRepository(Categories::class)->findOneBy(['slug' => $slug]));
        return $this->save((new Categories())->setTitre('Form category ' . uniqid())->setSlug($slug)
            ->setDuree(60)->setDescriptif('Description')->setActive($active)->setSoftDelete($deleted)
            ->setEditeur($this->editor())->setUpdatedAt(new \DateTime()));
    }

    protected function theme(): Theme
    {
        return $this->save((new Theme())->setName('Form theme ' . uniqid())->setUpdatedAt(new \DateTime()));
    }

    protected function guest(bool $ancien = false): InviteOldAnimateur
    {
        return $this->save((new InviteOldAnimateur())->setFirstName('Form person ' . uniqid())
            ->setLastName('Test')->setAncienanimateur($ancien));
    }

    protected function emission(): Emission
    {
        return $this->save((new Emission())->setTitre('Form emission ' . uniqid())->setDuree(60)
            ->setDescriptif('Description')->setKeyword('test')->setDatepub(new \DateTime())
            ->setUpdatedAt(new \DateTime())->setTheme($this->theme())->setCategorie($this->category()));
    }

    protected function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zinzine-form-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $content);
        return $path;
    }

    protected function choiceValues(FormInterface $field): array
    {
        return array_map(static fn($choice): string => $choice->value, $field->createView()->vars['choices']);
    }

    protected function associateCategoryUser(Categories $category, User $user): void
    {
        self::assertNotNull($category->getId());
        self::assertNotNull($user->getId());

        $this->em->getConnection()->insert('categories_user', [
            'categories_id' => $category->getId(),
            'user_id' => $user->getId(),
        ]);
    }
}
