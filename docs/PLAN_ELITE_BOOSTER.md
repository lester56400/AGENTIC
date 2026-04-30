# PLAN_ELITE_BOOSTER: Swiss Elite Booster Implementation

Ce plan détaille l'implémentation de la logique de maillage de précision "Elite" pour le plugin Smart Internal Links (SIL).

## 🎯 Objectifs
1.  **Quotas Adaptatifs** : Limiter les liens entrants selon un ratio du site (Option A).
2.  **Mode Pilier** : Permettre d'augmenter le quota pour les pages stratégiques.
3.  **Sécurité Drip-Feed** : Verrou de 14 jours entre deux actions de boost vers une même cible.
4.  **Moteur Micro-Élite (C)** : Utiliser les micro-embeddings avec "Context-Peeking" pour les phrases courtes.

---

## 🛠️ Modifications Proposées

### 1. Registre des Réglages (Base de données)
- **sil_elite_ratio** : Ratio de densité de liens (Défaut : 10% soit 0.1).
- **sil_elite_threshold** : Seuil de similarité sémantique (Défaut : 0.85).
- **sil_drip_feed_days** : Période de refroidissement (Défaut : 14 jours).
- **sil_pillar_multiplier** : Multiplicateur de quota pour les piliers (Défaut : 2.0).

### 2. Métadonnées (Post Meta)
- **_sil_is_pillar** : Flag pour identifier les articles piliers.
- **_sil_last_boost_timestamp** : Horodatage de la dernière action vers cette cible.
- **_sil_link_count_cache** : Cache du nombre total de liens (SIL + Naturels).

### 3. Évolutions du Moteur Logique
- **Calcul du Quota** : `Quota = ceil(Total_Pages * Ratio * (IsPillar ? Multiplier : 1))`.
- **Détection Globale** : Comptage exhaustif de tous les liens `<a>` vers la cible (Scanner SIL + Naturels).
- **Filtrage Micro-Élite** : Les scores < seuil reçoivent un avertissement "⚠️ Qualité Faible".
- **Context-Peeking** : Agrégation des paragraphes < 150 caractères avec leurs voisins avant vectorisation.
- **Vérification Cooldown** : Blocage de l'action si `maintenant - dernier_boost < 14 jours`.

### 4. Interface Utilisateur (UI/UX)
- **Jauges de Saturation** : Affichage `Links: X/Y` avec code couleur dans le Pilot Center.
- **Verrou Cooldown** : Icône de sablier ou compte à rebours sur le bouton Booster.
- **Badges de Qualité** : Affichage du score sémantique et avertissement de qualité.
- **Toggle Pilier** : Interface pour marquer/démarquer les articles comme piliers.

---

## 🧪 Plan de Vérification
1.  **Verrou Cooldown** : Vérifier que le verrou de 14 jours bloque bien l'injection.
2.  **Mise à l'échelle du Quota** : Vérifier que les articles piliers doublent bien leur quota.
3.  **Précision Sémantique** : Vérifier que les paragraphes courts agrègent bien leur contexte.
4.  **Précision du Scanner** : Vérifier que les liens naturels sont bien comptabilisés.

---

## 🛑 Validation Requise
**Veuillez approuver ce plan pour lancer l'implémentation.**
