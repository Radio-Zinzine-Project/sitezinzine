<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Emission;
use App\Form\EmissionType;
use App\Tests\Support\FormIntegrationTestCase;

final class EmissionAudioUrlOptionalTest extends FormIntegrationTestCase
{
    public static function absentAudioUrls(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
    }

    /** @dataProvider absentAudioUrls */
    public function testSeveralEmissionsMayHaveNoAudio(?string $url): void
    {
        $existing = $this->emission();
        $existing->setUrl($url);
        $this->em->flush();
        $owner = $this->user(['ROLE_SUPER_ADMIN']);
        $emission = (new Emission())->setCategorie($existing->getCategorie())->setTheme($existing->getTheme());
        $form = $this->form(EmissionType::class, $emission, [
            'current_user' => $owner,
            'can_manage_all_categories' => true,
            'can_manage_all_users' => true,
        ]);
        $form->submit([
            'titre' => 'Emission sans audio',
            'duree' => '60',
            'categorie' => (string) $existing->getCategorie()->getId(),
            'theme' => (string) $existing->getTheme()->getId(),
            'users' => [(string) $owner->getId()],
            'url' => $url,
        ]);
        $this->assertValidForm($form);
        self::assertSame('', $emission->getUrl());
    }
}
