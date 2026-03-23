<?php
/**
 * Plugin Name: _Smart Internal Links
 * Description: Génère automatiquement des liens internes pertinents grâce aux embeddings et OpenAI. Supporte Articles et Pages.
 * Version: 2.3.0
 * Author: Jennifer Larcher
 * Author URI: https://redactiwe.systeme.io/formation-redacteur-ia
 * Text Domain: smart-internal-links
 */

if (!defined('ABSPATH')) {
    exit;
}



define('SIL_VERSION', '2.3.0.' . time());
define('SIL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SIL_PLUGIN_URL', plugin_dir_url(__FILE__));

class SmartInternalLinks
{

    private static $instance = null;
    public $table_name;
    private $api_key;
    private $db_manager;
    public $semantic_silos;

    // Types de contenus supportés (Blog + Pages produits/services)
    public $post_types = ['post', 'page'];

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_post_types()
    {
        return $this->post_types;
    }

    private function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sil_embeddings';
        $this->api_key = get_option('sil_openai_api_key');

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Cornerstone & Meta boxes
        add_action('add_meta_boxes', [$this, 'add_cornerstone_meta_box']);
        add_action('save_post', [$this, 'save_cornerstone_meta_box']);

        // Ajax Hooks
        require_once SIL_PLUGIN_DIR . 'includes/class-sil-ajax-handler.php';
        $ajax_handler = new SIL_Ajax_Handler($this);

        // --- BMAD 2 : Semantic Cleaner ---
        require_once SIL_PLUGIN_DIR . 'includes/class-sil-semantic-cleaner.php';
        $semantic_cleaner = new SIL_Semantic_Cleaner($this);

        add_action('wp_ajax_sil_run_semantic_audit', [$semantic_cleaner, 'ajax_run_semantic_audit']);
        add_action('wp_ajax_sil_remove_illegitimate_link', [$semantic_cleaner, 'ajax_remove_illegitimate_link']);
        $ajax_handler->init($ajax_handler);

        add_action('wp_ajax_sil_generate_links', [$this, 'ajax_generate_links']);
        add_action('wp_ajax_sil_fix_silo_leak', [$this, 'ajax_fix_silo_leak']);
        add_action('wp_ajax_sil_apply_selected_links', [$ajax_handler, 'sil_insert_internal_link']);
        add_action('wp_ajax_sil_regenerate_embeddings', [$this, 'ajax_regenerate_embeddings']);
        add_action('wp_ajax_sil_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_sil_reset_no_match', [$this, 'ajax_reset_no_match']);
        add_action('wp_ajax_sil_check_links_health', [$this, 'ajax_check_links_health']);

        // REST API Init
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Nouveau : Hook pour la visualisation
        add_action('wp_ajax_sil_save_cornerstone', [$ajax_handler, 'sil_save_settings']);
        add_action('wp_ajax_sil_refresh_index_status', [$this, 'ajax_refresh_index_status']);
        add_action('wp_ajax_sil_get_graph_data', [$ajax_handler, 'sil_get_graph_data']);
        add_action('wp_ajax_sil_get_node_details', [$ajax_handler, 'sil_get_node_details']);

        add_action('wp_ajax_sil_get_all_ids_for_gsc_sync', [$this, 'ajax_get_all_ids_for_gsc_sync']);
        add_action('wp_ajax_sil_force_gsc_sync_batch', [$ajax_handler, 'sil_process_batch']);
        add_action('wp_ajax_sil_force_gsc_sync', [$this, 'ajax_force_gsc_sync']);
        add_action('wp_ajax_sil_gsc_oauth_redirect', [$this, 'ajax_gsc_oauth_redirect']);
        add_action('wp_ajax_sil_gsc_oauth_callback', [$this, 'ajax_gsc_oauth_callback']);
        add_action('wp_ajax_sil_gsc_oauth_disconnect', [$this, 'ajax_gsc_oauth_disconnect']);
        add_action('wp_ajax_sil_save_checklist_item', [$this, 'ajax_save_checklist_item']); // NEW
        add_action('wp_ajax_sil_delete_edge_link', [$ajax_handler, 'sil_delete_edge_link']); // NEW Phase 2
        add_action('wp_ajax_sil_remove_link', [$ajax_handler, 'sil_delete_edge_link']); // ALIAS pour le nettoyage sémantique
        add_action('wp_ajax_sil_remove_internal_link', [$ajax_handler, 'sil_remove_internal_link']);
        add_action('wp_ajax_sil_track_click', [$ajax_handler, 'sil_track_click']);
        add_action('wp_ajax_nopriv_sil_track_click', [$ajax_handler, 'sil_track_click']);


        add_action('wp_ajax_sil_search_posts_for_link', [$ajax_handler, 'sil_search_posts_for_link']);
        add_action('wp_ajax_sil_add_internal_link_from_map', [$ajax_handler, 'sil_add_internal_link_from_map']);
        add_action('wp_ajax_sil_update_seo_meta', [$ajax_handler, 'sil_update_seo_meta']);
        add_action('wp_ajax_sil_generate_bridge_prompt', [$ajax_handler, 'sil_generate_bridge_prompt']);
        add_action('wp_ajax_sil_apply_anchor_context', [$ajax_handler, 'sil_apply_anchor_context']);
        add_action('wp_ajax_sil_generate_seo_meta', [$ajax_handler, 'sil_generate_seo_meta']);
        add_action('wp_ajax_sil_invent_anchor_and_link', [$ajax_handler, 'sil_invent_anchor_and_link']);
        add_action('wp_ajax_sil_get_content_gap', [$ajax_handler, 'sil_get_content_gap_data']);

        // --- BMAD 1-E : Alias pour la création de pont sémantique ---
        add_action('wp_ajax_sil_create_semantic_bridge', [$ajax_handler, 'sil_generate_bridge_prompt']);

        // --- Semantic Silos (Fuzzy C-Means) ---
        add_action('wp_ajax_sil_rebuild_semantic_silos', [$ajax_handler, 'sil_rebuild_semantic_silos']);
        add_action('wp_ajax_sil_get_missing_inlinks',    [$ajax_handler, 'sil_get_missing_inlinks']);

        // AJAX Debug Logs


        add_action('transition_post_status', [$this, 'on_post_first_publish'], 10, 3);
        add_action('save_post', [$this, 'on_save_post'], 10, 3);
        add_action('admin_notices', [$this, 'display_suggestions_notice']);


        // Intégration GSC Data Sync
        require_once plugin_dir_path(__FILE__) . 'includes/class-sil-gsc-handler.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-sil-gsc-sync.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-sil-cluster-analysis.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-sil-database-manager.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-sil-centrality-engine.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-sil-semantic-silos.php';

        $this->db_manager     = new SIL_Database_Manager();
        $this->semantic_silos = new SIL_Semantic_Silos();

        add_action('sil_gsc_daily_sync', function () {
            if (get_option('sil_gsc_auto_sync') === '1') {
                $sync = new \Sil_Gsc_Sync();
                $sync->sync_data();
            }
        });

        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widgets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        register_activation_hook(__FILE__, [$this, 'activate']);
    }

    public function activate()
    {
        $this->db_manager->manage_tables();

        // Planification de la tâche Cron quotidienne pour GSC
        if (!wp_next_scheduled('sil_gsc_daily_sync')) {
            wp_schedule_event(time(), 'daily', 'sil_gsc_daily_sync');
        }
    }



    public function sil_register_rest_routes() {
        register_rest_route('sil/v1', '/graph', [
            'methods' => 'GET',
            'callback' => [$this, 'sil_rest_get_graph_data'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);
    }

    public function sil_rest_get_graph_data($request) {
        $force = $request->get_param('refresh') === 'true';
        try {
            $result = $this->get_rendered_graph_data($force);
            return rest_ensure_response(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            return new WP_Error('sil_graph_error', $e->getMessage(), ['status' => 500]);
        }
    }

    public function add_admin_menu()
    {

        add_menu_page(
            'Smart Internal Links',
            'Smart Links',
            'edit_posts',
            'smart-internal-links',
            [$this, 'render_admin_page'],
            'dashicons-admin-links',
            30
        );

        add_submenu_page(
            'smart-internal-links',
            'Réglages',
            'Réglages',
            'manage_options',
            'sil-settings',
            [$this, 'render_settings_page']
        );
        add_submenu_page(
            'smart-internal-links',
            'Cartographie Interactive',
            'Cartographie',
            'manage_options',
            'sil-cartographie',
            [$this, 'render_cartographie_page']
        );



        add_submenu_page(
            'smart-internal-links',
            'Réglages GSC',
            'Réglages GSC',
            'manage_options',
            'sil-gsc-settings',
            [$this, 'render_gsc_settings_page']
        );
        add_submenu_page(
            'smart-internal-links',
            'Dashboard de Cohérence',
            'Dashboard de Cohérence',
            'manage_options',
            'sil-opportunites',
            [$this, 'render_content_gap_page']
        );

    }

    /**
     * Ajoute les widgets au tableau de bord WordPress
     */
    public function add_dashboard_widgets()
    {
        wp_add_dashboard_widget(
            'sil_orphan_widget',
            'SIL : Contenus Orphelins',
            [$this, 'render_orphan_widget']
        );
    }

    /**
     * Affiche le widget des contenus orphelins
     */
    public function render_orphan_widget()
    {
        global $wpdb;
        $orphan_count = get_transient('sil_orphan_count');

        if (false === $orphan_count) {
            $orphan_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} 
                WHERE post_type = 'post' 
                AND post_status = 'publish' 
                AND ID NOT IN (SELECT DISTINCT target_id FROM {$wpdb->prefix}sil_links)"
            ));
            set_transient('sil_orphan_count', $orphan_count, 12 * HOUR_IN_SECONDS);
        }

        ?>
                <div class="sil-dashboard-widget">
                    <div style="text-align: center; padding: 20px 0;">
                        <span style="font-size: 48px; font-weight: bold; color: #d63638; display: block; line-height: 1;">
                            <?php echo esc_html($orphan_count); ?>
                        </span>
                        <span style="font-size: 14px; color: #50575e; margin-top: 10px; display: block;">
                            Articles sans aucun lien entrant
                        </span>
                    </div>
                    <p style="text-align: center; border-top: 1px solid #f0f0f1; padding-top: 15px; margin-top: 5px;">
                        <a href="<?php echo admin_url('admin.php?page=sil-cartographie'); ?>" class="button button-primary">
                            Voir la Cartographie
                        </a>
                    </p>
                </div>
                <style>
                    #sil_orphan_widget .inside {
                        padding: 0;
                        margin: 0;
                    }

                    .sil-dashboard-widget {
                        padding: 12px;
                    }
                </style>
                <?php
    }



    public function register_settings()
    {
        register_setting('sil_settings', 'sil_openai_api_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sil_settings', 'sil_openai_model', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sil_settings', 'sil_openai_custom_model', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sil_settings', 'sil_openai_seo_prompt', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('sil_settings', 'sil_openai_bridge_prompt', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('sil_settings', 'sil_auto_link', ['sanitize_callback' => 'absint']);
        register_setting('sil_settings', 'sil_max_links', ['sanitize_callback' => 'absint']);
        register_setting('sil_settings', 'sil_similarity_threshold', ['sanitize_callback' => 'floatval']);
        register_setting('sil_settings', 'sil_link_scope', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sil_settings', 'sil_exclude_external', ['sanitize_callback' => 'absint']);
        register_setting('sil_settings', 'sil_exclude_noindex', ['sanitize_callback' => 'absint']);
        register_setting('sil_settings', 'sil_infomap_api_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('sil_settings', 'sil_target_permeability', ['sanitize_callback' => 'absint']);
        register_setting('sil_settings', 'sil_semantic_k',           ['sanitize_callback' => 'absint']);
        register_setting('sil_settings', 'sil_node_repulsion',       ['sanitize_callback' => 'absint']);
        register_setting('sil_settings', 'sil_component_spacing',    ['sanitize_callback' => 'absint']);
        register_setting('sil_settings', 'sil_gravity',              ['sanitize_callback' => 'floatval']);

        // --- NOUVEAU: GSC Settings (OAuth 2.0) - Dedicated Group ---
        register_setting('sil_gsc_settings', 'sil_gsc_property_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('sil_gsc_settings', 'sil_gsc_client_id', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sil_gsc_settings', 'sil_gsc_client_secret', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sil_gsc_settings', 'sil_gsc_oauth_tokens'); // Store array of tokens directly
        register_setting('sil_gsc_settings', 'sil_gsc_last_sync');
        register_setting('sil_gsc_settings', 'sil_gsc_auto_sync', ['sanitize_callback' => 'absint']);
    }

    // ==============================================
    // CORNERSTONE META BOX
    // ==============================================

    public function add_cornerstone_meta_box()
    {
        foreach ($this->post_types as $type) {
            add_meta_box(
                'sil_cornerstone_box',
                'Smart Internal Links - SEO',
                [$this, 'render_cornerstone_box'],
                $type,
                'side',
                'high'
            );
        }
    }

    public function render_cornerstone_box($post)
    {
        $is_cornerstone = get_post_meta($post->ID, '_sil_is_cornerstone', true);
        $index_status = get_post_meta($post->ID, '_sil_gsc_index_status', true);
        if (empty($index_status)) {
            $index_status = 'Inconnu';
        }

        wp_nonce_field('sil_cornerstone_save', 'sil_cornerstone_nonce');
        ?>
                <div style="margin-top: 10px;">
                    <label>
                        <input type="checkbox" name="sil_is_cornerstone" value="1" <?php checked($is_cornerstone, '1'); ?> />
                        <strong>Contenu Pilier (Cornerstone)</strong>
                    </label>
                    <p class="description" style="margin-top:5px;">
                        Cochez cette case si cette page est stratégique. Elle sera mise en avant dans les suggestions de maillage.
                    </p>
                </div>

                <hr style="margin: 15px 0;">

                <div style="margin-top: 10px;">
                    <strong>Statut d'Indexation (GSC) :</strong>
                    <p style="margin: 5px 0;">
                        <span id="sil-index-status-badge"
                            style="display:inline-block; padding: 3px 8px; border-radius: 3px; background: #f0f0f1; border: 1px solid #8c8f94; font-weight:600;">
                            <?php echo esc_html($index_status); ?>
                        </span>
                    </p>
                    <button type="button" class="button button-secondary" id="sil-refresh-index-status"
                        data-post-id="<?php echo esc_attr($post->ID); ?>">
                        Rafraîchir l'indexation
                    </button>
                    <span class="spinner" id="sil-index-spinner" style="float:none; margin-top:0;"></span>
                    <p class="description" style="margin-top:5px;">
                        Utile pour forcer la vérification via l'API Google au lieu d'attendre l'analyse mensuelle.
                    </p>
                </div>

                <script>
                    jQuery(document).ready(function ($) {
                        $('#sil-refresh-index-status').on('click', function (e) {
                            e.preventDefault();
                            var btn = $(this);
                            var spinner = $('#sil-index-spinner');
                            var postId = btn.data('post-id');
                            btn.prop('disabled', true);
                            spinner.addClass('is-active');
                            $.post(ajaxurl, {
                                action: 'sil_refresh_index_status',
                                post_id: postId,
                                security: '<?php echo wp_create_nonce("sil_cornerstone_save"); ?>'
                            }, function (response) {
                                btn.prop('disabled', false);
                                spinner.removeClass('is-active');
                                if (response.success && response.data.status) {
                                    $('#sil-index-status-badge').text(response.data.status).css('background', '#d1e7dd');
                                } else {
                                    alert('Erreur lors de la vérification');
                                }
                            }).fail(function () {
                                btn.prop('disabled', false);
                                spinner.removeClass('is-active');
                                alert('Erreur réseau.');
                            });
                        });
                    });
                </script>
                <?php
    }

    public function save_cornerstone_meta_box($post_id)
    {
        if (!isset($_POST['sil_cornerstone_nonce']) || !wp_verify_nonce($_POST['sil_cornerstone_nonce'], 'sil_cornerstone_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return;

        $post_id = intval($post_id);
        if (!current_user_can('edit_post', $post_id))
            return;

        if (isset($_POST['sil_is_cornerstone'])) {
            update_post_meta($post_id, '_sil_is_cornerstone', '1');
        } else {
            delete_post_meta($post_id, '_sil_is_cornerstone');
        }
    }

    /**
     * Charge les scripts sur le front-end
     */
    public function enqueue_frontend_scripts()
    {
        wp_enqueue_script('sil-tracking', plugin_dir_url(__FILE__) . 'assets/sil-tracking.js', ['jquery'], SIL_VERSION, true);

        wp_localize_script('sil-tracking', 'silTracking', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sil_tracking_nonce')
        ]);
    }

    // ==============================================
    // SCRIPTS & STYLES
    // ==============================================

    public function enqueue_admin_scripts($hook)
    {
        // 1. Détection stricte de nos pages (slug toplevel ou sous-menus)
        // Les hooks ressemblent à "toplevel_page_smart-internal-links" ou "smart-links_page_sil-settings"
        $is_plugin_page = (strpos($hook, 'smart-internal-links') !== false || strpos($hook, 'sil-') !== false);

        if (!$is_plugin_page) {
            return;
        }

        // 2. CSS Global Admin
        wp_enqueue_style('sil-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], SIL_VERSION);

        // 3. Dépendance Louvain (Supprimée - Infomap Cluster Analysis géré côté serveur)

        // 4. Chargement spécifique selon la page

        // --- PAGES DE GRAPHE (Cytoscape + Graph.js) ---
        if (strpos($hook, 'sil-visualisation') !== false || strpos($hook, 'sil-visualization') !== false || strpos($hook, 'sil-cartographie') !== false) {
            wp_enqueue_script('cytoscape', 'https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.26.0/cytoscape.min.js', [], '3.26.0', true);

            wp_enqueue_script('sil-graph-js', plugin_dir_url(__FILE__) . 'assets/graph.js', ['jquery', 'cytoscape'], SIL_VERSION, true);
            wp_enqueue_style('sil-graph-css', plugin_dir_url(__FILE__) . 'assets/graph.css', [], SIL_VERSION);

            wp_localize_script('sil-graph-js', 'silGraphData', [
                'restUrl' => esc_url_raw(rest_url('sil/v1/graph-data')),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sil_nonce'),
                'rest_nonce' => wp_create_nonce('wp_rest'),
                'target_permeability' => get_option('sil_target_permeability', 20),
                'repulsion' => get_option('sil_node_repulsion', 300000),
                'spacing' => get_option('sil_component_spacing', 60),
                'gravity' => get_option('sil_gravity', 2.0)
            ]);
        }

        // --- PAGE PRINCIPALE & REGLAGES & CONTENT GAP (Admin.js) ---
        if ($hook === 'toplevel_page_smart-internal-links' || strpos($hook, 'sil-settings') !== false || strpos($hook, 'sil-gsc-settings') !== false || strpos($hook, 'sil-content-gap') !== false || strpos($hook, 'sil-opportunites') !== false) {
            // Enqueue custom JS
            wp_enqueue_script('sil-admin-js', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], '2.3.0', true);
            // Fix SQL : Suppression de la requête JSON_EXTRACT qui créait une erreur fatale sur certains environnements.
            wp_localize_script('sil-admin-js', 'silAjax', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sil_nonce'),
                'home_url' => home_url('/'),
                'admin_url' => admin_url('admin.php'),
                'maxClicks' => 100 // Valeur par défaut suffisante
            ]);
        }
    }

    // ==============================================
    // HELPER: NOINDEX DETECTION
    // ==============================================

    /**
     * Vérifie si un post est marqué "noindex" par les plugins SEO majeurs
     */
    private function is_noindexed($post_id)
    {
        // Yoast SEO
        $yoast_noindex = get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);
        if ($yoast_noindex == 1 || $yoast_noindex === '1')
            return true;

        // Rank Math
        $rankmath_robots = get_post_meta($post_id, 'rank_math_robots', true);
        if (is_array($rankmath_robots) && in_array('noindex', $rankmath_robots))
            return true;
        if (is_string($rankmath_robots) && strpos($rankmath_robots, 'noindex') !== false)
            return true;

        // SEOPress
        $seopress_noindex = get_post_meta($post_id, '_seopress_robots_index', true);
        if ($seopress_noindex === 'yes')
            return true;

        // All in One SEO Pack
        $aioseo_noindex = get_post_meta($post_id, '_aioseop_noindex', true);
        if ($aioseo_noindex === 'on')
            return true;

        return false;
    }

    /**
     * Calcule le score de santé global du maillage (0-100)
     */
    public function calculate_health_score()
    {
        global $wpdb;

        // Total published posts supported by SIL
        $total_posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND post_status = 'publish'",
            $this->post_types[0],
            $this->post_types[1]
        ));

        if ($total_posts === 0)
            return 0;

        // Part 1: % of posts linked at least once (60%)
        $linked_posts_count = (int) $wpdb->get_var("SELECT COUNT(DISTINCT target_id) FROM {$wpdb->prefix}sil_links");
        $linked_rate = ($linked_posts_count / $total_posts) * 100;
        $score_part1 = $linked_rate * 0.6;

        // Part 2: Link density (40%)
        // Goal: average 2 links created per post
        $total_links = (int) $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}sil_links");
        $ratio = $total_links / $total_posts;
        if ($ratio > 2)
            $ratio = 2;
        $score_part2 = ($ratio / 2) * 100 * 0.4;

        return min(100, round($score_part1 + $score_part2));
    }

    // ==============================================
    // FONCTIONS MÉTIER
    // ==============================================

    public function on_post_first_publish($new_status, $old_status, $post)
    {
        if ($new_status !== 'publish' || $old_status === 'publish')
            return;
        if (!in_array($post->post_type, $this->post_types))
            return;

        // 1. Génération de l'embedding (Indexation sémantique)
        if (get_option('sil_auto_link') === '1') {
            $this->generate_embedding($post->ID);
        }

        // 2. Recherche automatique d'opportunités de maillage
        error_log("SIL: Analyse automatique lancée pour le post #{$post->ID}");
        $analysis = $this->insert_internal_links($post->ID, true); // dry_run = true pour avoir les suggestions

        if (!empty($analysis['links'])) {
            update_post_meta($post->ID, '_sil_suggestions_ready', '1');
            update_post_meta($post->ID, '_sil_suggestions_count', count($analysis['links']));
            error_log("SIL: " . count($analysis['links']) . " suggestions trouvées pour #" . $post->ID);
        } else {
            error_log("SIL: Aucune suggestion trouvée pour #" . $post->ID);
        }

        // 3. Invalidation du cache du graphe
        $this->clear_graph_cache();
    }

    public function on_save_post($post_id, $post, $update)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return;
        if (!current_user_can('edit_post', $post_id))
            return;

        // Nettoyage des métas de cache/statut lors de la modification du contenu
        delete_post_meta($post_id, '_sil_no_match');
        delete_post_meta($post_id, '_sil_no_paragraphs');
        delete_post_meta($post_id, '_sil_suggestions_ready'); // Reset suggestions si le texte change

        // Invalidation du cache du graphe
        $this->clear_graph_cache();
    }

    /**
     * Affiche une notice si des suggestions sont prêtes
     */
    public function display_suggestions_notice()
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, $this->post_types)) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id)
            return;

        if (get_post_meta($post_id, '_sil_suggestions_ready', true) === '1') {
            $count = get_post_meta($post_id, '_sil_suggestions_count', true) ?: 0;
            ?>
                        <div class="notice notice-info is-dismissible">
                            <p>
                                <strong>🚀 Smart Internal Links :</strong>
                                <?php echo sprintf(_n('%d suggestion de maillage est prête.', '%d suggestions de maillage sont prêtes.', $count, 'smart-internal-links'), $count); ?>
                                <a
                                    href="<?php echo admin_url('admin.php?page=smart-internal-links'); ?>#suggestions-for-<?php echo $post_id; ?>">Voir
                                    les opportunités</a>.
                            </p>
                        </div>
                        <?php
        }
    }

    /**
     * Invalide le cache du graphe
     */
    public function clear_graph_cache()
    {
        delete_transient('sil_graph_cache');
    }

    /**
     * Enregistre les routes de l'API REST
     */
    public function register_rest_routes()
    {
        register_rest_route('sil/v1', '/graph-data', [
            'methods' => 'GET',
            'callback' => [$this, 'get_rest_graph_data'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);
    }

    /**
     * Endpoint REST pour les données du graphe
     */
    public function get_rest_graph_data($request)
    {
        $force = $request->get_param('refresh') === 'true';

        if ($force) {
            $this->clear_graph_cache();
        }

        // Retourner un format compatible avec le succès attendu parassets/graph.js (success: true, data: ...)
        return [
            'success' => true,
            'data' => $this->get_rendered_graph_data($force)
        ];
    }

    public function generate_embedding($post_id)
    {
        global $wpdb;

        // Vérification Exclusion Noindex
        if (get_option('sil_exclude_noindex') === '1' && $this->is_noindexed($post_id)) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish')
            return false;

        $content = wp_strip_all_tags($post->post_title . ' ' . $post->post_content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = mb_substr($content, 0, 8000);
        $content_hash = md5($content);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT content_hash FROM {$this->table_name} WHERE post_id = %d",
            $post_id
        ));

        if ($existing === $content_hash) {
            // error_log("SIL: Skipping post $post_id, already up to date.");
            return true;
        }

        $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'text-embedding-3-small',
                'input' => $content
            ])
        ]);

        if (is_wp_error($response)) {
            error_log("SIL ERROR: OpenAI API connection error for post $post_id: " . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['error'])) {
            error_log("SIL ERROR: OpenAI API responded with error for post $post_id: " . print_r($body['error'], true));
            return false;
        }

        if (!isset($body['data'][0]['embedding'])) {
            error_log("SIL ERROR: Malformed OpenAI API response for post $post_id: " . wp_remote_retrieve_body($response));
            return false;
        }

        $embedding = $body['data'][0]['embedding'];

        $wpdb->replace($this->table_name, [
            'post_id' => $post_id,
            'embedding' => json_encode($embedding),
            'content_hash' => $content_hash
        ], ['%d', '%s', '%s']);

        error_log("SIL SUCCESS: Generated and saved embedding for post $post_id");

        return true;
    }

    private function cosine_similarity($vec1, $vec2)
    {
        $dot = 0;
        $norm1 = 0;
        $norm2 = 0;
        $len = min(count($vec1), count($vec2));

        for ($i = 0; $i < $len; $i++) {
            $dot += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }

        if ($norm1 == 0 || $norm2 == 0)
            return 0;
        return $dot / (sqrt($norm1) * sqrt($norm2));
    }

    public function count_internal_links($post_id)
    {
        $post = get_post($post_id);
        if (!$post)
            return 0;

        $site_host = parse_url(home_url(), PHP_URL_HOST);
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches);

        $count = 0;
        foreach ($matches[1] as $url) {
            if (preg_match('/^(#|mailto:|tel:|javascript:)/i', $url))
                continue;

            $url_host = parse_url($url, PHP_URL_HOST);
            if (empty($url_host) || $url_host === $site_host) {
                $count++;
            }
        }

        return $count;
    }

    public function count_backlinks($post_id)
    {
        global $wpdb;

        $post_url = get_permalink($post_id);
        $post_slug = get_post_field('post_name', $post_id);

        if (empty($post_url) || empty($post_slug))
            return 0;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts} 
             WHERE post_type IN ('post', 'page') 
             AND post_status = 'publish' 
             AND ID != %d
             AND (
                 post_content LIKE %s 
                 OR post_content LIKE %s
                 OR post_content LIKE %s
             )",
            $post_id,
            '%href="' . esc_url($post_url) . '"%',
            '%href="' . '%/' . $wpdb->esc_like($post_slug) . '"%',
            '%href="' . '%/' . $wpdb->esc_like($post_slug) . '/"%'
        ));

        return intval($count);
    }

    private function has_external_links($content)
    {
        if (empty($content))
            return false;
        $site_host = parse_url(home_url(), PHP_URL_HOST);
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);

        foreach ($matches[1] as $url) {
            if (preg_match('/^(#|mailto:|tel:|javascript:)/i', $url))
                continue;
            $url_host = parse_url($url, PHP_URL_HOST);
            if (!empty($url_host) && $url_host !== $site_host)
                return true;
        }
        return false;
    }

    /**
     * Recherche des articles sémantiquement similaires au post cible.
     *
     * Utilise les requêtes GSC du post cible (ou les mots-clés du titre en fallback)
     * pour identifier des opportunités de liens dans d'autres articles.
     *
     * @param int  $post_id        ID du post cible (celui qui recevra les liens).
     * @param int  $limit          Nombre maximum de résultats (défaut 5).
     * @param bool $force_category Si true, restreint la recherche au(x) même(s) catégorie(s).
     *
     * @return array {
     *     Liste des candidats trouvés. Chaque élément est un tableau associatif :
     *
     *     @type int    $post_id         ID de l'article source (candidat).
     *     @type string $title           Titre de l'article source.
     *     @type float  $similarity      Score de similarité basé sur les correspondances.
     *     @type string $url             URL de l'article source.
     *     @type bool   $is_cornerstone  Si l'article source est un contenu pilier.
     *     @type string $anchor          Ancre naturelle extraite du texte.
     *     @type int    $paragraph_index Index du paragraphe où l'ancre a été trouvée.
     * }
     */
    public function find_similar_posts($post_id, $limit = 5, $force_category = false)
    {
        global $wpdb;

        $link_scope = get_option('sil_link_scope', 'category');
        $exclude_external = get_option('sil_exclude_external', '0') === '1';
        $exclude_noindex = get_option('sil_exclude_noindex', '0') === '1';

        // 1. Fetch Top 5 GSC Queries for the TARGET POST (the one we want links TO)
        $gsc_table = $wpdb->prefix . 'sil_gsc_data';
        $gsc_data = $wpdb->get_row($wpdb->prepare("SELECT top_queries FROM {$gsc_table} WHERE post_id = %d", $post_id));

        $target_queries = [];
        if ($gsc_data && !empty($gsc_data->top_queries)) {
            $queries = json_decode($gsc_data->top_queries, true);
            if (is_array($queries)) {
                $queries = array_slice($queries, 0, 5); // Take top 5
                foreach ($queries as $q) {
                    if (!empty($q['query'])) {
                        $target_queries[] = strtolower(trim($q['query']));
                    }
                }
            }
        }

        // Fallback to title keywords if no GSC data
        if (empty($target_queries)) {
            $target_post = get_post($post_id);
            if (!$target_post)
                return [];
            $title_words = explode(' ', strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $target_post->post_title)));
            $stop_words = ['le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'en', 'et', 'ou', 'pour', 'avec', 'sur', 'dans'];
            $target_queries = array_filter($title_words, function ($w) use ($stop_words) {
                return strlen($w) > 3 && !in_array($w, $stop_words);
            });
            $target_queries = array_slice(array_values($target_queries), 0, 5);
        }

        if (empty($target_queries))
            return [];

        // Build robust Regex patterns for these queries
        // Example: 'chat noir' -> \bchat[a-z]{0,3}\s+noir[a-z]{0,3}\b
        $patterns = [];
        foreach ($target_queries as $query) {
            $words = explode(' ', $query);
            $regex_parts = [];
            foreach ($words as $word) {
                if (strlen($word) > 2) {
                    // Allow up to 3 trailing characters for plurals/conjugations
                    $regex_parts[] = preg_quote($word, '/') . '[a-z]{0,3}';
                } else {
                    $regex_parts[] = preg_quote($word, '/');
                }
            }
            $patterns[$query] = '/\b' . implode('\s+', $regex_parts) . '\b/iu';
        }

        // 2. Fetch Candidates
        $args = [
            'post_type' => $this->post_types,
            'post_status' => 'publish',
            'post__not_in' => [$post_id],
            'numberposts' => -1,
            'fields' => 'ids'
        ];

        if (($link_scope === 'category' || $force_category) && get_post_type($post_id) === 'post') {
            $cats = wp_get_post_categories($post_id, ['fields' => 'ids']);
            if (!empty($cats)) {
                $args['category__in'] = $cats;
            }
        }

        $candidates = get_posts($args);
        if (empty($candidates))
            return [];

        $placeholders = implode(',', array_fill(0, count($candidates), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID as post_id, post_title, post_content 
             FROM {$wpdb->posts} 
             WHERE ID IN ($placeholders)",
            $candidates
        ));

        $start_fuzzy = microtime(true);
        $results = [];

        foreach ($rows as $row) {
            if ($exclude_noindex && $this->is_noindexed($row->post_id))
                continue;
            if ($exclude_external && $this->has_external_links($row->post_content))
                continue;

            $content = wp_strip_all_tags($row->post_content);
            $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/u', $content); // Split by sentences

            $best_match = null;
            $best_score = 0;
            $matched_anchor = '';

            foreach ($sentences as $sentence) {
                foreach ($patterns as $query => $pattern) {
                    if (preg_match($pattern, $sentence, $matches)) {
                        $score = mb_strlen($query); // Base score on query length
                        // Bonus if it appears early in sentence or exact match
                        if ($matches[0] === $query)
                            $score += 2;

                        if ($score > $best_score) {
                            // NEW: Verify if the anchor is not already inside a link in the candidate paragraph
                            $available_paragraphs = $this->get_available_paragraphs($row->post_content);
                            $in_link = true;
                            foreach ($available_paragraphs as $p) {
                                if (stripos($p['text'], $matches[0]) !== false) {
                                    if (!$this->is_anchor_in_link($p['content'], $matches[0])) {
                                        $in_link = false;
                                        break;
                                    }
                                }
                            }

                            if (!$in_link) {
                                $best_score = $score;
                                $best_match = $sentence;
                                $matched_anchor = $matches[0];
                            }
                        }
                    }
                }
            }

            if ($best_score > 0) {
                $is_cornerstone = get_post_meta($row->post_id, '_sil_is_cornerstone', true) === '1';

                // Fetch candidate's native paragraphs via existing method for exact insertion mapping later
                // We keep the first paragraph that contains the matched anchor
                $available_paragraphs = $this->get_available_paragraphs($row->post_content);
                $para_idx = -1;
                foreach ($available_paragraphs as $p) {
                    if (stripos($p['text'], $matched_anchor) !== false) {
                        $para_idx = $p['index'];
                        break;
                    }
                }

                if ($para_idx > -1) {
                    $results[] = [
                        'post_id' => $row->post_id,
                        'title' => html_entity_decode($row->post_title, ENT_QUOTES | ENT_XML1, 'UTF-8'),
                        'similarity' => $best_score / 10, // Max mock score for sorting
                        'url' => get_permalink($row->post_id),
                        'is_cornerstone' => $is_cornerstone,
                        'anchor' => $this->clean_natural_anchor($matched_anchor), // NATIVE EXTRACTED ANCHOR (Cleaned)
                        'paragraph_index' => $para_idx
                    ];
                }
            }
        }

        usort($results, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        error_log("SIL PROFILE: Semantic fuzzy loop for " . count($rows) . " candidates took " . round(microtime(true) - $start_fuzzy, 2) . "s");
        return array_slice($results, 0, min($limit, intval(get_option('sil_max_links', 3))));
    }

    /**
     * Nettoie les ancres naturelles en retirant les stop words aux extrémités.
     * 
     * @param string $anchor L'ancre à nettoyer.
     * @return string L'ancre nettoyée.
     */
    private function clean_natural_anchor($anchor)
    {
        $anchor = trim($anchor);
        if (empty($anchor)) {
            return '';
        }

        $stop_words = ['le', 'la', 'les', 'un', 'une', 'de', 'du', 'des', 'et', 'ou', 'à', 'au', 'aux', 'pour', 'dans', 'sur', 'avec'];

        // Création du pattern regex pour les stop words aux extrémités
        // \b assure qu'on match des mots entiers
        // (?:le|la|...) liste non capturante des mots
        $pattern = implode('|', array_map('preg_quote', $stop_words));

        // On boucle pour nettoyer récursivement (ex: "le de chat" -> "chat")
        $cleaned = true;
        while ($cleaned) {
            $old_anchor = $anchor;
            // Début de chaîne
            $anchor = preg_replace('/^(?:' . $pattern . ')\b\s+/iu', '', $anchor);
            // Fin de chaîne
            $anchor = preg_replace('/\s+\b(?:' . $pattern . ')$/iu', '', $anchor);

            if ($old_anchor === $anchor) {
                $cleaned = false;
            }
        }

        return ucfirst(trim($anchor));
    }

    /**
     * Vérifie si une ancre se trouve déjà à l'intérieur d'un lien <a> dans un bloc HTML.
     * Utilise DOMDocument pour une analyse robuste.
     * 
     * @param string $html Le fragment HTML (ex: contenu d'un paragraphe).
     * @param string $anchor L'ancre à tester.
     * @return bool True si l'ancre est exclusivement enfermée dans des liens, False si utilisable.
     */
    private function is_anchor_in_link($html, $anchor)
    {
        if (empty($html) || empty($anchor))
            return false;

        // On entoure de balises pour assurer une structure XML valide pour DOMDocument
        $wrapped_html = '<div>' . $html . '</div>';

        $dom = new DOMDocument();
        // Suppression des erreurs d'analyse (libxml) pour le HTML mal formé
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // On cherche l'ancre uniquement dans les TextNodes qui ne sont PAS descendants d'un <a>
        // On utilise la fonction contains() de XPath pour vérifier la présence
        $query = "//text()[not(ancestor::a) and contains(., '" . str_replace("'", "&apos;", $anchor) . "')]";
        $nodes = $xpath->query($query);

        return $nodes->length === 0;
    }

    private function choose_paragraph_and_anchor($paragraphs, $target_title, $target_excerpt, $used_indices, $target_top_query = '')
    {
        $available = [];
        foreach ($paragraphs as $p) {
            if (!in_array($p['index'], $used_indices)) {
                $available[] = "P" . $p['index'] . ": " . mb_substr($p['text'], 0, 200) . "...";
            }
        }
        if (empty($available))
            return null;

        $model = $this->get_openai_model();

        $query_instruction = "";
        if (!empty($target_top_query)) {
            $query_instruction = "\nLa cible se positionne en SEO sur la requête : '{$target_top_query}'. L'ancre DOIT cibler cette thématique ou l'intégrer naturellement.";
        }

        $prompt = "Tu es un expert SEO. Choisis le paragraphe le plus pertinent pour insérer un lien vers cet article:
Article cible: {$target_title} {$query_instruction}
Résumé: {$target_excerpt}
Paragraphes disponibles:
" . implode("\n\n", $available) . "
RÈGLES:
1. Choisis le paragraphe le plus thématiquement lié
2. Si AUCUN n'est pertinent, retourne index: -1
3. L'ancre DOIT contenir des mots-clés de la cible, mais VARIE les formulations.
4. Ancre de 2-6 mots, naturelle, intégrée au texte.
5. INTERDIT: 'cliquez ici', 'en savoir plus', 'découvrez', 'la sélection', 'cet article'
6. Évite la sur-optimisation (bourrage de mots-clés).
Réponds UNIQUEMENT en JSON: {\"index\": X, \"anchor\": \"...\"}";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 10, // Réduit à 10s max (fail fast)
            'headers' => ['Authorization' => 'Bearer ' . $this->api_key, 'Content-Type' => 'application/json'],
            // Removed max_tokens = 100 because sometimes JSON is truncated. Using response format if supported, but standard prompt is fast enough.
            'body' => json_encode([
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.1 // Lowered for faster, more deterministic output
            ])
        ]);

        if (is_wp_error($response))
            return null;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['choices'][0]['message']['content']))
            return null;
        $content = preg_replace('/```json\s*|\s*```/', '', $body['choices'][0]['message']['content']);
        $result = json_decode(trim($content), true);
        if (!$result || !isset($result['index']) || $result['index'] == -1)
            return null;
        return $result;
    }

    /**
     * Extrait les paragraphes éligibles pour l'insertion de liens.
     *
     * Analyse le contenu pour identifier les blocs <p> ou <div> valides,
     * en filtrant par longueur et densité de liens existants.
     *
     * @param string $content Le contenu HTML de l'article à analyser.
     *
     * @return array {
     *     Liste des métadonnées des paragraphes. Chaque élément contient :
     *
     *     @type int    $index      Position ordinale du paragraphe dans le contenu.
     *     @type string $content    Contenu HTML complet du paragraphe.
     *     @type int    $offset     Position brute (offset) dans la chaîne source.
     *     @type string $text       Contenu textuel nettoyé.
     *     @type int    $link_count Nombre de liens <a> déjà présents.
     * }
     */
    public function get_available_paragraphs($content)
    {
        // WordPress often stores text with double-newlines instead of <p> tags
        if (strpos($content, '<p>') === false) {
            $content = wpautop($content);
        }

        $result = preg_match_all('/(?:<p[^>]*>(.*?)<\/p>|<div[^>]*class="[^"]*thrv_text_element[^"]*"[^>]*>(.*?)<\/div>)/is', $content, $matches, PREG_OFFSET_CAPTURE);
        if ($result === false) {
            return []; // Regex failed (likely backtracking limit)
        }

        $paragraphs = [];
        if (!empty($matches[0])) {
            foreach ($matches[0] as $idx => $match) {
                // Decode entities so OpenAI sees plain exact text (e.g. '&' instead of '&amp;')
                $text = html_entity_decode(wp_strip_all_tags($match[0]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $link_count = substr_count(strtolower($match[0]), '<a ');
                if (strlen($text) > 80 && $link_count < 2) {
                    $paragraphs[] = ['index' => $idx, 'content' => $match[0], 'offset' => $match[1], 'text' => trim($text), 'link_count' => $link_count];
                }
            }
        }
        return $paragraphs;
    }

    public function insert_link_in_paragraph($paragraph, $anchor, $target_url, $target_title = '', $target_excerpt = '', $link_id = 0)
    {
        $text = html_entity_decode(wp_strip_all_tags($paragraph), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $anchor_decoded = html_entity_decode($anchor, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Tentative simple par recherche/remplacement si l'ancre exacte est trouvée
        if (stripos($text, $anchor_decoded) !== false) {
            // Vérifie qu'on est pas déjà dans un lien
            // Create a regex-friendly anchor that handles encoded/decoded variations in HTML
            $anchor_regex = str_replace(' ', '\s+', preg_quote($anchor_decoded, '/'));
            $anchor_regex = str_replace('&', '(?:&|&amp;|&#038;)', $anchor_regex);

            if (!preg_match('/<a[^>]*>[^<]*' . $anchor_regex . '/i', $paragraph)) {
                $attr = ' class="sil-link"';
                if ($link_id) {
                    $attr .= ' data-sil-id="' . intval($link_id) . '"';
                }
                $result = preg_replace('/(' . $anchor_regex . ')(?![^<]*<\/a>)/i', '<a href="' . esc_url($target_url) . '"' . $attr . '>$1</a>', $paragraph, 1);
                if ($result !== $paragraph && strpos($result, $target_url) !== false)
                    return $result;
            }
        }

        // Si remplacement simple échoue ou si on veut une insertion plus fluide via IA
        $existing_links = [];
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>.*?<\/a>/is', $paragraph, $matches);
        foreach ($matches[0] as $link)
            $existing_links[] = $link;

        $existing_info = !empty($existing_links) ? "\n\n⚠️ LIENS EXISTANTS À CONSERVER:\n" . implode("\n", $existing_links) : "";
        $model = $this->get_openai_model();

        // Prompt renforcé pour éviter le Markdown
        $prompt = "Tu es un expert SEO et rédacteur web. Tâche : Insérer un lien interne HTML dans le paragraphe ci-dessous.
PARAGRAPHE : \"{$text}\"
INSTRUCTION : Insère un lien vers \"{$target_url}\" avec l'ancre approchante ou exacte \"{$anchor_decoded}\" (Sujet cible : \"{$target_title}\").
RÈGLES STRICTES :
1. Renvoie le paragraphe complet formaté en HTML (<p>...</p>).
2. Utilise UNIQUEMENT la balise HTML <a> pour le lien. NE JAMAIS utiliser de Markdown [ancre](url).
3. Le lien doit être naturel et fluide. Adapte légèrement la phrase si nécessaire, mais garde le sens original.
4. Conserve strictement les liens existants listés ci-après.
{$existing_info}
5. Si l'insertion est impossible sans dénaturer le texte, réponds juste : IMPOSSIBLE
6. Ajoute systématiquement la classe sil-link et l'attribut data-sil-id=\"{$link_id}\" à la balise <a>.";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 45,
            'headers' => ['Authorization' => 'Bearer ' . $this->api_key, 'Content-Type' => 'application/json'],
            'body' => json_encode(['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => 800, 'temperature' => 0.4])
        ]);

        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['choices'][0]['message']['content'])) {
                $new_text = trim($body['choices'][0]['message']['content']);
                $new_text = preg_replace('/^["\']+|["\']+$/u', '', $new_text);
                $new_text = preg_replace('/```html\s*|\s*```/', '', $new_text); // Nettoyage blocs code

                // FALLBACK : Conversion Markdown -> HTML si l'IA s'est trompée
                $new_text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) use ($link_id) {
                    $attr = ' class="sil-link"';
                    if ($link_id) {
                        $attr .= ' data-sil-id="' . intval($link_id) . '"';
                    }
                    return '<a href="' . esc_url($m[2]) . '"' . $attr . '>' . esc_html($m[1]) . '</a>';
                }, $new_text);

                if ($new_text !== 'IMPOSSIBLE' && strpos($new_text, $target_url) !== false) {
                    // S'assurer qu'il y a des balises p
                    if (strpos($new_text, '<p>') === false) {
                        $new_text = '<p>' . $new_text . '</p>';
                    }
                    return $new_text;
                }
            }
        }

        // Fallback ultime : ajout à la fin
        $attr = ' class="sil-link"';
        if ($link_id) {
            $attr .= ' data-sil-id="' . intval($link_id) . '"';
        }
        $link = '<a href="' . esc_url($target_url) . '"' . $attr . '>' . esc_html($anchor) . '</a>';
        if (preg_match('/([^.!?]+)([.!?])\s*<\/(p|div)>\s*$/i', $paragraph, $matches)) {
            return preg_replace('/([.!?])\s*<\/(p|div)>\s*$/i', ", voir aussi " . $link . $matches[2] . '</$1>', $paragraph);
        }
        return preg_replace('/<\/(p|div)>\s*$/i', ' — voir aussi ' . $link . '</$1>', $paragraph);
    }

    public function link_already_exists($content, $target_url)
    {
        // Avoid false positives (e.g. target "/post-1" matching inside "/post-10")
        // Check for common href patterns
        $patterns = [
            'href="' . preg_quote($target_url, '/') . '"',
            'href=\'' . preg_quote($target_url, '/') . '\'',
            'href="' . preg_quote($target_url, '/') . '/', // Trailing slash variation
        ];

        foreach ($patterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $content)) {
                return true;
            }
        }
        return false;
    }

    public function insert_internal_links($post_id, $dry_run = false, $force_category = false)
    {
        $post = get_post($post_id);
        if (!$post)
            return ['success' => false, 'message' => 'Contenu non trouvé'];

        $content = $post->post_content;

        $start_find = microtime(true);
        // Find candidates that can link TO $post_id
        $similar = $this->find_similar_posts($post_id, 5, $force_category);
        error_log("SIL PROFILE: find_similar_posts took " . round(microtime(true) - $start_find, 2) . "s");

        if (empty($similar)) {
            if ($dry_run)
                update_post_meta($post_id, '_sil_no_match', time());
            return ['success' => false, 'message' => 'Aucune correspondance thématique ou mot-clé GSC introuvable', 'no_match' => true];
        }

        $links_added = [];
        $max_links = intval(get_option('sil_max_links', 3));

        foreach ($similar as $item) {
            if (count($links_added) >= $max_links)
                break;

            $candidate_post = get_post($item['post_id']);
            if (!$candidate_post)
                continue;

            $candidate_content = $candidate_post->post_content;
            $target_url = get_permalink($post_id); // The URL of the post we want links TO

            // Check if the candidate ALREADY links to the target post
            if ($this->link_already_exists($candidate_content, $target_url))
                continue;

            // Extract the native paragraph found by the fuzzy search
            $candidate_paragraphs = $this->get_available_paragraphs($candidate_content);
            $para_idx = $item['paragraph_index'];
            $anchor = $item['anchor'];

            $paragraph = null;
            foreach ($candidate_paragraphs as $p) {
                if ($p['index'] == $para_idx) {
                    $paragraph = $p;
                    break;
                }
            }
            if (!$paragraph)
                continue;

            // We bypass OpenAI entirely. 
            // We use the exact native anchor found in the sentence.
            $links_added[] = [
                'target_id' => $item['post_id'], // This is the ID of the post that WILL BE MODIFIED
                'target_title' => $item['title'], // Title of the post that WILL BE MODIFIED
                'target_url' => $target_url, // URL of the post we are linking TO
                'anchor' => $anchor,
                'similarity' => round($item['similarity'], 3),
                'paragraph_index' => $para_idx,
                'is_cornerstone' => $item['is_cornerstone']
            ];
        }

        if (empty($links_added)) {
            if ($dry_run)
                update_post_meta($post_id, '_sil_no_match', time());
            return ['success' => false, 'message' => 'Aucune ancrage valide trouvé dans les pages candidates', 'no_match' => true];
        }

        delete_post_meta($post_id, '_sil_no_match');

        if (!$dry_run) {
            foreach ($links_added as $link) {
                // Modification is APPLIED to the candidate post (target_id)
                $candidate_post = get_post($link['target_id']);
                if (!$candidate_post)
                    continue;

                $candidate_content = $candidate_post->post_content;
                $candidate_paragraphs = $this->get_available_paragraphs($candidate_content);

                $paragraph = null;
                foreach ($candidate_paragraphs as $p) {
                    if ($p['index'] == $link['paragraph_index']) {
                        $paragraph = $p;
                        break;
                    }
                }
                if (!$paragraph)
                    continue;

                $old = $paragraph['content'];
                $target_excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 30);

                // Replace anchor inside the candidate's exact existing native paragraph
                $new = $this->insert_link_in_paragraph($old, $link['anchor'], $link['target_url'], $post->post_title, $target_excerpt);

                // Log du lien dans la table sil_links
                global $wpdb;
                $wpdb->insert(
                    $wpdb->prefix . 'sil_links',
                    [
                        'source_id' => $link['target_id'],
                        'target_id' => $post_id,
                        'target_url' => $link['target_url'],
                        'anchor' => $link['anchor']
                    ],
                    ['%d', '%d', '%s', '%s']
                );
                $link_id = $wpdb->insert_id;

                // Replace anchor inside the candidate's exact existing native paragraph
                $new = $this->insert_link_in_paragraph($old, $link['anchor'], $link['target_url'], $post->post_title, $target_excerpt, $link_id);

                if ($new !== $old && strpos($new, $link['target_url']) !== false) {
                    $candidate_content = str_replace($old, $new, $candidate_content);
                    wp_update_post(['ID' => $link['target_id'], 'post_content' => $candidate_content]);
                }
            }
        }

        return ['success' => true, 'links' => $links_added, 'dry_run' => $dry_run];
    }

    // ==============================================
    // AJAX HANDLERS
    // ==============================================

    public function ajax_generate_links()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts'))
            wp_send_json_error('Permission refusée');
        $post_id = intval($_POST['post_id']);
        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] === 'true';
        wp_send_json($this->insert_internal_links($post_id, $dry_run));
    }

    public function ajax_fix_silo_leak()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts'))
            wp_send_json_error('Permission refusée');
        $post_id = intval($_POST['post_id']);
        wp_send_json($this->insert_internal_links($post_id, true, true)); // dry_run = true, force_category = true
    }


    public function ajax_regenerate_embeddings()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Permission refusée');

        if (isset($_POST['post_id'])) {
            $result = $this->generate_embedding(intval($_POST['post_id']));
            wp_send_json(['success' => $result !== false]);
            return;
        }

        global $wpdb;

        // --- CACHE CLEANUP ---
        // Force clear "No Paragraphs" cache globally when starting a full re-index
        // This fixes cases where posts were incorrectly cached as "no paragraphs" due to bugs.
        $wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = '_sil_no_paragraphs'");

        $posts = get_posts(['post_type' => $this->post_types, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids']);

        // Retirer les posts noindex de l'indexation globale
        if (get_option('sil_exclude_noindex') === '1') {
            $posts = array_filter($posts, function ($pid) {
                return !$this->is_noindexed($pid);
            });
        }

        $indexed = $wpdb->get_col("SELECT post_id FROM {$this->table_name}");
        $to_index = array_diff($posts, $indexed);

        wp_send_json([
            'success' => true,
            'total' => count($posts),
            'indexed' => count($indexed),
            'to_index' => array_values($to_index)
        ]);
    }

    public function ajax_get_stats()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts'))
            wp_send_json_error('Permission refusée');

        global $wpdb;
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status = 'publish'");
        $indexed = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $total_links = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sil_links");
        $broken_links = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sil_links WHERE status = 'broken'");

        wp_send_json_success([
            'total' => intval($total),
            'indexed' => intval($indexed),
            'to_index' => intval($total) - intval($indexed),
            'total_links' => $total_links ?: 0,
            'broken_links' => $broken_links ?: 0
        ]);
    }

    /**
     * Vérificateur de santé des liens (Scanner)
     */
    public function sil_check_links_health()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sil_links';

        // On vérifie les 20 liens les plus anciens ou jamais vérifiés
        $links = $wpdb->get_results("SELECT id, target_url FROM $table_name ORDER BY last_checked ASC LIMIT 20");

        if (empty($links))
            return 0;

        $broken_count = 0;
        foreach ($links as $link) {
            $response = wp_remote_head($link->target_url, ['timeout' => 5]);
            $status = 'valid';

            if (is_wp_error($response)) {
                // Skip network errors
            } else {
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 404) {
                    $status = 'broken';
                    $broken_count++;
                }
            }

            $wpdb->update(
                $table_name,
                ['status' => $status, 'last_checked' => current_time('mysql')],
                ['id' => $link->id],
                ['%s', '%s'],
                ['%d']
            );
        }

        return $broken_count;
    }

    public function ajax_check_links_health()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts'))
            wp_send_json_error('Permission refusée');

        $broken = $this->sil_check_links_health();
        wp_send_json_success(['broken_found' => $broken]);
    }


    public function ajax_refresh_index_status()
    {
        check_ajax_referer('sil_cornerstone_save', 'security');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Accès refusé');
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if ($post_id) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-sil-gsc-sync.php';
            $gsc_sync = new Sil_Gsc_Sync();

            if (!$gsc_sync->is_configured()) {
                wp_send_json_error('GSC n\'est pas configuré');
                return;
            }

            $gsc_sync->check_indexing_status([$post_id]);

            $new_status = get_post_meta($post_id, '_sil_gsc_index_status', true);
            if (empty($new_status)) {
                $new_status = 'Inconnu';
            }

            wp_send_json_success(['status' => $new_status]);
        }

        wp_send_json_error('Post ID manquant');
    }

    public function ajax_reset_no_match()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        if (!current_user_can('edit_posts'))
            wp_send_json_error('Permission refusée');
        $post_id = intval($_POST['post_id']);
        if ($post_id) {
            delete_post_meta($post_id, '_sil_no_match');
            delete_post_meta($post_id, '_sil_no_paragraphs');
            wp_send_json(['success' => true]);
        }
        wp_send_json(['success' => false]);
    }


    /**
     * Cœur du calcul et de la mise en cache des données du graphe
     */
    public function get_rendered_graph_data($force = false)
    {

        // Tenter de récupérer le Transient s'il n'y a pas de forçage
        if (!$force) {
            $cached = get_transient('sil_graph_cache');
            if (is_array($cached) && isset($cached['nodes']) && isset($cached['edges'])) {

                return [
                    'cached' => true,
                    'nodes' => $cached['nodes'],
                    'edges' => $cached['edges']
                ];
            }
        } else {
            $this->clear_graph_cache();
        }

        $site_host = parse_url(home_url(), PHP_URL_HOST);
        global $wpdb;
        $posts = get_posts([
            'post_type' => $this->post_types,
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        // Charger les données GSC pour tous ces posts
        $gsc_table = $wpdb->prefix . 'sil_gsc_data';
        $gsc_results = $wpdb->get_results("SELECT post_id, clicks, impressions, position, clicks_delta, clicks_delta_percent, yield_delta_percent, impressions_delta, position_delta, top_queries FROM $gsc_table", OBJECT_K);


        $nodes = [];
        $edges = [];

        // Préparation d'une map pour les mots-clés GSC afin de calculer les intersections
        $queries_map = [];
        foreach ($gsc_results as $pid => $data) {
            $q = json_decode($data->top_queries, true) ?: [];
            $queries_map[$pid] = array_map('strtolower', array_column($q, 'query'));
        }

        // --- NOUVEAU : Centralité calculée dynamiquement plus tard par Infomap ---
        $degrees = [];
        // -------------------------------------------------------

        foreach ($posts as $post) {
            if (!$post instanceof WP_Post)
                continue;
            $post_id = $post->ID;

            // Détection du silo (catégorie)
            $categories = get_the_category($post_id);
            $silo = !empty($categories) ? $categories[0]->name : 'Non classé';
            $silo_id = !empty($categories) ? $categories[0]->term_id : 0;

            // Détection du score GSC si disponible
            $gsc_clicks = 0;
            $gsc_position = null;
            $gsc_queries = [];
            $gsc_clicks_delta = 0;
            $gsc_impressions_delta = 0;
            $gsc_position_delta = 0;
            $gsc_yield_delta_percent = 0;
            $gsc_impressions = 0;

            if (isset($gsc_results[$post_id])) {
                $gsc_clicks = intval($gsc_results[$post_id]->clicks);
                $gsc_impressions = intval($gsc_results[$post_id]->impressions);
                $gsc_position = floatval($gsc_results[$post_id]->position);
                $gsc_clicks_delta = intval($gsc_results[$post_id]->clicks_delta);
                $gsc_clicks_delta_percent = floatval($gsc_results[$post_id]->clicks_delta_percent);
                $gsc_yield_delta_percent = floatval($gsc_results[$post_id]->yield_delta_percent);
                $gsc_impressions_delta = intval($gsc_results[$post_id]->impressions_delta);
                $gsc_position_delta = floatval($gsc_results[$post_id]->position_delta);
                $gsc_queries = json_decode($gsc_results[$post_id]->top_queries, true) ?: [];
            } else {
                $gsc_clicks_delta_percent = 0;
            }

            $p_type = get_post_type($post_id);
            $cat_name = 'Non classé';
            if ($p_type === 'page') {
                $cat_name = 'Page';
            } else {
                if (!empty($categories)) {
                    $cat_name = $categories[0]->name;
                }
            }

            $is_cornerstone = get_post_meta($post_id, '_sil_is_cornerstone', true) === '1';
            $cat_name = html_entity_decode($cat_name, ENT_QUOTES | ENT_XML1, 'UTF-8');

            // --- Calcul du Content Decay Critical ---
            $is_decay_critical = false;
            $pm_raw = isset($post->post_modified) ? $post->post_modified : $post->post_date;
            $post_modified_time = function_exists('get_post_modified_time') ? (int) get_post_modified_time('U', false, $post->ID) : strtotime($pm_raw);
            $post_time = max(strtotime($post->post_date), $post_modified_time);
            $six_months_ago = strtotime('-6 months');

            if ($post_time < $six_months_ago && $gsc_yield_delta_percent <= -15 && $gsc_position_delta <= -2) {
                $is_decay_critical = true;
            }

            $saved_checklist = get_post_meta($post_id, '_sil_checklist', true);
            if (!is_array($saved_checklist)) {
                $saved_checklist = [];
            }

            // Ces tags seront calculés avec précision par get_graph_data($nodes, $edges) plus loin.
            $in_degree = 0;
            $out_degree = 0;
            $tags = [];

            // --- Construction du noeud ---
            $nodes[] = [
                'id' => $post_id,
                'label' => wp_specialchars_decode(get_the_title($post_id), ENT_QUOTES),
                'title' => wp_specialchars_decode(get_the_title($post_id), ENT_QUOTES),
                'group' => $cat_name,
                'value' => $is_cornerstone ? 15 : 5,
                'is_cornerstone' => $is_cornerstone,
                'gsc_clicks' => $gsc_clicks,
                'gsc_impressions' => $gsc_impressions,
                'gsc_position' => $gsc_position,
                'gsc_clicks_delta' => $gsc_clicks_delta,
                'gsc_clicks_delta_percent' => $gsc_clicks_delta_percent,
                'gsc_yield_delta_percent' => $gsc_yield_delta_percent,
                'gsc_impressions_delta' => $gsc_impressions_delta,
                'gsc_position_delta' => $gsc_position_delta,
                'is_decay_critical' => $is_decay_critical,
                'category_name' => $cat_name,
                'category_id' => $silo_id,
                'sil_checklist' => $saved_checklist,
                'in_degree' => $in_degree,
                'out_degree' => $out_degree,
                'tags' => $tags
            ];

            // Striking distance est maintenant géré dynamiquement par le frontend si besoin,
            // ou bien on pourrait le recalculer dans get_graph_data($nodes, $edges) si utilisé par le Sidebar.
            // Actuellement il n'était pas utilisé rigoureusement dans cette vue.


            // --- Construction des arêtes (Liens sortants) ---
            preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches);
            $targets = [];

            foreach ($matches[1] as $url) {
                if (empty($url) || preg_match('/^(#|mailto:|tel:|javascript:)/i', $url))
                    continue;

                $url_host = parse_url($url, PHP_URL_HOST);
                if (empty($url_host) || $url_host === $site_host) {
                    $pid = url_to_postid($url);

                    // --- Fallback robuste par slug si url_to_postid échoue ---
                    if (!$pid) {
                        $path = parse_url($url, PHP_URL_PATH);
                        if ($path) {
                            $slug = basename(trim($path, '/'));
                            global $wpdb;
                            $pid = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' LIMIT 1", $slug));
                        }
                    }
                    // ----------------------------------------------------------
                    if ($pid && $pid !== $post_id) {
                        $targets[] = $pid;
                    }
                }
            }
            $targets = array_unique($targets);

            foreach ($targets as $tid) {
                // --- NOUVEAU : Calcul du Poids Infomap ---
                $clicks = 0;
                $impressions = 0;

                // On privilégie les données déjà chargées dans $gsc_results pour la performance
                if (isset($gsc_results[$tid])) {
                    $clicks = intval($gsc_results[$tid]->clicks);
                    $impressions = intval($gsc_results[$tid]->impressions);
                }

                // Application de la formule : (Clicks * 2) + Impressions + 1
                $weight = ($clicks * 2) + $impressions + 1;
                // -----------------------------------------

                $edges[] = [
                    'data' => [
                        'source' => (string) $post_id,
                        'target' => (string) $tid,
                        'weight' => $weight
                    ]
                ];
            }
        }


        // Marquer le Top 15 stratégique par impressions
        usort($nodes, function ($a, $b) {
            return $b['gsc_impressions'] <=> $a['gsc_impressions'];
        });
        for ($i = 0; $i < min(15, count($nodes)); $i++) {
            $nodes[$i]['is_strategic'] = true;
        }

        // --- NOUVEAU : Appel API Infomap Externe ---
        // et calcul des Out-degree et In-degree globaux (DB + HTML)
        $cluster_analysis = new \Sil_Cluster_Analysis();
        $enriched_data = $cluster_analysis->get_graph_data();

        $nodes = $enriched_data['nodes'];
        $edges = $enriched_data['edges'];
        $api_error = isset($enriched_data['api_error']) ? $enriched_data['api_error'] : '';
        $api_debug = isset($enriched_data['api_debug']) ? $enriched_data['api_debug'] : '';

        // --- BMAD 1 : INTELLIGENCE DE CENTRALITÉ & PIVOTS ---
        $centrality_engine = new SIL_Centrality_Engine();
        $cluster_embeddings = [];
        
        // 1. Grouper les embeddings par cluster
        foreach ($nodes as $node) {
            if (isset($node['data']['id']) && isset($node['data']['cluster_id'])) {
                $pid = $node['data']['id'];
                $cid = $node['data']['cluster_id'];
                $emb_raw = get_post_meta($pid, '_sil_embedding', true);
                $emb = is_array($emb_raw) ? $emb_raw : json_decode($emb_raw, true);
                if (!empty($emb)) {
                    $cluster_embeddings[$cid][] = $emb;
                }
            }
        }

        // 2. Calculer les barycentres par cluster
        $barycenters = [];
        foreach ($cluster_embeddings as $cid => $embs) {
            $barycenters[$cid] = SIL_Centrality_Engine::calculate_barycenter($embs);
        }

        // 3. Calculer les scores finaux et identifier les pivots
        $pivots = [];
        $best_scores = [];

        foreach ($nodes as &$node_ref) {
            if (!isset($node_ref['data']['cluster_id'])) continue;
            
            $pid = $node_ref['data']['id'];
            $cid = $node_ref['data']['cluster_id'];
            $in_degree = $node_ref['data']['in_degree'] ?? 0;
            $impressions = $node_ref['data']['gsc_impressions'] ?? 0;
            
            // Calcul de la position moyenne pour le power score
            $avg_pos = 0;
            if (isset($gsc_results[$pid])) {
                $avg_pos = floatval($gsc_results[$pid]->position);
            }

            // Récupération de l'embedding
            $emb_raw = get_post_meta($pid, '_sil_embedding', true);
            $emb = is_array($emb_raw) ? $emb_raw : json_decode($emb_raw, true);
            $barycenter = $barycenters[$cid] ?? null;

            // Calcul des composantes
            $semantic_score = SIL_Centrality_Engine::get_representativeness_score($emb, $barycenter);
            $gsc_power_score = SIL_Centrality_Engine::get_gsc_power_score($impressions, $avg_pos);
            $connectivity_score = min(1.0, $in_degree / 20.0);

            // Score Final (50/30/20)
            $final_score = SIL_Centrality_Engine::compute_final_score($semantic_score, $gsc_power_score, $connectivity_score);
            
            // Injection
            $node_ref['data']['sil_pagerank'] = $final_score;
            $node_ref['data']['is_pivot'] = false;

            // Tracking du meilleur score pour marquage pivot
            if (!isset($best_scores[$cid]) || $final_score > $best_scores[$cid]) {
                $best_scores[$cid] = $final_score;
                $pivots[$cid] = $pid;
            }
        }
        unset($node_ref);

        // 4. Marquage final des pivots
        foreach ($nodes as &$node_ref) {
            if (isset($node_ref['data']['cluster_id'])) {
                $cid = $node_ref['data']['cluster_id'];
                if (isset($pivots[$cid]) && $node_ref['data']['id'] === $pivots[$cid]) {
                    $node_ref['data']['is_pivot'] = true;
                }
            }
        }
        unset($node_ref);
        // -------------------------------------------------------

        // Sauvegarde dans un transient WordPress pour 12 heures (UNIQUEMENT si pas d'erreur API)
        if (empty($api_error)) {
            $cache_data = [
                'nodes' => array_values($nodes),
                'edges' => array_values($edges)
            ];
            set_transient('sil_graph_cache', $cache_data, 12 * HOUR_IN_SECONDS);
        }

        return [
            'cached' => false,
            'nodes' => array_values($nodes),
            'edges' => array_values($edges)
        ];
    }


    public function ajax_save_checklist_item()
    {
        check_ajax_referer('sil_nonce', 'nonce'); // Changed from sil_ajax_nonce to sil_nonce to match existing
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Accès refusé');
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $check_id = isset($_POST['check_id']) ? sanitize_text_field($_POST['check_id']) : '';
        $is_checked = isset($_POST['is_checked']) ? intval($_POST['is_checked']) : 0;

        if (!$post_id || !$check_id) {
            wp_send_json_error('Données invalides');
        }

        $saved_checklist = get_post_meta($post_id, '_sil_checklist', true);
        if (!is_array($saved_checklist)) {
            $saved_checklist = [];
        }

        if ($is_checked) {
            if (!in_array($check_id, $saved_checklist)) {
                $saved_checklist[] = $check_id;
            }
        } else {
            $saved_checklist = array_diff($saved_checklist, [$check_id]);
        }

        update_post_meta($post_id, '_sil_checklist', array_values($saved_checklist));

        wp_send_json_success($saved_checklist);
    }



    /**
     * Rendu de la page Cartographie Interactive (Phase 2)
     */
    public function render_cartographie_page()
    {
        global $wpdb;
        $unique_clusters = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sil_cluster_id'");
        if (empty($unique_clusters)) { $unique_clusters = range(1, 5); }
        ?>
        <div class="wrap">
            <div class="sil-header">
                <h1>🗺️ Cartographie Interactive du Maillage</h1>
                <div class="sil-graph-toolbar" style="flex-wrap: wrap;">
                    <div style="flex: 1 1 100%; display: flex; gap: 15px; align-items: center; margin-bottom: 12px;">
                        <select id="sil-silo-filter">
                            <option value="all">Tous les cocons (Vue globale)</option>
                            <?php foreach ($unique_clusters as $cluster_id): ?>
                                <option value="<?php echo esc_attr($cluster_id); ?>">Silo <?php echo esc_html($cluster_id); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" id="sil-node-search" list="sil-node-list" placeholder="🔍 Rechercher un article..." style="width: 250px;">
                        <datalist id="sil-node-list"></datalist>
                    </div>
                    <div style="flex: 1 1 100%; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button id="sil-refresh-graph" class="button">🔄 Actualiser</button>
                        <button id="sil-center-graph" class="button">🎯 Centrer</button>
                        <button id="sil-zoom-in" class="button">➕</button>
                        <button id="sil-zoom-out" class="button">➖</button>
                        <button id="sil-export-png" class="button sil-btn-secondary">📸 Export PNG</button>
                        <button id="sil-export-json" class="button sil-btn-secondary">📦 Export JSON (Audit AI)</button>
                        <span class="sil-badge-count" style="margin-left:auto;">Nœuds: <span id="sil-node-count">0</span></span>
                    </div>
                </div>
            </div>

            <?php
            $health_score = $this->calculate_health_score();
            $health_color = '#d63638';
            if ($health_score > 40) $health_color = '#dba617';
            if ($health_score > 75) $health_color = '#198754';
            ?>
            <div class="sil-health-score-container" style="background:#fff; padding:20px; border-radius:8px; margin-bottom:20px; border:1px solid #ccd0d4; display:flex; align-items:center; gap:20px;">
                <div style="flex:1;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-weight:600;">
                        <span>Score de Santé du Maillage</span>
                        <span style="color:<?php echo $health_color; ?>;"><?php echo $health_score; ?> / 100</span>
                    </div>
                    <div style="background:#f0f0f1; height:12px; border-radius:6px; overflow:hidden; border:1px solid #dcdcde;">
                        <div style="width:<?php echo $health_score; ?>%; background:<?php echo $health_color; ?>; height:100%; transition:width 0.5s ease-in-out;"></div>
                    </div>
                </div>
                <div style="max-width:300px; font-size:13px; color:#515962; border-left:1px solid #f0f0f1; padding-left:20px;">
                    <strong>Indicateur de santé :</strong> Ce score prend en compte la couverture de votre maillage (60%) et la densité des liens créés (40%).
                </div>
            </div>

            <div id="sil-graph-outer" style="position:relative; display:flex; gap:0;">

                <div id="sil-graph-container" style="flex-grow:1; min-height:700px; transition:width 0.3s ease; position:relative;">
                    <div id="sil-graph-loading" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center; z-index:1000; background:rgba(255,255,255,0.95); padding:40px; border-radius:15px; box-shadow:0 20px 50px rgba(0,0,0,0.15); width:450px;">
                        <div class="spinner is-active" style="float:none; margin:0 auto 20px auto; width:40px; height:40px;"></div>
                        <h3 id="sil-step-label" style="margin:10px 0; color:#1e293b; font-size:18px; font-weight:600;">Initialisation...</h3>
                        <div style="background:#e2e8f0; height:10px; width:100%; border-radius:4px; overflow:hidden; margin:20px 0; border:1px solid #cbd5e1;">
                            <div id="sil-progress-bar" style="background:linear-gradient(90deg,#3b82f6,#2563eb); height:100%; width:10%; transition:width 0.6s cubic-bezier(0.4,0,0.2,1);"></div>
                        </div>
                        <p id="sil-step-detail" style="font-size:13px; color:#64748b; margin:0; line-height:1.4;">Préparation des données du site...</p>
                    </div>
                </div>

                <div id="sil-graph-sidebar" class="sil-graph-sidebar" style="display:none;">
                    <div class="sidebar-header">
                        <h3>🔍 Diagnostic</h3>
                        <button id="sil-close-sidebar" class="sil-close-btn">&times;</button>
                    </div>
                    <div id="sil-sidebar-content" class="sidebar-body"></div>
                </div>

                <div id="sil-graph-tooltip" class="sil-graph-tooltip"></div>
            </div>

            <div class="sil-legend-bar" style="margin-top:15px; display:flex; gap:20px; font-size:13px; align-items:center;">
                <div style="display:flex; align-items:center; gap:5px;"><span style="width:12px; height:12px; border:3px solid #ffd700; border-radius:50%; display:inline-block;"></span> Top 15 Stratégique</div>
                <div style="display:flex; align-items:center; gap:5px;"><span style="width:12px; height:12px; border:3px solid #d63638; border-radius:50%; display:inline-block;"></span> Content Decay Critique</div>
                <div style="display:flex; align-items:center; gap:5px;"><span style="width:12px; height:12px; background:rgba(34,113,177,0.4); border-radius:3px; display:inline-block;"></span> Silo Halo (Cluster sémantique)</div>
                <div style="font-style:italic; color:#666; margin-left:auto;">💡 Double-cliquez sur un lien pour le supprimer réellement de l'article.</div>
            </div>
        </div>

        <style>
            .sil-graph-tooltip h4 { margin:0 0 8px 0; padding-bottom:5px; color:#2271b1; }
            .sil-graph-tooltip ul { margin:0; padding:0; list-style:none; font-size:12px; }
            .sil-graph-tooltip li { margin-bottom:4px; }
            .strategic-badge { color:#856404; background:#fff3cd; padding:2px 5px; border-radius:3px; font-weight:bold; }
            .decay-alert { color:#721c24; background:#f8d7da; padding:2px 5px; border-radius:3px; font-weight:bold; }
            .sil-badge-count { background:#2271b1; color:#fff; padding:2px 8px; border-radius:12px; font-size:12px; margin-left:10px; }
            #sil-graph-outer { position:relative; display:flex; gap:0; }
            #sil-graph-container { flex-grow:1; transition:width 0.3s ease; }
            .sil-graph-sidebar { width:350px; max-height:700px; background:#fff; border:1px solid #ccd0d4; border-left:none; border-radius:0 4px 4px 0; display:flex; flex-direction:column; box-shadow:-2px 0 5px rgba(0,0,0,0.05); z-index:90; overflow:hidden; }
            .sidebar-header { padding:15px; background:#f6f7f7; border-bottom:1px solid #ccd0d4; display:flex; justify-content:space-between; align-items:center; }
            .sidebar-header h3 { margin:0; font-size:16px; color:#1d2327; }
            .sil-close-btn { background:none; border:none; font-size:24px; cursor:pointer; color:#646970; }
            .sidebar-body { padding:15px; overflow-y:auto; flex-grow:1; }
            .polluting-link-item { background:#fff5f5; border:1px solid #fecaca; padding:10px; border-radius:4px; margin-bottom:10px; }
            .polluting-link-item h5 { margin:0 0 5px 0; font-size:13px; color:#b91c1c; }
            .polluting-actions { margin-top:8px; display:flex; justify-content:flex-end; }
            .sil-btn-break { background:#d63638; color:#fff; border:none; padding:5px 10px; border-radius:3px; cursor:pointer; font-size:12px; }
            .sil-btn-break:hover { background:#b32d2e; }
        </style>
        <?php
    }


    // ==============================================
    // PAGES ADMIN
    // ==============================================

    public function render_admin_page()
    {
        global $wpdb;

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status = 'publish'");
        $indexed = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        $per_page = 50;
        $current = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total_pages = ceil($total / $per_page);
        $offset = ($current - 1) * $per_page;
        ?>
                <div class="wrap">
                    <div class="sil-header">
                        <h1>🔗 Smart Internal Links (Articles & Pages)</h1>
                        <div class="sil-header-actions">
                            <button type="button" id="sil-force-gsc-sync-main" class="button button-primary sil-btn-sm"
                                style="margin-right:8px;" title="Recalcul des métriques Content Decay depuis GSC.">🤖 Actualiser les
                                métriques GSC</button>
                            <button id="sil-refresh-stats" class="sil-btn sil-btn-secondary sil-btn-sm">🔄</button>
                            <a href="<?php echo admin_url('admin.php?page=sil-settings'); ?>"
                                class="sil-btn sil-btn-secondary sil-btn-sm">⚙️ Réglages</a>
                        </div>
                    </div>

                    <!-- TAB: LISTE -->
                    <div id="sil-list-content">
                        <div class="sil-stats-grid">
                            <div class="sil-stat-card primary">
                                <div class="stat-label">Contenus publiés</div>
                                <div class="stat-value" id="stat-total"><?php echo intval($total); ?></div>
                            </div>
                            <div class="sil-stat-card success">
                                <div class="stat-label">Indexés</div>
                                <div class="stat-value" id="stat-indexed"><?php echo intval($indexed); ?></div>
                            </div>
                            <div class="sil-stat-card warning">
                                <div class="stat-label">À indexer</div>
                                <div class="stat-value" id="stat-to-index"><?php echo intval($total - $indexed); ?></div>
                            </div>
                            <div class="sil-stat-card danger" id="card-broken-links" style="border-left-color: #d63638;">
                                <div class="stat-label">Liens cassés</div>
                                <div class="stat-value" id="stat-broken-links">0</div>
                            </div>
                        </div>

                        <div class="sil-card">
                            <div class="sil-card-header">
                                <h2>📊 Indexation des embeddings</h2>
                                <button id="sil-regenerate" class="sil-btn sil-btn-primary sil-btn-sm">Indexer tout</button>
                            </div>
                            <div class="sil-card-body">
                                <p style="margin:0;color:#6b7280;">Générez les embeddings pour détecter la similarité entre vos articles
                                    et pages.</p>
                                <div id="sil-progress" class="sil-progress" style="display:none;">
                                    <span class="spinner is-active"></span>
                                    <span class="sil-progress-text">Indexation...</span>
                                </div>
                            </div>
                        </div>

                        <div class="sil-card">
                            <div class="sil-card-header">
                                <h2>📝 Gestion des liens internes</h2>
                            </div>
                            <div class="sil-card-body">
                                <div class="sil-filters">
                                    <button class="sil-filter-btn active" data-filter="all">Tous</button>
                                    <button class="sil-filter-btn" data-filter="none">Sans lien sortant</button>
                                    <button class="sil-filter-btn" data-filter="few">1-2 liens</button>
                                    <button class="sil-filter-btn" data-filter="good">3+ liens</button>
                                    <button class="sil-filter-btn" data-filter="no-match">⚠️ Sans correspondance</button>
                                    <button class="sil-filter-btn" data-filter="decay">📉 Content Decay</button>
                                    <span class="sil-filter-count"><?php echo intval($total); ?> contenu(s)</span>
                                </div>

                                <div class="sil-mass-actions">
                                    <button id="sil-preview-filtered" class="sil-btn sil-btn-secondary sil-btn-sm">👁️ Prévisualiser
                                        tous</button>
                                    <button id="sil-bulk-apply" class="sil-btn sil-btn-primary sil-btn-sm" disabled>Appliquer aux
                                        sélectionnés</button>
                                    <button id="sil-scan-links" class="sil-btn sil-btn-secondary sil-btn-sm"
                                        title="Vérifier la validité des 20 liens les plus anciens.">🔍 Scanner la santé des
                                        liens</button>
                                </div>

                                <table class="sil-table">
                                    <thead>
                                        <tr>
                                            <th class="check-column"><input type="checkbox" id="sil-select-all"></th>
                                            <th>Contenu</th>
                                            <th>Type</th>
                                            <th title="Liens internes sortants">🔗 Sortants</th>
                                            <th title="Liens internes entrants">📥 Reçus</th>
                                            <th>Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $posts = get_posts([
                                            'post_type' => $this->post_types,
                                            'post_status' => 'publish',
                                            'numberposts' => $per_page,
                                            'offset' => $offset,
                                            'orderby' => 'date',
                                            'order' => 'DESC'
                                        ]);

                                        // Pre-fetch decay data
                                        $post_ids = array_column($posts, 'ID');
                                        $decay_data = [];
                                        if (!empty($post_ids)) {
                                            $gsc_table = $wpdb->prefix . 'sil_gsc_data';
                                            $ids_placeholder = implode(',', array_fill(0, count($post_ids), '%d'));
                                            $decay_results = $wpdb->get_results($wpdb->prepare("SELECT post_id, yield_delta_percent, position_delta FROM {$gsc_table} WHERE post_id IN ($ids_placeholder)", ...$post_ids));
                                            foreach ($decay_results as $row) {
                                                // 📉 Content Decay si Yield chute de > 15% ET position chute de > 2 rangs
                                                if (floatval($row->position_delta) <= -2 && floatval($row->yield_delta_percent) <= -15) {
                                                    $decay_data[$row->post_id] = [
                                                        'position_delta' => floatval($row->position_delta),
                                                        'yield_delta_percent' => floatval($row->yield_delta_percent)
                                                    ];
                                                }
                                            }
                                        }

                                        if (is_array($posts)):
                                            foreach ($posts as $post):
                                                if (!$post instanceof WP_Post)
                                                    continue;
                                                $post_type_label = $post->post_type === 'page' ? 'Page' : 'Article';
                                                $is_cornerstone = get_post_meta($post->ID, '_sil_is_cornerstone', true) === '1';

                                                $outgoing = $this->count_internal_links($post->ID);
                                                $incoming = $this->count_backlinks($post->ID);
                                                $no_match = get_post_meta($post->ID, '_sil_no_match', true);

                                                $out_class = $outgoing === 0 ? 'sil-badge-danger' : ($outgoing < 3 ? 'sil-badge-warning' : 'sil-badge-success');
                                                $in_class = $incoming === 0 ? 'sil-badge-danger' : ($incoming < 3 ? 'sil-badge-warning' : 'sil-badge-success');

                                                $data_links = $outgoing === 0 ? 'none' : ($outgoing < 3 ? 'few' : 'good');

                                                // Vérification stricte: L'article doit avoir plus de 6 mois
                                                $pm_raw = isset($post->post_modified) ? $post->post_modified : $post->post_date;
                                                $post_modified_time = function_exists('get_post_modified_time') ? (int) get_post_modified_time('U', false, $post->ID) : strtotime($pm_raw);
                                                $post_time = max(strtotime($post->post_date), $post_modified_time);
                                                $six_months_ago = strtotime('-6 months');
                                                $is_decaying = isset($decay_data[$post->ID]) && ($post_time < $six_months_ago);
                                                ?>
                                                        <tr data-post-id="<?php echo $post->ID; ?>" data-links="<?php echo $data_links; ?>"
                                                            data-no-match="<?php echo $no_match ? 'true' : 'false'; ?>"
                                                            data-decay="<?php echo $is_decaying ? 'true' : 'false'; ?>">
                                                            <td class="check-column">
                                                                <input type="checkbox" class="sil-post-cb" value="<?php echo $post->ID; ?>">
                                                            </td>
                                                            <td>
                                                                <div class="sil-article-title">
                                                                    <?php echo esc_html($post->post_title); ?>
                                                                    <?php if ($is_cornerstone): ?>
                                                                            <span title="Contenu Pilier" style="cursor:help;">⭐</span>
                                                                    <?php endif; ?>
                                                                    <?php
                                                                    $index_status = get_post_meta($post->ID, '_sil_gsc_index_status', true);
                                                                    if (!empty($index_status)) {
                                                                        $lower_status = mb_strtolower($index_status, 'UTF-8');
                                                                        $is_indexed = (strpos($lower_status, 'indexée') !== false || strpos($lower_status, 'indexed') !== false)
                                                                            && strpos($lower_status, 'non index') === false
                                                                            && strpos($lower_status, 'not index') === false;

                                                                        if ($is_indexed) {
                                                                            echo '<span title="Indexée" style="cursor:help; margin-left:4px;">🟢</span>';
                                                                        } else {
                                                                            echo '<span title="Erreur : ' . esc_attr($index_status) . '" style="cursor:help; margin-left:4px;">🔴</span>';
                                                                        }
                                                                    }
                                                                    ?>
                                                                    <?php if ($no_match): ?><span
                                                                                title="Alerte : Impossible de trouver une de vos top requêtes GSC dans le texte. L'article se désynchronise de l'intention utilisateur."
                                                                                style="cursor:help;" class="sil-no-match-icon">⚠️</span><?php endif; ?>
                                                                    <?php if ($is_decaying): ?><span
                                                                                title="Content Decay : Perte de rendement par rapport à l'année dernière. Chute des clics et des positions."
                                                                                style="cursor:help;">📉</span><?php endif; ?>
                                                                    <?php
                                                                    $text = wp_strip_all_tags($post->post_content);
                                                                    $word_count = count(preg_split('~[^\p{L}\p{N}\']+~u', $text, -1, PREG_SPLIT_NO_EMPTY));
                                                                    ?>
                                                                    <span class="sil-word-count"
                                                                        style="font-size:11px; margin-left:8px; color:#94a3b8; font-weight:normal;"
                                                                        title="Mots calculés par le plugin">(<?php echo $word_count; ?> mots)</span>
                                                                </div>
                                                                <div class="sil-article-meta">
                                                                    <a href="<?php echo get_edit_post_link($post->ID); ?>" target="_blank">Modifier</a>
                                                                    ·
                                                                    <a href="<?php echo get_permalink($post->ID); ?>" target="_blank">Voir</a>
                                                                    <?php if ($no_match): ?>
                                                                            · <a href="#" class="sil-reset-no-match"
                                                                                data-post-id="<?php echo $post->ID; ?>">Réinitialiser</a>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                            <td><span class="sil-badge sil-badge-neutral"><?php echo $post_type_label; ?></span></td>
                                                            <td><span class="sil-badge <?php echo $out_class; ?>"><?php echo $outgoing; ?></span></td>
                                                            <td><span class="sil-badge <?php echo $in_class; ?>"><?php echo $incoming; ?></span></td>
                                                            <td><?php echo get_the_date('d/m/Y', $post->ID); ?></td>
                                                            <td>
                                                                <div class="sil-actions">
                                                                    <button class="sil-btn sil-btn-secondary sil-btn-sm sil-preview-btn"
                                                                        data-post-id="<?php echo $post->ID; ?>">Prévisualiser</button>
                                                                    <button class="sil-btn sil-btn-primary sil-btn-sm sil-apply-btn"
                                                                        data-post-id="<?php echo $post->ID; ?>">Appliquer</button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <tr class="sil-preview-row" data-post-id="<?php echo $post->ID; ?>" style="display:none;">
                                                            <td colspan="7">
                                                                <div class="sil-preview-content"></div>
                                                            </td>
                                                        </tr>
                                                <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($total_pages > 1): ?>
                                    <div class="sil-pagination">
                                        <span class="sil-pagination-info">Page <?php echo $current; ?> sur <?php echo $total_pages; ?></span>
                                        <?php if ($current > 1): ?>
                                                <a href="<?php echo esc_url(add_query_arg('paged', 1)); ?>">« Première</a>
                                                <a href="<?php echo esc_url(add_query_arg('paged', $current - 1)); ?>">‹ Précédente</a>
                                        <?php endif; ?>

                                        <?php for ($i = max(1, $current - 2); $i <= min($total_pages, $current + 2); $i++): ?>
                                                <?php if ($i === $current): ?>
                                                        <span class="current"><?php echo $i; ?></span>
                                                <?php else: ?>
                                                        <a href="<?php echo esc_url(add_query_arg('paged', $i)); ?>"><?php echo $i; ?></a>
                                                <?php endif; ?>
                                        <?php endfor; ?>

                                        <?php if ($current < $total_pages): ?>
                                                <a href="<?php echo esc_url(add_query_arg('paged', $current + 1)); ?>">Suivante ›</a>
                                                <a href="<?php echo esc_url(add_query_arg('paged', $total_pages)); ?>">Dernière »</a>
                                        <?php endif; ?>
                                    </div>
                            <?php endif; ?>
                        </div>

                        <div class="sil-credits">
                            Plugin créé par <a href="https://redactiwe.systeme.io/formation-redacteur-ia" target="_blank">Jennifer
                                Larcher</a>
                        </div>
                    </div>
                    <?php
    }



    public function render_settings_page()
    {
        ?>
        <div class="wrap sil-settings-wrap">
            <div class="sil-header" style="margin-bottom:20px;">
                <h1>⚙️ Réglages Smart Internal Links</h1>
                <p class="description">Configurez l'intelligence sémantique et la santé de votre maillage.</p>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:30px;">
                <!-- Bloc 1 : Indexation Sémantique -->
                <div class="sil-card" style="border-top: 4px solid #3b82f6;">
                    <div class="sil-card-header" style="padding:15px; border-bottom:1px solid #eee;">
                        <h2 style="margin:0; font-size:1.1em;">🧠 Indexation Sémantique</h2>
                    </div>
                    <div class="sil-card-body" style="padding:15px;">
                        <p class="description">Générez les vecteurs d'analyse pour chaque page de votre site (Embeddings OpenAI).</p>
                        
                        <div id="sil-indexing-progress-container" style="display:none; margin: 15px 0;">
                            <div style="background:#e2e8f0; border-radius:10px; height:8px; overflow:hidden;">
                                <div id="sil-indexing-bar" style="width:0%; height:100%; background:#3b82f6; transition:width 0.3s ease;"></div>
                            </div>
                            <div style="display:flex; justify-content:space-between; margin-top:5px; font-size:11px; color:#64748b;">
                                <span>Progression : <span id="sil-indexing-stats">0 / 0</span></span>
                                <span id="sil-indexing-status-text">Calcul en cours...</span>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; margin-top:15px;">
                            <button type="button" id="sil-start-indexing" class="button button-primary">🚀 Démarrer l'Indexation</button>
                            <button type="button" id="sil-run-semantic-audit" class="button button-secondary">🔍 Audit de Cohésion</button>
                            <span id="sil-audit-loader" style="display:none; vertical-align: middle; margin-left: 10px;"><span class="spinner is-active" style="float:none;"></span> Audit...</span>
                        </div>
                        <div id="sil-audit-feedback-settings" style="margin-top:15px; display:none;"></div>
                    </div>
                </div>

                <!-- Bloc 2 : Intégrité & Calculs -->
                <div class="sil-card" style="border-top: 4px solid #10b981;">
                    <div class="sil-card-header" style="padding:15px; border-bottom:1px solid #eee;">
                        <h2 style="margin:0; font-size:1.1em;">🛡️ Intégrité du Système (Unit Tests)</h2>
                    </div>
                    <div class="sil-card-body" style="padding:15px;">
                        <p class="description">Stress-test des algorithmes de maillage et de la logique de ROI en temps réel.</p>
                        
                        <div id="sil-test-results" style="margin: 15px 0; max-height: 80px; overflow-y:auto; background:#f8fafc; padding:10px; border-radius:4px; font-size:11px; font-family:monospace; border:1px solid #e2e8f0;">
                            <span style="color:#94a3b8;">Aucun test effectué.</span>
                        </div>

                        <div style="display:flex; gap:10px; margin-top:15px;">
                            <button type="button" id="sil-run-unit-tests" class="button button-secondary">🧪 Lancer le Stress-Test</button>
                            <button type="button" id="sil-run-diagnostic" class="button button-secondary">🩺 Santé Générale</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Diagnostic (masqué par défaut) -->
            <div id="sil-diagnostic-results" style="display:none; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:15px; margin-bottom:30px;"></div>

            <script>
            jQuery(document).ready(function($) {
                // Diagnostic Logic
                $('#sil-run-diagnostic').on('click', function() {
                    const $res = $('#sil-diagnostic-results');
                    $res.show().html('<div style="grid-column: 1 / -1; text-align:center;"><span class="spinner is-active"></span> Analyse systémique...</div>');
                    $.post(ajaxurl, { action: 'sil_run_system_diagnostic', nonce: '<?php echo wp_create_nonce("sil_nonce"); ?>' }, function(r) {
                        if(r.success) {
                            let html = '';
                            $.each(r.data, function(k, v) {
                                html += `<div style="background:#fff; padding:15px; border-radius:8px; border:1px solid #e2e8f0; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                                    <div style="font-size:24px;">${v.status}</div>
                                    <div style="font-weight:bold; margin:5px 0;">${v.label}</div>
                                    <div style="font-size:11px; color:#64748b;">${v.desc}</div>
                                </div>`;
                            });
                            $res.html(html);
                        }
                    });
                });
            });
            </script>

            <!-- Configuration Form -->
            <div class="sil-card">
                <div class="sil-card-header" style="padding:15px; border-bottom:1px solid #eee;">
                    <h2 style="margin:0; font-size:1.1em;">🛠️ Configuration de l'Intelligence Artificielle</h2>
                </div>
                <div class="sil-card-body" style="padding:15px;">
                    <form method="post" action="options.php">
                        <?php settings_fields('sil_settings'); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="sil_openai_api_key">Clé API OpenAI</label></th>
                                <td>
                                    <input type="password" id="sil_openai_api_key" name="sil_openai_api_key" value="<?php echo esc_attr(get_option('sil_openai_api_key')); ?>" class="regular-text">
                                    <p class="description">Indispensable pour calculer les embeddings et générer les ponts sémantiques.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="sil_openai_model">Modèle OpenAI</label></th>
                                <td>
                                    <select name="sil_openai_model" id="sil_openai_model" onchange="document.getElementById('sil_custom_model_row').style.display = this.value === 'custom' ? 'table-row' : 'none';">
                                        <option value="gpt-4o" <?php selected(get_option('sil_openai_model', 'gpt-4o'), 'gpt-4o'); ?>>GPT-4o (Recommandé - Qualité)</option>
                                        <option value="gpt-4o-mini" <?php selected(get_option('sil_openai_model'), 'gpt-4o-mini'); ?>>GPT-4o Mini (Plus rapide & moins cher)</option>
                                        <option value="gpt-3.5-turbo" <?php selected(get_option('sil_openai_model'), 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo (Ancien modèle)</option>
                                        <option value="custom" <?php selected(get_option('sil_openai_model'), 'custom'); ?>>Modèle personnalisé...</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="sil_custom_model_row" style="<?php echo get_option('sil_openai_model') === 'custom' ? '' : 'display:none;'; ?>">
                                <th><label for="sil_openai_custom_model">Modèle personnalisé</label></th>
                                <td>
                                    <input type="text" id="sil_openai_custom_model" name="sil_openai_custom_model" value="<?php echo esc_attr(get_option('sil_openai_custom_model')); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="sil_openai_seo_prompt">Prompt IA (Titre & Meta)</label></th>
                                <td>
                                    <textarea id="sil_openai_seo_prompt" name="sil_openai_seo_prompt" class="large-text" rows="4"><?php echo function_exists('esc_textarea') ? esc_textarea(get_option('sil_openai_seo_prompt')) : esc_html(get_option('sil_openai_seo_prompt')); ?></textarea>
                                    <p class="description">Prompt système utilisé pour la réécriture des Titles et Meta-Descriptions depuis le graphe.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="sil_openai_bridge_prompt">Prompt IA (Pont Sémantique)</label></th>
                                <td>
                                    <textarea id="sil_openai_bridge_prompt" name="sil_openai_bridge_prompt" class="large-text" rows="4"><?php echo function_exists('esc_textarea') ? esc_textarea(get_option('sil_openai_bridge_prompt')) : esc_html(get_option('sil_openai_bridge_prompt')); ?></textarea>
                                    <p class="description">Prompt système utilisé pour l'invention d'ancres et la rédaction de ponts sémantiques entre deux contenus.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="sil_similarity_threshold">Seuil de Similarité Sémantique</label></th>
                                <td>
                                    <input type="number" id="sil_similarity_threshold" name="sil_similarity_threshold" value="<?php echo esc_attr(get_option('sil_similarity_threshold', 0.3)); ?>" min="0.1" max="0.9" step="0.05" class="small-text">
                                    <p class="description">0.3 recommandé. Plus le seuil est haut, plus le maillage est strict.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="sil_target_permeability">Perméabilité des Cocons (Cible %)</label></th>
                                <td>
                                    <input type="range" id="sil_target_permeability" name="sil_target_permeability" value="<?php echo esc_attr(get_option('sil_target_permeability', 20)); ?>" min="0" max="40" step="5" oninput="this.nextElementSibling.innerText = this.value + '%'">
                                    <span style="font-weight:bold; margin-left:10px;"><?php echo esc_html(get_option('sil_target_permeability', 20)); ?>%</span>
                                    <p class="description">Pourcentage idéal de liens sortants d'un cocon vers d'autres cocons (Ratio de Diffusion).</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="sil_semantic_k">Nombre de Cocons Sémantiques (k)</label></th>
                                <td>
                                    <input type="number" id="sil_semantic_k" name="sil_semantic_k" value="<?php echo esc_attr(get_option('sil_semantic_k', 6)); ?>" min="2" max="20" class="small-text">
                                    <p class="description">Le nombre de grappes (clusters) que l'IA va tenter d'isoler. 6 par défaut.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Répulsion des bulles (Physique)</th>
                                <td>
                                    <input type="number" name="sil_node_repulsion" value="<?php echo esc_attr(get_option('sil_node_repulsion', 300000)); ?>" class="regular-text" />
                                    <p class="description">Défaut: 300000 (Optimisé pour ~50 contenus). Augmentez pour éloigner les bulles les unes des autres.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Espacement des silos (Physique)</th>
                                <td>
                                    <input type="number" name="sil_component_spacing" value="<?php echo esc_attr(get_option('sil_component_spacing', 60)); ?>" class="regular-text" />
                                    <p class="description">Défaut: 60. Distance minimale entre des groupes d'articles non reliés.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Gravité centrale (Physique)</th>
                                <td>
                                    <input type="number" step="0.1" name="sil_gravity" value="<?php echo esc_attr(get_option('sil_gravity', 2.0)); ?>" class="regular-text" />
                                    <p class="description">Défaut: 2.0. Force d'attraction vers le centre de la carte (évite l'écartement infini).</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Structure Sémantique</th>
                                <td>
                                    <button type="button" id="sil-rebuild-semantic-silos" class="button button-secondary">🔄 Recalculer les Silos Sémantiques</button>
                                    <p class="description">Utilise les Embeddings OpenAI pour regrouper vos contenus par thématique pure (C-Means Fuzzy).<br>Cela permet de détecter les <strong>ponts sémantiques</strong> et les dérives entre silos.</p>
                                    <div id="sil-silo-rebuild-status" style="margin-top:10px; font-weight:bold; display:none;"></div>
                                </td>
                            </tr>
                            <tr>
                                <th>Exclusions & Sécurité</th>
                                <td>
                                    <label style="display:block; margin-bottom:8px;">
                                        <input type="checkbox" name="sil_exclude_noindex" value="1" <?php checked(get_option('sil_exclude_noindex'), '1'); ?>> Ne pas mailler les pages en <code>noindex</code>
                                    </label>
                                    <label>
                                        <input type="checkbox" name="sil_auto_link" value="1" <?php checked(get_option('sil_auto_link'), '1'); ?>> Indexer automatiquement à la publication
                                    </label>
                                </td>
                            </tr>

                        </table>
                        <div style="margin-top:20px; border-top:1px solid #eee; padding-top:20px;">
                            <?php submit_button('💾 Enregistrer les réglages', 'primary', 'submit', false); ?>
                        </div>
                    </form>
                </div>
            </div>

        </div>

        <?php
    }

    public function render_gsc_settings_page()
    {
        ?>
                    <div class="wrap" id="sil-gsc-settings-page">
                        <div class="sil-header">
                            <h1>⚙️ Réglages Google Search Console</h1>
                        </div>

                        <div class="sil-card">
                            <div class="sil-card-body">
                                <p>Connectez le plugin à Search Console pour voir le vrai trafic de vos cocons et guider l'IA vers les
                                    mots-clés qui marchent.</p>

                                <div style="margin: 20px 0;">
                                    <button type="button" id="sil-open-gsc-modal" class="button button-primary">➕ Ajouter ce site à mon
                                        projet Google Cloud</button>
                                    <p class="description" style="margin-top: 5px;">Affiche la procédure exacte pour autoriser ce site
                                        dans votre console Google.</p>
                                </div>

                                <form method="post" action="options.php">
                                    <?php settings_fields('sil_gsc_settings'); ?>

                                    <h2 style="font-size: 1.2em; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 30px;">
                                        Configuration Google Cloud</h2>

                                    <table class="form-table sil-settings-table">
                                        <tr>
                                            <th><label for="sil_gsc_property_url">Propriété GSC (URL)</label></th>
                                            <td>
                                                <input type="url" id="sil_gsc_property_url" name="sil_gsc_property_url"
                                                    value="<?php echo esc_attr(get_option('sil_gsc_property_url')); ?>"
                                                    class="regular-text" placeholder="https://monsite.com/">
                                                <p class="description">L'URL exacte de votre propriété telle qu'elle apparaît dans la
                                                    Search Console (ex: <code>sc-domain:monsite.com</code> ou
                                                    <code>https://monsite.com/</code>).
                                                </p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="sil_gsc_client_id">Client ID (OAuth 2.0)</label></th>
                                            <td>
                                                <input type="text" id="sil_gsc_client_id" name="sil_gsc_client_id"
                                                    value="<?php echo esc_attr(get_option('sil_gsc_client_id')); ?>"
                                                    class="regular-text" placeholder="ex: 123456789-abcdef.apps.googleusercontent.com">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th><label for="sil_gsc_client_secret">Client Secret (OAuth 2.0)</label></th>
                                            <td>
                                                <input type="password" id="sil_gsc_client_secret" name="sil_gsc_client_secret"
                                                    value="<?php echo esc_attr(get_option('sil_gsc_client_secret')); ?>"
                                                    class="regular-text" placeholder="ex: GOCSPX-123456789">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Statut de connexion GSC</th>
                                            <td>
                                                <?php
                                                $tokens = get_option('sil_gsc_oauth_tokens');

                                                // Vérifier si Client ID et Secret sont sauvegardés (nécessaire pour afficher le bouton)
                                                $has_credentials = !empty(get_option('sil_gsc_client_id')) && !empty(get_option('sil_gsc_client_secret'));

                                                if (!empty($tokens) && isset($tokens['refresh_token'])):
                                                    ?>
                                                        <span style="color: green; font-weight: bold;">✅ Connecté à Google Search Console</span>
                                                        <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=sil_gsc_oauth_disconnect&_wpnonce=' . wp_create_nonce('sil_gsc_disconnect'))); ?>"
                                                            class="button button-small" style="margin-left: 10px;">Déconnecter</a>
                                                <?php else: ?>
                                                        <span style="color: #ea580c; font-weight: bold;">❌ Non connecté</span>
                                                        <?php if ($has_credentials): ?>
                                                                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=sil_gsc_oauth_redirect')); ?>"
                                                                    class="button button-primary" style="margin-left: 10px;">Se connecter à Google
                                                                    Search Console</a>
                                                        <?php else: ?>
                                                                <p class="description" style="color: #d63638; margin-top: 5px;">Veuillez d'abord
                                                                    sauvegarder les identifiants Client ID et Client Secret pour faire apparaître le
                                                                    bouton de connexion.</p>
                                                        <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Synchronisation GSC</th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="sil_gsc_auto_sync" value="1" <?php checked(get_option('sil_gsc_auto_sync'), '1'); ?>>
                                                    Activer la synchronisation automatique quotidienne
                                                </label>
                                                <p class="description">Si désactivée, vous devrez cliquer sur "Force GSC Sync Now" pour
                                                    actualiser les données.</p>
                                            </td>
                                        </tr>
                                    </table>

                                    <?php submit_button('Sauvegarder les Réglages GSC', 'primary', 'submit', true, ['style' => 'margin-top:20px;']); ?>
                                </form>

                                <div style="margin-top: 25px; border-top: 1px solid #ddd; padding-top: 15px;">
                                    <button type="button" id="sil-force-gsc-sync" class="button button-secondary">
                                        Force GSC Sync Now
                                    </button>
                                    <span id="sil-gsc-sync-status" style="margin-left: 10px; font-weight: bold;"></span>
                                    <?php
                                    $last_sync = get_option('sil_gsc_last_sync');
                                    if ($last_sync) {
                                        echo '<p class="description" style="display:inline-block; margin-left: 10px;">Dernière synchronisation : ' . esc_html($last_sync) . '</p>';
                                    }
                                    ?>
                                </div>

                                <!-- Diagnostic Table -->
                                <h2 style="font-size: 1.2em; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 40px;">
                                    Diagnostic: Derniers contenus scannés
                                </h2>
                                <p class="description">Affiche les 10 dernières pages avec des données GSC en base de données pour
                                    vérifier leur enregistrement.</p>
                                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                                    <thead>
                                        <tr>
                                            <th style="width: 40%;">Titre de la Page</th>
                                            <th>Top Requêtes (Mots-clés GSC mémorisés)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $diagnostic_posts = get_posts([
                                            'post_type' => ['post', 'page'],
                                            'post_status' => 'publish',
                                            'numberposts' => 10,
                                            'meta_query' => [
                                                [
                                                    'key' => '_sil_gsc_data',
                                                    'compare' => 'EXISTS'
                                                ]
                                            ],
                                            'orderby' => 'post_modified',
                                            'order' => 'DESC'
                                        ]);

                                        if (!empty($diagnostic_posts)):
                                            foreach ($diagnostic_posts as $post):
                                                if (!$post instanceof WP_Post)
                                                    continue;
                                                $gsc_json = get_post_meta($post->ID, '_sil_gsc_data', true);
                                                $gsc_data = !empty($gsc_json) && is_string($gsc_json) ? json_decode($gsc_json, true) : [];
                                                $queries = isset($gsc_data['top_queries']) ? $gsc_data['top_queries'] : [];
                                                ?>
                                                        <tr>
                                                            <td>
                                                                <a href="<?php echo get_edit_post_link($post->ID); ?>" target="_blank">
                                                                    <strong><?php echo esc_html($post->post_title); ?></strong>
                                                                </a><br>
                                                                <small style="color: #666;">ID: <?php echo $post->ID; ?></small>
                                                            </td>
                                                            <td>
                                                                <?php if (!empty($queries)): ?>
                                                                        <ol style="margin: 0; padding-left: 20px;">
                                                                            <?php foreach (array_slice($queries, 0, 5) as $query_row): ?>
                                                                                    <li>
                                                                                        <?php
                                                                                        $raw_keyword = isset($query_row['query']) ? $query_row['query'] : (isset($query_row['keys'][0]) ? $query_row['keys'][0] : 'Aucun mot-clé');
                                                                                        // Capture les uXXXX avec ou sans antislash
                                                                                        $keyword = preg_replace_callback('/(?:\\\\+)?u([0-9a-fA-F]{4})/', function ($match) {
                                                                                            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
                                                                                        }, $raw_keyword);
                                                                                        $keyword = wp_specialchars_decode($keyword, ENT_QUOTES);
                                                                                        $clicks = isset($query_row['clicks']) ? intval($query_row['clicks']) : 0;
                                                                                        echo esc_html($keyword);
                                                                                        ?>
                                                                                        <span style="color:#999; font-size:11px;">(Clics:
                                                                                            <?php echo $clicks; ?>)</span>
                                                                                    </li>
                                                                            <?php endforeach; ?>
                                                                        </ol>
                                                                <?php else: ?>
                                                                        <span style="color: #ea580c;">Aucune requête (GSC n'a rien trouvé pour cette
                                                                            page)</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                <?php endforeach;
                                        else: ?>
                                                <tr>
                                                    <td colspan="2" style="text-align: center; padding: 20px; color: #d63638;">
                                                        <strong>Aucune donnée trouvée !</strong><br>
                                                        La base de données ne contient aucun mot-clé GSC. Assurez-vous que l'API GSC est bien
                                                        connectée et qu'elle renvoie des données pour ce domaine.
                                                    </td>
                                                </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal GSC -->
                <div id="sil-gsc-modal"
                    style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5);">
                    <div
                        style="background-color:#fff; margin:10% auto; padding:20px; border:1px solid #888; width:80%; max-width:600px; border-radius:8px; box-shadow:0 4px 8px rgba(0,0,0,0.2);">
                        <span id="sil-gsc-modal-close"
                            style="color:#aaa; float:right; font-size:28px; font-weight:bold; cursor:pointer;"
                            title="Fermer">&times;</span>
                        <h2 style="margin-top:0;">Procédure d'ajout à Google Cloud</h2>

                        <ol style="line-height: 1.6; font-size: 14px; margin-top: 20px;">
                            <li style="margin-bottom: 20px;">
                                <strong>Copiez cette URL de redirection :</strong><br>
                                <code
                                    style="display:inline-block; margin-top:5px; padding:5px 10px; background:#f0f0f1; border:1px solid #c3c4c7; user-select:all; font-size: 14px;">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <?php echo esc_url(admin_url('admin-ajax.php?action=sil_gsc_oauth_callback')); ?>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    </code>
                            </li>
                            <li style="margin-bottom: 20px;">
                                <a href="https://console.cloud.google.com/auth/clients/create?project=smart-internal-links"
                                    target="_blank" class="button button-primary" style="text-decoration: none;">Ouvrir ma console
                                    Google Cloud</a>
                            </li>
                            <li style="margin-bottom: 20px;">
                                Cliquez sur votre <strong>ID Client OAuth existant</strong> dans la liste.
                            </li>
                            <li style="margin-bottom: 20px;">
                                Dans la section <em>"URI de redirection autorisés"</em>, cliquez sur <strong>"Ajouter un URI"</strong>
                                et collez l'URL copiée à l'étape 1.
                            </li>
                            <li style="margin-bottom: 20px;">
                                <strong>Enregistrez</strong> sur Google Cloud, puis revenez ici pour cliquer sur le bouton <em>"Se
                                    connecter à Google Search Console"</em>.
                            </li>
                        </ol>
                        <div style="text-align:right; margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">
                            <button type="button" id="sil-gsc-modal-ok" class="button button-secondary">J'ai compris</button>
                        </div>
                    </div>
                </div>

                <script>
                    jQuery(document).ready(function ($) {

                        // Modal logic
                        $('#sil-open-gsc-modal').on('click', function () {
                            $('#sil-gsc-modal').fadeIn(200);
                        });
                        $('#sil-gsc-modal-close, #sil-gsc-modal-ok').on('click', function () {
                            $('#sil-gsc-modal').fadeOut(200);
                        });

                    });
                </script>
                <?php
    }
    public function render_content_gap_page() {
        ?>
        <div class="wrap sil-gap-wrap">
            <h1>Intelligence GSC : 3 Colonnes de Pilotage</h1>
            <div class="sil-toolbar-gap" style="background:#fff; padding:15px; border:1px solid #ccd0d4; margin:20px 0; display:flex; align-items:center; gap:20px; border-radius:5px;">
                <div style="flex: 0 1 250px;">
                    <label>Sensibilité : <span id="sil-gap-val">50</span> impressions</label>
                    <input type="range" id="sil-gap-threshold" min="0" max="500" value="50" step="10" style="width:100%;">
                </div>
                <button id="sil-run-gap-analysis" class="button button-primary button-large">🔍 Analyser les Opportunités</button>
                <span id="sil-gap-loader" style="display:none;"><span class="spinner is-active"></span> Calcul...</span>
            </div>

            <div style="display:flex; gap:15px;">
                <div style="flex:1; background:#fff; border:1px solid #ccd0d4; border-radius:5px;">
                    <div style="padding:10px; background:#f0f0f1; border-bottom:1px solid #ccd0d4; font-weight:bold;">🚀 Striking Distance (Pos 6-15)</div>
                    <div id="gap-striking" style="padding:10px; min-height:150px;"></div>
                </div>
                <div style="flex:1; background:#fff; border:1px solid #ccd0d4; border-radius:5px;">
                    <div style="padding:10px; background:#f0f0f1; border-bottom:1px solid #ccd0d4; font-weight:bold;">🛠️ Consolidation (Pos 16-35)</div>
                    <div id="gap-consolidation" style="padding:10px; min-height:150px;"></div>
                </div>
                <div style="flex:1; background:#fff; border:1px solid #ccd0d4; border-radius:5px;">
                    <div style="padding:10px; background:#f0f0f1; border-bottom:1px solid #ccd0d4; font-weight:bold;">🕳️ True Gaps (Pos > 40)</div>
                    <div id="gap-true" style="padding:10px; min-height:150px;"></div>
                </div>
            </div>

            <h2 style="margin-top:40px; font-size: 1.2em; border-bottom: 1px solid #eee; padding-bottom: 5px;">⚠️ Alertes d'Étanchéité (Fuites sémantiques entre cocons)</h2>
            <div id="gap-silotage" style="background:#fff; border:1px solid #ccd0d4; border-radius:5px; padding:0; min-height:100px;">
                <div style="padding:20px; text-align:center; color:#666;">Cliquez sur Analyser les Opportunités pour vérifier l'étanchéité locale de vos silos.</div>
            </div>

        </div>
        <?php
    }
    public function render_visualization_page()
    {
        ?>
                <div class="wrap sil-visualization-page">
                    <div class="sil-header">
                        <h1>
                            🕸️ Visualisation du Maillage
                            <div class="sil-header-controls" style="display:inline-block; margin-left:20px;">
                                <button type="button" id="sil-refresh-graph" class="button button-secondary"
                                    title="Rafraîchir les données">🔄</button>
                                <span class="sil-sep" style="margin:0 10px;color:#ccc;">|</span>
                                <button type="button" id="sil-zoom-in" class="button button-secondary" title="Zoomer">➕</button>
                                <button type="button" id="sil-zoom-out" class="button button-secondary" title="Dézoomer">➖</button>
                                <button type="button" id="sil-fit-graph" class="button button-secondary" title="Ajuster">⤢
                                    Ajuster</button>
                                <button type="button" id="sil-fullscreen-toggle" class="button button-secondary"
                                    title="Plein écran">⛶</button>
                            </div>
                        </h1>
                    </div>

                    <!-- Graph Wrapper -->

                    <div id="sil-graph-wrapper" class="sil-graph-wrapper">


                        <!-- Legend -->
                        <div class="sil-graph-legend">
                            <div><span style="color:#d97706;">●</span> Piliers (Cornerstone)</div>
                            <div><span style="color:#3b82f6;">●</span> Articles</div>
                            <div><span style="color:#10b981;">●</span> Pages</div>
                        </div>

                        <!-- Container -->
                        <div id="sil-graph-container">
                            <!-- Graph will be rendered here by JS -->
                        </div>

                    </div>
                    <!-- Side Panel (Moved OUTSIDE wrapper) -->
                    <div id="sil-details-side-panel" class="sil-side-panel">
                        <button type="button" id="sil-panel-close" class="sil-panel-close">×</button>
                        <h3 id="sil-panel-title">Titre du contenu</h3>

                        <div class="sil-panel-body">
                            <div class="sil-panel-stats">
                                <div class="sil-stat-item">
                                    <span class="sil-stat-val" id="sil-panel-in">-</span>
                                    <span class="sil-stat-label">Liens Entrants</span>
                                </div>
                                <div class="sil-stat-item">
                                    <span class="sil-stat-val" id="sil-panel-out">-</span>
                                    <span class="sil-stat-label">Liens Sortants</span>
                                </div>
                            </div>

                            <div class="sil-panel-actions">
                                <a href="#" id="sil-panel-edit-btn" class="button button-primary" target="_blank">✏️ Éditer</a>
                                <a href="#" id="sil-panel-view-btn" class="button button-secondary" target="_blank">👀 Voir</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
    }
    private function get_openai_model()
    {
        $model = get_option('sil_openai_model', 'gpt-4o');
        if ($model === 'custom') {
            $custom = get_option('sil_openai_custom_model');
            return !empty($custom) ? $custom : 'gpt-4o';
        }
        return $model;
    }



    public function ajax_get_all_ids_for_gsc_sync()
    {
        check_ajax_referer('sil_nonce', '_ajax_nonce'); // [Security-Fix]
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé.');
        }

        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];

        $post_ids = get_posts($args);

        // Filter out noindexed pages
        $valid_ids = [];
        foreach ($post_ids as $pid) {
            if (!$this->is_noindexed($pid)) {
                $valid_ids[] = $pid;
            }
        }

        wp_send_json_success($valid_ids);
    }


    public function ajax_force_gsc_sync()
    {
        check_ajax_referer('sil_nonce', 'nonce'); // [Security-Fix]
        set_time_limit(0); // Empêche le timeout serveur lors du scan complet
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé.');
        }

        try {
            if (!class_exists('Sil_Gsc_Sync')) {
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-gsc-sync.php';
            }

            $sync = new Sil_Gsc_Sync();
            $result = $sync->sync_data();


            if ($result === true) {
                wp_send_json_success('Synchronisation terminée avec succès.');
            } else {
                wp_send_json_error('La synchronisation a échoué (vérifiez les logs ou le statut de connexion OAuth).');
            }
        } catch (\Exception $e) {
            wp_send_json_error('Erreur critique de synchronisation : ' . $e->getMessage());
        }
    }

    // ==============================================
    // OAuth 2.0 GSC Integration Handlers
    // ==============================================

    public function ajax_gsc_oauth_redirect()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }

        $client_id = get_option('sil_gsc_client_id');
        $client_secret = get_option('sil_gsc_client_secret');

        if (empty($client_id) || empty($client_secret)) {
            wp_die('Veuillez d\'abord configurer votre Client ID et Client Secret dans les réglages.');
        }

        try {
            error_log('SIL GSC: Starting native OAuth Redirect...');
            $handler = new \Sil_Gsc_Handler();
            $auth_url = $handler->get_auth_url();

            error_log('SIL GSC: Generated native Auth URL: ' . $auth_url);
            wp_redirect($auth_url);
            exit;
        } catch (\Exception $e) {
            error_log('SIL GSC Fatal Error in native redirect: ' . $e->getMessage());
            wp_die('Erreur d\'initialisation OAuth : ' . esc_html($e->getMessage()));
        }
    }

    public function ajax_gsc_oauth_callback()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }

        if (!isset($_GET['code'])) {
            wp_die('Code d\'autorisation manquant. Redirection incomplète.');
        }

        try {
            error_log('SIL GSC: Starting native OAuth Callback processing...');
            $handler = new \Sil_Gsc_Handler();
            $token = $handler->fetch_access_token_with_auth_code($_GET['code']);

            // Store tokens (this includes Bearer access_token & refresh_token)
            update_option('sil_gsc_oauth_tokens', $token);
            error_log('SIL GSC: Native Tokens successfully acquired and saved.');

            // Redirect back to settings page with success message
            wp_redirect(admin_url('admin.php?page=sil-gsc-settings&gsc_auth=success'));
            exit;
        } catch (\Exception $e) {
            error_log('SIL GSC Fatal Error in native callback: ' . $e->getMessage());
            wp_die('Erreur d\'authentification OAuth : ' . esc_html($e->getMessage()));
        }
    }

    public function ajax_gsc_oauth_disconnect()
    {
        check_admin_referer('sil_gsc_disconnect');

        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }

        delete_option('sil_gsc_oauth_tokens');

        wp_redirect(admin_url('admin.php?page=smart-internal-links-settings&gsc_auth=disconnected'));
        exit;
    }
}
require_once plugin_dir_path(__FILE__) . 'includes/class-sil-cluster-analysis.php';

// Init
SmartInternalLinks::get_instance();








