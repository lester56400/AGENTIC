<?php
/**
 * Smart Internal Links - Embedding Manager
 * Centralise la logique OpenAI et les calculs de similarité vectorielle.
 */

if (!defined('ABSPATH')) exit;

class SIL_Embedding_Manager {

    private $api_key;
    private $table_name;
    private $micro_table_name;
    private $model = 'text-embedding-3-small';

    /**
     * @param string $api_key Clé API OpenAI.
     * @param string $table_name Nom de la table d'embeddings.
     */
    public function __construct($api_key, $table_name, $micro_table_name = '') {
        $this->api_key = $api_key;
        $this->table_name = $table_name;
        $this->micro_table_name = $micro_table_name ?: $table_name . '_micro'; // Fallback logic
    }

    /**
     * Calcule la similarité cosinus entre deux vecteurs.
     */
    public function calculate_similarity($vec1, $vec2) {
        if (!is_array($vec1) || !is_array($vec2) || empty($vec1) || empty($vec2)) {
            return 0;
        }

        $dot = 0;
        $norm1 = 0;
        $norm2 = 0;
        $len = min(count($vec1), count($vec2));

        for ($i = 0; $i < $len; $i++) {
            $dot += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }

        if ($norm1 == 0 || $norm2 == 0) return 0;
        return $dot / (sqrt($norm1) * sqrt($norm2));
    }

    /**
     * Génère et sauvegarde l'embedding pour un post donné.
     * @param int $post_id
     * @return bool Succès de l'opération.
     */
    public function generate_post_embedding($post_id) {
        global $wpdb;

        // Préparation du contenu (Titre + Corps)
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') return false;

        $content = wp_strip_all_tags($post->post_title . ' ' . $post->post_content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = mb_substr($content, 0, 8000); // Limite de tokens
        $content_hash = md5($content);

        // Vérification si l'article a changé
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT content_hash FROM {$this->table_name} WHERE post_id = %d",
            $post_id
        ));

        if ($existing === $content_hash) {
            return true;
        }

        $embedding = $this->get_remote_embedding($content);
        if (!$embedding) return false;

        return $this->save_embedding($post_id, $embedding, $content_hash);
    }

    /**
     * Appel API OpenAI pour un texte unique.
     */
    public function get_remote_embedding($text) {
        if (empty($this->api_key)) return false;

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model' => $this->model,
                'input' => $text
            ])
        ]);

        if (is_wp_error($response)) {
            error_log("SIL ERROR: OpenAI API connection error: " . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['error'])) {
            error_log("SIL ERROR: OpenAI API responded with error: " . print_r($body['error'], true));
            return false;
        }

        return $body['data'][0]['embedding'] ?? false;
    }

    /**
     * Récupère les embeddings pour un lot de textes (Batch).
     */
    public function batch_get_embeddings($texts) {
        if (empty($texts) || empty($this->api_key)) return [];

        $input = array_map(function($t) { 
            return mb_substr(wp_strip_all_tags($t), 0, 3000); 
        }, $texts);

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json'
            ],
            'body' => json_encode([
                'model' => $this->model,
                'input' => $input
            ])
        ]);

        if (is_wp_error($response)) return [];

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['data']) || !is_array($body['data'])) return [];

        $embeddings = [];
        foreach ($body['data'] as $item) {
            if (isset($item['embedding'])) $embeddings[] = $item['embedding'];
        }

        return $embeddings;
    }

    /**
     * Trouve le meilleur paragraphe dans un article source par rapport à un vecteur cible.
     */
    public function get_best_paragraph_match($source_id, $target_vector) {
        global $wpdb;

        if (empty($this->micro_table_name) || empty($target_vector)) return false;

        // 1. S'assurer que le cache est à jour
        $this->refresh_micro_cache($source_id);

        // 2. Récupérer l'ordre réel des paragraphes via le chunker
        if (!class_exists('SIL_Content_Chunker')) {
            require_once SIL_PLUGIN_DIR . 'includes/class-sil-content-chunker.php';
        }
        $chunker = new SIL_Content_Chunker();
        $paragraphs_full = $chunker->get_paragraphs($source_id, true);
        $i = 0;
        $hash_to_index = [];
        foreach ($paragraphs_full as $hash => $data) {
            $hash_to_index[$hash] = $i++;
        }

        // 3. Récupérer les vecteurs du cache
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT paragraph_hash, content, vector FROM {$this->micro_table_name} WHERE post_id = %d",
            $source_id
        ));

        if (empty($rows)) return false;

        $best_score = -1;
        $best_paragraph = '';
        $best_index = 0;

        foreach ($rows as $row) {
            // --- PROTECTION COLLISION (Option B - Swiss Precision) ---
            // On vérifie le lien dans la version RAW (avec balises) du Chunker
            $raw_content = isset($paragraphs_full[$row->paragraph_hash]) ? $paragraphs_full[$row->paragraph_hash]['raw'] : '';
            if (preg_match('/<a\s+/i', $raw_content)) continue;

            $vector = json_decode($row->vector, true);
            if (!$vector) continue;

            $similarity = $this->calculate_similarity($vector, $target_vector);
            if ($similarity > $best_score) {
                $best_score = $similarity;
                $best_paragraph = $row->content;
                $best_index = isset($hash_to_index[$row->paragraph_hash]) ? $hash_to_index[$row->paragraph_hash] : 0;
            }
        }

        return [
            'content' => $best_paragraph,
            'p_index' => $best_index,
            'score'   => round($best_score, 4)
        ];
    }

    /**
     * Met à jour le cache des micro-embeddings pour un post.
     * v2.6 - Swiss Precision: Context-Peeking pour les paragraphes courts.
     */
    private function refresh_micro_cache($post_id) {
        global $wpdb;

        if (!class_exists('SIL_Content_Chunker')) {
            require_once SIL_PLUGIN_DIR . 'includes/class-sil-content-chunker.php';
        }

        $chunker = new SIL_Content_Chunker();
        $paragraphs = $chunker->get_paragraphs($post_id);

        if (empty($paragraphs)) return;

        // Récupérer les hashes existants
        $existing_hashes = $wpdb->get_col($wpdb->prepare(
            "SELECT paragraph_hash FROM {$this->micro_table_name} WHERE post_id = %d",
            $post_id
        ));

        $p_keys = array_keys($paragraphs);
        $p_values = array_values($paragraphs);
        $count = count($p_values);
        
        $to_vectorize = [];
        $vectorization_map = []; // hash => stabilized_text

        for ($i = 0; $i < $count; $i++) {
            $hash = $p_keys[$i];
            if (in_array($hash, $existing_hashes)) continue;

            $text = $p_values[$i];
            $stabilized_text = $text;

            // --- CONTEXT-PEEKING (Swiss Precision) ---
            // Si le paragraphe est court (< 150 chars), on aggrège les suivants
            if (mb_strlen($text) < 150) {
                $peek_idx = $i + 1;
                while (mb_strlen($stabilized_text) < 150 && $peek_idx < $count) {
                    $stabilized_text .= " " . $p_values[$peek_idx];
                    $peek_idx++;
                }
            }

            $vectorization_map[$hash] = $stabilized_text;
            $to_vectorize[] = $stabilized_text;
        }

        if (empty($to_vectorize)) return;

        // Batch vectorization
        $embeddings = $this->batch_get_embeddings($to_vectorize);

        if (count($embeddings) === count($to_vectorize)) {
            $idx = 0;
            foreach ($vectorization_map as $hash => $stable_text) {
                $wpdb->insert($this->micro_table_name, [
                    'post_id' => $post_id,
                    'paragraph_hash' => $hash,
                    'vector' => json_encode($embeddings[$idx]),
                    'content' => $paragraphs[$hash] // On garde l'original pour l'affichage/mapping
                ], ['%d', '%s', '%s', '%s']);
                $idx++;
            }
        }
    }

    /**
     * Sauvegarde en base de données.
     */
    public function save_embedding($post_id, $embedding, $hash) {
        global $wpdb;
        $result = $wpdb->replace($this->table_name, [
            'post_id' => $post_id,
            'embedding' => json_encode($embedding),
            'content_hash' => $hash
        ], ['%d', '%s', '%s']);

        if ($result !== false) {
            error_log("SIL SUCCESS: Saved embedding for post $post_id");
            return true;
        }
        return false;
    }
}
