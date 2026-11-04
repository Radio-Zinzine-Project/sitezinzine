<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class AppExtensionTest extends TestCase
{
    /** @dataProvider highlightCases */
    public function testHighlightsLiteralMatches(string $text, ?string $search, string $expected): void
    {
        self::assertSame($expected, (new AppExtension())->highlightText($text, $search));
    }

    public static function highlightCases(): iterable
    {
        yield 'no search' => ['Radio Zinzine', null, 'Radio Zinzine'];
        yield 'empty search' => ['Radio Zinzine', '', 'Radio Zinzine'];
        yield 'zero is currently treated as no search' => ['Radio 2020', '0', 'Radio 2020'];
        yield 'no match' => ['Radio Zinzine', 'journal', 'Radio Zinzine'];
        yield 'empty text' => ['', 'radio', ''];
        yield 'all matches preserve case' => [
            'Radio radio RADIO',
            'radio',
            '<span class="highlight">Radio</span> <span class="highlight">radio</span> <span class="highlight">RADIO</span>',
        ];
        yield 'literal regex characters and delimiter' => [
            'Programme (a+b)/c? puis (a+b)/c?',
            '(a+b)/c?',
            'Programme <span class="highlight">(a+b)/c?</span> puis <span class="highlight">(a+b)/c?</span>',
        ];
        yield 'match inside a word' => ['radiophonique', 'radio', '<span class="highlight">radio</span>phonique'];
    }

    public function testHighlightFilterRendersItsMarkupWithTwigAutoescapingEnabled(): void
    {
        $twig = new Environment(new ArrayLoader([
            'highlight' => '{{ text|highlight(search) }}',
        ]), ['autoescape' => 'html']);
        $twig->addExtension(new AppExtension());

        self::assertSame(
            '<span class="highlight">Radio</span> Zinzine',
            $twig->render('highlight', ['text' => 'Radio Zinzine', 'search' => 'radio'])
        );
    }
}
