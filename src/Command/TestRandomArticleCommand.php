<?php

namespace App\Command;

use App\Repository\WikipediaArticleRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-random-article',
    description: 'Teste la récupération d\'un article aléatoire',
)]
class TestRandomArticleCommand extends Command
{
    public function __construct(
        private WikipediaArticleRepository $wikipediaArticleRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $io->title('Test de récupération d\'article aléatoire');

            // Test général
            $randomArticle = $this->wikipediaArticleRepository->findRandomArticle();

            if ($randomArticle) {
                $io->success('Article aléatoire trouvé :');
                $io->table(
                    ['Propriété', 'Valeur'],
                    [
                        ['Titre', $randomArticle->getTitle()],
                        ['Difficulté', $randomArticle->getDifficulty()],
                        ['Catégorie', $randomArticle->getCategory()],
                        ['URL', $randomArticle->getUrl()],
                    ]
                );
            } else {
                $io->error('Aucun article aléatoire trouvé');
                return Command::FAILURE;
            }

            // Test avec difficulté
            $io->section('Test avec difficulté "facile"');
            $easyArticle = $this->wikipediaArticleRepository->findRandomArticle('facile');

            if ($easyArticle) {
                $io->success('Article facile trouvé : ' . $easyArticle->getTitle());
            } else {
                $io->warning('Aucun article facile trouvé');
            }

            // Test avec difficulté "moyen"
            $io->section('Test avec difficulté "moyen"');
            $mediumArticle = $this->wikipediaArticleRepository->findRandomArticle('moyen');

            if ($mediumArticle) {
                $io->success('Article moyen trouvé : ' . $mediumArticle->getTitle());
            } else {
                $io->warning('Aucun article moyen trouvé');
            }

            // Test avec difficulté "difficile"
            $io->section('Test avec difficulté "difficile"');
            $hardArticle = $this->wikipediaArticleRepository->findRandomArticle('difficile');

            if ($hardArticle) {
                $io->success('Article difficile trouvé : ' . $hardArticle->getTitle());
            } else {
                $io->warning('Aucun article difficile trouvé');
            }

            $io->success('Tous les tests ont réussi !');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors du test : ' . $e->getMessage());
            $io->text('Trace : ' . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
