<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Entity\Theme;
use App\Form\ThemeType;
use App\Tests\Support\FormIntegrationTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ThemeTypeTest extends FormIntegrationTestCase
{
    public function testNameAndOptionalImage(): void
    {
        $theme = new Theme();
        $form = $this->form(ThemeType::class, $theme);
        $form->submit(['name' => ' Culture ']);
        $this->assertValidForm($form);
        self::assertSame('Culture', $theme->getName());
        self::assertNull($theme->getThumbnailFile());
    }
    public function testUploadedImageIsMappedWithoutMovingIt(): void
    {
        $path = $this->temporaryFile(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $file = new UploadedFile($path, 'image.png', test: true);
        $theme = new Theme();
        $form = $this->form(ThemeType::class, $theme);
        $form->submit(['name' => 'Culture', 'thumbnailFile' => $file]);
        $this->assertValidForm($form);
        self::assertSame($file, $theme->getThumbnailFile());
        self::assertFileExists($path);
    }
}
