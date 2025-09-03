<?php

namespace App\Controller;

use App\Service\PedantixService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class GameController extends AbstractController
{
    public function __construct(
        private PedantixService $pedantixService
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('game/index.html.twig');
    }

    #[Route('/create-room', name: 'app_create_room', methods: ['POST'])]
    public function createRoom(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $wikipediaUrl = $data['wikipedia_url'] ?? '';
        $gameMode = $data['game_mode'] ?? 'competition'; // Par défaut: compétition

        if (empty($wikipediaUrl)) {
            return $this->json(['success' => false, 'error' => 'URL Wikipedia requise'], 400);
        }

        // Valider le mode de jeu
        if (!in_array($gameMode, ['competition', 'cooperation'])) {
            return $this->json(['success' => false, 'error' => 'Mode de jeu invalide'], 400);
        }

        // Valider le format de l'URL Wikipedia
        if (!preg_match('/^https?:\/\/(fr\.)?wikipedia\.org\/wiki\/.+/', $wikipediaUrl)) {
            return $this->json(['success' => false, 'error' => 'Veuillez entrer une URL Wikipedia française valide (ex: https://fr.wikipedia.org/wiki/Eau)'], 400);
        }

        try {
            $room = $this->pedantixService->createRoom($wikipediaUrl, $gameMode);

            return $this->json([
                'success' => true,
                'room_code' => $room->getCode(),
                'title' => $room->getTitle(),
                'game_mode' => $room->getGameMode()
            ]);
        } catch (\Exception $e) {
            // Log l'erreur pour debugging
            error_log('Erreur création de salle: ' . $e->getMessage() . ' pour URL: ' . $wikipediaUrl);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/join-room', name: 'app_join_room', methods: ['POST'])]
    public function joinRoom(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $roomCode = strtoupper($data['room_code'] ?? '');
        $playerName = $data['player_name'] ?? '';

        if (empty($roomCode) || empty($playerName)) {
            return $this->json(['error' => 'Code de salle et nom de joueur requis'], 400);
        }

        try {
            $ipAddress = $request->getClientIp();
            $gameSession = $this->pedantixService->joinRoom($roomCode, $playerName, $ipAddress);

            if (!$gameSession) {
                return $this->json(['error' => 'Salle introuvable'], 404);
            }

            $room = $gameSession->getRoom();

            return $this->json([
                'success' => true,
                'session_id' => $gameSession->getId(),
                'room' => [
                    'code' => $room->getCode(),
                    'title' => 'Article mystère', // Ne pas révéler le vrai titre
                    'hints' => $room->getHints()
                ],
                'player' => [
                    'name' => $gameSession->getPlayerName(),
                    'found_words' => $gameSession->getFoundWords(),
                    'attempts' => $gameSession->getAttempts(),
                    'score' => $gameSession->getScore(),
                    'completed' => $gameSession->isCompleted()
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/game/{roomCode}', name: 'app_game_room')]
    public function gameRoom(string $roomCode): Response
    {
        return $this->render('game/room.html.twig', [
            'room_code' => strtoupper($roomCode)
        ]);
    }

    #[Route('/api/guess', name: 'app_submit_guess', methods: ['POST'])]
    public function submitGuess(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $sessionId = $data['session_id'] ?? null;
        $guess = $data['guess'] ?? '';

        if (!$sessionId || empty($guess)) {
            return $this->json(['success' => false, 'error' => 'Session et mot requis'], 400);
        }

        try {
            $gameSession = $this->pedantixService->getGameSession($sessionId);
            if (!$gameSession) {
                return $this->json(['success' => false, 'error' => 'Session invalide'], 400);
            }

            $result = $this->pedantixService->submitGuess($gameSession, $guess);

            // Si c'est un doublon, retourner une erreur spécifique
            if (isset($result['duplicate']) && $result['duplicate']) {
                return $this->json([
                    'success' => false,
                    'error' => 'Mot déjà essayé',
                    'duplicate' => true,
                    'word' => $result['word']
                ], 400);
            }

            return $this->json([
                'success' => true,
                'result' => $result,
                'player' => [
                    'found_words' => $gameSession->getFoundWords(),
                    'attempts' => $gameSession->getAttempts(),
                    'score' => $gameSession->getScore(),
                    'completed' => $gameSession->isCompleted()
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/article-content/{roomCode}', name: 'app_article_content')]
    public function getArticleContent(string $roomCode, Request $request): JsonResponse
    {
        try {
            $room = $this->pedantixService->getRoomByCode($roomCode);
            if (!$room) {
                return $this->json(['error' => 'Salle introuvable'], 404);
            }

            $sessionId = $request->query->get('session_id');
            $foundWords = [];
            $gameCompleted = false;
            $proximityData = [];
            $gameSession = null;
            $titleProgress = null;

            if ($sessionId) {
                $gameSession = $this->pedantixService->getGameSession($sessionId);
                if ($gameSession) {
                    $foundWords = $gameSession->getFoundWords();
                    $gameCompleted = $gameSession->isCompleted();

                    // Récupérer les données de proximité des dernières tentatives
                    $proximityDataRaw = $request->query->get('proximity_data', '[]');
                    if (is_string($proximityDataRaw)) {
                        $decoded = json_decode($proximityDataRaw, true);
                        if (is_array($decoded)) {
                            $proximityData = $decoded;
                        }
                    }

                    // Récupérer les informations de progression du titre
                    $titleProgress = $this->pedantixService->getTitleProgress($gameSession, $room);
                }
            }

            $processedContent = $this->pedantixService->getProcessedContent($room, $foundWords, $proximityData, $gameCompleted);

            return $this->json([
                'title' => $room->getTitle(),
                'content' => $processedContent,
                'total_words' => count($room->getWordsToFind()),
                'game_completed' => $gameCompleted,
                'title_progress' => $titleProgress
            ]);
        } catch (\Exception $e) {
            // Log l'erreur pour debugging
            error_log('Erreur dans getArticleContent: ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString());

            return $this->json([
                'error' => 'Erreur interne du serveur: ' . $e->getMessage(),
                'debug_info' => [
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile())
                ]
            ], 500);
        }
    }

    #[Route('/api/leaderboard/{roomCode}', name: 'app_leaderboard')]
    public function leaderboard(string $roomCode): JsonResponse
    {
        try {
            $room = $this->pedantixService->getRoomByCode($roomCode);
            if (!$room) {
                return $this->json(['error' => 'Salle introuvable'], 404);
            }

            $leaderboard = $this->pedantixService->getLeaderboard($room);
            $activePlayers = $this->pedantixService->getActivePlayers($room);

            $leaderboardData = array_map(function($session) {
                return [
                    'player_name' => $session->getPlayerName(),
                    'score' => $session->getScore(),
                    'completed_at' => $session->getCompletedAt()?->format('Y-m-d H:i:s'),
                    'attempts' => $session->getAttempts()
                ];
            }, $leaderboard);

            $activePlayersData = array_map(function($session) {
                return [
                    'player_name' => $session->getPlayerName(),
                    'score' => $session->getScore(),
                    'found_words_count' => count($session->getFoundWords()),
                    'last_activity' => $session->getLastActivity()->format('Y-m-d H:i:s')
                ];
            }, $activePlayers);

            return $this->json([
                'leaderboard' => $leaderboardData,
                'active_players' => $activePlayersData
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/random-article', name: 'app_random_article', methods: ['GET'])]
    public function getRandomArticle(Request $request): JsonResponse
    {
        try {
            // Debug : vérifier si le service est bien injecté
            if (!$this->pedantixService) {
                return $this->json(['error' => 'Service PedantixService non disponible'], 500);
            }

            $difficulty = $request->query->get('difficulty');

            // Debug : tester directement le repository
            $randomArticle = $this->pedantixService->getRandomArticle($difficulty);

            if (!$randomArticle) {
                return $this->json([
                    'success' => false,
                    'error' => 'Aucun article disponible',
                    'debug' => 'Repository returned null'
                ], 404);
            }

            return $this->json([
                'success' => true,
                'article' => [
                    'title' => $randomArticle->getTitle(),
                    'url' => $randomArticle->getUrl(),
                    'difficulty' => $randomArticle->getDifficulty(),
                    'category' => $randomArticle->getCategory()
                ]
            ]);
        } catch (\Exception $e) {
            // Log détaillé de l'erreur
            error_log('Erreur dans getRandomArticle: ' . $e->getMessage() . ' - File: ' . $e->getFile() . ' - Line: ' . $e->getLine());
            error_log('Stack trace: ' . $e->getTraceAsString());

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
                'debug' => [
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            ], 500);
        }
    }

    #[Route('/game/{roomCode}/recap', name: 'app_game_recap')]
    public function gameRecap(string $roomCode): Response
    {
        // Rediriger vers l'accueil au lieu d'afficher les stats
        return $this->redirectToRoute('app_home');
    }

    #[Route('/api/game-events/{roomCode}', name: 'app_game_events')]
    public function getGameEvents(string $roomCode, Request $request): JsonResponse
    {
        try {
            $room = $this->pedantixService->getRoomByCode($roomCode);
            if (!$room) {
                return $this->json(['error' => 'Salle introuvable'], 404);
            }

            $sessionId = $request->query->get('session_id');
            $lastEventId = $request->query->get('last_event_id', 0);

            // Récupérer les événements récents (nouveaux joueurs qui trouvent le mot, etc.)
            $events = $this->pedantixService->getGameEvents($room, (int)$lastEventId);

            // Vérifier l'état global du jeu
            $gameStatus = $this->pedantixService->checkGameStatus($room);

            return $this->json([
                'events' => $events,
                'game_status' => $gameStatus,
                'last_event_id' => $events ? max(array_column($events, 'id')) : $lastEventId
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/complete-game/{roomCode}', name: 'app_complete_game', methods: ['POST'])]
    public function completeGame(string $roomCode, Request $request): JsonResponse
    {
        try {
            $room = $this->pedantixService->getRoomByCode($roomCode);
            if (!$room) {
                return $this->json(['error' => 'Salle introuvable'], 404);
            }

            $data = json_decode($request->getContent(), true);
            $sessionId = $data['session_id'] ?? null;

            if (!$sessionId) {
                return $this->json(['error' => 'Session requise'], 400);
            }

            // Marquer le jeu comme terminé et générer les statistiques finales
            $result = $this->pedantixService->completeGame($room, $sessionId);

            return $this->json([
                'success' => true,
                'game_completed' => true,
                'winner' => $result['winner'] ?? null,
                'final_leaderboard' => $result['leaderboard'] ?? [],
                'redirect_to_recap' => true
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/new-game', name: 'app_new_game', methods: ['POST'])]
    public function newGame(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $currentRoomCode = $data['current_room_code'] ?? '';
            $sessionId = $data['session_id'] ?? null;

            if (!$sessionId) {
                return $this->json(['error' => 'Session requise'], 400);
            }

            // Récupérer la session actuelle
            $currentSession = $this->pedantixService->getGameSession($sessionId);
            if (!$currentSession) {
                return $this->json(['error' => 'Session invalide'], 400);
            }

            $room = $currentSession->getRoom();

            // Vérifier si une nouvelle partie peut être démarrée
            if (!$room->canStartNewGame()) {
                return $this->json([
                    'error' => 'Une nouvelle partie est déjà en cours de création. Veuillez patienter.',
                    'locked' => true
                ], 423);
            }

            // Acquérir le verrou pour cette session
            $room->lockForNewGame($sessionId);
            $this->pedantixService->saveRoom($room);

            // Obtenir un article aléatoire pour la nouvelle partie
            $randomArticle = $this->pedantixService->getRandomArticle();
            if (!$randomArticle) {
                $room->unlockNewGame();
                $this->pedantixService->saveRoom($room);
                return $this->json(['error' => 'Aucun article disponible'], 404);
            }

            // Créer la nouvelle partie dans la même salle
            $result = $this->pedantixService->startNewGameInSameRoom($room, $randomArticle->getUrl());

            return $this->json([
                'success' => true,
                'new_game_number' => $room->getGameNumber(),
                'new_article_title' => $result['title'],
                'message' => 'Nouvelle partie démarrée !',
                'reload_page' => true
            ]);

        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/room-status/{roomCode}', name: 'app_room_status')]
    public function getRoomStatus(string $roomCode, Request $request): JsonResponse
    {
        try {
            $room = $this->pedantixService->getRoomByCode($roomCode);
            if (!$room) {
                return $this->json(['error' => 'Salle introuvable'], 404);
            }

            $sessionId = $request->query->get('session_id');
            $gameSession = null;

            if ($sessionId) {
                $gameSession = $this->pedantixService->getGameSession($sessionId);
            }

            $status = $this->pedantixService->getRoomStatus($room, $gameSession);

            return $this->json($status);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
