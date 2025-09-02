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

        if (empty($wikipediaUrl)) {
            return $this->json(['error' => 'URL Wikipedia requise'], 400);
        }

        try {
            $room = $this->pedantixService->createRoom($wikipediaUrl);

            return $this->json([
                'success' => true,
                'room_code' => $room->getCode(),
                'title' => $room->getTitle()
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
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
                    'title' => $room->getTitle(),
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
            return $this->json(['error' => 'Session et mot requis'], 400);
        }

        try {
            $gameSession = $this->pedantixService->getGameSession($sessionId);
            if (!$gameSession) {
                return $this->json(['error' => 'Session invalide'], 400);
            }

            $result = $this->pedantixService->submitGuess($gameSession, $guess);

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
            return $this->json(['error' => $e->getMessage()], 500);
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
                }
            }

            $processedContent = $this->pedantixService->getProcessedContent($room, $foundWords, $proximityData, $gameCompleted);

            return $this->json([
                'title' => $room->getTitle(),
                'content' => $processedContent,
                'total_words' => count($room->getWordsToFind()),
                'game_completed' => $gameCompleted
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
}
