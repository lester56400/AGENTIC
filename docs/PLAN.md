# Plan : Anti-Cannibalisation Sémantique par Embeddings

L'objectif est d'ajouter une intelligence "pré-export" au plugin pour décider si un mot-clé manquant (opportunité) doit faire l'objet d'un nouvel article ou d'une mise à jour d'un article existant, en se basant sur la similarité vectorielle (embeddings).

## Architecture Technique

### 1. Collecte des opportunités
- Dans `get_graph_data()`, APRÈS la détection des `hollow_clusters` et des `true_gaps`.
- Lister toutes les opportunités textuelles (sujets suggérés).

### 2. Génération d'Embeddings (Batch)
- Utiliser l'API OpenAI (`text-embedding-3-small`).
- Envoyer toutes les opportunités en UNE SEULE requête pour minimiser la latence.
- Récupérer les vecteurs.

### 3. Calcul de Similarité (Local PHP)
- Pour chaque opportunité :
    - Comparer son vecteur avec les embeddings des articles existants du silo (`_sil_embedding` déjà présent en base).
    - Calculer la similarité Cosine (produit scalaire des vecteurs normalisés).

### 4. Logique de Décision
- **Si Similarité > 0.85** (ou seuil paramétrable) :
    - Action : `RECOMMEND_UPDATE`
    - Cible : ID de l'article le plus proche.
    - Raison : "Sujet très proche de l'existant (Cannibalisation)".
- **Si Similarité < 0.85** :
    - Action : `RECOMMEND_CREATE`
    - Raison : "Angle thématique distinct".

### 5. Export JSON
- Injecter ces décisions dans la clé `opportunities` du fichier `sil-audit-data.json`.

## Changements de fichiers
- `includes/class-sil-cluster-analysis.php` : Cœur de la logique de calcul.
- `includes/class-sil-openai-client.php` (ou équivalent) : Ajout de la méthode batch embedding.

## Plan de vérification
- Vérifier que le JSON contient bien les nouveaux champs `{action: "UPDATE", target_id: 123...}`.
- Tester avec des sujets volontairement proches pour valider le déclenchement du `UPDATE`.
