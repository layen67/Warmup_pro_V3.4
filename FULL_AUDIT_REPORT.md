# Rapport d'Audit Complet - Warmup_pro_V3.4

Ce rapport présente une analyse exhaustive du plugin **Postal Warmup Pro V3.4**, basée sur l'examen du code source fourni.

## 1. STRUCTURE DU PLUGIN

### Fichiers PHP et Rôles

| Répertoire | Fichier | Rôle |
| :--- | :--- | :--- |
| **Racine** | `postal-warmup.php` | Point d'entrée principal. Initialise le plugin et l'autoloading. |
| **src/Core** | `Activator.php` | Activation : création des 16 tables DB, cron jobs, options par défaut. |
| | `Deactivator.php` | Désactivation : nettoyage éventuel (non destructif par défaut). |
| | `Loader.php` | Gestionnaire d'enregistrement des hooks WordPress. |
| | `Plugin.php` | Chef d'orchestre : instancie les classes et définit les hooks principaux. |
| | `TemplateEngine.php` | Moteur de rendu simple pour les templates d'email (variables dynamiques). |
| | `i18n.php` | Chargement des fichiers de traduction (`.mo/.po`). |
| **src/Admin** | `Admin.php` | Gestion de l'interface d'administration (menus, styles, scripts). |
| | `AjaxHandler.php` | Point central des requêtes AJAX (dashboard, stats, tests). |
| | `Settings.php` | Gestionnaire global des options (`pw_settings`). |
| | `WarmupSettings.php` | Gestionnaire des options spécifiques au warmup (volume, horaire). |
| | `TemplateManager.php` | Logique métier CRUD pour les templates d'emails. |
| | `ISPManager.php` | Logique métier CRUD pour les fournisseurs d'accès (ISPs). |
| | `StrategyManager.php` | Logique métier CRUD pour les stratégies de montée en charge. |
| **src/API** | `Client.php` | Wrapper HTTP unifié pour l'API Postal (`wp_remote_request`). |
| | `Sender.php` | Service d'envoi d'emails (`wp_remote_post` direct) et Worker asynchrone. |
| | `WebhookHandler.php` | Endpoint REST pour recevoir les événements Postal. |
| **src/Models** | `Database.php` | Couche d'accès aux données (CRUD générique Servers, Logs). |
| | `Stats.php` | Agrégation et requêtage des statistiques (History, Daily, Metrics). |
| | `Strategy.php` | Modèle de données pour les stratégies. |
| **src/Services** | `QueueManager.php` | Gestion de la file d'attente (`postal_queue`) : ajout, traitement, retry. |
| | `WarmupEngine.php` | Moteur décisionnel : calcule l'avancement journalier (`warmup_day`). |
| | `StrategyEngine.php` | Moteur de calcul : détermine les quotas journaliers selon la stratégie. |
| | `LoadBalancer.php` | Algorithme de sélection du serveur d'envoi (V3 : Score & Safety). |
| | `Logger.php` | Service de journalisation (DB et Fichiers). |
| | `Encryption.php` | Chiffrement des clés API en base de données. |
| | `EmailNotifications.php` | Envoi d'alertes et de rapports journaliers. |
| | `ISPDetector.php` | Détection du fournisseur d'accès à partir du domaine de l'email. |
| | `HealthScoreCalculator.php` | Calcul du score de santé des serveurs. |
| | `WarmupAdvisor.php` | Surveillance proactive et recommandations d'actions correctives. |
| | `WebhookDispatcher.php` | Relais de webhooks vers des services tiers. |
| | `DomScanService.php` | Audit technique des enregistrements DNS (SPF, DKIM). |

### Base de Données (16 Tables)

Toutes les tables sont préfixées par `wp_postal_` (ou préfixe WP).

| Table | Rôle | Colonnes Clés | Usage |
| :--- | :--- | :--- | :--- |
| `postal_servers` | Configuration des serveurs Postal. | `api_url`, `api_key` (chiffrée), `warmup_day`, `daily_limit` | Stocke les identifiants API et l'état global d'avancement. |
| `postal_logs` | Journal des événements techniques. | `server_id`, `level`, `message`, `context` (JSON) | Debugging et audit des erreurs. |
| `postal_stats` | Stats horaires brutes (Legacy/Aggrégat). | `sent_count`, `success_count`, `error_count`, `avg_response_time` | Affichage rapide dashboard (aujourd'hui). |
| `postal_stats_history` | Historique détaillé des événements (V3). | `message_id`, `event_type` (sent, delivered...), `timestamp` | Source de vérité pour l'analyse fine et le tracking. |
| `postal_stats_daily` | Agrégats journaliers archivés. | `total_sent`, `total_success`, `total_error` | Performance dashboard (historique long). |
| `postal_queue` | File d'attente des envois. | `status` (pending/processing), `scheduled_at`, `attempts` | Tampon de sortie pour réguler le débit. |
| `postal_metrics` | Métriques agrégées par template/event. | `template_id`, `event_type`, `count` | Stats de performance des templates. |
| `postal_templates` | Contenu des emails de warmup. | `name`, `data` (JSON: subject, html...), `status` | Modèles d'emails rotatifs. |
| `postal_template_folders` | Organisation des templates. | `name`, `parent_id` | Hiérarchie visuelle. |
| `postal_template_versions` | Versioning des templates. | `data`, `version_number`, `diff_summary` | Historique des modifications. |
| `postal_isps` | Configuration des règles par FAI. | `isp_key`, `domains`, `max_daily`, `strategy` | Règles spécifiques (Gmail vs Yahoo). |
| `postal_server_isp_stats` | Tracking réputation par couple Serveur/FAI. | `server_id`, `isp_key`, `score`, `warmup_day` | Cœur de la logique de warmup V3. |
| `postal_strategies` | Définitions des courbes de chauffe. | `config_json` (paliers, croissance) | Personnalisation de la montée en charge. |
| `postal_mailto_clicks` | Tracking des clics Mailto. | `user_agent`, `ip_address` | Stats d'engagement simulé (si utilisé). |
| `postal_template_tags` | Tags pour templates. | `name`, `color` | Organisation. |
| `postal_template_tag_relations` | Liaison Tags <-> Templates. | `template_id`, `tag_id` | Many-to-Many. |

### WP-Cron Enregistrés

| Hook | Fréquence | Callback | Rôle |
| :--- | :--- | :--- | :--- |
| `pw_process_queue` | `every_minute` | `QueueManager::process_queue` | Traitement de la file d'attente (envoi des emails). |
| `pw_warmup_daily_increment` | `daily` (Minuit) | `WarmupEngine::process_daily_advancement` | Calcul du passage au jour suivant (`warmup_day++`). |
| `pw_daily_stats_aggregation` | `daily` | `Stats::aggregate_daily_stats` | Consolidation des stats de la veille dans `stats_daily`. |
| `pw_cleanup_queue` | `daily` | `QueueManager::cleanup` | Purge des vieux éléments de la file et logs. |
| `pw_daily_report` | `daily` | `EmailNotifications::send_daily_report` | Envoi du rapport email à l'admin. |
| `pw_cleanup_old_logs` | `daily` | `Logger::cleanup_old_logs` | Rotation des logs techniques. |
| `pw_cleanup_old_stats` | `weekly` | `Stats::cleanup_old_stats` | Purge des stats brutes très anciennes. |
| `pw_advisor_check` | `hourly` | `WarmupAdvisor::run` | Analyse de santé et recommandations automatiques. |

### Endpoints REST WordPress

| Route | Méthode | Callback | Rôle |
| :--- | :--- | :--- | :--- |
| `postal-warmup/v1/webhook` | `POST` | `WebhookHandler::handle_webhook` | Réception des événements Postal (Delivery, Bounce, Reply). |
| `postal-warmup/v1/test` | `GET` | `WebhookHandler::test_endpoint` | Vérification de connectivité simple. |

---

## 2. INTÉGRATION API POSTAL ACTUELLE

### Endpoints Postal Appelés

| Endpoint | Méthode | Paramètres Envoyés | Traitement de la Réponse |
| :--- | :--- | :--- | :--- |
| **`/send/message`** | `POST` | `to`, `from`, `subject`, `plain_body`, `html_body`, `reply_to`, `tag`, `headers` (`X-Warmup-Template`, `List-Unsubscribe`...) | **Critique**. Récupère le `message_id` en cas de succès pour le tracking. En cas d'erreur, log le message et déclenche le mécanisme de retry. |
| **`/messages`** | `GET` | `count=1` | **Diagnostic**. Utilisé uniquement pour vérifier que le serveur répond ("Health Check"). Le contenu est ignoré. |
| **`/suppressions`** | `GET` | *(Aucun)* | **Affichage**. Récupère la liste brute pour l'afficher dans l'admin. Aucune synchronisation locale. |
| **`/suppressions/delete`** | `POST` | `email` | **Action**. Supprime une entrée de la liste noire Postal suite à une action utilisateur. |

### Webhooks Gérés

L'endpoint `postal-warmup/v1/webhook` écoute et traite :

| Événement | Logique Exécutée |
| :--- | :--- |
| `MessageSent` | Enregistre l'événement `sent` dans l'historique. |
| `MessageDelivered` | Enregistre l'événement `delivered`. Incrémente les compteurs de succès. |
| `MessageDeliveryFailed` | Enregistre l'événement `failed`. Log une erreur critique. Décrémente le score de réputation interne. |
| `MessageBounced` | Enregistre l'événement `bounced`. Log un avertissement. Peut déclencher une pause serveur selon configuration. |
| `MessageLinkClicked` | Enregistre l'événement `clicked`. |
| `MessageLoaded` | Enregistre l'événement `opened`. |
| `DomainDNSError` | Enregistre l'événement `dns_error`. Log une alerte critique sur la config DNS. |
| `rcpt_to` (Message Entrant) | Détecte une réponse. Vérifie la boucle d'envoi. Ajoute une **réponse automatique** dans la file d'attente via `QueueManager`. |

### Données Ignorées / Non Exploitées

1.  **Contenu des messages** : Le plugin ne stocke jamais le corps des messages envoyés ou reçus via l'API.
2.  **Détails techniques des bounces** : Les raisons précises du bounce (code SMTP, message d'erreur distant) sont souvent présentes dans le payload webhook mais rarement structurées en base, juste loggées en JSON brut.
3.  **Quotas Postal** : L'API Postal fournit des infos sur les quotas du serveur (`/server`), mais le plugin calcule ses propres quotas locaux sans vérifier la limite réelle côté Postal.

---

## 3. BUGS IDENTIFIÉS ET RISQUES

### 1. Absence Critique de Générateur de Trafic (Seed List)
**Sévérité : Bloquant**
Le plugin contient toute l'infrastructure pour *gérer* un warmup (Calcul de quotas, File d'attente, Stratégies, Templates, Load Balancer), mais **aucun composant ne génère les emails initiaux**.
-   `WarmupEngine` calcule "combien" envoyer (`warmup_day`), mais ne déclenche pas l'ajout des emails.
-   `QueueManager` traite ce qui est présent, mais n'ajoute rien de lui-même (sauf des réponses aux emails entrants).
-   Il manque une fonctionnalité de "Seed List" (Liste de contacts) et un "Generator Service" qui, chaque jour, peuple la file d'attente avec des emails vers cette liste.
-   **Conséquence** : En l'état, le plugin ne fait rien sauf si on lui envoie des emails (mode "Répondeur" uniquement) ou si un outil externe injecte des tâches dans `postal_queue`.

### 2. Incohérence du Client HTTP
**Sévérité : Moyenne**
-   Le fichier `src/API/Sender.php` utilise directement `wp_remote_post()` pour l'envoi critique, dupliquant la logique de connexion (headers, timeout).
-   Le fichier `src/API/Client.php` existe et encapsule proprement ces appels, mais n'est utilisé que pour les actions secondaires (suppressions, tests).
-   **Risque** : Maintenance difficile. Si l'authentification change ou si on veut ajouter du logging global, il faut modifier deux endroits.

### 3. Race Condition dans `QueueManager`
**Sévérité : Moyenne**
-   La méthode `process_queue` sélectionne des items `pending` puis les met à jour en `processing`.
-   Bien qu'un système de verrou via Transient (`pw_queue_lock`) soit présent, ce n'est pas un verrou atomique strict (surtout avec certains object caches).
-   **Risque** : Si le Cron WP et le Cron Serveur tournent simultanément, des doublons d'envoi peuvent survenir. Une transaction SQL ou un verrou plus robuste (`GET_LOCK`) serait préférable.

### 4. Dépendance Externe Non Gérée (Action Scheduler)
**Sévérité : Faible**
-   Le plugin utilise `as_schedule_single_action` pour l'envoi asynchrone.
-   Il vérifie `function_exists`, mais si Action Scheduler n'est pas présent (ex: site sans WooCommerce), il fallback sur un mode synchrone qui peut faire timeout le processus PHP lors de gros volumes.
-   Le plugin semble inclure une version vendored dans `vendor/woocommerce/action-scheduler` mais l'initialisation dans `postal-warmup.php` est conditionnelle.

---

## 4. LACUNES FONCTIONNELLES

### 1. Simulation vs Natif
Le plugin réinvente la roue sur plusieurs aspects que Postal gère nativement :
-   **Tracking** : Le plugin reconstruit l'état des messages via webhooks. C'est fragile (si le webhook échoue, le statut reste "envoyé"). Il devrait pouvoir interroger l'API Postal pour confirmer le statut final en cas de doute.
-   **File d'attente** : Postal est un MTA avec sa propre queue. Le plugin ajoute une surcouche de queue WordPress. C'est nécessaire pour le "Warmup" (lissage du débit), mais cela ajoute de la complexité et de la latence.

### 2. Manque de "Seed List" (Liste de Contacts)
C'est la lacune majeure. Un outil de Warmup doit envoyer des emails à une liste de boîtes de réception contrôlées (Gmail, Outlook, etc.) pour générer de la réputation positive (Ouvrir, Cliquer, Marquer comme important).
-   Le plugin ne gère pas de "Contacts".
-   Il ne gère pas la rotation des contacts.
-   Il ne permet pas d'importer une liste CSV de destinataires.

### 3. Gestion des Bounces Limitée
Bien que détectés, les bounces ne semblent pas entraîner d'action corrective sur la source des données (puisqu'il n'y a pas de liste de contacts). Dans un scénario réel, une adresse qui bounce doit être immédiatement exclue des envois futurs pour ne pas griller la réputation. Ici, le plugin met juste en pause le serveur ou réduit le volume, mais ne "nettoie" pas la donnée.
