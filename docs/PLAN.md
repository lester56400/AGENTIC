# 🚀 Plan d'implémentation : Adopter V2 (Proximité Sémantique & Ponts)

## 🎯 Objectif
Faire évoluer le bouton "Adopter" pour qu'il propose de relier l'article orphelin (ou "adoptable") à son **"frère sémantique" le plus proche** (via embeddings) au lieu de forcer le maillage par le Mégaphone. Le flux mettra également en avant la création d'un **Pont Sémantique** par l'IA.

Conformément à vos choix de design :
1. **Élargissement "Adoptable"** : Les articles ayant `0` ou `1` lien entrant seront éligibles à l'adoption. Les vrais orphelins (0) gardent leur urgence critique, ceux avec 1 lien deviennent "Adoptables" pour forcer le maillage horizontal.
2. **Choix de la source** : La modale proposera de choisir entre le Frère Sémantique (pré-sélectionné) et le Mégaphone.
3. **Alerte visuelle Mégaphone** : Si le Mégaphone fait *déjà* un lien vers l'article, il restera sélectionnable mais avec un avertissement visuel clair pour éviter la surcharge.

---

## 🛠️ Modifications proposées

### 1. Backend : Logique de Similarité (`class-sil-cluster-analysis.php`)
- **[NEW]** Création de la méthode `get_closest_brother_for_post($post_id)` :
  - Identifie le silo de l'orphelin/adoptable.
  - Charge l'embedding de l'orphelin et calcule la similarité cosinus (`SIL_Centrality_Engine::get_representativeness_score`) avec tous les autres articles du silo.
  - Exclut les articles qui font **déjà** un lien vers l'adoptable (pour éviter les boucles inutiles).
  - Retourne l'ID de l'article ayant le score de similarité le plus élevé.

### 2. Backend : API AJAX (`class-sil-ajax-handler.php`)
- **[MODIFY]** `sil_get_orphan_adoption_info()` :
  - Charge le Mégaphone (`get_megaphone_for_post`) et le Frère Sémantique (`get_closest_brother_for_post`).
  - Vérifie si le Mégaphone a déjà un lien pointant vers l'adoptable en interrogeant la DB ou le graphe (`$is_megaphone_already_linking`).
  - Renvoie une structure de données enrichie (`parents_disponibles` : frère + mégaphone) au lieu d'un seul ID.

### 3. Frontend : Interface Liste (`class-sil-admin-renderer.php`)
- **[MODIFY]** Affichage du bouton "Adopter" :
  - L'affichage ne sera plus limité à `$incoming === 0`.
  - Condition : `if ($incoming <= 1)`.
  - Le style visuel du bouton s'adaptera : Rouge (Danger) pour 0 lien, Orange (Warning) pour 1 lien.

### 4. Frontend : Modale & UI (`assets/admin.js`)
- **[MODIFY]** Fonction `openAdoptionModal()` :
  - **Zone Parent :** Ajout de boutons radio pour choisir la source du lien.
    - "Frère Sémantique : [Titre]" (Pré-sélectionné).
    - "Mégaphone : [Titre]" (Avec badge d'avertissement *⚠️ Déjà lié* si `$is_megaphone_already_linking` est vrai).
  - **Boutons d'action :** Inversion visuelle. "Générer un Pont Sémantique IA" devient le CTA principal (`sil-btn-primary`), et "Insérer lien classique" devient secondaire.
- **[MODIFY]** Événements AJAX : l'ID envoyé sera celui du parent sélectionné via les boutons radio.

---

## ⚠️ Points d'attention
- Si l'article n'a pas encore été indexé sémantiquement (pas d'embedding), le Frère ne pourra pas être calculé. L'option sera cachée et le système basculera sur le Mégaphone par défaut.

> [!IMPORTANT]
> **Veuillez valider cette version finale du plan d'implémentation pour lancer le code.**
