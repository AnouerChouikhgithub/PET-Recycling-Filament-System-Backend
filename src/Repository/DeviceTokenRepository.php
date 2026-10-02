<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeviceToken;
use App\Entity\Machine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceToken>
 */
final class DeviceTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceToken::class);
    }

    /** Find a non-revoked token by its SHA-512 hash (equality lookup). */
    public function findActiveByHash(string $tokenHash): ?DeviceToken
    {
        // join('machine') + include_join_columns: the Machine is fetched with
        // the token in one query (no lazy proxy — the authenticator builds the
        // security principal from it inside the request).
        $token = $this->createQueryBuilder('t')
            ->innerJoin('t.machine', 'm')
            ->addSelect('m')
            ->where('t.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();

        return (null !== $token && !$token->isRevoked()) ? $token : null;
    }

    /** Revoke every token of a machine (device wipe / security incident). */
    public function revokeAllForMachine(Machine $machine): int
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('t')
            ->update()
            ->set('t.revokedAt', ':now')
            ->where('t.machine = :machine')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('machine', $machine)
            ->getQuery()
            ->execute();
    }
}
