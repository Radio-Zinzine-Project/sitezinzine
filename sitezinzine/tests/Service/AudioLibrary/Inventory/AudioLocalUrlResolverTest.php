<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use PHPUnit\Framework\TestCase;

final class AudioLocalUrlResolverTest extends TestCase
{
    private const BASES = [
        'http://radiozinzine.org/emissions/',
        'http://radiozinzine.org/uploads/emissionsMp3/',
    ];
    private const PATH = 'SAN/2015/SAN20150723MedecineTraditionnelleChinoise.mp3';

    public function testHistoricalAndFutureBasesResolveToTheSameExactPath(): void
    {
        $resolver = new AudioLocalUrlResolver(self::BASES);
        $expected = ['status' => 'local', 'relative_path' => self::PATH];
        self::assertSame($expected, $resolver->resolve(self::BASES[0] . self::PATH));
        self::assertSame($expected, $resolver->resolve(self::BASES[1] . self::PATH));
    }

    public function testHistoricalNamesAndFilenameBytesArePreservedWithoutNewNamingRules(): void
    {
        $path = 'Archives anciennes/Émission+été (Copie).MP3';
        // Raw spaces are not an unambiguous URL representation: no silent encoding.
        $resolver = new AudioLocalUrlResolver(self::BASES);
        self::assertSame(['status' => 'uncertain', 'relative_path' => null], $resolver->resolve(self::BASES[0] . $path));
        $path = 'Archives/Émission+été_(Copie).MP3';
        self::assertSame(['status' => 'local', 'relative_path' => $path], $resolver->resolve(self::BASES[0] . $path));
    }

    public function testPathCaseIsNeverFolded(): void
    {
        $resolver = new AudioLocalUrlResolver(self::BASES);
        foreach (['SAN/2015/Archive.mp3', 'san/2015/archive.mp3'] as $path) {
            self::assertSame(['status' => 'local', 'relative_path' => $path], $resolver->resolve(self::BASES[0] . $path));
        }
    }

    /** @dataProvider nonLocalUrls */
    public function testNonLocalResultsNeverExposeAnInferredPath(?string $url, string $status): void
    {
        $before = $url;
        self::assertSame(['status' => $status, 'relative_path' => null], (new AudioLocalUrlResolver(self::BASES))->resolve($url));
        self::assertSame($before, $url);
    }

    public static function nonLocalUrls(): array
    {
        $base = self::BASES[0];
        return [
            'null' => [null, 'absent'],
            'empty' => ['', 'absent'],
            'whitespace is not silently trimmed' => ['   ', 'uncertain'],
            'external host' => ['https://example.org/emissions/' . self::PATH, 'external'],
            'host suffix attack' => ['http://radiozinzine.org.example.com/emissions/' . self::PATH, 'external'],
            'other directory on same host' => ['http://radiozinzine.org/podcasts/' . self::PATH, 'external'],
            'similar path prefix' => ['http://radiozinzine.org/emissions-autres/' . self::PATH, 'external'],
            'preproduction is not implicitly allowed' => ['https://eloisemcglasson.ovh/uploads/emissionsMp3/' . self::PATH, 'external'],
            'different scheme' => ['https://radiozinzine.org/emissions/' . self::PATH, 'uncertain'],
            'unsupported local scheme' => ['ftp://radiozinzine.org/emissions/' . self::PATH, 'uncertain'],
            'protocol relative' => ['//radiozinzine.org/emissions/' . self::PATH, 'uncertain'],
            'root relative' => ['/emissions/' . self::PATH, 'uncertain'],
            'relative' => [self::PATH, 'uncertain'],
            'host without scheme' => ['radiozinzine.org/emissions/' . self::PATH, 'uncertain'],
            'explicit standard port' => ['http://radiozinzine.org:80/emissions/' . self::PATH, 'uncertain'],
            'explicit other port' => ['http://radiozinzine.org:8080/emissions/' . self::PATH, 'uncertain'],
            'query' => [$base . self::PATH . '?download=1', 'uncertain'],
            'empty query' => [$base . self::PATH . '?', 'uncertain'],
            'fragment' => [$base . self::PATH . '#audio', 'uncertain'],
            'empty fragment' => [$base . self::PATH . '#', 'uncertain'],
            'encoded separator' => [$base . 'SAN%2F2015/file.mp3', 'uncertain'],
            'encoded backslash' => [$base . 'SAN%5cfile.mp3', 'uncertain'],
            'encoded percent' => [$base . 'file%252Fname.mp3', 'uncertain'],
            'encoded space' => [$base . 'file%20name.mp3', 'uncertain'],
            'malformed percent' => [$base . 'file%ZZ.mp3', 'uncertain'],
            'encoded prefix' => ['http://radiozinzine.org/%65missions/file.mp3', 'uncertain'],
            'parent traversal' => [$base . '../outside.mp3', 'uncertain'],
            'embedded parent traversal' => [$base . 'SAN/../outside.mp3', 'uncertain'],
            'dot component' => [$base . './file.mp3', 'uncertain'],
            'empty component' => [$base . 'SAN//file.mp3', 'uncertain'],
            'trailing separator' => [$base . 'SAN/', 'uncertain'],
            'empty local path' => [$base, 'uncertain'],
            'prefix without separator' => ['http://radiozinzine.org/emissions', 'uncertain'],
            'backslash' => [$base . 'SAN\\file.mp3', 'uncertain'],
            'control character' => [$base . "file\n.mp3", 'uncertain'],
            'null byte' => [$base . "file\0.mp3", 'uncertain'],
            'delete character' => [$base . "file\x7f.mp3", 'uncertain'],
            'colon in relative path' => [$base . 'C:/file.mp3', 'uncertain'],
            'credentials' => ['http://user:secret@radiozinzine.org/emissions/file.mp3', 'uncertain'],
            'malformed URL' => ['http://', 'uncertain'],
            'different prefix case is not normalized' => ['http://radiozinzine.org/Emissions/file.mp3', 'uncertain'],
        ];
    }

    public function testSchemeAndHostUseUrlSemanticsWithoutChangingThePath(): void
    {
        self::assertSame(
            ['status' => 'local', 'relative_path' => self::PATH],
            (new AudioLocalUrlResolver(self::BASES))->resolve('HTTP://RADIOZINZINE.ORG/emissions/' . self::PATH),
        );
    }

    public function testHttpsAndOtherHostsAreLocalOnlyWhenExplicitlyConfigured(): void
    {
        $resolver = new AudioLocalUrlResolver([
            ...self::BASES,
            'https://radiozinzine.org/emissions/',
            'https://example.test/audio',
        ]);
        self::assertSame(['status' => 'local', 'relative_path' => self::PATH], $resolver->resolve('https://radiozinzine.org/emissions/' . self::PATH));
        self::assertSame(['status' => 'local', 'relative_path' => self::PATH], $resolver->resolve('https://example.test/audio/' . self::PATH));
    }

    public function testOverlappingBasesCannotChooseAnArbitraryRelativeIdentity(): void
    {
        $resolver = new AudioLocalUrlResolver(['http://radiozinzine.org/emissions/', 'http://radiozinzine.org/emissions/SAN/']);
        self::assertSame(['status' => 'uncertain', 'relative_path' => null], $resolver->resolve(self::BASES[0] . self::PATH));
    }

    public function testDuplicateBasesDoNotMakeAUniqueIdentityAmbiguous(): void
    {
        $resolver = new AudioLocalUrlResolver([self::BASES[0], rtrim(self::BASES[0], '/')]);
        self::assertSame(['status' => 'local', 'relative_path' => self::PATH], $resolver->resolve(self::BASES[0] . self::PATH));
    }

    /** @dataProvider invalidConfigurations */
    public function testInvalidConfigurationIsRejectedExplicitly(array $bases): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AudioLocalUrlResolver($bases);
    }

    public static function invalidConfigurations(): array
    {
        return [
            'empty configuration' => [[]],
            'non string base' => [[42]],
            'empty base' => [['']],
            'relative base' => [['/emissions/']],
            'missing host' => [['http:///emissions/']],
            'unsupported scheme' => [['ftp://radiozinzine.org/emissions/']],
            'missing library prefix' => [['http://radiozinzine.org']],
            'credentials' => [['http://user:secret@radiozinzine.org/emissions/']],
            'port' => [['http://radiozinzine.org:80/emissions/']],
            'query' => [['http://radiozinzine.org/emissions/?x=1']],
            'fragment' => [['http://radiozinzine.org/emissions/#x']],
            'encoded prefix' => [['http://radiozinzine.org/%65missions/']],
            'traversal' => [['http://radiozinzine.org/emissions/../']],
            'backslash' => [['http://radiozinzine.org/emissions\\private/']],
            'control' => [["http://radiozinzine.org/emissions/\n"]],
            'empty component' => [['http://radiozinzine.org/emissions//']],
        ];
    }
}
