<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déploiement Pedantix</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .step { margin: 20px 0; padding: 15px; border-left: 4px solid #007cba; background: #f8f9fa; }
        .success { border-color: #28a745; background: #d4edda; }
        .error { border-color: #dc3545; background: #f8d7da; }
        .warning { border-color: #ffc107; background: #fff3cd; }
        button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .output { background: #000; color: #0f0; padding: 10px; border-radius: 5px; font-family: monospace; white-space: pre-wrap; max-height: 300px; overflow-y: auto; }
        .form-group { margin: 10px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="password"] { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>🚀 Déploiement Pedantix</h1>

    <div class="step warning">
        <h3>⚠️ Configuration requise</h3>
        <p>Avant de commencer, assurez-vous d'avoir :</p>
        <ul>
            <li>Uploadé tous les fichiers du projet dans ce dossier</li>
            <li>Configuré votre domaine pour pointer vers le dossier <code>public/</code></li>
            <li>Vos informations de base de données OVH</li>
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
            <button type="submit">💾 Sauvegarder la configuration</button>
        </form>
    </div>

    <div class="step" id="deploySection" style="display: none;">
        <h3>2. Déploiement automatique</h3>
        <button onclick="startDeployment()">🚀 Démarrer le déploiement</button>
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
        <p><strong>N'oubliez pas de supprimer ce fichier après le déploiement !</strong></p>
        <button onclick="window.location.href='/'">🎮 Aller à Pedantix</button>
    </div>

    <script>
        document.getElementById('configForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(e.target);
            const config = {
                db_host: document.getElementById('db_host').value,
                db_name: document.getElementById('db_name').value,
                db_user: document.getElementById('db_user').value,
                db_pass: document.getElementById('db_pass').value,
                domain: document.getElementById('domain').value
            };

            // Créer le fichier .env
            fetch('?action=save_config', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(config)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('deploySection').style.display = 'block';
                    alert('Configuration sauvegardée !');
                } else {
                    alert('Erreur : ' + data.error);
                }
            });
        });

        function startDeployment() {
            const output = document.getElementById('deployOutput');
            output.style.display = 'block';
            output.textContent = '🚀 Démarrage du déploiement...\n';

            fetch('?action=deploy')
                .then(response => response.text())
                .then(data => {
                    output.textContent += data;
                    if (data.includes('DÉPLOIEMENT RÉUSSI')) {
                        document.getElementById('successSection').style.display = 'block';
                    }
                })
                .catch(error => {
                    output.textContent += '\n❌ Erreur : ' + error;
                });
        }
    </script>
</body>
</html>

<?php
if ($_GET['action'] ?? '' === 'save_config') {
    $input = json_decode(file_get_contents('php://input'), true);

    $envContent = "APP_ENV=prod\n";
    $envContent .= "APP_SECRET=" . bin2hex(random_bytes(16)) . "\n";
    $envContent .= "APP_DEBUG=0\n\n";
    $envContent .= "DATABASE_URL=\"mysql://{$input['db_user']}:{$input['db_pass']}@{$input['db_host']}:3306/{$input['db_name']}?serverVersion=8.0&charset=utf8mb4\"\n\n";
    $envContent .= "TRUSTED_PROXIES=127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16\n";
    $envContent .= "TRUSTED_HOSTS='^(localhost|127\.0\.0\.1|" . preg_quote($input['domain'], '/') . ")$'\n";

    if (file_put_contents('.env', $envContent)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Impossible d\'écrire le fichier .env']);
    }
    exit;
}

if ($_GET['action'] ?? '' === 'deploy') {
    header('Content-Type: text/plain');

    echo "=== Installation de Composer ===\n";
    if (!file_exists('composer.phar')) {
        echo "Téléchargement de Composer...\n";
        file_put_contents('composer.phar', file_get_contents('https://getcomposer.org/composer.phar'));
        chmod('composer.phar', 0755);
    }
    echo "✅ Composer prêt\n\n";

    echo "=== Installation des dépendances ===\n";
    $output = shell_exec('php composer.phar install --no-dev --optimize-autoloader --no-interaction 2>&1');
    echo $output . "\n";

    echo "=== Déploiement complet ===\n";
    $output = shell_exec('php bin/console app:deploy prod 2>&1');
    echo $output . "\n";

    echo "=== Configuration des permissions ===\n";
    if (is_dir('var/cache')) {
        chmod('var/cache', 0775);
        echo "✅ Permissions var/cache configurées\n";
    }
    if (is_dir('var/log')) {
        chmod('var/log', 0775);
        echo "✅ Permissions var/log configurées\n";
    }

    echo "\n🎉 DÉPLOIEMENT RÉUSSI !\n";
    echo "Votre Pedantix est maintenant opérationnel !\n";
    exit;
}
?>
