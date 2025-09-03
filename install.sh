#!/bin/bash

# Script d'installation rapide pour serveur OVH
# Usage: curl -sSL https://votre-repo.com/install.sh | bash

echo "🎮 Installation Pedantix sur serveur OVH"
echo "========================================"

# Vérifications préalables
if ! command -v php &> /dev/null; then
    echo "❌ PHP n'est pas installé"
    exit 1
fi

if ! command -v composer &> /dev/null; then
    echo "❌ Composer n'est pas installé"
    exit 1
fi

# Installation
echo "📦 Installation des dépendances..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "🔧 Configuration..."
if [ ! -f .env ]; then
    cp .env.prod .env
    echo "⚠️  Fichier .env créé - MODIFIEZ LA CONFIGURATION !"
fi

echo "🚀 Déploiement automatique..."
php bin/console app:deploy prod

echo ""
echo "✅ Installation terminée !"
echo ""
echo "📋 Actions requises :"
echo "1. Modifiez le fichier .env avec vos paramètres de base de données"
echo "2. Configurez votre serveur web pour pointer vers public/"
echo "3. Activez HTTPS"
echo ""
echo "🎯 Votre Pedantix est prêt avec plus de 150 articles Wikipedia !"
