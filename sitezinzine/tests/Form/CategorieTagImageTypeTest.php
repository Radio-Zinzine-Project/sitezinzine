<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Entity\CategorieTagImage;
use App\Form\CategorieTagImageType;
use App\Tests\Support\FormIntegrationTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CategorieTagImageTypeTest extends FormIntegrationTestCase
{
    public function testCategoryYearAndInactiveChoiceStyle(): void
    {
        $active = $this->category();
        $inactive = $this->category(false);
        $entity = new CategorieTagImage();
        $form = $this->form(CategorieTagImageType::class, $entity);
        $choices = $form->get('categorie')->createView()->vars['choices'];
        $attrs = [];
        foreach ($choices as $choice) { $attrs[$choice->value] = $choice->attr; }
        self::assertSame([], $attrs[$active->getId()]);
        self::assertSame(['style' => 'color:#999;'], $attrs[$inactive->getId()]);
        $form->submit(['categorie' => (string) $active->getId(), 'annee' => '2026']);
        $this->assertValidForm($form);
        self::assertSame($active, $entity->getCategorie());
        self::assertSame(2026, $entity->getAnnee());
        self::assertNull($entity->getImageFile());
    }
    /** @dataProvider files */
    public function testImageMimeAndSizeConstraints(string $kind, bool $valid): void
    {
        $content = 'text file';
        if ('text' !== $kind) {
            $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        }
        if ('large' === $kind) { $content .= str_repeat('x', 2100000); }
        $file = new UploadedFile($this->temporaryFile($content), 'image.png', test: true);
        $entity = new CategorieTagImage();
        $form = $this->form(CategorieTagImageType::class, $entity);
        $form->submit(['categorie' => (string) $this->category()->getId(), 'annee' => '2026', 'imageFile' => $file]);
        self::assertSame($valid, $form->isValid(), (string) $form->getErrors(true, false));
        self::assertSame($file, $entity->getImageFile());
        if (!$valid) { self::assertGreaterThan(0, count($form->get('imageFile')->getErrors())); }
    }
    public static function files(): iterable
    {
        yield 'PNG' => ['png', true];
        yield 'texte déguisé' => ['text', false];
        yield 'trop volumineux' => ['large', false];
    }
    public function testYearOutsideAllowedRangeIsRejected(): void
    {
        $form = $this->form(CategorieTagImageType::class, new CategorieTagImage());
        $form->submit(['categorie' => (string) $this->category()->getId(), 'annee' => '2101']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('annee')->getErrors()));
    }
}
