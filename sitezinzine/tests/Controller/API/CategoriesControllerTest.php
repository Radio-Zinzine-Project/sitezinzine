<?php

declare(strict_types=1);

namespace App\Tests\Controller\API;

use App\Controller\API\CategoriesController;
use App\Entity\Categories;
use App\Repository\CategoriesRepository;
use App\Tests\Support\ControllerActionTestCase;
use Symfony\Component\Serializer\SerializerInterface;

class CategoriesControllerTest extends ControllerActionTestCase
{
    /** @dataProvider results */
    public function testIndexUsesPublicSerializationGroup(bool $empty): void
    {
        $controller = new CategoriesController();
        $this->wire($controller);
        $items = $empty ? [] : [new Categories()];
        $repo = $this->createMock(CategoriesRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn($items);
        $serializer = $this->createMock(SerializerInterface::class);
        $json = $empty ? '[]' : '[{"id":12}]';
        $serializer->expects(self::once())->method('serialize')->with($items, 'json', self::callback(static fn (array $context): bool => $context['groups'] === ['categories.index']))->willReturn($json);
        $this->container->set('serializer', $serializer);
        $response = $controller->index($repo);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame($json, $response->getContent());
    }

    public static function results(): iterable
    {
        yield 'vide' => [true];
        yield 'avec résultats' => [false];
    }
}
