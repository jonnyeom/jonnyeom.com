<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StravaToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StravaToken> */
class StravaTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StravaToken::class);
    }

    public function findForAthlete(int $athleteId): StravaToken|null
    {
        return $this->findOneBy(['athleteId' => $athleteId]);
    }
}
