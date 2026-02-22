<?php
/**
 * Exemple de script de réception de Webhook (Receiver)
 * Placez ce fichier sur un serveur accessible publiquement et copiez son URL dans les paramètres du plugin.
 */

// Initialisation du log
$log_file = __DIR__ . '/webhook.log';
$method = $_SERVER['REQUEST_METHOD'];

// Récupérer le contenu brut de la requête (JSON)
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

// Construire l'entrée de log
$log_entry = date('Y-m-d H:i:s') . " - Méthode : $method\n";
$log_entry .= "Headers : " . print_r(getallheaders(), true) . "\n";
$log_entry .= "Body Raw : " . (empty($raw_input) ? '(vide)' : $raw_input) . "\n";

if ($data) {
    $log_entry .= "Données décodées : " . print_r($data, true) . "\n";
} else {
    $log_entry .= "Erreur JSON : " . json_last_error_msg() . "\n";
}
$log_entry .= "------------------\n";

// Écrire dans le fichier
file_put_contents($log_file, $log_entry, FILE_APPEND);

// Réponse au client (Plugin ou Navigateur)
if ($method === 'GET') {
    // Si on accède via le navigateur, afficher un message clair
    header('Content-Type: text/plain; charset=utf-8');
    echo "Webhook Receiver prêt.\n";
    echo "Envoyez une requête POST avec un body JSON pour tester.\n";
    echo "Dernier log :\n\n" . file_get_contents($log_file);
    exit;
}

// Traitement selon l'événement (POST uniquement)
if (isset($data['event'])) {
    switch ($data['event']) {
        case 'MessageSent':
            // Exemple : Faire quelque chose quand un message est envoyé
            break;

        case 'MessageDeliveryFailed':
            // Exemple : Désactiver l'utilisateur dans votre base de données locale
            $email = $data['payload']['to'] ?? 'inconnu';
            $error = $data['payload']['error'] ?? 'Erreur inconnue';
            // my_custom_logic_disable_user($email);
            break;
            
        case 'MessageBounced':
            // Exemple : Notifier l'admin via Slack
            break;
            
        case 'TestEvent':
            // Log spécial pour les tests
            file_put_contents($log_file, "TEST REÇU AVEC SUCCÈS !\n", FILE_APPEND);
            break;
    }
}

// Répondre toujours avec 200 OK pour confirmer la réception
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'message' => 'Webhook received']);

/**
 * Helper pour les serveurs où getallheaders() n'existe pas (Nginx/FPM parfois)
 */
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}
