<?php

namespace App\Repository;

use App\Entity\GameSession;
use App\Entity\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameSession>
 */
class GameSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameSession::class);
    }

    public function findByRoomAndPlayer(Room $room, string $playerName, string $ipAddress): ?GameSession
    {
        return $this->createQueryBuilder('gs')
            ->andWhere('gs.room = :room')
            ->andWhere('gs.playerName = :playerName')
            ->andWhere('gs.ipAddress = :ipAddress')
            ->setParameter('room', $room)
            ->setParameter('playerName', $playerName)
            ->setParameter('ipAddress', $ipAddress)
            ->orderBy('gs.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveSessionsForRoom(Room $room): array
    {
        return $this->createQueryBuilder('gs')
            ->andWhere('gs.room = :room')
            ->andWhere('gs.lastActivity > :since')
            ->setParameter('room', $room)
            ->setParameter('since', new \DateTimeImmutable('-1 hour'))
            ->orderBy('gs.score', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getLeaderboard(Room $room, int $limit = 10): array
    {
        return $this->createQueryBuilder('gs')
            ->andWhere('gs.room = :room')
            ->andWhere('gs.isCompleted = true')
            ->setParameter('room', $room)
            ->orderBy('gs.score', 'DESC')
            ->addOrderBy('gs.completedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getCompletedSessions(Room $room): array
    {
        return $this->createQueryBuilder('gs')
            ->andWhere('gs.room = :room')
            ->andWhere('gs.isCompleted = true')
            ->setParameter('room', $room)
            ->orderBy('gs.completedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getWinner(Room $room): ?GameSession
    {
        return $this->createQueryBuilder('gs')
            ->andWhere('gs.room = :room')
            ->andWhere('gs.isCompleted = true')
            ->setParameter('room', $room)
            ->orderBy('gs.score', 'DESC')
            ->addOrderBy('gs.completedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getRecentCompletions(Room $room, int $lastEventId): array
    {
        // Pour simuler les "événements récents", on retourne les sessions complétées
        // avec un ID supérieur au dernier événement traité
        // Cette méthode peut être améliorée avec une vraie table d'événements
        return $this->createQueryBuilder('gs')
            ->andWhere('gs.room = :room')
            ->andWhere('gs.isCompleted = true')
            ->andWhere('gs.id > :lastEventId')
            ->setParameter('room', $room)
            ->setParameter('lastEventId', max(0, $lastEventId - 1000)) // Compenser l'offset
            ->orderBy('gs.completedAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
    }

    public function save(GameSession $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(GameSession $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
