<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Tests\Support\CapturesMp3FallbackLog;

use App\Tests\Support\{Mp3FailureProbe, Mp3FailureTestCase};

final class Mp3FailureHandlingTest extends Mp3FailureTestCase
{
    use CapturesMp3FallbackLog;

    /** @dataProvider rollbackCases */
    public function testFailureRollsBackAllEditorialChangesAndMp3References(string $operation, string $stage): void
    {
        $form = $this->prepare($operation);
        $this->arm($stage);
        $this->send($form);
        $this->assertWitness();
        self::assertSame([
            'fault reached' => true,
            'original' => ['exists' => true, 'hash' => $this->originalHash],
            'SQL' => $this->baseline,
        ], [
            'fault reached' => $this->probe->faultCount > 0,
            'original' => $this->originalState(),
            'SQL' => $this->sqlState(),
        ], 'Neither scalar fields, host associations, nor MP3 references may survive a failed request.');
    }

    public static function rollbackCases(): iterable
    {
        foreach (['replacement', 'editorial'] as $operation) {
            foreach (['metadata', 'move', 'doctrine'] as $stage) {
                yield "$operation / $stage" => [$operation, $stage];
            }
        }
    }

    /** @dataProvider existingMp3Operations */
    public function testFailureKeepsAnEditableFormWithSubmittedValues(string $operation): void
    {
        $form = $this->prepare($operation);
        $this->arm('metadata');
        $this->send($form);
        $this->assertWitness();
        self::assertSame([
            'fault reached' => true, 'controlled response' => true, 'values' => $this->input,
        ], [
            'fault reached' => $this->probe->faultCount > 0,
            'controlled response' => $this->controlledResponse(), 'values' => $this->formState(),
        ]);
        // Browsers do not preserve file inputs; re-selection of an upload is allowed.
        self::assertCount(1, $this->client->getCrawler()->filter('form input[type="file"][name="emission[thumbnailFileMp3]"]'));
    }

    /** @dataProvider stages */
    public function testPublicErrorExplainsTheProblemWithoutTechnicalDisclosure(string $stage): void
    {
        $form = $this->prepare('replacement');
        $this->arm($stage);
        $this->send($form);
        $this->assertWitness();
        $crawler = $this->client->getCrawler();
        $messages = $crawler->filter('.alert-danger, .alert-error, .alert-warning, .invalid-feedback, .form-error-message, [role="alert"]');
        $message = $messages->count() ? $messages->text() : '';
        $nature = match ($stage) {
            'metadata' => '/m[eé]tadonn|tags?|id3|couverture/iu',
            'move' => '/d[eé]plac|stock|class|fichier|disque/iu',
            'doctrine' => '/enregistr|sauvegard|base|donn[eé]es/iu',
        };
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame([
            'fault reached' => true, 'controlled response' => true, 'form accessible' => true,
            'nature explained' => true, 'internal details disclosed' => false,
        ], [
            'fault reached' => $this->probe->faultCount > 0,
            'controlled response' => $this->controlledResponse(),
            'form accessible' => $this->formState() !== [],
            'nature explained' => preg_match($nature, $message) === 1,
            'internal details disclosed' => preg_match('/MP3_TECHNICAL_SENTINEL|private\/encoder-detail|Stack Trace|Stack trace|RuntimeException|\/var\/www\/html|zinzine-mp3-tests-/i', $html) === 1
                || str_contains($html, $this->secret) || str_contains($html, $this->personal),
        ]);
    }

    public static function stages(): iterable
    {
        yield 'metadata' => ['metadata'];
        yield 'move' => ['move'];
        yield 'doctrine' => ['doctrine'];
    }

    /** @dataProvider allOperations */
    public function testIncidentIsCorrelatedBetweenPublicMessageAndTechnicalLog(string $operation): void
    {
        $form = $this->prepare($operation);
        $this->arm('metadata');
        $this->send($form);
        $this->assertWitness();
        self::assertGreaterThan(0, $this->probe->faultCount, 'Mandatory MP3 processing was not reached.');
        $incidents = array_values(array_filter($this->probe->records, static fn ($record) =>
            Mp3FailureProbe::containsOrigin([$record->message, $record->context])
            && Mp3FailureProbe::field($record->context, ['incident_id', 'incidentId', 'incident']) !== null
        ));
        self::assertNotEmpty($incidents, 'A framework exception log alone is insufficient: an identifiable MP3 incident is required.');
        $incidentIds = [];
        foreach ($incidents as $record) {
            $context = $record->context;
            self::assertFalse(Mp3FailureProbe::containsSensitiveObject($context), 'Do not log user/token/request objects or MP3 streams.');
            $incident = Mp3FailureProbe::field($context, ['incident_id', 'incidentId', 'incident']);
            self::assertIsString($incident);
            self::assertNotSame('', trim($incident));
            $incidentIds[] = $incident;
            self::assertSame((string) $this->subject->getId(), (string) Mp3FailureProbe::field($context, ['emission_id', 'emissionId']));
            $loggedOperation = (string) Mp3FailureProbe::field($context, ['operation', 'operation_type', 'type_operation']);
            self::assertMatchesRegularExpression(match ($operation) {
                'upload' => '/^upload$|t[eé]l[eé]versement/iu',
                'replacement' => '/replacement|remplacement/iu',
                'editorial' => '/editorial|[eé]ditorial|retraitement/iu',
            }, $loggedOperation);
            self::assertMatchesRegularExpression('/metadata|m[eé]tadonn|id3|tags?|cover|couverture/iu',
                (string) Mp3FailureProbe::field($context, ['stage', 'step', 'etape']));
            $diagnostic = $record->message . ' ' . Mp3FailureProbe::diagnosticText($context);
            self::assertStringContainsString('RuntimeException', $diagnostic);
            self::assertStringContainsString('MP3_TECHNICAL_SENTINEL', $diagnostic);
            self::assertStringContainsString('/private/encoder-detail', $diagnostic);
            foreach ([$this->secret, $this->personal, 'Test-password-42!'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $diagnostic);
            }
            $mp3 = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/mp3/test.mp3');
            self::assertStringNotContainsString($mp3, $diagnostic);
            self::assertStringNotContainsString(base64_encode($mp3), $diagnostic);
            self::assertStringNotContainsString(substr($mp3, 0, 64), $diagnostic);
            self::assertStringNotContainsString(base64_encode(substr($mp3, 0, 64)), $diagnostic);
        }
        self::assertCount(1, array_unique($incidentIds), 'One failure must have one consistent incident identifier.');
        self::assertStringContainsString($incidentIds[0], $this->responseText());
        self::assertStringNotContainsString('MP3_TECHNICAL_SENTINEL', $this->responseText());
        self::assertSame($this->input, $this->formState());
    }

    /** @dataProvider existingMp3Operations */
    public function testBrokenLoggingCannotCompromiseTheOriginalOrEditorialRollback(string $operation): void
    {
        $form = $this->prepare($operation);
        $this->arm('metadata');
        $this->probe->failLogging = true;
        [, $incidentId] = $this->captureMp3FallbackLog(
            fn () => $this->send($form), $this->subject->getId(),
            $operation === 'editorial' ? 'editorial_reprocessing' : 'replacement',
        );
        self::assertStringContainsString($incidentId, $this->responseText());
        $this->assertWitness();
        self::assertSame([
            'processing fault reached' => true, 'logger failure reached' => true,
            'original' => ['exists' => true, 'hash' => $this->originalHash], 'SQL' => $this->baseline,
        ], [
            'processing fault reached' => $this->probe->faultCount > 0,
            'logger failure reached' => $this->probe->loggingFailed,
            'original' => $this->originalState(), 'SQL' => $this->sqlState(),
        ]);
    }

    /** @dataProvider existingMp3Operations */
    public function testCorrectedRequestCanBeResubmittedAfterFailure(string $operation): void
    {
        $form = $this->prepare($operation);
        $this->arm('metadata');
        $this->send($form);
        $this->assertWitness();
        self::assertGreaterThan(0, $this->probe->faultCount);
        self::assertSame($this->input, $this->formState(), 'Retry must use the returned form, not an artificial new GET.');
        $retry = $this->client->getCrawler()->selectButton('Sauvegarder')->form();
        $this->disarm();
        $this->send($retry); // send() selects a fresh upload copy for a replacement.
        self::assertResponseRedirects();
        $row = $this->sqlState();
        self::assertSame('Titre corrige', $row['titre']);
        self::assertSame('Description corrigee', $row['descriptif']);
        self::assertSame($this->input['emission[categorie]'], (string) $row['categorie_id']);
        self::assertSame($this->input['emission[users]'], array_map('strval', $row['users']));
        self::assertStringStartsWith('2025-09-17', $row['datepub']);
        self::assertFileExists($this->path($row['thumbnail_mp3']));
        self::assertStringEndsWith('/' . $row['thumbnail_mp3'], $row['url']);
        $info = (new \getID3())->analyze($this->path($row['thumbnail_mp3']));
        self::assertArrayNotHasKey('error', $info);
        self::assertStringEndsWith(' Titre corrige', $info['tags']['id3v2']['title'][0]);
        $this->assertWitness();
    }

    public static function existingMp3Operations(): iterable
    {
        yield 'replacement' => ['replacement'];
        yield 'editorial reprocessing' => ['editorial'];
    }

    public static function allOperations(): iterable
    {
        yield 'initial upload' => ['upload'];
        yield from self::existingMp3Operations();
    }
}
