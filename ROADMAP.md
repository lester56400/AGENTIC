# Roadmap - Smart Internal Links

## À faire pour demain (2026-03-20)

### 1. Filtrage du Graphe
- [ ] **Exclure les articles `noindex` du graphe** : Modifier la récupération des données du graphe (`class-sil-cluster-analysis.php`) pour ne pas inclure les nœuds dont le meta SEO est réglé sur `noindex`.

### 2. Création de Pont Sémantique
- [ ] **Révision du bouton d'insertion** : Vérifier les contrastes de couleurs dans la modale de création de pont. Le bouton de validation semble être "bleu sur bleu".
- [ ] **Correction de l'affichage de la modale** : Résoudre le problème de défilement. Lorsqu'on crée un pont en bas de la modale, l'affichage se fait en dessous de la zone visible, forçant l'utilisation de l'ascenseur.

### 3. Recommandations de Silotage
- [ ] **Nommage des silos dans les recommandations** : Remplacer l'affichage type "Silo 9001" par un nom plus explicite comme "Thématique : [Nom du Pivot]" ou le titre de l'article pivot du silo.

### 4. Suggestions de Metas SEO
- [ ] **Édition des suggestions** : Permettre la réécriture manuelle des metas (Title/Description) après la suggestion de l'IA, ou la validation directe en l'état.

---

## Suggestions d'Amélioration UX/UI (Priorités Design)

*Choisissez parmi ces points pour moderniser l'interface et améliorer l'expérience utilisateur.*

### 🎨 1. Refactorisation de l'Architecture CSS
- [ ] **Suppression des styles "inline"** : Déplacer tous les styles injectés via JS vers des classes CSS dédiées dans `admin.css`.
- [ ] **Design Tokens (Thématisation)** : Unifier l'utilisation des variables `--sil-primary` et remplacer les couleurs codées en dur par des jetons cohérents.

### ✨ 2. Icônographie "Premium" (Remplacement des Emojis)
- [ ] **Remplacement des Emojis par des SVG** : Remplacer les emojis (👾, 🚩, 🧼, 🥇, 🥈, ⚠️) par un set d'icônes SVG professionnel (ex: **Lucide** ou **Heroicons**).
- [ ] **Indices visuels de statut** : Utiliser des icônes d'alerte uniformes pour les "Intruders", "Orphans" et "Siphons".

### 🧠 3. Fluidité des Interactions (Micro-interactions)
- [ ] **Feedback sans interruption** : Remplacer les `alert()` par un système de **Toasts/Notifications**.
- [ ] **Transitions de chargement** : Améliorer les états de chargement (skeletons ou spinners personnalisés).
- [ ] **Animations de volet** : Assurer que le panneau latéral s'ouvre avec une animation de glissement "smooth".

### 📊 4. Ergonomie du Graphe (Cytoscape)
- [ ] **Typographie "Tech/SEO"** : Adopter la police **Fira Sans** pour le corps et **Fira Code** pour les données techniques.
- [ ] **Boutons flottants de contrôle** : Rendre les boutons "Centrer" et "Zoom" plus accessibles via une barre d'outils flottante.

---

## 🚀 Innovations Stratégiques (Futur / R&D)

*Fonctionnalités avancées pour transformer SIL en plateforme d'autorité SEO complète.*

### 🏛️ Option A : Générateur de Pages Piliers & Silotage Automatique
- [ ] **Détection de "L'Optimal K"** : Calculer mathématiquement le nombre idéal de silos (via Elbow Method ou Silhouette) pour que le maillage soit le plus naturel possible.
- [ ] **Détection de "Silo Master"** : Identifier automatiquement quand un silo sémantique a besoin d'une page centrale de référence.
- [ ] **Génération IA d'Articles-Piliers** : Création d'un article "Guide Complet" qui résume chaque article du silo et distribue le jus SEO de manière hiérarchique.
- [ ] **Maillage Réciproque (Hub & Spoke)** : Forcer ou suggérer un lien de l'article vers sa page Pilier pour sceller l'autorité thématique du silo.
- [ ] **Templates "Magazine"** : Mise en page spécifique (Grid/Mosaic) pour ces pages piliers afin de les différencier visuellement.

### 🎯 Option B : Optimisation Prédictive des Ancres (CTR)
- [ ] **Audit de Distribution d'Ancres** : Analyser la diversité des textes d'ancres pointant vers une page pour éviter la sur-optimisation tout en restant pertinent.
- [ ] **Recommandations GSC** : Utiliser les "Real Queries" de la Search Console pour suggérer des ancres qui correspondent exactement à ce que les utilisateurs tapent.
- [ ] **Vérification de Cohérence** : Alerter si une ancre "Optimisée" est utilisée trop fréquemment par rapport aux ancres naturelles.

### 🛡️ Option C : Sculptage de Link Juice & Crawl Budget
- [ ] **Détection de "Fuite de Jus"** : Cartographier les liens sortants vers des pages à faible valeur SEO (tags inutiles, archives vides, mentions légales).
- [ ] **Tableau de bord de Sculptage** : Proposer en un clic de passer ces liens en `nofollow` ou de les supprimer pour "pousser" toute la puissance vers les pages stratégiques.

### 🛑 Option D : Anti-Cannibalisation IA (Vérification Avant Suggestion)
- [ ] **Filtre de Similarité Pré-Génération** : Injecter la liste des articles existants du silo dans le prompt IA pour lui interdire de suggérer des sujets déjà couverts.
- [ ] **Vérification Vectorielle Post-Génération** : Croiser chaque nouvelle idée d'article soumise par l'IA avec la base de données (embeddings) pour supprimer les doublons sémantiques avant affichage (< 80% de similarité requise).

---

## 🛠️ Dette Technique & Architecture (Clean Code)

*Améliorer la maintenabilité en éclatant les "God Objects" en services spécialisés.*

### 📦 1. Découpage du fichier principal (`smart-internal-links.php`)
- [ ] **Extraction de `SIL_Admin_Manager`** : Gérer l'ajout des menus, sous-menus et les notices d'administration.
- [ ] **Extraction de `SIL_Asset_Loader`** : Gérer l'enregistrement et le chargement conditionnel des scripts/styles.
- [ ] **Extraction de `SIL_Content_Observer`** : Déplacer la logique des hooks `save_post` et `transition_post_status`.
- [ ] **Extraction de `SIL_Embedding_Service`** : Isoler la logique d'appel à l'API OpenAI et le calcul de similarité.

### 🔌 2. Refactorisation des Handlers AJAX (`class-sil-ajax-handler.php`)
- [ ] **Éclatage en Sous-Handlers** : Diviser par domaine : `SIL_Ajax_Graph`, `SIL_Ajax_GSC`, `SIL_Ajax_Maillage`, `SIL_Ajax_Silos`.
- [ ] **Standardisation des Réponses** : Créer une méthode helper pour uniformiser les réponses JSON et la gestion d'erreurs.

### 🧹 3. Qualité de Code & DRY
- [ ] **Unification de l'extraction GSC** : Créer un service centralisé pour lire les données GSC (éviter la duplication dans les AJAX handlers).
- [ ] **Utilisation de Guard Clauses** : Rédiger des retours précoces pour réduire l'imbrication des fonctions.
- [ ] **Type Hinting** : Ajouter des types PHP 7.4+ aux arguments et retours de fonctions.

---

## Guide des Boutons d'Indexation

Pour clarifier l'interface, voici à quoi servent les différents boutons "d'indexation" :

1. **"Rafraîchir l'indexation" (dans l'édition d'un article)** : 
   - *Action* : Interroge l'API Google Search Console pour cet article précis.
   - *Utilité* : Met à jour immédiatement le badge de statut (Indexé / Non indexé).

2. **"Synchronisation GSC" (Réglages GSC / Dashboard)** :
   - *Action* : Synchronise l'ensemble du site avec la Google Search Console.
   - *Utilité* : Récupère les mots-clés, positions, impressions et le statut d'indexation.

3. **"Indexer tout le contenu" (Page principale Smart Links)** :
   - *Action* : **Indexation Sémantique** via OpenAI.
   - *Utilité* : Calcule les "embeddings" pour comprendre le *sens* des articles.

4. **"Recalculer les cocons sémantiques"** :
   - *Action* : Regroupe les articles en silos basés sur leur sens (clustering).
   - *Utilité* : Met à jour la structure du graphe et les couleurs des silos.
