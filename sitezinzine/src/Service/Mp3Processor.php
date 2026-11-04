<?php

namespace App\Service;

use App\Entity\Emission;
use App\Repository\CategorieTagImageRepository;
use Symfony\Component\Filesystem\Filesystem;
use Vich\UploaderBundle\Storage\StorageInterface;
use App\ValueObject\PreparedMp3;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\PersistentCollection;
use App\Enum\Mp3FailureStage;
use App\Exception\Mp3OperationFailure;

final class Mp3Processor
{
    private ?string $resolvedWorkRoot = null;

    public function __construct(
        private StorageInterface $storage,
        private Filesystem $fs,
        private CategorieTagImageRepository $categorieTagImageRepository,
        private string $mp3BaseDir, // ex: '%kernel.project_dir%/public/uploads/mp3'
        private string $mp3PublicBaseUrl,
        private string $projectDir,
        private ?string $mp3WorkDir = null,
    ) {}

    public function process(Emission $emission): void
    {
        $filename = $emission->getThumbnailMp3();
        $this->categoryCode($emission);
        if (!$filename) {
            throw new \RuntimeException("Aucun nom de fichier MP3 n'a été enregistré par Vich.");
        }
        $source = $this->storedPath($filename);
        if (!is_file($source)) {
            throw new \RuntimeException('Fichier source introuvable : ' . $source);
        }
        $prepared = $this->prepare($emission, new File($source));
        try {
            $relative = $this->publish($prepared);
            $emission->setThumbnailMp3($relative)->setUrl($this->publicUrl($relative));
            // Legacy ingestion consumes an unclassified source only after successful preparation.
            // Replacements of persisted references go through Mp3Replacement instead.
            if (!str_contains($filename, '/')) {
                @unlink($source);
            }
        } finally {
            $this->release($prepared);
        }
    }

    private function categoryCode(Emission $emission): string
    {
        $categorie = $emission->getCategorie();
        $code = $categorie?->getSlug();

        if ($code === null || !preg_match('/^[A-Z]{3}$/', $code)) {
            throw new \RuntimeException("Categorie.slug manquant/invalide : impossible de ranger le MP3.");
        }

        return $code;
    }

    public function prepare(Emission $emission, File $source): PreparedMp3
    {
        return Mp3OperationFailure::at(Mp3FailureStage::PREPARATION, fn () => $this->prepareCandidate($emission, $source));
    }

    private function prepareCandidate(Emission $emission, File $source): PreparedMp3
    {
        $code = $this->categoryCode($emission);
        $date = $emission->getDatepub() ?? new \DateTime();
        $directory = $this->workRoot() . DIRECTORY_SEPARATOR . 'mp3-' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Création du répertoire privé MP3 impossible.');
        }
        $candidate = new PreparedMp3(
            $directory, $directory . '/ready.mp3', $code, $date->format('Y'),
            $code . $date->format('Ymd') . $this->formatTitleCamelCase($emission->getTitre())
        );
        try {
            $input = $directory . '/input.mp3';
            if (!copy($source->getPathname(), $input) || !chmod($input, 0600)) {
                throw new \RuntimeException('Copie privée du MP3 impossible.');
            }
            $before = Mp3OperationFailure::at(Mp3FailureStage::VALIDATION, fn () => $this->analyze($input));
            $expected = Mp3OperationFailure::at(Mp3FailureStage::METADATA, fn () => $this->writeTags($input, $emission));
            $after = Mp3OperationFailure::at(Mp3FailureStage::METADATA, fn () => $this->analyze($input));
            Mp3OperationFailure::at(Mp3FailureStage::METADATA, function () use ($before, $after, $expected): void {
                if ($before['audio_sha256'] !== $after['audio_sha256']) {
                    throw new \RuntimeException('Le contenu audio a changé pendant le traitement ID3.');
                }
                foreach ($expected as $tag => $values) {
                    if ($tag === 'attached_picture') {
                        $picture = $after['comments']['picture'][0] ?? [];
                        if (($picture['data'] ?? null) !== $values[0]['data'] || ($picture['image_mime'] ?? null) !== $values[0]['mime']) {
                            throw new \RuntimeException('Vérification de la couverture ID3 impossible.');
                        }
                    } elseif ($this->nonEmptyTagValues($after['tags']['id3v2'][$tag] ?? []) !== $this->nonEmptyTagValues($values)) {
                        throw new \RuntimeException('Vérification des métadonnées ID3 impossible : ' . $tag);
                    }
                }
            });
            // Seal only the private candidate. The original is never the rename source.
            Mp3OperationFailure::at(Mp3FailureStage::MOVE, fn () => $this->fs->rename($input, $candidate->path, false));
            return $candidate;
        } catch (\Throwable $error) {
            $this->release($candidate);
            throw $error;
        }
    }

    /** Editorial projection without file access, cover lookup or tag writing. */
    public function editorialState(Emission $emission, array $persisted = []): array
    {
        $value = static fn (string $name, mixed $current): mixed => array_key_exists($name, $persisted) ? $persisted[$name] : $current;
        $category = $value('categorie', $emission->getCategorie());
        $date = $value('datepub', $emission->getDatepub());
        $publisher = $value('editeur', $emission->getEditeur());

        return [
            'title' => trim($value('titre', $emission->getTitre())),
            // Category identity also determines the category/year cover lookup.
            'category' => [$category?->getId(), $category?->getSlug(), $category?->getTitre()],
            'date' => $date?->format('Y-m-d'),
            'artist' => $this->artistFrom(
                $this->editorialMembers($value('users', $emission->getUsers()), $persisted !== []),
                $this->editorialMembers($value('inviteOldAnimateurs', $emission->getInviteOldAnimateurs()), $persisted !== []),
                $value('ref', $emission->getRef())
            ),
            'comment' => $this->trimComment($value('descriptif', $emission->getDescriptif())),
            'publisher' => $publisher?->getName() ?? 'Radio Zinzine',
        ];
    }

    private function editorialMembers(Collection $members, bool $original): array
    {
        // Initializing first gives lazy collections their real persisted snapshot.
        $current = $members->toArray();
        return $original && $members instanceof PersistentCollection ? $members->getSnapshot() : $current;
    }

    public function existingFile(string $relative): File
    {
        // A plain File is never an upload, and prepare() only reads it into an independent copy.
        return new File($this->storedPath($relative));
    }

    public function publish(PreparedMp3 $candidate): string
    {
        return Mp3OperationFailure::at(Mp3FailureStage::MOVE, fn () => $this->publishCandidate($candidate));
    }

    private function publishCandidate(PreparedMp3 $candidate): string
    {
        if (!chmod($candidate->path, 0644)) {
            throw new \RuntimeException('Permissions de publication MP3 incorrectes.');
        }
        $this->fs->mkdir($this->mp3BaseDir);
        $destDir = $this->storedPath($candidate->code . '/' . $candidate->year);
        $this->fs->mkdir($destDir);
        // Check again after mkdir, including any pre-existing directory symlinks.
        $this->storedPath($candidate->code . '/' . $candidate->year);
        for ($suffix = 0; ; ++$suffix) {
            $name = $candidate->basename . ($suffix === 0 ? '' : '-' . $suffix) . '.mp3';
            $target = $destDir . DIRECTORY_SEPARATOR . $name;
            // link() is an atomic no-replace operation, unlike check-then-rename(false).
            if (@link($candidate->path, $target)) {
                return $candidate->code . '/' . $candidate->year . '/' . $name;
            }
            clearstatcache(true, $target);
            if (file_exists($target) || is_link($target)) {
                continue;
            }
            throw new \RuntimeException('Publication MP3 impossible : lien dur non disponible (vérifier le système de fichiers et les permissions).');
        }
    }

    public function publicUrl(string $relative): string
    {
        return rtrim($this->mp3PublicBaseUrl, '/') . '/' . $relative;
    }

    public function storedFileHash(?string $relative): ?string
    {
        if ($relative === null) {
            return null;
        }
        try {
            $path = $this->storedPath($relative);
            return is_file($path) ? (@hash_file('sha256', $path) ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function analyze(string $path): array
    {
        require_once $this->projectDir . '/vendor/james-heinrich/getid3/getid3/getid3.php';
        $reader = new \getID3();
        $info = $reader->analyze($path);
        $offset = $info['avdataoffset'] ?? 0;
        $length = ($info['avdataend'] ?? 0) - $offset;
        if (!empty($info['error']) || ($info['fileformat'] ?? null) !== 'mp3' || $length <= 0) {
            throw new \RuntimeException('MP3 invalide ou illisible.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('MP3 invalide ou illisible.');
        }
        try {
            $hash = hash_init('sha256');
            if (fseek($stream, $offset) !== 0 || hash_update_stream($hash, $stream, $length) !== $length) {
                throw new \RuntimeException('Lecture du flux audio MP3 impossible.');
            }
            $info['audio_sha256'] = hash_final($hash);
        } finally {
            fclose($stream);
        }
        return $info;
    }

    private function nonEmptyTagValues(array $values): array
    {
        return array_values(array_filter(array_map('strval', $values), static fn (string $value) => $value !== ''));
    }

    private function workRoot(): string
    {
        // Standalone processors with non-public storage can keep their private work nearby.
        $public = realpath($this->projectDir . '/public');
        $base = realpath($this->mp3BaseDir);
        $default = $public && $base && str_starts_with($base, $public . DIRECTORY_SEPARATOR)
            ? $this->projectDir . '/var/mp3-work' : $this->mp3BaseDir . '/private';
        $work = $this->mp3WorkDir ?? $default;
        $this->fs->mkdir($work, 0700);
        $root = realpath($work);
        if ($root === false || is_link($work) || ($public && ($root === $public || str_starts_with($root, $public . DIRECTORY_SEPARATOR)))) {
            throw new \RuntimeException('Le stockage de préparation MP3 doit être privé.');
        }
        if (!chmod($root, 0700)) {
            throw new \RuntimeException('Permissions du stockage privé MP3 incorrectes.');
        }
        return $this->resolvedWorkRoot = $root;
    }

    public function release(PreparedMp3 $candidate): void
    {
        // Never recursively delete a caller-supplied path or follow a symlink.
        $directory = realpath($candidate->directory);
        if ($directory === false || is_link($candidate->directory)
            || dirname($directory) !== $this->resolvedWorkRoot
            || !preg_match('/^mp3-[a-f0-9]{32}$/', basename($directory))) {
            return;
        }
        $entries = @scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if ($entry !== '.' && $entry !== '..' && !is_link($path) && is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private function storedPath(string $relative): string
    {
        if ($relative === '' || preg_match('~(^[/\\\\]|[\\\\:]|(^|/)\.\.(/|$)|\x00)~', $relative)) {
            throw new \RuntimeException('Chemin MP3 non autorisé.');
        }
        $root = realpath($this->mp3BaseDir);
        if ($root === false) {
            throw new \RuntimeException('Stockage MP3 introuvable.');
        }
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path)) {
                throw new \RuntimeException('Lien symbolique non autorisé dans le stockage MP3.');
            }
        }
        return $path;
    }

    public function consumeUpload(UploadedFile $upload, ?string $original): void
    {
        $path = realpath($upload->getPathname());
        try {
            $originalPath = $original !== null ? realpath($this->storedPath($original)) : null;
        } catch (\Throwable) {
            return;
        }
        if ($path !== false && $path !== $originalPath) {
            @unlink($path);
        }
    }

    public function removeObsolete(string $relative): void
    {
        $path = $this->storedPath($relative);
        if (is_file($path)) {
            $this->fs->remove($path);
        }
    }

    private function formatTitleCamelCase(string $s): string
    {
        $s = trim($s);

        // translittération (é → e, etc.)
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;

        // remplace tout ce qui n’est pas lettre/chiffre par espace
        $s = preg_replace('/[^A-Za-z0-9]+/', ' ', $s) ?? '';

        // met en mots
        $words = explode(' ', strtolower($s));

        // supprime les valeurs vides éventuelles
        $words = array_filter($words, static fn($w) => $w !== '');

        // met en CamelCase
        $words = array_map(static fn($w) => ucfirst($w), $words);

        return substr(implode('', $words), 0, 50) ?: 'Emission';
    }

    private function writeTags(string $filePath, Emission $emission): array
    {
        $getId3Php = $this->projectDir . '/vendor/james-heinrich/getid3/getid3/getid3.php';
        $writePhp  = $this->projectDir . '/vendor/james-heinrich/getid3/getid3/write.php';

        if (!is_file($getId3Php)) {
            throw new \RuntimeException('getid3.php introuvable : ' . $getId3Php);
        }

        if (!is_file($writePhp)) {
            throw new \RuntimeException('write.php introuvable : ' . $writePhp);
        }

        require_once $getId3Php;
        require_once $writePhp;

        if (!class_exists('getid3_writetags')) {
            throw new \RuntimeException('La classe getid3_writetags n’a pas été chargée.');
        }

        $tagwriter = new \getid3_writetags();
        $tagwriter->filename = $filePath;
        $tagwriter->tagformats = ['id3v2.3'];
        $tagwriter->overwrite_tags = true;
        $tagwriter->remove_other_tags = false;
        $tagwriter->tag_encoding = 'UTF-8';

        $cat = $emission->getCategorie();
        $code = $cat?->getSlug() ?? 'XXX';

        $date = $emission->getDatepub() ?? new \DateTime();
        $dateStr = $date->format('Ymd');

        $titre = trim($emission->getTitre() ?? '');
        $fullTitle = trim(sprintf('%s%s %s', $code, $dateStr, $titre));

        $tagData = [
            'title'     => [$fullTitle],
            'artist'    => [$this->buildArtist($emission)],
            'album'     => [$cat?->getTitre() ?? 'Radio Zinzine'],
            'year'      => [$date->format('Y')],
            'comment'   => [$this->trimComment($emission->getDescriptif() ?? '')],
            'genre'     => ['Podcast'],
            'publisher' => [$emission->getEditeur()?->getName() ?? 'Radio Zinzine'],
            'language'  => ['fr'],
        ];

        $coverPath = $this->resolveCoverPath($emission);

        if ($coverPath !== null) {
            $imageData = @file_get_contents($coverPath);

            if ($imageData !== false) {
                $mime = mime_content_type($coverPath) ?: 'image/jpeg';

                $tagData['attached_picture'][0] = [
                    'data'          => $imageData,
                    'picturetypeid' => 0x03,
                    'description'   => 'Cover',
                    'mime'          => $mime,
                ];
            }
        }

        $tagwriter->tag_data = $tagData;

        if (!$tagwriter->WriteTags()) {
            $errors = $tagwriter->errors ?? [];
            throw new \RuntimeException('Écriture des tags impossible : ' . implode(' | ', $errors));
        }
        return $tagData;
    }

    private function resolveCoverPath(Emission $emission): ?string
    {
        $categorie = $emission->getCategorie();
        $datepub = $emission->getDatepub();

        if ($categorie && $datepub) {
            $annee = (int) $datepub->format('Y');

            $tagImage = $this->categorieTagImageRepository->findOneByCategorieAndYear($categorie, $annee);

            if ($tagImage !== null) {
                $path = $this->storage->resolvePath($tagImage, 'imageFile');

                if ($path && is_file($path)) {
                    return $path;
                }
            }
        }

        $fallback = $this->projectDir . '/public/images/default-mp3-cover.jpg';

        return is_file($fallback) ? $fallback : null;
    }

    private function trimComment(string $htmlOrText): string
    {
        $text = strip_tags($htmlOrText);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = trim($text);

        return mb_substr($text, 0, 500);
    }

    private function buildArtist(Emission $emission): string
    {
        return $this->artistFrom($emission->getUsers()->toArray(), $emission->getInviteOldAnimateurs()->toArray(), $emission->getRef());
    }

    private function artistFrom(array $users, array $guests, ?string $ref): string
    {
        if ($users !== []) {
            return implode(', ', array_map(static fn ($user) => $user->getUsername(), $users));
        }
        if ($guests !== []) {
            return implode(', ', array_map(static fn ($person) => (string) $person, $guests));
        }
        return $ref ?? 'Radio Zinzine';
    }

    public function delete(Emission $emission): void
    {
        $currentMp3 = $emission->getThumbnailMp3();

        if (!$currentMp3) {
            return;
        }

        $fullPath = rtrim($this->mp3BaseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($currentMp3, '/\\');

        if (is_file($fullPath)) {
            $this->fs->remove($fullPath);
        }

        $emission->setThumbnailMp3(null);
        $emission->setUrl(null);
    }

}
