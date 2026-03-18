# Plugin Structure: Smart Internal Links

This document provides a comprehensive overview of the classes, methods, and variables within the Smart Internal Links plugin.

## Core Classes

### [SmartInternalLinks](file:///c:/Users/leste/Downloads/smart-internal-links/smart-internal-links.php)
The main controller of the plugin.

#### Properties
- `$api_key`: OpenAI API key.
- `$table_name`: Primary table for embeddings (`{$wpdb->prefix}sil_embeddings`).
- `$post_types`: Array of supported post types (`['post', 'page']`).

#### Methods
- `get_instance()`: Singleton implementation.
- `__construct()`: Initializes hooks and includes dependencies.
- `init_db()`: Creates the necessary database tables.
- `generate_embedding($post_id)`: Generates and stores OpenAI embeddings for a specific post. Uses MD5 hashing to avoid redundant API calls.
- `find_similar_posts($post_id, $limit, $force_category)`: Identifies semantically similar posts using GSC queries or title keywords.
- `insert_internal_links($post_id, $dry_run, $force_category)`: Core logic for recommending and optionally inserting internal links.
- `get_rendered_graph_data($force)`: Calculates nodes and edges for the interactive graph, including GSC metrics and Infomap clustering.
- `count_internal_links($post_id)`: Counts outgoing internal links in a post.
- `count_backlinks($post_id)`: Counts incoming internal links to a post.
- `calculate_health_score()`: Computes the overall health percentage based on coverage and link density.

---

### [SIL_Ajax_Handler](file:///c:/Users/leste/Downloads/smart-internal-links/includes/class-sil-ajax-handler.php)
Manages all AJAX actions and communication between the frontend and the core logic.

#### Methods
- `init($handler)`: Registers all `wp_ajax_*` hooks.
- `sil_index_embeddings_batch()`: Processes a batch of posts for embedding generation. 
  - **Note**: Fixed a critical typo where it previously called an undefined `generate_and_store_embedding` instead of `generate_embedding`.
- `sil_get_graph_data()`: AJAX bridge for `get_rendered_graph_data`.
- `sil_run_system_diagnostic()`: Performs a system-wide check of embeddings, GSC data, and topology.
- `sil_get_content_gap_data()`: Analyzes GSC data vs. existing content to identify SEO opportunities.
- `sil_get_node_details()`: Retrieves statistics and link data for a specific node in the graph.

---

### [SIL_Cluster_Analysis](file:///c:/Users/leste/Downloads/smart-internal-links/includes/class-sil-cluster-analysis.php)
Handles Infomap clustering and topological analysis.

#### Methods
- `get_graph_data($nodes, $edges)`: Enhances graph data with cluster IDs, PageRank, and permeability metrics.
- `calculate_pagerank($nodes, $edges)`: Simple iterative implementation of PageRank.

---

### [Sil_Gsc_Sync](file:///c:/Users/leste/Downloads/smart-internal-links/includes/class-sil-gsc-sync.php)
Synchronizes data with Google Search Console.

#### Methods
- `sync_data($post_ids)`: Fetches impressions, clicks, and queries for the specified posts.
- `is_configured()`: Checks if OAuth credentials are present.

---

## Global JavaScript Objects

### `silAjax`
Available on all admin pages. Contains the base `ajaxurl` and security nonces.

### `silGraphData`
Primarily available on the Graph page. Contains localized data for initial graph rendering.

## Database Tables

- `{$wpdb->prefix}sil_embeddings`: Stores post IDs and their 1536-dimensional OpenAI embeddings.
- `{$wpdb->prefix}sil_links`: Tracks internal links created or detected by the plugin.
- `{$wpdb->prefix}sil_gsc_data`: Caches performance metrics and top queries from Google Search Console.
