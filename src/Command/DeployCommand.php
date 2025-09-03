<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:deploy',
    description: 'Déploie l\'application Pedantix en une seule commande (migrations, articles, cache, etc.)',
)]
class DeployCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('env', InputArgument::OPTIONAL, 'Environnement (dev|prod)', 'prod')
            ->setHelp('Cette commande déploie l\'application Pedantix en exécutant toutes les étapes nécessaires.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = $input->getArgument('env');

        $io->title('🚀 Déploiement Pedantix');

        try {
            // 1. Nettoyer le cache
            $io->section('1. Nettoyage du cache');
            $this->runCommand(['cache:clear', '--env=' . $env], $io);

            // 2. Créer la base de données si nécessaire
            $io->section('2. Configuration de la base de données');
            $this->runCommand(['doctrine:database:create', '--if-not-exists'], $io);

            // 3. Exécuter les migrations
            $io->section('3. Migrations de la base de données');
            $this->runCommand(['doctrine:migrations:migrate', '--no-interaction'], $io);

            // 4. Peupler avec les articles Wikipedia
            $io->section('4. Peuplement des articles Wikipedia');
            $this->runCommand(['app:seed-wikipedia-articles'], $io);

            // 5. Vérifications finales
            $io->section('5. Vérifications');

            // Compter les articles
            $articleCount = $this->entityManager
                ->createQuery('SELECT COUNT(a.id) FROM App\Entity\WikipediaArticle a')
                ->getSingleScalarResult();

            $io->success([
                'Déploiement terminé avec succès !',
                "Articles en base de données : {$articleCount}",
                'Application prête à être utilisée'
            ]);

            $io->section('📝 Points d\'accès de l\'application');
            $io->table(['Endpoint', 'Description'], [
                ['/', 'Page d\'accueil'],
                ['/create-room', 'Créer une nouvelle partie'],
                ['/api/random-article', 'Article aléatoire'],
                ['/game/{code}', 'Rejoindre une partie'],
            ]);

            if ($env === 'prod') {
                $io->note([
                    'Mode production activé',
                    'Assurez-vous que :',
                    '- Le serveur web pointe vers public/',
                    '- HTTPS est configuré',
                    '- Les variables d\'environnement sont définies',
                    '- Les permissions des dossiers var/ sont correctes'
                ]);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error([
                'Erreur lors du déploiement :',
                $e->getMessage()
            ]);
            return Command::FAILURE;
        }
    }

    private function runCommand(array $command, SymfonyStyle $io): void
    {
        $process = new Process(['php', 'bin/console', ...$command]);
        $process->setTimeout(300); // 5 minutes max par commande

        $io->write("Exécution : " . implode(' ', $command) . "... ");

        $process->run();

        if ($process->isSuccessful()) {
            $io->writeln('<fg=green>✓ OK</>');
            if ($output = $process->getOutput()) {
                $io->text($output);
            }
        } else {
            $io->writeln('<fg=red>✗ ERREUR</>');
            throw new \Exception('Commande échouée: ' . $process->getErrorOutput());
        }
    }
}
