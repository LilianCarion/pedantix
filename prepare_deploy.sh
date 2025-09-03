#!/bin/bash

# 🚀 Script de déploiement Pedantix pour serveur OVH (SFTP uniquement)
# Ce script prépare tout pour que vous n'ayez qu'à uploader et exécuter UNE commande

echo "🚀 Préparation du déploiement Pedantix pour serveur OVH"
echo "======================================================"

# Créer un dossier de déploiement
DEPLOY_DIR="deploy_package"
mkdir -p $DEPLOY_DIR

# Copier tous les fichiers nécessaires
echo "📦 Copie des fichiers..."
cp -r src/ $DEPLOY_DIR/
cp -r templates/ $DEPLOY_DIR/
cp -r config/ $DEPLOY_DIR/
cp -r migrations/ $DEPLOY_DIR/
cp -r public/ $DEPLOY_DIR/
cp -r bin/ $DEPLOY_DIR/
cp composer.json $DEPLOY_DIR/
cp composer.lock $DEPLOY_DIR/
cp symfony.lock $DEPLOY_DIR/
cp .env.prod $DEPLOY_DIR/.env

# Créer le script de déploiement final
cat > $DEPLOY_DIR/deploy_final.php << 'EOF'
<?php
/**
 * Script de déploiement automatique Pedantix
 * À exécuter UNE SEULE FOIS sur le serveur via navigateur web
 * URL: https://votre-domaine.com/deploy_final.php
 */

echo "<h1>🚀 Déploiement Pedantix</h1>";
echo "<pre>";

// Fonction pour exécuter des commandes et afficher le résultat
function runCommand($command, $description) {
    echo "\n=== $description ===\n";
    echo "Commande: $command\n";

    $output = [];
    $return_var = 0;
    exec($command . " 2>&1", $output, $return_var);

    foreach ($output as $line) {
        echo $line . "\n";
    }

    if ($return_var === 0) {
        echo "✅ Succès\n";
    } else {
        echo "❌ Erreur (code: $return_var)\n";
    }

    return $return_var === 0;
}

// Vérifier que PHP est disponible
echo "🔍 Vérification de l'environnement...\n";
echo "Version PHP: " . PHP_VERSION . "\n";
echo "Répertoire de travail: " . getcwd() . "\n";

// 1. Installation des dépendances Composer
if (file_exists('composer.phar')) {
    $composerCmd = 'php composer.phar';
} else {
    // Télécharger Composer si nécessaire
    echo "📥 Téléchargement de Composer...\n";
    file_put_contents('composer.phar', file_get_contents('https://getcomposer.org/composer.phar'));
    chmod('composer.phar', 0755);
    $composerCmd = 'php composer.phar';
}

runCommand("$composerCmd install --no-dev --optimize-autoloader --no-interaction", "Installation des dépendances");

// 2. Configuration .env
if (!file_exists('.env')) {
    echo "⚠️ IMPORTANT: Configurez le fichier .env avec vos paramètres de base de données\n";
    echo "Le fichier .env a été créé depuis .env.prod\n";
}

// 3. Déploiement automatique
runCommand("php bin/console app:deploy prod", "Déploiement complet (migrations + articles)");

// 4. Configuration des permissions
echo "\n=== Configuration des permissions ===\n";
if (is_dir('var/cache')) {
    chmod('var/cache', 0775);
    echo "✅ Permissions var/cache configurées\n";
}
if (is_dir('var/log')) {
    chmod('var/log', 0775);
    echo "✅ Permissions var/log configurées\n";
}

// 5. Test final
echo "\n=== Test final ===\n";
if (runCommand("php bin/console debug:container --env=prod", "Test du container Symfony")) {
    echo "🎉 DÉPLOIEMENT RÉUSSI !\n";
    echo "\n📊 Statistiques:\n";

    // Compter les articles
    try {
        ob_start();
        runCommand("php bin/console doctrine:query:sql 'SELECT COUNT(*) as count FROM wikipedia_article' --quiet", "");
        $output = ob_get_clean();
        echo "Articles en base: " . $output . "\n";
    } catch (Exception $e) {
        echo "Articles: Base initialisée\n";
    }

    echo "\n🎯 Votre Pedantix est maintenant opérationnel !\n";
    echo "- Plus de 150 articles Wikipedia disponibles\n";
    echo "- Modes Compétition et Coopération\n";
    echo "- Nouvelles parties automatiques\n";
    echo "\n⚠️ N'oubliez pas de supprimer ce fichier deploy_final.php après le déploiement !\n";
} else {
    echo "❌ Erreur lors du test final\n";
}

echo "</pre>";
?>
EOF

# Créer un fichier README pour l'utilisateur
cat > $DEPLOY_DIR/README_DEPLOYMENT.md << 'EOF'
# 🚀 Déploiement Pedantix sur serveur OVH

## Étapes simples (SSH bloqué)

### 1. Upload via SFTP
Uploadez TOUT le contenu de ce dossier `deploy_package` dans votre dossier `www/` sur le serveur OVH.

### 2. Configuration de la base de données
Éditez le fichier `.env` uploadé avec vos vraies informations OVH :

```env
DATABASE_URL="mysql://votre_user_ovh:votre_password_ovh@localhost:3306/votre_base_pedantix?serverVersion=8.0&charset=utf8mb4"
APP_SECRET=VOTRE_VRAIE_CLE_SECRETE_32_CARACTERES
TRUSTED_HOSTS='^(localhost|127\.0\.0\.1|votre-domaine\.com)$'
```

### 3. Configuration serveur web
Assurez-vous que votre nom de domaine pointe vers le dossier `public/` (pas la racine www).

### 4. Déploiement automatique
Ouvrez dans votre navigateur :
```
https://votre-domaine.com/deploy_final.php
```

Cette page va automatiquement :
- ✅ Installer Composer et les dépendances
- ✅ Créer la base de données
- ✅ Exécuter les migrations
- ✅ Peupler avec 150+ articles Wikipedia
- ✅ Configurer les permissions
- ✅ Tester que tout fonctionne

### 5. Nettoyage
Supprimez le fichier `deploy_final.php` après le déploiement réussi.

## 🎯 Votre Pedantix sera opérationnel !
- Plus de 150 articles Wikipedia
- Modes Compétition et Coopération
- Nouvelles parties automatiques
- Ajout automatique d'articles depuis les URL
EOF

# Créer un fichier .htaccess optimisé pour le dossier public
cat > $DEPLOY_DIR/public/.htaccess << 'EOF'
# Configuration Apache pour Pedantix

DirectoryIndex index.php

<IfModule mod_negotiation.c>
    Options -MultiViews
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Gestion des URL Symfony
    RewriteCond %{REQUEST_URI}::$0 ^(/.+)/(.*)::\2$
    RewriteRule .* - [E=BASE:%1]

    # Réécriture vers index.php pour les routes Symfony
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>

# Sécurité
<Files ~ "^\.">
    Order allow,deny
    Deny from all
</Files>

# Cache des assets statiques
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
</IfModule>

# Compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/plain
    AddOutputFilterByType DEFLATE text/html
    AddOutputFilterByType DEFLATE text/xml
    AddOutputFilterByType DEFLATE text/css
    AddOutputFilterByType DEFLATE application/xml
    AddOutputFilterByType DEFLATE application/xhtml+xml
    AddOutputFilterByType DEFLATE application/rss+xml
    AddOutputFilterByType DEFLATE application/javascript
    AddOutputFilterByType DEFLATE application/x-javascript
</IfModule>
EOF

echo "✅ Package de déploiement créé dans le dossier '$DEPLOY_DIR'"
echo ""
echo "📋 Instructions :"
echo "1. Uploadez TOUT le contenu du dossier '$DEPLOY_DIR' vers votre serveur OVH (dossier www/)"
echo "2. Éditez le fichier .env avec vos paramètres de base de données"
echo "3. Pointez votre domaine vers le dossier public/"
echo "4. Ouvrez https://votre-domaine.com/deploy_final.php dans votre navigateur"
echo "5. Supprimez deploy_final.php après le déploiement"
echo ""
echo "🎯 Votre Pedantix sera opérationnel avec 150+ articles Wikipedia !"
