<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Mp3FailureStage;
use App\Exception\Mp3OperationFailure;
use App\ValueObject\Mp3Incident;
use Psr\Log\LoggerInterface;

final class Mp3IncidentReporter
{
    public function __construct(private LoggerInterface $logger) {}

    public function report(Mp3OperationFailure $failure, int $emissionId, string $operation): Mp3Incident
    {
        try {
            $id = 'MP3-' . bin2hex(random_bytes(16));
        } catch (\Throwable) {
            // An incident reference is not an authentication token. Keep reporting possible
            // even if unavailable system entropy caused the preparation failure itself.
            $id = 'MP3-' . hash('sha256', uniqid('', true) . '-' . getmypid() . '-' . hrtime(true));
        }
        $origin = $failure->getPrevious() ?? $failure;
        try {
            $context = [
                'incident_id' => $id,
                'emission_id' => $emissionId,
                'operation' => $operation,
                'stage' => $failure->stage->value,
                'rollback_confirmed' => $failure->rollbackConfirmed,
                'original_preserved' => $failure->originalPreserved,
                'exception' => $this->exceptionContext($origin),
            ];
            $this->logger->error('Échec du traitement MP3, incident {incident_id}.', $context);
        } catch (\Throwable $loggingError) {
            // Independent best-effort fallback: never recurse through the failed logger.
            try {
                @error_log(sprintf('MP3 incident=%s emission=%d operation=%s stage=%s cause=%s logger_failure=%s',
                    $id, $emissionId, $operation, $failure->stage->value, $origin::class, $loggingError::class));
            } catch (\Throwable) {
                // Logging availability cannot change the outcome of the operation.
            }
        }

        if ($failure->rollbackConfirmed) {
            $message = $failure->stage->publicDescription() . ' Vos modifications n’ont pas été enregistrées.';
        } else {
            $message = $failure->stage->publicDescription();
            if ($failure->stage !== Mp3FailureStage::COMMIT) {
                $message .= ' Le résultat de l’enregistrement n’a pas pu être confirmé.';
            }
            $message .= ' Ne renvoyez pas le formulaire avant vérification.';
        }
        if ($failure->originalPreserved) {
            $message .= ' L’ancien MP3 a été conservé, sans modification de son contenu.';
        }
        $message .= $failure->rollbackConfirmed
            ? ' Corrigez le problème ou contactez l’administrateur.'
            : ' Rechargez la fiche ou contactez l’administrateur pour vérifier son état.';
        if ($operation !== 'editorial_reprocessing') {
            $message .= ' Sélectionnez de nouveau le fichier MP3 pour une nouvelle tentative.';
        }
        $message .= ' Référence incident : ' . $id . '.';

        return new Mp3Incident($id, $message,
            !$failure->rollbackConfirmed || $failure->stage === Mp3FailureStage::CONFLICT,
            $failure->rollbackConfirmed && $failure->originalPreserved && $failure->stage !== Mp3FailureStage::CONFLICT);
    }

    private function exceptionContext(\Throwable $error, int $depth = 0): array
    {
        // SQL exceptions may embed queries/parameters. Keep their type, code and stack,
        // but omit request values and query text. No argument/object graph is serialized.
        $message = $error instanceof \Doctrine\DBAL\Exception || $error instanceof \PDOException
            ? 'Erreur SQL/Doctrine : détails de requête omis.' : $this->safeDiagnostic($error->getMessage());
        $trace = array_map(static fn (array $frame): array => array_intersect_key($frame,
            array_flip(['file', 'line', 'class', 'type', 'function'])), $error->getTrace());
        return [
            'class' => $error::class,
            'code' => $error->getCode(),
            'sql_state' => $error instanceof \Doctrine\DBAL\Driver\Exception || $error instanceof \Doctrine\DBAL\Exception\DriverException
                ? $error->getSQLState() : ($error instanceof \PDOException ? ($error->errorInfo[0] ?? null) : null),
            'message' => $message,
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => array_slice($trace, 0, 40),
            'previous' => $error->getPrevious() !== null && $depth < 5
                ? $this->exceptionContext($error->getPrevious(), $depth + 1) : null,
        ];
    }

    private function safeDiagnostic(string $message): string
    {
        if (!mb_check_encoding($message, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', $message)) {
            return 'Message non textuel omis.';
        }
        $message = preg_replace('~(https?://)[^/\s@]+@~i', '$1[identifiants masqués]@', $message) ?? '';
        $message = preg_replace('/\b(Bearer|Basic)\s+[^\s,;]+/i', '$1 [masqué]', $message) ?? '';
        $message = preg_replace('/\b(password|passwd|token|secret|api[_-]?key)\s*[=:]\s*[^\s,;]+/i', '$1=[masqué]', $message) ?? '';
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[adresse masquée]', $message) ?? '';
        return mb_substr($message, 0, 2000);
    }
}
