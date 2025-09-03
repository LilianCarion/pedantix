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

        // Articles Wikipedia français populaires pour différents niveaux de difficulté (150+ articles)
        $articles = [
            // === NIVEAU FACILE (40 articles) ===
            // Animaux
            ['title' => 'Chat', 'url' => 'https://fr.wikipedia.org/wiki/Chat', 'category' => 'Animaux', 'difficulty' => 'facile'],
            ['title' => 'Chien', 'url' => 'https://fr.wikipedia.org/wiki/Chien', 'category' => 'Animaux', 'difficulty' => 'facile'],
            ['title' => 'Éléphant', 'url' => 'https://fr.wikipedia.org/wiki/Éléphant', 'category' => 'Animaux', 'difficulty' => 'facile'],
            ['title' => 'Lion', 'url' => 'https://fr.wikipedia.org/wiki/Lion', 'category' => 'Animaux', 'difficulty' => 'facile'],
            ['title' => 'Oiseau', 'url' => 'https://fr.wikipedia.org/wiki/Oiseau', 'category' => 'Animaux', 'difficulty' => 'facile'],
            ['title' => 'Poisson', 'url' => 'https://fr.wikipedia.org/wiki/Poisson', 'category' => 'Animaux', 'difficulty' => 'facile'],
            ['title' => 'Abeille', 'url' => 'https://fr.wikipedia.org/wiki/Abeille', 'category' => 'Animaux', 'difficulty' => 'facile'],
            ['title' => 'Papillon', 'url' => 'https://fr.wikipedia.org/wiki/Papillon', 'category' => 'Animaux', 'difficulty' => 'facile'],

            // Géographie & Villes
            ['title' => 'Paris', 'url' => 'https://fr.wikipedia.org/wiki/Paris', 'category' => 'Géographie', 'difficulty' => 'facile'],
            ['title' => 'France', 'url' => 'https://fr.wikipedia.org/wiki/France', 'category' => 'Géographie', 'difficulty' => 'facile'],
            ['title' => 'Lyon', 'url' => 'https://fr.wikipedia.org/wiki/Lyon', 'category' => 'Géographie', 'difficulty' => 'facile'],
            ['title' => 'Marseille', 'url' => 'https://fr.wikipedia.org/wiki/Marseille', 'category' => 'Géographie', 'difficulty' => 'facile'],
            ['title' => 'Toulouse', 'url' => 'https://fr.wikipedia.org/wiki/Toulouse', 'category' => 'Géographie', 'difficulty' => 'facile'],
            ['title' => 'Montagne', 'url' => 'https://fr.wikipedia.org/wiki/Montagne', 'category' => 'Géographie', 'difficulty' => 'facile'],
            ['title' => 'Mer', 'url' => 'https://fr.wikipedia.org/wiki/Mer', 'category' => 'Géographie', 'difficulty' => 'facile'],
            ['title' => 'Rivière', 'url' => 'https://fr.wikipedia.org/wiki/Rivière', 'category' => 'Géographie', 'difficulty' => 'facile'],

            // Sciences basiques
            ['title' => 'Eau', 'url' => 'https://fr.wikipedia.org/wiki/Eau', 'category' => 'Science', 'difficulty' => 'facile'],
            ['title' => 'Air', 'url' => 'https://fr.wikipedia.org/wiki/Air', 'category' => 'Science', 'difficulty' => 'facile'],
            ['title' => 'Feu', 'url' => 'https://fr.wikipedia.org/wiki/Feu', 'category' => 'Science', 'difficulty' => 'facile'],
            ['title' => 'Terre', 'url' => 'https://fr.wikipedia.org/wiki/Terre', 'category' => 'Astronomie', 'difficulty' => 'facile'],
            ['title' => 'Soleil', 'url' => 'https://fr.wikipedia.org/wiki/Soleil', 'category' => 'Astronomie', 'difficulty' => 'facile'],
            ['title' => 'Lune', 'url' => 'https://fr.wikipedia.org/wiki/Lune', 'category' => 'Astronomie', 'difficulty' => 'facile'],

            // Alimentation
            ['title' => 'Pain', 'url' => 'https://fr.wikipedia.org/wiki/Pain', 'category' => 'Alimentation', 'difficulty' => 'facile'],
            ['title' => 'Chocolat', 'url' => 'https://fr.wikipedia.org/wiki/Chocolat', 'category' => 'Alimentation', 'difficulty' => 'facile'],
            ['title' => 'Pomme', 'url' => 'https://fr.wikipedia.org/wiki/Pomme', 'category' => 'Alimentation', 'difficulty' => 'facile'],
            ['title' => 'Fromage', 'url' => 'https://fr.wikipedia.org/wiki/Fromage', 'category' => 'Alimentation', 'difficulty' => 'facile'],
            ['title' => 'Vin', 'url' => 'https://fr.wikipedia.org/wiki/Vin', 'category' => 'Alimentation', 'difficulty' => 'facile'],

            // Culture & Monuments
            ['title' => 'Tour Eiffel', 'url' => 'https://fr.wikipedia.org/wiki/Tour_Eiffel', 'category' => 'Architecture', 'difficulty' => 'facile'],
            ['title' => 'Louvre', 'url' => 'https://fr.wikipedia.org/wiki/Musée_du_Louvre', 'category' => 'Culture', 'difficulty' => 'facile'],
            ['title' => 'Notre-Dame de Paris', 'url' => 'https://fr.wikipedia.org/wiki/Cathédrale_Notre-Dame_de_Paris', 'category' => 'Architecture', 'difficulty' => 'facile'],

            // Sport
            ['title' => 'Football', 'url' => 'https://fr.wikipedia.org/wiki/Football', 'category' => 'Sport', 'difficulty' => 'facile'],
            ['title' => 'Tennis', 'url' => 'https://fr.wikipedia.org/wiki/Tennis', 'category' => 'Sport', 'difficulty' => 'facile'],
            ['title' => 'Rugby', 'url' => 'https://fr.wikipedia.org/wiki/Rugby', 'category' => 'Sport', 'difficulty' => 'facile'],

            // Transports
            ['title' => 'Voiture', 'url' => 'https://fr.wikipedia.org/wiki/Automobile', 'category' => 'Transport', 'difficulty' => 'facile'],
            ['title' => 'Avion', 'url' => 'https://fr.wikipedia.org/wiki/Avion', 'category' => 'Transport', 'difficulty' => 'facile'],
            ['title' => 'Train', 'url' => 'https://fr.wikipedia.org/wiki/Train', 'category' => 'Transport', 'difficulty' => 'facile'],
            ['title' => 'Vélo', 'url' => 'https://fr.wikipedia.org/wiki/Bicyclette', 'category' => 'Transport', 'difficulty' => 'facile'],

            // Couleurs & Formes
            ['title' => 'Rouge', 'url' => 'https://fr.wikipedia.org/wiki/Rouge', 'category' => 'Couleur', 'difficulty' => 'facile'],
            ['title' => 'Bleu', 'url' => 'https://fr.wikipedia.org/wiki/Bleu', 'category' => 'Couleur', 'difficulty' => 'facile'],
            ['title' => 'Vert', 'url' => 'https://fr.wikipedia.org/wiki/Vert', 'category' => 'Couleur', 'difficulty' => 'facile'],
            ['title' => 'Jaune', 'url' => 'https://fr.wikipedia.org/wiki/Jaune', 'category' => 'Couleur', 'difficulty' => 'facile'],

            // === NIVEAU MOYEN (60 articles) ===
            // Histoire
            ['title' => 'Révolution française', 'url' => 'https://fr.wikipedia.org/wiki/Révolution_française', 'category' => 'Histoire', 'difficulty' => 'moyen'],
            ['title' => 'Napoléon Bonaparte', 'url' => 'https://fr.wikipedia.org/wiki/Napoléon_Ier', 'category' => 'Histoire', 'difficulty' => 'moyen'],
            ['title' => 'Louis XIV', 'url' => 'https://fr.wikipedia.org/wiki/Louis_XIV', 'category' => 'Histoire', 'difficulty' => 'moyen'],
            ['title' => 'Première Guerre mondiale', 'url' => 'https://fr.wikipedia.org/wiki/Première_Guerre_mondiale', 'category' => 'Histoire', 'difficulty' => 'moyen'],
            ['title' => 'Seconde Guerre mondiale', 'url' => 'https://fr.wikipedia.org/wiki/Seconde_Guerre_mondiale', 'category' => 'Histoire', 'difficulty' => 'moyen'],
            ['title' => 'Moyen Âge', 'url' => 'https://fr.wikipedia.org/wiki/Moyen_Âge', 'category' => 'Histoire', 'difficulty' => 'moyen'],
            ['title' => 'Renaissance', 'url' => 'https://fr.wikipedia.org/wiki/Renaissance', 'category' => 'Histoire', 'difficulty' => 'moyen'],
            ['title' => 'Empire romain', 'url' => 'https://fr.wikipedia.org/wiki/Empire_romain', 'category' => 'Histoire', 'difficulty' => 'moyen'],

            // Sciences & Technologies
            ['title' => 'Photosynthèse', 'url' => 'https://fr.wikipedia.org/wiki/Photosynthèse', 'category' => 'Biologie', 'difficulty' => 'moyen'],
            ['title' => 'Évolution', 'url' => 'https://fr.wikipedia.org/wiki/Évolution_(biologie)', 'category' => 'Biologie', 'difficulty' => 'moyen'],
            ['title' => 'ADN', 'url' => 'https://fr.wikipedia.org/wiki/Acide_désoxyribonucléique', 'category' => 'Biologie', 'difficulty' => 'moyen'],
            ['title' => 'Cellule', 'url' => 'https://fr.wikipedia.org/wiki/Cellule_(biologie)', 'category' => 'Biologie', 'difficulty' => 'moyen'],
            ['title' => 'Atome', 'url' => 'https://fr.wikipedia.org/wiki/Atome', 'category' => 'Chimie', 'difficulty' => 'moyen'],
            ['title' => 'Molécule', 'url' => 'https://fr.wikipedia.org/wiki/Molécule', 'category' => 'Chimie', 'difficulty' => 'moyen'],
            ['title' => 'Électricité', 'url' => 'https://fr.wikipedia.org/wiki/Électricité', 'category' => 'Physique', 'difficulty' => 'moyen'],
            ['title' => 'Magnétisme', 'url' => 'https://fr.wikipedia.org/wiki/Magnétisme', 'category' => 'Physique', 'difficulty' => 'moyen'],
            ['title' => 'Gravité', 'url' => 'https://fr.wikipedia.org/wiki/Gravitation', 'category' => 'Physique', 'difficulty' => 'moyen'],
            ['title' => 'Intelligence artificielle', 'url' => 'https://fr.wikipedia.org/wiki/Intelligence_artificielle', 'category' => 'Technologie', 'difficulty' => 'moyen'],
            ['title' => 'Internet', 'url' => 'https://fr.wikipedia.org/wiki/Internet', 'category' => 'Technologie', 'difficulty' => 'moyen'],
            ['title' => 'Ordinateur', 'url' => 'https://fr.wikipedia.org/wiki/Ordinateur', 'category' => 'Technologie', 'difficulty' => 'moyen'],

            // Géographie avancée
            ['title' => 'Océan Atlantique', 'url' => 'https://fr.wikipedia.org/wiki/Océan_Atlantique', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Océan Pacifique', 'url' => 'https://fr.wikipedia.org/wiki/Océan_Pacifique', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Himalaya', 'url' => 'https://fr.wikipedia.org/wiki/Himalaya', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Amazonie', 'url' => 'https://fr.wikipedia.org/wiki/Amazonie', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Sahara', 'url' => 'https://fr.wikipedia.org/wiki/Sahara', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Antarctique', 'url' => 'https://fr.wikipedia.org/wiki/Antarctique', 'category' => 'Géographie', 'difficulty' => 'moyen'],

            // Arts & Culture
            ['title' => 'Leonardo da Vinci', 'url' => 'https://fr.wikipedia.org/wiki/Léonard_de_Vinci', 'category' => 'Art', 'difficulty' => 'moyen'],
            ['title' => 'Pablo Picasso', 'url' => 'https://fr.wikipedia.org/wiki/Pablo_Picasso', 'category' => 'Art', 'difficulty' => 'moyen'],
            ['title' => 'Vincent van Gogh', 'url' => 'https://fr.wikipedia.org/wiki/Vincent_van_Gogh', 'category' => 'Art', 'difficulty' => 'moyen'],
            ['title' => 'Mozart', 'url' => 'https://fr.wikipedia.org/wiki/Wolfgang_Amadeus_Mozart', 'category' => 'Musique', 'difficulty' => 'moyen'],
            ['title' => 'Beethoven', 'url' => 'https://fr.wikipedia.org/wiki/Ludwig_van_Beethoven', 'category' => 'Musique', 'difficulty' => 'moyen'],
            ['title' => 'Bach', 'url' => 'https://fr.wikipedia.org/wiki/Jean-Sébastien_Bach', 'category' => 'Musique', 'difficulty' => 'moyen'],

            // Littérature
            ['title' => 'Victor Hugo', 'url' => 'https://fr.wikipedia.org/wiki/Victor_Hugo', 'category' => 'Littérature', 'difficulty' => 'moyen'],
            ['title' => 'Molière', 'url' => 'https://fr.wikipedia.org/wiki/Molière', 'category' => 'Littérature', 'difficulty' => 'moyen'],
            ['title' => 'Shakespeare', 'url' => 'https://fr.wikipedia.org/wiki/William_Shakespeare', 'category' => 'Littérature', 'difficulty' => 'moyen'],
            ['title' => 'Voltaire', 'url' => 'https://fr.wikipedia.org/wiki/Voltaire', 'category' => 'Littérature', 'difficulty' => 'moyen'],

            // Animaux & Nature
            ['title' => 'Dinosaure', 'url' => 'https://fr.wikipedia.org/wiki/Dinosauria', 'category' => 'Paléontologie', 'difficulty' => 'moyen'],
            ['title' => 'Baleine', 'url' => 'https://fr.wikipedia.org/wiki/Baleine', 'category' => 'Animaux', 'difficulty' => 'moyen'],
            ['title' => 'Requin', 'url' => 'https://fr.wikipedia.org/wiki/Requin', 'category' => 'Animaux', 'difficulty' => 'moyen'],
            ['title' => 'Aigle', 'url' => 'https://fr.wikipedia.org/wiki/Aigle_(oiseau)', 'category' => 'Animaux', 'difficulty' => 'moyen'],
            ['title' => 'Forêt', 'url' => 'https://fr.wikipedia.org/wiki/Forêt', 'category' => 'Nature', 'difficulty' => 'moyen'],
            ['title' => 'Désert', 'url' => 'https://fr.wikipedia.org/wiki/Désert', 'category' => 'Géographie', 'difficulty' => 'moyen'],

            // Philosophie & Religion
            ['title' => 'Philosophie', 'url' => 'https://fr.wikipedia.org/wiki/Philosophie', 'category' => 'Philosophie', 'difficulty' => 'moyen'],
            ['title' => 'Socrate', 'url' => 'https://fr.wikipedia.org/wiki/Socrate', 'category' => 'Philosophie', 'difficulty' => 'moyen'],
            ['title' => 'Platon', 'url' => 'https://fr.wikipedia.org/wiki/Platon', 'category' => 'Philosophie', 'difficulty' => 'moyen'],
            ['title' => 'Aristote', 'url' => 'https://fr.wikipedia.org/wiki/Aristote', 'category' => 'Philosophie', 'difficulty' => 'moyen'],

            // Mathématiques
            ['title' => 'Mathématiques', 'url' => 'https://fr.wikipedia.org/wiki/Mathématiques', 'category' => 'Mathématiques', 'difficulty' => 'moyen'],
            ['title' => 'Géométrie', 'url' => 'https://fr.wikipedia.org/wiki/Géométrie', 'category' => 'Mathématiques', 'difficulty' => 'moyen'],
            ['title' => 'Algèbre', 'url' => 'https://fr.wikipedia.org/wiki/Algèbre', 'category' => 'Mathématiques', 'difficulty' => 'moyen'],
            ['title' => 'Calcul intégral', 'url' => 'https://fr.wikipedia.org/wiki/Calcul_intégral', 'category' => 'Mathématiques', 'difficulty' => 'moyen'],

            // Médecine
            ['title' => 'Médecine', 'url' => 'https://fr.wikipedia.org/wiki/Médecine', 'category' => 'Médecine', 'difficulty' => 'moyen'],
            ['title' => 'Antibiotique', 'url' => 'https://fr.wikipedia.org/wiki/Antibiotique', 'category' => 'Médecine', 'difficulty' => 'moyen'],
            ['title' => 'Vaccin', 'url' => 'https://fr.wikipedia.org/wiki/Vaccin', 'category' => 'Médecine', 'difficulty' => 'moyen'],
            ['title' => 'Système immunitaire', 'url' => 'https://fr.wikipedia.org/wiki/Système_immunitaire', 'category' => 'Biologie', 'difficulty' => 'moyen'],

            // Pays et Capitales
            ['title' => 'Allemagne', 'url' => 'https://fr.wikipedia.org/wiki/Allemagne', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Italie', 'url' => 'https://fr.wikipedia.org/wiki/Italie', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Espagne', 'url' => 'https://fr.wikipedia.org/wiki/Espagne', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Japon', 'url' => 'https://fr.wikipedia.org/wiki/Japon', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Chine', 'url' => 'https://fr.wikipedia.org/wiki/République_populaire_de_Chine', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'États-Unis', 'url' => 'https://fr.wikipedia.org/wiki/États-Unis', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Brésil', 'url' => 'https://fr.wikipedia.org/wiki/Brésil', 'category' => 'Géographie', 'difficulty' => 'moyen'],
            ['title' => 'Inde', 'url' => 'https://fr.wikipedia.org/wiki/Inde', 'category' => 'Géographie', 'difficulty' => 'moyen'],

            // === NIVEAU DIFFICILE (50+ articles) ===
            // Sciences avancées
            ['title' => 'Mécanique quantique', 'url' => 'https://fr.wikipedia.org/wiki/Mécanique_quantique', 'category' => 'Physique', 'difficulty' => 'difficile'],
            ['title' => 'Théorie de la relativité', 'url' => 'https://fr.wikipedia.org/wiki/Théorie_de_la_relativité', 'category' => 'Physique', 'difficulty' => 'difficile'],
            ['title' => 'Thermodynamique', 'url' => 'https://fr.wikipedia.org/wiki/Thermodynamique', 'category' => 'Physique', 'difficulty' => 'difficile'],
            ['title' => 'Électromagnétisme', 'url' => 'https://fr.wikipedia.org/wiki/Électromagnétisme', 'category' => 'Physique', 'difficulty' => 'difficile'],
            ['title' => 'Physique des particules', 'url' => 'https://fr.wikipedia.org/wiki/Physique_des_particules', 'category' => 'Physique', 'difficulty' => 'difficile'],

            // Biologie avancée
            ['title' => 'Mitochondrie', 'url' => 'https://fr.wikipedia.org/wiki/Mitochondrie', 'category' => 'Biologie', 'difficulty' => 'difficile'],
            ['title' => 'Épigénétique', 'url' => 'https://fr.wikipedia.org/wiki/Épigénétique', 'category' => 'Biologie', 'difficulty' => 'difficile'],
            ['title' => 'Protéine', 'url' => 'https://fr.wikipedia.org/wiki/Protéine', 'category' => 'Biochimie', 'difficulty' => 'difficile'],
            ['title' => 'Enzyme', 'url' => 'https://fr.wikipedia.org/wiki/Enzyme', 'category' => 'Biochimie', 'difficulty' => 'difficile'],
            ['title' => 'Chromosome', 'url' => 'https://fr.wikipedia.org/wiki/Chromosome', 'category' => 'Génétique', 'difficulty' => 'difficile'],
            ['title' => 'Ribonucléique', 'url' => 'https://fr.wikipedia.org/wiki/Acide_ribonucléique', 'category' => 'Biologie', 'difficulty' => 'difficile'],
            ['title' => 'Méiose', 'url' => 'https://fr.wikipedia.org/wiki/Méiose', 'category' => 'Biologie', 'difficulty' => 'difficile'],
            ['title' => 'Mitose', 'url' => 'https://fr.wikipedia.org/wiki/Mitose', 'category' => 'Biologie', 'difficulty' => 'difficile'],

            // Informatique & Technologies
            ['title' => 'Algorithme de Dijkstra', 'url' => 'https://fr.wikipedia.org/wiki/Algorithme_de_Dijkstra', 'category' => 'Informatique', 'difficulty' => 'difficile'],
            ['title' => 'Apprentissage automatique', 'url' => 'https://fr.wikipedia.org/wiki/Apprentissage_automatique', 'category' => 'Informatique', 'difficulty' => 'difficile'],
            ['title' => 'Cryptographie', 'url' => 'https://fr.wikipedia.org/wiki/Cryptographie', 'category' => 'Informatique', 'difficulty' => 'difficile'],
            ['title' => 'Blockchain', 'url' => 'https://fr.wikipedia.org/wiki/Blockchain', 'category' => 'Technologie', 'difficulty' => 'difficile'],
            ['title' => 'Réseau de neurones', 'url' => 'https://fr.wikipedia.org/wiki/Réseau_de_neurones_artificiels', 'category' => 'Informatique', 'difficulty' => 'difficile'],

            // Mathématiques avancées
            ['title' => 'Topologie', 'url' => 'https://fr.wikipedia.org/wiki/Topologie', 'category' => 'Mathématiques', 'difficulty' => 'difficile'],
            ['title' => 'Analyse complexe', 'url' => 'https://fr.wikipedia.org/wiki/Analyse_complexe', 'category' => 'Mathématiques', 'difficulty' => 'difficile'],
            ['title' => 'Théorie des groupes', 'url' => 'https://fr.wikipedia.org/wiki/Théorie_des_groupes', 'category' => 'Mathématiques', 'difficulty' => 'difficile'],
            ['title' => 'Équation différentielle', 'url' => 'https://fr.wikipedia.org/wiki/Équation_différentielle', 'category' => 'Mathématiques', 'difficulty' => 'difficile'],
            ['title' => 'Théorie des nombres', 'url' => 'https://fr.wikipedia.org/wiki/Théorie_des_nombres', 'category' => 'Mathématiques', 'difficulty' => 'difficile'],

            // Chimie avancée
            ['title' => 'Chimie organique', 'url' => 'https://fr.wikipedia.org/wiki/Chimie_organique', 'category' => 'Chimie', 'difficulty' => 'difficile'],
            ['title' => 'Thermochimie', 'url' => 'https://fr.wikipedia.org/wiki/Thermochimie', 'category' => 'Chimie', 'difficulty' => 'difficile'],
            ['title' => 'Catalyse', 'url' => 'https://fr.wikipedia.org/wiki/Catalyse', 'category' => 'Chimie', 'difficulty' => 'difficile'],
            ['title' => 'Spectroscopie', 'url' => 'https://fr.wikipedia.org/wiki/Spectroscopie', 'category' => 'Chimie', 'difficulty' => 'difficile'],

            // Philosophie avancée
            ['title' => 'Épistémologie', 'url' => 'https://fr.wikipedia.org/wiki/Épistémologie', 'category' => 'Philosophie', 'difficulty' => 'difficile'],
            ['title' => 'Métaphysique', 'url' => 'https://fr.wikipedia.org/wiki/Métaphysique', 'category' => 'Philosophie', 'difficulty' => 'difficile'],
            ['title' => 'Phénoménologie', 'url' => 'https://fr.wikipedia.org/wiki/Phénoménologie', 'category' => 'Philosophie', 'difficulty' => 'difficile'],
            ['title' => 'Herméneutique', 'url' => 'https://fr.wikipedia.org/wiki/Herméneutique', 'category' => 'Philosophie', 'difficulty' => 'difficile'],

            // Médecine spécialisée
            ['title' => 'Neurologie', 'url' => 'https://fr.wikipedia.org/wiki/Neurologie', 'category' => 'Médecine', 'difficulty' => 'difficile'],
            ['title' => 'Oncologie', 'url' => 'https://fr.wikipedia.org/wiki/Oncologie', 'category' => 'Médecine', 'difficulty' => 'difficile'],
            ['title' => 'Cardiologie', 'url' => 'https://fr.wikipedia.org/wiki/Cardiologie', 'category' => 'Médecine', 'difficulty' => 'difficile'],
            ['title' => 'Immunologie', 'url' => 'https://fr.wikipedia.org/wiki/Immunologie', 'category' => 'Médecine', 'difficulty' => 'difficile'],
            ['title' => 'Endocrinologie', 'url' => 'https://fr.wikipedia.org/wiki/Endocrinologie', 'category' => 'Médecine', 'difficulty' => 'difficile'],

            // Économie & Sociologie
            ['title' => 'Microéconomie', 'url' => 'https://fr.wikipedia.org/wiki/Microéconomie', 'category' => 'Économie', 'difficulty' => 'difficile'],
            ['title' => 'Macroéconomie', 'url' => 'https://fr.wikipedia.org/wiki/Macroéconomie', 'category' => 'Économie', 'difficulty' => 'difficile'],
            ['title' => 'Sociologie', 'url' => 'https://fr.wikipedia.org/wiki/Sociologie', 'category' => 'Sciences sociales', 'difficulty' => 'difficile'],
            ['title' => 'Anthropologie', 'url' => 'https://fr.wikipedia.org/wiki/Anthropologie', 'category' => 'Sciences sociales', 'difficulty' => 'difficile'],

            // Psychologie
            ['title' => 'Psychanalyse', 'url' => 'https://fr.wikipedia.org/wiki/Psychanalyse', 'category' => 'Psychologie', 'difficulty' => 'difficile'],
            ['title' => 'Neuropsychologie', 'url' => 'https://fr.wikipedia.org/wiki/Neuropsychologie', 'category' => 'Psychologie', 'difficulty' => 'difficile'],
            ['title' => 'Cognitivisme', 'url' => 'https://fr.wikipedia.org/wiki/Cognitivisme', 'category' => 'Psychologie', 'difficulty' => 'difficile'],

            // Littérature & Arts avancés
            ['title' => 'Existentialisme', 'url' => 'https://fr.wikipedia.org/wiki/Existentialisme', 'category' => 'Philosophie', 'difficulty' => 'difficile'],
            ['title' => 'Structuralisme', 'url' => 'https://fr.wikipedia.org/wiki/Structuralisme', 'category' => 'Littérature', 'difficulty' => 'difficile'],
            ['title' => 'Déconstructivisme', 'url' => 'https://fr.wikipedia.org/wiki/Déconstructivisme', 'category' => 'Architecture', 'difficulty' => 'difficile'],

            // Géologie & Géophysique
            ['title' => 'Tectonique des plaques', 'url' => 'https://fr.wikipedia.org/wiki/Tectonique_des_plaques', 'category' => 'Géologie', 'difficulty' => 'difficile'],
            ['title' => 'Sismologie', 'url' => 'https://fr.wikipedia.org/wiki/Sismologie', 'category' => 'Géophysique', 'difficulty' => 'difficile'],
            ['title' => 'Volcanologie', 'url' => 'https://fr.wikipedia.org/wiki/Volcanologie', 'category' => 'Géologie', 'difficulty' => 'difficile'],
            ['title' => 'Minéralogie', 'url' => 'https://fr.wikipedia.org/wiki/Minéralogie', 'category' => 'Géologie', 'difficulty' => 'difficile'],

            // Astronomie avancée
            ['title' => 'Trou noir', 'url' => 'https://fr.wikipedia.org/wiki/Trou_noir', 'category' => 'Astronomie', 'difficulty' => 'difficile'],
            ['title' => 'Nébuleuse', 'url' => 'https://fr.wikipedia.org/wiki/Nébuleuse', 'category' => 'Astronomie', 'difficulty' => 'difficile'],
            ['title' => 'Supernova', 'url' => 'https://fr.wikipedia.org/wiki/Supernova', 'category' => 'Astronomie', 'difficulty' => 'difficile'],
            ['title' => 'Exoplanète', 'url' => 'https://fr.wikipedia.org/wiki/Exoplanète', 'category' => 'Astronomie', 'difficulty' => 'difficile']
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
