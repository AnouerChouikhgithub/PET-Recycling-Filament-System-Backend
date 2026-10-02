<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Gesdinet\JWTRefreshTokenBundle\Doctrine\DeleteRefreshTokenRepositoryInterface;
use Gesdinet\JWTRefreshTokenBundle\Doctrine\RefreshTokenRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Repository for App\Entity\RefreshToken (gesdinet v2 contract: must implement
 * RefreshTokenRepositoryInterface AND DeleteRefreshTokenRepositoryInterface —
 * the latter powers single-use rotation, logout and max_tokens_per_user).
 *
 * NOTE: the generic conflict between gesdinet's
 * RefreshTokenRepositoryInterface<T of RefreshTokenInterface> and Doctrine's
 * ServiceEntityRepository<RefreshToken> cannot be expressed in PHPStan
 * generics without contradicting ObjectRepository<T>; the targeted ignores
 * in phpstan.neon (scoped to this file) document that. The runtime contract
 * is exercised by tests/Api/RefreshTokenFlowTest.php.
 *
 * @extends ServiceEntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository implements RefreshTokenRepositoryInterface, DeleteRefreshTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    #[\Override]
    public function findInvalid(?DateTimeInterface $datetime = null): iterable
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.valid < :datetime')
            ->setParameter('datetime', $datetime ?? new \DateTime())
            ->getQuery()
            ->toIterable();
    }

    #[\Override]
    public function findInvalidBatch(?DateTimeInterface $datetime = null, ?int $batchSize = null, int $offset = 0): iterable
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.valid < :datetime')
            ->setParameter('datetime', $datetime ?? new \DateTime());

        if (null !== $batchSize) {
            $qb->setMaxResults($batchSize);
        }
        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }

        return $qb->getQuery()->toIterable();
    }

    #[\Override]
    public function deleteByUser(UserInterface $user): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('t')
            ->delete()
            ->where('t.username = :identifier')
            ->setParameter('identifier', $user->getUserIdentifier())
            ->getQuery()
            ->execute();

        return $deleted;
    }

    #[\Override]
    public function deleteToken(\Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface $refreshToken): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('t')
            ->delete()
            ->where('t.id = :id')
            ->setParameter('id', $refreshToken->getId())
            ->getQuery()
            ->execute();

        return $deleted;
    }

    #[\Override]
    public function deleteAllButNewestForUser(UserInterface $user, int $keep): int
    {
        // Rows to delete are found first: DELETE cannot take an offset, and
        // the kept ones are the newest (by expiry), i.e. an offset from the top.
        /** @var list<RefreshToken> $stale */
        $stale = $this->createQueryBuilder('t')
            ->where('t.username = :identifier')
            ->setParameter('identifier', $user->getUserIdentifier())
            ->orderBy('t.valid', 'DESC')
            ->setFirstResult($keep)
            ->getQuery()
            ->getResult();

        if ([] === $stale) {
            return 0;
        }

        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('t')
            ->delete()
            ->where('t.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (RefreshToken $token): int => (int) $token->getId(), $stale))
            ->getQuery()
            ->execute();

        return $deleted;
    }
}
