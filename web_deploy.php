<?php
// Désactiver l'affichage des erreurs HTML et forcer JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Headers pour forcer JSON
header('Content-Type: application/json');

// Fonction pour envoyer une réponse JSON propre
function sendJsonResponse($data) {
    echo json_encode($data);
    exit;
}

// Fonction pour gérer les erreurs et envoyer JSON
function handleError($message) {
    sendJsonResponse(['success' => false, 'error' => $message]);
}

// Gestion des requêtes AJAX
if ($_GET['action'] ?? '' === 'save_config') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            handleError('Données JSON invalides');
        }

        // Validation des données
        $required = ['db_host', 'db_name', 'db_user', 'db_pass', 'domain'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                handleError("Le champ {$field} est requis");
            }
        }

        $envContent = "APP_ENV=prod\n";
        $envContent .= "APP_SECRET=" . bin2hex(random_bytes(16)) . "\n";
        $envContent .= "APP_DEBUG=0\n\n";
        $envContent .= "DATABASE_URL=\"mysql://{$input['db_user']}:{$input['db_pass']}@{$input['db_host']}:3306/{$input['db_name']}?serverVersion=8.0&charset=utf8mb4\"\n\n";
        $envContent .= "TRUSTED_PROXIES=127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16\n";
        $envContent .= "TRUSTED_HOSTS='^(localhost|127\.0\.0\.1|" . preg_quote($input['domain'], '/') . ")$'\n";

        if (file_put_contents('.env', $envContent)) {
            sendJsonResponse(['success' => true, 'message' => 'Configuration sauvegardée']);
        } else {
            handleError('Impossible d\'écrire le fichier .env - vérifiez les permissions');
        }
    } catch (Exception $e) {
        handleError('Erreur lors de la sauvegarde : ' . $e->getMessage());
    }
}

if ($_GET['action'] ?? '' === 'deploy') {
    try {
        header('Content-Type: text/plain');

        echo "=== Vérification de l'environnement ===\n";

        // Vérifier PHP
        echo "Version PHP: " . PHP_VERSION . "\n";

        // Vérifier le fichier .env
        if (!file_exists('.env')) {
            echo "❌ Fichier .env manquant\n";
            exit;
        }
        echo "✅ Fichier .env trouvé\n";

        echo "\n=== Installation de Composer ===\n";
        if (!file_exists('composer.phar')) {
            echo "Téléchargement de Composer...\n";
            $composerData = file_get_contents('https://getcomposer.org/composer.phar');
            if ($composerData === false) {
                echo "❌ Impossible de télécharger Composer\n";
                exit;
            }
            file_put_contents('composer.phar', $composerData);
            chmod('composer.phar', 0755);
        }
        echo "✅ Composer prêt\n\n";

        echo "=== Installation des dépendances ===\n";
        $output = [];
        $return_var = 0;
        exec('php composer.phar install --no-dev --optimize-autoloader --no-interaction 2>&1', $output, $return_var);

        foreach ($output as $line) {
            echo $line . "\n";
        }

        if ($return_var !== 0) {
            echo "❌ Erreur lors de l'installation des dépendances\n";
            exit;
        }
        echo "✅ Dépendances installées\n\n";

        echo "=== Déploiement automatique ===\n";
        $output = [];
        $return_var = 0;
        exec('php bin/console app:deploy prod 2>&1', $output, $return_var);

        foreach ($output as $line) {
            echo $line . "\n";
        }

        if ($return_var !== 0) {
            echo "⚠️ Avertissement lors du déploiement (code: {$return_var})\n";
        } else {
            echo "✅ Déploiement réussi\n";
        }

        echo "\n=== Configuration des permissions ===\n";
        if (is_dir('var/cache')) {
            chmod('var/cache', 0775);
            echo "✅ Permissions var/cache configurées\n";
        }
        if (is_dir('var/log')) {
            chmod('var/log', 0775);
            echo "✅ Permissions var/log configurées\n";
        }

        echo "\n🎉 DÉPLOIEMENT TERMINÉ !\n";
        echo "Votre Pedantix est maintenant opérationnel !\n";
        echo "\n📋 Prochaines étapes :\n";
        echo "1. Supprimez ce fichier web_deploy.php\n";
        echo "2. Vérifiez que votre domaine pointe vers public/\n";
        echo "3. Testez votre application\n";

    } catch (Exception $e) {
        echo "\n❌ ERREUR : " . $e->getMessage() . "\n";
    }
    exit;
}

// Si ce n'est pas une requête AJAX, afficher l'interface
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déploiement Pedantix</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .step { margin: 20px 0; padding: 15px; border-left: 4px solid #007cba; background: #f8f9fa; border-radius: 5px; }
        .success { border-color: #28a745; background: #d4edda; }
        .error { border-color: #dc3545; background: #f8d7da; }
        .warning { border-color: #ffc107; background: #fff3cd; }
        button { background: #007cba; color: white; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        button:hover { background: #0056b3; }
        button:disabled { background: #ccc; cursor: not-allowed; }
        .output { background: #000; color: #0f0; padding: 15px; border-radius: 5px; font-family: monospace; white-space: pre-wrap; max-height: 400px; overflow-y: auto; font-size: 14px; }
        .form-group { margin: 15px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
        .loading { display: none; }
        .icon { margin-right: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>���� Déploiement Pedantix</h1>

        <div class="step warning">
            <h3>⚠️ Prérequis</h3>
            <p>Avant de commencer, vérifiez que :</p>
            <ul>
                <li>✅ Tous les fichiers du projet sont uploadés</li>
                <li>✅ Votre domaine pointe vers le dossier <code>public/</code></li>
                <li>✅ PHP 8.1+ est activé</li>
                <li>✅ L'extension MySQL est activée</li>
            </ul>
        </div>

        <div class="step">
            <h3>1. Configuration de la base de données</h3>
            <form id="configForm">
                <div class="form-group">
                    <label for="db_host">Host de base de données :</label>
                    <input type="text" id="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label for="db_name">Nom de la base de données :</label>
                    <input type="text" id="db_name" placeholder="pedantix_prod" required>
                </div>
                <div class="form-group">
                    <label for="db_user">Utilisateur base de données :</label>
                    <input type="text" id="db_user" required>
                </div>
                <div class="form-group">
                    <label for="db_pass">Mot de passe base de données :</label>
                    <input type="password" id="db_pass" required>
                </div>
                <div class="form-group">
                    <label for="domain">Votre nom de domaine :</label>
                    <input type="text" id="domain" placeholder="votre-domaine.com" required>
                </div>
                <button type="submit" id="saveBtn">
                    <span class="icon">💾</span>Sauvegarder la configuration
                </button>
            </form>
        </div>

        <div class="step" id="deploySection" style="display: none;">
            <h3>2. Déploiement automatique</h3>
            <button onclick="startDeployment()" id="deployBtn">
                <span class="icon">🚀</span>Démarrer le déploiement
            </button>
            <div id="deployOutput" class="output" style="display: none;"></div>
        </div>

        <div class="step success" id="successSection" style="display: none;">
            <h3>✅ Déploiement terminé !</h3>
            <p>Votre Pedantix est maintenant opérationnel avec :</p>
            <ul>
                <li>Plus de 150 articles Wikipedia</li>
                <li>Modes Compétition et Coopération</li>
                <li>Nouvelles parties automatiques</li>
                <li>Ajout automatique d'articles depuis les URL</li>
            </ul>
            <p><strong>⚠️ Important :</strong> Supprimez ce fichier <code>web_deploy.php</code> après le déploiement !</p>
            <button onclick="testApplication()">
                <span class="icon">🎮</span>Tester Pedantix
            </button>
        </div>
    </div>

    <script>
        document.getElementById('configForm').addEventListener('submit', function(e) {
            e.preventDefault();

            console.log('🔧 Début de la sauvegarde de configuration');

            const config = {
                db_host: document.getElementById('db_host').value.trim(),
                db_name: document.getElementById('db_name').value.trim(),
                db_user: document.getElementById('db_user').value.trim(),
                db_pass: document.getElementById('db_pass').value.trim(),
                domain: document.getElementById('domain').value.trim()
            };

            // Validation côté client
            for (const [key, value] of Object.entries(config)) {
                if (!value) {
                    alert(`Le champ ${key} est requis`);
                    return;
                }
            }

            const submitBtn = document.getElementById('saveBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="icon">⏳</span>Sauvegarde en cours...';

            fetch('?action=save_config', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(config)
            })
            .then(response => {
                console.log('📡 Réponse reçue:', response);

                if (!response.ok) {
                    throw new Error(`Erreur HTTP: ${response.status} - ${response.statusText}`);
                }

                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Réponse non-JSON reçue. Vérifiez les erreurs PHP.');
                }

                return response.json();
            })
            .then(data => {
                console.log('✅ Données reçues:', data);

                if (data.success) {
                    document.getElementById('deploySection').style.display = 'block';
                    alert('✅ Configuration sauvegardée avec succès !');
                } else {
                    alert('❌ Erreur : ' + (data.error || 'Erreur inconnue'));
                }
            })
            .catch(error => {
                console.error('❌ Erreur complète:', error);
                alert('❌ Erreur de connexion : ' + error.message + '\n\nVérifiez la console du navigateur pour plus de détails.');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        function startDeployment() {
            console.log('🚀 Début du déploiement');

            const output = document.getElementById('deployOutput');
            const deployBtn = document.getElementById('deployBtn');

            output.style.display = 'block';
            output.textContent = '🚀 Démarrage du déploiement...\n';

            deployBtn.disabled = true;
            deployBtn.innerHTML = '<span class="icon">⏳</span>Déploiement en cours...';

            fetch('?action=deploy', {
                method: 'GET',
                headers: {
                    'Accept': 'text/plain'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Erreur HTTP: ${response.status}`);
                }
                return response.text();
            })
            .then(data => {
                output.textContent = data;

                if (data.includes('DÉPLOIEMENT TERMINÉ')) {
                    document.getElementById('successSection').style.display = 'block';
                    deployBtn.innerHTML = '<span class="icon">✅</span>Déploiement terminé';
                } else {
                    deployBtn.disabled = false;
                    deployBtn.innerHTML = '<span class="icon">🚀</span>Réessayer le déploiement';
                }
            })
            .catch(error => {
                console.error('❌ Erreur de déploiement:', error);
                output.textContent += '\n❌ Erreur : ' + error.message;
                deployBtn.disabled = false;
                deployBtn.innerHTML = '<span class="icon">🚀</span>Réessayer le déploiement';
            });
        }

        function testApplication() {
            window.open('/', '_blank');
        }
    </script>
</body>
</html>

