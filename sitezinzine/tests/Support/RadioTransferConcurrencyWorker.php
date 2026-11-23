<?php

declare(strict_types=1);

use App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferJournal;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Clock\NativeClock;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$params, $request] = json_decode(fgets(STDIN), true, 32, JSON_THROW_ON_ERROR);
$db = DriverManager::getConnection($params);
try {
    if ($db->fetchOne('SELECT DATABASE()') !== 'symfony_test') {
        throw new LogicException('Unexpected worker database.');
    }
    $db->executeStatement('SET SESSION innodb_lock_wait_timeout = 3');
    echo json_encode(['connection' => (int) $db->fetchOne('SELECT CONNECTION_ID()')], JSON_THROW_ON_ERROR), "\n";
    if (trim((string) fgets(STDIN)) !== 'insert') {
        exit(2);
    }
    $journal = new RadioTransferJournal($db, new NativeClock());
    try {
        $event = $journal->append($request, 'A', 15);
        echo json_encode(['id' => (string) $event['id']], JSON_THROW_ON_ERROR), "\n";
    } catch (DomainException) {
        echo "{\"conflict\":true}\n";
    }
} finally {
    $db->close();
}
