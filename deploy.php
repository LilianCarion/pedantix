<?php
/**
 * Script de déploiement web automatique pour OVH
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
        // Étape 1: Créer le fichier .env automatiquement
        $steps[] = ['step' => 1, 'description' => 'Configuration automatique .env'];

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
        $steps[0]['success'] = true;

        // Étape 2: Installer Composer
        $steps[] = ['step' => 2, 'description' => 'Installation de Composer'];

        if (!file_exists('composer.phar')) {
            $composerContent = file_get_contents('https://getcomposer.org/composer.phar');
            if ($composerContent === false) {
                throw new Exception('Impossible de télécharger Composer');
            }
            file_put_contents('composer.phar', $composerContent);
            chmod('composer.phar', 0755);
        }
        $steps[1]['success'] = true;

        // Étape 3: Installer les dépendances
        $steps[] = ['step' => 3, 'description' => 'Installation des dépendances PHP'];

        $output = [];
        $returnCode = 0;
        exec('php composer.phar install --no-dev --optimize-autoloader --no-interaction 2>&1', $output, $returnCode);
        $steps[2]['success'] = $returnCode === 0;
        $steps[2]['output'] = implode("\n", $output);

        if (!$steps[2]['success']) {
            throw new Exception('Échec de l\'installation des dépendances');
        }

        // Étape 4: Déploiement complet
        $steps[] = ['step' => 4, 'description' => 'Déploiement complet (cache, DB, articles)'];

        $output = [];
        $returnCode = 0;
        exec('php bin/console app:deploy prod 2>&1', $output, $returnCode);
        $steps[3]['success'] = $returnCode === 0;
        $steps[3]['output'] = implode("\n", $output);

        if (!$steps[3]['success']) {
            throw new Exception('Échec du déploiement');
        }

        // Étape 5: Configuration des permissions
        $steps[] = ['step' => 5, 'description' => 'Configuration des permissions'];

        if (is_dir('var/cache')) chmod('var/cache', 0775);
        if (is_dir('var/log')) chmod('var/log', 0775);
        $steps[4]['success'] = true;

        // Étape 6: Test final et comptage des articles
        $steps[] = ['step' => 6, 'description' => 'Vérification finale'];

        $output = [];
        $returnCode = 0;
        exec('php bin/console debug:container --env=prod 2>&1', $output, $returnCode);
        $steps[5]['success'] = $returnCode === 0;

        // Compter les articles
        $articleCount = 0;
        try {
            $output = [];
            exec("php bin/console doctrine:query:sql 'SELECT COUNT(*) as count FROM wikipedia_article' --quiet 2>&1", $output, $returnCode);
            if ($returnCode === 0 && !empty($output)) {
                foreach ($output as $line) {
                    if (preg_match('/(\d+)/', $line, $matches)) {
                        $articleCount = (int)$matches[1];
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            // Ignorer les erreurs de comptage
        }

        if (!$steps[5]['success']) {
            throw new Exception('Test final échoué');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Déploiement réussi !',
            'data' => [
                'steps' => $steps,
                'article_count' => $articleCount,
                'domain' => $ovh_config['domain'],
                'next_steps' => [
                    'Votre Pedantix est maintenant opérationnel !',
                    'Configurez votre serveur web pour pointer vers public/',
                    'Supprimez ce fichier deploy.php pour la sécurité',
                    'Accédez à votre site via: http://' . $ovh_config['domain'],
                    "Articles Wikipedia en base: $articleCount"
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors du déploiement: ' . $e->getMessage(),
            'data' => ['steps' => $steps]
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
                                ${step.output ? '<pre style="font-size: 12px; margin-top: 10px;">' + step.output.substring(0, 500) + (step.output.length > 500 ? '...' : '') + '</pre>' : ''}
                            `;
                            stepsContainer.appendChild(stepDiv);
                        });
                    }

                    document.getElementById('result').innerHTML = `
                        <div class="alert alert-success">
                            <h3>✅ Déploiement réussi !</h3>
                            <p><strong>Articles en base :</strong> ${result.data.article_count || 0}</p>
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
                        </div>
                    `;

                    if (result.data && result.data.steps) {
                        result.data.steps.forEach(step => {
                            const stepDiv = document.createElement('div');
                            stepDiv.className = 'step ' + (step.success ? 'success' : 'error');
                            stepDiv.innerHTML = `
                                <strong>Étape ${step.step}:</strong> ${step.description}
                                ${step.output ? '<pre style="font-size: 12px;">' + step.output + '</pre>' : ''}
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
