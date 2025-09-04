<?php

namespace App\Controller;

use App\Entity\GameSession;
use App\Entity\Room;
use App\Entity\WikipediaArticle;
use App\Repository\GameSessionRepository;
use App\Repository\RoomRepository;
use App\Repository\WikipediaArticleRepository;
use App\Service\PedantixService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Process\Process;

#[Route('/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoomRepository $roomRepository,
        private GameSessionRepository $gameSessionRepository,
        private WikipediaArticleRepository $wikipediaArticleRepository,
        private PedantixService $pedantixService
    ) {}

    #[Route('/', name: 'admin_dashboard')]
    public function dashboard(): Response
    {
        // Statistiques générales
        $totalRooms = $this->roomRepository->count([]);
        $activeRooms = $this->roomRepository->count(['isGameCompleted' => false]);
        $completedRooms = $this->roomRepository->count(['isGameCompleted' => true]);
        $totalSessions = $this->gameSessionRepository->count([]);
        $activeSessions = $this->gameSessionRepository->createQueryBuilder('gs')
            ->where('gs.lastActivity > :since')
            ->setParameter('since', new \DateTimeImmutable('-1 hour'))
            ->getQuery()
            ->getResult();
        $totalArticles = $this->wikipediaArticleRepository->count([]);
        $activeArticles = $this->wikipediaArticleRepository->count(['isActive' => true]);

        // Dernières activités
        $recentRooms = $this->roomRepository->findBy([], ['createdAt' => 'DESC'], 10);
        $recentSessions = $this->gameSessionRepository->findBy([], ['lastActivity' => 'DESC'], 15);

        // Top joueurs
        $topPlayers = $this->gameSessionRepository->createQueryBuilder('gs')
            ->select('gs.playerName, COUNT(gs.id) as gamesPlayed, AVG(gs.score) as avgScore, MAX(gs.score) as maxScore')
            ->where('gs.isCompleted = true')
            ->groupBy('gs.playerName')
            ->orderBy('avgScore', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'totalRooms' => $totalRooms,
                'activeRooms' => $activeRooms,
                'completedRooms' => $completedRooms,
                'totalSessions' => $totalSessions,
                'activeSessions' => count($activeSessions),
                'totalArticles' => $totalArticles,
                'activeArticles' => $activeArticles,
            ],
            'recentRooms' => $recentRooms,
            'recentSessions' => $recentSessions,
            'topPlayers' => $topPlayers,
        ]);
    }

    #[Route('/rooms', name: 'admin_rooms')]
    public function rooms(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $queryBuilder = $this->roomRepository->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC');

        $search = $request->query->get('search');
        if ($search) {
            $queryBuilder->where('r.title LIKE :search OR r.code LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $gameMode = $request->query->get('gameMode');
        if ($gameMode) {
            $queryBuilder->andWhere('r.gameMode = :gameMode')
                ->setParameter('gameMode', $gameMode);
        }

        $status = $request->query->get('status');
        if ($status === 'active') {
            $queryBuilder->andWhere('r.isGameCompleted = false');
        } elseif ($status === 'completed') {
            $queryBuilder->andWhere('r.isGameCompleted = true');
        }

        $totalQuery = clone $queryBuilder;
        $total = count($totalQuery->getQuery()->getResult());

        $rooms = $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = ceil($total / $limit);

        return $this->render('admin/rooms.html.twig', [
            'rooms' => $rooms,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'search' => $search,
            'gameMode' => $gameMode,
            'status' => $status,
        ]);
    }

    #[Route('/sessions', name: 'admin_sessions')]
    public function sessions(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $queryBuilder = $this->gameSessionRepository->createQueryBuilder('gs')
            ->leftJoin('gs.room', 'r')
            ->addSelect('r')
            ->orderBy('gs.lastActivity', 'DESC');

        $search = $request->query->get('search');
        if ($search) {
            $queryBuilder->where('gs.playerName LIKE :search OR r.title LIKE :search OR r.code LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $totalQuery = clone $queryBuilder;
        $total = count($totalQuery->getQuery()->getResult());

        $sessions = $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = ceil($total / $limit);

        return $this->render('admin/sessions.html.twig', [
            'sessions' => $sessions,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'search' => $search,
        ]);
    }

    #[Route('/articles', name: 'admin_articles')]
    public function articles(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $queryBuilder = $this->wikipediaArticleRepository->createQueryBuilder('wa')
            ->orderBy('wa.createdAt', 'DESC');

        $search = $request->query->get('search');
        if ($search) {
            $queryBuilder->where('wa.title LIKE :search OR wa.category LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        $category = $request->query->get('category');
        if ($category) {
            $queryBuilder->andWhere('wa.category = :category')
                ->setParameter('category', $category);
        }

        $difficulty = $request->query->get('difficulty');
        if ($difficulty) {
            $queryBuilder->andWhere('wa.difficulty = :difficulty')
                ->setParameter('difficulty', $difficulty);
        }

        $totalQuery = clone $queryBuilder;
        $total = count($totalQuery->getQuery()->getResult());

        $articles = $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalPages = ceil($total / $limit);

        // Récupérer les catégories et difficultés disponibles
        $categories = $this->wikipediaArticleRepository->createQueryBuilder('wa')
            ->select('DISTINCT wa.category')
            ->where('wa.category IS NOT NULL')
            ->orderBy('wa.category')
            ->getQuery()
            ->getScalarResult();

        $difficulties = $this->wikipediaArticleRepository->createQueryBuilder('wa')
            ->select('DISTINCT wa.difficulty')
            ->where('wa.difficulty IS NOT NULL')
            ->orderBy('wa.difficulty')
            ->getQuery()
            ->getScalarResult();

        return $this->render('admin/articles.html.twig', [
            'articles' => $articles,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'search' => $search,
            'category' => $category,
            'difficulty' => $difficulty,
            'categories' => array_column($categories, 'category'),
            'difficulties' => array_column($difficulties, 'difficulty'),
        ]);
    }

    #[Route('/actions', name: 'admin_actions')]
    public function actions(): Response
    {
        return $this->render('admin/actions.html.twig');
    }

    #[Route('/api/action/clear-rooms', name: 'admin_clear_rooms', methods: ['POST'])]
    public function clearRooms(): JsonResponse
    {
        try {
            // Supprimer d'abord toutes les sessions de jeu
            $this->entityManager->createQuery('DELETE FROM App\Entity\GameSession')->execute();

            // Puis supprimer toutes les salles
            $roomsDeleted = $this->entityManager->createQuery('DELETE FROM App\Entity\Room')->execute();

            return $this->json([
                'success' => true,
                'message' => "Toutes les salles ont été supprimées ({$roomsDeleted} salles)",
                'roomsDeleted' => $roomsDeleted
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/action/clear-inactive-sessions', name: 'admin_clear_inactive_sessions', methods: ['POST'])]
    public function clearInactiveSessions(): JsonResponse
    {
        try {
            // Supprimer les sessions inactives depuis plus de 24h
            $cutoff = new \DateTimeImmutable('-24 hours');
            $sessionsDeleted = $this->entityManager->createQuery(
                'DELETE FROM App\Entity\GameSession gs WHERE gs.lastActivity < :cutoff'
            )
            ->setParameter('cutoff', $cutoff)
            ->execute();

            return $this->json([
                'success' => true,
                'message' => "Sessions inactives supprimées ({$sessionsDeleted} sessions)",
                'sessionsDeleted' => $sessionsDeleted
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/action/seed-articles', name: 'admin_seed_articles', methods: ['POST'])]
    public function seedArticles(): JsonResponse
    {
        try {
            $process = new Process(['php', 'bin/console', 'app:seed-wikipedia-articles']);
            $process->setWorkingDirectory($this->getParameter('kernel.project_dir'));
            $process->setTimeout(300); // 5 minutes
            $process->run();

            if ($process->isSuccessful()) {
                return $this->json([
                    'success' => true,
                    'message' => 'Articles Wikipedia ajoutés avec succès',
                    'output' => $process->getOutput()
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'message' => 'Erreur lors de l\'ajout des articles',
                    'error' => $process->getErrorOutput()
                ], 500);
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/action/deploy', name: 'admin_deploy', methods: ['POST'])]
    public function deploy(): JsonResponse
    {
        try {
            // Exécuter le script de déploiement
            $deployScript = $this->getParameter('kernel.project_dir') . '/deploy.php';

            if (!file_exists($deployScript)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Script de déploiement introuvable'
                ], 404);
            }

            $process = new Process(['php', $deployScript, 'auto=1']);
            $process->setWorkingDirectory($this->getParameter('kernel.project_dir'));
            $process->setTimeout(600); // 10 minutes
            $process->run();

            return $this->json([
                'success' => $process->isSuccessful(),
                'message' => $process->isSuccessful() ? 'Déploiement réussi' : 'Erreur de déploiement',
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput()
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/action/cache-clear', name: 'admin_cache_clear', methods: ['POST'])]
    public function cacheClear(): JsonResponse
    {
        try {
            $process = new Process(['php', 'bin/console', 'cache:clear']);
            $process->setWorkingDirectory($this->getParameter('kernel.project_dir'));
            $process->setTimeout(120);
            $process->run();

            return $this->json([
                'success' => $process->isSuccessful(),
                'message' => $process->isSuccessful() ? 'Cache vidé avec succès' : 'Erreur lors du vidage du cache',
                'output' => $process->getOutput()
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/action/database-update', name: 'admin_database_update', methods: ['POST'])]
    public function databaseUpdate(): JsonResponse
    {
        try {
            $process = new Process(['php', 'bin/console', 'doctrine:schema:update', '--force']);
            $process->setWorkingDirectory($this->getParameter('kernel.project_dir'));
            $process->setTimeout(120);
            $process->run();

            return $this->json([
                'success' => $process->isSuccessful(),
                'message' => $process->isSuccessful() ? 'Base de données mise à jour' : 'Erreur lors de la mise à jour',
                'output' => $process->getOutput()
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/room/{id}/delete', name: 'admin_room_delete', methods: ['POST'])]
    public function deleteRoom(Room $room): JsonResponse
    {
        try {
            // Supprimer d'abord toutes les sessions liées
            $this->entityManager->createQuery(
                'DELETE FROM App\Entity\GameSession gs WHERE gs.room = :room'
            )->setParameter('room', $room)->execute();

            // Puis supprimer la salle
            $this->entityManager->remove($room);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => "Salle '{$room->getCode()}' supprimée avec succès"
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/article/{id}/toggle', name: 'admin_article_toggle', methods: ['POST'])]
    public function toggleArticle(WikipediaArticle $article): JsonResponse
    {
        try {
            $article->setIsActive(!$article->isActive());
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Article ' . ($article->isActive() ? 'activé' : 'désactivé'),
                'isActive' => $article->isActive()
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/article/{id}/delete', name: 'admin_article_delete', methods: ['POST'])]
    public function deleteArticle(WikipediaArticle $article): JsonResponse
    {
        try {
            $this->entityManager->remove($article);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => "Article '{$article->getTitle()}' supprimé avec succès"
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression : ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/system-info', name: 'admin_system_info')]
    public function systemInfo(): Response
    {
        $projectDir = $this->getParameter('kernel.project_dir');

        // Informations système
        $systemInfo = [
            'php_version' => PHP_VERSION,
            'symfony_version' => \Symfony\Component\HttpKernel\Kernel::VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];

        // Taille des dossiers
        $folderSizes = [
            'var/cache' => $this->getFolderSize($projectDir . '/var/cache'),
            'var/log' => $this->getFolderSize($projectDir . '/var/log'),
            'vendor' => $this->getFolderSize($projectDir . '/vendor'),
        ];

        return $this->render('admin/system_info.html.twig', [
            'systemInfo' => $systemInfo,
            'folderSizes' => $folderSizes,
        ]);
    }

    private function getFolderSize(string $path): string
    {
        if (!is_dir($path)) {
            return 'N/A';
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $this->formatBytes($size);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}
