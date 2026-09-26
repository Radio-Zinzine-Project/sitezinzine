<?php

namespace App\Repository;

use App\Entity\SiteImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteImage>
 */
class SiteImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteImage::class);
    }

    public function findOneByKey(string $key): ?SiteImage
    {
        return $this->findOneBy([
            'key' => $key,
        ]);
    }
}