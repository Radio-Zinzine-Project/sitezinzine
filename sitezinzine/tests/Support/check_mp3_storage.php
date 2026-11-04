<?php

declare(strict_types=1);

// Read-only container preflight: no database connection or MP3 processing.
require dirname(__DIR__) . '/bootstrap.php';

$kernel = new App\Kernel('test', true);
try {
    $kernel->boot();
    $container = $kernel->getContainer();
    $root = App\Tests\Support\Mp3TestStorage::root();
    $mapping = $container->getParameter('vich_uploader.mappings')['emissionsMp3'];
    if ($container->getParameter('app.mp3_base_dir') !== $root
        || $mapping['upload_destination'] !== $root
        || $mapping['uri_prefix'] !== $container->getParameter('app.mp3_public_base_url')) {
        throw new RuntimeException('Unsafe MP3 test configuration.');
    }
    if ($container->getParameter('app.mp3_work_dir') !== $root . '/private') {
        throw new RuntimeException('Unsafe private MP3 test directory.');
    }
    echo 'MP3 isolation verified: ', $root, PHP_EOL;
} finally {
    $kernel->shutdown();
}
