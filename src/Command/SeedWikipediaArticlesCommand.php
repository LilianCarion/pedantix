<?php

namespace App\Command;

use App\Entity\WikipediaArticle;
use App\Repository\WikipediaArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-wikipedia-articles',
    description: 'Peuple la base de données avec des articles Wikipedia populaires'
)]
class SeedWikipediaArticlesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WikipediaArticleRepository $wikipediaArticleRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Peuple la base de données avec des articles Wikipedia populaires')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force l\'ajout même si des articles existent déjà')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limite le nombre d\'articles à ajouter', 50)
            ->setHelp('Cette commande ajoute des articles Wikipedia populaires dans la base de données pour le jeu Pedantix.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');
        $limit = (int) $input->getOption('limit');

        // Vérifier s'il y a déjà des articles
        $existingCount = $this->wikipediaArticleRepository->count([]);
        if ($existingCount > 0 && !$force) {
            $io->note("Il y a déjà {$existingCount} articles dans la base. Utilisez --force pour en ajouter davantage.");
            return Command::SUCCESS;
        }

        $io->title('Peuplement des articles Wikipedia pour Pedantix');

        // Liste d'articles Wikipedia populaires et intéressants pour le jeu
        $articles = $this->getPopularArticles();

        $progressBar = $io->createProgressBar(min(count($articles), $limit));
        $progressBar->start();

        $added = 0;
        $skipped = 0;
        $errors = 0;

        foreach (array_slice($articles, 0, $limit) as $articleData) {
            try {
                // Vérifier si l'article existe déjà
                $existing = $this->wikipediaArticleRepository->findOneBy(['url' => $articleData['url']]);
                if ($existing) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                // Créer le nouvel article
                $article = new WikipediaArticle();
                $article->setTitle($articleData['title']);
                $article->setUrl($articleData['url']);
                $article->setCategory($articleData['category'] ?? null);
                $article->setDifficulty($articleData['difficulty'] ?? null);
                $article->setIsActive(true);

                $this->entityManager->persist($article);
                $added++;

                // Flush par batch de 10 pour les performances
                if ($added % 10 === 0) {
                    $this->entityManager->flush();
                }

            } catch (\Exception $e) {
                $errors++;
                $io->warning("Erreur avec l'article '{$articleData['title']}': " . $e->getMessage());
            }

            $progressBar->advance();
        }

        // Flush final
        $this->entityManager->flush();
        $progressBar->finish();

        $io->newLine(2);
        $io->success("Opération terminée !");

        $io->table(
            ['Statistique', 'Nombre'],
            [
                ['Articles ajoutés', $added],
                ['Articles ignorés (déjà existants)', $skipped],
                ['Erreurs', $errors],
                ['Total en base', $this->wikipediaArticleRepository->count([])]
            ]
        );

        if ($errors > 0) {
            $io->warning("Il y a eu {$errors} erreur(s) lors de l'ajout.");
        }

        return Command::SUCCESS;
    }

    /**
     * Liste d'articles Wikipedia populaires et variés pour le jeu
     */
    private function getPopularArticles(): array
    {
        return [
            // Sciences et nature
            ['title' => 'Eau', 'url' => 'https://fr.wikipedia.org/wiki/Eau', 'category' => 'Science', 'difficulty' => 'Facile'],
            ['title' => 'Soleil', 'url' => 'https://fr.wikipedia.org/wiki/Soleil', 'category' => 'Astronomie', 'difficulty' => 'Facile'],
            ['title' => 'Terre', 'url' => 'https://fr.wikipedia.org/wiki/Terre', 'category' => 'Astronomie', 'difficulty' => 'Facile'],
            ['title' => 'Lune', 'url' => 'https://fr.wikipedia.org/wiki/Lune', 'category' => 'Astronomie', 'difficulty' => 'Facile'],
            ['title' => 'Oxygène', 'url' => 'https://fr.wikipedia.org/wiki/Oxygène', 'category' => 'Chimie', 'difficulty' => 'Moyen'],
            ['title' => 'ADN', 'url' => 'https://fr.wikipedia.org/wiki/Acide_désoxyribonucléique', 'category' => 'Biologie', 'difficulty' => 'Difficile'],
            ['title' => 'Photosynthèse', 'url' => 'https://fr.wikipedia.org/wiki/Photosynthèse', 'category' => 'Biologie', 'difficulty' => 'Moyen'],
            ['title' => 'Électricité', 'url' => 'https://fr.wikipedia.org/wiki/Électricité', 'category' => 'Physique', 'difficulty' => 'Moyen'],
            ['title' => 'Gravité', 'url' => 'https://fr.wikipedia.org/wiki/Gravitation', 'category' => 'Physique', 'difficulty' => 'Difficile'],
            ['title' => 'Atome', 'url' => 'https://fr.wikipedia.org/wiki/Atome', 'category' => 'Physique', 'difficulty' => 'Moyen'],

            // Animaux
            ['title' => 'Chat', 'url' => 'https://fr.wikipedia.org/wiki/Chat', 'category' => 'Animaux', 'difficulty' => 'Facile'],
            ['title' => 'Chien', 'url' => 'https://fr.wikipedia.org/wiki/Chien', 'category' => 'Animaux', 'difficulty' => 'Facile'],
            ['title' => 'Éléphant', 'url' => 'https://fr.wikipedia.org/wiki/Éléphant', 'category' => 'Animaux', 'difficulty' => 'Facile'],
            ['title' => 'Dauphin', 'url' => 'https://fr.wikipedia.org/wiki/Dauphin', 'category' => 'Animaux', 'difficulty' => 'Moyen'],
            ['title' => 'Requin', 'url' => 'https://fr.wikipedia.org/wiki/Requin', 'category' => 'Animaux', 'difficulty' => 'Moyen'],
            ['title' => 'Abeille', 'url' => 'https://fr.wikipedia.org/wiki/Abeille', 'category' => 'Animaux', 'difficulty' => 'Moyen'],
            ['title' => 'Dinosaure', 'url' => 'https://fr.wikipedia.org/wiki/Dinosaure', 'category' => 'Paléontologie', 'difficulty' => 'Moyen'],

            // Histoire et géographie
            ['title' => 'France', 'url' => 'https://fr.wikipedia.org/wiki/France', 'category' => 'Géographie', 'difficulty' => 'Facile'],
            ['title' => 'Paris', 'url' => 'https://fr.wikipedia.org/wiki/Paris', 'category' => 'Géographie', 'difficulty' => 'Facile'],
            ['title' => 'Londres', 'url' => 'https://fr.wikipedia.org/wiki/Londres', 'category' => 'Géographie', 'difficulty' => 'Facile'],
            ['title' => 'Rome', 'url' => 'https://fr.wikipedia.org/wiki/Rome', 'category' => 'Géographie', 'difficulty' => 'Facile'],
            ['title' => 'Première Guerre mondiale', 'url' => 'https://fr.wikipedia.org/wiki/Première_Guerre_mondiale', 'category' => 'Histoire', 'difficulty' => 'Moyen'],
            ['title' => 'Révolution française', 'url' => 'https://fr.wikipedia.org/wiki/Révolution_française', 'category' => 'Histoire', 'difficulty' => 'Moyen'],
            ['title' => 'Napoléon Bonaparte', 'url' => 'https://fr.wikipedia.org/wiki/Napoléon_Ier', 'category' => 'Histoire', 'difficulty' => 'Moyen'],
            ['title' => 'Tour Eiffel', 'url' => 'https://fr.wikipedia.org/wiki/Tour_Eiffel', 'category' => 'Monument', 'difficulty' => 'Facile'],
            ['title' => 'Pyramides de Gizeh', 'url' => 'https://fr.wikipedia.org/wiki/Pyramides_de_Gizeh', 'category' => 'Monument', 'difficulty' => 'Moyen'],

            // Culture et arts
            ['title' => 'Musique', 'url' => 'https://fr.wikipedia.org/wiki/Musique', 'category' => 'Culture', 'difficulty' => 'Facile'],
            ['title' => 'Peinture', 'url' => 'https://fr.wikipedia.org/wiki/Peinture', 'category' => 'Art', 'difficulty' => 'Facile'],
            ['title' => 'Cinéma', 'url' => 'https://fr.wikipedia.org/wiki/Cinéma', 'category' => 'Culture', 'difficulty' => 'Facile'],
            ['title' => 'Littérature', 'url' => 'https://fr.wikipedia.org/wiki/Littérature', 'category' => 'Culture', 'difficulty' => 'Moyen'],
            ['title' => 'Leonardo da Vinci', 'url' => 'https://fr.wikipedia.org/wiki/Léonard_de_Vinci', 'category' => 'Art', 'difficulty' => 'Moyen'],
            ['title' => 'William Shakespeare', 'url' => 'https://fr.wikipedia.org/wiki/William_Shakespeare', 'category' => 'Littérature', 'difficulty' => 'Moyen'],
            ['title' => 'Mozart', 'url' => 'https://fr.wikipedia.org/wiki/Wolfgang_Amadeus_Mozart', 'category' => 'Musique', 'difficulty' => 'Moyen'],

            // Sports
            ['title' => 'Football', 'url' => 'https://fr.wikipedia.org/wiki/Football', 'category' => 'Sport', 'difficulty' => 'Facile'],
            ['title' => 'Tennis', 'url' => 'https://fr.wikipedia.org/wiki/Tennis', 'category' => 'Sport', 'difficulty' => 'Facile'],
            ['title' => 'Jeux olympiques', 'url' => 'https://fr.wikipedia.org/wiki/Jeux_olympiques', 'category' => 'Sport', 'difficulty' => 'Moyen'],
            ['title' => 'Tour de France', 'url' => 'https://fr.wikipedia.org/wiki/Tour_de_France', 'category' => 'Sport', 'difficulty' => 'Moyen'],

            // Technologie
            ['title' => 'Internet', 'url' => 'https://fr.wikipedia.org/wiki/Internet', 'category' => 'Technologie', 'difficulty' => 'Moyen'],
            ['title' => 'Ordinateur', 'url' => 'https://fr.wikipedia.org/wiki/Ordinateur', 'category' => 'Technologie', 'difficulty' => 'Moyen'],
            ['title' => 'Téléphone', 'url' => 'https://fr.wikipedia.org/wiki/Téléphone', 'category' => 'Technologie', 'difficulty' => 'Facile'],
            ['title' => 'Intelligence artificielle', 'url' => 'https://fr.wikipedia.org/wiki/Intelligence_artificielle', 'category' => 'Technologie', 'difficulty' => 'Difficile'],

            // Alimentation
            ['title' => 'Pain', 'url' => 'https://fr.wikipedia.org/wiki/Pain', 'category' => 'Alimentation', 'difficulty' => 'Facile'],
            ['title' => 'Fromage', 'url' => 'https://fr.wikipedia.org/wiki/Fromage', 'category' => 'Alimentation', 'difficulty' => 'Facile'],
            ['title' => 'Vin', 'url' => 'https://fr.wikipedia.org/wiki/Vin', 'category' => 'Alimentation', 'difficulty' => 'Moyen'],
            ['title' => 'Chocolat', 'url' => 'https://fr.wikipedia.org/wiki/Chocolat', 'category' => 'Alimentation', 'difficulty' => 'Facile'],
            ['title' => 'Café', 'url' => 'https://fr.wikipedia.org/wiki/Café', 'category' => 'Alimentation', 'difficulty' => 'Facile'],

            // Philosophie et religion
            ['title' => 'Philosophie', 'url' => 'https://fr.wikipedia.org/wiki/Philosophie', 'category' => 'Philosophie', 'difficulty' => 'Difficile'],
            ['title' => 'Religion', 'url' => 'https://fr.wikipedia.org/wiki/Religion', 'category' => 'Religion', 'difficulty' => 'Moyen'],
            ['title' => 'Socrate', 'url' => 'https://fr.wikipedia.org/wiki/Socrate', 'category' => 'Philosophie', 'difficulty' => 'Difficile'],
            ['title' => 'Platon', 'url' => 'https://fr.wikipedia.org/wiki/Platon', 'category' => 'Philosophie', 'difficulty' => 'Difficile'],

            // Concepts abstraits
            ['title' => 'Amour', 'url' => 'https://fr.wikipedia.org/wiki/Amour', 'category' => 'Psychologie', 'difficulty' => 'Moyen'],
            ['title' => 'Bonheur', 'url' => 'https://fr.wikipedia.org/wiki/Bonheur', 'category' => 'Psychologie', 'difficulty' => 'Moyen'],
            ['title' => 'Temps', 'url' => 'https://fr.wikipedia.org/wiki/Temps', 'category' => 'Physique', 'difficulty' => 'Difficile'],
            ['title' => 'Espace', 'url' => 'https://fr.wikipedia.org/wiki/Espace_(notion)', 'category' => 'Physique', 'difficulty' => 'Difficile'],

            // Mathématiques
            ['title' => 'Mathématiques', 'url' => 'https://fr.wikipedia.org/wiki/Mathématiques', 'category' => 'Mathématiques', 'difficulty' => 'Moyen'],
            ['title' => 'Nombre', 'url' => 'https://fr.wikipedia.org/wiki/Nombre', 'category' => 'Mathématiques', 'difficulty' => 'Moyen'],
            ['title' => 'Pi', 'url' => 'https://fr.wikipedia.org/wiki/Pi', 'category' => 'Mathématiques', 'difficulty' => 'Difficile'],
            ['title' => 'Géométrie', 'url' => 'https://fr.wikipedia.org/wiki/Géométrie', 'category' => 'Mathématiques', 'difficulty' => 'Moyen'],

            // Médecine et corps humain
            ['title' => 'Cœur', 'url' => 'https://fr.wikipedia.org/wiki/Cœur', 'category' => 'Médecine', 'difficulty' => 'Facile'],
            ['title' => 'Cerveau', 'url' => 'https://fr.wikipedia.org/wiki/Cerveau', 'category' => 'Médecine', 'difficulty' => 'Moyen'],
            ['title' => 'Sang', 'url' => 'https://fr.wikipedia.org/wiki/Sang', 'category' => 'Médecine', 'difficulty' => 'Facile'],
            ['title' => 'Muscle', 'url' => 'https://fr.wikipedia.org/wiki/Muscle', 'category' => 'Médecine', 'difficulty' => 'Moyen']
        ];
    }
}
