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

    /**
     * Vérifie si tous les mots du titre ont été trouvés par le joueur
     */
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
                return false; // Il manque encore au moins un mot
            }
        }

        return true; // Tous les mots du titre ont été trouvés
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

        // Si le jeu est terminé, révéler tous les mots normalement (pas en jaune)
        if ($gameCompleted) {
            // Diviser le contenu en mots tout en préservant la ponctuation et la structure
            $words = preg_split('/(\s+|[.,;:!?()"\'-])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

            $processedWords = [];
            foreach ($words as $word) {
                if (trim($word) === '' || preg_match('/^\s*$/', $word) || preg_match('/^[.,;:!?()"\'-]+$/', $word)) {
                    // Espaces et ponctuation - garder tel quel
                    $processedWords[] = $word;
                } else {
                    // Tous les mots sont révélés avec un style normal (plus de jaune)
                    $processedWords[] = '<span class="revealed-word-victory">' . htmlspecialchars($word) . '</span>';
                }
            }

            return implode('', $processedWords);
        }

        // Créer un mapping des mots de l'article vers les mots devinés les plus proches
        $wordProximityMapping = $this->buildWordProximityMapping($content, $proximityData);

        // Créer un mapping des proximités sémantiques pour les mots trouvés
        $semanticProximityMapping = $this->buildSemanticProximityMapping($content, $allFoundWords);

        // Comportement normal : diviser le contenu en mots tout en préservant la ponctuation et la structure
        $words = preg_split('/(\s+|[.,;:!?()"\'-])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        $processedWords = [];
        foreach ($words as $word) {
            if (trim($word) === '' || preg_match('/^\s*$/', $word) || preg_match('/^[.,;:!?()"\'-]+$/', $word)) {
                // Espaces et ponctuation - garder tel quel
                $processedWords[] = $word;
            } else {
                // C'est un mot - vérifier s'il doit être dévoilé
                $normalizedWord = $this->normalizeWord($word);
                $isRevealed = $this->isWordRevealed($word, $foundWordsNormalized);

                if ($isRevealed) {
                    // Mot trouvé : affichage en texte noir normal sans arrière-plan
                    $processedWords[] = '<span class="revealed-word">' . htmlspecialchars($word) . '</span>';
                } else {
                    // Vérifier si ce mot a une proximité sémantique avec un mot trouvé
                    if (isset($semanticProximityMapping[$normalizedWord])) {
                        $semanticInfo = $semanticProximityMapping[$normalizedWord];
                        $foundWord = $semanticInfo['found_word'];
                        $proximityScore = $semanticInfo['proximity'];
                        $colorStyle = $this->getProximityColorStyle($proximityScore);

                        // Afficher le mot trouvé avec la couleur de proximité sémantique
                        $processedWords[] = '<span class="hidden-word-with-proximity" style="' . $colorStyle . '" data-word="' . htmlspecialchars($normalizedWord) . '" data-found="' . htmlspecialchars($foundWord) . '" data-proximity="' . $proximityScore . '">' . htmlspecialchars($foundWord) . '</span>';
                    } else if (isset($wordProximityMapping[$normalizedWord])) {
                        // Vérifier la proximité avec les mots devinés mais non trouvés
                        $proximityInfo = $wordProximityMapping[$normalizedWord];
                        $guessedWord = $proximityInfo['guessed_word'];
                        $proximityScore = $proximityInfo['proximity'];
                        $colorStyle = $this->getProximityColorStyle($proximityScore);

                        // Afficher le mot deviné avec la couleur de proximité
                        $processedWords[] = '<span class="hidden-word-with-proximity" style="' . $colorStyle . '" data-word="' . htmlspecialchars($normalizedWord) . '" data-guessed="' . htmlspecialchars($guessedWord) . '" data-proximity="' . $proximityScore . '">' . htmlspecialchars($guessedWord) . '</span>';
                    } else {
                        // Mot complètement caché
                        $processedWords[] = '<span class="hidden-word" data-word="' . htmlspecialchars($normalizedWord) . '">' . str_repeat('█', mb_strlen($word)) . '</span>';
                    }
                }
            }
        }

        return implode('', $processedWords);
    }

    /**
     * Vérifie si un mot de l'article doit être révélé basé sur les mots trouvés par le joueur
     * Prend en compte les conjugaisons et variations
     */
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
                // Vérifier les conjugaisons pour chaque partie
                foreach ($foundWordsNormalized as $foundWord) {
                    if ($this->isVerbConjugation($foundWord, $normalizedPart)) {
                        return true;
                    }
                }
            }
        }

        return false;
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

    private function fetchWikipediaArticle(string $url): array
    {
        $title = $this->extractTitleFromUrl($url);

        // Utiliser l'API de résumé de Wikipedia qui donne directement l'introduction
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
                throw new \Exception('Impossible de r��cupérer l\'article Wikipedia: ' . ($error['message'] ?? 'Erreur de connexion'));
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

            // L'extract contient déjà un résumé propre de l'article
            $content = $summaryData['extract'];

            // Nettoyer un peu plus le contenu pour enlever les références restantes
            $content = preg_replace('/\[[\d,\s]+\]/', '', $content); // Supprimer les références [1], [2,3], etc.
            $content = preg_replace('/\s+/', ' ', $content); // Normaliser les espaces
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
            // Log l'erreur pour debugging
            error_log('Erreur fetchWikipediaArticle: ' . $e->getMessage() . ' pour URL: ' . $url);
            throw $e;
        }
    }

    private function cleanWikipediaContent(string $html): string
    {
        // Supprimer les balises non désirées
        $html = preg_replace('/<script.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style.*?<\/style>/is', '', $html);
        $html = preg_replace('/<figure.*?<\/figure>/is', '', $html);
        $html = preg_replace('/<table.*?<\/table>/is', '', $html);
        $html = preg_replace('/<div[^>]*class="[^"]*infobox[^"]*".*?<\/div>/is', '', $html);

        // Supprimer TOUTES les boîtes d'aide, navigation et métadonnées
        $html = preg_replace('/<div[^>]*class="[^"]*dablink[^"]*".*?<\/div>/is', '', $html);
        $html = preg_replace('/<div[^>]*class="[^"]*hatnote[^"]*".*?<\/div>/is', '', $html);
        $html = preg_replace('/<div[^>]*class="[^"]*disambig[^"]*".*?<\/div>/is', '', $html);
        $html = preg_replace('/<div[^>]*class="[^"]*navigation[^"]*".*?<\/div>/is', '', $html);
        $html = preg_replace('/<div[^>]*class="[^"]*navbox[^"]*".*?<\/div>/is', '', $html);
        $html = preg_replace('/<div[^>]*class="[^"]*metadata[^"]*".*?<\/div>/is', '', $html);

        // Approche plus simple et plus stricte : extraire seulement les 2-3 premiers vrais paragraphes
        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $matches);
        $allParagraphs = $matches[1];

        $introContent = [];
        $paragraphCount = 0;

        foreach ($allParagraphs as $paragraph) {
            $cleaned = strip_tags($paragraph);
            $cleaned = html_entity_decode($cleaned, ENT_QUOTES, 'UTF-8');
            $cleaned = preg_replace('/\s+/', ' ', $cleaned);
            $cleaned = trim($cleaned);

            // Filtres très stricts pour ne garder que l'introduction
            if (!empty($cleaned) &&
                strlen($cleaned) > 30 && // Paragraphes substantiels seulement
                !preg_match('/^(Pour les articles|Page d\'aide|Ne doit pas être confondu|Cet article|voir|redirigé|coordination|modifier|wikidata)/i', $cleaned) &&
                !preg_match('/(homonymie|homonymes|voir aussi|articles connexes|catégorie|portail)/i', $cleaned) &&
                !preg_match('/^\s*(modifier|edit|\[|\()/i', $cleaned)) {

                $introContent[] = $cleaned;
                $paragraphCount++;

                // LIMITER STRICTEMENT à 2-3 paragraphes d'introduction maximum
                if ($paragraphCount >= 2) {
                    break;
                }
            }
        }

        // S'assurer qu'on a au moins quelque chose de substantiel
        if (empty($introContent)) {
            return "L'eau est un composé chimique ubiquitaire sur la Terre, essentiel pour tous les organismes vivants connus.";
        }

        return implode("\n\n", $introContent);
    }

    private function getElementPosition(\DOMNode $element): int
    {
        $position = 0;
        $current = $element;

        while ($current->previousSibling !== null) {
            $current = $current->previousSibling;
            $position++;
        }

        // Ajouter la position des parents
        if ($current->parentNode !== null && $current->parentNode->nodeName !== '#document') {
            $position += $this->getElementPosition($current->parentNode) * 1000;
        }

        return $position;
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

    private function extractAllWordsFromContent(string $content): array
    {
        // Extraire tous les mots du contenu en préservant la structure
        $words = preg_split('/(\s+|[.,;:!?()"\-])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $cleanWords = [];

        foreach ($words as $word) {
            $cleanWord = trim($word);
            if (empty($cleanWord) || preg_match('/^[.,;:!?()"\'\\-\\s]+$/', $cleanWord)) {
                continue;
            }

            // Traitement spécial pour les mots avec apostrophes
            if (strpos($cleanWord, "'") !== false) {
                $parts = explode("'", $cleanWord);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (!empty($part) && strlen($part) >= 2) {
                        $cleanWords[] = $part;
                    }
                }
            } else {
                if (strlen($cleanWord) >= 2) {
                    $cleanWords[] = $cleanWord;
                }
            }
        }

        return $cleanWords;
    }

    private function extractTitleWords(string $title): array
    {
        // Extraire tous les mots significatifs du titre
        $words = preg_split('/\s+/', $title);
        $titleWords = [];

        foreach ($words as $word) {
            $cleaned = $this->normalizeWord($word);
            if (strlen($cleaned) >= 2) {
                $titleWords[] = $word; // Garder le mot original, pas normalisé
            }
        }

        return $titleWords;
    }

    private function normalizeWord(string $word): string
    {
        // Enlever la ponctuation et normaliser
        $word = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
        $word = mb_strtolower($word, 'UTF-8');

        // Enlever les accents pour la comparaison
        $word = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $word);
        $word = preg_replace('/[^a-z0-9]/', '', $word);

        return $word;
    }

    private function wordExistsInArticle(string $guess, string $content): bool
    {
        $normalizedGuess = $this->normalizeWord($guess);

        // Cas spécial pour les contractions comme "l'"
        if (strlen($guess) == 1 && in_array(strtolower($guess), ['l', 'd', 'j', 'n', 'm', 'c', 's', 't'])) {
            // Rechercher des patterns comme "l'eau", "d'eau", etc.
            $pattern = '/\b' . preg_quote(strtolower($guess)) . '\'/i';
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        // Diviser le contenu en mots en préservant les apostrophes
        $words = preg_split('/(\s+|[.,;:!?()"\-])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($words as $word) {
            $cleanWord = trim($word);
            if (empty($cleanWord)) continue;

            // Traitement spécial pour les mots avec apostrophes
            if (strpos($cleanWord, "'") !== false) {
                $parts = explode("'", $cleanWord);
                foreach ($parts as $part) {
                    if ($this->normalizeWord($part) === $normalizedGuess) {
                        return true;
                    }
                    // Vérifier les conjugaisons pour les parties de mots avec apostrophe
                    if ($this->isVerbConjugation($normalizedGuess, $this->normalizeWord($part))) {
                        return true;
                    }
                }
            }

            $normalizedWord = $this->normalizeWord($cleanWord);
            if ($normalizedWord === $normalizedGuess) {
                return true;
            }

            // Vérifier si le mot deviné est un infinitif et le mot de l'article une conjugaison
            if ($this->isVerbConjugation($normalizedGuess, $normalizedWord)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie si un mot est une conjugaison d'un verbe à l'infinitif
     */
    private function isVerbConjugation(string $infinitive, string $word): bool
    {
        // Patterns de conjugaison française simplifiés
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

        // Vérifier aussi les verbes irréguliers les plus courants
        $irregularVerbs = $this->getIrregularVerbs();
        if (isset($irregularVerbs[$infinitive])) {
            return in_array($word, $irregularVerbs[$infinitive]);
        }

        return false;
    }

    /**
     * Patterns de conjugaison pour les verbes réguliers
     */
    private function getConjugationPatterns(): array
    {
        return [
            'er' => [
                'e', 'es', 'e', 'ons', 'ez', 'ent', // présent
                'ais', 'ais', 'ait', 'ions', 'iez', 'aient', // imparfait
                'ai', 'as', 'a', 'ames', 'ates', 'erent', // passé simple
                'erai', 'eras', 'era', 'erons', 'erez', 'eront', // futur
                'erais', 'erais', 'erait', 'erions', 'eriez', 'eraient', // conditionnel
                'ant', 'e', // participes
            ],
            'ir' => [
                'is', 'is', 'it', 'issons', 'issez', 'issent', // présent
                'issais', 'issais', 'issait', 'issions', 'issiez', 'issaient', // imparfait
                'is', 'is', 'it', 'imes', 'ites', 'irent', // passé simple
                'irai', 'iras', 'ira', 'irons', 'irez', 'iront', // futur
                'irais', 'irais', 'irait', 'irions', 'iriez', 'iraient', // conditionnel
                'issant', 'i', // participes
            ],
            're' => [
                's', 's', '', 'ons', 'ez', 'ent', // présent
                'ais', 'ais', 'ait', 'ions', 'iez', 'aient', // imparfait
                'is', 'is', 'it', 'imes', 'ites', 'irent', // passé simple
                'rai', 'ras', 'ra', 'rons', 'rez', 'ront', // futur
                'rais', 'rais', 'rait', 'rions', 'riez', 'raient', // conditionnel
                'ant', 'u', // participes
            ]
        ];
    }

    /**
     * Verbes irréguliers les plus courants
     */
    private function getIrregularVerbs(): array
    {
        return [
            'etre' => ['suis', 'es', 'est', 'sommes', 'etes', 'sont', 'etais', 'etait', 'etions', 'etiez', 'etaient', 'fus', 'fut', 'fumes', 'furent', 'serai', 'seras', 'sera', 'serons', 'serez', 'seront', 'serais', 'serait', 'serions', 'seriez', 'seraient', 'etant', 'ete'],
            'avoir' => ['ai', 'as', 'a', 'avons', 'avez', 'ont', 'avais', 'avait', 'avions', 'aviez', 'avaient', 'eus', 'eut', 'eumes', 'eurent', 'aurai', 'auras', 'aura', 'aurons', 'aurez', 'auront', 'aurais', 'aurait', 'aurions', 'auriez', 'auraient', 'ayant', 'eu'],
            'aller' => ['vais', 'vas', 'va', 'allons', 'allez', 'vont', 'allais', 'allait', 'allions', 'alliez', 'allaient', 'allai', 'alla', 'allames', 'allerent', 'irai', 'iras', 'ira', 'irons', 'irez', 'iront', 'irais', 'irait', 'irions', 'iriez', 'iraient', 'allant', 'alle'],
            'faire' => ['fais', 'fait', 'faisons', 'faites', 'font', 'faisais', 'faisait', 'faisions', 'faisiez', 'faisaient', 'fis', 'fit', 'fimes', 'firent', 'ferai', 'feras', 'fera', 'ferons', 'ferez', 'feront', 'ferais', 'ferait', 'ferions', 'feriez', 'feraient', 'faisant', 'fait'],
            'dire' => ['dis', 'dit', 'disons', 'dites', 'disent', 'disais', 'disait', 'disions', 'disiez', 'disaient', 'dis', 'dit', 'dimes', 'dirent', 'dirai', 'diras', 'dira', 'dirons', 'direz', 'diront', 'dirais', 'dirait', 'dirions', 'diriez', 'diraient', 'disant', 'dit'],
            'voir' => ['vois', 'voit', 'voyons', 'voyez', 'voient', 'voyais', 'voyait', 'voyions', 'voyiez', 'voyaient', 'vis', 'vit', 'vimes', 'virent', 'verrai', 'verras', 'verra', 'verrons', 'verrez', 'verront', 'verrais', 'verrait', 'verrions', 'verriez', 'verraient', 'voyant', 'vu'],
            'savoir' => ['sais', 'sait', 'savons', 'savez', 'savent', 'savais', 'savait', 'savions', 'saviez', 'savaient', 'sus', 'sut', 'sumes', 'surent', 'saurai', 'sauras', 'saura', 'saurons', 'saurez', 'sauront', 'saurais', 'saurait', 'saurions', 'sauriez', 'sauraient', 'sachant', 'su'],
            'pouvoir' => ['peux', 'peut', 'pouvons', 'pouvez', 'peuvent', 'pouvais', 'pouvait', 'pouvions', 'pouviez', 'pouvaient', 'pus', 'put', 'pumes', 'purent', 'pourrai', 'pourras', 'pourra', 'pourrons', 'pourrez', 'pourront', 'pourrais', 'pourrait', 'pourrions', 'pourriez', 'pourraient', 'pouvant', 'pu'],
            'vouloir' => ['veux', 'veut', 'voulons', 'voulez', 'veulent', 'voulais', 'voulait', 'voulions', 'vouliez', 'voulaient', 'voulus', 'voulut', 'voulumes', 'voulurent', 'voudrai', 'voudras', 'voudra', 'voudrons', 'voudrez', 'voudront', 'voudrais', 'voudrait', 'voudrions', 'voudriez', 'voudraient', 'voulant', 'voulu'],
            'venir' => ['viens', 'vient', 'venons', 'venez', 'viennent', 'venais', 'venait', 'venions', 'veniez', 'venaient', 'vins', 'vint', 'vinmes', 'vinrent', 'viendrai', 'viendras', 'viendra', 'viendrons', 'viendrez', 'viendront', 'viendrais', 'viendrait', 'viendrions', 'viendriez', 'viendraient', 'venant', 'venu'],
            'partir' => ['pars', 'part', 'partons', 'partez', 'partent', 'partais', 'partait', 'partions', 'partiez', 'partaient', 'partis', 'partit', 'partimes', 'partirent', 'partirai', 'partiras', 'partira', 'partirons', 'partirez', 'partiront', 'partirais', 'partirait', 'partirions', 'partiriez', 'partiraient', 'partant', 'parti'],
        ];
    }

    private function calculateSemanticSimilarity(string $word1, string $word2): int
    {
        // Système de proximité sémantique équilibré comme Pedantix

        // 1. Vérifier les synonymes directs (score élevé)
        $synonyms = $this->getSynonyms();
        if (isset($synonyms[$word1]) && in_array($word2, $synonyms[$word1])) {
            return 950;
        }
        if (isset($synonyms[$word2]) && in_array($word1, $synonyms[$word2])) {
            return 950;
        }

        // 2. Vérifier les groupes sémantiques proches
        $semanticGroups = $this->getSemanticGroups();
        $group1 = null;
        $group2 = null;

        // Trouver les groupes des mots
        foreach ($semanticGroups as $groupName => $words) {
            if (in_array($word1, $words)) {
                $group1 = $groupName;
            }
            if (in_array($word2, $words)) {
                $group2 = $groupName;
            }
        }

        // 3. Même groupe sémantique = proximité élevée
        if ($group1 && $group2 && $group1 === $group2) {
            return $this->getGroupProximityScore($group1, $word1, $word2);
        }

        // 4. Groupes liés = proximité moyenne
        $relatedGroups = $this->getRelatedSemanticGroups();
        if ($group1 && $group2 && isset($relatedGroups[$group1]) && in_array($group2, $relatedGroups[$group1])) {
            return rand(400, 600); // Proximité variable pour les groupes liés
        }

        // 5. Vérifier les relations morphologiques (préfixes, suffixes)
        $morphological = $this->calculateMorphologicalSimilarity($word1, $word2);
        if ($morphological > 0) {
            return $morphological;
        }

        // 6. Vérifier les relations contextuelles spécifiques
        $contextual = $this->getContextualProximity($word1, $word2);
        if ($contextual > 0) {
            return $contextual;
        }

        return 0;
    }

    /**
     * Calcule le score de proximité pour des mots du même groupe
     */
    private function getGroupProximityScore(string $group, string $word1, string $word2): int
    {
        // Scores différents selon le type de groupe
        $groupScores = [
            'animaux_domestiques' => rand(800, 900),
            'animaux_sauvages' => rand(750, 850),
            'couleurs' => rand(700, 800),
            'corps_humain' => rand(750, 900),
            'geographie_france' => rand(600, 800),
            'science_physique' => rand(650, 850),
            'science_chimie' => rand(700, 900),
            'technologie' => rand(600, 750),
            'alimentation' => rand(650, 800),
            'transport' => rand(600, 750),
            'batiments' => rand(650, 800),
            'emotions' => rand(700, 850),
            'temps_meteo' => rand(600, 800),
            'materiaux' => rand(650, 800),
            'professions' => rand(600, 750),
            'sports' => rand(650, 800),
            'arts' => rand(600, 750),
            'plantes' => rand(700, 850),
            'eau_liquides' => rand(800, 950), // Très liés
            'feu_chaleur' => rand(750, 900)
        ];

        return $groupScores[$group] ?? rand(600, 800);
    }

    /**
     * Calcule la similarité morphologique (préfixes, suffixes, racines)
     */
    private function calculateMorphologicalSimilarity(string $word1, string $word2): int
    {
        // Éviter les mots trop courts
        if (strlen($word1) < 4 || strlen($word2) < 4) {
            return 0;
        }

        // Préfixes communs
        $prefixes = ['anti', 'auto', 'bio', 'co', 'de', 'dis', 'ex', 'hyper', 'inter', 'mega', 'micro', 'mini', 'multi', 'neo', 'post', 'pre', 'pro', 'pseudo', 're', 'semi', 'sub', 'super', 'trans', 'ultra', 'uni'];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($word1, $prefix) && str_starts_with($word2, $prefix)) {
                $suffix1 = substr($word1, strlen($prefix));
                $suffix2 = substr($word2, strlen($prefix));
                if (strlen($suffix1) >= 3 && strlen($suffix2) >= 3) {
                    $similarity = $this->calculateStringSimilarity($suffix1, $suffix2);
                    if ($similarity > 0.6) {
                        return (int)(300 + $similarity * 300); // 300-600
                    }
                }
            }
        }

        // Suffixes communs
        $suffixes = ['tion', 'sion', 'ment', 'able', 'ible', 'ique', 'aire', 'oire', 'eur', 'euse', 'age', 'isme', 'iste', 'ité', 'ité', 'ance', 'ence'];

        foreach ($suffixes as $suffix) {
            if (str_ends_with($word1, $suffix) && str_ends_with($word2, $suffix)) {
                $root1 = substr($word1, 0, -strlen($suffix));
                $root2 = substr($word2, 0, -strlen($suffix));
                if (strlen($root1) >= 3 && strlen($root2) >= 3) {
                    $similarity = $this->calculateStringSimilarity($root1, $root2);
                    if ($similarity > 0.5) {
                        return (int)(250 + $similarity * 350); // 250-600
                    }
                }
            }
        }

        // Similarité générale de chaîne pour des mots très similaires
        $similarity = $this->calculateStringSimilarity($word1, $word2);
        if ($similarity > 0.8) {
            return (int)(400 + $similarity * 300); // 400-700 pour des mots très similaires
        }

        return 0;
    }

    /**
     * Calcule la similarité entre deux chaînes (algorithme de Jaro-Winkler simplifié)
     */
    private function calculateStringSimilarity(string $str1, string $str2): float
    {
        $len1 = strlen($str1);
        $len2 = strlen($str2);

        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }

        if ($str1 === $str2) {
            return 1.0;
        }

        // Calcul de distance de Levenshtein normalisée
        $distance = levenshtein($str1, $str2);
        $maxLen = max($len1, $len2);

        return 1 - ($distance / $maxLen);
    }

    /**
     * Relations contextuelles spécifiques (comme dans Pedantix)
     */
    private function getContextualProximity(string $word1, string $word2): int
    {
        $contextualPairs = [
            // Relations cause-effet
            'feu' => ['chaleur' => 800, 'fumee' => 750, 'cendre' => 700, 'brulure' => 650],
            'eau' => ['humidite' => 750, 'vapeur' => 800, 'glace' => 850, 'liquide' => 900],
            'soleil' => ['chaleur' => 700, 'lumiere' => 850, 'jour' => 650, 'ete' => 600],
            'pluie' => ['eau' => 800, 'nuage' => 750, 'humidite' => 700, 'parapluie' => 600],

            // Relations spatiales
            'mer' => ['plage' => 700, 'vague' => 800, 'poisson' => 650, 'sel' => 600],
            'montagne' => ['sommet' => 750, 'vallee' => 700, 'neige' => 600, 'rocher' => 650],
            'foret' => ['arbre' => 850, 'bois' => 800, 'feuille' => 700, 'animal' => 550],

            // Relations fonctionnelles
            'voiture' => ['roue' => 700, 'moteur' => 750, 'essence' => 650, 'route' => 600],
            'maison' => ['toit' => 700, 'porte' => 650, 'fenetre' => 650, 'mur' => 700],
            'ordinateur' => ['ecran' => 700, 'clavier' => 650, 'souris' => 600, 'internet' => 550],

            // Relations temporelles
            'jour' => ['nuit' => 600, 'matin' => 650, 'soir' => 650, 'soleil' => 700],
            'hiver' => ['neige' => 800, 'froid' => 850, 'ete' => 500, 'glace' => 750],

            // Relations biologiques
            'coeur' => ['sang' => 900, 'circulation' => 850, 'artere' => 800, 'battement' => 750],
            'poumon' => ['respiration' => 900, 'air' => 800, 'oxygene' => 850, 'souffle' => 700],
            'cerveau' => ['pensee' => 800, 'neurone' => 850, 'intelligence' => 750, 'memoire' => 800],
        ];

        // Vérifier dans les deux sens
        if (isset($contextualPairs[$word1][$word2])) {
            return $contextualPairs[$word1][$word2];
        }
        if (isset($contextualPairs[$word2][$word1])) {
            return $contextualPairs[$word2][$word1];
        }

        return 0;
    }

    private function getSemanticGroups(): array
    {
        // Groupes sémantiques équilibrés comme Pedantix
        return [
            'animaux_domestiques' => ['chat', 'chien', 'cheval', 'vache', 'poule', 'cochon', 'mouton', 'chevre', 'lapin', 'canard'],
            'animaux_sauvages' => ['lion', 'tigre', 'elephant', 'singe', 'ours', 'loup', 'renard', 'cerf', 'sanglier', 'aigle'],
            'couleurs' => ['rouge', 'bleu', 'vert', 'jaune', 'noir', 'blanc', 'orange', 'violet', 'rose', 'gris', 'marron'],
            'corps_humain' => ['tete', 'corps', 'bras', 'jambe', 'main', 'pied', 'oeil', 'bouche', 'nez', 'oreille', 'coeur', 'poumon', 'cerveau', 'sang'],
            'geographie_france' => ['paris', 'lyon', 'marseille', 'toulouse', 'nice', 'nantes', 'strasbourg', 'montpellier', 'bordeaux', 'lille'],
            'science_physique' => ['energie', 'force', 'vitesse', 'masse', 'temperature', 'pression', 'lumiere', 'son', 'electricite', 'magnetisme'],
            'science_chimie' => ['eau', 'oxygene', 'hydrogene', 'carbone', 'azote', 'molecule', 'atome', 'acide', 'base', 'reaction'],
            'technologie' => ['ordinateur', 'internet', 'telephone', 'television', 'radio', 'logiciel', 'programme', 'donnee', 'reseau', 'systeme'],
            'alimentation' => ['pain', 'lait', 'fromage', 'viande', 'legume', 'fruit', 'eau', 'vin', 'biere', 'sucre', 'sel'],
            'transport' => ['voiture', 'train', 'avion', 'bateau', 'velo', 'moto', 'bus', 'metro', 'camion', 'taxi'],
            'batiments' => ['maison', 'ecole', 'hopital', 'eglise', 'musee', 'theatre', 'cinema', 'restaurant', 'hotel', 'magasin'],
            'emotions' => ['joie', 'tristesse', 'colere', 'peur', 'amour', 'haine', 'surprise', 'degout', 'fierte', 'honte'],
            'temps_meteo' => ['soleil', 'pluie', 'neige', 'vent', 'nuage', 'orage', 'brouillard', 'chaleur', 'froid', 'tempete'],
            'materiaux' => ['bois', 'metal', 'plastique', 'verre', 'pierre', 'tissu', 'papier', 'cuir', 'ceramique', 'caoutchouc'],
            'professions' => ['medecin', 'professeur', 'avocat', 'ingenieur', 'agriculteur', 'artiste', 'musicien', 'cuisinier', 'vendeur', 'ouvrier'],
            'sports' => ['football', 'tennis', 'natation', 'course', 'cyclisme', 'basketball', 'volleyball', 'rugby', 'golf', 'ski'],
            'arts' => ['peinture', 'musique', 'theatre', 'danse', 'sculpture', 'litterature', 'cinema', 'photographie', 'dessin', 'poesie'],
            'plantes' => ['arbre', 'fleur', 'herbe', 'feuille', 'racine', 'tige', 'graine', 'fruit', 'legume', 'foret'],
            'eau_liquides' => ['eau', 'mer', 'ocean', 'riviere', 'lac', 'pluie', 'neige', 'glace', 'vapeur', 'humidite'],
            'feu_chaleur' => ['feu', 'flamme', 'chaleur', 'chaud', 'brulure', 'incendie', 'fumee', 'cendre', 'temperature', 'soleil']
        ];
    }

    private function getRelatedSemanticGroups(): array
    {
        // Groupes liés entre eux (proximité moyenne)
        return [
            'animaux_domestiques' => ['animaux_sauvages', 'alimentation'],
            'animaux_sauvages' => ['animaux_domestiques', 'plantes'],
            'science_physique' => ['science_chimie', 'technologie'],
            'science_chimie' => ['science_physique', 'materiaux'],
            'corps_humain' => ['emotions', 'alimentation'],
            'alimentation' => ['corps_humain', 'plantes', 'animaux_domestiques'],
            'transport' => ['technologie', 'materiaux'],
            'batiments' => ['materiaux', 'geographie_france'],
            'temps_meteo' => ['eau_liquides', 'feu_chaleur'],
            'eau_liquides' => ['temps_meteo', 'science_chimie'],
            'feu_chaleur' => ['temps_meteo', 'science_physique'],
            'plantes' => ['alimentation', 'animaux_sauvages', 'eau_liquides'],
            'arts' => ['emotions', 'couleurs'],
            'sports' => ['corps_humain', 'emotions'],
            'professions' => ['batiments', 'technologie']
        ];
    }

    /**
     * Dictionnaire de synonymes étendu mais pertinent
     */
    private function getSynonyms(): array
    {
        return [
            // Synonymes directs (score très élevé)
            'eau' => ['h2o', 'flotte'],
            'h2o' => ['eau'],
            'ocean' => ['mer'],
            'mer' => ['ocean'],
            'voiture' => ['auto', 'automobile'],
            'auto' => ['voiture', 'automobile'],
            'automobile' => ['voiture', 'auto'],
            'maison' => ['domicile', 'habitation'],
            'domicile' => ['maison', 'habitation'],
            'habitation' => ['maison', 'domicile'],
            'grand' => ['gros', 'enorme', 'gigantesque'],
            'gros' => ['grand', 'enorme'],
            'enorme' => ['grand', 'gros', 'gigantesque'],
            'petit' => ['minuscule', 'tiny'],
            'minuscule' => ['petit', 'tiny'],
            'beau' => ['joli', 'magnifique'],
            'joli' => ['beau', 'magnifique'],
            'magnifique' => ['beau', 'joli'],
            'rouge' => ['ecarlate', 'vermillon'],
            'ecarlate' => ['rouge'],
            'bleu' => ['azur', 'cyan'],
            'azur' => ['bleu'],
            'rapide' => ['vite', 'veloce'],
            'vite' => ['rapide'],
            'intelligent' => ['malin', 'futé'],
            'malin' => ['intelligent'],
            'manger' => ['bouffer', 'deguster'],
            'dormir' => ['reposer', 'sommeiller'],
            'marcher' => ['aller', 'deambuler'],
            'regarder' => ['observer', 'contempler'],
            'parler' => ['discuter', 'bavarder'],

        ];
    }

    /**
     * Sauvegarde une salle
     */
    public function saveRoom(Room $room): void
    {
        $this->roomRepository->save($room, true);
    }

    /**
     * Calcule la difficulté d'un mot basé sur le nombre de joueurs qui l'ont trouvé
     */
    private function calculateWordDifficulty(string $word, int $foundByCount, int $totalPlayers): string
    {
        $percentage = $totalPlayers > 0 ? ($foundByCount / $totalPlayers) * 100 : 0;

        if ($percentage >= 80) {
            return 'Très facile';
        } elseif ($percentage >= 60) {
            return 'Facile';
        } elseif ($percentage >= 40) {
            return 'Moyen';
        } elseif ($percentage >= 20) {
            return 'Difficile';
        } else {
            return 'Très difficile';
        }
    }

    /**
     * Retourne le style CSS pour la couleur de proximité
     */
    private function getProximityColorStyle(int $proximityScore): string
    {
        if ($proximityScore >= 800) {
            return 'background: #d0d0d0 !important; color: #FFD700 !important; font-weight: bold !important;'; // Très chaud - doré
        } elseif ($proximityScore >= 600) {
            return 'background: #d0d0d0 !important; color: #FF8C00 !important; font-weight: bold !important;'; // Chaud - orange
        } elseif ($proximityScore >= 400) {
            return 'background: #d0d0d0 !important; color: #FF6347 !important;'; // Tiède - rouge tomate
        } else {
            return 'background: #d0d0d0 !important; color: #696969 !important;'; // Froid - gris foncé
        }
    }

    /**
     * Obtient la position d'un joueur dans le classement
     */
    private function getPlayerPosition(Room $room, GameSession $session): int
    {
        $leaderboard = $this->getLeaderboard($room);

        foreach ($leaderboard as $index => $leaderSession) {
            if ($leaderSession->getId() === $session->getId()) {
                return $index + 1;
            }
        }

        return count($leaderboard) + 1; // Si pas trouvé, mettre à la fin
    }

    /**
     * Génère un message de victoire personnalisé
     */
    private function generateVictoryMessage(GameSession $session, Room $room): string
    {
        $position = $this->getPlayerPosition($room, $session);
        $playerName = $session->getPlayerName();

        if ($position === 1) {
            return "🏆 {$playerName} remporte la victoire !";
        } elseif ($position <= 3) {
            return "🥉 {$playerName} termine sur le podium (#{$position}) !";
        } else {
            return "✅ {$playerName} a trouvé le mot-titre !";
        }
    }

    /**
     * Récupère les informations de progression du titre (mots trouvés/total)
     */
    public function getTitleProgress(GameSession $gameSession, Room $room): array
    {
        $titleWords = $this->extractTitleWords($room->getTitle());
        $titleWordsNormalized = array_map([$this, 'normalizeWord'], $titleWords);

        // En mode coopération, vérifier les mots trouvés globalement
        if ($room->isCooperativeMode()) {
            $allFoundWords = array_unique(array_merge($gameSession->getFoundWords(), $room->getGlobalFoundWords()));
        } else {
            $allFoundWords = $gameSession->getFoundWords();
        }

        $foundWordsNormalized = array_map([$this, 'normalizeWord'], $allFoundWords);

        $displayWords = [];
        $foundCount = 0;

        foreach ($titleWords as $index => $titleWord) {
            $normalizedTitleWord = $titleWordsNormalized[$index];
            $isFound = false;

            foreach ($foundWordsNormalized as $foundWord) {
                if ($foundWord === $normalizedTitleWord) {
                    $isFound = true;
                    break;
                }
            }

            if ($isFound) {
                $displayWords[] = $titleWord; // Afficher le mot trouvé
                $foundCount++;
            } else {
                // Afficher des traits selon la longueur du mot
                $displayWords[] = str_repeat('_', mb_strlen($titleWord));
            }
        }

        return [
            'title' => $room->getTitle(),
            'total_words' => count($titleWords),
            'found_words' => $foundCount,
            'display_title' => implode(' ', $displayWords),
            'is_complete' => $foundCount === count($titleWords),
            'progress_percentage' => count($titleWords) > 0 ? round(($foundCount / count($titleWords)) * 100) : 0
        ];
    }

    /**
     * Marque tous les joueurs actifs comme gagnants en mode coopération
     */
    private function markAllPlayersAsWinners(Room $room): void
    {
        $activePlayers = $this->getActivePlayers($room);

        foreach ($activePlayers as $session) {
            if (!$session->isCompleted()) {
                $session->setCompleted(true);
                $session->setCompletedAt(new \DateTimeImmutable());

                // Attribuer un score de participation pour tous les joueurs
                $participationScore = max(500 - ($session->getAttempts() * 5), 100);
                $session->setScore($session->getScore() + $participationScore);

                $this->gameSessionRepository->save($session, false);
            }
        }

        // Flush tous les changements en une fois
        $this->entityManager->flush();
    }

    /**
     * Calcule la proximité sémantique entre un mot deviné et le contenu de l'article
     * Version BEAUCOUP plus stricte pour éviter les fausses associations
     */
    private function calculateSemanticProximity(string $guess, string $content, string $title): int
    {
        $normalizedGuess = $this->normalizeWord($guess);

        // Extraire les mots du contenu et du titre
        $contentWords = $this->extractAllWords($content);
        $titleWords = array_map([$this, 'normalizeWord'], $this->extractTitleWords($title));

        $maxProximity = 0;

        // Vérifier la proximité avec chaque mot du contenu et du titre
        foreach (array_merge($contentWords, $titleWords) as $contentWord) {
            $normalizedContentWord = $this->normalizeWord($contentWord);

            // Éviter de comparer un mot avec lui-même
            if ($normalizedGuess === $normalizedContentWord) {
                continue;
            }

            $similarity = $this->calculateSemanticSimilarity($normalizedGuess, $normalizedContentWord);
            $maxProximity = max($maxProximity, $similarity);
        }

        return $maxProximity;
    }

    /**
     * Construit un mapping des proximités pour les mots devinés
     */
    private function buildWordProximityMapping(string $content, array $proximityData): array
    {
        $mapping = [];
        $contentWords = $this->extractAllWordsFromContent($content);

        foreach ($proximityData as $proximityItem) {
            $guessedWord = $proximityItem['word'] ?? '';
            $proximityScore = $proximityItem['proximity'] ?? 0;

            if (empty($guessedWord) || $proximityScore <= 0) {
                continue;
            }

            $normalizedGuess = $this->normalizeWord($guessedWord);

            // Chercher les mots du contenu qui pourraient correspondre
            foreach ($contentWords as $contentWord) {
                $normalizedContentWord = $this->normalizeWord($contentWord);

                // Vérifier si ce mot du contenu a une proximité sémantique avec le mot deviné
                $similarity = $this->calculateSemanticSimilarity($normalizedGuess, $normalizedContentWord);

                if ($similarity > 0 && $similarity >= $proximityScore * 0.8) { // Tolérance de 20%
                    $mapping[$normalizedContentWord] = [
                        'guessed_word' => $guessedWord,
                        'proximity' => $proximityScore
                    ];
                }
            }
        }

        return $mapping;
    }

    /**
     * Construit un mapping des proximités sémantiques pour les mots trouvés
     */
    private function buildSemanticProximityMapping(string $content, array $foundWords): array
    {
        $mapping = [];
        $contentWords = $this->extractAllWordsFromContent($content);

        foreach ($foundWords as $foundWord) {
            $normalizedFoundWord = $this->normalizeWord($foundWord);

            foreach ($contentWords as $contentWord) {
                $normalizedContentWord = $this->normalizeWord($contentWord);

                // Éviter de mapper un mot trouvé avec lui-même
                if ($normalizedFoundWord === $normalizedContentWord) {
                    continue;
                }

                $similarity = $this->calculateSemanticSimilarity($normalizedFoundWord, $normalizedContentWord);

                if ($similarity > 0) {
                    $mapping[$normalizedContentWord] = [
                        'found_word' => $foundWord,
                        'proximity' => $similarity
                    ];
                }
            }
        }

        return $mapping;
    }


    /**
     * Mots vides à ignorer dans l'analyse
     */
    private function getStopWords(): array
    {
        return [
            'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'est', 'en', 'a', 'il', 'être', 'et', 'à', 'avoir', 'que', 'pour',
            'dans', 'ce', 'son', 'une', 'sur', 'avec', 'ne', 'se', 'pas', 'tout', 'plus', 'par', 'grand', 'ou', 'si', 'les',
            'deux', 'très', 'bien', 'où', 'sans', 'peut', 'lui', 'aussi', 'son', 'comme', 'après', 'alors', 'sous', 'était',
            'avant', 'entre', 'fait', 'lors', 'dont', 'cet', 'donc', 'cette', 'ses', 'soit', 'leur', 'ont', 'peu', 'aux',
            'nous', 'vous', 'ils', 'elles', 'ces', 'ceux', 'celle', 'celui', 'depuis', 'contre', 'vers', 'chez', 'selon'
        ];
    }
}
