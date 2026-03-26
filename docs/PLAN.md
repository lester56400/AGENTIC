# Implementation Plan: Gutenberg Safety & Fuzzy Adoption

Ce plan vise à corriger les erreurs de validation de blocs Gutenberg et à implémenter l'Option C (Ancre Sémantique Floue) pour une insertion plus naturelle.

## User Review Required

> [!WARNING]
> **Sécurité Gutenberg** : L'utilisation actuelle de `wp_kses_post()` sur le contenu renvoyé par l'IA supprime les commentaires HTML de Gutenberg (`<!-- wp:paragraph -->`), ce qui cause l'erreur "Contenu invalide". Nous allons passer à une méthode de nettoyage qui préserve ces délimiteurs.

---

## Proposed Changes

### [Component] Backend: Semantic Bridge Logic
#### [MODIFY] [includes/class-sil-ajax-handler.php](file:///c:/Users/leste/Documents/GitHub/AGENTIC/includes/class-sil-ajax-handler.php)

**1. Debug Gutenberg Corruption:**
- Dans `sil_apply_anchor_context`, remplacer `wp_kses_post()` par `wp_kses()` avec une configuration autorisant les commentaires HTML, ou utiliser `force_balance_tags()` si on fait confiance au flux IA.
- S'assurer que le `original_text` envoyé au front-end ne tronque pas les balises Gutenberg.

**2. Implement Option C (Fuzzy Anchor):**
- Modifier `sil_generate_bridge_prompt` :
    - Changer le prompt pour demander à l'IA de scanner le paragraphe et d'identifier la meilleure portion de texte existante à transformer en lien.
    - Supprimer l'obligation d'utiliser l'ancre littérale si une meilleure opportunité sémantique existe dans le texte.
- Mettre à jour l'objet de retour pour inclure des instructions claires sur la flexibilité de l'ancre.

### [Component] Frontend: Bridge Manager
#### [MODIFY] [assets/sil-bridge-manager.js](file:///c:/Users/leste/Documents/GitHub/AGENTIC/assets/sil-bridge-manager.js)
- Mettre à jour les labels de la modale pour refléter le mode "Fuzzy" (ex: "L'IA va adapter le texte existant").

---

## Verification Plan

### Automated Tests
- Exécuter `lint_runner.py` pour valider les changements PHP/JS.
- Vérifier la préservation des commentaires HTML via un script de test unitaire simple.

### Manual Verification
1. Créer un article avec l'éditeur Gutenberg.
2. Lancer un Pont Sémantique sur un paragraphe.
3. Sauvegarder et vérifier si l'éditeur affiche l'erreur "Contenu invalide".
4. Vérifier que le lien a été inséré sur un texte existant (Fuzzy) plutôt que d'être "forcé".
