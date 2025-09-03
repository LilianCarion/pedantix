<?php

// Script de test pour diagnostiquer les problèmes avec l'API Wikipedia

echo "=== Test de connectivité Wikipedia ===\n\n";

// Test 1: Vérifier que PHP peut faire des requêtes HTTP
echo "1. Test de connectivité de base...\n";
$context = stream_context_create([
    'http' => [
        'header' => "User-Agent: PedantixApp/1.0\r\n",
        'timeout' => 10,
        'ignore_errors' => true
    ]
]);

// Test avec une URL simple
$testUrl = "https://httpbin.org/get";
echo "Test avec httpbin.org... ";
$response = @file_get_contents($testUrl, false, $context);
if ($response === false) {
    echo "ÉCHEC\n";
    $error = error_get_last();
    echo "Erreur: " . ($error['message'] ?? 'Inconnue') . "\n";
} else {
    echo "SUCCÈS\n";
}

echo "\n";

// Test 2: Tester l'API Wikipedia
echo "2. Test de l'API Wikipedia...\n";
$wikipediaUrl = "https://fr.wikipedia.org/api/rest_v1/page/summary/Eau";
echo "URL testée: $wikipediaUrl\n";

$response = @file_get_contents($wikipediaUrl, false, $context);
if ($response === false) {
    echo "ÉCHEC de connexion à Wikipedia\n";
    $error = error_get_last();
    echo "Erreur: " . ($error['message'] ?? 'Inconnue') . "\n";

    // Vérifier les informations sur le contexte de stream
    echo "\nInformations de configuration PHP:\n";
    echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'Activé' : 'DÉSACTIVÉ') . "\n";
    echo "user_agent: " . ini_get('user_agent') . "\n";
    echo "default_socket_timeout: " . ini_get('default_socket_timeout') . "s\n";

} else {
    echo "SUCCÈS de connexion à Wikipedia\n";
    $data = json_decode($response, true);
    if ($data) {
        echo "Titre reçu: " . ($data['title'] ?? 'N/A') . "\n";
        echo "Extrait (100 premiers caractères): " . substr($data['extract'] ?? '', 0, 100) . "...\n";
    } else {
        echo "Erreur de décodage JSON\n";
        echo "Réponse brute (200 premiers caractères): " . substr($response, 0, 200) . "\n";
    }
}

echo "\n";

// Test 3: Test avec cURL si disponible
echo "3. Test avec cURL (si disponible)...\n";
if (function_exists('curl_init')) {
    echo "cURL est disponible\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $wikipediaUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PedantixApp/1.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || !empty($error)) {
        echo "ÉCHEC avec cURL\n";
        echo "Erreur cURL: $error\n";
    } else {
        echo "SUCCÈS avec cURL\n";
        echo "Code HTTP: $httpCode\n";
        $data = json_decode($response, true);
        if ($data) {
            echo "Titre reçu: " . ($data['title'] ?? 'N/A') . "\n";
        }
    }
} else {
    echo "cURL n'est pas disponible\n";
}

echo "\n=== Fin des tests ===\n";
