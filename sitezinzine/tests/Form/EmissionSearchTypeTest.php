<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\EmissionSearchType;
use App\Tests\Support\FormIntegrationTestCase;

class EmissionSearchTypeTest extends FormIntegrationTestCase
{
    public function testPeopleChoicesIncludeBothSourcesAndAreSorted(): void
    {
        $user = $this->user();
        $old = $this->guest(true);
        $form = $this->form(EmissionSearchType::class);
        self::assertSame('GET', $form->getConfig()->getMethod());
        self::assertFalse($form->getConfig()->getOption('csrf_protection'));
        $choices = $form->get('personne')->createView()->vars['choices'];
        $labels = [];
        $attrs = [];
        foreach ($choices as $choice) {
            $labels[] = $choice->label;
            $attrs[$choice->value] = $choice->attr;
        }
        self::assertSame(['class' => 'user'], $attrs['user:' . $user->getId()]);
        self::assertSame(['class' => 'old-animateur'], $attrs['old:' . $old->getId()]);
        $sorted = $labels;
        natcasesort($sorted);
        self::assertSame(array_values($sorted), $labels);
    }
    public function testSubmittedFiltersMapEntitiesDatesAndPersonIdentifier(): void
    {
        $category = $this->category();
        $theme = $this->theme();
        $old = $this->guest(true);
        $form = $this->form(EmissionSearchType::class);
        $form->submit([
            'titre' => ' Culture ',
            'dateDebut' => '2026-10-01',
            'dateFin' => '2026-10-31',
            'categorie' => (string) $category->getId(),
            'theme' => (string) $theme->getId(),
            'personne' => 'old:' . $old->getId()
        ]);
        $this->assertValidForm($form);
        $data = $form->getData();
        self::assertSame('Culture', $data['titre']);
        self::assertSame($category, $data['categorie']);
        self::assertSame($theme, $data['theme']);
        self::assertSame('old:' . $old->getId(), $data['personne']);
        self::assertSame('2026-10-01', $data['dateDebut']->format('Y-m-d'));
    }
    public function testEmptySearchIsValid(): void
    {
        $form = $this->form(EmissionSearchType::class);
        $form->submit([]);
        $this->assertValidForm($form);
        self::assertNull($form->getData()['personne']);
        self::assertNull($form->getData()['categorie']);
    }
    public function testUnknownPersonAndInvalidDateAreRejected(): void
    {
        $form = $this->form(EmissionSearchType::class);
        $form->submit(['personne' => 'user:2147483647', 'dateDebut' => 'invalid']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('personne')->getErrors()));
        self::assertGreaterThan(0, count($form->get('dateDebut')->getErrors()));
    }

    public function testCategoriesAreNotFilteredByDefault(): void
    {
        $categoryWithoutPublicEmission = $this->category();

        $emission = $this->emission();
        $categoryWithPublicEmission = $emission->getCategorie();

        self::assertNotNull($categoryWithPublicEmission);

        $emission->setUrl('/uploads/mp3/public-emission.mp3');
        $this->em->flush();

        $form = $this->form(EmissionSearchType::class);

        $categoryValues = $this->choiceValues($form->get('categorie'));

        self::assertContains(
            (string) $categoryWithoutPublicEmission->getId(),
            $categoryValues
        );

        self::assertContains(
            (string) $categoryWithPublicEmission->getId(),
            $categoryValues
        );
    }

    public function testPublicOnlyCategoriesContainOnlyCategoriesWithPublicEmissions(): void
    {
        $categoryWithoutEmission = $this->category();

        $emissionWithoutUrl = $this->emission();
        $categoryWithoutPublicEmission = $emissionWithoutUrl->getCategorie();

        $publicEmission = $this->emission();
        $categoryWithPublicEmission = $publicEmission->getCategorie();

        self::assertNotNull($categoryWithoutPublicEmission);
        self::assertNotNull($categoryWithPublicEmission);

        $publicEmission->setUrl('/uploads/mp3/public-emission.mp3');

        $this->em->flush();

        $form = $this->form(
            EmissionSearchType::class,
            null,
            [
                'public_only' => true,
            ]
        );

        $categoryValues = $this->choiceValues($form->get('categorie'));

        self::assertContains(
            (string) $categoryWithPublicEmission->getId(),
            $categoryValues
        );

        self::assertNotContains(
            (string) $categoryWithoutEmission->getId(),
            $categoryValues
        );

        self::assertNotContains(
            (string) $categoryWithoutPublicEmission->getId(),
            $categoryValues
        );
    }
}
