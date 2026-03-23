# Smart Internal Links (SIL)

Smart Internal Links est un plugin WordPress d'analyse SEO avancée. Il utilise les Embeddings OpenAI et la Théorie des Graphes pour cartographier vos contenus, identifier les failles de vos silos, et vous donner des actions hyper-ciblées pour dominer la SERP.

## Quick Start

1.  Placez le dossier dans `/wp-content/plugins/` et activez l'extension via WordPress.
2.  Dans **Smart Links > Réglages**, saisissez votre clé API OpenAI.
3.  Lancez la génération des Embeddings depuis le tableau de bord du plugin.
4.  Ouvrez la **Cartographie** pour visualiser vos silos sémantiques.
5.  Cliquez sur "Exporter l'Audit JSON" et confiez ce fichier à votre GEM pour obtenir votre plan d'action.

## Features

- **Cartographie Visuelle (SVG)** : Visualisez instantanément la structure de vos silos, les pages orphelines, et les Mégaphones (pages fortes) dans un moteur de rendu ultra fluide.
- **Analyse Sémantique Vectorielle** : Utilise `text-embedding-3-small` pour regrouper les articles par vrai sens, et non pas par simple correspondance de mots-clés.
- **Détection des Fuites (Leak Detective)** : Repère et nomme précisément les liens qui "fuient" hors d'un cocon sémantique et diluent votre PageRank interne (`cluster_permeability`).
- **Analyse des Trous Sémantiques** : Identifie les sous-thématiques manquantes de votre site et calcule les opportunités de mots-clés non exploitées.
- **Boutons d'Action Rapide IA** : 5 actions accessibles depuis la carte :
    - *Créer un pont sémantique*
    - *Trouver une ancre Zéro GSC*
    - *Générer via IA (Tickets SEO RankMath)*
    - *Inventer le lien*
    - *Désoptimiser un lien cannibal*

## Architecture & Interaction avec le GEM

Le plugin récupère la donnée de la Google Search Console et calcule les positions vectorielles. Cependant, l'intelligence stratégique est déportée vers une IA configurée (Le GEM).

1. **Le Plugin (Le Calculateurs)** : Produit un fichier JSON complet de la santé du site.
2. **Le GEM (Le Stratège)** : Propulsé par `Gem.md` (les instructions strictes de formatage) et `deep.md` (la théorie SEO du Link Juice). Il lit le JSON et dicte le plan 80/20.
3. **L'Utilisateur (L'Exécutant)** : Applique les recommandations du GEM dans la cartographie via les boutons IA génératifs.

## Configuration

| Réglage | Description | Recommandation |
|----------|-------------|---------|
| Clé API OpenAI | Obligatoire pour les calculs de similarité et l'UI d'édition | Utiliser un compte avec crédits |
| Modèle Embedding | Modèle de calcul des vecteurs | `text-embedding-3-small` |
| Seuil de Similarité | Précision requise pour qu'un lien soit suggéré | 0.70 - 0.75 |

## Documentation Complète

- [Documentation IA (llms.txt)](./llms.txt) : Détails techniques du JSON, clés de configuration du GEM et interaction entre le plugin et l'IA.
- [Instructions GEM (Gem.md)](./Gem.md) : Le prompt master à fournir à votre IA.
- [Base de Connaissances (deep.md)](./deep.md) : Les règles théoriques de SEO sémantique.

## Roadmap 2026

- **Historical Tracking & ROI Dashboard** : Enregistrement de l'état des silos (Perméabilité, Health Score) à chaque recalcul. Les variations de maillage seront confrontées aux courbes d'Impressions GSC (Avant/Après) pour prouver le ROI et l'efficacité des recommandations de l'IA.
- **Adoption Automatique des Orphelins (Bouton Mégaphone)** : Interface en 1 clic sur les pages orphelines pour simuler leur rattachement à la page la plus forte (Mégaphone) de leur silo mathématique et déclencher une indexation forcée par le flux.
- **Cornerstone Hubs (Content Generation)** : Détection des trous sémantiques massifs (True Gaps) dans un silo et génération automatique par l'IA d'un squelette de page pilier (H1/H2) structurée pour capturer cette autorité.
- **Copywriting Sémantique (Optimisation CTR GSC)** : Détection des anomalies GSC ("Top Position mais Zéro Clic") et réécriture chirurgicale des Titles/Metas. L'approche est *strictement sémantique et anti-putaclic*, basée sur la réponse à l'intention de recherche exacte pour voler le clic à la concurrence de façon organique.
