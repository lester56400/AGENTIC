<?php
/**
 * Smart Internal Links - Admin Manager
 * Gère l'interface d'administration, les menus, les réglages et les assets.
 */

if (!defined('ABSPATH')) exit;

class SIL_Admin_Manager {

    private $main;

    /**
     * @param SmartInternalLinks $main Instance principale du plugin.
     */
    public function __construct($main) {
        $this->main = $main;
        
        // Hooks d'initialisation
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Meta boxes
        add_action('add_meta_boxes', [$this, 'add_cornerstone_meta_box']);
        add_action('save_post', [$this, 'save_cornerstone_meta_box']);
        
        // Widgets
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widgets']);
    }

    /**
     * Enregistre le menu et les sous-menus.
     */
    public function add_admin_menu() {
        add_menu_page(
            'Smart Internal Links',
            'Smart Links',
            'edit_posts',
            'smart-internal-links',
            [$this->main->renderer, 'render_admin_page'],
            'dashicons-admin-links',
            30
        );

        add_submenu_page(
            'smart-internal-links',
            'Réglages',
            'Réglages',
            'manage_options',
            'sil-settings',
            [$this->main->renderer, 'render_settings_page']
        );

        add_submenu_page(
            'smart-internal-links',
            'Cartographie Interactive',
            'Cartographie',
            'manage_options',
            'sil-cartographie',
            [$this->main->renderer, 'render_cartographie_page']
        );

        add_submenu_page(
            'smart-internal-links',
            'Réglages GSC',
            'Réglages GSC',
            'manage_options',
            'sil-gsc-settings',
            [$this->main->renderer, 'render_gsc_settings_page']
        );

        add_submenu_page(
            'smart-internal-links',
            'Dashboard de Cohérence',
            'Dashboard de Cohérence',
            'manage_options',
            'sil-opportunites',
            [$this->main->renderer, 'render_content_gap_page']
        );

        // Pilotage avec badge de notification
        $pilotage_title = $this->get_pilotage_title_with_badge();
        add_submenu_page(
            'smart-internal-links',
            'Pilotage (Beta)',
            $pilotage_title,
            'manage_options',
            'sil-pilotage',
            [$this->main->renderer, 'render_pilotage_page']
        );
    }

    /**
     * Enregistre tous les réglages du plugin.
     */
    public function register_settings() {
        // Paramètres OpenAI & Core
        $settings = [
            'sil_openai_api_key'      => 'sanitize_text_field',
            'sil_openai_model'        => 'sanitize_text_field',
            'sil_openai_custom_model' => 'sanitize_text_field',
            'sil_openai_seo_prompt'   => 'sanitize_textarea_field',
            'sil_openai_bridge_prompt'=> 'sanitize_textarea_field',
            'sil_auto_link'           => 'absint',
            'sil_max_links'           => 'absint',
            'sil_similarity_threshold'=> 'floatval',
            'sil_similarity_max'      => 'floatval',
            'sil_toxicity_threshold'  => 'floatval',
            'sil_link_scope'          => 'sanitize_text_field',
            'sil_exclude_noindex'     => 'absint',
            'sil_target_permeability' => 'absint',
            'sil_elite_ratio'         => 'floatval',
            'sil_elite_threshold'     => 'floatval',
            'sil_drip_feed_days'      => 'absint',
            'sil_pillar_multiplier'   => 'floatval'
        ];

        foreach ($settings as $opt => $callback) {
            register_setting('sil_settings', $opt, ['sanitize_callback' => $callback]);
        }

        // Paramètres GSC (OAuth 2.0)
        register_setting('sil_gsc_settings', 'sil_gsc_property_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('sil_gsc_settings', 'sil_gsc_client_id',     ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sil_gsc_settings', 'sil_gsc_client_secret', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sil_gsc_settings', 'sil_gsc_oauth_tokens');
        register_setting('sil_gsc_settings', 'sil_gsc_last_sync');
        register_setting('sil_gsc_settings', 'sil_gsc_auto_sync',     ['sanitize_callback' => 'absint']);
    }

    /**
     * Charge les scripts et styles pour l'administration.
     */
    public function enqueue_admin_scripts($hook) {
        $is_plugin_page = (strpos($hook, 'smart-internal-links') !== false || strpos($hook, 'sil-') !== false);
        if (!$is_plugin_page) return;

        // Fonts & Global CSS
        wp_enqueue_style('sil-google-fonts', 'https://fonts.googleapis.com/css2?family=Fira+Code:wght@500&family=Fira+Sans:wght@400;600;700&display=swap', [], null);
        wp_enqueue_style('sil-admin', SIL_PLUGIN_URL . 'assets/admin.css', [], SIL_VERSION);

        // Core Interaction JS
        wp_enqueue_script('sil-bridge-manager', SIL_PLUGIN_URL . 'assets/sil-bridge-manager.js', ['jquery'], SIL_VERSION, true);

        // Graph Specifics
        if (strpos($hook, 'sil-cartographie') !== false) {
            wp_enqueue_script('cytoscape', 'https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.26.0/cytoscape.min.js', [], '3.26.0', true);
            wp_enqueue_script('sil-graph-js', SIL_PLUGIN_URL . 'assets/sil-graph-v3.js', ['jquery', 'cytoscape', 'sil-bridge-manager'], SIL_VERSION, true);
            wp_enqueue_style('sil-graph-css', SIL_PLUGIN_URL . 'assets/sil-graph-v2.css', [], SIL_VERSION);
        }

        // Pilotage Specifics
        if (strpos($hook, 'sil-pilotage') !== false) {
            wp_enqueue_style('sil-pilot-center', SIL_PLUGIN_URL . 'assets/sil-pilot-center.css', [], SIL_VERSION);
            wp_enqueue_script('sil-pilot-center-js', SIL_PLUGIN_URL . 'assets/sil-pilot-center.js', ['jquery', 'sil-bridge-manager'], SIL_VERSION, true);
        }

        // Legacy Admin/Settings JS
        if (strpos($hook, 'sil-settings') !== false || strpos($hook, 'smart-internal-links') !== false || strpos($hook, 'sil-opportunites') !== false) {
            wp_enqueue_script('sil-admin-js', SIL_PLUGIN_URL . 'assets/admin.js', ['jquery', 'sil-bridge-manager'], '2.3.0', true);
        }

        // --- UNIFIED DATA (Rescue Plan) ---
        $shared_data = [
            'ajaxurl'             => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce('sil_nonce'),
            'admin_nonce'         => wp_create_nonce('sil_admin_nonce'),
            'siteUrl'             => get_site_url(),
            'restUrl'             => esc_url_raw(rest_url('sil/v1/graph-data')),
            'rest_nonce'          => wp_create_nonce('wp_rest'),
            'home_url'            => home_url('/'),
            'admin_url'           => admin_url('admin.php'),
            'post_edit_base'      => admin_url('post.php'),
            'maxClicks'           => 100,
            'target_permeability' => get_option('sil_target_permeability', 20),
            'repulsion'           => get_option('sil_node_repulsion', 300000),
            'spacing'             => get_option('sil_component_spacing', 60),
            'gravity'             => get_option('sil_gravity', 2.0),
            'icons'               => [
                'check'     => SIL_Icons::get_icon('check', ['size' => 14]),
                'x'         => SIL_Icons::get_icon('x', ['size' => 14]),
                'rocket'    => SIL_Icons::get_icon('rocket', ['size' => 14]),
                'link'      => SIL_Icons::get_icon('link', ['size' => 14, 'class' => 'sil-inline-icon']),
                'star'      => SIL_Icons::get_icon('star', ['size' => 12, 'color' => '#f59e0b']),
                'ghost'     => SIL_Icons::get_icon('ghost', ['size' => 14]),
                'flag'      => SIL_Icons::get_icon('flag', ['size' => 14]),
                'droplets'  => SIL_Icons::get_icon('droplets', ['size' => 14]),
                'target'    => SIL_Icons::get_icon('target', ['size' => 14]),
                'lightbulb' => SIL_Icons::get_icon('lightbulb', ['size' => 14]),
                'bridge'    => SIL_Icons::get_icon('bridge', ['size' => 14]),
                'anchor'    => SIL_Icons::get_icon('anchor', ['size' => 14]),
                'bot'       => SIL_Icons::get_icon('bot', ['size' => 14]),
                'wand'      => SIL_Icons::get_icon('wand', ['size' => 14]),
                'sparkles'  => SIL_Icons::get_icon('sparkles', ['size' => 14]),
                'trending_down' => SIL_Icons::get_icon('trending-down', ['size' => 14]),
            ]
        ];

        wp_localize_script('sil-admin-js', 'silSharedData', $shared_data);
        wp_localize_script('sil-graph-js', 'silSharedData', $shared_data);
        wp_localize_script('sil-pilot-center-js', 'silSharedData', $shared_data);
        wp_localize_script('sil-bridge-manager', 'silSharedData', $shared_data);
    }

    /**
     * Gère la Meta Box Cornerstone.
     */
    public function add_cornerstone_meta_box() {
        foreach ($this->main->post_types as $type) {
            add_meta_box(
                'sil_cornerstone_box',
                'Smart Internal Links - SEO',
                [$this->main->renderer, 'render_cornerstone_box'],
                $type,
                'side',
                'high'
            );
        }
    }

    /**
     * Sauvegarde la Meta Box Cornerstone.
     */
    public function save_cornerstone_meta_box($post_id) {
        if (!isset($_POST['sil_cornerstone_nonce']) || !wp_verify_nonce($_POST['sil_cornerstone_nonce'], 'sil_cornerstone_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        if (current_user_can('edit_post', $post_id)) {
            // Cornerstone
            if (isset($_POST['sil_is_cornerstone'])) {
                update_post_meta($post_id, '_sil_is_cornerstone', '1');
            } else {
                delete_post_meta($post_id, '_sil_is_cornerstone');
            }

            // Pillar Content
            if (isset($_POST['sil_is_pillar'])) {
                update_post_meta($post_id, '_sil_is_pillar', '1');
            } else {
                delete_post_meta($post_id, '_sil_is_pillar');
            }
        }
    }

    /**
     * Ajoute les widgets Dashboard.
     */
    public function add_dashboard_widgets() {
        wp_add_dashboard_widget(
            'sil_orphan_widget',
            'SIL : Contenus Orphelins',
            [$this->main->renderer, 'render_orphan_widget']
        );
    }

    /**
     * Calcule le titre du menu Pilotage avec le badge de notifications.
     */
    private function get_pilotage_title_with_badge() {
        global $wpdb;
        $title = 'Pilotage 💎';
        
        $table_name = $wpdb->prefix . 'sil_scheduled_links';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) return $title;

        $pending_count = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM $table_name l
            JOIN {$wpdb->prefix}posts p ON l.target_id = p.ID
            WHERE p.post_status = 'publish' AND l.status = 'pending'
        ");

        $cannibal_alerts = (int) get_option('sil_cannibalization_alerts_count', 0);
        $total = $pending_count + $cannibal_alerts;

        if ($total > 0) {
            $title .= ' <span class="update-plugins count-' . $total . '"><span class="plugin-count">' . $total . '</span></span>';
        }

        return $title;
    }
}
