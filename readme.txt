=== Smart Internal Links ===
Contributors: Jennifer Larcher
Tags: seo, internal links, embeddings, openai, automations
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 2.3.0
Requires PHP: 7.4
License: GPLv2 or later

Génère automatiquement un maillage interne pertinent grâce à l'IA (OpenAI Embeddings) et permet une visualisation graphique des liens.

== Description ==

Smart Internal Links est un plugin SEO avancé qui utilise la puissance de l'intelligence artificielle (OpenAI) pour analyser sémantiquement vos contenus et proposer des liens internes contextuels.

**Fonctionnalités principales :**
*   **Analyse Sémantique :** Utilise les embeddings OpenAI pour comprendre le sens de vos articles, pas juste les mots-clés.
*   **Suggestions Automatiques :** Propose des liens pertinents vers d'autres articles ou pages de votre site.
*   **Insertion "Humaine" :** Rédige des phrases de liaison naturelles ou insère des liens sur des ancres existantes fluides.
*   **Visualisation Graphique SVG :** Affiche un graphe interactif (billes et liens) ultra-léger et fluide pour repérer les clusters et les orphelins.
*   **Contenu Pilier (Cornerstone) :** Marquez vos articles stratégiques pour qu'ils soient prioritaires dans les suggestions.
*   **Optimisation Cache :** Système intelligent pour ne pas consommer de crédits API inutilement.

== Installation ==

1.  Téléchargez le plugin et placez le dossier `smart-internal-links` dans `/wp-content/plugins/`.
2.  Activez l'extension via le menu "Extensions" de WordPress.
3.  Allez dans **Smart Links > Réglages**.
4.  Entrez votre clé API OpenAI.
5.  Configurez vos préférences (seuil de similarité, nombre de liens max, etc.).
6.  Allez dans le tableau de bord principal et cliquez sur "Générer les embeddings".

== Configuration (API OpenAI) ==

Ce plugin nécessite une clé API OpenAI payante (les crédits gratuits expirent souvent).
Modèles utilisés :
*   `text-embedding-3-small` (très peu coûteux) pour l'analyse.
*   `gpt-4o` ou `gpt-4o-mini` pour la rédaction des ancres.

== Screenshots ==

1.  Tableau de bord de gestion des liens.
2.  Visualisation graphique du maillage (SVG).
3.  Réglages du plugin.

== Changelog ==

= 2.3.0 =
*   New: Remplacement complet de Vis.js par un moteur de rendu SVG natif (plus rapide, plus léger, meilleure gestion du zoom).
*   Fix: Correction des liens "Voir" et "Éditer" dans le panneau latéral.
*   Fix: Correction du comptage des liens entrants/sortants (supporte désormais les formats source/target et from/to).
*   UX: Amélioration du design du panneau latéral (meilleure lisibilité, boutons accessibles).
*   Dev: Nettoyage du code JS (suppression des dépendances inutiles).

= 2.2.0 =
*   Fix: Correction de l'insertion des liens (force le format HTML).
*   Fix: Amélioration de la visualisation du graphe (gestion d'erreurs et timeouts).
*   Perf: Optimisation de la consommation de tokens (cache "pas de paragraphes").
*   Sécurité: Renforcement des vérifications AJAX et échappement des données.

= 2.1.0 =
*   Ajout de la visualisation graphique (Vis.js).
*   Support des "Contenus Piliers".

= 1.0.0 =
*   Version initiale.
