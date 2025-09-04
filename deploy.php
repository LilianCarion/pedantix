<?php
/**
 * Script de déploiement web automatique pour OVH avec gestion des versions PHP
 * À exécuter UNE SEULE FOIS après upload via navigateur web
 * URL: http://analantix.ovh/deploy.php
 */

// Configuration OVH pré-remplie
$ovh_config = [
    'db_host' => 'analanjroot.mysql.db',
    'db_name' => 'analanjroot',
    'db_user' => 'analanjroot',
    'db_pass' => 'Bulls071201',
    'domain' => 'analantix.ovh',
    'app_secret' => 'a9f2c8e1b5d7h3k6m9p2q8r4t7w1x5z8y2b6c9e3f7j4n8s1v5y9a2d6g3k7m1p4'
];

// Détecter le chemin PHP correct pour OVH
function findPhpPath() {
    $possiblePaths = [
        '/usr/local/php8.1/bin/php',
        '/usr/local/php8.2/bin/php',
        '/usr/local/php8.3/bin/php',
        '/usr/bin/php8.1',
        '/usr/bin/php8.2',
        '/usr/bin/php8.3',
        '/opt/alt/php81/usr/bin/php',
        '/opt/alt/php82/usr/bin/php',
        '/opt/alt/php83/usr/bin/php',
        'php8.1',
        'php8.2',
        'php8.3',
        'php'
    ];

    foreach ($possiblePaths as $path) {
        $output = [];
        $returnCode = 0;
        exec("$path --version 2>/dev/null", $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return $path;
        }
    }

    return 'php'; // Fallback
}

// Configurer les variables d'environnement pour Composer
function setupComposerEnvironment() {
    $homeDir = getcwd() . '/composer_home';

    // Créer le répertoire home pour Composer s'il n'existe pas
    if (!is_dir($homeDir)) {
        mkdir($homeDir, 0755, true);
    }

    // Définir les variables d'environnement
    putenv("HOME=$homeDir");
    putenv("COMPOSER_HOME=$homeDir");
    putenv("COMPOSER_CACHE_DIR=$homeDir/cache");

    return $homeDir;
}

// Exécuter une commande avec les bonnes variables d'environnement
function execWithEnv($command, $phpPath) {
    $homeDir = getcwd() . '/composer_home';

    // Construire la commande avec les variables d'environnement
    $envVars = [
        "HOME=$homeDir",
        "COMPOSER_HOME=$homeDir",
        "COMPOSER_CACHE_DIR=$homeDir/cache"
    ];

    $fullCommand = implode(' ', $envVars) . ' ' . $command;

    $output = [];
    $returnCode = 0;
    exec($fullCommand . ' 2>&1', $output, $returnCode);

    return [
        'success' => $returnCode === 0,
        'output' => implode("\n", $output),
        'return_code' => $returnCode
    ];
}

// Vérifier que nous sommes bien sur le serveur
$isLocal = in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1']) ||
           strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0;

if ($isLocal) {
    die('⚠️ Ce script est destiné au serveur de production seulement.');
}

// Traitement automatique
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['auto'])) {
    header('Content-Type: application/json; charset=utf-8');

    $steps = [];
    $allSuccess = true;

    try {
        // Configurer l'environnement Composer
        $homeDir = setupComposerEnvironment();

        // Détecter le chemin PHP
        $phpPath = findPhpPath();

        // Étape 1: Vérification de l'environnement
        $steps[] = ['step' => 1, 'description' => 'Détection de l\'environnement PHP et configuration Composer'];

        $output = [];
        $returnCode = 0;
        exec("$phpPath --version 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('PHP introuvable sur le serveur. Contactez votre hébergeur OVH.');
        }

        $phpVersion = implode("\n", $output);
        $steps[0]['success'] = true;
        $steps[0]['output'] = "PHP détecté: $phpPath\n$phpVersion\nComposer HOME: $homeDir";

        // Étape 2: Créer le fichier .env automatiquement
        $steps[] = ['step' => 2, 'description' => 'Configuration automatique .env'];

        $envContent = "# Configuration générée automatiquement pour OVH\n";
        $envContent .= "APP_ENV=prod\n";
        $envContent .= "APP_SECRET=" . $ovh_config['app_secret'] . "\n";
        $envContent .= "APP_DEBUG=0\n\n";
        $envContent .= "# Base de données OVH\n";
        $envContent .= "DATABASE_URL=\"mysql://{$ovh_config['db_user']}:{$ovh_config['db_pass']}@{$ovh_config['db_host']}:3306/{$ovh_config['db_name']}?serverVersion=8.0&charset=utf8mb4\"\n\n";
        $envContent .= "# Configuration serveur\n";
        $envContent .= "TRUSTED_PROXIES=127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16\n";
        $envContent .= "TRUSTED_HOSTS='^(localhost|127\\.0\\.0\\.1|" . preg_quote($ovh_config['domain'], '/') . ")$'\n";

        if (file_put_contents('.env', $envContent) === false) {
            throw new Exception('Impossible de créer le fichier .env');
        }
        $steps[1]['success'] = true;

        // Étape 3: Installer Composer avec le bon chemin PHP
        $steps[] = ['step' => 3, 'description' => 'Installation de Composer avec PHP détecté'];

        if (!file_exists('composer.phar')) {
            $composerContent = file_get_contents('https://getcomposer.org/composer.phar');
            if ($composerContent === false) {
                throw new Exception('Impossible de télécharger Composer');
            }
            file_put_contents('composer.phar', $composerContent);
            chmod('composer.phar', 0755);
        }
        $steps[2]['success'] = true;

        // Étape 4: Nettoyer les anciennes dépendances et corriger les configurations
        $steps[] = ['step' => 4, 'description' => 'Nettoyage complet et correction des configurations Symfony 6.4'];

        // Supprimer composer.lock pour forcer la résolution avec les nouvelles versions
        if (file_exists('composer.lock')) {
            unlink('composer.lock');
        }

        // Supprimer le dossier vendor pour une installation propre
        if (is_dir('vendor')) {
            $result = execWithEnv("rm -rf vendor", $phpPath);
        }

        // Supprimer le cache pour éviter les conflits
        if (is_dir('var/cache')) {
            $result = execWithEnv("rm -rf var/cache/*", $phpPath);
        }

        // Corriger la configuration CSRF si nécessaire
        $csrfFile = 'config/packages/csrf.yaml';
        if (file_exists($csrfFile)) {
            $csrfContent = file_get_contents($csrfFile);
            if (strpos($csrfContent, 'token_id') !== false || strpos($csrfContent, 'stateless_token_ids') !== false) {
                $newCsrfContent = "framework:\n    csrf_protection:\n        enabled: true\n";
                file_put_contents($csrfFile, $newCsrfContent);
            }
        }

        // Corriger la configuration property_info
        $propertyInfoFile = 'config/packages/property_info.yaml';
        $propertyInfoContent = "framework:\n    property_info:\n        enabled: true\n";
        file_put_contents($propertyInfoFile, $propertyInfoContent);

        // Corriger la configuration framework
        $frameworkFile = 'config/packages/framework.yaml';
        $frameworkContent = "framework:\n    secret: '%env(APP_SECRET)%'\n    http_method_override: false\n    handle_all_throwables: true\n    trusted_hosts: '%env(TRUSTED_HOSTS)%'\n    trusted_proxies: '%env(TRUSTED_PROXIES)%'\n    session:\n        handler_id: null\n        cookie_secure: auto\n        cookie_samesite: lax\n        storage_factory_id: session.storage.factory.native\n    php_errors:\n        log: true\n    cache:\n        app: cache.adapter.filesystem\n        system: cache.adapter.system\n";
        file_put_contents($frameworkFile, $frameworkContent);

        // Corriger la configuration doctrine
        $doctrineFile = 'config/packages/doctrine.yaml';
        $doctrineContent = "doctrine:\n    dbal:\n        url: '%env(resolve:DATABASE_URL)%'\n        charset: utf8mb4\n        default_table_options:\n            charset: utf8mb4\n            collate: utf8mb4_unicode_ci\n    orm:\n        auto_generate_proxy_classes: true\n        enable_lazy_ghost_objects: true\n        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware\n        auto_mapping: true\n        mappings:\n            App:\n                is_bundle: false\n                type: attribute\n                dir: '%kernel.project_dir%/src/Entity'\n                prefix: 'App\\Entity'\n                alias: App\n";
        file_put_contents($doctrineFile, $doctrineContent);

        // Corriger la configuration security
        $securityFile = 'config/packages/security.yaml';
        $securityContent = "security:\n    password_hashers:\n        Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface: 'auto'\n    providers:\n        users_in_memory: { memory: null }\n    firewalls:\n        dev:\n            pattern: ^/(_(profiler|wdt)|css|images|js)/\n            security: false\n        main:\n            lazy: true\n            provider: users_in_memory\n    access_control: []\n";
        file_put_contents($securityFile, $securityContent);

        // Corriger la configuration twig
        $twigFile = 'config/packages/twig.yaml';
        $twigContent = "twig:\n    default_path: '%kernel.project_dir%/templates'\n    form_themes: ['bootstrap_5_layout.html.twig']\n";
        file_put_contents($twigFile, $twigContent);

        // Corriger la configuration validator
        $validatorFile = 'config/packages/validator.yaml';
        $validatorContent = "framework:\n    validation:\n        email_validation_mode: html5\n        not_compromised_password: false\n";
        file_put_contents($validatorFile, $validatorContent);

        // Corriger la configuration cache
        $cacheFile = 'config/packages/cache.yaml';
        $cacheContent = "framework:\n    cache:\n        app: cache.adapter.filesystem\n        system: cache.adapter.system\n";
        file_put_contents($cacheFile, $cacheContent);

        // Corriger la configuration routing
        $routingFile = 'config/packages/routing.yaml';
        $routingContent = "framework:\n    router:\n        utf8: true\n        strict_requirements: null\n";
        file_put_contents($routingFile, $routingContent);

        // Corriger la configuration doctrine_migrations
        $migrationsFile = 'config/packages/doctrine_migrations.yaml';
        $migrationsContent = "doctrine_migrations:\n    migrations_paths:\n        'DoctrineMigrations': '%kernel.project_dir%/migrations'\n    enable_profiler: false\n";
        file_put_contents($migrationsFile, $migrationsContent);

        // Installer avec les nouvelles contraintes de version (Symfony 6.4)
        $result = execWithEnv("$phpPath composer.phar update --no-dev --optimize-autoloader --no-interaction --with-all-dependencies", $phpPath);
        $steps[3]['success'] = $result['success'];
        $steps[3]['output'] = $result['output'];

        if (!$result['success']) {
            // Essayer avec install si update échoue
            $result2 = execWithEnv("$phpPath composer.phar install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=php", $phpPath);
            if ($result2['success']) {
                $steps[3]['success'] = true;
                $steps[3]['output'] .= "\n\nSecond essai avec install réussi:\n" . $result2['output'];
            } else {
                throw new Exception('Échec de l\'installation des dépendances: ' . $result['output']);
            }
        }

        // Étape 5: Créer la base de données
        $steps[] = ['step' => 5, 'description' => 'Configuration de la base de données'];

        $result = execWithEnv("$phpPath bin/console doctrine:database:create --if-not-exists --no-interaction", $phpPath);
        $steps[4]['success'] = $result['success'];
        $steps[4]['output'] = $result['output'];

        // Continuer même si la base existe déjà

        // Étape 6: Gestion intelligente des migrations avec correction des colonnes manquantes
        $steps[] = ['step' => 6, 'description' => 'Migrations et vérification du schéma de base'];

        // Vérifier si les tables existent et s'il manque des colonnes
        $result = execWithEnv("$phpPath bin/console doctrine:schema:update --dump-sql", $phpPath);
        $schemaDiff = $result['output'];

        if (strpos($schemaDiff, 'ALTER TABLE') !== false || strpos($schemaDiff, 'CREATE TABLE') !== false) {
            // Il y a des changements de schéma à appliquer
            $result = execWithEnv("$phpPath bin/console doctrine:schema:update --force", $phpPath);
            $steps[5]['success'] = $result['success'];
            $steps[5]['output'] = "Mise à jour du schéma automatique:\n" . $result['output'];

            if (!$result['success']) {
                throw new Exception('Échec de la mise à jour du schéma: ' . $result['output']);
            }
        } else {
            // Essayer les migrations normales
            $result = execWithEnv("$phpPath bin/console doctrine:migrations:migrate --no-interaction", $phpPath);
            $steps[5]['success'] = $result['success'];
            $steps[5]['output'] = $result['output'];

            if (!$result['success']) {
                // Si les migrations échouent, marquer toutes comme exécutées et forcer la mise à jour du schéma
                if (strpos($result['output'], 'already exists') !== false || strpos($result['output'], 'previously executed') !== false) {
                    $result2 = execWithEnv("$phpPath bin/console doctrine:migrations:version --add --all --no-interaction", $phpPath);
                    $result3 = execWithEnv("$phpPath bin/console doctrine:schema:update --force", $phpPath);
                    $steps[5]['success'] = $result3['success'];
                    $steps[5]['output'] .= "\n\nSynchronisation et mise à jour forcée du schéma:\n" . $result2['output'] . "\n" . $result3['output'];
                } else {
                    throw new Exception('Échec des migrations: ' . $result['output']);
                }
            }
        }

        // Vérification finale du schéma
        $result = execWithEnv("$phpPath bin/console doctrine:schema:validate --no-interaction", $phpPath);
        if ($result['success']) {
            $steps[5]['output'] .= "\n✅ Schéma de base de données validé et synchronisé";
        }

        // Étape 7: Nettoyer le cache
        $steps[] = ['step' => 7, 'description' => 'Nettoyage du cache'];

        $result = execWithEnv("$phpPath bin/console cache:clear --env=prod --no-debug", $phpPath);
        $steps[6]['success'] = $result['success'];
        $steps[6]['output'] = $result['output'];

        if (!$result['success']) {
            throw new Exception('Échec du nettoyage du cache: ' . $result['output']);
        }

        // Étape 8: Peupler avec les articles Wikipedia
        $steps[] = ['step' => 8, 'description' => 'Peuplement des articles Wikipedia'];

        $result = execWithEnv("$phpPath bin/console app:seed-wikipedia-articles", $phpPath);
        $steps[7]['success'] = $result['success'];
        $steps[7]['output'] = $result['output'];

        if (!$result['success']) {
            // Continuer même si ça échoue, les articles peuvent être ajoutés manuellement
            $steps[7]['warning'] = 'Articles non chargés automatiquement - ils seront ajoutés lors de la première utilisation';
        }

        // Étape 9: Configuration des permissions
        $steps[] = ['step' => 9, 'description' => 'Configuration des permissions'];

        if (is_dir('var/cache')) {
            chmod('var/cache', 0775);
        }
        if (is_dir('var/log')) {
            chmod('var/log', 0775);
        }

        $steps[8]['success'] = true;

        // Étape 10: Test final
        $steps[] = ['step' => 10, 'description' => 'Test de l\'installation'];

        $result = execWithEnv("$phpPath bin/console debug:container --env=prod", $phpPath);
        $steps[9]['success'] = $result['success'];
        $steps[9]['output'] = $result['output'];

        if (!$result['success']) {
            throw new Exception('Test final échoué: ' . $result['output']);
        }

        // Compter les articles en base
        $articleCount = 0;
        try {
            $result = execWithEnv("$phpPath bin/console doctrine:query:sql 'SELECT COUNT(*) as count FROM wikipedia_article' --quiet", $phpPath);
            if ($result['success'] && !empty($result['output'])) {
                preg_match('/(\d+)/', $result['output'], $matches);
                $articleCount = isset($matches[1]) ? (int)$matches[1] : 0;
            }
        } catch (Exception $e) {
            // Ignorer les erreurs de comptage
        }

        echo json_encode([
            'success' => true,
            'message' => 'Déploiement réussi avec Symfony 6.4 LTS !',
            'data' => [
                'steps' => $steps,
                'article_count' => $articleCount,
                'php_path' => $phpPath,
                'composer_home' => $homeDir,
                'symfony_version' => '6.4 LTS (compatible PHP 8.1)',
                'domain' => $ovh_config['domain'],
                'next_steps' => [
                    'Votre Pedantix est maintenant opérationnel !',
                    'Version: Symfony 6.4 LTS compatible PHP 8.1',
                    'Configurez votre serveur web pour pointer vers public/',
                    'Supprimez ce fichier deploy.php pour la sécurité',
                    'Accédez à votre site via: http://' . $ovh_config['domain'],
                    "Articles Wikipedia en base: $articleCount",
                    "PHP utilisé: $phpPath"
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors du déploiement: ' . $e->getMessage(),
            'data' => ['steps' => $steps, 'php_path' => $phpPath ?? 'non détecté', 'composer_home' => $homeDir ?? 'non configuré']
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Interface HTML
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déploiement Pedantix OVH</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .container {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        h1 { color: #333; text-align: center; margin-bottom: 30px; }

        .config-display {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .step {
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
            border-left: 4px solid #ccc;
        }

        .step.success {
            background: #d4edda;
            border-left-color: #28a745;
        }

        .step.error {
            background: #f8d7da;
            border-left-color: #dc3545;
        }

        .alert {
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        #progress { display: none; margin-top: 20px; }
        #result { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Déploiement Pedantix OVH</h1>

        <div id="form-section">
            <p><strong>Configuration automatique détectée :</strong></p>

            <div class="config-display">
                <h4>📋 Paramètres OVH configurés :</h4>
                <ul>
                    <li><strong>Base de données :</strong> <?php echo $ovh_config['db_name']; ?></li>
                    <li><strong>Host :</strong> <?php echo $ovh_config['db_host']; ?></li>
                    <li><strong>Utilisateur :</strong> <?php echo $ovh_config['db_user']; ?></li>
                    <li><strong>Domaine :</strong> <?php echo $ovh_config['domain']; ?></li>
                </ul>

                <div class="alert alert-info">
                    <h5>🔧 Détection automatique du PHP</h5>
                    <p>Le script détectera automatiquement le bon chemin PHP sur votre serveur OVH (php8.1, php8.2, etc.)</p>
                </div>
            </div>

            <p>Cliquez sur le bouton ci-dessous pour démarrer le déploiement automatique. Toute la configuration est déjà prête !</p>

            <button onclick="startDeployment()" class="btn" id="deployBtn">
                🚀 Démarrer le déploiement automatique
            </button>
        </div>

        <div id="progress">
            <h3>Déploiement en cours...</h3>
            <div id="steps"></div>
        </div>

        <div id="result"></div>
    </div>

    <script>
        function startDeployment() {
            document.getElementById('form-section').style.display = 'none';
            document.getElementById('progress').style.display = 'block';

            const stepsContainer = document.getElementById('steps');

            fetch(window.location.href + '?auto=1', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Afficher les étapes réussies
                    if (result.data.steps) {
                        result.data.steps.forEach(step => {
                            const stepDiv = document.createElement('div');
                            stepDiv.className = 'step ' + (step.success ? 'success' : 'error');
                            stepDiv.innerHTML = `
                                <strong>Étape ${step.step}:</strong> ${step.description}
                                ${step.output ? '<pre style="font-size: 12px; margin-top: 10px; max-height: 200px; overflow-y: auto;">' + step.output.substring(0, 1000) + (step.output.length > 1000 ? '...' : '') + '</pre>' : ''}
                                ${step.warning ? '<div style="color: orange;">⚠️ ' + step.warning + '</div>' : ''}
                            `;
                            stepsContainer.appendChild(stepDiv);
                        });
                    }

                    document.getElementById('result').innerHTML = `
                        <div class="alert alert-success">
                            <h3>✅ Déploiement réussi !</h3>
                            <p><strong>Articles en base :</strong> ${result.data.article_count || 0}</p>
                            <p><strong>PHP utilisé :</strong> ${result.data.php_path || 'Détecté automatiquement'}</p>
                            <p><strong>URL :</strong> <a href="http://${result.data.domain}" target="_blank">http://${result.data.domain}</a></p>
                            <h4>Prochaines étapes :</h4>
                            <ul>
                                ${result.data.next_steps.map(step => '<li>' + step + '</li>').join('')}
                            </ul>
                        </div>
                    `;
                } else {
                    document.getElementById('result').innerHTML = `
                        <div class="alert alert-danger">
                            <h3>❌ Erreur de déploiement</h3>
                            <p>${result.message}</p>
                            ${result.data.php_path ? '<p><strong>PHP détecté :</strong> ' + result.data.php_path + '</p>' : ''}
                        </div>
                    `;

                    if (result.data && result.data.steps) {
                        result.data.steps.forEach(step => {
                            const stepDiv = document.createElement('div');
                            stepDiv.className = 'step ' + (step.success ? 'success' : 'error');
                            stepDiv.innerHTML = `
                                <strong>Étape ${step.step}:</strong> ${step.description}
                                ${step.output ? '<pre style="font-size: 12px; max-height: 200px; overflow-y: auto;">' + step.output + '</pre>' : ''}
                            `;
                            stepsContainer.appendChild(stepDiv);
                        });
                    }
                }

                document.getElementById('result').style.display = 'block';

            })
            .catch(error => {
                document.getElementById('result').innerHTML = `
                    <div class="alert alert-danger">
                        <h3>❌ Erreur de connexion</h3>
                        <p>Impossible de communiquer avec le serveur : ${error.message}</p>
                    </div>
                `;
                document.getElementById('result').style.display = 'block';
            });
        }
    </script>
</body>
</html>
