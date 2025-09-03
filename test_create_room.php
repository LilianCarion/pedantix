<?php

// Test direct de création de salle pour reproduire l'erreur

require_once 'vendor/autoload.php';

use App\Kernel;
use App\Service\PedantixService;
use Symfony\Component\Dotenv\Dotenv;

echo "=== Test de création de salle ===\n\n";

try {
    // Charger manuellement les variables d'environnement
    $dotenv = new Dotenv();
    $dotenv->loadEnv(__DIR__.'/.env');

    echo "Variables d'environnement chargées\n";
    echo "DATABASE_URL trouvée: " . ($_ENV['DATABASE_URL'] ? 'OUI' : 'NON') . "\n\n";

    // Initialiser le kernel Symfony
    $kernel = new Kernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    // Récupérer le service PedantixService
    $pedantixService = $container->get(PedantixService::class);

    echo "Service PedantixService récupéré avec succès\n";

    // Tester avec l'URL de l'eau
    $testUrl = "https://fr.wikipedia.org/wiki/Eau";
    echo "Test avec URL: $testUrl\n\n";

    echo "Création de la salle en cours...\n";
    $room = $pedantixService->createRoom($testUrl);

    echo "✅ SUCCÈS! Salle créée:\n";
    echo "- Code: " . $room->getCode() . "\n";
    echo "- Titre: " . $room->getTitle() . "\n";
    echo "- Contenu (100 premiers caractères): " . substr($room->getContent(), 0, 100) . "...\n";
    echo "- Nombre de mots: " . count($room->getWordsToFind()) . "\n";

} catch (Exception $e) {
    echo "❌ ERREUR lors de la création de salle:\n";
    echo "Type: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Fichier: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nTrace complète:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Fin du test ===\n";
