<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminControllerTest extends WebTestCase
{
    public function testAdminPageAccessibleToLoggedInUser(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $user = new User();
        $user->setUsername('admin_' . uniqid());
        $user->setEmail('admin_' . uniqid() . '@example.com');
        $user->setPassword('fakehashedpassword');
        $user->setRoles(['ROLE_USER']);

        $entityManager = $container->get('doctrine')->getManager();
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);

        $client->request('GET', '/admin/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('body');
    }
}