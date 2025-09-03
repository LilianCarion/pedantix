#!/bin/bash

# 🚀 Script de déploiement automatique Pedantix OVH
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

# Vérifier l'environnement
log_info "1. Vérification de l'environnement..."
if ! command -v php &> /dev/null; then
    log_error "PHP n'est pas disponible. Vérifiez votre configuration OVH."
    exit 1
fi

PHP_VERSION=$(php -v | head -n 1 | cut -d ' ' -f 2 | cut -d '.' -f 1,2)
log_info "Version PHP: $PHP_VERSION"

if [[ "$PHP_VERSION" < "8.1" ]]; then
    log_warning "PHP 8.1+ recommandé. Version actuelle: $PHP_VERSION"
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
    curl -sS https://getcomposer.org/installer | php
    if [ $? -ne 0 ]; then
        log_error "Échec du téléchargement de Composer"
        exit 1
    fi
fi

# Installation des dépendances
log_info "4. Installation des dépendances..."
php composer.phar install --no-dev --optimize-autoloader --no-interaction
if [ $? -ne 0 ]; then
    log_error "Échec de l'installation des dépendances"
    exit 1
fi

# Déploiement complet
log_info "5. Déploiement complet (migrations + articles Wikipedia)..."
php bin/console app:deploy prod
if [ $? -ne 0 ]; then
    log_error "Échec du déploiement"
    exit 1
fi

# Configuration des permissions
log_info "6. Configuration des permissions..."
chmod -R 755 var/cache var/log 2>/dev/null || true

# Test de connexion à la base
log_info "7. Test de la base de données..."
php bin/console doctrine:schema:validate --no-interaction >/dev/null 2>&1
if [ $? -eq 0 ]; then
    log_info "✅ Connexion à la base de données OK"
else
    log_warning "⚠️ Problème potentiel avec la base de données"
fi

# Compter les articles
ARTICLE_COUNT=$(php bin/console doctrine:query:sql 'SELECT COUNT(*) as count FROM wikipedia_article' --quiet 2>/dev/null | tail -n 1 | awk '{print $1}' || echo "?")

log_info "8. Vérification finale..."
php bin/console debug:container --env=prod >/dev/null 2>&1
if [ $? -eq 0 ]; then
    echo ""
    echo "🎉 DÉPLOIEMENT RÉUSSI !"
    echo "======================="
    echo "📊 Statistiques :"
    echo "   - Articles Wikipedia : $ARTICLE_COUNT"
    echo "   - URL : http://analantix.ovh"
    echo "   - Base de données : analanjroot"
    echo ""
    echo "🎯 Votre Pedantix est opérationnel !"
    echo "   - Plus de 150 articles Wikipedia disponibles"
    echo "   - Modes Compétition et Coopération"
    echo "   - Nouvelles parties automatiques"
    echo ""
    echo "⚠️ IMPORTANT :"
    echo "   - Configurez votre serveur web pour pointer vers public/"
    echo "   - Activez HTTPS si possible"
    echo "   - Supprimez ce script après le premier déploiement"
else
    log_error "Erreur lors de la vérification finale"
    exit 1
fi
