<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\EmissionRepository;
use App\Tests\Support\FunctionalTestCase;

final class PublicEmissionWorkflowTest extends FunctionalTestCase
{
    public function testHomePageProvidesPublicNavigation(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/emission/recherche"]');
        self::assertSelectorExists('a[href="/programme"]');
    }

    public function testPublicEmissionPageDisplaysItsTitleDescriptionAndCategory(): void
    {
        $emission = $this->createEmission($this->marker.' reportage', $this->createUser());
        $this->client->request('GET', '/emission/'.$emission->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bloc-texte h1', $emission->getTitre());
        self::assertSelectorTextContains('.bloc-texte .descriptif', $emission->getDescriptif());
        self::assertSelectorTextContains('.catemission', $emission->getCategorie()->getTitre());
        self::assertSelectorExists('a[href="'.$emission->getUrl().'"]');
    }

    public function testMissingEmissionUsesTheCustom404Page(): void
    {
        $missingId = (int) $this->entityManager->createQuery('SELECT MAX(e.id) FROM App\Entity\Emission e')->getSingleScalarResult() + 1;
        $this->client->request('GET', '/emission/'.$missingId);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1.error-page-title', 'Cette page est introuvable');
        self::assertSelectorExists('a.error-button--primary[href="/"]');
    }

    public function testSearchFindsTheRelevantEmissionAndOpensItsPage(): void
    {
        $matching = $this->createEmission($this->marker.' reportage');
        $other = $this->createEmission('autresujet'.bin2hex(random_bytes(8)));
        $crawler = $this->search('/emission/recherche', ['titre' => $this->marker]);
        self::assertResponseIsSuccessful();
        $this->assertEmissionVisible($matching);
        $this->assertEmissionVisible($other, false);
        $this->client->click($crawler->filter('.titre a[href="/emission/'.$matching->getId().'"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bloc-texte h1', $matching->getTitre());
    }

    public function testSearchWithoutMatchesDisplaysTheEmptyResultMessage(): void
    {
        $this->createEmission('autresujet'.bin2hex(random_bytes(8)));
        $this->search('/emission/recherche', ['titre' => $this->marker]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.no-results', 'Aucune émission trouvée pour les critères de recherche.');
        self::assertSelectorNotExists('.emission-list section');
    }

    public function testEmptySearchKeepsTheUnfilteredResults(): void
    {
        $this->createEmission($this->marker.' reportage');
        $crawler = $this->client->request('GET', '/emission/recherche');
        self::assertResponseIsSuccessful();
        $before = $crawler->filter('.emission-list .titre a')->extract(['href']);
        self::assertNotEmpty($before);
        $crawler = $this->client->submit($crawler->selectButton('Rechercher')->form(['emission_search[titre]' => '']));
        self::assertResponseIsSuccessful();
        self::assertSame($before, $crawler->filter('.emission-list .titre a')->extract(['href']));
        self::assertSelectorNotExists('.no-results');
    }

    public function testCategorySelectionExcludesOtherCategories(): void
    {
        $category = $this->createCategory();
        $first = $this->createEmission($this->marker.' premier', category: $category);
        $second = $this->createEmission($this->marker.' second', category: $category);
        $other = $this->createEmission($this->marker.' autre');
        $this->search('/emission/recherche', ['categorie' => (string) $category->getId()]);
        self::assertResponseIsSuccessful();
        $this->assertEmissionVisible($first);
        $this->assertEmissionVisible($second);
        $this->assertEmissionVisible($other, false);
    }

    public function testThemeSelectionExcludesOtherThemes(): void
    {
        $theme = $this->createTheme();
        $first = $this->createEmission($this->marker.' premier', theme: $theme);
        $second = $this->createEmission($this->marker.' second', theme: $theme);
        $other = $this->createEmission($this->marker.' autre');
        $this->search('/emission/recherche', ['theme' => (string) $theme->getId()]);
        self::assertResponseIsSuccessful();
        $this->assertEmissionVisible($first);
        $this->assertEmissionVisible($second);
        $this->assertEmissionVisible($other, false);
    }

    public function testNextPagePreservesTheFilterAndDisplaysDifferentEmissions(): void
    {
        $category = $this->createCategory();
        $theme = $this->createTheme();
        // Read the real configured page size instead of inventing a fixture limit.
        $pagination = static::getContainer()->get(EmissionRepository::class)->findBySearch(['categorie' => $category]);
        $pageSize = $pagination->getItemNumberPerPage();
        $expectedLinks = [];
        for ($index = 0; $index <= $pageSize; ++$index) {
            $emission = $this->createEmission($this->marker.' episode '.$index, category: $category, theme: $theme);
            $expectedLinks[] = '/emission/'.$emission->getId();
        }
        $crawler = $this->search('/emission/recherche', ['categorie' => (string) $category->getId()]);
        self::assertResponseIsSuccessful();
        $firstPage = $crawler->filter('.emission-list .titre a')->extract(['href']);
        self::assertCount($pageSize, $firstPage);
        self::assertSelectorExists('.pagination a');
        $next = $crawler->filter('.pagination a')->reduce(static function ($node): bool {
            parse_str((string) parse_url($node->attr('href'), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 0) === 2;
        })->first();
        self::assertCount(1, $next);
        $this->client->click($next->link());
        self::assertResponseIsSuccessful();
        $secondPage = $this->client->getCrawler()->filter('.emission-list .titre a')->extract(['href']);
        self::assertCount(1, $secondPage);
        self::assertSame([], array_intersect($firstPage, $secondPage));
        self::assertEqualsCanonicalizing($expectedLinks, array_merge($firstPage, $secondPage));
    }
}
