# 5 Propositions d'Amélioration de l'Ergonomie (UX/UI)

Suite à l'analyse de l'interface d'édition de templates, voici 5 axes d'amélioration pour rendre l'outil plus fluide et intuitif.

## 1. Réorganisation Logique des Onglets (Priorité Haute - Implémenté)
**Problème :** L'onglet "Postal" (Configuration technique d'envoi) apparaissait avant "Mailto" (Configuration du lien généré).
**Solution :** Inverser l'ordre pour suivre le flux logique de l'utilisateur :
1.  **Général** : Nom, Statut, Dossier.
2.  **Mailto** : Ce que l'utilisateur *configure* pour générer ses liens (Sujet, Corps, Expéditeur).
3.  **Postal** : Ce que le système *envoie* techniquement (le moteur sous-jacent).
4.  **Stats** : Le résultat.

## 2. Barre d'Outils "Sticky" (Ancrée)
**Problème :** Lors de l'édition d'un template long (HTML complexe), la barre d'outils contenant les boutons "Insérer Variable", "Spintax", "Preview" disparaît lorsque l'on scrolle vers le bas. L'utilisateur doit remonter pour utiliser ces fonctions.
**Solution :** Appliquer un style CSS `position: sticky; top: 0; z-index: 10;` à la classe `.pw-variant-toolbar`. Cela gardera les outils toujours visibles en haut de la zone d'édition, quel que soit le niveau de défilement.

## 3. Auto-complétion Intelligente des Variables (`{{`)
**Problème :** L'insertion de variables nécessite de quitter le clavier pour utiliser la souris, sélectionner dans la liste déroulante, puis cliquer sur "Insérer". C'est lent et casse le flux de rédaction.
**Solution :** Implémenter un déclencheur clavier. Lorsque l'utilisateur tape les caractères `{{` dans un champ texte :
*   Ouvrir un petit menu flottant (dropdown) à la position du curseur.
*   Lister les variables disponibles (`prenom`, `email`, `date`, etc.).
*   Permettre la sélection via les flèches du clavier et `Entrée`.

## 4. Aperçu en Temps Réel (Split View)
**Problème :** Le bouton bascule "Code / Preview" oblige à des allers-retours constants pour vérifier le rendu visuel d'une modification HTML.
**Solution :** Sur les écrans larges (> 1200px), proposer un mode "Split View" (Vue Scindée) :
*   À gauche : L'éditeur de code (Source).
*   À droite : L'aperçu (Rendu).
*   Le rendu se met à jour automatiquement après une courte pause de frappe (debounce 500ms).

## 5. Actions en Masse (Bulk Actions) sur la Grille
**Problème :** La gestion de nombreux templates est fastidieuse. Pour déplacer ou supprimer 10 templates, l'utilisateur doit répéter l'action 10 fois (clic menu, confirmation).
**Solution :** Ajouter un mode "Sélection" dans la vue grille :
*   Permettre de cocher plusieurs cartes de templates.
*   Afficher une barre d'actions flottante en bas d'écran : "Déplacer (3)", "Supprimer (3)", "Archiver (3)".
*   Cela améliorerait considérablement la productivité pour l'organisation des dossiers.
