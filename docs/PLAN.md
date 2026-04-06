# Plan de Réparation : GSC & Scan d'Opportunités (v2.5.5)

Ce plan vise à résoudre les erreurs d'autorisation GSC et les échecs AJAX lors du scan d'opportunités (Content Gap).

## User Review Required

> [!IMPORTANT]
> **Erreur GSC (Permissions)** : L'API Google est extrêmement sensible au format de l'URL de propriété (ex: avec ou sans slash final). Nous allons implémenter une normalisation automatique pour s'assurer que l'URL envoyée à Google correspond exactement à la propriété enregistrée dans votre Search Console.
> **Scan d'Opportunités (500/Timeout)** : Le scan actuel est trop lourd pour des sites avec beaucoup d'articles. Nous allons optimiser les requêtes SQL et la structure de données pour alléger la boucle de traitement et éviter les erreurs réseau (500).

## Modifications Proposées

### Optimisation GSC (Backend Specialist, SEO Specialist)

#### [MODIFY] [includes/class-sil-gsc-handler.php](file:///c:/Users/leste/Documents/GitHub/AGENTIC/includes/class-sil-gsc-handler.php)
- Ajouter une méthode de normalisation de l'URL de site (gestion du trailing slash).
- Améliorer le logging des erreurs GSC pour identifier quel compte/site pose problème précisément.

### Optimisation Scan AJAX (Backend Specialist, Performance Optimizer)

#### [MODIFY] [includes/class-sil-ajax-handler.php](file:///c:/Users/leste/Documents/GitHub/AGENTIC/includes/class-sil-ajax-handler.php)
- Optimiser `sil_get_content_gap_data`.
- Remplacer `SELECT * FROM postmeta` par une requête jointe `$wpdb->get_results("SELECT p.ID, p.post_title, pm.meta_value FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE pm.meta_key = '_sil_gsc_data' AND p.post_status = 'publish'")` pour réduire les appels à la base.
- Optimiser le filtrage des keywords déjà présents dans les titres.

### Vérification (Test Engineer)

#### [NEW] [tests/test-gsc-logic.php](file:///c:/Users/leste/Documents/GitHub/AGENTIC/tests/test-gsc-logic.php)
- Script de validation pour la normalisation GSC.

## Questions Ouvertes

- Dans votre GSC, la propriété `https://moulin-a-cafe.org` finit-elle par un slash ?
- Environ combien d'articles sont publiés sur le site ?

## Plan de Vérification

### Tests Automatisés
- Exécution de `checklist.py`.
- Benchmark local sur `sil_get_content_gap_data`.

### Vérification Manuelle
- Test manuel de la connexion GSC par l'utilisateur.
- Test manuel du scan "Analyser les Opportunités".
