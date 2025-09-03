<?php

namespace App\Command;

use App\Entity\WikipediaArticle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-wikipedia-articles',
    description: 'Ajoute des articles Wikipedia par défaut dans la base de données',
)]
class SeedWikipediaArticlesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Articles Wikipedia français populaires pour différents niveaux de difficulté
        $articles = [
            // Facile
            [
                'title' => 'Chat',
                'url' => 'https://fr.wikipedia.org/wiki/Chat',
                'category' => 'Animaux',
                'difficulty' => 'facile'
            ],
            [
                'title' => 'Paris',
                'url' => 'https://fr.wikipedia.org/wiki/Paris',
                'category' => 'Géographie',
                'difficulty' => 'facile'
            ],
            [
                'title' => 'Eau',
                'url' => 'https://fr.wikipedia.org/wiki/Eau',
                'category' => 'Science',
                'difficulty' => 'facile'
            ],
            [
                'title' => 'Soleil',
                'url' => 'https://fr.wikipedia.org/wiki/Soleil',
                'category' => 'Astronomie',
                'difficulty' => 'facile'
            ],
            [
                'title' => 'France',
                'url' => 'https://fr.wikipedia.org/wiki/France',
                'category' => 'Géographie',
                'difficulty' => 'facile'
            ],

            // Moyen
            [
                'title' => 'Photosynthèse',
                'url' => 'https://fr.wikipedia.org/wiki/Photosynthèse',
                'category' => 'Biologie',
                'difficulty' => 'moyen'
            ],
            [
                'title' => 'Révolution française',
                'url' => 'https://fr.wikipedia.org/wiki/Révolution_française',
                'category' => 'Histoire',
                'difficulty' => 'moyen'
            ],
            [
                'title' => 'Intelligence artificielle',
                'url' => 'https://fr.wikipedia.org/wiki/Intelligence_artificielle',
                'category' => 'Technologie',
                'difficulty' => 'moyen'
            ],
            [
                'title' => 'Océan Atlantique',
                'url' => 'https://fr.wikipedia.org/wiki/Océan_Atlantique',
                'category' => 'Géographie',
                'difficulty' => 'moyen'
            ],
            [
                'title' => 'Mozart',
                'url' => 'https://fr.wikipedia.org/wiki/Wolfgang_Amadeus_Mozart',
                'category' => 'Musique',
                'difficulty' => 'moyen'
            ],

            // Difficile
            [
                'title' => 'Mécanique quantique',
                'url' => 'https://fr.wikipedia.org/wiki/Mécanique_quantique',
                'category' => 'Physique',
                'difficulty' => 'difficile'
            ],
            [
                'title' => 'Mitochondrie',
                'url' => 'https://fr.wikipedia.org/wiki/Mitochondrie',
                'category' => 'Biologie',
                'difficulty' => 'difficile'
            ],
            [
                'title' => 'Théorie de la relativité',
                'url' => 'https://fr.wikipedia.org/wiki/Théorie_de_la_relativité',
                'category' => 'Physique',
                'difficulty' => 'difficile'
            ],
            [
                'title' => 'Algorithme de Dijkstra',
                'url' => 'https://fr.wikipedia.org/wiki/Algorithme_de_Dijkstra',
                'category' => 'Informatique',
                'difficulty' => 'difficile'
            ],
            [
                'title' => 'Épigénétique',
                'url' => 'https://fr.wikipedia.org/wiki/Épigénétique',
                'category' => 'Biologie',
                'difficulty' => 'difficile'
            ],

            // Articles supplémentaires populaires
            [
                'title' => 'Leonardo da Vinci',
                'url' => 'https://fr.wikipedia.org/wiki/Léonard_de_Vinci',
                'category' => 'Art',
                'difficulty' => 'moyen'
            ],
            [
                'title' => 'Chocolat',
                'url' => 'https://fr.wikipedia.org/wiki/Chocolat',
                'category' => 'Alimentation',
                'difficulty' => 'facile'
            ],
            [
                'title' => 'Internet',
                'url' => 'https://fr.wikipedia.org/wiki/Internet',
                'category' => 'Technologie',
                'difficulty' => 'moyen'
            ],
            [
                'title' => 'Dinosaure',
                'url' => 'https://fr.wikipedia.org/wiki/Dinosauria',
                'category' => 'Paléontologie',
                'difficulty' => 'moyen'
            ],
            [
                'title' => 'Tour Eiffel',
                'url' => 'https://fr.wikipedia.org/wiki/Tour_Eiffel',
                'category' => 'Architecture',
                'difficulty' => 'facile'
            ]
        ];

        $count = 0;
        foreach ($articles as $articleData) {
            // Vérifier si l'article existe déjà
            $existing = $this->entityManager->getRepository(WikipediaArticle::class)
                ->findOneBy(['title' => $articleData['title']]);

            if (!$existing) {
                $article = new WikipediaArticle();
                $article->setTitle($articleData['title']);
                $article->setUrl($articleData['url']);
                $article->setCategory($articleData['category']);
                $article->setDifficulty($articleData['difficulty']);
                $article->setActive(true);

                $this->entityManager->persist($article);
                $count++;

                $io->writeln("Ajouté: {$articleData['title']} ({$articleData['difficulty']})");
            } else {
                $io->writeln("Existe déjà: {$articleData['title']}");
            }
        }

        $this->entityManager->flush();

        $io->success("$count articles Wikipedia ont été ajoutés à la base de données.");

        return Command::SUCCESS;
    }
}
