<?php

namespace App\Repository;

use App\Entity\WikipediaArticle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WikipediaArticle>
 */
class WikipediaArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WikipediaArticle::class);
    }

    public function findRandomArticle(?string $difficulty = null): ?WikipediaArticle
    {
        // Approche alternative : récupérer tous les articles correspondants et en choisir un au hasard
        $qb = $this->createQueryBuilder('wa')
            ->andWhere('wa.isActive = true');

        if ($difficulty) {
            $qb->andWhere('wa.difficulty = :difficulty')
               ->setParameter('difficulty', $difficulty);
        }

        $articles = $qb->getQuery()->getResult();

        if (empty($articles)) {
            return null;
        }

        // Sélectionner un article au hasard avec PHP
        $randomIndex = array_rand($articles);
        return $articles[$randomIndex];
    }

    public function findByDifficulty(string $difficulty): array
    {
        return $this->createQueryBuilder('wa')
            ->andWhere('wa.isActive = true')
            ->andWhere('wa.difficulty = :difficulty')
            ->setParameter('difficulty', $difficulty)
            ->orderBy('wa.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('wa')
            ->andWhere('wa.isActive = true')
            ->andWhere('wa.category = :category')
            ->setParameter('category', $category)
            ->orderBy('wa.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(WikipediaArticle $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(WikipediaArticle $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
