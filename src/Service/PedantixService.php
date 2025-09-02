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

        $gameSession->incrementAttempts();
        $gameSession->updateActivity();

        $result = [
            'found' => false,
            'word' => $guess,
            'proximity' => null,
            'gameCompleted' => false,
            'isExactMatch' => false
        ];

        // Vérifier si c'est le mot-titre (victoire)
        $titleWords = $this->extractTitleWords($room->getTitle());
        $normalizedGuess = $this->normalizeWord($guess);

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

            // Ajouter des points pour chaque mot trouvé
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
                $isRevealed = in_array($normalizedWord, $foundWordsNormalized);

                if ($isRevealed) {
                    $processedWords[] = '<span class="revealed-word">' . htmlspecialchars($word) . '</span>';
                } else {
                    // Vérifier si ce mot a une proximité avec un mot deviné récemment
                    $proximityClass = $this->getProximityClass($normalizedWord, $proximityData);

                    if ($proximityClass) {
                        // Afficher le mot avec la couleur de proximité mais il reste grisé
                        $processedWords[] = '<span class="hidden-word ' . $proximityClass . '" data-word="' . htmlspecialchars($normalizedWord) . '">' . htmlspecialchars($word) . '</span>';
                    } else {
                        // Mot complètement caché
                        $processedWords[] = '<span class="hidden-word" data-word="' . htmlspecialchars($normalizedWord) . '">' . str_repeat('█', mb_strlen($word)) . '</span>';
                    }
                }
            }
        }

        return implode('', $processedWords);
    }

    private function getProximityClass(string $normalizedWord, array $proximityData): ?string
    {
        foreach ($proximityData as $data) {
            if (!isset($data['word']) || !isset($data['proximity'])) continue;

            $guessNormalized = $this->normalizeWord($data['word']);
            $proximity = $data['proximity'];

            // Calculer la similarité entre le mot de l'article et le mot deviné
            $similarity = $this->calculateLevenshteinSimilarity($normalizedWord, $guessNormalized);

            // Déterminer la classe CSS en fonction de la proximité
            if ($similarity > 0.8 || $proximity > 800) {
                return 'proximity-very-close'; // Jaune clair - on brûle
            } elseif ($similarity > 0.6 || $proximity > 400) {
                return 'proximity-close'; // Orange
            } elseif ($similarity > 0.4 || $proximity > 100) {
                return 'proximity-distant'; // Orange foncé
            }
        }

        return null;
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
                'header' => "User-Agent: PedantixApp/1.0\r\n"
            ]
        ]);

        $summaryResponse = file_get_contents($summaryApiUrl, false, $context);
        if ($summaryResponse === false) {
            throw new \Exception('Impossible de récupérer l\'article Wikipedia');
        }

        $summaryData = json_decode($summaryResponse, true);
        if (!$summaryData || !isset($summaryData['extract'])) {
            throw new \Exception('Contenu de l\'article introuvable');
        }

        // L'extract contient déjà un résumé propre de l'article
        $content = $summaryData['extract'];

        // Nettoyer un peu plus le contenu pour enlever les références restantes
        $content = preg_replace('/\[[\d,\s]+\]/', '', $content); // Supprimer les références [1], [2,3], etc.
        $content = preg_replace('/\s+/', ' ', $content); // Normaliser les espaces
        $content = trim($content);

        $properTitle = $summaryData['title'] ?? $title;

        return [
            'title' => $properTitle,
            'content' => $content,
            'allWords' => $this->extractAllWords($content)
        ];
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
                }
            }

            if ($this->normalizeWord($cleanWord) === $normalizedGuess) {
                return true;
            }
        }

        return false;
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

        // Vérifier la proximité avec TOUS les mots du contenu
        foreach ($contentWords as $word) {
            if (strlen($word) >= 2 && !in_array($word, $this->getStopWords())) {
                $similarity = $this->calculateLevenshteinSimilarity($normalizedGuess, $word);

                // Système de proximité basé sur la similarité
                if ($similarity > 0.8) {
                    // Très proche - jaune clair (on brûle)
                    $maxProximity = max($maxProximity, 800 + ($similarity * 100));
                } elseif ($similarity > 0.6) {
                    // Assez proche - orange
                    $maxProximity = max($maxProximity, 400 + ($similarity * 200));
                } elseif ($similarity > 0.4) {
                    // Pas très proche - orange foncé
                    $maxProximity = max($maxProximity, 100 + ($similarity * 100));
                }
            }
        }

        // Vérifier aussi les mots partiels et les sous-chaînes
        foreach ($contentWords as $word) {
            if (strlen($word) >= 3) {
                // Si le guess est contenu dans le mot ou vice versa
                if (strpos($word, $normalizedGuess) !== false || strpos($normalizedGuess, $word) !== false) {
                    $maxProximity = max($maxProximity, 600);
                }
            }
        }

        // Si aucune proximité significative trouvée, retourner 0 (pas d'affichage)
        if ($maxProximity < 100) {
            return 0;
        }

        return min(999, $maxProximity);
    }

    private function extractAllWordsFromContent(string $content): array
    {
        // Extraire tous les mots du contenu en préservant la structure
        $words = preg_split('/(\s+|[.,;:!?()"\'-])/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
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
}
