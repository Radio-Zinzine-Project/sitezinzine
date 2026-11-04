<?php

declare(strict_types=1);

namespace App\Tests\Controller\API;

use App\Controller\API\EmissionsController;
use App\Entity\Emission;
use App\Repository\EmissionRepository;
use App\Tests\Support\ControllerActionTestCase;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;

class EmissionsControllerTest extends ControllerActionTestCase
{
    /** @dataProvider pages */
    public function testIndexUsesRequestedPageAndPublicGroup(string $query, int $page): void
    {
        $controller = new EmissionsController();
        $this->wire($controller);
        $pagination = $this->createMock(PaginationInterface::class);
        $repo = $this->createMock(EmissionRepository::class);
        $repo->expects(self::once())->method('paginateEmissions')->with($page, '')->willReturn($pagination);
        $this->serialization($pagination, '{"items":[]}');
        $response = $controller->index($repo, Request::create('/'.$query));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"items":[]}', $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public static function pages(): iterable
    {
        yield ['', 1];
        yield ['?page=2', 2];
    }

    public function testShowSerializesResolvedEmission(): void
    {
        $controller = new EmissionsController();
        $this->wire($controller);
        $emission = new Emission();
        $this->serialization($emission, '{"id":42}');
        $response = $controller->show($emission);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"id":42}', $response->getContent());
    }

    private function serialization(object $value, string $json): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('serialize')->with($value, 'json', self::callback(static fn (array $context): bool => $context['groups'] === ['emissions.index']))->willReturn($json);
        $this->container->set('serializer', $serializer);
    }

    // create() termine par dd() ; lastEmissions() appelle une méthode absente du repository.
    // Aucun faux scénario de succès ne simule ces fonctionnalités inachevées.
}
