Roadmap - Smart Internal Links

---

## 🔒 PHASE 0 — Stabilisation Topologique des Cocons (GATE OBLIGATOIRE)

**Priorité : ABSOLUE — Prérequis à toute autre action SEO**

> ⚠️ **RÈGLE DU VERROU (GATE)** : Tant que cette phase n'est pas terminée, les actions suivantes sont **INTERDITES** :
> - 🌉 Ponts sémantiques (Alinéa 4.4)
> - 📈 Striking Distance (Alinéa 2.3)
> - 📉 Content Decay (Alinéa 2.2)
> - Toute optimisation cosmétique du maillage
>
> **Seule exception autorisée** : les recommandations de **Content Gap** (Alinéa 4.1 — Remplissage des Gaps), car elles renforcent les silos sans risquer de les fragiliser.

**Contexte :** Si le site a un historique technique lourd ou des silos poreux, il est impératif de figer la structure avant d'ajouter des liens. Créer des ponts sur des fondations instables revient à construire sur du sable — perte de temps garantie.

### Étape 0.1 — Ré-ancrage des Intrus (Alinéa 5.1 + 5.2) `[PRIORITÉ ABSOLUE]`

> Objectif : Figer les silos en éliminant les fuites topologiques.

- [x] **Audit complet** : Lister tous les `is_intruder: "true"` avec leur `node_semantic_target`.
- [x] **Décision par intrus** : Pour chaque intrus, choisir :
  - **Option A (DÉFAUT)** — Repatrier vers `_sil_ideal_silo` via `[🔄 Repatrier]` (Principe Semantic-First).
  - **Option B** — Créer un pont vers le silo idéal via `[🌉 Pont]` si déplacement non souhaité.
- [x] **Vérification** : Aucun nœud `is_intruder` ne subsiste après traitement.

### Étape 0.2 — Traitement des Siphons (Alinéa 3.1) `[PRIORITÉ HAUTE]`

> Objectif : Rétablir la circulation du jus SEO dans les cocons.

- [x] **Audit complet** : Lister tous les `is_siphon: "true"` (in_degree > 3, out_degree = 0).
- [ ] **Action systématique** : Créer un lien sortant vers le Pivot du silo (`is_pivot: true`) via `[✨ Inventer le lien (IA)]`.
- [ ] **Vérification** : Aucun nœud `is_siphon` ne subsiste. Chaque siphon redirige du jus vers son Pivot.

### Étape 0.3 — Adoption des Orphelins Matures `[PRIORITÉ MOYENNE]`

> Objectif : Rattacher les pages isolées ayant une ancienneté prouvée.

- [x] **Filtre de maturité** : Ne traiter QUE les orphelins dont `post_date > 60 jours`.
  - Les orphelins récents (< 60 jours) sont en phase de découverte naturelle → ne pas intervenir.
- [x] **Audit complet** : Lister tous les `is_orphan: "true"` ET `post_date > 60 jours`.
- [x] **Trouver le Mégaphone** : Page avec `is_pivot: true` ou `sil_pagerank ≥ 60` dans le **même** `cluster_id`.
- [x] **Action** : `[✨ Inventer le lien (IA)]` depuis le Mégaphone vers l'orphelin.
- [ ] **Cas urgent** : Si l'orphelin a aussi `index_status ≠ SUBMITTED_AND_INDEXED` → traitement en priorité 0.

### Critères de Déblocage (Sortie du GATE)

> ✅ **Pour débloquer les phases suivantes, TOUTES ces conditions doivent être remplies :**

| # | Condition | Vérification |
|---|-----------|-------------|
| 1 | Zéro intrus restant | `is_intruder: "true"` count = 0 |
| 2 | Zéro siphon restant | `is_siphon: "true"` count = 0 |
| 3 | Zéro orphelin mature non traité | `is_orphan: "true"` ET `post_date > 60j` count = 0 |
| 4 | Perméabilité des silos < 25% | `stats_summary.silo_health` conforme (Alinéa 1.6) |

> 🔓 **Une fois ces 4 conditions validées**, les ponts sémantiques, le striking distance et le content decay sont autorisés.

---

## ⏳ PHASE 0.5 — Délais de Grâce Temporels (Filtre Sélectif)

**Priorité : HAUTE — À intégrer dans `deep.md` (Article 7) + `Gem.md`**

**Contexte :** Intervenir trop tôt sur un contenu neuf, c'est corriger un diagnostic que Google n'a pas encore stabilisé. Le temps est une variable aussi importante que la sémantique.

### Principe : Grace Period sélective par type de diagnostic

| Diagnostic | Grace Period | Raison |
|---|---|---|
| Positions faibles / Striking Distance | ⏳ **60j** post-publication | Google n'a pas stabilisé le ranking |
| Content Decay / Variations de rendement | ⏳ **90j** post-modification | Trop tôt pour mesurer l'effet d'une MAJ |
| CTR faible (`gsc_ctr < 1.5%`) | ⏳ **60j** post-publication | Pas assez de données d'impressions |
| Cannibalisation | ⏳ **60j** post-publication | Besoin de données de queries stabilisées |
| **Intrus / Siphon / Orphelin** | ❌ **Pas de grâce** | Structurel (topologie), pas GSC-dépendant |
| **Indexation (`CRAWLED_NOT_INDEXED`)** | ❌ **Pas de grâce** | Toujours urgent |
| **Content Gap** | ❌ **Pas de grâce** | Renforce les silos |

### Tâches d'implémentation

- [ ] **`deep.md`** : Créer un **Article 7 — Délais de Grâce Temporels** avec les seuils et la matrice ci-dessus.
- [ ] **`Gem.md`** : Ajouter un pré-filtre dans chaque Étape concernée (2, 3, 4) invoquant l'Article 7 avant de diagnostiquer.
- [ ] **Gem.md — Étape 2 (SAMU)** : Exclure du decay les articles dont `post_modified < 90 jours`.
- [ ] **Gem.md — Étape 3 (TURBO)** : Exclure du striking distance les articles dont `post_date < 60 jours`.
- [ ] **Gem.md — Canvas** : Ajouter une mention `⏳ En grâce (Xj restants)` pour les articles filtrés (visibilité sans action).

---

🚨 BMAD — Inversion Logique "Booster" (Cible vs Source)

Priorité : Haute — Focus UI/UX

Objectif : Transformer le bouton "Booster" en un outil d'acquisition de jus : on sélectionne la page à pousser (Cible), et on cherche un "Mégaphone" (Source) pour lui envoyer de l'autorité.

Tâche 1 — Inversion de l'ouverture de la modale (JS) [⚡ Flash-Ready]

[x] Fichier : assets/admin.js

[x] Dans renderGapTable, trouver l'écouteur de clic sur .sil-booster-btn.

[x] Modifier l'appel à openBridgeModal : passer l'ID de l'article en 2ème argument (Target) et null en 1er (Source).

// Avant : openBridgeModal(articleId, null, keyword)
// Après : openBridgeModal(null, articleId, keyword)


Tâche 2 — Adaptation de l'interface de la modale (JS) [⚡ Flash-Ready]

[x] Fichier : assets/sil-bridge-manager.js

[x] Dans openBridgeModal(sourceId, targetId, anchor), détecter le "Mode Booster" (sourceId === null && targetId !== null).

[x] Modifier dynamiquement le DOM de la modale :
🚀 Booster l'article, Étape 1 : Chercher un article SOURCE (Mégaphone)...

[x] Stocker le targetId fixe et orienter la recherche vers des sources potentielles. S'assurer que lors de l'insertion finale, le target_id est bien celui stocké initialement.

Tâche 3 — Support du sens inverse (PHP)

[x] Fichier : includes/class-sil-ajax-handler.php

- [x] **Vérifier que sil_search_posts_for_link retourne bien les impressions GSC pour chaque article.**
- [x] **S'assurer que les résultats permettent d'identifier visuellement les Mégaphones (sources fortes) dans la modale via le volume d'impressions.**

🧠 Phase 6 — Moteur de Recherche Hybride (RRF & RankBrain)

Priorité : Stratégique — Focus Algorithmique

Objectif : Implémenter le Reciprocal Rank Fusion pour fusionner l'IA (sens/embeddings) et la GSC (performance/lexical), tout en gérant les "Intrus" topologiques et les nouveaux indicateurs critiques du GEM V17.

Tâche 1 — Core Algorithm & Data Service (PHP)

[x] Fichier : includes/class-sil-seo-utils.php

[x] Service de Performance GSC : Créer une méthode mutualisée pour récupérer les clics/impressions en une seule requête SQL pour une liste d'IDs (indispensable pour le tri RRF sans latence).

[x] Calcul des nouveaux indicateurs (V17) :

is_decay_critical : Logique de détection de chute de trafic (comparaison N vs N-1).

cannibalization_risk : Score de similarité sémantique élevé entre deux pages positionnées sur le même cluster.

index_status : Récupération du statut exact via l'API Indexing/GSC.

- [x] **Calculateur RRF** : Créer la fonction `calculate_rrf_score($semantic_rank, $gsc_rank, $k = 60)`.
- [x] **Implémentation de la fusion** : Appliquer la formule $RRFscore(d) = \sum_{r \in R} \frac{1}{k + r(d)}$. Le score final est la somme des inverses des rangs dans chaque liste.

Tâche 2 — Gestion du "Cold Start" & "Plan de Montage" (GEM)

[x] Logique de création (Pre-GSC) : Pour les recommandations de type CREATE (GEM), forcer l'algorithme à ignorer le score lexical (GSC). La proximité sémantique par embeddings ($> 0.80$) devient le seul critère.

[x] Prime de Fraîcheur : Si un article est publié depuis moins de 30 jours ou possède peu d'impressions, appliquer une pondération automatique (ex: 90% Sémantique / 10% GSC) pour ne pas pénaliser les "Pépites".

[x] Ajouter un flag is_new_content dans les résultats JSON pour l'interface.

Tâche 3 — Adaptation de find_similar_posts() (Tuyauterie)

- [x] Modifier la méthode pour accepter le paramètre `$search_mode` ('target' ou 'source').
- [x] **Filtrage Sémantique** : Appliquer un seuil strict (Threshold) à 0.80 pour garantir la cohérence thématique avant d'appliquer le tri RRF.
- [x] **Mode Source (Booster)** : Identifier et prioriser les "Mégaphones" (plus haut volume d'impressions GSC) parmi les voisins sémantiques.
- [x] **Inclure le hybrid_confidence_score (0-100%) dans le retour JSON.**

Tâche 4 — Diagnostic de Divergence & UX [⚡ Flash-Ready]

[x] Alerte d'indexation manquante : Afficher une notice/bannière dans la modale si les embeddings sont absents (invitant à cliquer sur "Indexer tout le contenu").

[ ] Visualisation de l'Intrus (👾) : Améliorer le rendu Cytoscape pour mettre en évidence les nœuds dont la couleur (Sens/Embedding) jure avec le conteneur (Cluster topologique).

[ ] Indicateur UI : Dans la modale de maillage, ajouter une jauge de pertinence ou une icône "RankBrain" à côté de chaque résultat.

[ ] Tooltip explicatif : "Score de consensus : [X]% (Confirmé par IA + GSC)".

🛠️ Phase 7 — Arsenal d'Outils SIL (Boutons Actionnables)

Objectif : Implémenter les 5 boutons spécifiques requis par la configuration MASTER V17 pour l'audit.

Tâche 1 — Implémentation des Boutons UI (JS/CSS) [⚡ Flash-Ready]

- [x] 🌉 **Créer un pont sémantique** : Intégration du workflow hybride (Phrase de transition IA + Lien). Utile quand le mot-clé GSC est absent du texte source mais thématiquement pertinent.
- [x] 🤖 **Trouver une ancre (Zéro GSC)** : Mode spécifique pour pages sans data GSC (IA génère l'ancre). Bouton de secours crucial pour les pages nouvelles.
- [x] ✨ **Générer via IA (RankMath)** : Trigger de réécriture du Titre SEO et de la Meta Description si gsc_ctr < 1.5% ou is_decay_critical == true.
- [x] ✨ **Inventer le lien (IA)** : Automatisation 80/20 pour boucher les trous sémantiques identifiés dans les silos sans intervention manuelle lourde.
- [x] 📉 **Désoptimiser (IA)** : Action corrective pour les conflits de cannibalisation (cannibalization_risk) ou désamorçage des ancres sur-optimisées.

🐛 Phase 8 — Corrections de Bugs Critiques

Priorité : Très Haute — Focus Éditeur Gutenberg

Objectif : Sécuriser l'intégrité des blocs Gutenberg lors des manipulations de contenu via l'IA.

Tâche 1 — Correction de la double encapsulation Gutenberg (PHP) [⚡ Flash-Ready]

[x] Fichier : includes/class-sil-ajax-handler.php

[x] Diagnostiquer la méthode `sil_apply_anchor_context` : le texte de remplacement s'encapsule dans de nouvelles balises `<!-- wp:paragraph -->` même si le paragraphe original les possède déjà.

[x] Adapter la logique de remplacement : Vérifier si le paragraphe dans `$haystack` est déjà enveloppé par un bloc Gutenberg avant d'ajouter les marqueurs `<!-- wp:paragraph -->` autour de `$final_text`, ou remplacer le bloc entier.


🗓️ À faire pour demain (2026-03-20)

1. Filtrage du Graphe

[x] Exclure les articles noindex du graphe : Modifier la récupération des données du graphe (class-sil-cluster-analysis.php) pour ne pas inclure les nœuds dont le meta SEO est réglé sur noindex.

2. Création de Pont Sémantique

[x] Révision du bouton d'insertion : Vérifier les contrastes de couleurs dans la modale de création de pont. Le bouton de validation semble être "bleu sur bleu".

[x] Correction de l'affichage de la modale : Résoudre le problème de défilement. Lorsqu'on crée un pont en bas de la modale, l'affichage se fait en dessous de la zone visible, forçant l'utilisation de l'ascenseur.

3. Recommandations de Silotage

[x] Nommage des silos dans les recommandations : Remplacer l'affichage type "Silo 9001" par un nom plus explicite comme "Thématique : [Nom du Pivot]" ou le titre de l'article pivot du silo.

4. Suggestions de Metas SEO [⚡ Flash-Ready]

[ ] Édition des suggestions : Permettre la réécriture manuelle des metas (Title/Description) après la suggestion de l'IA, ou la validation directe en l'état.

🎨 Suggestions d'Amélioration UX/UI (Priorités Design)

1. Refactorisation de l'Architecture CSS

[x] Suppression des styles "inline" : Déplacer tous les styles injectés via JS vers des classes CSS dédiées dans admin.css.

[x] Design Tokens (Thématisation) : Unifier l'utilisation des variables --sil-primary et remplacer les couleurs codées en dur par des jetons cohérents.

2. Icônographie "Premium" (Remplacement des Emojis)

[x] Remplacement des Emojis par des SVG : Remplacer les emojis (👾, 🚩, 🧼, 🥇, 🥈, ⚠️) par un set d'icônes SVG professionnel (Lucide ou Heroicons).

[ ] Indices visuels de statut (V17) : Ajouter des icônes spécifiques pour les "Siphons" (⚠️), les "Intruders" (👾) et les "Orphans" stratégiques basés sur les données du graphe.

3. Fluidité des Interactions (Micro-interactions)

[x] Feedback sans interruption : Remplacer les alert() par un système de Toasts/Notifications.

[x] Transitions de chargement : Améliorer les états de chargement (skeletons ou spinners personnalisés).

[x] Animations de volet : Assurer que le panneau latéral s'ouvre avec une animation de glissement "smooth".

4. Ergonomie du Graphe (Cytoscape) [⚡ Flash-Ready]

[ ] Typographie "Tech/SEO" : Adopter la police Fira Sans pour le corps et Fira Code pour les données techniques.

[ ] Boutons flottants de contrôle : Rendre les boutons "Centrer" et "Zoom" plus accessibles via une barre d'outils flottante.

🚀 Innovations Stratégiques (Futur / R&D)

🏛️ Option A : Générateur de Pages Piliers & Stratégie Éditoriale Actionnable

[ ] Le "Business Case" du Contenu (ROI Sémantique) : Expliquer pourquoi écrire (Défense de silo, Potentiel GSC, Correction de dette sémantique) pour justifier l'effort de rédaction.

[ ] Le "Contrat de Liaison" (Maillage Prédictif) : Désigner la source (Mégaphone) et l'ancre (Exacte/Naturelle) avant la rédaction pour garantir le transfert de jus immédiat.

[ ] Futur : Analyse d'Intention LLM : Faire évoluer le Gem.md pour déduire l'angle (Comparatif, FAQ, Guide) via l'analyse des requêtes GSC.

[ ] Détection de "Silo Master" : Identifier automatiquement quand un silo sémantique a besoin d'une page centrale de référence.

[ ] Génération IA d'Articles-Piliers : Création d'un article "Guide Complet" qui résume chaque article du silo et distribue le jus SEO de manière hiérarchique.

[ ] Maillage Réciproque (Hub & Spoke) : Forcer ou suggérer un lien de l'article vers sa page Pilier pour sceller l'autorité thématique du silo.

[ ] Templates "Magazine" : Mise en page spécifique (Grid/Mosaic) pour ces pages piliers afin de les différencier visuellement.

🎯 Option B : Optimisation Prédictive des Ancres (CTR)

[ ] Audit de Distribution d'Ancres : Analyser la diversité des textes d'ancres pointant vers une page pour éviter la sur-optimisation tout en restant pertinent.

[ ] Recommandations GSC : Utiliser les "Real Queries" de la Search Console pour suggérer des ancres basées sur le meilleur score RRF lexical.

[ ] Vérification de Cohérence : Alerter si une ancre "Optimisée" est utilisée trop fréquemment par rapport aux ancres naturelles.

🛡️ Option C : Sculptage de Link Juice & Crawl Budget

[ ] Détection de "Fuite de Jus" : Cartographier les liens sortants vers des pages à faible valeur SEO (tags inutiles, archives vides, mentions légales).

[ ] Tableau de bord de Sculptage : Proposer en un clic de passer ces liens en nofollow ou de les supprimer pour "pousser" toute la puissance vers les pages stratégiques.

🛑 Option D : Anti-Cannibalisation IA (Vérification Avant Suggestion)

[x] Filtre de Similarité Pré-Génération : Injecter la liste des articles existants du silo dans le prompt IA pour lui interdire de suggérer des sujets déjà couverts.

[x] Vérification Vectorielle Hybride (Validé) : Utiliser le moteur RRF comme socle unique pour croiser les nouvelles idées avec l'existant. Similarité requise < 92% pour valider une suggestion. Intégré dans le Pilot Center (Duel de Pages).

🛠️ Dette Technique & Architecture (Clean Code)

📦 1. Découpage du fichier principal (smart-internal-links.php)

[ ] Extraction de SIL_Admin_Manager : Gérer l'ajout des menus, sous-menus et les notices d'administration.

[ ] Extraction de SIL_Asset_Loader : Gérer l'enregistrement et le chargement conditionnel des scripts/styles.

[ ] Extraction de SIL_Content_Observer : Déplacer la logique des hooks save_post et transition_post_status.

[ ] Extraction de SIL_Embedding_Service (Priorité : Critique) : Isoler la logique d'appel à l'API OpenAI et le calcul de similarité. Note : Prérequis indispensable au moteur de recherche RRF.

🔌 2. Refactorisation des Handlers AJAX (class-sil-ajax-handler.php)

[ ] Éclatage en Sous-Handlers : Diviser par domaine : SIL_Ajax_Graph, SIL_Ajax_GSC, SIL_Ajax_Maillage, SIL_Ajax_Silos.

[ ] Standardisation des Réponses : Créer une méthode helper pour uniformiser les réponses JSON et la gestion d'erreurs.

🧹 3. Qualité de Code & DRY [⚡ Flash-Ready]

[ ] Unification de l'extraction GSC : Centraliser la lecture des données GSC pour éviter les duplications SQL (Initié en Phase 6).

[ ] Utilisation de Guard Clauses : Rédiger des retours précoces pour réduire l'imbrication des fonctions.

[ ] Type Hinting : Ajouter des types PHP 7.4+ aux arguments et retours de fonctions.

📘 Guide des Boutons d'Indexation

Pour clarifier l'interface, voici à quoi servent les différents boutons "d'indexation" :

"Rafraîchir l'indexation" (dans l'édition d'un article) :

Action : Interroge l'API Google Search Console pour cet article précis.

Utilité : Met à jour immédiatement le badge de statut (Indexé / Non indexé).

"Synchronisation GSC" (Réglages GSC / Dashboard) :

Action : Synchronise l'ensemble du site avec la Google Search Console.

Utilité : Récupère les mots-clés, positions, impressions et le statut d'indexatio

"Indexer tout le contenu" (Page principale Smart Links) :

Action : Indexation Sémantique via OpenAI.

Utilité : Calcule les "embeddings" pour comprendre le sens des articles. Crucial pour le fonctionnement du moteur hybride RRF.

"Recalculer les cocons sémantiques" :

Action : Regroupe les articles en silos basés sur leur sens (clustering) via l'algorithme Infomap.

Utilité : Met à jour la structure du graphe et les couleurs des silos pour visualiser la cohérence thématique.

🚀 Phase 9 — Duel de Pages (Anti-Cannibalisation Hybride)

Objectif : Sécuriser l'autorité thématique en identifiant et neutralisant les conflits de positionnement entre pages "trop proches" (IA) ou se chevauchant sur les mêmes requêtes (GSC).

Tâche 1 — Réglages et Seuils Critiques (PHP)
[ ] Ajouter `sil_similarity_max` (défaut 0.92) pour bloquer les "Near-Duplicates" sémantiques.
[ ] Ajouter `sil_gsc_overlap_threshold` (défaut 1) pour détecter les chevauchements de mots-clés TOP 5.
[ ] Fichier : `smart-internal-links.php` (register_settings).

Tâche 2 — Moteur de Détection Hybride (PHP) [Stratégie Option C]
[ ] Implémenter `detect_cannibalization_risks()` dans `SIL_Pilot_Engine`.
[ ] Logique de calcul du Score de Conflit :
    - Calculer `cosine_similarity` entre paires de posts (optimisé par silos).
    - Extraire les intersections de `top_queries` dans `sil_gsc_data`.
[ ] Trigger : Lancement asynchrone lors de la `sync_data` GSC et post-publication.
[ ] Consigner les résultats dans `sil_action_log` avec le statut `alert`.

Tâche 3 — Interface de Pilotage & Pastille Rouge (JS/UX)
[ ] Notifications de menu : Ajouter le badge rouge sur le menu "Pilotage" si des alertes non traitées existent.
[ ] Création de la vue "Duel de Pages" dans le Pilot Center :
    - Liste des duels avec "Score de Conflit" (Code couleur : Rouge/Orange).
    - Tableau comparatif des GSC Queries en conflit.
    - Boutons d'action : "Gérer le Duel" (Ouvre la modale de fusion/désoptimisation).

Tâche 4 — Blocage Préventif de Maillage (Logic)
[ ] Dans `insert_internal_links()`, interdire l'insertion de liens automatiques entre deux pages déclarées en "Duel" pour éviter l'aggravation de la cannibalisation.

🚀 Phase 10 — Précision Chirurgicale (Micro-Embeddings)

Priorité : Haute — Focus Pertinence Contextuelle

Objectif : Améliorer la localisation de l'ancre en identifiant mathématiquement le paragraphe le plus pertinent au sein d'un article long via une approche hybride (Global -> Local).

- [x] **Tâche 1 — Moteur de Segmentation Gutenberg (PHP)**
- [x] **Tâche 2 — Cache Sémantique Local (DB)**
- [x] **Tâche 3 — Workflow de Recherche Hybride (PHP/JS)**
- [x] **Tâche 4 — Optimisation Financière & Performance**

🚀 Phase 11 — Protection en Temps Réel (Garde-fous)

Priorité : Moyenne — Focus Prévention

Objectif : Empêcher la corruption structurelle de se propager en interceptant le contenu avant sa sauvegarde.

Tâche 1 — Hook de Sauvegarde (PHP)
[ ] Implémenter un filtre sur `wp_insert_post_data` ou `pre_post_update`.
[ ] Réutiliser la logique de `check_html_integrity` pour scanner le contenu entrant.
[ ] Si une corruption majeure est détectée (blocs mal formés), lever une exception ou ajouter une notice d'admin "Contenu à risque" pour empêcher la sauvegarde aveugle.

---

📋 Note : Phase validée par brainstorm le 2026-04-10 (Option A).

---

## 🚀 Phase 12 — Moteur de Purge Hybride (Toxic Link Detector)

**Priorité : HAUTE — Focus Santé Sémantique**

**Objectif :** Nettoyer les silos en identifiant les liens "parasites" via le score de toxicité hybride.

### Tâche 1 — Backend & Réglages (PHP)
- [ ] **Settings API** : Ajouter l'option `sil_toxicity_threshold` (Défaut : 0.4) dans l'onglet Expert.
- [ ] **Moteur de Contexte** : Développer une fonction `get_link_context($source_id, $target_id)` qui extrait le paragraphe (Gutenberg ou classique) entourant le lien `<a>`.
- [ ] **Calculateur de Toxicité** : 
  - Formule : `Score = (1 - Similarité_Embedding) * Performance_GSC`.
  - Marquer comme `TOXIC` si Similarité < `sil_toxicity_threshold`.

### Tâche 2 — Interface "Swiss Purge" (JS/UI)
- [ ] **Vue Dédiée** : Créer un tableau de bord de purge dans le style Swiss.
- [ ] **Conteneur de Contexte** : Afficher un snippet du texte original pour chaque lien suspect (Vérification humaine).
- [ ] **Action Groupée** : Bouton `[✂️ Purge Automatique]` pour tous les liens sous le seuil sans performance GSC.

---

## 🎨 Phase 13 — Migration Globale Swiss Precision (Total Rebranding)

**Priorité : MOYENNE — Focus Identité & UX**

**Objectif :** Abandonner définitivement les styles WordPress génériques pour une interface "Premium/Tech" cohérente.

### Étape 1 : Fondations (Design System)
- [ ] **Design Tokens** : Unifier les variables CSS (Colors: Black/White/Slate/Blue, Typography: Inter/Mono, Spacing: 4px grid).
- [ ] **SVG Library** : Remplacement final des derniers emojis par les icônes Lucide/Heroicons (Audit complet).

### Étape 2 : Composants Atomiques (Swiss UI)
- [ ] **Boutons & Inputs** : Bordures noires `1px` ou `2px`, ombres portées solides (Sharp shadows), pas de dégradés.
- [ ] **Modales** : Refonte de la `BridgeModal` vers le format Swiss (Header noir, corps blanc, haute lisibilité).

### Étape 3 : Migration des Vues
- [ ] **Pilot Center** : Passage au mode Swiss (Tableaux haute densité).
- [ ] **Cartographie** : Standardisation sur la vue "Plan d'Architecte" (Swiss).
- [ ] **Réglages** : Simplification visuelle des formulaires (Style "Formulaires Techniques").

---

📋 Note : Phase 12 & 13 ajoutées le 2026-04-19 suite au brainstorm sur la toxicité et l'identité visuelle.

---

## 🚀 Phase 14 — Swiss Elite Booster (Précision Chirurgicale & Drip-Feed)

**Priorité : HAUTE — Focus Stratégie & Sécurité**

**Objectif :** Transformer le maillage en un moteur de précision "Elite" qui respecte la densité saine du site et évite la suroptimisation via un système de quotas adaptatifs et de verrous temporels.

### Tâche 1 — Quotas Adaptatifs & Mode Pilier (Logic)
- [ ] **Ratio de Densité** : Implémenter le calcul `Quota = ceil(Total_Pages * Ratio)`. (Ratio par défaut : 10%).
- [ ] **Option "Article Pilier"** : Ajouter une meta `_sil_is_pillar` permettant de doubler le quota pour les pages stratégiques (ex: 20%).
- [ ] **Vision Globale** : S'assurer que le compteur `X / Y` prend en compte les liens SIL + les liens naturels (détectés par la Phase 0).

### Tâche 2 — Moteur Micro-Élite & Context-Peeking (IA)
- [ ] **Seuil d'Élite** : Imposer un seuil de similarité strict (Défaut : 0.85) pour les suggestions de micro-embeddings.
- [ ] **Context-Peeking** : Si un paragraphe source est trop court (< 150 caractères), agréger les paragraphes voisins pour la vectorisation afin de garantir un match sémantique stable.
- [ ] **Badges de Qualité** : Afficher les suggestions sous le seuil avec un avertissement "⚠️ Qualité Faible".

### Tâche 3 — Sécurité "Drip-Feed" (Cooldown)
- [ ] **Verrou de 14 jours** : Enregistrer le timestamp du dernier boost vers une cible. Désactiver le bouton "Booster" si la dernière action date de moins de 14 jours.
- [ ] **Timer UI** : Afficher le temps restant avant la prochaine action possible ("Dispo dans X jours").

### Tâche 4 — Interface de Pilotage (Swiss UI)
- [ ] **Jauge de Saturation** : Intégrer l'indicateur `Links: 2/5` sur chaque ligne d'action du Pilot Center.
- [ ] **Réglages Expert** : Ajouter les curseurs de réglage (Ratio, Seuil, Délai) dans l'onglet Expert de SIL.

---

📋 Note : Phase 14 planifiée le 2026-04-19. Mise en place prévue le 2026-04-20.

---

## 🚀 Phase 15 — Carte d'Autorité Thématique (Swiss Projection)

**Priorité : STRATÉGIQUE — Focus Analyse Sémantique Pure**

**Objectif :** Basculer d'une visualisation de "liens" vers une visualisation de "sens". Les articles sont positionnés mathématiquement selon leur proximité vectorielle (Nuage Libre), révélant la structure thématique réelle avant même le maillage.

### Tâche 1 — Moteur de Projection (JS/Backend)
- [ ] **Projection 2D (PCA/UMAP)** : Implémenter ou intégrer une bibliothèque de réduction de dimensionnalité pour convertir les embeddings (1536d) en coordonnées (X, Y) stables.
- [ ] **Coordonnées Persistantes** : Option pour stocker les coordonnées calculées en meta afin de garantir une stabilité visuelle totale entre les sessions.
- [ ] **Mode "Preset" Cytoscape** : Configurer le layout pour utiliser les positions fixes au lieu du moteur de forces `cose`.

### Tâche 2 — Géographie des Silos (Visualisation)
- [ ] **Suppression des Boîtes** : Abandonner les "Compound Nodes" au profit d'un nuage libre.
- [ ] **Fond de Carte (Heatmap/Voronoi)** : Créer une couche de fond (canvas) qui colore les régions de l'écran selon le silo dominant, créant une véritable "carte géographique" des thématiques.
- [ ] **Détection de Superposition** : Mettre en évidence visuelle les zones où deux silos se chevauchent (conflit sémantique).

### Tâche 3 — Maillage Superposé (Audit de Tension)
- [ ] **Rendu des Liens Toujours Visibles** : Maintenir tous les liens HTML tracés sur la carte sémantique.
- [ ] **Indicateur de Tension** : Plus un lien est long (reliant deux points sémantiquement éloignés), plus il est coloré vers le rouge/orange pour signaler une fuite.
- [ ] **Filtrage des Liens** : Ajouter un curseur pour masquer les liens "faibles" et ne garder que l'infrastructure majeure du site.

### Tâche 4 — Édition Topologique en Drag & Drop ("God Mode")
- [ ] **Action Directe** : Permettre à l'utilisateur de lier ou délier des articles directement depuis le graphe en dessinant des liens entre les bulles.
- [ ] **Génération IA Asynchrone** : Le geste de Drag & Drop génère automatiquement les "ponts sémantiques" via l'IA en tâche de fond.
- [ ] **Feedback Visuel** : Gérer les états d'attente (loaders sur les edges) et de succès/erreur directement sur le canvas interactif.

---

📋 Note : Phase 15 planifiée le 2026-04-24. Bascule sur la logique "Semantic-First". Option "God Mode" ajoutée suite au brainstorm du 2026-04-29.