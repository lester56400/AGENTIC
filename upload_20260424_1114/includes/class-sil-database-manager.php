<?php
/**
 * SIL_Database_Manager
 * Gestion professionnelle du schéma SQL pour Smart Internal Links.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIL_Database_Manager
{
    private $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Crée ou met à jour les tables du plugin via dbDelta.
     */
    public function manage_tables()
    {
        $charset_collate = $this->wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // 1. Table des Embeddings
        $table_embeddings = $this->wpdb->prefix . 'sil_embeddings';
        $sql_embeddings = "CREATE TABLE $table_embeddings (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            embedding longtext NOT NULL,
            content_hash varchar(32) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_id (post_id),
            KEY content_hash (content_hash)
        ) $charset_collate;";

        // 2. Table GSC Data (SCHEMA BMAD 3 COMPLET)
        $table_gsc = $this->wpdb->prefix . 'sil_gsc_data';
        $sql_gsc = "CREATE TABLE $table_gsc (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            url varchar(255) NOT NULL,
            clicks int(11) DEFAULT 0,
            impressions int(11) DEFAULT 0,
            position float DEFAULT 0,
            clicks_delta int(11) DEFAULT 0,
            clicks_delta_percent float DEFAULT 0,
            yield_delta_percent float DEFAULT 0,
            impressions_delta int(11) DEFAULT 0,
            position_delta float DEFAULT 0,
            top_queries text,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_id (post_id),
            KEY url (url)
        ) $charset_collate;";

        // 3. Table des Liens
        $table_links = $this->wpdb->prefix . 'sil_links';
        $sql_links = "CREATE TABLE $table_links (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            source_id bigint(20) NOT NULL,
            target_id bigint(20) NOT NULL,
            target_url varchar(255) NOT NULL,
            anchor varchar(255) NOT NULL,
            status varchar(20) DEFAULT 'valid',
            click_count int(11) DEFAULT 0,
            last_checked datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_id (source_id),
            KEY target_id (target_id),
            KEY status (status)
        ) $charset_collate;";

        // 4. Table des Silos Sémantiques (Fuzzy C-Means)
        $table_membership = $this->wpdb->prefix . 'sil_silo_membership';
        $sql_membership = "CREATE TABLE $table_membership (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            silo_id tinyint(4) NOT NULL,
            score float NOT NULL,
            is_primary tinyint(1) DEFAULT 0,
            is_bridge tinyint(1) DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_silo (post_id, silo_id),
            KEY post_id (post_id),
            KEY silo_id (silo_id),
            KEY is_bridge (is_bridge)
        ) $charset_collate;";

        // 5. Table de Log d'Actions (ROI Monitoring) v2.5
        $table_action_log = $this->wpdb->prefix . 'sil_action_log';
        $sql_action_log = "CREATE TABLE $table_action_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            action_type varchar(50) NOT NULL,
            post_id_source bigint(20) DEFAULT NULL,
            post_id_target bigint(20) DEFAULT NULL,
            initial_stats longtext DEFAULT NULL,
            expected_gain longtext DEFAULT NULL,
            status varchar(20) DEFAULT 'incubation',
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id_source (post_id_source),
            KEY post_id_target (post_id_target),
            KEY status (status)
        ) $charset_collate;";

        // 6. Table de l'Incubateur de Liens (Brouillons planifiés)
        $table_scheduled_links = $this->wpdb->prefix . 'sil_scheduled_links';
        $sql_scheduled_links = "CREATE TABLE $table_scheduled_links (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            source_id bigint(20) NOT NULL,
            target_id bigint(20) NOT NULL,
            anchor varchar(255) NOT NULL,
            note text DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY target_id (target_id),
            KEY status (status)
        ) $charset_collate;";

        dbDelta($sql_embeddings);
        dbDelta($sql_gsc);
        dbDelta($sql_links);
        dbDelta($sql_membership);
        dbDelta($sql_action_log);
        dbDelta($sql_scheduled_links);

        // 7. [NEW V10] Table de Cache des Micro-Embeddings (Paragraphes)
        $table_micro_cache = $this->wpdb->prefix . 'sil_micro_cache';
        $sql_micro_cache = "CREATE TABLE $table_micro_cache (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            paragraph_hash varchar(32) NOT NULL,
            vector longtext NOT NULL,
            content text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY paragraph_hash (paragraph_hash)
        ) $charset_collate;";

        dbDelta($sql_micro_cache);
    }

    /**
     * TEST DE VÉRIFICATION BMAD 3
     * 1. Vérifie l'existence de la table wp_sil_gsc_data.
     * 2. Vérifie spécifiquement la présence de la colonne yield_delta_percent.
     * 3. Affiche "SUCCESS: Database schema matches specification" si tout est correct.
     * 
     * public static function test_db_integrity() {
     *     global $wpdb;
     *     $table_name = $wpdb->prefix . 'sil_gsc_data';
     *     $column = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'yield_delta_percent'");
     *     if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") && !empty($column)) {
     *         echo "SUCCESS: Database schema matches specification";
     *     } else {
     *         echo "FAILURE: Schema mismatch or table missing";
     *     }
     * }
     */

    /**
     * Vérifie si la table de log existe, sinon lance manage_tables.
     * Permet de forcer l'installation sans réactiver le plugin.
     */
    public function check_and_install_tables()
    {
        $table_action = $this->wpdb->prefix . 'sil_action_log';
        $table_scheduled = $this->wpdb->prefix . 'sil_scheduled_links';

        if ($this->wpdb->get_var("SHOW TABLES LIKE '$table_action'") != $table_action || 
            $this->wpdb->get_var("SHOW TABLES LIKE '$table_scheduled'") != $table_scheduled) {
            $this->manage_tables();
        }
    }
}
