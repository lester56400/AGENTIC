# Implementation Plan: HTML Integrity & Prompt Excellence

Ce plan vise à garantir la robustesse technique et la qualité rédactionnelle des liens insérés par l'IA.

## Proposed Changes

### [Component] Backend: Link Validation & Refined Prompting
#### [MODIFY] [includes/class-sil-ajax-handler.php](file:///c:/Users/leste/Documents/GitHub/AGENTIC/includes/class-sil-ajax-handler.php)

**1. HTML Syntax Validation:**
- Dans `sil_apply_anchor_context`, ajouter une vérification via `DOMDocument` pour s'assurer que le contenu renvoyé par l'IA est du HTML valide et ne contient pas de balises mal fermées.
- Vérifier la présence effective d'une balise `<a>` valide pointant vers la cible.

**2. Prompt Refinement (French Grammar & HTML Strictness):**
- Modifier `sil_generate_bridge_prompt` :
    - Ajouter des instructions impératives sur la grammaire française (articles définis/indéfinis obligatoires).
    - Ordonner le respect strict de la syntaxe HTML sans simplification (pas de "shortcodes" ou de texte tronqué).
    - Exiger une réponse "propre" sans texte de commentaire (juste le bloc HTML).

---

## Verification Plan

### Automated Tests
- Simuler des retours IA "sales" (HTML mal formé) et vérifier que le plugin refuse l'insertion avec un message d'erreur clair.
- Valider le nouveau prompt en le testant manuellement contre Gemini/ChatGPT.

### Manual Verification
1. Lancer un Pont Sémantique.
2. Vérifier que la suggestion de l'IA contient bien tous les articles français nécessaires ("le", "la", "à").
3. Vérifier que le code HTML inséré est complet et valide dans l'éditeur.
