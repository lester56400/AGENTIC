Roadmap - Smart Internal Links

🚨 BMAD — Inversion Logique "Booster" (Cible vs Source)

Priorité : Haute — Focus UI/UX

Objectif : Transformer le bouton "Booster" en un outil d'acquisition de jus : on sélectionne la page à pousser (Cible), et on cherche un "Mégaphone" (Source) pour lui envoyer de l'autorité.

Tâche 1 — Inversion de l'ouverture de la modale (JS) [⚡ Flash-Ready]

[ ] Fichier : assets/admin.js

[ ] Dans renderGapTable, trouver l'écouteur de clic sur .sil-booster-btn.

[x] Modifier l'appel à openBridgeModal : passer l'ID de l'article en 2ème argument (Target) et null en 1er (Source).

// Avant : openBridgeModal(articleId, null, keyword)
// Après : openBridgeModal(null, articleId, keyword)


Tâche 2 — Adaptation de l'interface de la modale (JS) [⚡ Flash-Ready]

[ ] Fichier : assets/sil-bridge-manager.js

[x] Dans openBridgeModal(sourceId, targetId, anchor), détecter le "Mode Booster" (sourceId === null && targetId !== null).

[x] Modifier dynamiquement le DOM de la modale :
🚀 Booster l'article, Étape 1 : Chercher un article SOURCE (Mégaphone)...

[x] Stocker le targetId fixe et orienter la recherche vers des sources potentielles. S'assurer que lors de l'insertion finale, le target_id est bien celui stocké initialement.

Tâche 3 — Support du sens inverse (PHP)

[ ] Fichier : includes/class-sil-ajax-handler.php

[x] Vérifier que sil_search_posts_for_link retourne bien les impressions GSC pour chaque article.

[x] S'assurer que les résultats permettent d'identifier visuellement les Mégaphones (sources fortes) dans la modale via le volume d'impressions.

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

[ ] Calculateur RRF : Créer la fonction calculate_rrf_score($semantic_rank, $gsc_rank, $k = 60).

[ ] Implémentation de la fusion : Appliquer la formule $RRFscore(d) = \sum_{r \in R} \frac{1}{k + r(d)}$. Le score final est la somme des inverses des rangs dans chaque liste.

Tâche 2 — Gestion du "Cold Start" & "Plan de Montage" (GEM)

[x] Logique de création (Pre-GSC) : Pour les recommandations de type CREATE (GEM), forcer l'algorithme à ignorer le score lexical (GSC). La proximité sémantique par embeddings ($> 0.80$) devient le seul critère.

[x] Prime de Fraîcheur : Si un article est publié depuis moins de 30 jours ou possède peu d'impressions, appliquer une pondération automatique (ex: 90% Sémantique / 10% GSC) pour ne pas pénaliser les "Pépites".

[x] Ajouter un flag is_new_content dans les résultats JSON pour l'interface.

Tâche 3 — Adaptation de find_similar_posts() (Tuyauterie)

[ ] Modifier la méthode pour accepter le paramètre $search_mode ('target' ou 'source').

[x] Filtrage Sémantique : Appliquer un seuil strict (Threshold) à 0.80 pour garantir la cohérence thématique avant d'appliquer le tri RRF.

[x] Mode Source (Booster) : Identifier et prioriser les "Mégaphones" (plus haut volume d'impressions GSC) parmi les voisins sémantiques.

[x] Inclure le hybrid_confidence_score (0-100%) dans le retour JSON.

Tâche 4 — Diagnostic de Divergence & UX [⚡ Flash-Ready]

[ ] Alerte d'indexation manquante : Afficher une notice/bannière dans la modale si les embeddings sont absents (invitant à cliquer sur "Indexer tout le contenu").

[ ] Visualisation de l'Intrus (👾) : Améliorer le rendu Cytoscape pour mettre en évidence les nœuds dont la couleur (Sens/Embedding) jure avec le conteneur (Cluster topologique).

[ ] Indicateur UI : Dans la modale de maillage, ajouter une jauge de pertinence ou une icône "RankBrain" à côté de chaque résultat.

[ ] Tooltip explicatif : "Score de consensus : [X]% (Confirmé par IA + GSC)".

🛠️ Phase 7 — Arsenal d'Outils SIL (Boutons Actionnables)

Objectif : Implémenter les 5 boutons spécifiques requis par la configuration MASTER V17 pour l'audit.

Tâche 1 — Implémentation des Boutons UI (JS/CSS) [⚡ Flash-Ready]

[ ] 🌉 Créer un pont sémantique : Intégration du workflow hybride (Phrase de transition IA + Lien). Utile quand le mot-clé GSC est absent du texte source mais thématiquement pertinent.

[ ] 🤖 Trouver une ancre (Zéro GSC) : Mode spécifique pour pages sans data GSC (IA génère l'ancre). Bouton de secours crucial pour les pages nouvelles.

[ ] ✨ Générer via IA (RankMath) : Trigger de réécriture du Titre SEO et de la Meta Description si gsc_ctr < 1.5% ou is_decay_critical == true.

[ ] ✨ Inventer le lien (IA) : Automatisation 80/20 pour boucher les trous sémantiques identifiés dans les silos sans intervention manuelle lourde.

[ ] 📉 Désoptimiser (IA) : Action corrective pour les conflits de cannibalisation (cannibalization_risk) ou désamorçage des ancres sur-optimisées.

🐛 Phase 8 — Corrections de Bugs Critiques

Priorité : Très Haute — Focus Éditeur Gutenberg

Objectif : Sécuriser l'intégrité des blocs Gutenberg lors des manipulations de contenu via l'IA.

Tâche 1 — Correction de la double encapsulation Gutenberg (PHP) [⚡ Flash-Ready]

[ ] Fichier : includes/class-sil-ajax-handler.php

[ ] Diagnostiquer la méthode `sil_apply_anchor_context` : le texte de remplacement s'encapsule dans de nouvelles balises `<!-- wp:paragraph -->` même si le paragraphe original les possède déjà.

[ ] Adapter la logique de remplacement : Vérifier si le paragraphe dans `$haystack` est déjà enveloppé par un bloc Gutenberg avant d'ajouter les marqueurs `<!-- wp:paragraph -->` autour de `$final_text`, ou remplacer le bloc entier.


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

[ ] Filtre de Similarité Pré-Génération : Injecter la liste des articles existants du silo dans le prompt IA pour lui interdire de suggérer des sujets déjà couverts.

[ ] Vérification Vectorielle Hybride (Validé) : Utiliser le moteur RRF comme socle unique pour croiser les nouvelles idées avec l'existant. Similarité requise < 80% pour valider une suggestion.

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

Utilité : Récupère les mots-clés, positions, impressions et le statut d'indexation.

"Indexer tout le contenu" (Page principale Smart Links) :

Action : Indexation Sémantique via OpenAI.

Utilité : Calcule les "embeddings" pour comprendre le sens des articles. Crucial pour le fonctionnement du moteur hybride RRF.

"Recalculer les cocons sémantiques" :

Action : Regroupe les articles en silos basés sur leur sens (clustering) via l'algorithme Infomap.

Utilité : Met à jour la structure du graphe et les couleurs des silos pour visualiser la cohérence thématique.