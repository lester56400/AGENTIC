<?php
/**
 * Smart Internal Links - Entity Manager
 * Gère l'extraction des concepts clés (Entités) via OpenAI GPT.
 */

if (!defined('ABSPATH')) exit;

class SIL_Entity_Manager {

    private $api_key;
    private $model = 'gpt-4o-mini';

    public function __construct($api_key) {
        $this->api_key = $api_key;
        // On récupère le modèle dynamiquement via l'instance principale
        $main = SmartInternalLinks::get_instance();
        $this->model = $main->get_openai_model();
    }

    /**
     * Extrait les entités sémantiques principales d'un post.
     * @param int $post_id
     * @return array|bool Liste d'entités ou false.
     */
    public function extract_entities($post_id) {
        $post = get_post($post_id);
        if (!$post) return false;

        $content = wp_strip_all_tags($post->post_title . ' ' . $post->post_content);
        $content = mb_substr($content, 0, 4000); // Limite raisonnable pour l'extraction

        $prompt = "Analyse le texte suivant et extrais les 5 à 8 entités nommées ou concepts thématiques les plus importants. 
        Réponds UNIQUEMENT par une liste de mots ou expressions séparés par des virgules, sans numérotation.
        Exemple pour un test de moulin à café : Moulin à café, Broyage, Meule conique, Espresso, Grain.
        
        Texte : " . $content;

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Tu es un expert en SEO sémantique et analyse de contenu.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.3
            ])
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $text = $body['choices'][0]['message']['content'] ?? '';

        if (empty($text)) return false;

        $entities = array_map('sanitize_text_field', array_map('trim', explode(',', $text)));
        $entities = array_filter($entities);

        update_post_meta($post_id, '_sil_entities', $entities);
        return $entities;
    }

    /**
     * Récupère les entités les plus fréquentes dans un groupe d'articles (pour nommer un silo).
     */
    public function get_cluster_label($post_ids) {
        $all_entities = [];
        foreach ($post_ids as $pid) {
            $entities = get_post_meta($pid, '_sil_entities', true);
            if (is_array($entities)) {
                $all_entities = array_merge($all_entities, $entities);
            }
        }

        if (empty($all_entities)) return "";

        $counts = array_count_values($all_entities);
        arsort($counts);
        
        $top = array_slice(array_keys($counts), 0, 2);
        return implode(' / ', $top);
    }
}
