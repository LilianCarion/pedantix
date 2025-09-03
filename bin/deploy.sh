#!/bin/bash

# Script de déploiement complet pour Pedantix
# Usage: ./bin/deploy.sh [production|dev]

set -e  # Arrêter le script en cas d'erreur

ENV=${1:-production}
echo "🚀 Démarrage du déploiement Pedantix en mode: $ENV"

# Couleurs pour les messages
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Fonction pour afficher des messages colorés
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Vérifier que Composer est installé
if ! command -v composer &> /dev/null; then
    log_error "Composer n'est pas installé. Veuillez l'installer d'abord."
    exit 1
fi

# Vérifier que PHP est installé
if ! command -v php &> /dev/null; then
    log_error "PHP n'est pas installé. Veuillez l'installer d'abord."
    exit 1
fi

log_info "1. Installation des dépendances..."
if [ "$ENV" = "production" ]; then
    composer install --no-dev --optimize-autoloader
else
    composer install
fi

log_info "2. Configuration de l'environnement..."
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        log_warning "Fichier .env créé depuis .env.example. Veuillez le configurer avec vos paramètres."
    else
        log_error "Aucun fichier .env trouvé. Veuillez en créer un."
        exit 1
    fi
fi

# Nettoyer le cache
log_info "3. Nettoyage du cache..."
php bin/console cache:clear --env=$ENV --no-debug

if [ "$ENV" = "production" ]; then
    # Optimisations pour la production
    log_info "4. Optimisations pour la production..."
    php bin/console cache:warmup --env=prod --no-debug

    # Générer les assets optimisés si Webpack Encore est utilisé
    if [ -f "webpack.config.js" ]; then
        if command -v npm &> /dev/null; then
            log_info "   Installation des dépendances Node.js..."
            npm install
            log_info "   Build des assets pour la production..."
            npm run build
        fi
    fi
fi

# Vérifier la configuration de la base de données
log_info "5. Vérification de la base de données..."
if php bin/console doctrine:database:create --if-not-exists --no-interaction; then
    log_info "   Base de données créée ou déjà existante"
else
    log_warning "   Impossible de créer la base de données - vérifiez la configuration"
fi

# Exécuter les migrations
log_info "6. Exécution des migrations..."
php bin/console doctrine:migrations:migrate --no-interaction

# Peuplement de la base avec les articles Wikipedia
log_info "7. Peuplement de la base de données avec les articles..."
php bin/console app:seed-wikipedia-articles

# Vérifier que tout fonctionne
log_info "8. Vérification de l'installation..."
if php bin/console debug:container --env=$ENV > /dev/null 2>&1; then
    log_info "   Container Symfony OK"
else
    log_error "   Problème avec le container Symfony"
    exit 1
fi

# Test de connexion à la base de données
if php bin/console doctrine:schema:validate --no-interaction > /dev/null 2>&1; then
    log_info "   Schéma de base de données OK"
else
    log_warning "   Problème potentiel avec le schéma de base de données"
fi

# Permissions pour les fichiers de cache et logs
log_info "9. Configuration des permissions..."
if [ -d "var/cache" ]; then
    chmod -R 775 var/cache
    log_info "   Permissions cache configurées"
fi

if [ -d "var/log" ]; then
    chmod -R 775 var/log
    log_info "   Permissions logs configurées"
fi

# Afficher les informations finales
log_info "10. Informations de déploiement..."
echo ""
echo "📊 Statistiques:"
echo "   - Articles en base: $(php bin/console doctrine:query:sql 'SELECT COUNT(*) as count FROM wikipedia_article' --quiet | tail -n +3 | head -1 | awk '{print $1}')"
echo "   - Environment: $ENV"
echo "   - Version PHP: $(php -r 'echo PHP_VERSION;')"
echo "   - Symfony version: $(php bin/console --version | head -1)"
echo ""

if [ "$ENV" = "production" ]; then
    echo "🔒 Conseils pour la production:"
    echo "   - Assurez-vous que votre serveur web pointe vers le dossier 'public/'"
    echo "   - Configurez HTTPS"
    echo "   - Vérifiez que les variables d'environnement sont correctement définies"
    echo "   - Surveillez les logs dans var/log/"
    echo ""
fi

echo "🎯 Points d'accès:"
echo "   - Page d'accueil: //"
echo "   - API articles aléatoires: /api/random-article"
echo "   - Créer une salle: /create-room"
echo ""

log_info "✅ Déploiement terminé avec succès!"
echo ""
echo "🚀 Votre application Pedantix est prête !"
echo "   Pour démarrer le serveur de développement: php -S localhost:8000 -t public/"
echo ""
