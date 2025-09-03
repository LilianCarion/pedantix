<?php

namespace App\Service;

use App\Entity\GameSession;
use App\Entity\Room;
use App\Entity\WikipediaArticle;
use App\Repository\GameSessionRepository;
use App\Repository\RoomRepository;
use App\Repository\WikipediaArticleRepository;
use Doctrine\ORM\EntityManagerInterface;

class PedantixService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoomRepository $roomRepository,
        private GameSessionRepository $gameSessionRepository,
        private WikipediaArticleRepository $wikipediaArticleRepository
    ) {}

    public function createRoom(string $wikipediaUrl, string $gameMode = 'competition'): Room
    {
        $articleData = $this->fetchWikipediaArticle($wikipediaUrl);

        // Ajouter automatiquement l'article à notre base de données s'il n'existe pas
        $this->addArticleToDatabase($wikipediaUrl, $articleData['title']);

        $room = new Room();
        $room->setTitle($articleData['title']);
        $room->setContent($articleData['content']);
        $room->setUrl($wikipediaUrl);
        $room->setWordsToFind($articleData['allWords']);
        $room->setHints([]); // Pas d'indices dans le vrai Pedantix
        $room->setGameMode($gameMode);

        $this->roomRepository->save($room, true);

        return $room;
    }

    public function joinRoom(string $roomCode, string $playerName, string $ipAddress): ?GameSession
    {
        $room = $this->roomRepository->findByCode($roomCode);
        if (!$room) {
            return null;
        }

        // Chercher une session existante pour ce joueur
        $existingSession = $this->gameSessionRepository->findByRoomAndPlayer($room, $playerName, $ipAddress);

        if ($existingSession) {
            $existingSession->updateActivity();
            $this->gameSessionRepository->save($existingSession, true);
            return $existingSession;
        }

        // Créer une nouvelle session
        $gameSession = new GameSession();
        $gameSession->setRoom($room);
        $gameSession->setPlayerName($playerName);
        $gameSession->setIpAddress($ipAddress);

        $this->gameSessionRepository->save($gameSession, true);

        return $gameSession;
    }

    public function submitGuess(GameSession $gameSession, string $guess): array
    {
        $guess = trim($guess);
        $room = $gameSession->getRoom();
        $content = $room->getContent();

        // Vérifier si le mot a déjà été essayé par ce joueur
        $normalizedGuess = $this->normalizeWord($guess);
        $foundWordsNormalized = array_map([$this, 'normalizeWord'], $gameSession->getFoundWords());

        if (in_array($normalizedGuess, $foundWordsNormalized)) {
            return [
                'found' => false,
                'word' => $guess,
                'proximity' => null,
                'gameCompleted' => false,
                'isExactMatch' => false,
                'error' => 'Mot déjà trouvé',
                'duplicate' => true
            ];
        }

        $gameSession->incrementAttempts();
        $gameSession->updateActivity();

        $result = [
            'found' => false,
            'word' => $guess,
            'proximity' => null,
            'gameCompleted' => false,
            'isExactMatch' => false,
            'duplicate' => false
        ];

        // Extraire tous les mots significatifs du titre
        $titleWords = $this->extractTitleWords($room->getTitle());
        $titleWordsNormalized = array_map([$this, 'normalizeWord'], $titleWords);

        // Vérifier si le mot deviné correspond à un des mots du titre
        $isTitleWord = false;
        foreach ($titleWordsNormalized as $titleWord) {
            if ($titleWord === $normalizedGuess) {
                $isTitleWord = true;
                break;
            }
        }

        if ($isTitleWord) {
            $result['found'] = true;
            $result['isExactMatch'] = true;
            $gameSession->addFoundWord($guess);

            // En mode coopératif, ajouter le mot à la liste globale de la salle
            if ($room->isCooperativeMode()) {
                $room->addGlobalFoundWord($guess);
                $this->roomRepository->save($room, true);
            }

            // Vérifier si TOUS les mots du titre ont été trouvés
            $allTitleWordsFound = $this->checkAllTitleWordsFound($gameSession, $room, $titleWordsNormalized);

            if ($allTitleWordsFound) {
                $result['gameCompleted'] = true;
                $gameSession->setCompleted(true);

                // En mode coopération, marquer le jeu comme terminé pour tous
                if ($room->isCooperativeMode()) {
                    $room->setIsGameCompleted(true);
                    $room->setCompletedAt(new \DateTimeImmutable());

                    // En coopération, tous les joueurs actifs deviennent "gagnants"
                    $this->markAllPlayersAsWinners($room);
                    $this->roomRepository->save($room, true);
                }

                // Score final basé sur le nombre de tentatives (moins = mieux)
                $finalScore = max(1000 - ($gameSession->getAttempts() * 10), 100);
                $gameSession->setScore($finalScore);
            }

            $this->gameSessionRepository->save($gameSession, true);
            return $result;
        }

        // Vérifier si le mot existe dans l'article (mais n'est pas un mot du titre)
        if ($this->wordExistsInArticle($guess, $content)) {
            $result['found'] = true;
            $gameSession->addFoundWord($guess);

            // En mode coopératif, ajouter le mot à la liste globale de la salle
            if ($room->isCooperativeMode()) {
                $room->addGlobalFoundWord($guess);
                $this->roomRepository->save($room, true);
            }

            // Ajouter des points pour chaque mot trouvé (seulement si pas déjà trouvé)
            $currentScore = $gameSession->getScore() + 10;
            $gameSession->setScore($currentScore);
        } else {
            // Calculer la proximité sémantique avec les mots de l'article
            $result['proximity'] = $this->calculateSemanticProximity($guess, $content, $room->getTitle());
        }

        $this->gameSessionRepository->save($gameSession, true);
        return $result;
    }

    public function getProcessedContent(Room $room, array $foundWords, array $proximityData = [], bool $gameCompleted = false): string
    {
        $content = $room->getContent();

        // En mode coopératif, combiner les mots trouvés par le joueur avec ceux trouvés globalement
        if ($room->isCooperativeMode()) {
            $allFoundWords = array_unique(array_merge($foundWords, $room->getGlobalFoundWords()));
        } else {
            $allFoundWords = $foundWords;
        }

        $foundWordsNormalized = array_map([$this, 'normalizeWord'], $allFoundWords);

        // Si le jeu est terminé, révéler tous les mots normalement
        if ($gameCompleted) {
            $words = preg_split('/(\s+|[.,;:!?()"\'-])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

            $processedWords = [];
            foreach ($words as $word) {
                if (trim($word) === '' || preg_match('/^\s*$/', $word) || preg_match('/^[.,;:!?()"\'-]+$/', $word)) {
                    $processedWords[] = $word;
                } else {
                    $processedWords[] = '<span class="revealed-word-victory">' . htmlspecialchars($word) . '</span>';
                }
            }

            return implode('', $processedWords);
        }

        // Comportement normal : diviser le contenu en mots
        $words = preg_split('/(\s+|[.,;:!?()"\'-])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        $processedWords = [];
        foreach ($words as $word) {
            if (trim($word) === '' || preg_match('/^\s*$/', $word) || preg_match('/^[.,;:!?()"\'-]+$/', $word)) {
                $processedWords[] = $word;
            } else {
                $normalizedWord = $this->normalizeWord($word);
                $isRevealed = $this->isWordRevealed($word, $foundWordsNormalized);

                if ($isRevealed) {
                    $processedWords[] = '<span class="revealed-word">' . htmlspecialchars($word) . '</span>';
                } else {
                    $processedWords[] = '<span class="hidden-word" data-word="' . htmlspecialchars($normalizedWord) . '">' . str_repeat('█', mb_strlen($word)) . '</span>';
                }
            }
        }

        return implode('', $processedWords);
    }

    public function getLeaderboard(Room $room): array
    {
        return $this->gameSessionRepository->getLeaderboard($room);
    }

    public function getActivePlayers(Room $room): array
    {
        return $this->gameSessionRepository->findActiveSessionsForRoom($room);
    }

    public function getRoomByCode(string $code): ?Room
    {
        return $this->roomRepository->findByCode($code);
    }

    public function getGameSession(int $sessionId): ?GameSession
    {
        return $this->gameSessionRepository->find($sessionId);
    }

    public function getRandomArticle(?string $difficulty = null): ?WikipediaArticle
    {
        return $this->wikipediaArticleRepository->findRandomArticle($difficulty);
    }

    private function checkAllTitleWordsFound(GameSession $gameSession, Room $room, array $titleWordsNormalized): bool
    {
        // En mode coopération, vérifier les mots trouvés globalement
        if ($room->isCooperativeMode()) {
            $allFoundWords = array_unique(array_merge($gameSession->getFoundWords(), $room->getGlobalFoundWords()));
        } else {
            $allFoundWords = $gameSession->getFoundWords();
        }

        $foundWordsNormalized = array_map([$this, 'normalizeWord'], $allFoundWords);

        // Vérifier que chaque mot du titre a été trouvé
        foreach ($titleWordsNormalized as $titleWord) {
            $found = false;
            foreach ($foundWordsNormalized as $foundWord) {
                if ($foundWord === $titleWord) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    private function isWordRevealed(string $articleWord, array $foundWordsNormalized): bool
    {
        $normalizedArticleWord = $this->normalizeWord($articleWord);

        // Vérification directe
        if (in_array($normalizedArticleWord, $foundWordsNormalized)) {
            return true;
        }

        // Vérifier si le mot de l'article est une conjugaison d'un des mots trouvés
        foreach ($foundWordsNormalized as $foundWord) {
            if ($this->isVerbConjugation($foundWord, $normalizedArticleWord)) {
                return true;
            }
        }

        // Vérifier les contractions avec apostrophes
        if (strpos($articleWord, "'") !== false) {
            $parts = explode("'", $articleWord);
            foreach ($parts as $part) {
                $normalizedPart = $this->normalizeWord($part);
                if (in_array($normalizedPart, $foundWordsNormalized)) {
                    return true;
                }
                foreach ($foundWordsNormalized as $foundWord) {
                    if ($this->isVerbConjugation($foundWord, $normalizedPart)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function fetchWikipediaArticle(string $url): array
    {
        $title = $this->extractTitleFromUrl($url);

        // Utiliser l'API de résumé de Wikipedia
        $summaryApiUrl = "https://fr.wikipedia.org/api/rest_v1/page/summary/" . urlencode($title);

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: PedantixApp/1.0\r\n",
                'timeout' => 30,
                'ignore_errors' => true
            ]
        ]);

        try {
            $summaryResponse = file_get_contents($summaryApiUrl, false, $context);

            if ($summaryResponse === false) {
                $error = error_get_last();
                throw new \Exception('Impossible de récupérer l\'article Wikipedia: ' . ($error['message'] ?? 'Erreur de connexion'));
            }

            $summaryData = json_decode($summaryResponse, true);

            if (!$summaryData) {
                throw new \Exception('Réponse invalide de l\'API Wikipedia');
            }

            if (isset($summaryData['type']) && $summaryData['type'] === 'disambiguation') {
                throw new \Exception('Cette page est une page de désambiguïsation. Veuillez choisir un article plus spécifique.');
            }

            if (!isset($summaryData['extract']) || empty($summaryData['extract'])) {
                throw new \Exception('Contenu de l\'article introuvable ou vide');
            }

            $content = $summaryData['extract'];

            // Nettoyer le contenu
            $content = preg_replace('/\[[\d,\s]+\]/', '', $content);
            $content = preg_replace('/\s+/', ' ', $content);
            $content = trim($content);

            if (strlen($content) < 50) {
                throw new \Exception('L\'article est trop court pour créer une partie intéressante');
            }

            $properTitle = $summaryData['title'] ?? $title;

            return [
                'title' => $properTitle,
                'content' => $content,
                'allWords' => $this->extractAllWords($content)
            ];
        } catch (\Exception $e) {
            error_log('Erreur fetchWikipediaArticle: ' . $e->getMessage() . ' pour URL: ' . $url);
            throw $e;
        }
    }

    private function extractTitleFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $title = basename($path);
        return urldecode($title);
    }

    private function extractAllWords(string $content): array
    {
        $words = preg_split('/\s+/', $content);
        $cleanWords = [];

        foreach ($words as $word) {
            $cleaned = $this->normalizeWord($word);
            if (strlen($cleaned) >= 2 && !in_array($cleaned, $this->getStopWords())) {
                $cleanWords[] = $cleaned;
            }
        }

        return array_unique($cleanWords);
    }

    private function extractTitleWords(string $title): array
    {
        $words = preg_split('/\s+/', $title);
        $titleWords = [];

        foreach ($words as $word) {
            $cleaned = $this->normalizeWord($word);
            if (strlen($cleaned) >= 2) {
                $titleWords[] = $word;
            }
        }

        return $titleWords;
    }

    private function normalizeWord(string $word): string
    {
        $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
        $word = mb_strtolower($word, 'UTF-8');
        $word = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $word);
        $word = preg_replace('/[^a-z0-9]/', '', $word);

        return $word;
    }

    private function wordExistsInArticle(string $guess, string $content): bool
    {
        $normalizedGuess = $this->normalizeWord($guess);

        $words = preg_split('/(\s+|[.,;:!?()"\-])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($words as $word) {
            $cleanWord = trim($word);
            if (empty($cleanWord)) continue;

            if (strpos($cleanWord, "'") !== false) {
                $parts = explode("'", $cleanWord);
                foreach ($parts as $part) {
                    if ($this->normalizeWord($part) === $normalizedGuess) {
                        return true;
                    }
                    if ($this->isVerbConjugation($normalizedGuess, $this->normalizeWord($part))) {
                        return true;
                    }
                }
            }

            $normalizedWord = $this->normalizeWord($cleanWord);
            if ($normalizedWord === $normalizedGuess) {
                return true;
            }

            if ($this->isVerbConjugation($normalizedGuess, $normalizedWord)) {
                return true;
            }
        }

        return false;
    }

    private function isVerbConjugation(string $infinitive, string $word): bool
    {
        $conjugationPatterns = $this->getConjugationPatterns();

        foreach ($conjugationPatterns as $ending => $replacements) {
            if (str_ends_with($infinitive, $ending)) {
                $stem = substr($infinitive, 0, -strlen($ending));

                foreach ($replacements as $replacement) {
                    $conjugated = $stem . $replacement;
                    if ($conjugated === $word) {
                        return true;
                    }
                }
            }
        }

        $irregularVerbs = $this->getIrregularVerbs();
        if (isset($irregularVerbs[$infinitive])) {
            return in_array($word, $irregularVerbs[$infinitive]);
        }

        return false;
    }

    private function getConjugationPatterns(): array
    {
        return [
            'er' => [
                'e', 'es', 'e', 'ons', 'ez', 'ent',
                'ais', 'ais', 'ait', 'ions', 'iez', 'aient',
                'ai', 'as', 'a', 'ames', 'ates', 'erent',
                'erai', 'eras', 'era', 'erons', 'erez', 'eront',
                'erais', 'erais', 'erait', 'erions', 'eriez', 'eraient',
                'ant', 'e',
            ],
            'ir' => [
                'is', 'is', 'it', 'issons', 'issez', 'issent',
                'issais', 'issais', 'issait', 'issions', 'issiez', 'issaient',
                'is', 'is', 'it', 'imes', 'ites', 'irent',
                'irai', 'iras', 'ira', 'irons', 'irez', 'iront',
                'irais', 'irais', 'irait', 'irions', 'iriez', 'iraient',
                'issant', 'i',
            ],
            're' => [
                's', 's', '', 'ons', 'ez', 'ent',
                'ais', 'ais', 'ait', 'ions', 'iez', 'aient',
                'is', 'is', 'it', 'imes', 'ites', 'irent',
                'rai', 'ras', 'ra', 'rons', 'rez', 'ront',
                'rais', 'rais', 'rait', 'rions', 'riez', 'raient',
                'ant', 'u',
            ]
        ];
    }

    private function getIrregularVerbs(): array
    {
        return [
            'etre' => ['suis', 'es', 'est', 'sommes', 'etes', 'sont', 'etais', 'etait', 'etions', 'etiez', 'etaient', 'fus', 'fut', 'fumes', 'furent', 'serai', 'seras', 'sera', 'serons', 'serez', 'seront', 'serais', 'serait', 'serions', 'seriez', 'seraient', 'etant', 'ete'],
            'avoir' => ['ai', 'as', 'a', 'avons', 'avez', 'ont', 'avais', 'avait', 'avions', 'aviez', 'avaient', 'eus', 'eut', 'eumes', 'eurent', 'aurai', 'auras', 'aura', 'aurons', 'aurez', 'auront', 'aurais', 'aurait', 'aurions', 'auriez', 'auraient', 'ayant', 'eu'],
            'aller' => ['vais', 'vas', 'va', 'allons', 'allez', 'vont', 'allais', 'allait', 'allions', 'alliez', 'allaient', 'allai', 'alla', 'allames', 'allerent', 'irai', 'iras', 'ira', 'irons', 'irez', 'iront', 'irais', 'irait', 'irions', 'iriez', 'iraient', 'allant', 'alle'],
            'faire' => ['fais', 'fait', 'faisons', 'faites', 'font', 'faisais', 'faisait', 'faisions', 'faisiez', 'faisaient', 'fis', 'fit', 'fimes', 'firent', 'ferai', 'feras', 'fera', 'ferons', 'ferez', 'feront', 'ferais', 'ferait', 'ferions', 'feriez', 'feraient', 'faisant', 'fait'],
        ];
    }

    private function getStopWords(): array
    {
        return [
            'le', 'de', 'et', 'à', 'un', 'il', 'être', 'et', 'en', 'avoir', 'que', 'pour',
            'dans', 'ce', 'son', 'une', 'sur', 'avec', 'ne', 'se', 'pas', 'tout', 'plus',
            'par', 'grand', 'en', 'une', 'être', 'et', 'à', 'il', 'avoir', 'ne', 'je', 'son',
            'que', 'se', 'qui', 'ce', 'dans', 'en', 'du', 'elle', 'au', 'de', 'le', 'un'
        ];
    }

    private function calculateSemanticProximity(string $guess, string $content, string $title): int
    {
        // Implémentation simplifiée de proximité sémantique
        $normalizedGuess = $this->normalizeWord($guess);
        $contentWords = $this->extractAllWords($content);
        $titleWords = $this->extractAllWords($title);

        // Vérifier la similarité avec les mots du titre
        foreach ($titleWords as $titleWord) {
            $similarity = similar_text($normalizedGuess, $titleWord);
            if ($similarity > 5) {
                return min(900, $similarity * 100);
            }
        }

        // Vérifier la similarité avec les mots du contenu
        $maxSimilarity = 0;
        foreach ($contentWords as $contentWord) {
            $similarity = similar_text($normalizedGuess, $contentWord);
            $maxSimilarity = max($maxSimilarity, $similarity);
        }

        return min(600, $maxSimilarity * 50);
    }

    private function addArticleToDatabase(string $url, string $title): void
    {
        try {
            $existingArticle = $this->wikipediaArticleRepository->findOneBy(['url' => $url]);

            if (!$existingArticle) {
                $article = new WikipediaArticle();
                $article->setTitle($title);
                $article->setUrl($url);
                $article->setCategory($this->determineCategory($title, $url));
                $article->setDifficulty($this->determineDifficulty($title, $url));
                $article->setActive(true);

                $this->entityManager->persist($article);
                $this->entityManager->flush();

                error_log("Nouvel article ajouté automatiquement: {$title}");
            }
        } catch (\Exception $e) {
            error_log("Erreur lors de l'ajout automatique de l'article: " . $e->getMessage());
        }
    }

    private function determineCategory(string $title, string $url): string
    {
        $title = strtolower($title);
        $url = strtolower($url);

        if (preg_match('/\b(chat|chien|lion|tigre|éléphant|oiseau|poisson|animal|mammifère)\b/', $title . ' ' . $url)) {
            return 'Animaux';
        }

        if (preg_match('/\b(paris|france|ville|pays|océan|mer|montagne|rivière)\b/', $title . ' ' . $url)) {
            return 'Géographie';
        }

        if (preg_match('/\b(physique|chimie|biologie|mathématiques|science)\b/', $title . ' ' . $url)) {
            return 'Science';
        }

        if (preg_match('/\b(guerre|révolution|empire|roi|reine|histoire)\b/', $title . ' ' . $url)) {
            return 'Histoire';
        }

        if (preg_match('/\b(art|peinture|musique|littérature|culture)\b/', $title . ' ' . $url)) {
            return 'Culture';
        }

        return 'Divers';
    }

    private function determineDifficulty(string $title, string $url): string
    {
        $title = strtolower($title);
        $url = strtolower($url);

        $easyKeywords = ['chat', 'chien', 'eau', 'soleil', 'paris', 'france'];
        $hardKeywords = ['quantique', 'relativité', 'thermodynamique', 'algorithme'];

        foreach ($hardKeywords as $keyword) {
            if (strpos($title . ' ' . $url, $keyword) !== false) {
                return 'difficile';
            }
        }

        foreach ($easyKeywords as $keyword) {
            if (strpos($title . ' ' . $url, $keyword) !== false) {
                return 'facile';
            }
        }

        return 'moyen';
    }

    private function markAllPlayersAsWinners(Room $room): void
    {
        $activePlayers = $this->getActivePlayers($room);

        foreach ($activePlayers as $session) {
            if (!$session->isCompleted()) {
                $session->setCompleted(true);
                $session->setCompletedAt(new \DateTimeImmutable());

                $participationScore = max(500 - ($session->getAttempts() * 5), 100);
                $session->setScore($session->getScore() + $participationScore);

                $this->gameSessionRepository->save($session, false);
            }
        }

        $this->entityManager->flush();
    }
}
