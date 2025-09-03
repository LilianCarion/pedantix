# 🚀 Déploiement Pedantix OVH - Configuration automatique

## Configuration intégrée
Vos paramètres OVH sont maintenant directement intégrés dans le projet :
- **Base de données :** analanjroot
- **Host :** analanjroot.mysql.db
- **Utilisateur :** analanjroot
- **Mot de passe :** Bulls071201
- **Domaine :** analantix.ovh

## 📋 Déploiement en 2 étapes seulement

### Méthode 1 : Via interface web (recommandée)
1. **Uploadez tout** le contenu de votre repo dans `www/` sur OVH
2. **Ouvrez** dans votre navigateur : `http://analantix.ovh/deploy.php`
3. **Cliquez** sur "Démarrer le déploiement automatique"
4. **Supprimez** `deploy.php` après succès

### Méthode 2 : Via SSH (si disponible)
1. **Uploadez tout** le contenu dans `www/`
2. **Exécutez** : `bash deploy_ovh.sh`
3. **Supprimez** le script après succès

## 🎯 Ce qui sera fait automatiquement
- ✅ Configuration .env avec vos paramètres OVH
- ✅ Installation de Composer et dépendances
- ✅ Création de la base de données
- ✅ Exécution des migrations
- ✅ Peuplement avec 150+ articles Wikipedia
- ✅ Configuration des permissions
- ✅ Tests de bon fonctionnement

## ⚠️ Configuration serveur web
**IMPORTANT :** Configurez votre serveur web OVH pour que le nom de domaine `analantix.ovh` pointe vers le dossier `public/` (pas vers la racine `www/`).

Dans votre panel OVH :
1. Allez dans "Multisite"
2. Modifiez `analantix.ovh`
3. Changez le dossier racine vers `public/`

## 🎉 Résultat final
Votre Pedantix sera accessible sur `http://analantix.ovh` avec :
- Plus de 150 articles Wikipedia pré-chargés
- Modes Compétition et Coopération
- Système de nouvelles parties automatiques
- Interface responsive et moderne

## 🔧 En cas de problème
Si le déploiement échoue :
1. Vérifiez que PHP 8.1+ est activé sur votre hébergement
2. Vérifiez que l'extension MySQL est activée
3. Consultez les logs d'erreur dans le panel OVH
4. Assurez-vous que le domaine pointe vers `public/`

Votre configuration est maintenant entièrement automatisée !
