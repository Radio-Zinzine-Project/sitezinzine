<?php

namespace App\Service;

use App\Entity\Emission;
use App\Repository\CategorieTagImageRepository;
use Symfony\Component\Filesystem\Filesystem;
use Vich\UploaderBundle\Storage\StorageInterface;

final class Mp3Processor
{
    public function __construct(
        private StorageInterface $storage,
        private Filesystem $fs,
        private CategorieTagImageRepository $categorieTagImageRepository,
        private string $mp3BaseDir, // ex: '%kernel.project_dir%/public/uploads/mp3'
        private string $mp3PublicBaseUrl,
        private string $projectDir,
    ) {}

    public function process(Emission $emission): void
    {
        $categorie = $emission->getCategorie();
        $code = $categorie?->getSlug();

        if ($code === null || !preg_match('/^[A-Z]{3}$/', $code)) {
            throw new \RuntimeException("Categorie.slug manquant/invalide : impossible de ranger le MP3.");
        }

        $currentFilename = $emission->getThumbnailMp3();

        if (!$currentFilename) {
            throw new \RuntimeException("Aucun nom de fichier MP3 n'a été enregistré par Vich.");
        }

        $sourcePath = rtrim($this->mp3BaseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($currentFilename, '/\\');

        if (!is_file($sourcePath)) {
            throw new \RuntimeException("Fichier source introuvable : " . $sourcePath);
        }

        $date = $emission->getDatepub() ?? new \DateTime();
        $yyyy = $date->format('Y');

        $destDir = rtrim($this->mp3BaseDir, '/\\') . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . $yyyy;
        $this->fs->mkdir($destDir);

        $safeTitle = $this->formatTitleCamelCase($emission->getTitre());

        $baseName = sprintf('%s%s%s', $code, $date->format('Ymd'), $safeTitle);
        $finalName = $this->uniqueName($destDir, $baseName, 'mp3');

        $destPath = $destDir . DIRECTORY_SEPARATOR . $finalName;

        $this->writeTags($sourcePath, $emission);
        $this->fs->rename($sourcePath, $destPath, true);

        $relative = $code . '/' . $yyyy . '/' . $finalName;
        $emission->setThumbnailMp3($relative);
        $emission->setUrl(rtrim($this->mp3PublicBaseUrl, '/') . '/' . $relative);
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

    private function uniqueName(string $dir, string $base, string $ext): string
    {
        $i = 0;

        do {
            $suffix = $i === 0 ? '' : '-' . $i;
            $name = $base . $suffix . '.' . $ext;
            $i++;
        } while (is_file($dir . DIRECTORY_SEPARATOR . $name));

        return $name;
    }

    private function writeTags(string $filePath, Emission $emission): void
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
        // 1. users
        if (!$emission->getUsers()->isEmpty()) {
            $names = [];

            foreach ($emission->getUsers() as $user) {
                $names[] = $user->getUsername(); // ou getNom() selon ton modèle
            }

            return implode(', ', $names);
        }

        // 2. invités / anciens animateurs
        if (!$emission->getInviteOldAnimateurs()->isEmpty()) {
            $names = [];

            foreach ($emission->getInviteOldAnimateurs() as $person) {
                $names[] = (string) $person; // tu as déjà un __toString normalement
            }

            return implode(', ', $names);
        }

        // 3. fallback ref
        return $emission->getRef() ?? 'Radio Zinzine';
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
