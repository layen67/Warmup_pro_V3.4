# Rapport d'analyse API Postal - Warmup_pro_V3.4

Ce document présente une analyse détaillée de l'intégration de l'API Postal dans le plugin Warmup Pro V3.4.

## 1. Endpoints de l'API Postal appelés

Le plugin interagit avec l'API Postal via les endpoints suivants :

| Endpoint | Méthode | Paramètres | Usage / Réponse exploitée | Fichier source |
| :--- | :--- | :--- | :--- | :--- |
| `/send/message` | `POST` | `to`, `from`, `subject`, `plain_body`, `html_body`, `headers` (dont `X-Warmup-Template`, `List-Unsubscribe`...), `reply_to`, `tag` | **Critique**. Envoi d'email. La réponse est analysée pour récupérer `message_id` (succès) ou le message d'erreur. | `src/API/Sender.php` (via `send_request`), `src/Admin/AjaxHandler.php` (via test connection) |
| `/suppressions` | `GET` | Aucun | **Informatif**. Récupère la liste des suppressions (bounces, complaints) pour affichage dans le tableau de bord. Données non stockées. | `src/Admin/AjaxHandler.php` (via `Client::request`) |
| `/suppressions/delete` | `POST` | `email` | **Action**. Supprime une entrée de la liste de suppression. La réponse est vérifiée pour confirmer le succès. | `src/Admin/AjaxHandler.php` (via `Client::request`) |
| `/messages` | `GET` | `count=1` | **Diagnostic**. Utilisé uniquement pour vérifier la connectivité ("Health Check"). Le contenu du message retourné est ignoré. | `src/Admin/AjaxHandler.php` (via `Client::request`) |

## 2. Webhooks Postal gérés

Le fichier `src/API/WebhookHandler.php` gère les webhooks entrants sur la route `postal-warmup/v1/webhook`.

### Événements écoutés et logique exécutée

| Événement (`event`) | Logique exécutée |
| :--- | :--- |
| `MessageSent` | Enregistre une métrique `sent` dans `postal_stats_history` (sauf si déjà traité par l'envoi API synchrone, logique de déduplication présente). Met à jour les compteurs globaux `sent_count`. |
| `MessageDelivered` | Enregistre une métrique `delivered` dans `postal_stats_history`. Met à jour les compteurs de succès. |
| `MessageDeliveryFailed` | Enregistre une métrique `failed` dans `postal_stats_history`. Log une erreur critique. Met à jour les compteurs d'erreur. |
| `MessageBounced` | Enregistre une métrique `bounced` dans `postal_stats_history`. Log un avertissement. Peut déclencher une action de gestion de bounce (suppression de file d'attente/notification) selon configuration. |
| `MessageLinkClicked` | Enregistre une métrique `clicked` dans `postal_stats_history`. |
| `MessageLoaded` | Enregistre une métrique `opened` dans `postal_stats_history`. |
| `DomainDNSError` | Enregistre une métrique `dns_error` dans `postal_stats_history`. Log une erreur critique. |
| *(N/A - Message Entrant)* `rcpt_to` | Détecte une réponse (Reply). Vérifie la boucle d'envoi (anti-reply-to-self). Ajoute une tâche de réponse automatique dans `postal_queue` via `QueueManager`. |

## 3. Client HTTP utilisé

L'appel à l'API Postal est réalisé de deux manières légèrement différentes mais reposant sur la même infrastructure WordPress :

1.  **Classe `PostalWarmup\API\Client`** (`src/API/Client.php`) :
    *   Utilise `wp_remote_request()`.
    *   Gère l'authentification (`X-Server-API-Key`), le formatage JSON, et la gestion centralisée des erreurs.
    *   Utilisé par `AjaxHandler.php` pour les requêtes de gestion (suppressions, health check).

2.  **Classe `PostalWarmup\API\Sender`** (`src/API/Sender.php`) :
    *   Utilise directement `wp_remote_post()`.
    *   Duplique partiellement la logique d'appel (headers, timeout) pour l'endpoint critique d'envoi.
    *   Utilisé exclusivement pour l'envoi de messages (`/send/message`).

**Note :** L'utilisation directe de `wp_remote_post` dans `Sender.php` contourne la classe wrapper `Client`, ce qui crée une incohérence mineure dans la gestion du client HTTP.

## 4. Données retournées par l'API stockées en base

Les données suivantes issues des réponses API (ou payloads Webhook) sont persistées :

*   **`message_id`** : Stocké dans la table `postal_stats_history` (colonne `message_id`) et dans les logs (`postal_logs` via contexte JSON).
*   **`server_id`** : (Déduit du domaine ou du contexte) Stocké dans toutes les tables de statistiques (`postal_servers`, `postal_stats`, `postal_stats_history`).
*   **Statuts d'envoi/livraison** : Agrégés dans les compteurs `sent_count`, `success_count`, `error_count` des tables `postal_servers` et `postal_stats`.
*   **Temps de réponse (Latence API)** : Stocké dans `postal_stats` (`avg_response_time`) et logs.

## 5. Données retournées par l'API ignorées ou non exploitées

*   **Détails des messages (`/messages`)** : Le plugin ne stocke pas le contenu, les headers complets ou l'historique des messages retournés par Postal. Seul le `message_id` est conservé.
*   **Détails des suppressions (`/suppressions`)** : La liste est affichée à la demande mais n'est pas synchronisée ou stockée localement pour référence croisée rapide.
*   **Métadonnées étendues des webhooks** : Les payloads de webhook contiennent souvent des détails techniques (IP, user-agent complet, détails SMTP) qui sont parfois loggés en texte brut/JSON dans `postal_logs` mais non structurés en base de données pour analyse fine.

## 6. Lacunes critiques (Simulations vs Natif)

Voici les fonctionnalités simulées ou manquantes côté plugin par rapport aux capacités natives de l'API Postal :

1.  **Suivi des messages (Tracking)** :
    *   *Simulé* : Le plugin reconstruit l'historique des statuts (`sent` -> `delivered`) en écoutant les webhooks et en stockant chaque événement comme une ligne dans `postal_stats_history`.
    *   *Natif* : Postal possède une base de données complète de l'état des messages. Le plugin pourrait interroger `GET /messages/{id}/deliveries` pour obtenir l'état exact et l'historique de livraison d'un message spécifique en cas de doute, au lieu de se fier uniquement à la réception (parfois aléatoire) des webhooks.

2.  **Statistiques de performance** :
    *   *Simulé* : Le plugin calcule ses propres statistiques d'envoi/succès/erreur en incrémentant des compteurs locaux lors des envois et réceptions de webhooks.
    *   *Natif* : Postal dispose probablement de statistiques internes (via API `/server` ou autre), mais le plugin préfère sa propre "vérité" pour corréler avec les templates et les stratégies de warmup, ce qui est justifié mais redondant.

3.  **Gestion de la file d'attente (Queue)** :
    *   *Simulé* : Le plugin utilise Action Scheduler (`postal_queue` conceptuelle) pour réguler le débit d'envoi (throttling) vers Postal.
    *   *Natif* : Postal a sa propre queue de sortie SMTP. Le plugin doit tout de même gérer sa file pour ne pas saturer l'API ou respecter des règles de chauffe (warmup) très strictes que Postal ne gère pas nativement (montée en charge progressive par jour/heure). C'est donc une "simulation" nécessaire.

4.  **Santé du serveur** :
    *   *Manquant* : Le plugin vérifie juste la connectivité (`GET /messages`). Il n'exploite pas les infos de quota ou de réputation IP que Postal pourrait fournir via `/server`.
