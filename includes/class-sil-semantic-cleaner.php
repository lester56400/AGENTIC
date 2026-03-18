<?php
/**
 * SIL_Semantic_Cleaner
 * Gère l'audit sémantique des liens et le nettoyage manuel.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SIL_Semantic_Cleaner {

    private $main;

    public function __construct($main) {
        $this->main = $main;
    }

    /**
     * Calcule la similarité sémantique entre deux contenus.
     */
    public function get_link_similarity($source_id, $target_id) {
        $source_emb_raw = get_post_meta($source_id, '_sil_embedding', true);
        $target_emb_raw = get_post_meta($target_id, '_sil_embedding', true);

        $source_emb = is_array($source_emb_raw) ? $source_emb_raw : json_decode($source_emb_raw, true);
        $target_emb = is_array($target_emb_raw) ? $target_emb_raw : json_decode($target_emb_raw, true);

        if (empty($source_emb) || empty($target_emb)) {
            return 0;
        }

        if (!class_exists('SIL_Centrality_Engine')) {
            require_once SIL_PLUGIN_DIR . 'includes/class-sil-centrality-engine.php';
        }

        return SIL_Centrality_Engine::get_representativeness_score($source_emb, $target_emb);
    }

    /**
     * AJAX : Lance l'audit sémantique sur tous les liens de la table.
     */
    public function ajax_run_semantic_audit() {
        check_ajax_referer('sil_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sil_links';
        $links = $wpdb->get_results("SELECT id, source_id, target_id FROM $table_name");

        $threshold = floatval(get_option('sil_similarity_threshold', 0.3));
        $count_valid = 0;
        $count_suspect = 0;

        foreach ($links as $link) {
            $similarity = $this->get_link_similarity($link->source_id, $link->target_id);
            $status = ($similarity < $threshold) ? 'suspect' : 'valid';

            $wpdb->update(
                $table_name,
                ['status' => $status, 'last_checked' => current_time('mysql')],
                ['id' => $link->id]
            );

            if ($status === 'suspect') $count_suspect++;
            else $count_valid++;
        }

        wp_send_json_success([
            'message' => 'Audit terminé',
            'valid' => $count_valid,
            'suspect' => $count_suspect
        ]);
    }

    /**
     * AJAX : Supprime physiquement un lien du HTML et de la DB.
     */
    public function ajax_remove_illegitimate_link() {
        check_ajax_referer('sil_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $link_id = intval($_POST['link_id'] ?? 0);
        if (!$link_id) wp_send_json_error('ID de lien manquant');

        global $wpdb;
        $table_name = $wpdb->prefix . 'sil_links';
        $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $link_id));

        if (!$link) wp_send_json_error('Lien introuvable dans la base');

        $source_id = $link->source_id;
        $target_url = $link->target_url;
        $anchor = $link->anchor;

        // 1. Nettoyage du contenu HTML de l'article source
        $post = get_post($source_id);
        if ($post) {
            $content = $post->post_content;
            
            // Pattern robuste : cherche le lien exact vers l'URL cible avec cette ancre
            // On utilise preg_quote pour l'URL et l'ancre pour éviter les erreurs de regex
            $quoted_url = preg_quote($target_url, '/');
            $quoted_anchor = preg_quote($anchor, '/');
            
            // On cherche <a ... href="URL" ...>ANCRE</a> et on ne garde que ANCRE
            // Supporte les attributs variés (target, class, etc.)
            $pattern = '/<a[^>]+href=["\']' . $quoted_url . '["\'][^>]*>' . $quoted_anchor . '<\/a>/is';
            
            $new_content = preg_replace($pattern, $anchor, $content);

            if ($new_content !== $content) {
                wp_update_post([
                    'ID' => $source_id,
                    'post_content' => $new_content
                ]);
            }
        }

        // 2. Suppression de l'entrée dans la base de données
        $wpdb->delete($table_name, ['id' => $link_id]);

        wp_send_json_success(['message' => 'Lien supprimé avec succès']);
    }

    /**
     * TEST DE VÉRIFICATION BMAD 2
     * Simule une comparaison sous le seuil et valide le statut 'suspect'.
     */
    public static function test_cleaner_logic() {
        if (!class_exists('SIL_Centrality_Engine')) {
            require_once SIL_PLUGIN_DIR . 'includes/class-sil-centrality-engine.php';
        }
        
        $v1 = [1, 0, 0];
        $v2 = [0.1, 0.9, 0]; // Très peu similaire
        $threshold = 0.3;
        
        // Simuler le score du moteur
        $sim = SIL_Centrality_Engine::get_representativeness_score($v1, $v2);
        
        $status = ($sim < $threshold) ? 'suspect' : 'valid';
        if ($status === 'suspect') {
            return "SUCCESS: Semantic cleaner logic validated (suspect detected)";
        }
        return "FAILURE: Unexpected status $status";
    }
}
