# 🗺️ Plan de Bataille : Synchronisation Plugin SIL ↔ Gem.md (TERMINÉ ✅)

Ce document répertorie les écarts de données entre l'export JSON du plugin (`sil-audit-data.json`) et les attentes du moteur d'audit (`Gem.md`).

## 🚨 Écarts de Données (Audit de Conformité : 100%)

### 1. Naming & Mapping (Priorité : Critique)
| Attendu dans Gem.md | État dans SIL_Cluster_Analysis | Action Plugin | Statut |
| :--- | :--- | :--- | :--- |
| `silo_label` | Présent | Injecter le label textuel du silo dans chaque nœud article. | ✅ OK |
| `is_pivot` | `string` | Convertir en string `"true"`/`"false"` pour l'uniformité. | ✅ OK |
| `is_intruder` | `"true"`/`"false"` | Déjà implémenté et vérifié. | ✅ OK |
| `ideal_cluster_id` | `best_cid` | Déjà implémenté et vérifié. | ✅ OK |
| `current_similarity` | `semantic_score` | Déjà implémenté et vérifié. | ✅ OK |

### 2. Données Sémantiques (Priorité : Haute)
- [x] **Validation du Cache** : transient `sil_graph_cache_v13_0` invalidé si `$force_refresh` est vrai. ✅
- [x] **Drift** : `pivot_drift` exporté dans les nœuds parents (Silo). ✅

### 3. Structure & Résumés (Priorité : Moyenne)
- [x] **DNA Sémantique** : `silo_semantic_signatures` intégré dans `stats_summary`. ✅

---

## 🛠️ Plan d'Action (Battle Plan) - RÉALISÉ

### Étape 1 : Nettoyage & Uniformisation (Sprint 1)
- [x] Modifier `includes/class-sil-cluster-analysis.php` pour injecter `silo_label` dans les articles.
- [x] Stringifier tous les booléens (`is_pivot`, etc.).
- [x] Forcer l'invalidation du cache pour tester les nouveaux exports.

### Étape 2 : Calculs de Similarité (Sprint 2)
- [x] Vérifier que `ideal_cluster_label` est bien récupéré depuis `silo_labels`.
- [x] S'assurer que le calcul de similarité (`current_similarity`) est précis.

### Étape 3 : Validation & Export (Sprint 3)
- [x] Déclencher un nouvel export via un script de test ou l'UI.
- [x] Vérifier la conformité finale du JSON avec `Gem.md`.

### Étape 4 : Synchronisation de la Loi SEO (deep.md)
- [x] Remplacer les variables obsolètes (`_sil_ideal_silo` → `ideal_cluster_id`).
- [x] Préciser le calcul du delta (`similarity` - `current_similarity`).
- [x] Harmoniser le typage des booléens (`"true"`/`"false"`).
- [x] Intégrer `ideal_cluster_label` dans les diagnostics d'intrus.

---

## 📝 Notes Techniques
- **Fichier Gem.md cible** : [Gem.md](file:///c:/Users/leste/Documents/GitHub/AGENTIC/Gem.md)
- **Classe PHP cible** : `SIL_Cluster_Analysis` dans `includes/class-sil-cluster-analysis.php`
- **Audit Version** : 2026.V17.0
