<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;

abstract class PublicControllerActionTestCase extends ControllerActionTestCase
{
    /** Formulaires sans entité : les contraintes réelles sont testées dans tests/Form. */
    protected function unmappedForm(
        string $type,
        bool $submitted,
        bool $valid,
        array $fields = [],
        array $data = [],
        array $options = []
    ): void {
        $form = $this->createMock(FormInterface::class);

        $form
            ->expects(self::once())
            ->method('handleRequest')
            ->with(self::isInstanceOf(Request::class))
            ->willReturnSelf();

        $form->method('isSubmitted')->willReturn($submitted);
        $form->method('isValid')->willReturn($valid);
        $form->method('createView')->willReturn(new FormView());
        $form->method('getData')->willReturn($data);

        $children = [];

        foreach ($fields as $name => $value) {
            $child = $this->createMock(FormInterface::class);
            $child->method('getData')->willReturn($value);
            $children[$name] = $child;
        }

        $form
            ->method('get')
            ->willReturnCallback(
                static fn (string $name) => $children[$name]
            );

        $factory = $this->createMock(FormFactoryInterface::class);

        $factory
            ->expects(self::once())
            ->method('create')
            ->with($type, null, $options)
            ->willReturn($form);

        $this->container->set('form.factory', $factory);
    }
}