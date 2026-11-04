<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Entity\Page;
use App\Form\PageType;
use App\Tests\Support\FormIntegrationTestCase;

class PageTypeTest extends FormIntegrationTestCase
{
    public function testRequiredFieldsCannotBeEmpty(): void
    {
        $form = $this->form(PageType::class, new Page());
        $form->submit(['slug' => '', 'title' => '', 'content' => '']);
        self::assertFalse($form->isValid());
        foreach (['slug', 'title', 'content'] as $field) {
            self::assertGreaterThan(0, count($form->get($field)->getErrors()));
        }
    }
    public function testCreationMapsSlugTitleAndContent(): void
    {
        $page = new Page();
        $form = $this->form(PageType::class, $page);
        self::assertFalse($form->get('slug')->isDisabled());
        self::assertFalse($form->has('deleteMainImage'));
        $form->submit(['slug' => 'presentation', 'title' => 'Présentation', 'content' => '<p>Bienvenue</p>']);
        $this->assertValidForm($form);
        self::assertSame('presentation', $page->getSlug());
        self::assertSame('Présentation', $page->getTitle());
        self::assertSame('<p>Bienvenue</p>', $page->getContent());
    }
    public function testEditionKeepsSlugAndLeavesImageDeletionToController(): void
    {
        $page = $this->save((new Page())->setSlug(uniqid('page-'))->setTitle('Ancien')->setContent('Texte'));
        $slug = $page->getSlug();
        // Pas de flush après ajout de cette référence : aucun fichier réel n'est nécessaire.
        $page->setMainImageName('header.png');
        $form = $this->form(PageType::class, $page);
        self::assertTrue($form->get('slug')->isDisabled());
        self::assertTrue($form->has('deleteMainImage'));
        $form->submit(['slug' => 'forged-slug', 'title' => 'Nouveau', 'content' => 'Nouveau texte', 'deleteMainImage' => '1']);
        $this->assertValidForm($form);
        self::assertSame($slug, $page->getSlug());
        self::assertSame('Nouveau', $page->getTitle());
        self::assertTrue($form->get('deleteMainImage')->getData());
        self::assertSame('header.png', $page->getMainImageName());
    }
}
