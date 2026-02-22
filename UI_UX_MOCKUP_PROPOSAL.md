# Mockup UI/UX – Plugin Warmup WordPress (Design & Surf)

Ce document détaille la proposition de modernisation de l'interface utilisateur (UI) et de l'expérience utilisateur (UX) pour le plugin Postal Warmup. L'objectif est de rendre l'interface plus lisible, moderne et réactive sans altérer la logique métier existante.

## 1. Dashboard Principal – Vue d’ensemble

Une vue synthétique permettant de surveiller la santé de tous les serveurs et ISPs en un coup d'œil.

*   **Tableau Principal :** Colonnes dynamiques [Serveur (IP/Domaine) | ISP (Stratégie) | Quota Actuel | Pending | Processing | Sent (24h) | Failed (24h)].
*   **Visualisation des Quotas :** Barres de progression colorées (ex: 80/150).
    *   **Vert :** Quota < 80% utilisé.
    *   **Jaune :** Quota > 80% utilisé (Attention).
    *   **Rouge :** Quota atteint ou serveur désactivé.
*   **Filtres Rapides :** Par Serveur, Par ISP, Par Date.

## 2. Serveur Postal – Gestion Simplifiée

Interface épurée pour la gestion des serveurs d'envoi.

*   **Liste :** [IP | Domaine | Status | Quota (via stratégie)].
*   **Ajout Rapide :** Formulaire minimaliste (IP + Domaine uniquement). Les quotas journaliers sont désormais gérés par les stratégies ISP, donc plus de champ "Quota" manuel ici.
*   **Badges de Statut :**
    *   🟢 **Vert :** Actif
    *   🔴 **Rouge :** Désactivé

## 3. ISP Manager – Gestion des Stratégies

Clarification de l'interface de gestion des fournisseurs d'accès (Gmail, Yahoo, etc.).

*   **Liste :** ISPs affichés avec leur stratégie associée.
*   **Nettoyage :** Suppression des champs obsolètes "Quota global" et "Limite horaire" (gérés dynamiquement ou via templates).
*   **Badges de Stratégie :**
    *   🟢 **Douce (Conservative) :** Vert
    *   🟡 **Agressive :** Jaune
    *   🔵 **Personnalisée :** Bleu

## 4. Scenario Engine – Vue Visuelle

Une interface graphique pour comprendre et gérer les enchaînements d'actions.

*   **Liste Scénarios :** Nom, Nombre de Steps, Status.
*   **Drag & Drop :** Réordonnancement facile des étapes à la souris.
*   **Arbre de Décision (Mini-Graph) :**
    ```
    Step1 ├─ OK → Step2
          └─ STOP → End

    Step2 ├─ OUI → Step3
          └─ NON → Step4
    ```
*   **Icônes Contextuelles :** Icône Serveur, ISP, Template visible sur chaque step pour une identification rapide.

## 5. Step Editor – Édition Modulaire

Lors de l'édition d'une étape de scénario.

*   **Dropdown Template :** Sélection simple d'un template existant.
*   **Champs en Lecture Seule :** Fuseau horaire & Plages horaires (récupérés automatiquement depuis le template sélectionné pour éviter les incohérences).
*   **Infos Non-Modifiables :** Serveur, ISP, Stratégie (affichés pour contexte uniquement).
*   **Actions :** 💾 Enregistrer, ❌ Annuler.

## 6. Queue & Warmup Dashboard

Le cœur du monitoring temps réel.

*   **Colonnes :** Pending | Processing | Sent | Failed | Top ISP.
*   **Graphiques Dynamiques (Chart.js / ApexCharts) :**
    *   Progression du warmup par Serveur et ISP.
    *   Volume journalier vs Limite de la stratégie.

## 7. Styles et Palette (Design System)

Une identité visuelle cohérente.

*   **Couleurs Sémantiques :**
    *   🟢 **Succès / OK**
    *   🟡 **Warning / Attention**
    *   🔴 **Erreur / Stop / Désactivé**
    *   🔵 **Info / Configuration Personnalisée**
*   **Typographie :** Moderne (Inter ou Roboto).
*   **Layout :** Espacements généreux (White space), boutons arrondis, états Hover/Focus clairs.

## 8. Responsiveness

Interface "Mobile-First" adaptée à tous les écrans.

*   **Tablettes/Mobiles :** Les tableaux deviennent scrollables horizontalement ou s'adaptent en cartes ("Stack").
*   **Headers Sticky :** Les entêtes de tableaux restent visibles au scroll.
*   **Accordéons :** Pour les sections longues ou techniques.

## 9. Interactions JS (UX)

*   **Feedback Immédiat :** Toasts / Snackbars pour confirmer les actions (ex: "Serveur sauvegardé").
*   **Indicateurs de Chargement :** Spinners sur les boutons lors des appels AJAX lourds.
*   **Drag & Drop :** Fluide et naturel pour les steps de scénarios.

## 10. Documentation et Aide Intégrée

*   **Tooltips :** Icônes `?` au survol pour expliquer les termes techniques.
*   **Mini-Guides :** Explications contextuelles sur :
    *   Le fonctionnement des Quotas & Stratégies.
    *   L'impact du Fuseau Horaire.
    *   La logique du Scenario Engine.

## 11. Bonus UX (Évolutions Futures)

*   **Dark Mode :** Bascule Thème Clair / Sombre.
*   **Filtres Interactifs :** Recherche instantanée dans les tableaux.
*   **Prêt pour le futur :** Structure préparée pour l'ajout de modules visuels avancés (métriques détaillées, anti-abuse, routing intelligent).

---

**Résumé :**
Cette proposition modernise l'outil sans toucher au "moteur" PHP existant. Elle transforme une interface d'administration technique en un véritable **Tableau de Bord de Pilotage**, plus sûr et plus agréable à utiliser au quotidien.
