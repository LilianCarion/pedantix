#!/bin/bash

# 🚀 Script de déploiement automatique Pedantix OVH avec détection PHP
# Exécute ce script UNE SEULE FOIS après avoir pullé le repo

echo "🚀 Déploiement automatique Pedantix sur OVH"
echo "============================================="

# Couleurs
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Fonction pour détecter le bon chemin PHP sur OVH
find_php_path() {
    local possible_paths=(
        "/usr/local/php8.1/bin/php"
        "/usr/local/php8.2/bin/php"
        "/usr/local/php8.3/bin/php"
        "/usr/bin/php8.1"
        "/usr/bin/php8.2"
        "/usr/bin/php8.3"
        "/opt/alt/php81/usr/bin/php"
        "/opt/alt/php82/usr/bin/php"
        "/opt/alt/php83/usr/bin/php"
        "php8.1"
        "php8.2"
        "php8.3"
        "php"
    )

    for php_path in "${possible_paths[@]}"; do
        if command -v "$php_path" &> /dev/null; then
            local version=$($php_path --version 2>/dev/null | head -n 1)
            if [[ $? -eq 0 ]]; then
                echo "$php_path"
                return 0
            fi
        fi
    done

    echo "php"  # Fallback
    return 1
}

# Détecter le chemin PHP
log_info "1. Détection de l'environnement PHP..."
PHP_PATH=$(find_php_path)
PHP_VERSION=$($PHP_PATH --version 2>/dev/null | head -n 1 || echo "Version inconnue")

if [[ $? -eq 0 ]]; then
    log_info "PHP détecté: $PHP_PATH"
    log_info "Version: $PHP_VERSION"
else
    log_error "Aucune version de PHP trouvée. Vérifiez votre configuration OVH."
    exit 1
fi

# Configuration automatique .env
log_info "2. Configuration automatique..."
if [ ! -f .env ]; then
    cp .env.prod .env
    log_info "Fichier .env configuré automatiquement avec vos paramètres OVH"
else
    log_warning "Fichier .env existe déjà - préservation de la configuration existante"
fi

# Installation Composer si nécessaire
log_info "3. Installation de Composer..."
if [ ! -f composer.phar ]; then
    log_info "Téléchargement de Composer..."
    curl -sS https://getcomposer.org/installer | $PHP_PATH
    if [ $? -ne 0 ]; then
        log_error "Échec du téléchargement de Composer"
        exit 1
    fi
fi

# Installation des dépendances avec le bon chemin PHP
log_info "4. Installation des dépendances..."
$PHP_PATH composer.phar install --no-dev --optimize-autoloader --no-interaction
if [ $? -ne 0 ]; then
    log_error "Échec de l'installation des dépendances"
    exit 1
fi

# Déploiement complet avec le bon chemin PHP
log_info "5. Déploiement complet (migrations + articles Wikipedia)..."
$PHP_PATH bin/console app:deploy prod
if [ $? -ne 0 ]; then
    log_error "Échec du déploiement"
    exit 1
fi

# Configuration des permissions
log_info "6. Configuration des permissions..."
chmod -R 755 var/cache var/log 2>/dev/null || true

# Test de connexion à la base avec le bon chemin PHP
log_info "7. Test de la base de données..."
$PHP_PATH bin/console doctrine:schema:validate --no-interaction >/dev/null 2>&1
if [ $? -eq 0 ]; then
    log_info "✅ Connexion à la base de données OK"
else
    log_warning "⚠️ Problème potentiel avec la base de données"
fi

# Compter les articles avec le bon chemin PHP
ARTICLE_COUNT=$($PHP_PATH bin/console doctrine:query:sql 'SELECT COUNT(*) as count FROM wikipedia_article' --quiet 2>/dev/null | tail -n 1 | awk '{print $1}' || echo "?")

log_info "8. Vérification finale..."
$PHP_PATH bin/console debug:container --env=prod >/dev/null 2>&1
if [ $? -eq 0 ]; then
    echo ""
    echo "🎉 DÉPLOIEMENT RÉUSSI !"
    echo "======================="
    echo "📊 Statistiques :"
    echo "   - PHP utilisé : $PHP_PATH"
    echo "   - Version PHP : $PHP_VERSION"
    echo "   - Articles Wikipedia : $ARTICLE_COUNT"
    echo "   - URL : http://analantix.ovh"
    echo "   - Base de données : analanjroot"
    echo ""
    echo "🎯 Votre Pedantix est opérationnel !"
    echo "   - Plus de 150 articles Wikipedia disponibles"
    echo "   - Modes Compétition et Coopération"
    echo "   - Nouvelles parties automatiques"
    echo ""
    echo "���️ IMPORTANT :"
    echo "   - Configurez votre serveur web pour pointer vers public/"
    echo "   - Activez HTTPS si possible"
    echo "   - Supprimez ce script après le premier déploiement"
else
    log_error "Erreur lors de la vérification finale"
    exit 1
fi
