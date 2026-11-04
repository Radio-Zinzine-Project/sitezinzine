<?php

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ErrorPagesHttpTest extends WebTestCase
{
    public function testUnknownUrlDisplaysCustom404Page(): void
    {
        $client = static::createClient([
            'environment' => 'test',
            'debug' => false,
        ]);

        $client->request(
            'GET',
            '/une-page-qui-n-existe-vraiment-pas'
        );

        $this->assertResponseStatusCodeSame(404);

        $this->assertSelectorTextContains(
            'h1.error-page-title',
            'Cette page est introuvable'
        );

        $this->assertSelectorTextContains(
            'h2.error-heading',
            'Impossible de trouver la page'
        );

        $this->assertSelectorTextContains(
            '.error-message',
            "La page que vous cherchez n'existe pas"
        );

        $this->assertSelectorExists(
            'a.error-button--primary[href="/"]'
        );
    }
}