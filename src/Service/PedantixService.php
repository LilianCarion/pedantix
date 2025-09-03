<?php

namespace App\Service;

use App\Entity\GameSession;
use App\Entity\Room;
use App\Repository\GameSessionRepository;
use App\Repository\RoomRepository;
use Doctrine\ORM\EntityManagerInterface;

class PedantixService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoomRepository $roomRepository,
        private GameSessionRepository $gameSessionRepository
    ) {}

    public function createRoom(string $wikipediaUrl): Room
    {
        $articleData = $this->fetchWikipediaArticle($wikipediaUrl);

        $room = new Room();
        $room->setTitle($articleData['title']);
        $room->setContent($articleData['content']);
        $room->setUrl($wikipediaUrl);
        $room->setWordsToFind($articleData['allWords']);
        $room->setHints([]); // Pas d'indices dans le vrai Pedantix

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

        // Vérifier si c'est le mot-titre (victoire)
        $titleWords = $this->extractTitleWords($room->getTitle());

        foreach ($titleWords as $titleWord) {
            if ($this->normalizeWord($titleWord) === $normalizedGuess) {
                $result['found'] = true;
                $result['isExactMatch'] = true;
                $result['gameCompleted'] = true;
                $gameSession->addFoundWord($guess);
                $gameSession->setCompleted(true);

                // Score final basé sur le nombre de tentatives (moins = mieux)
                $finalScore = max(1000 - ($gameSession->getAttempts() * 10), 100);
                $gameSession->setScore($finalScore);

                $this->gameSessionRepository->save($gameSession, true);
                return $result;
            }
        }

        // Vérifier si le mot existe dans l'article
        if ($this->wordExistsInArticle($guess, $content)) {
            $result['found'] = true;
            $gameSession->addFoundWord($guess);

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
        $foundWordsNormalized = array_map([$this, 'normalizeWord'], $foundWords);

        // Si le jeu est terminé, révéler tous les mots
        if ($gameCompleted) {
            // Diviser le contenu en mots tout en préservant la ponctuation et la structure
            $words = preg_split('/(\s+|[.,;:!?()"\'-])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

            $processedWords = [];
            foreach ($words as $word) {
                if (trim($word) === '' || preg_match('/^\s*$/', $word) || preg_match('/^[.,;:!?()"\'-]+$/', $word)) {
                    // Espaces et ponctuation - garder tel quel
                    $processedWords[] = $word;
                } else {
                    // Tous les mots sont révélés avec le style de victoire
                    $processedWords[] = '<span class="title-word">' . htmlspecialchars($word) . '</span>';
                }
            }

            return implode('', $processedWords);
        }

        // Créer un mapping des mots de l'article vers les mots devinés les plus proches
        $wordProximityMapping = $this->buildWordProximityMapping($content, $proximityData);

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
                    // Vérifier si ce mot a une proximité avec un mot deviné
                    if (isset($wordProximityMapping[$normalizedWord])) {
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

    private function calculateSemanticProximity(string $guess, string $content, string $title): int
    {
        $normalizedGuess = $this->normalizeWord($guess);
        $titleWords = array_map([$this, 'normalizeWord'], $this->extractTitleWords($title));

        // Extraire TOUS les mots de l'article, pas seulement le titre
        $allContentWords = $this->extractAllWordsFromContent($content);
        $contentWords = array_map([$this, 'normalizeWord'], $allContentWords);

        $maxProximity = 0;

        // Vérifier la proximité avec les mots du titre (proximité maximale)
        foreach ($titleWords as $titleWord) {
            $similarity = $this->calculateLevenshteinSimilarity($normalizedGuess, $titleWord);
            if ($similarity > 0.7) {
                $maxProximity = max($maxProximity, 950 + ($similarity * 50));
            }
        }

        // Nouveau système de proximité sémantique avancé
        foreach ($allContentWords as $contentWord) {
            $normalizedContentWord = $this->normalizeWord($contentWord);
            if (strlen($normalizedContentWord) >= 2 && !in_array($normalizedContentWord, $this->getStopWords())) {

                // 1. Vérifier la similarité sémantique directe
                $semanticScore = $this->calculateSemanticSimilarity($normalizedContentWord, $normalizedGuess);
                if ($semanticScore > 0) {
                    $maxProximity = max($maxProximity, $semanticScore);
                }

                // 2. Vérifier la distance de Levenshtein (orthographe similaire)
                $similarity = $this->calculateLevenshteinSimilarity($normalizedGuess, $normalizedContentWord);
                if ($similarity > 0.8) {
                    $maxProximity = max($maxProximity, 800 + ($similarity * 100));
                } elseif ($similarity > 0.6) {
                    $maxProximity = max($maxProximity, 400 + ($similarity * 200));
                } elseif ($similarity > 0.4) {
                    $maxProximity = max($maxProximity, 100 + ($similarity * 100));
                }

                // 3. Vérifier les sous-chaînes
                if (strlen($normalizedGuess) >= 3 && strlen($normalizedContentWord) >= 3) {
                    if (strpos($normalizedGuess, $normalizedContentWord) !== false || strpos($normalizedContentWord, $normalizedGuess) !== false) {
                        $maxProximity = max($maxProximity, 600);
                    }
                }
            }
        }

        // Si aucune proximité significative trouvée, retourner 0 (pas d'affichage)
        if ($maxProximity < 100) {
            return 0;
        }

        return min(999, $maxProximity);
    }

    private function calculateSemanticSimilarity(string $word1, string $word2): int
    {
        // Base de données de relations sémantiques simplifiée
        $semanticGroups = $this->getSemanticGroups();

        $group1 = null;
        $group2 = null;

        // Trouver les groupes sémantiques des mots
        foreach ($semanticGroups as $groupName => $words) {
            if (in_array($word1, $words)) {
                $group1 = $groupName;
            }
            if (in_array($word2, $words)) {
                $group2 = $groupName;
            }
        }

        // Si les deux mots sont dans le même groupe sémantique
        if ($group1 && $group2 && $group1 === $group2) {
            return 900; // Très haute proximité sémantique
        }

        // Vérifier les groupes liés
        $relatedGroups = $this->getRelatedSemanticGroups();
        if ($group1 && $group2 && isset($relatedGroups[$group1]) && in_array($group2, $relatedGroups[$group1])) {
            return 700; // Proximité sémantique élevée
        }

        // Vérifier les synonymes directs
        $synonyms = $this->getSynonyms();
        if (isset($synonyms[$word1]) && in_array($word2, $synonyms[$word1])) {
            return 850;
        }
        if (isset($synonyms[$word2]) && in_array($word1, $synonyms[$word2])) {
            return 850;
        }

        return 0;
    }

    private function getSemanticGroups(): array
    {
        return [
            'etats_matiere' => ['liquide', 'gaz', 'solide', 'plasma', 'vapeur', 'fluide'],
            'chimie' => ['molecule', 'atome', 'element', 'compose', 'reaction', 'chimique', 'formule', 'oxygene', 'hydrogene', 'carbone', 'azote'],
            'eau_related' => ['eau', 'aquatique', 'marin', 'maritime', 'oceanique', 'fluvial', 'hydrique', 'hydraulique', 'hydrologie'],
            'temperature' => ['chaud', 'froid', 'chaleur', 'temperature', 'thermique', 'calorique', 'glacial', 'bouillant'],
            'corps_humain' => ['corps', 'organisme', 'cellule', 'tissu', 'organe', 'muscle', 'sang', 'cerveau', 'coeur'],
            'science' => ['physique', 'biologie', 'chimie', 'mathematiques', 'recherche', 'experience', 'laboratoire', 'scientifique'],
            'geographie' => ['terre', 'planete', 'continent', 'ocean', 'mer', 'riviere', 'montagne', 'vallee', 'climat'],
            'vie' => ['vivant', 'organisme', 'biologique', 'vital', 'existence', 'survie', 'evolutif'],
            'couleurs' => ['rouge', 'bleu', 'vert', 'jaune', 'noir', 'blanc', 'orange', 'violet', 'rose', 'gris'],
            'taille' => ['grand', 'petit', 'enorme', 'minuscule', 'gigantesque', 'microscopique', 'immense', 'tiny'],
            'mouvement' => ['rapide', 'lent', 'vitesse', 'acceleration', 'deceleration', 'mobile', 'statique', 'dynamique'],
            'qualites' => ['important', 'essentiel', 'crucial', 'vital', 'necessaire', 'indispensable', 'fondamental'],
        ];
    }

    private function getRelatedSemanticGroups(): array
    {
        return [
            'etats_matiere' => ['chimie', 'temperature', 'science'],
            'chimie' => ['etats_matiere', 'science', 'eau_related'],
            'eau_related' => ['chimie', 'etats_matiere', 'geographie', 'vie'],
            'temperature' => ['etats_matiere', 'science'],
            'corps_humain' => ['vie', 'science'],
            'science' => ['chimie', 'corps_humain', 'temperature'],
            'geographie' => ['eau_related', 'vie'],
            'vie' => ['corps_humain', 'eau_related', 'geographie'],
        ];
    }

    private function getSynonyms(): array
    {
        return [
            'eau' => ['h2o', 'aqua', 'flotte'],
            'liquide' => ['fluide', 'liquid'],
            'gaz' => ['gazeux', 'vapeur', 'aeriforme'],
            'solide' => ['dur', 'rigide', 'cristallin'],
            'chaud' => ['chaude', 'brulant', 'torride'],
            'froid' => ['froide', 'glacial', 'frigide'],
            'grand' => ['grande', 'gros', 'grosse', 'immense', 'gigantesque'],
            'petit' => ['petite', 'minuscule', 'infime'],
            'important' => ['importante', 'essentiel', 'essentielle', 'crucial', 'cruciale'],
            'necessaire' => ['indispensable', 'requis', 'obligatoire'],
            'vivant' => ['vivante', 'anime', 'biologique'],
            'chimique' => ['chimiques', 'moleculaire'],
            'naturel' => ['naturelle', 'nature', 'natif'],
            'artificiel' => ['artificielle', 'synthetique', 'fabrique'],
        ];
    }

    private function calculateLevenshteinSimilarity(string $str1, string $str2): float
    {
        $len1 = strlen($str1);
        $len2 = strlen($str2);

        if ($len1 === 0 && $len2 === 0) return 1.0;
        if ($len1 === 0 || $len2 === 0) return 0.0;

        $distance = levenshtein($str1, $str2);
        $maxLen = max($len1, $len2);

        return 1 - ($distance / $maxLen);
    }

    private function getStopWords(): array
    {
        return [
            'le', 'de', 'et', 'à', 'un', 'il', 'être', 'en', 'avoir', 'que', 'pour',
            'dans', 'ce', 'son', 'une', 'sur', 'avec', 'ne', 'se', 'pas', 'tout', 'plus',
            'par', 'grand', 'mais', 'qui', 'comme', 'où', 'ou', 'du', 'des', 'les', 'la',
            'cette', 'ces', 'ses', 'leur', 'leurs', 'aux', 'nous', 'vous', 'ils', 'elles',
            'est', 'sont', 'était', 'ont', 'peut', 'fait', 'très', 'bien', 'deux', 'aussi'
        ];
    }

    private function buildWordProximityMapping(string $content, array $proximityData): array
    {
        $mapping = [];

        if (empty($proximityData)) {
            return $mapping;
        }

        // Extraire tous les mots de l'article
        $allContentWords = $this->extractAllWordsFromContent($content);

        foreach ($proximityData as $proximityInfo) {
            $guessedWord = $proximityInfo['word'];
            $proximityScore = $proximityInfo['proximity'];

            // Trouver le meilleur mot de l'article pour afficher ce mot deviné
            $bestMatch = null;
            $bestSimilarity = 0;

            foreach ($allContentWords as $contentWord) {
                $normalizedContentWord = $this->normalizeWord($contentWord);
                $normalizedGuessedWord = $this->normalizeWord($guessedWord);

                // Calculer la similarité entre le mot deviné et le mot de l'article
                $similarity = $this->calculateLevenshteinSimilarity($normalizedGuessedWord, $normalizedContentWord);

                if ($similarity > $bestSimilarity && $similarity > 0.3) {
                    $bestSimilarity = $similarity;
                    $bestMatch = $normalizedContentWord;
                }
            }

            // Si on a trouvé un match suffisamment bon, l'ajouter au mapping
            if ($bestMatch !== null) {
                $mapping[$bestMatch] = [
                    'guessed_word' => $guessedWord,
                    'proximity' => $proximityScore
                ];
            }
        }

        return $mapping;
    }

    private function getProximityColorStyle(int $proximityScore): string
    {
        if ($proximityScore >= 900) {
            // Très chaud - jaune/or
            return 'background: linear-gradient(45deg, #FFEB3B, #FFC107) !important; color: #333 !important;';
        } elseif ($proximityScore >= 700) {
            // Chaud - orange
            return 'background: linear-gradient(45deg, #FF9800, #F57C00) !important; color: white !important;';
        } elseif ($proximityScore >= 500) {
            // Tiède - orange plus clair
            return 'background: linear-gradient(45deg, #FF9800, #FFB74D) !important; color: white !important;';
        } elseif ($proximityScore >= 300) {
            // Froid - gris
            return 'background: linear-gradient(45deg, #9E9E9E, #757575) !important; color: white !important;';
        } else {
            // Très froid - gris foncé
            return 'background: linear-gradient(45deg, #757575, #424242) !important; color: white !important;';
        }
    }
}
