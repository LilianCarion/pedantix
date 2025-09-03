# 🚀 Déploiement Pedantix sans SSH

## Problème : SSH bloqué sur serveur OVH
## Solution : Déploiement via SFTP + Interface web

### 📋 Étapes simplifiées

#### 1. Préparation en local
- ✅ Tous vos fichiers sont prêts
- ✅ J'ai créé les scripts de déploiement automatique

#### 2. Upload via SFTP
Uploadez ces fichiers dans votre dossier `www/` sur OVH :

**Fichiers obligatoires :**
- `web_deploy.php` (interface de déploiement)
- Tous les dossiers : `src/`, `templates/`, `config/`, `migrations/`, `public/`, `bin/`
- `composer.json`, `composer.lock`, `symfony.lock`
- `.env.prod` (à renommer en `.env`)

#### 3. Configuration automatique
1. **Ouvrez dans votre navigateur :**
   ```
   https://votre-domaine.com/web_deploy.php
   ```

2. **Remplissez le formulaire avec vos infos OVH :**
   - Host base de données : `localhost`
   - Nom de la base : `votre_base_pedantix`
   - Utilisateur BDD : `votre_user_ovh`
   - Mot de passe BDD : `votre_password_ovh`
   - Votre domaine : `votre-domaine.com`

3. **Cliquez sur "Démarrer le déploiement"**

L'interface web va automatiquement :
- ✅ Installer Composer
- ✅ Installer les dépendances
- ✅ Créer la base de données
- ✅ Exécuter les migrations
- ✅ Peupler avec 150+ articles Wikipedia
- ✅ Configurer les permissions

#### 4. Configuration serveur web
Assurez-vous que votre nom de domaine OVH pointe vers le dossier `public/` (pas vers la racine www).

#### 5. Nettoyage
Supprimez `web_deploy.php` après le déploiement réussi.

### 🎯 Résultat
Votre Pedantix sera opérationnel avec :
- **150+ articles Wikipedia** automatiquement chargés
- **Modes Compétition et Coopération**
- **Nouvelles parties automatiques**
- **Ajout automatique d'articles** depuis les URL Wikipedia

### 🔧 En cas de problème
Si l'interface web ne fonctionne pas, vous pouvez :
1. Utiliser le gestionnaire de fichiers OVH
2. Éditer le fichier `.env` manuellement
3. Exécuter via le panneau OVH : `php bin/console app:deploy prod`

### 📞 Points de vérification
- ✅ Nom de domaine pointe vers `public/`
- ✅ Fichier `.env` configuré avec vos données OVH
- ✅ PHP 8.1+ activé sur votre hébergement
- ✅ Extension MySQL activée
