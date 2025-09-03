<?php

require_once 'vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use App\Entity\WikipediaArticle;
use App\Repository\WikipediaArticleRepository;

// Charger les variables d'environnement
$dotenv = new Dotenv();
$dotenv->load('.env.local', '.env');

// Configuration Doctrine
$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/src'],
    isDevMode: true,
);

// Paramètres de connexion
$connectionParams = [
    'url' => $_ENV['DATABASE_URL'],
];

$connection = DriverManager::getConnection($connectionParams);
$entityManager = EntityManager::create($connection, $config);

// Tester le repository
try {
    $repository = $entityManager->getRepository(WikipediaArticle::class);

    echo "Test de récupération d'un article aléatoire...\n";
    $randomArticle = $repository->findRandomArticle();

    if ($randomArticle) {
        echo "✅ Article aléatoire trouvé :\n";
        echo "  - Titre: " . $randomArticle->getTitle() . "\n";
        echo "  - Difficulté: " . $randomArticle->getDifficulty() . "\n";
        echo "  - Catégorie: " . $randomArticle->getCategory() . "\n";
        echo "  - URL: " . $randomArticle->getUrl() . "\n";
    } else {
        echo "❌ Aucun article aléatoire trouvé\n";
    }

    // Tester avec une difficulté spécifique
    echo "\nTest avec difficulté 'facile'...\n";
    $easyArticle = $repository->findRandomArticle('facile');

    if ($easyArticle) {
        echo "✅ Article facile trouvé : " . $easyArticle->getTitle() . "\n";
    } else {
        echo "❌ Aucun article facile trouvé\n";
    }

} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage() . "\n";
    echo "Trace : " . $e->getTraceAsString() . "\n";
}
