# 📘 Code Civil SEO & Base de Connaissances Technique (SIL)

> **Rôle :** Unique référentiel de seuils et de règles. Le moteur d'exécution (`Gem.md`) s'y réfère strictement. Ne jamais extrapoler au-delà de ces Alinéas.

---

## Article 1 : L'Arsenal d'Outils SIL (Interface Utilisateur)

*Instructions pour l'utilisation des boutons d'actions au sein de l'interface WordPress.*

* **Alinéa 1.1 — `[🌉 Créer un pont sémantique]` :** Workflow hybride humain+IA pour insertion de phrase de transition naturelle. Réservé aux cas complexes : mot-clé GSC absent du texte source, ou pont inter-silo nécessitant une validation humaine.
* **Alinéa 1.2 — `[🤖 Trouver une ancre (Zéro GSC)]` :** Bouton de secours pour les pages sans données GSC (`gsc_impressions = 0`). L'IA invente le maillage optimal.
* **Alinéa 1.3 — `[✨ Inventer le lien (IA)]` :** Automatisation complète (Règle 80/20). Idéal pour boucher les trous sémantiques identifiés sans intervention manuelle lourde. À utiliser **systématiquement** pour les pages marquées `is_orphan`, `is_siphon`, ou `is_missing_reciprocity`.
* **Alinéa 1.4 — `[📉 Désoptimiser (IA)]` :** Désamorçage de la cannibalisation (`cannibalization_risk: "true"`) ou des ancres sur-optimisées (voir Alinéa 3.3).
* **Alinéa 1.5 — `[✨ Générer via IA (RankMath)]` :** Réécriture du Titre SEO et de la Meta Description pour booster le CTR. Déclencher si `gsc_ctr < 1.5%` ou `is_decay_critical: "true"`.
* **Alinéa 1.6 — Métrique « Perméabilité des Silos » :** Cible idéale = **20%**. Lire `stats_summary.silo_health`.
  * Si > 25% → Fuite critique. Identifier les coupables dans `stats_summary.leak_details`.
  * Si < 15% → Silo trop fermé. Envisager un pont sémantique contrôlé.

---

## Article 2 : Hygiène GSC et Survie

* **Alinéa 2.1 — Indexation (PRIORITÉ 0) :** Si `index_status` ≠ `SUBMITTED_AND_INDEXED` → Aucun maillage ne sert.

    | Statut `index_status` | Diagnostic | Action prioritaire |
    |:---|:---|:---|
    | `CRAWLED_NOT_INDEXED` | Google crawle mais rejette. Vérifier `word_count` (thin content < 500 ?) et qualité. | Enrichir contenu + `[✨ Générer via IA (RankMath)]` |
    | `SUBMITTED_BUT_NOT_INDEXED` | Soumis mais ignoré. Probable orphelin. Vérifier `is_orphan`. | Adopter via Mégaphone (**Alinéa 3.2**) |
    | `unknown` | Pas de données GSC suffisantes. Probablement sain. | Surveiller, pas d'urgence |

* **Alinéa 2.2 — Content Decay :** Si `is_decay_critical: "true"` → Action immédiate.
  * Critères internes : `post_modified` > 6 mois + `gsc_yield_delta_percent` ≤ -15% + `gsc_position_delta` ≤ -2.
  * Afficher dans le tableau : `gsc_trend`, `gsc_yield_delta_percent`, `post_modified`.
  * Action : `[✨ Générer via IA (RankMath)]` + boost depuis le Pivot du silo.

* **Alinéa 2.3 — Striking Distance :** Cible les requêtes de `striking_distance_keywords[]` (Position 10-30 avec impressions).
  * Trouver le Mégaphone (`is_pivot: "true"` dans le même `cluster_id`) pour perfuser du jus.
  * Action : `[✨ Inventer le lien (IA)]` depuis le Mégaphone vers la page cible.

---

## Article 3 : Topologie et Théorie du Flux

* **Alinéa 3.1 — Siphons :** Un siphon absorbe massivement sans redistribuer.
  * Détection : `is_siphon: "true"` (in_degree > 3, out_degree = 0).
  * Action : Créer un lien sortant vers le Pivot du silo (`is_pivot: "true"`) via `[✨ Inventer le lien (IA)]`.

* **Alinéa 3.2 — Adoption d'Orphelins :**
  * Détection : `is_orphan: "true"` (in_degree = 0).
  * Trouver le Mégaphone : page avec `is_pivot: "true"` ou `sil_pagerank` ≥ 60 dans le **même** `cluster_id`.
  * Si l'orphelin a aussi `index_status` ≠ `SUBMITTED_AND_INDEXED` → **URGENCE ABSOLUE**.
  * Action : `[✨ Inventer le lien (IA)]` depuis le Mégaphone vers l'orphelin.

* **Alinéa 3.3 — Diversité d'Ancres :**
  * Source de données : `incoming_anchors[]` (nœuds) + `anchor` (edges).
  * **Seuil Danger** : Si > 80% des `incoming_anchors` d'une page sont identiques → sur-optimisation. Action : `[📉 Désoptimiser (IA)]`.
  * **Ancres Creuses** : "cliquez ici", "lien", "en savoir plus", "ici" → Gaspillage de jus. Réécrire avec le `main_query` ou le `lexical_field`.
  * **Ratio Idéal** : 40% exact/partiel match + 40% naturel/marque + 20% générique.

* **Alinéa 3.4 — Thin Content :**
  * `word_count` < 500 dans un silo avec articles `is_strategic: "true"` → Trou noir. Absorbe du jus sans redistribuer.
  * Si `word_count` < 800 → Recommander enrichissement substantiel pour toute action `UPDATE`.
  * Si deux articles dans le même silo ont `is_semantic_duplicate: "true"` → Envisager la fusion.

---

## Article 4 : Le Playbook de l'Architecte

* **Alinéa 4.1 — Remplissage des Gaps :** Utilise `opportunities.true_gaps[]` et `opportunities.semantic_gaps[]`.
  * Chaque gap contient un `lexical_field` → matière lexicale pour la rédaction.
  * Chaque gap contient une `linking_strategy` → source et cible du futur lien.
  * Les `semantic_gaps` de type `hollow_cluster` indiquent un silo sans pilier central.

* **Alinéa 4.2 — Cannibalisation (Règle de Décision) :** Ne jamais recalculer. Utiliser la clé `recommendation` :
  * **`UPDATE`** : Similarité forte (> 0.85). Enrichir l'URL cible `target_title [ID:target_id]` avec les mots du `lexical_field`.
  * **`CREATE`** : Angle unique. Rédiger une nouvelle ressource basée sur le `lexical_field`.

* **Alinéa 4.3 — Anti-Doublon Absolu :** Avant toute suggestion `CREATE`, scanner l'intégralité de `elements[]` par titre et `main_query`. Si la thématique existe déjà (même sous un angle dérivé) → reclasser en `UPDATE`.

* **Alinéa 4.4 — Ponts Manquants (Missing Bridge) :**
  * Source : `metadata.silo_distances` (matrice) + `opportunities.semantic_gaps[type=missing_bridge]`.
  * Si distance < 0.40 entre deux silos ET aucun edge entre eux dans `elements[]` → pont recommandé.
  * Utiliser le `lexical_bridge` fourni pour la phrase de liaison.
  * Action : `[🌉 Créer un pont sémantique]` entre les Pivots (`is_pivot: "true"`) des deux silos.

---

## Article 5 : Les Intrus (Phase 0.1 — Ré-ancrage Sémantique)

> **Principe Fondateur : Semantic-First.** La structure (liens) doit se plier au sens (contenu), jamais l'inverse. Les liens peuvent être faits et défaits ; le sens d'un article, lui, est fixe. En conséquence, le positionnement sémantique prévaut toujours sur le positionnement topologique.

* **Alinéa 5.1 — Détection :** `is_intruder: "true"` + `node_semantic_target` indique le silo de fuite topologique.
  * L'article est posté dans le silo `cluster_id` mais 100% de ses liens sortent vers `node_semantic_target`.
  * L'audit sémantique identifie le ralliement via `ideal_cluster_id` et `ideal_cluster_label` (barycentre le plus proche).

* **Alinéa 5.2 — Traitement (Défaut : Ralliement Sémantique) :**
  * **Action par défaut → `[🔄 Repatrier]`** : Déplacer l'article vers son silo sémantique idéal (`ideal_cluster_id`). Cette opération modifie uniquement l'assignation dans `sil_silo_membership` (non-destructif, réversible).
  * **Action de secours → `[🌉 Pont]`** : Si le déplacement n'est pas souhaité (article pivot, historique fort), créer un pont sémantique vers le Pivot (`is_pivot: "true"`) du silo idéal pour canaliser la fuite.

* **Alinéa 5.4 — Règle de Tolérance Sémantique (Seuil 10%) :**
  * Le rapatriement vers un silo idéal n'est OBLIGATOIRE que si l'écart de similarité (`delta`) est ≥ 0.10.
  * **Calcul du Delta** : `delta = similarity - current_similarity`.
  * Si `delta < 0.10`, l'article est considéré comme **Polyvalent**. Action : Maintenir dans le silo actuel + créer un pont sémantique vers le silo idéal.
  * Ce seuil évite de briser l'expérience utilisateur et la topologie existante pour des gains sémantiques marginaux.

---

## Article 6 : Réciprocité et Structure Interne (NOUVEAU)

* **Alinéa 6.1 — Réciprocité Manquante :** `is_missing_reciprocity: "true"` signifie que l'article ne renvoie pas de lien vers le Pivot de son silo.
  * Action : `[✨ Inventer le lien (IA)]` depuis cet article vers le Pivot (`is_pivot: "true"`).

* **Alinéa 6.2 — Ponts Sémantiques :**   `is_bridge: "true"` indique un article qui chevauche deux silos.
  * Ce n'est PAS un intrus. C'est un connecteur légitime.
  * Vérifier que ses liens sortants vont vers les Pivots des DEUX silos concernés.

* **Alinéa 6.3 — Densité et Résilience :**
  * **Seuil de Fragilité** : Un silo comportant moins de 5 articles est considéré comme `🚩 Fragile`.
  * **Seuil de Croissance** : Un silo comportant entre 5 et 8 articles est considéré comme `📈 En croissance`.
  * **Signature Sémantique Sélective (SSS)** : Pour tout silo < 8 articles, le système extrait l'ADN sémantique (5 titres les plus représentatifs) pour guider les suggestions.
  * **Matrice des 5 Intentions** : Toute suggestion de création doit couvrir 5 angles :
        1. **Autorité** (Guide Pilier), 2. **Tutoriel** (Pratique), 3. **Problème** (Dépannage), 4. **Comparatif** (Aide au choix), 5. **Tendance** (Insolite).

* **Alinéa 6.4 — L'Exploration "Océan Bleu" (Nouveaux Silos)** L'IA doit utiliser la `distance_matrix` (Matrice des distances entre silos) pour identifier les territoires sémantiques vierges.
  * **La Règle de l'Espace Vide :** Si deux silos majeurs (ex: Silo A et Silo B) ont une distance sémantique très élevée (ex: `distance > 0.85`), ils sont diamétralement opposés sur la carte. L'espace entre eux est vide.
  * **Création du Nouveau Silo :** L'IA doit trianguler un nouveau sujet (Nouveau Silo) qui se trouverait conceptuellement "au milieu" de ces deux extrêmes, ou dans une thématique adjacente totalement ignorée par le site actuel.
  * **Format de la recommandation :** Le nouveau silo proposé doit obligatoirement être décliné selon la "Matrice des 5 Intentions" (Autorité, Tutoriel, Problème, Comparatif, Tendance) définie à l'Alinéa 6.2.

---

## Article 7 : Délais de Grâce Temporels (Filtre Sélectif)

* **Alinéa 7.1 — Principe de Non-Interférence Précoce :** Le SEO nécessite une phase de stabilisation. Intervenir trop tôt sur un contenu neuf ou récemment modifié génère du "bruit" statistique.
* **Alinéa 7.2 — Matrice des Seuils de Grâce :**

    | Diagnostic | Délai de Grâce | Condition de Filtrage |
    |:---|:---|:---|
    | Striking Distance | ⏳ **60j** post-publication | `post_date` < 60 jours |
    | Content Decay | ⏳ **90j** post-modification | `post_modified` < 90 jours |
    | CTR / Cannibalisation | ⏳ **60j** post-publication | `post_date` < 60 jours |
    | **Intrus / Siphon / Orphelin** | ❌ **0j (Sans grâce)** | Structurel (Topologie) → Toujours urgent |
    | **Indexation (Urgent)** | ❌ **0j (Sans grâce)** | Critique → Toujours urgent |

* **Alinéa 7.3 — Comportement de l'Audit :** Les articles "En grâce" doivent être listés dans les tableaux pour visibilité mais porter la mention `⏳ En grâce (Xj restants)`. Aucune action corrective (UPDATE/BOOST) ne doit être prescrite tant que le délai n'est pas expiré.
