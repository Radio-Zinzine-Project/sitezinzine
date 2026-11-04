<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Entity\Diffusion;
use App\Form\DiffusionType;
use App\Tests\Support\FormIntegrationTestCase;

class DiffusionTypeTest extends FormIntegrationTestCase
{
    public function testMapsEmissionDateAndBroadcastNumber(): void
    {
        $emission = $this->emission();
        $diffusion = new Diffusion();
        $form = $this->form(DiffusionType::class, $diffusion);
        $form->submit(['emission' => (string) $emission->getId(), 'horaireDiffusion' => '2026-10-20T14:30', 'nombreDiffusion' => '2']);
        $this->assertValidForm($form);
        self::assertSame($emission, $diffusion->getEmission());
        self::assertSame(2, $diffusion->getNombreDiffusion());
        self::assertSame('2026-10-20 14:30', $diffusion->getHoraireDiffusion()->format('Y-m-d H:i'));
    }
    public function testInvalidDateAndUnknownEmissionAreRejected(): void
    {
        $form = $this->form(DiffusionType::class, new Diffusion());
        $form->submit(['emission' => '2147483647', 'horaireDiffusion' => 'invalid', 'nombreDiffusion' => '1']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('emission')->getErrors()));
        self::assertGreaterThan(0, count($form->get('horaireDiffusion')->getErrors()));
    }
}
