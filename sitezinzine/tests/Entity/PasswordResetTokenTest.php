<?php

namespace App\Tests\Entity;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class PasswordResetTokenTest extends TestCase
{
    public function testConstructorInitializesValues(): void
    {
        $user = new User();

        $expiresAt = new \DateTimeImmutable('+1 hour');

        $before = new \DateTimeImmutable();

        $token = new PasswordResetToken(
            $user,
            'abc123',
            $expiresAt
        );

        $after = new \DateTimeImmutable();

        $this->assertNull($token->getId());
        $this->assertSame($user, $token->getUser());
        $this->assertSame('abc123', $token->getToken());
        $this->assertSame($expiresAt, $token->getExpiresAt());

        $reflection = new \ReflectionClass($token);
        $property = $reflection->getProperty('createdAt');
        $property->setAccessible(true);

        $createdAt = $property->getValue($token);

        $this->assertInstanceOf(
            \DateTimeImmutable::class,
            $createdAt
        );

        $this->assertGreaterThanOrEqual(
            $before,
            $createdAt
        );

        $this->assertLessThanOrEqual(
            $after,
            $createdAt
        );
    }

    public function testFutureTokenIsNotExpired(): void
    {
        $token = new PasswordResetToken(
            new User(),
            'future-token',
            new \DateTimeImmutable('+1 hour')
        );

        $this->assertFalse($token->isExpired());
    }

    public function testPastTokenIsExpired(): void
    {
        $token = new PasswordResetToken(
            new User(),
            'expired-token',
            new \DateTimeImmutable('-1 hour')
        );

        $this->assertTrue($token->isExpired());
    }

    public function testTokenExpiringNowIsExpired(): void
    {
        $expiresAt = new \DateTimeImmutable();

        $token = new PasswordResetToken(
            new User(),
            'now-token',
            $expiresAt
        );

        $this->assertTrue($token->isExpired());
    }
}