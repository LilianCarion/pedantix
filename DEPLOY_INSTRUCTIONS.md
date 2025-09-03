# 🚀 Instructions de déploiement Pedantix sur serveur OVH

## 1. Upload via SFTP
Uploadez TOUS les fichiers de votre projet dans le dossier www/ de votre serveur.

## 2. Configuration SSH
Connectez-vous en SSH à votre serveur et exécutez ces commandes :

```bash
# Aller dans le dossier web
cd ~/www

# Copier la configuration de production
cp .env.prod .env

# Éditer la configuration avec vos paramètres OVH
nano .env
```

## 3. Configuration .env à modifier
Dans le fichier .env, modifiez ces lignes avec VOS paramètres OVH :

```env
# REMPLACEZ par vos vraies données OVH
DATABASE_URL="mysql://votre_user_db:votre_password@localhost:3306/votre_base_pedantix?serverVersion=8.0&charset=utf8mb4"

# Générez une vraie clé secrète
APP_SECRET=VOTRE_VRAIE_CLE_SECRETE_UNIQUE_32_CARACTERES

# Votre nom de domaine
TRUSTED_HOSTS='^(localhost|127\.0\.0\.1|votre-domaine\.com)$'
```

## 4. Installation des dépendances
```bash
# Si composer n'est pas installé globalement
curl -sS https://getcomposer.org/installer | php
php composer.phar install --no-dev --optimize-autoloader

# Ou si composer est disponible
composer install --no-dev --optimize-autoloader
```

## 5. Déploiement automatique
```bash
# Exécuter la commande magique qui fait TOUT
php bin/console app:deploy prod
```

Cette commande va :
- ✅ Nettoyer le cache
- ✅ Créer la base de données
- ✅ Exécuter les migrations
- ✅ Peupler avec 150+ articles Wikipedia
- ✅ Vérifier que tout fonctionne

## 6. Configuration du serveur web
Assurez-vous que votre domaine pointe vers le dossier `public/` de votre projet.

### Pour Apache (.htaccess dans public/) :
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php [QSA,L]
```

### Pour Nginx :
```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

## 7. Permissions des dossiers
```bash
chmod -R 775 var/cache
chmod -R 775 var/log
```

## 8. Test final
Accédez à votre domaine : `https://votre-domaine.com`

Vous devriez voir la page d'accueil Pedantix avec :
- ✅ Plus de 150 articles Wikipedia disponibles
- ✅ Fonctionnalité "Article aléatoire"
- ✅ Modes Compétition et Coopération
- ✅ Ajout automatique d'articles depuis les URL

## 🔧 Dépannage

### Erreur de base de données :
```bash
# Vérifier la connexion
php bin/console doctrine:database:create --if-not-exists
```

### Erreur de permissions :
```bash
sudo chown -R www-data:www-data var/
```

### Cache problems :
```bash
php bin/console cache:clear --env=prod
```

## 🎯 Votre Pedantix est maintenant en production !
- 150+ articles Wikipedia prêts à jouer
- Ajout automatique de nouveaux articles
- Modes Compétition et Coopération
- Système de nouvelles parties avec bouton "Nouvelle partie"
