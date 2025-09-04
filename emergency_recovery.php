<?php
// Script de récupération d'urgence pour serveur OVH
// À placer dans le dossier racine et exécuter via navigateur

// Forcer l'interprétation PHP
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Configuration OVH (VOS VRAIES DONNÉES)
$config = [
    'db_host' => 'analanjroot.mysql.db',
    'db_name' => 'analanjroot',
    'db_user' => 'analanjroot',
    'db_pass' => 'Bulls071201',
    'domain' => 'analantix.ovh',
    'app_secret' => 'a9f2c8e1b5d7h3k6m9p2q8r4t7w1x5z8y2b6c9e3f7j4n8s1v5y9a2d6g3k7m1p4'
];

?>
<!DOCTYPE html>
<html>
<head>
    <title>🚑 Récupération d'urgence - Pedantix</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #0056b3; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🚑 Récupération d'urgence Pedantix</h1>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])): ?>

        <?php if ($_POST['action'] === 'create_env'): ?>
            <h2>1. Création du fichier .env</h2>
            <?php
            $envContent = "# Configuration générée automatiquement pour récupération d'urgence\n";
            $envContent .= "APP_ENV=prod\n";
            $envContent .= "APP_SECRET=" . $config['app_secret'] . "\n";
            $envContent .= "APP_DEBUG=0\n\n";
            $envContent .= "# Base de données OVH\n";
            $envContent .= "DATABASE_URL=\"mysql://{$config['db_user']}:{$config['db_pass']}@{$config['db_host']}:3306/{$config['db_name']}?serverVersion=8.0&charset=utf8mb4\"\n\n";
            $envContent .= "# Configuration serveur\n";
            $envContent .= "TRUSTED_PROXIES=127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16\n";
            $envContent .= "TRUSTED_HOSTS='^(localhost|127\\.0\\.0\\.1|analantix\\.ovh)$'\n";

            if (file_put_contents('.env', $envContent) !== false) {
                echo '<div class="success">✅ Fichier .env créé avec succès !</div>';
                echo '<pre>' . htmlspecialchars($envContent) . '</pre>';
            } else {
                echo '<div class="error">❌ Impossible de créer le fichier .env</div>';
            }
            ?>

        <?php elseif ($_POST['action'] === 'check_files'): ?>
            <h2>2. Vérification des fichiers</h2>
            <?php
            $files = [
                '.env' => 'Fichier de configuration',
                'public/index.php' => 'Point d\'entrée Symfony',
                'vendor/autoload.php' => 'Autoloader Composer',
                'bin/console' => 'Console Symfony',
                'composer.json' => 'Configuration Composer'
            ];

            foreach ($files as $file => $description) {
                if (file_exists($file)) {
                    echo "<div class='success'>✅ $description : $file</div>";
                } else {
                    echo "<div class='error'>❌ MANQUANT : $description : $file</div>";
                }
            }
            ?>

        <?php elseif ($_POST['action'] === 'fix_permissions'): ?>
            <h2>3. Correction des permissions</h2>
            <?php
            $directories = ['var/cache', 'var/log', 'public'];
            foreach ($directories as $dir) {
                if (is_dir($dir)) {
                    chmod($dir, 0755);
                    echo "<div class='success'>✅ Permissions corrigées pour $dir</div>";
                } else {
                    echo "<div class='error'>❌ Répertoire manquant : $dir</div>";
                }
            }
            ?>

        <?php elseif ($_POST['action'] === 'create_htaccess'): ?>
            <h2>4. Création du .htaccess</h2>
            <?php
            $htaccessContent = "# Configuration .htaccess pour Pedantix sur OVH\n\n";
            $htaccessContent .= "# Redirection vers le dossier public si pas déjà dedans\n";
            $htaccessContent .= "RewriteEngine On\n";
            $htaccessContent .= "RewriteCond %{REQUEST_URI} !^/public/\n";
            $htaccessContent .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
            $htaccessContent .= "RewriteCond %{REQUEST_FILENAME} !-d\n";
            $htaccessContent .= "RewriteRule ^(.*)$ /public/\$1 [L]\n\n";
            $htaccessContent .= "# Configuration PHP pour OVH\n";
            $htaccessContent .= "php_value memory_limit 256M\n";
            $htaccessContent .= "php_value max_execution_time 60\n";

            if (file_put_contents('.htaccess', $htaccessContent) !== false) {
                echo '<div class="success">✅ Fichier .htaccess créé !</div>';
                echo '<pre>' . htmlspecialchars($htaccessContent) . '</pre>';
            } else {
                echo '<div class="error">❌ Impossible de créer .htaccess</div>';
            }
            ?>

        <?php elseif ($_POST['action'] === 'test_database'): ?>
            <h2>5. Test de connexion base de données</h2>
            <?php
            try {
                $pdo = new PDO(
                    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
                    $config['db_user'],
                    $config['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                echo '<div class="success">✅ Connexion à la base de données réussie !</div>';

                // Tester quelques requêtes
                $stmt = $pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo '<div class="info">Tables existantes : ' . implode(', ', $tables) . '</div>';

            } catch (PDOException $e) {
                echo '<div class="error">❌ Erreur de connexion DB : ' . $e->getMessage() . '</div>';
            }
            ?>

        <?php elseif ($_POST['action'] === 'check_symfony'): ?>
            <h2>6. Test Symfony</h2>
            <?php
            // Test simple d'inclusion Symfony
            try {
                if (file_exists('vendor/autoload.php')) {
                    require_once 'vendor/autoload.php';
                    echo '<div class="success">✅ Autoloader Composer chargé</div>';

                    // Vérifier si on peut instancier le kernel
                    if (class_exists('App\\Kernel')) {
                        echo '<div class="success">✅ Classe Kernel trouvée</div>';
                    } else {
                        echo '<div class="error">❌ Classe Kernel non trouvée</div>';
                    }
                } else {
                    echo '<div class="error">❌ vendor/autoload.php manquant - Composer non installé</div>';
                }
            } catch (Exception $e) {
                echo '<div class="error">❌ Erreur Symfony : ' . $e->getMessage() . '</div>';
            }
            ?>

        <?php elseif ($_POST['action'] === 'full_recovery'): ?>
            <h2>🚑 RÉCUPÉRATION COMPLÈTE</h2>
            <?php
            echo '<div class="info">Début de la récupération automatique...</div>';

            // 1. Créer .env
            $envContent = "APP_ENV=prod\nAPP_SECRET={$config['app_secret']}\nAPP_DEBUG=0\nDATABASE_URL=\"mysql://{$config['db_user']}:{$config['db_pass']}@{$config['db_host']}:3306/{$config['db_name']}?serverVersion=8.0&charset=utf8mb4\"\nTRUSTED_PROXIES=127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16\nTRUSTED_HOSTS='^(localhost|127\\.0\\.0\\.1|analantix\\.ovh)$'\n";
            file_put_contents('.env', $envContent);
            echo '<div class="success">✅ .env créé</div>';

            // 2. Créer .htaccess
            $htaccessContent = "RewriteEngine On\nRewriteCond %{REQUEST_URI} !^/public/\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^(.*)$ /public/\$1 [L]\nphp_value memory_limit 256M\n";
            file_put_contents('.htaccess', $htaccessContent);
            echo '<div class="success">✅ .htaccess créé</div>';

            // 3. Permissions
            if (is_dir('var/cache')) chmod('var/cache', 0755);
            if (is_dir('var/log')) chmod('var/log', 0755);
            if (is_dir('public')) chmod('public', 0755);
            echo '<div class="success">✅ Permissions corrigées</div>';

            // 4. Test DB
            try {
                $pdo = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_pass']);
                echo '<div class="success">✅ Base de données accessible</div>';
            } catch (Exception $e) {
                echo '<div class="error">❌ DB : ' . $e->getMessage() . '</div>';
            }

            echo '<div class="success">🎉 Récupération terminée ! Essayez d\'accéder à votre site maintenant.</div>';
            ?>
        <?php endif; ?>

    <?php else: ?>

        <div class="info">
            <strong>Votre serveur est inaccessible après le pull.</strong><br>
            Ce script va diagnostiquer et corriger les problèmes les plus courants.
        </div>

        <h2>Actions de récupération :</h2>

        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="create_env">
            <button type="submit" class="btn">1. Créer le fichier .env manquant</button>
        </form>

        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="check_files">
            <button type="submit" class="btn">2. Vérifier les fichiers</button>
        </form>

        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="fix_permissions">
            <button type="submit" class="btn">3. Corriger les permissions</button>
        </form>

        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="create_htaccess">
            <button type="submit" class="btn">4. Créer .htaccess</button>
        </form>

        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="test_database">
            <button type="submit" class="btn">5. Tester la base de données</button>
        </form>

        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="check_symfony">
            <button type="submit" class="btn">6. Tester Symfony</button>
        </form>

        <hr>

        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="full_recovery">
            <button type="submit" class="btn" style="background: #dc3545; font-size: 18px; padding: 15px 30px;">
                🚑 RÉCUPÉRATION AUTOMATIQUE COMPLÈTE
            </button>
        </form>

        <hr>

        <h3>Informations système :</h3>
        <div class="info">
            <strong>PHP Version :</strong> <?php echo PHP_VERSION; ?><br>
            <strong>Serveur :</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Inconnu'; ?><br>
            <strong>Document Root :</strong> <?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'Inconnu'; ?><br>
            <strong>Répertoire actuel :</strong> <?php echo getcwd(); ?><br>
            <strong>URL actuelle :</strong> <?php echo 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>
        </div>

    <?php endif; ?>

    <hr>
    <p><a href="?" class="btn">🔄 Retour au menu</a></p>
    <p><small>⚠️ Supprimez ce fichier une fois la récupération terminée pour des raisons de sécurité.</small></p>
</body>
</html>
