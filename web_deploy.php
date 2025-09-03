<?php
/**
 * Interface de déploiement web pour Pedantix
 * À utiliser quand SSH n'est pas disponible sur le serveur OVH
 */

// Configuration de sécurité
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Vérifier que nous sommes bien sur le serveur (pas en local)
$isLocal = in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1']) ||
           strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0;

if ($isLocal) {
    die('⚠️ Ce script est destiné au serveur de production seulement.');
}

// Header pour JSON
header('Content-Type: application/json; charset=utf-8');

// Fonction pour retourner une réponse JSON
function jsonResponse($success, $message, $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fonction pour exécuter des commandes et capturer le résultat
function executeCommand($command, $description = '') {
    $output = [];
    $returnCode = 0;

    exec($command . ' 2>&1', $output, $returnCode);

    return [
        'success' => $returnCode === 0,
        'output' => implode("\n", $output),
        'command' => $command,
        'description' => $description
    ];
}

// Traitement POST pour le déploiement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lire les données JSON envoyées
    $input = file_get_contents('php://input');

    if (empty($input)) {
        jsonResponse(false, 'Aucune donnée reçue');
    }

    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(false, 'Données JSON invalides: ' . json_last_error_msg());
    }

    // Valider les données requises
    $required = ['db_host', 'db_name', 'db_user', 'db_pass', 'app_secret', 'domain'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            jsonResponse(false, "Le champ '$field' est requis");
        }
    }

    $steps = [];
    $allSuccess = true;

    try {
        // Étape 1: Créer le fichier .env
        $steps[] = ['step' => 1, 'description' => 'Configuration du fichier .env'];

        $envContent = "# Configuration générée automatiquement\n";
        $envContent .= "APP_ENV=prod\n";
        $envContent .= "APP_SECRET=" . $data['app_secret'] . "\n";
        $envContent .= "APP_DEBUG=0\n\n";
        $envContent .= "# Base de données\n";
        $envContent .= "DATABASE_URL=\"mysql://{$data['db_user']}:{$data['db_pass']}@{$data['db_host']}:3306/{$data['db_name']}?serverVersion=8.0&charset=utf8mb4\"\n\n";
        $envContent .= "# Configuration serveur\n";
        $envContent .= "TRUSTED_PROXIES=127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16\n";
        $envContent .= "TRUSTED_HOSTS='^(localhost|127\\.0\\.0\\.1|" . preg_quote($data['domain'], '/') . ")$'\n";

        if (file_put_contents('.env', $envContent) === false) {
            throw new Exception('Impossible de créer le fichier .env');
        }

        $steps[0]['success'] = true;

        // Étape 2: Vérifier/installer Composer
        $steps[] = ['step' => 2, 'description' => 'Installation de Composer'];

        if (!file_exists('composer.phar')) {
            $composerUrl = 'https://getcomposer.org/composer.phar';
            $composerContent = file_get_contents($composerUrl);
            if ($composerContent === false) {
                throw new Exception('Impossible de télécharger Composer');
            }
            file_put_contents('composer.phar', $composerContent);
            chmod('composer.phar', 0755);
        }

        $steps[1]['success'] = true;

        // Étape 3: Installer les dépendances
        $steps[] = ['step' => 3, 'description' => 'Installation des dépendances PHP'];

        $result = executeCommand('php composer.phar install --no-dev --optimize-autoloader --no-interaction');
        $steps[2]['success'] = $result['success'];
        $steps[2]['output'] = $result['output'];

        if (!$result['success']) {
            throw new Exception('Échec de l\'installation des dépendances: ' . $result['output']);
        }

        // Étape 4: Nettoyer le cache
        $steps[] = ['step' => 4, 'description' => 'Nettoyage du cache'];

        $result = executeCommand('php bin/console cache:clear --env=prod --no-debug');
        $steps[3]['success'] = $result['success'];
        $steps[3]['output'] = $result['output'];

        if (!$result['success']) {
            throw new Exception('Échec du nettoyage du cache: ' . $result['output']);
        }

        // Étape 5: Créer la base de données
        $steps[] = ['step' => 5, 'description' => 'Création de la base de données'];

        $result = executeCommand('php bin/console doctrine:database:create --if-not-exists --no-interaction');
        $steps[4]['success'] = $result['success'];
        $steps[4]['output'] = $result['output'];

        // Continuer même si la base existe déjà

        // Étape 6: Migrations
        $steps[] = ['step' => 6, 'description' => 'Exécution des migrations'];

        $result = executeCommand('php bin/console doctrine:migrations:migrate --no-interaction');
        $steps[5]['success'] = $result['success'];
        $steps[5]['output'] = $result['output'];

        if (!$result['success']) {
            throw new Exception('Échec des migrations: ' . $result['output']);
        }

        // Étape 7: Peupler avec les articles Wikipedia
        $steps[] = ['step' => 7, 'description' => 'Peuplement des articles Wikipedia'];

        $result = executeCommand('php bin/console app:seed-wikipedia-articles');
        $steps[6]['success'] = $result['success'];
        $steps[6]['output'] = $result['output'];

        if (!$result['success']) {
            // Continuer même si ça échoue, les articles peuvent être ajoutés manuellement
            $steps[6]['warning'] = 'Articles non chargés automatiquement - ils seront ajoutés lors de la première utilisation';
        }

        // Étape 8: Configuration des permissions
        $steps[] = ['step' => 8, 'description' => 'Configuration des permissions'];

        if (is_dir('var/cache')) {
            chmod('var/cache', 0775);
        }
        if (is_dir('var/log')) {
            chmod('var/log', 0775);
        }

        $steps[7]['success'] = true;

        // Étape 9: Test final
        $steps[] = ['step' => 9, 'description' => 'Test de l\'installation'];

        $result = executeCommand('php bin/console debug:container --env=prod');
        $steps[8]['success'] = $result['success'];
        $steps[8]['output'] = $result['output'];

        if (!$result['success']) {
            throw new Exception('Test final échoué: ' . $result['output']);
        }

        // Compter les articles en base
        $articleCount = 0;
        try {
            $result = executeCommand("php bin/console doctrine:query:sql 'SELECT COUNT(*) as count FROM wikipedia_article' --quiet");
            if ($result['success']) {
                preg_match('/(\d+)/', $result['output'], $matches);
                $articleCount = isset($matches[1]) ? (int)$matches[1] : 0;
            }
        } catch (Exception $e) {
            // Ignorer les erreurs de comptage
        }

        jsonResponse(true, 'Déploiement réussi !', [
            'steps' => $steps,
            'article_count' => $articleCount,
            'next_steps' => [
                'Votre Pedantix est maintenant opérationnel !',
                'Supprimez ce fichier web_deploy.php pour la sécurité',
                'Accédez à votre site via: https://' . $data['domain'],
                "Articles Wikipedia en base: $articleCount"
            ]
        ]);

    } catch (Exception $e) {
        $allSuccess = false;
        jsonResponse(false, 'Erreur lors du déploiement: ' . $e->getMessage(), [
            'steps' => $steps,
            'error_details' => $e->getTraceAsString()
        ]);
    }
}

// Interface HTML pour la configuration
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déploiement Pedantix</title>
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

        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }

        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }

        input:focus {
            border-color: #667eea;
            outline: none;
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

        .btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .progress {
            display: none;
            margin-top: 20px;
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

        .step.warning {
            background: #fff3cd;
            border-left-color: #ffc107;
        }

        .step.running {
            background: #d1ecf1;
            border-left-color: #17a2b8;
        }

        .help-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Déploiement Pedantix</h1>

        <div id="form-section">
            <p>Configurez votre installation Pedantix avec vos paramètres de serveur OVH.</p>

            <form id="deployForm">
                <div class="form-group">
                    <label for="db_host">Host de la base de données :</label>
                    <input type="text" id="db_host" name="db_host" value="localhost" required>
                    <div class="help-text">Généralement "localhost" pour OVH</div>
                </div>

                <div class="form-group">
                    <label for="db_name">Nom de la base de données :</label>
                    <input type="text" id="db_name" name="db_name" placeholder="votre_base_pedantix" required>
                    <div class="help-text">Le nom de votre base MySQL créée dans le panel OVH</div>
                </div>

                <div class="form-group">
                    <label for="db_user">Utilisateur de la base :</label>
                    <input type="text" id="db_user" name="db_user" placeholder="votre_user_ovh" required>
                    <div class="help-text">Votre nom d'utilisateur MySQL OVH</div>
                </div>

                <div class="form-group">
                    <label for="db_pass">Mot de passe de la base :</label>
                    <input type="password" id="db_pass" name="db_pass" placeholder="votre_password_ovh" required>
                    <div class="help-text">Votre mot de passe MySQL OVH</div>
                </div>

                <div class="form-group">
                    <label for="app_secret">Clé secrète de l'application :</label>
                    <input type="text" id="app_secret" name="app_secret" placeholder="Gén��rée automatiquement" required>
                    <div class="help-text">Une clé secrète unique pour sécuriser votre application</div>
                    <button type="button" onclick="generateSecret()" style="margin-top: 5px; padding: 5px 10px; font-size: 12px;">Générer une clé</button>
                </div>

                <div class="form-group">
                    <label for="domain">Votre nom de domaine :</label>
                    <input type="text" id="domain" name="domain" placeholder="votre-domaine.com" required>
                    <div class="help-text">Le nom de domaine de votre site (sans http://)</div>
                </div>

                <button type="submit" class="btn" id="deployBtn">
                    🚀 Démarrer le déploiement
                </button>
            </form>
        </div>

        <div id="progress" class="progress">
            <h3>Déploiement en cours...</h3>
            <div id="steps"></div>
        </div>

        <div id="result" style="display: none;"></div>
    </div>

    <script>
        // Générer une clé secrète aléatoire
        function generateSecret() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            let secret = '';
            for (let i = 0; i < 32; i++) {
                secret += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('app_secret').value = secret;
        }

        // Générer automatiquement une clé au chargement
        window.onload = function() {
            generateSecret();
        };

        // Gérer la soumission du formulaire
        document.getElementById('deployForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());

            // Valider les données
            for (const [key, value] of Object.entries(data)) {
                if (!value.trim()) {
                    alert('Veuillez remplir tous les champs');
                    return;
                }
            }

            // Cacher le formulaire et montrer le progrès
            document.getElementById('form-section').style.display = 'none';
            document.getElementById('progress').style.display = 'block';

            const stepsContainer = document.getElementById('steps');

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    // Afficher les étapes réussies
                    if (result.data.steps) {
                        result.data.steps.forEach(step => {
                            const stepDiv = document.createElement('div');
                            stepDiv.className = 'step ' + (step.success ? 'success' : (step.warning ? 'warning' : 'error'));
                            stepDiv.innerHTML = `
                                <strong>Étape ${step.step}:</strong> ${step.description}
                                ${step.output ? '<pre>' + step.output + '</pre>' : ''}
                                ${step.warning ? '<div style="color: orange;">⚠️ ' + step.warning + '</div>' : ''}
                            `;
                            stepsContainer.appendChild(stepDiv);
                        });
                    }

                    // Afficher le résultat final
                    document.getElementById('result').innerHTML = `
                        <div class="alert alert-success">
                            <h3>✅ Déploiement réussi !</h3>
                            <p><strong>Articles en base :</strong> ${result.data.article_count || 0}</p>
                            <h4>Prochaines étapes :</h4>
                            <ul>
                                ${result.data.next_steps.map(step => '<li>' + step + '</li>').join('')}
                            </ul>
                        </div>
                    `;
                } else {
                    // Afficher l'erreur
                    document.getElementById('result').innerHTML = `
                        <div class="alert alert-danger">
                            <h3>❌ Erreur de déploiement</h3>
                            <p>${result.message}</p>
                        </div>
                    `;

                    // Afficher les étapes partielles si disponibles
                    if (result.data && result.data.steps) {
                        result.data.steps.forEach(step => {
                            const stepDiv = document.createElement('div');
                            stepDiv.className = 'step ' + (step.success ? 'success' : 'error');
                            stepDiv.innerHTML = `
                                <strong>Étape ${step.step}:</strong> ${step.description}
                                ${step.output ? '<pre>' + step.output + '</pre>' : ''}
                            `;
                            stepsContainer.appendChild(stepDiv);
                        });
                    }
                }

                document.getElementById('result').style.display = 'block';

            } catch (error) {
                document.getElementById('result').innerHTML = `
                    <div class="alert alert-danger">
                        <h3>❌ Erreur de connexion</h3>
                        <p>Impossible de communiquer avec le serveur : ${error.message}</p>
                    </div>
                `;
                document.getElementById('result').style.display = 'block';
            }
        });
    </script>
</body>
</html>
