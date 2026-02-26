# Propositions d'Améliorations - Warmup Pro V3.4

Ce document liste les améliorations techniques et fonctionnelles identifiées pour compléter et fiabiliser le plugin, en attente de validation.

## 1. Fonctionnalités Manquantes (Bloquants / Priorité Haute)

### A. Interface de Gestion des Chaînes (Threads)
**Constat :** L'onglet "Chaînes" dans l'administration des templates n'est pas encore implémenté visuellement, bien que la logique backend (AJAX) soit prête.
**Proposition :**
- Créer un fichier JS/CSS dédié pour l'interface "Chaînes".
- Afficher les templates sous forme de flux visuel (Template A → Template B → Template C).
- Boutons pour "Compléter la chaîne" (création automatique des templates manquants).

### B. Widget Dashboard "Threads"
**Constat :** Les méthodes `get_thread_stats` et `get_recent_threads` existent dans `Stats.php` mais ne sont pas affichées sur le tableau de bord.
**Proposition :**
- Ajouter un widget dans `partials/dashboard.php` pour visualiser les conversations en cours.
- Métriques clés : Nombre de threads actifs, Taux de réponse, Dernières interactions.

### C. Liste de Contacts (Seed List)
**Constat :** Le plugin ne gère pas de liste de destinataires ("Seed List"). Il dépend entièrement des réponses manuelles ou d'apports externes.
**Proposition :**
- Créer une table `postal_contacts` (email, status, last_sent).
- Créer un importateur CSV pour les contacts.
- Ajouter un "Générateur de Trafic" qui pioche dans cette liste pour initier des conversations (et pas seulement répondre).

## 2. Améliorations Techniques (Stabilité / Code Quality)

### A. Unification du Client HTTP
**Constat :** Duplication de logique entre `src/API/Client.php` (utilisé pour les outils) et `src/API/Sender.php` (utilisé pour l'envoi).
**Proposition :**
- Refondre `Sender.php` pour qu'il utilise `Client::request()`.
- Centraliser la gestion des erreurs HTTP et des logs API.

### B. Robustesse de la Queue (Race Conditions)
**Constat :** Le verrouillage via Transients WP (`pw_queue_lock`) n'est pas atomique.
**Proposition :**
- Utiliser des verrous SQL (`GET_LOCK`) si la base de données le permet, ou une librairie de verrouillage plus robuste.
- Passer à une gestion de queue plus performante (tables dédiées avec status atomic updates).

### C. Nettoyage Automatique des Données
**Constat :** La table `postal_stats_history` va grossir indéfiniment avec le temps.
**Proposition :**
- Implémenter une tâche CRON de purge/archivage configurable (ex: garder 90 jours d'historique détaillé, puis ne garder que les agrégats).

## 3. UX / UI

### A. Prévisualisation des Emails
**Constat :** L'éditeur de template est basique.
**Proposition :**
- Ajouter un bouton "Envoyer un test" directement depuis l'éditeur de template.
- Prévisualiser le rendu des variables (`{{civilite}}`, `{{date}}`) en temps réel.

### B. Assistant de Configuration (Onboarding)
**Constat :** La configuration initiale est complexe (Clés API, Webhooks, DNS).
**Proposition :**
- Créer un "Wizard" étape par étape pour guider le premier paramétrage.
- Vérificateur automatique de configuration DNS (SPF/DKIM) intégré au dashboard.

---
*Document généré automatiquement suite à l'audit du code.*
