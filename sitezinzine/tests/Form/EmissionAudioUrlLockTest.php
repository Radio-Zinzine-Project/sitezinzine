<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Emission;
use App\Form\EmissionType;
use App\Repository\CategoriesRepository;
use App\Repository\UserRepository;
use App\Service\EmissionUserChoicesProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\DataTransformer\CollectionToArrayTransformer;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Validation;

/** Real form binding and HTTP request handling, without a kernel or database. */
final class EmissionAudioUrlLockTest extends TestCase
{
    public function testCreationOffersNeitherAudioUrlNorMp3Upload(): void
    {
        $form = $this->form(new Emission(), false);

        self::assertFalse($form->has('url'));
        self::assertFalse($form->has('thumbnailFileMp3'));
        self::assertFalse($form->has('deleteMp3'));
    }

    public static function forgedUrls(): iterable
    {
        yield 'replacement' => ['https://attacker.example/audio.mp3'];
        yield 'clearing' => [''];
        yield 'array' => [['https://attacker.example/audio.mp3']];
    }

    /** @dataProvider forgedUrls */
    public function testForgedCreationPostCannotAssignAnAudioUrl(mixed $submitted): void
    {
        $emission = new Emission();
        $form = $this->form($emission, false);
        $this->post($form, ['url' => $submitted, 'thumbnailMp3' => 'forged.mp3']);

        self::assertTrue($form->isSubmitted());
        self::assertNull($emission->getUrl());
        self::assertNull($emission->getThumbnailMp3());
        self::assertSame('Titre éditorial', $emission->getTitre());
    }

    public function testEditDisplaysTheStoredUrlAsDisabled(): void
    {
        $url = 'https://Example.test/Émission.MP3?Token=AbC&nom=Caf%C3%A9';
        $emission = (new Emission())->setUrl($url);
        $form = $this->form($emission, true);

        self::assertTrue($form->has('url'));
        self::assertTrue($form->get('url')->isDisabled());
        self::assertSame($url, $form->createView()['url']->vars['value']);
        self::assertTrue($form->createView()['url']->vars['disabled']);
        self::assertTrue($form->has('thumbnailFileMp3'));
    }

    /** @dataProvider forgedUrls */
    public function testForgedEditPostPreservesTheHistoricalUrl(mixed $submitted): void
    {
        $url = 'http://historique.example/Ancienne Émission.MP3?Token=AbC';
        $emission = (new Emission())->setUrl($url)->setThumbnailMp3('Ancienne Émission.MP3');
        $form = $this->form($emission, true);
        $this->post($form, ['url' => $submitted]);

        self::assertSame($url, $emission->getUrl());
        self::assertSame('Ancienne Émission.MP3', $emission->getThumbnailMp3());
        self::assertSame('Titre éditorial', $emission->getTitre());
        self::assertTrue($form->isSynchronized());
    }

    public function testOrdinaryEditWithoutUrlPreservesAnExternalUrl(): void
    {
        $url = 'https://external.example/listen?id=42';
        $emission = (new Emission())->setUrl($url);
        $this->post($this->form($emission, true));

        self::assertSame($url, $emission->getUrl());
        self::assertSame('Titre éditorial', $emission->getTitre());
        // The setter remains available to imports and internal MP3 workflows.
        self::assertSame($emission, $emission->setUrl('https://local.example/new.mp3'));
        self::assertSame('https://local.example/new.mp3', $emission->getUrl());
    }

    public function testEditStillMapsTheUploadedFileWithoutMappingTheForgedUrl(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'audio-url-lock-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, 'isolated form upload fixture');
            $upload = new UploadedFile($path, 'historical.mp3', 'audio/mpeg', null, true);
            $emission = (new Emission())->setUrl('https://external.example/original.mp3');
            $form = $this->form($emission, true);
            $this->post($form, ['url' => 'https://forged.example/new.mp3'], $upload);

            self::assertSame($upload, $emission->getThumbnailFileMp3());
            self::assertSame('https://external.example/original.mp3', $emission->getUrl());
            self::assertTrue($form->get('thumbnailFileMp3')->isSynchronized());
            self::assertFileExists($path);
        } finally {
            unlink($path);
        }
    }

    private function post(FormInterface $form, array $extra = [], ?UploadedFile $upload = null): void
    {
        $form->handleRequest(new Request([], ['emission' => array_replace([
            'titre' => 'Titre éditorial', 'duree' => '60', 'keyword' => 'édition',
        ], $extra)], [], [], $upload === null ? [] : ['emission' => ['thumbnailFileMp3' => $upload]], [
            'REQUEST_METHOD' => 'POST',
        ]));
    }

    private function form(Emission $emission, bool $edit): FormInterface
    {
        $categories = $this->createMock(CategoriesRepository::class);
        $categories->expects(self::never())->method('find');
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('createQueryBuilder');
        $type = new EmissionType($categories, new EmissionUserChoicesProvider($users));

        return Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([$type], []))
            ->addExtension(new AudioUrlEmptyEntityChoices())
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()->createNamed('emission', EmissionType::class, $emission, ['with_mp3' => $edit]);
    }
}

/** Replace only unrelated Doctrine choice loading; URL/File types and form listeners stay real. */
final class AudioUrlEmptyEntityChoices implements FormExtensionInterface
{
    public function hasType(string $name): bool { return $name === EntityType::class; }

    public function getType(string $name): FormTypeInterface
    {
        return new class extends AbstractType {
            public function getParent(): string { return ChoiceType::class; }

            public function configureOptions(OptionsResolver $resolver): void
            {
                $resolver->setDefined(['class', 'query_builder']);
                $resolver->setDefault('choices', []);
            }

            public function buildForm(FormBuilderInterface $builder, array $options): void
            {
                if ($options['multiple']) {
                    $builder->addModelTransformer(new CollectionToArrayTransformer());
                }
            }
        };
    }

    public function getTypeExtensions(string $name): array { return []; }
    public function hasTypeExtensions(string $name): bool { return false; }
    public function getTypeGuesser(): ?FormTypeGuesserInterface { return null; }
}
