# Explication détaillée du flux "Mailto" et Réponse Automatique

Ce document explique le cycle de vie complet d'un email généré via les liens `mailto:` du plugin, depuis le clic utilisateur jusqu'à la réponse automatisée du système.

## 1. Génération du lien (Le Déclencheur)
Tout commence par l'affichage d'un lien ou bouton `mailto:` sur le site via un shortcode (ex: `[postal_warmup template="support"]`).

**Fichier concerné :** `src/Services/Mailto.php`

1.  **Shortcode :** Le code génère une URL `mailto:` complexe contenant :
    *   **Destinataire :** Une adresse du type `prefix@domaine-du-serveur.com` (le serveur est choisi par le `LoadBalancer` pour répartir la charge).
    *   **Sujet & Corps :** Pré-remplis aléatoirement à partir du template (ex: "Question", "Besoin d'aide...").
2.  **Tracking (Optionnel) :** Si le tracking est activé, un script JS (`mailto-tracker.js`) intercepte le clic pour l'enregistrer dans la table `postal_mailto_clicks` avant d'ouvrir le client mail de l'utilisateur.

## 2. Envoi par l'utilisateur (Action Externe)
L'utilisateur clique, son client mail (Outlook, Gmail...) s'ouvre avec le brouillon pré-rempli. Il clique sur "Envoyer".
*   Cet email part de *l'adresse personnelle de l'utilisateur* vers l'adresse du serveur Postal (ex: `contact@mon-serveur-warmup.com`).
*   **Note :** À ce stade, WordPress ne sait rien de l'envoi, c'est une action purement SMTP entre l'utilisateur et Postal.

## 3. Réception par Postal (Serveur Mail)
Postal reçoit l'email sur le domaine configuré.
Si une "Route" est configurée dans Postal pour rediriger les emails entrants vers un webhook HTTP (ex: `https://mon-site.com/wp-json/postal-warmup/v1/webhook`), Postal prépare une requête POST.

## 4. Traitement du Webhook (Entrée dans WordPress)
Postal envoie les données de l'email (expéditeur, sujet, contenu) au plugin.

**Fichier concerné :** `src/API/WebhookHandler.php`

1.  **Réception (`handle_webhook`) :** Le plugin reçoit le JSON. Il détecte qu'il s'agit d'un message entrant via la présence du champ `rcpt_to` (destinataire).
2.  **Analyse (`handle_incoming_message`) :**
    *   **Identification :** Il extrait le domaine destinataire pour retrouver l'ID du serveur interne (`server_id`).
    *   **Sécurité (Anti-Boucle) :** Il vérifie que l'expéditeur n'est pas un autre serveur du système (pour éviter qu'ils ne se répondent indéfiniment).
    *   **Log :** L'événement est enregistré dans les logs ("Message entrant").
3.  **Mise en file d'attente (`QueueManager::add`) :**
    *   Le plugin prépare une **réponse automatique**.
    *   **Sujet :** "Re: " + le sujet original.
    *   **Template :** Il cherche un template dont le nom correspond au préfixe de l'email (ex: si envoyé à `support@...`, il cherche le template "support").
    *   **Action :** Il ajoute cette tâche dans la table `postal_queue` avec le statut `pending` (en attente).

## 5. Envoi de la Réponse (Sortie de WordPress)
Le Cron job (qui tourne toutes les minutes) déclenche le traitement de la file d'attente.

**Fichier concerné :** `src/Services/QueueManager.php`

1.  **Traitement (`process_queue`) :** Il récupère la tâche en attente.
2.  **Sélection du Serveur (`LoadBalancer`) :**
    *   **Point Important :** Le système recalcul quel serveur doit envoyer la réponse.
    *   Il est possible (selon la configuration du Load Balancer) que la réponse parte d'un *autre* serveur que celui qui a reçu l'email, si cela permet d'équilibrer la charge ou d'optimiser la délivrabilité.
3.  **Envoi (`Sender::process_queue`) :**
    *   L'email est envoyé via l'API Postal (`POST /send/message`).
    *   Le statut passe à `sent` dans la file d'attente.
4.  **Tracking :** L'envoi est comptabilisé dans les statistiques du jour.

## Résumé du Flux

1.  **Site WP** (`Mailto.php`) -> Génère lien `mailto:support@serveurA.com`
2.  **Utilisateur** -> Envoie email à `support@serveurA.com`
3.  **Postal** -> Reçoit email -> Appelle Webhook WP
4.  **WP** (`WebhookHandler.php`) -> Reçoit webhook -> Crée tâche "Répondre à l'utilisateur"
5.  **WP** (`QueueManager.php`) -> Exécute tâche -> Envoie email "Re: ..." (via `Sender.php`)
