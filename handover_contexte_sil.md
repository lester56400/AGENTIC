# 📂 Document de Contexte Maître : Projet Smart Internal Links (SIL)

Ce document est la mémoire centrale du projet. Il doit être lu par toute nouvelle instance d'IA pour comprendre non seulement le code, mais la **stratégie de domination SEO** et les **choix d'ingénierie** spécifiques effectués durant le développement.

## 1\. Identité & Vision : Le "Topological SEO"

**Smart Internal Links (SIL)** n'est pas un plugin de maillage classique basé sur de simples mots-clés. SIL traite le site web comme un **système de fluides** (le jus SEO) guidé par une **boussole sémantique**.

-   **L'Objectif :** Passer d'un outil d'aide à la décision à un **Orchestrateur SEO Autonome**.
-   **Le Concept unique :** L'analyse de la **Divergence**. Nous cherchons l'écart entre le maillage RÉEL (Topologie) et le contenu écrit (Sémantique) pour réaligner le site sur les attentes de l'algorithme Rank-Brain de Google.

## 2\. La Logique Interne & Choix Algorithmiques

### 🧠 A. Topologie : Le choix d'Infomap (vs Louvain)

Contrairement à la majorité des outils qui utilisent l'algorithme de Louvain (qui cherche des groupes statiques de "proximité"), nous avons choisi **Infomap** (Python).

-   **Pourquoi ?** Infomap modélise la théorie du **Surfeur Aléatoire** (Random Surfer). Il détecte les "unités de flux". Si un crawler a tendance à rester "piégé" dans un groupe de pages à cause des liens, Infomap le définit comme un silo.
-   **Pondération GSC :** Les liens ne sont pas égaux. Ils sont pondérés par la formule : `(Clics * 2) + Impressions + 1`. Cela transforme les pages fortes en **Mégaphones** qui aspirent et redistribuent le flux.
-   **Échelle Logarithmique :** Pour éviter l'effet "Trou Noir" (une page massive qui absorberait tous les silos en une seule couleur), nous appliquons une compression logarithmique sur les poids avant l'envoi à l'API.

### 🎯 B. Sémantique : Logical Truth (OpenAI)

Nous utilisons `text-embedding-3-small` pour créer une empreinte digitale mathématique de chaque texte.

-   **Le Barycentre :** Pour chaque silo physique (Infomap), nous calculons le "sens moyen" du groupe.
-   **Divergence (👾) :** Une page est marquée comme **Intrus** si elle est physiquement liée au Silo A mais que son vecteur sémantique est plus proche du Silo B. C'est le signal d'une erreur de maillage structurelle.

## 3\. Choix Stratégiques Majeurs (Le "Pourquoi")

-   **Inversion Logique "Booster" :** Un article en _Striking Distance_ (Pos 6-15) est une pépite. Il est **TOUJOURS la CIBLE** (Target). Cliquer sur "Booster" doit déclencher la recherche d'une **SOURCE** puissante (Mégaphone du même silo) pour lui envoyer du jus.
-   **Pivot Consolidation d'Intention :** Nous ne suggérons plus de créer de nouveaux articles systématiquement (risque de cannibalisation). Nous privilégions la **Consolidation** : si un mot-clé fort GSC est absent du titre d'un article qui ranke déjà, on propose de modifier le Titre/H1 existant.
-   **La Règle de Patience (60 jours) :** Le SEO est lent. Le plugin enregistre chaque action dans `_sil_last_seo_update` et interdit de retoucher une page avant 2 mois pour laisser à Google le temps de stabiliser les données sans "bruit" statistique.
-   **Perméabilité (Cible 20%) :** Selon le référentiel `deep.md`, un silo ne doit pas être une prison. Nous visons 80% de liens internes et 20% de "Ponts sémantiques" transversaux pour irriguer tout le domaine.

## 4\. Architecture Technique & Contraintes

-   **Modularité PHP (POO) :** Le plugin est découpé en services spécialisés :
-   `SIL_Database_Manager` : Unification des requêtes SQL.
-   `SIL_Centrality_Engine` : Calcul mathématique des In/Out Degree.
-   `SIL_Pilot_Engine` : Logique de décision (Leader/Loser).
-   `SIL_Infomap_API` : Communication avec le microservice Python (Render).
-   **Human-in-the-loop (Workflow Bridge) :** Nous ne laissons pas l'IA écrire seule en base de données. Le plugin génère un prompt ➔ l'utilisateur consulte l'IA ➔ l'utilisateur valide/édite le HTML dans la modale avant injection.
-   **Garde-fous Environnementaux :**
-   **Cloudflare :** Attribut `data-cfasync="false"` obligatoire sur les scripts pour éviter les crashs `cf.core`.
-   **Gutenberg :** Interdiction de modifier la structure des blocs (shortcodes, commentaires HTML). On reste dans les balises `<p>`.
-   **MariaDB :** JAMAIS de `JSON_EXTRACT` en SQL (incompatible o2switch mutualisé). Le filtrage se fait en PHP.

## 5\. Guide Visuel & Diagnostic (Cytoscape)

-   **🚩 (Orphelin) :** In-degree = 0. Priorité de raccordement.
-   **🧽 (Siphon) :** Reçoit beaucoup mais ne donne rien. Bloque la circulation du PageRank.
-   **👾 (Intrus) :** Contradiction entre les liens (Infomap) et le sens (Embeddings).
-   **Bordure Orange :** Content Decay (Impressions élevées mais CTR < 1%).

## 6\. Documents de référence obligatoires

-   `**ROADMAP.md**` **:** État d'avancement et Phase 5.
-   `**deep.md**` **:** Lois théoriques de la Tri-force.
-   `**Gem.md**` **:** Protocole d'audit pour l'expert externe.
-   `**plugin_structure.md**` **:** Plan technique des fichiers.

## 7\. Mission immédiate

Finaliser l'inversion de logique du bouton "Booster" dans `admin.js` et `sil-bridge-manager.js` (la cible doit être fixe, la source doit être recherchée). Préparer l'ajout du marquage `data-sil="ai"` pour le futur tracking de ROI.