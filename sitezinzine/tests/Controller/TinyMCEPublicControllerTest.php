<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\TinyMCEPublicController;
use App\Tests\Support\ControllerActionTestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\String\Slugger\AsciiSlugger;

class TinyMCEPublicControllerTest extends ControllerActionTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
    private TinyMCEPublicController $controller;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/zinzine-tinymce-public-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->container->set('parameter_bag', new ParameterBag(['kernel.project_dir' => $this->directory]));
        $this->controller = new TinyMCEPublicController();
        $this->wire($this->controller);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->directory)) {
                // Seulement les fichiers du répertoire temporaire unique créé par ce test.
                foreach ([$this->directory . '/public/uploads/tinymce', $this->directory] as $path) {
                    foreach (glob($path . '/*') ?: [] as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                }
                foreach (['/public/uploads/tinymce', '/public/uploads', '/public', ''] as $suffix) {
                    if (is_dir($this->directory . $suffix)) {
                        rmdir($this->directory . $suffix);
                    }
                }
            }
        } finally {
            try {
                parent::tearDown();
            } finally {
                unset($this->controller);
            }
        }
    }

    public function testMissingFileReturns400(): void
    {
        $response = $this->controller->upload(Request::create('/', 'POST'), new AsciiSlugger());
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'Aucun fichier reçu'], json_decode($response->getContent(), true));
        self::assertDirectoryDoesNotExist($this->directory . '/public');
    }

    public function testMimeTypeIsCheckedInsteadOfTrustingExtension(): void
    {
        $path = $this->directory . '/source.png';
        file_put_contents($path, 'This is plain text, not an image.');
        $file = new UploadedFile($path, 'image.png', 'image/png', test: true);
        $response = $this->controller->upload($this->request($file), new AsciiSlugger());
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'Format d’image non supporté'], json_decode($response->getContent(), true));
        self::assertFileExists($path);
        self::assertDirectoryDoesNotExist($this->directory . '/public');
    }

    public function testImageIsMovedToUploadDirectoryWithSafeUniqueName(): void
    {
        $path = $this->source();
        $expectedHash = hash_file('sha256', $path);
        $file = new UploadedFile($path, 'mon image.png', test: true);
        $response = $this->controller->upload($this->request($file), new AsciiSlugger());
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('~^/uploads/tinymce/mon-image-[a-f0-9]+\.png$~', $data['location']);
        $target = $this->directory . '/public' . $data['location'];
        self::assertFileExists($target);
        self::assertFileDoesNotExist($path);
        self::assertSame($expectedHash, hash_file('sha256', $target));

        // Un deuxième upload du même nom conserve le premier fichier.
        $second = $this->controller->upload($this->request(new UploadedFile($this->source(), 'mon image.png', test: true)), new AsciiSlugger());
        $secondData = json_decode($second->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $second->getStatusCode());
        self::assertNotSame($data['location'], $secondData['location']);
        self::assertFileExists($target);
    }

    public function testMoveFailureReturns500WithoutExposingException(): void
    {
        $path = $this->source();
        $file = $this->getMockBuilder(UploadedFile::class)
            ->setConstructorArgs([$path, 'image.png', null, UPLOAD_ERR_OK, true])
            ->onlyMethods(['move'])->getMock();
        $file->expects(self::once())->method('move')->willThrowException(new FileException('Private filesystem path'));
        $response = $this->controller->upload($this->request($file), new AsciiSlugger());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Erreur pendant l’upload du fichier'], json_decode($response->getContent(), true));
        self::assertFileExists($path);
    }

    private function source(): string
    {
        $path = $this->directory . '/source.png';
        file_put_contents($path, base64_decode(self::PNG, true));
        return $path;
    }

    private function request(UploadedFile $file): Request
    {
        return Request::create('/', 'POST', [], [], ['file' => $file]);
    }
}
