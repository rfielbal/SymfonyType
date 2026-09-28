<?php

namespace App\Repository;

use App\Entity\HistoriqueMdp;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HistoriqueMdp> */
class HistoriqueMdpRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HistoriqueMdp::class);
    }
}
