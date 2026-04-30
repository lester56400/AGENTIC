<?php
/**
 * Plugin Name: _Smart Internal Links
 * Description: Génère automatiquement des liens internes pertinents grâce aux embeddings et OpenAI. Supporte Articles et Pages.
 * Version: 2.8.3
 * Author: Jennifer Larcher
 * Author URI: https://redactiwe.systeme.io/formation-redacteur-ia
 * Text Domain: smart-internal-links
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SIL_VERSION', '2.8.3');
define('SIL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SIL_PLUGIN_URL', plugin_dir_url(__FILE__));

class SmartInternalLinks
{    private static $instance = null;
    public $table_name;
    public $micro_table_name;
    private $api_key;
    
    // Conteneur pour le lazy loading
    private $instances = [];

    // Types de contenus supportés (Blog + Pages produits/services)
    public $post_types = ['post', 'page'];

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Lazy Loader : Charge les classes et instancie les objets uniquement à la demande.
     */
    public function __get($key) {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        switch($key) {
            case 'embeddings':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-embedding-manager.php';
                $this->instances['embeddings'] = new SIL_Embedding_Manager($this->api_key, $this->table_name, $this->micro_table_name);
                return $this->instances['embeddings'];

            case 'renderer':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-admin-renderer.php';
                $this->instances['renderer'] = new SIL_Admin_Renderer($this);
                return $this->instances['renderer'];

            case 'entity_manager':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-entity-manager.php';
                $this->instances['entity_manager'] = new SIL_Entity_Manager($this->api_key);
                return $this->instances['entity_manager'];

            case 'semantic_silos':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-semantic-silos.php';
                $this->instances['semantic_silos'] = new SIL_Semantic_Silos();
                return $this->instances['semantic_silos'];

            case 'scanner':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-scanner.php';
                $this->instances['scanner'] = new SIL_Scanner($this);
                return $this->instances['scanner'];

            case 'pilot_engine':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-pilot-engine.php';
                $this->instances['pilot_engine'] = new SIL_Pilot_Engine($this);
                return $this->instances['pilot_engine'];

            case 'db_manager':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-database-manager.php';
                $this->instances['db_manager'] = new SIL_Database_Manager();
                return $this->instances['db_manager'];
            
            case 'admin_manager':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-admin-manager.php';
                $this->instances['admin_manager'] = new SIL_Admin_Manager($this);
                return $this->instances['admin_manager'];

            case 'ajax_handler':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-ajax-handler.php';
                $this->instances['ajax_handler'] = new SIL_Ajax_Handler($this);
                return $this->instances['ajax_handler'];

            case 'cluster_analysis':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-cluster-analysis.php';
                $this->instances['cluster_analysis'] = new SIL_Cluster_Analysis($this);
                return $this->instances['cluster_analysis'];
            
            case 'centrality_engine':
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-centrality-engine.php';
                return true; // Solo file, static methods
        }


        return null;
    }


    public function get_post_types()
    {
        return $this->post_types;
    }

    private function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sil_embeddings';
        $this->micro_table_name = $wpdb->prefix . 'sil_micro_cache';
        $this->api_key = get_option('sil_openai_api_key');

        // Charger les utilitaires légers et les icônes (statiques ou légers)
        require_once SIL_PLUGIN_DIR . 'includes/class-sil-icons.php';
        require_once SIL_PLUGIN_DIR . 'includes/class-sil-seo-utils.php';

        // Initialiser les hooks Admin & AJAX (Léger, car les classes lourdes sont lazy-loadées)
        $this->admin_manager; // Déclenche le lazy-load pour enregistrer les menus
        $this->ajax_handler->init(); // Déclenche le lazy-load pour enregistrer les actions

        // Hooks Core
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('transition_post_status', [$this, 'on_post_first_publish'], 10, 3);
        add_action('save_post', [$this, 'on_save_post'], 10, 3);
        add_action('save_post', [$this, 'auto_validate_scheduled_links'], 10, 3);
        add_action('admin_notices', [$this, 'display_suggestions_notice']);

        // Cron logic (Léger)
        add_action('sil_gsc_daily_sync', function () {
            if (get_option('sil_gsc_auto_sync') === '1') {
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-gsc-sync.php';
                $sync = new \Sil_Gsc_Sync();
                $sync->sync_data();
            }
        });

        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);

        // GSC Native OAuth hooks
        add_action('wp_ajax_sil_gsc_oauth_redirect', [$this, 'ajax_gsc_oauth_redirect']);
        add_action('wp_ajax_sil_gsc_oauth_callback', [$this, 'ajax_gsc_oauth_callback']);
        add_action('wp_ajax_sil_gsc_oauth_disconnect', [$this, 'ajax_gsc_oauth_disconnect']);
        add_action('wp_ajax_sil_get_all_ids_for_gsc_sync', [$this, 'ajax_get_all_ids_for_gsc_sync']);
        add_action('wp_ajax_sil_force_gsc_sync_batch', [$this, 'ajax_force_gsc_sync_batch']);

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

    // Methods migrated to SIL_Admin_Manager: 
    // add_admin_menu, register_settings, add_cornerstone_meta_box, save_cornerstone_meta_box

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

    // Method migrated to SIL_Admin_Manager: enqueue_admin_scripts

    // ==============================================
    // HELPER: NOINDEX DETECTION
    // ==============================================

    /**
     * Vérifie si un post est marqué "noindex" par les plugins SEO majeurs
     */
    private function is_noindexed($post_id)
    {
        return SIL_SEO_Utils::is_noindexed($post_id);
    }

    /**
     * Calcule le score de santé global du maillage (0-100)
     */
    /**
     * Calcule le score de santé (Proxy vers le renderer)
     */
    public function calculate_health_score() {
        return $this->renderer->calculate_health_score();
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
        delete_transient('sil_graph_cache_v13_0');
        delete_transient('sil_graph_cache_v12_0');
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
        return $this->embeddings->generate_post_embedding($post_id);
    }

    public function cosine_similarity($vec1, $vec2)
    {
        return $this->embeddings->calculate_similarity($vec1, $vec2);
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
        if (strpos($content, '<p>') === false) {
            $content = wpautop($content);
        }

        $paragraphs = [];
        
        // Fallback sécurisé : Utilisation de DOMDocument pour éviter les limites de regex
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // Encodage forcé pour éviter les soucis UTF-8
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // On cherche les paragraphes, les blocs texte Thrive et les blocs Spectra
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//p | //div[contains(@class, "thrv_text_element")] | //div[contains(@class, "uagb-")]');
        
        $idx = 0;
        foreach ($nodes as $node) {
            // Obtenir le HTML interne du nœud
            $html = $dom->saveHTML($node);
            
            // Decode entities so OpenAI/Analysis sees plain exact text
            $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', $text));
            $text = trim($text);

            $link_count = substr_count(strtolower($html), '<a ');
            
            if (strlen($text) > 80 && $link_count < 2) {
                // Pour maintenir la compatibilité avec insert_link_in_paragraph qui attend un offset de regex,
                // nous ne fournissons plus d'offset mais nous basons sur le remplacement de contenu HTML exact.
                $paragraphs[] = [
                    'index'      => $idx,
                    'content'    => $html,
                    'text'       => $text,
                    'link_count' => $link_count
                ];
            }
            $idx++;
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
6. Ajoute systématiquement la classe sil-link et l'attribut data-sil-id=\"{$link_id}\" à la balise <a>.
7. Respecte la balise HTML de conteneur originale du paragraphe (ex: <p>...</p> ou <div>...</div>).";

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
                    // S'assurer qu'il y a une balise de conteneur (p ou div)
                    if (strpos($new_text, '<p>') === false && strpos($new_text, '<div') === false) {
                        // Si l'original était un div, on remet un div, sinon p
                        if (strpos($paragraph, '<div') === 0) {
                            $new_text = '<div>' . $new_text . '</div>';
                        } else {
                            $new_text = '<p>' . $new_text . '</p>';
                        }
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

            // --- NOUVEAU: Check Anti-Cannibalisation (Duel de Pages) ---
            $max_sim = floatval(get_option('sil_similarity_max', 0.92));
            $source_emb = get_post_meta($item['post_id'], '_sil_embedding', true);
            $target_emb = get_post_meta($post_id, '_sil_embedding', true);

            if (!empty($source_emb) && !empty($target_emb)) {
                $source_v = is_array($source_emb) ? $source_emb : json_decode($source_emb, true);
                $target_v = is_array($target_emb) ? $target_emb : json_decode($target_emb, true);
                
                if ($source_v && $target_v) {
                    $real_similarity = $this->cosine_similarity($source_v, $target_v);
                    if ($real_similarity >= $max_sim) {
                        error_log("SIL: Blocage Cannibalisation Sémantique détecté entre " . $item['post_id'] . " et " . $post_id . " (Score: $real_similarity)");
                        continue;
                    }
                }
            }

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
        $enriched_data = $this->cluster_analysis->get_graph_data($force);

        return [
            'cached'           => isset($enriched_data['metadata']['generated_at']) && abs(strtotime($enriched_data['metadata']['generated_at']) - time()) < 10, 
            'nodes'            => $enriched_data['nodes'] ?? [],
            'edges'            => $enriched_data['edges'] ?? [],
            'opportunities'    => $enriched_data['opportunities'] ?? [],
            'stats_summary'    => $enriched_data['stats_summary'] ?? [],
            'metadata'         => $enriched_data['metadata'] ?? []
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
    public function get_openai_model()
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
        check_ajax_referer('sil_nonce', 'nonce'); 
        set_time_limit(0); 
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé.');
        }

        try {
            if (!class_exists('Sil_Gsc_Sync')) {
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-gsc-sync.php';
            }

            $sync = new Sil_Gsc_Sync();
            $result = $sync->sync_data();

            if (isset($result['success']) && $result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result['message'] ?? 'La synchronisation a échoué.');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function ajax_force_gsc_sync_batch()
    {
        check_ajax_referer('sil_nonce', 'nonce');
        set_time_limit(60); 

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Accès refusé.');
        }

        $post_ids = isset($_POST['post_ids']) ? (array) $_POST['post_ids'] : [];
        if (empty($post_ids)) {
            wp_send_json_error('Aucun ID de post fourni.');
        }

        try {
            if (!class_exists('Sil_Gsc_Sync')) {
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-gsc-sync.php';
            }

            $sync = new Sil_Gsc_Sync();
            $result = $sync->sync_data($post_ids);

            if (isset($result['success']) && $result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result['message'] ?? 'Erreur lors du traitement du lot.');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
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
            if (!class_exists('Sil_Gsc_Handler')) {
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-gsc-handler.php';
            }
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
            if (!class_exists('Sil_Gsc_Handler')) {
                require_once SIL_PLUGIN_DIR . 'includes/class-sil-gsc-handler.php';
            }
            $handler = new \Sil_Gsc_Handler();
            $token = $handler->fetch_access_token_with_auth_code($_GET['code']);

            // Store tokens (this includes Bearer access_token & refresh_token)
            update_option('sil_gsc_oauth_tokens', $token, false);
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
        
        wp_redirect(admin_url('admin.php?page=sil-gsc-settings&gsc_auth=disconnected'));
        exit;
    }

    /**
     * Vérifie si un lien programmé a été inséré manuellement lors de la sauvegarde.
     */
    public function auto_validate_scheduled_links($post_id, $post, $update)
    {
        if (wp_is_post_revision($post_id) || !$update) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        global $wpdb;
        $table = $wpdb->prefix . 'sil_scheduled_links';

        // Vérification silencieuse de l'existence de la table
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) return;

        // Cherche les tâches en attente pour cet article
        $pending = $wpdb->get_results($wpdb->prepare(
            "SELECT id, target_id FROM $table WHERE source_id = %d AND status = 'pending'",
            $post_id
        ));

        if (empty($pending)) return;

        foreach ($pending as $link) {
            $target_url = get_permalink($link->target_id);
            $escaped_url = preg_quote($target_url, '/');
            $relative_url = wp_make_link_relative($target_url);

            // Si le lien est trouvé dans le contenu, on valide la tâche
            if (preg_match('/href=["\'](' . $escaped_url . '|' . preg_quote($relative_url, '/') . ')["\']/i', $post->post_content)) {
                $wpdb->update($table, ['status' => 'completed'], ['id' => $link->id], ['%s'], ['%d']);
            }
        }
    }
}

// Init
$GLOBALS['smart_internal_links'] = SmartInternalLinks::get_instance();









