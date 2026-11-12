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
        yield 'spaces only' => ['   '];
        yield 'missing field' => [null, false];
    }

    /** @dataProvider absentAudioUrls */
    public function testSeveralEmissionsMayHaveNoAudio(?string $url, bool $includeUrl = true): void
    {
        $existing = $this->emission();
        $existing->setUrl($url);
        $this->em->flush();
        $owner = $this->user(['ROLE_SUPER_ADMIN']);
        $emission = (new Emission())->setDatepub(new \DateTime())
            ->setCategorie($existing->getCategorie())->setTheme($existing->getTheme());
        $form = $this->form(EmissionType::class, $emission, [
            'current_user' => $owner,
            'can_manage_all_categories' => true,
            'can_manage_all_users' => true,
        ]);
        $data = [
            'titre' => 'Emission sans audio',
            'duree' => '60',
            'categorie' => (string) $existing->getCategorie()->getId(),
            'theme' => (string) $existing->getTheme()->getId(),
            'users' => [(string) $owner->getId()],
        ];
        if ($includeUrl) {
            $data['url'] = $url;
        }
        $form->submit($data);
        $this->assertValidForm($form);
        self::assertNull($emission->getUrl());
        $this->save($emission);
        $id = $emission->getId();
        $this->em->clear();
        self::assertNull($this->em->find(Emission::class, $id)->getUrl());
    }

    public function testEditingEmissionCanClearItsAudioUrl(): void
    {
        $emission = $this->emission();
        $owner = $this->user(['ROLE_SUPER_ADMIN']);
        $url = 'https://example.test/' . bin2hex(random_bytes(16)) . '.mp3';
        $emission->setUrl($url)->addUser($owner);
        $this->em->flush();
        self::assertSame($url, $emission->getUrl());

        $form = $this->form(EmissionType::class, $emission, [
            'current_user' => $owner,
            'can_manage_all_categories' => true,
            'can_manage_all_users' => true,
        ]);
        $form->submit([
            'titre' => $emission->getTitre(),
            'duree' => '60',
            'categorie' => (string) $emission->getCategorie()->getId(),
            'theme' => (string) $emission->getTheme()->getId(),
            'users' => [(string) $owner->getId()],
            'url' => '',
        ]);
        $this->assertValidForm($form);
        self::assertNull($emission->getUrl());
        $this->em->flush();
        $id = $emission->getId();
        $this->em->clear();
        self::assertNull($this->em->find(Emission::class, $id)->getUrl());
    }

    public function testNonEmptyAudioUrlIsPreserved(): void
    {
        $emission = $this->emission();
        $owner = $this->user(['ROLE_SUPER_ADMIN']);
        $url = 'https://Example.test/Émission.MP3?Token=AbC-' . bin2hex(random_bytes(16)) . '&nom=Café%20du%20soir';
        $form = $this->form(EmissionType::class, $emission, [
            'current_user' => $owner,
            'can_manage_all_categories' => true,
            'can_manage_all_users' => true,
        ]);
        $form->submit([
            'titre' => $emission->getTitre(),
            'duree' => '60',
            'categorie' => (string) $emission->getCategorie()->getId(),
            'theme' => (string) $emission->getTheme()->getId(),
            'users' => [(string) $owner->getId()],
            'url' => $url,
        ]);
        $this->assertValidForm($form);
        self::assertSame($url, $emission->getUrl());
        $this->em->flush();
        $id = $emission->getId();
        $this->em->clear();
        self::assertSame($url, $this->em->find(Emission::class, $id)->getUrl());
    }
}
