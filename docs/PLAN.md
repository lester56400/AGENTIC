# 🎼 Plan d'Orchestration: Audit UI/UX et Polissage du Pilot Center (v2.5.x)

**Contexte :** Le Pilot Center de Smart Internal Links est fonctionnel. L'objectif est maintenant de transcender l'interface existante et de lui appliquer un niveau de finition premium et "designer-developer" (via la skill `@frontend-ui-ux`), sans réécrire la logique cœur (`class-sil-admin-renderer.php`, `sil-pilot-center.js`).

## 1. Analyse Structurale (Explorer Agent - Terminée)
L'existant repose sur un style "Glassmorphism" sombre. Le code est scindé entre :
- `assets/sil-pilot-center.css` : Gère le layout Grid, les "glass-cards", les variables CSS lux (-lux-accent, -lux-green).
- `assets/sil-pilot-center.js` : Gère les onglets et les événements asynchrones.
- `includes/class-sil-admin-renderer.php` : Rendu du DOM.

## 2. Définition de la Direction Esthétique (Frontend Specialist)
> Conformément à la skill `code-yeongyu-oh-my-opencode-frontend-ui-ux`, nous devons choisir une position forte et audacieuse.
- **Ton : "Luxury/Refined Dark Mode"**. Fini le gradient basique. Nous allons vers une interface sombre, texturée (léger grain), avec des ombres dramatiques (`box-shadow` directionnelles) et des contrastes typographiques élevés.
- **Typographie :** On abandonne l'empilement système (`sans-serif` classique). On injecte une typographie de titre élégante (ex. `Cormorant Garamond` ou `Playfair Display`) contrastant avec une sans-serif chirurgicale pour la donnée tabulaire (ex. `Manrope` ou `Outfit`).
- **Couleur :** Palette monochromatique charbon/obsidienne, avec **une seule** couleur d'accent néon (ex. Ambre incandescent ou Cyan glacial) pour guider le clic.
- **Espaces :** Application stricte du negative space. Les marges internes (`padding`) des `glass-cards` passeront de 24px à 36px/48px pour laisser respirer l'information. Espace asymétrique si possible.

## 3. Micro-Interactions et Feedbacks Visuels
- **Hover States :** Les lignes de tableaux du Journal d'Action ou de l'Incubateur n'auront pas qu'un simple changement de fond. Elles soulèveront légèrement l'élément (Translate Y) avec une aura.
- **Spinners / Skeletons :** Le basculement en mode "chargement" d'un bouton (ex: bouton 🚀 lors du pont sémantique) passera d'un texte "⏳..." à une véritable animation CSS (spinner minimaliste).
- **Toast Notifications :** Si l'alerte native `alert('...')` est utilisée en JS, nous allons la remplacer ou la doubler par un snackbar animé dans le coin inférieur droit, intégré via `sil-pilot-center.css`.

## 4. Intégration WP / Encapsulation (DevOps / Test Engineer)
- Vérification stricte du *scope* des balises (`#sil-pilot-hub h1` au lieu de `h1`) pour garantir que d'autres plugins WordPress ou le back-office ne sont pas perturbés.

## 5. Phase d'Exécution par le Pool d'Agents (Phase 2)
Une fois ce plan validé, l'Orchestrateur déploiera les agents suivants en parallèle/séquentiel :
1. **Frontend-Specialist :** Refactoring de `assets/sil-pilot-center.css` (tokens, polices, layouts asymétriques).
2. **Frontend-Specialist (JS) :** Refactoring de `assets/sil-pilot-center.js` (ajouts de micro-loaders pour les appels $.post).
3. **Backend-Specialist / Test-Engineer :** Modification minime de `class-sil-admin-renderer.php` pour injecter les nouvelles classes de skeleton. Vérification post-déploiement (`lint_runner.py`, `ux_audit.py`).
4. **DevOps-Engineer :** Exportera les modifications vers le dossier de livraison (ex: `upload_...`).
