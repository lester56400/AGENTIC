<?php
/**
 * Smart Internal Links Admin Renderer
 *
 * @package SmartInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SIL_Admin_Renderer
 *
 * Handles the rendering of all admin pages for the plugin.
 */
class SIL_Admin_Renderer {

	/**
	 * Main plugin instance.
	 *
	 * @var SmartInternalLinks
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param SmartInternalLinks $plugin Main plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Render the main dashboard page.
	 */
	public function render_admin_page() {
		global $wpdb;

		$table_links = $wpdb->prefix . 'sil_links';

		// Total posts
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND post_status = 'publish'",
			$this->plugin->post_types[0],
			$this->plugin->post_types[1]
		) );

		// Indexed posts
		$indexed = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->plugin->table_name}" );

		$per_page    = 50;
		$current     = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$total_pages = ceil( $total / $per_page );
		$offset      = ( $current - 1 ) * $per_page;
		?>
		<div class="wrap">
			<div class="sil-header">
				<h1><?php echo SIL_Icons::get_icon( 'link', [ 'size' => 24, 'class' => 'sil-title-icon' ] ); ?> Smart Internal Links (Articles & Pages)</h1>
				<div class="sil-header-actions">
					<button type="button" id="sil-force-gsc-sync-main" class="button button-primary sil-btn-sm"
						style="margin-right:8px;" title="Recalcul des métriques Content Decay depuis GSC.">🤖 Actualiser les
						métriques GSC</button>
					<button id="sil-refresh-stats" class="sil-btn sil-btn-secondary sil-btn-sm">🔄</button>
					<a href="<?php echo admin_url( 'admin.php?page=sil-settings' ); ?>"
						class="sil-btn sil-btn-secondary sil-btn-sm">⚙️ Réglages</a>
				</div>
			</div>

			<!-- TAB: LISTE -->
			<div id="sil-list-content">
				<div class="sil-stats-grid">
					<div class="sil-stat-card primary">
						<div class="stat-label">Contenus publiés</div>
						<div class="stat-value" id="stat-total"><?php echo intval( $total ); ?></div>
					</div>
					<div class="sil-stat-card success">
						<div class="stat-label">Indexés</div>
						<div class="stat-value" id="stat-indexed"><?php echo intval( $indexed ); ?></div>
					</div>
					<div class="sil-stat-card warning">
						<div class="stat-label">À indexer</div>
						<div class="stat-value" id="stat-to-index"><?php echo intval( $total - $indexed ); ?></div>
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
							<button class="sil-filter-btn" data-filter="orphan">🔴 Orphelines</button>
							<span class="sil-filter-count"><?php echo intval( $total ); ?> contenu(s)</span>
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
									<th title="Liens internes sortants"><?php echo SIL_Icons::get_icon( 'link', [ 'size' => 14 ] ); ?> Sortants</th>
									<th title="Liens internes entrants">📥 Reçus</th>
									<th>Date</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php
								$posts = get_posts( [
									'post_type'   => $this->plugin->post_types,
									'post_status' => 'publish',
									'numberposts' => $per_page,
									'offset'      => $offset,
									'orderby'     => 'date',
									'order'       => 'DESC',
								] );

								// Pre-fetch decay data
								$post_ids   = array_column( $posts, 'ID' );
								$decay_data = [];
								if ( ! empty( $post_ids ) ) {
									$gsc_table       = $wpdb->prefix . 'sil_gsc_data';
									$ids_placeholder = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
									$decay_results   = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, yield_delta_percent, position_delta FROM {$gsc_table} WHERE post_id IN ($ids_placeholder)", ...$post_ids ) );
									foreach ( $decay_results as $row ) {
										// 📉 Content Decay si Yield chute de > 15% ET position chute de > 2 rangs
										if ( floatval( $row->position_delta ) <= -2 && floatval( $row->yield_delta_percent ) <= -15 ) {
											$decay_data[ $row->post_id ] = [
												'position_delta'      => floatval( $row->position_delta ),
												'yield_delta_percent' => floatval( $row->yield_delta_percent ),
											];
										}
									}
								}

								if ( is_array( $posts ) ) :
									foreach ( $posts as $post ) :
										if ( ! $post instanceof WP_Post ) {
											continue;
										}
										$post_type_label = 'page' === $post->post_type ? 'Page' : 'Article';
										$is_cornerstone  = '1' === get_post_meta( $post->ID, '_sil_is_cornerstone', true );

										$outgoing = $this->plugin->count_internal_links( $post->ID );
										$incoming = $this->plugin->count_backlinks( $post->ID );
										$no_match = get_post_meta( $post->ID, '_sil_no_match', true );

										$out_class = 0 === $outgoing ? 'sil-badge-danger' : ( $outgoing < 3 ? 'sil-badge-warning' : 'sil-badge-success' );
										$in_class  = 0 === $incoming ? 'sil-badge-danger' : ( $incoming < 3 ? 'sil-badge-warning' : 'sil-badge-success' );

										$data_links = 0 === $outgoing ? 'none' : ( $outgoing < 3 ? 'few' : 'good' );

										// Vérification stricte: L'article doit avoir plus de 6 mois
										$pm_raw             = isset( $post->post_modified ) ? $post->post_modified : $post->post_date;
										$post_modified_time = function_exists( 'get_post_modified_time' ) ? (int) get_post_modified_time( 'U', false, $post->ID ) : strtotime( $pm_raw );
										$post_time          = max( strtotime( $post->post_date ), $post_modified_time );
										$six_months_ago     = strtotime( '-6 months' );
										$is_decaying        = isset( $decay_data[ $post->ID ] ) && ( $post_time < $six_months_ago );
										?>
												<tr data-post-id="<?php echo $post->ID; ?>" data-links="<?php echo $data_links; ?>"
													data-no-match="<?php echo $no_match ? 'true' : 'false'; ?>"
													data-decay="<?php echo $is_decaying ? 'true' : 'false'; ?>"
													data-orphan="<?php echo ( 0 === $incoming ) ? 'true' : 'false'; ?>">
													<td class="check-column">
														<input type="checkbox" class="sil-post-cb" value="<?php echo $post->ID; ?>">
													</td>
													<td>
														<div class="sil-article-title">
															<?php echo esc_html( $post->post_title ); ?>
															<?php if ( $is_cornerstone ) : ?>
																	<span title="Contenu Pilier" style="cursor:help;"><?php echo SIL_Icons::get_icon( 'star', [ 'size' => 14, 'color' => '#f59e0b' ] ); ?></span>
															<?php endif; ?>
															<?php
															$index_status = get_post_meta( $post->ID, '_sil_gsc_index_status', true );
															if ( ! empty( $index_status ) ) {
																$lower_status = mb_strtolower( $index_status, 'UTF-8' );
																$is_indexed   = ( false !== strpos( $lower_status, 'indexée' ) || false !== strpos( $lower_status, 'indexed' ) )
																	&& false === strpos( $lower_status, 'non index' )
																	&& false === strpos( $lower_status, 'not index' );

																if ( $is_indexed ) {
																	echo '<span title="Indexée" style="cursor:help; margin-left:4px;">🟢</span>';
																} else {
																	echo '<span title="Erreur : ' . esc_attr( $index_status ) . '" style="cursor:help; margin-left:4px;">🔴</span>';
																}
															}
															?>
															<?php if ( $no_match ) : ?>
<span
																		title="Alerte : Impossible de trouver une de vos top requêtes GSC dans le texte. L'article se désynchronise de l'intention utilisateur."
																		style="cursor:help;" class="sil-no-match-icon">⚠️</span><?php endif; ?>
															<?php if ( $is_decaying ) : ?>
<span
																		title="Content Decay : Perte de rendement par rapport à l'année dernière. Chute des clics et des positions."
																		style="cursor:help;">📉</span><?php endif; ?>
															<?php
															$text       = wp_strip_all_tags( $post->post_content );
															$word_count = count( preg_split( '~[^\p{L}\p{N}\']+~u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
															?>
															<span class="sil-word-count"
																style="font-size:11px; margin-left:8px; color:#94a3b8; font-weight:normal;"
																title="Mots calculés par le plugin">(<?php echo $word_count; ?> mots)</span>
														</div>
														<div class="sil-article-meta">
															<a href="<?php echo admin_url( 'post.php?post=' . intval( $post->ID ) . '&action=edit' ); ?>" target="_blank">Modifier</a>
															·
															<a href="<?php echo get_permalink( $post->ID ); ?>" target="_blank">Voir</a>
															<?php if ( $no_match ) : ?>
																	· <a href="#" class="sil-reset-no-match"
																		data-post-id="<?php echo $post->ID; ?>">Réinitialiser</a>
															<?php endif; ?>
														</div>
													</td>
													<td><span class="sil-badge sil-badge-neutral"><?php echo $post_type_label; ?></span></td>
													<td><span class="sil-badge <?php echo $out_class; ?>"><?php echo $outgoing; ?></span></td>
													<td><span class="sil-badge <?php echo $in_class; ?>"><?php echo $incoming; ?></span></td>
													<td><?php echo get_the_date( 'd/m/Y', $post->ID ); ?></td>
													<td>
														<div class="sil-actions">
															<button class="sil-btn sil-btn-secondary sil-btn-sm sil-preview-btn"
																data-post-id="<?php echo $post->ID; ?>">Prévisualiser</button>
															<?php if ( 0 === $incoming ) : ?>
																	<button class="sil-btn sil-btn-danger sil-btn-sm sil-adopt-btn"
																		data-post-id="<?php echo $post->ID; ?>">Adopter</button>
															<?php else : ?>
																	<button class="sil-btn sil-btn-primary sil-btn-sm sil-apply-btn"
																		data-post-id="<?php echo $post->ID; ?>">Appliquer</button>
															<?php endif; ?>
														</div>
													</td>
												</tr>
												<tr class="sil-preview-row" data-post-id="<?php echo $post->ID; ?>" style="display:none;">
													<td colspan="7">
														<div class="sil-preview-content"></div>
													</td>
												</tr>
										<?php
									endforeach;
endif;
								?>
							</tbody>
						</table>
					</div>

					<?php if ( $total_pages > 1 ) : ?>
							<div class="sil-pagination">
								<span class="sil-pagination-info">Page <?php echo $current; ?> sur <?php echo $total_pages; ?></span>
								<?php if ( $current > 1 ) : ?>
										<a href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>">« Première</a>
										<a href="<?php echo esc_url( add_query_arg( 'paged', $current - 1 ) ); ?>">‹ Précédente</a>
								<?php endif; ?>

								<?php for ( $i = max( 1, $current - 2 ); $i <= min( $total_pages, $current + 2 ); $i++ ) : ?>
										<?php if ( $i === $current ) : ?>
												<span class="current"><?php echo $i; ?></span>
										<?php else : ?>
												<a href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>"><?php echo $i; ?></a>
										<?php endif; ?>
								<?php endfor; ?>

								<?php if ( $current < $total_pages ) : ?>
										<a href="<?php echo esc_url( add_query_arg( 'paged', $current + 1 ) ); ?>">Suivante ›</a>
										<a href="<?php echo esc_url( add_query_arg( 'paged', $total_pages ) ); ?>">Dernière »</a>
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

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
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
					$.post(ajaxurl, { action: 'sil_run_system_diagnostic', nonce: '<?php echo wp_create_nonce( "sil_nonce" ); ?>' }, function(r) {
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
						<?php settings_fields( 'sil_settings' ); ?>
						<table class="form-table">
							<tr>
								<th><label for="sil_openai_api_key">Clé API OpenAI</label></th>
								<td>
									<input type="password" id="sil_openai_api_key" name="sil_openai_api_key" value="<?php echo esc_attr( get_option( 'sil_openai_api_key' ) ); ?>" class="regular-text">
									<p class="description">Indispensable pour calculer les embeddings et générer les ponts sémantiques.</p>
								</td>
							</tr>
							<tr>
								<th><label for="sil_openai_model">Modèle OpenAI</label></th>
								<td>
									<select name="sil_openai_model" id="sil_openai_model" onchange="document.getElementById('sil_custom_model_row').style.display = this.value === 'custom' ? 'table-row' : 'none';">
										<option value="gpt-4o" <?php selected( get_option( 'sil_openai_model', 'gpt-4o' ), 'gpt-4o' ); ?>>GPT-4o (Recommandé - Qualité)</option>
										<option value="gpt-4o-mini" <?php selected( get_option( 'sil_openai_model' ), 'gpt-4o-mini' ); ?>>GPT-4o Mini (Plus rapide & moins cher)</option>
										<option value="gpt-3.5-turbo" <?php selected( get_option( 'sil_openai_model' ), 'gpt-3.5-turbo' ); ?>>GPT-3.5 Turbo (Ancien modèle)</option>
										<option value="custom" <?php selected( get_option( 'sil_openai_model' ), 'custom' ); ?>>Modèle personnalisé...</option>
									</select>
								</td>
							</tr>
							<tr id="sil_custom_model_row" style="<?php echo get_option( 'sil_openai_model' ) === 'custom' ? '' : 'display:none;'; ?>">
								<th><label for="sil_openai_custom_model">Modèle personnalisé</label></th>
								<td>
									<input type="text" id="sil_openai_custom_model" name="sil_openai_custom_model" value="<?php echo esc_attr( get_option( 'sil_openai_custom_model' ) ); ?>" class="regular-text">
								</td>
							</tr>
							<tr>
								<th><label for="sil_openai_seo_prompt">Prompt IA (Titre & Meta)</label></th>
								<td>
									<textarea id="sil_openai_seo_prompt" name="sil_openai_seo_prompt" class="large-text" rows="4"><?php echo function_exists( 'esc_textarea' ) ? esc_textarea( get_option( 'sil_openai_seo_prompt' ) ) : esc_html( get_option( 'sil_openai_seo_prompt' ) ); ?></textarea>
									<p class="description">Prompt système utilisé pour la réécriture des Titles et Meta-Descriptions depuis le graphe.</p>
								</td>
							</tr>
							<tr>
								<th><label for="sil_openai_bridge_prompt">Prompt IA (Pont Sémantique)</label></th>
								<td>
									<textarea id="sil_openai_bridge_prompt" name="sil_openai_bridge_prompt" class="large-text" rows="4"><?php echo function_exists( 'esc_textarea' ) ? esc_textarea( get_option( 'sil_openai_bridge_prompt' ) ) : esc_html( get_option( 'sil_openai_bridge_prompt' ) ); ?></textarea>
									<p class="description">
										Prompt système utilisé pour l'invention d'ancres et la rédaction de ponts sémantiques.<br />
										<b>Variables disponibles :</b> <code>{{link}}</code> (lien complet), <code>{{anchor}}</code>, <code>{{url}}</code>, <code>{{source_title}}</code>, <code>{{target_title}}</code>.
									</p>
								</td>
							</tr>
							<tr>
								<th><label for="sil_similarity_threshold">Seuil de Similarité Sémantique</label></th>
								<td>
									<input type="number" id="sil_similarity_threshold" name="sil_similarity_threshold" value="<?php echo esc_attr( get_option( 'sil_similarity_threshold', 0.3 ) ); ?>" min="0.1" max="0.9" step="0.05" class="small-text">
									<p class="description">0.3 recommandé. Plus le seuil est haut, plus le maillage est strict.</p>
								</td>
							</tr>
							<tr>
								<th><label for="sil_target_permeability">Perméabilité des Cocons (Cible %)</label></th>
								<td>
									<input type="range" id="sil_target_permeability" name="sil_target_permeability" value="<?php echo esc_attr( get_option( 'sil_target_permeability', 20 ) ); ?>" min="0" max="40" step="5" oninput="this.nextElementSibling.innerText = this.value + '%'">
									<span style="font-weight:bold; margin-left:10px;"><?php echo esc_html( get_option( 'sil_target_permeability', 20 ) ); ?>%</span>
									<p class="description">Pourcentage idéal de liens sortants d'un cocon vers d'autres cocons (Ratio de Diffusion).</p>
								</td>
							</tr>
							<tr>
								<th><label for="sil_semantic_k">Nombre de Cocons Sémantiques (k)</label></th>
								<td>
									<input type="number" id="sil_semantic_k" name="sil_semantic_k" value="<?php echo esc_attr( get_option( 'sil_semantic_k', 6 ) ); ?>" min="2" max="20" class="small-text">
									<p class="description">Le nombre de grappes (clusters) que l'IA va tenter d'isoler. 6 par défaut.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Répulsion des bulles (Physique)</th>
								<td>
									<input type="number" name="sil_node_repulsion" value="<?php echo esc_attr( get_option( 'sil_node_repulsion', 300000 ) ); ?>" class="regular-text" />
									<p class="description">Défaut: 300000 (Optimisé pour ~50 contenus). Augmentez pour éloigner les bulles les unes des autres.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Espacement des silos (Physique)</th>
								<td>
									<input type="number" name="sil_component_spacing" value="<?php echo esc_attr( get_option( 'sil_component_spacing', 60 ) ); ?>" class="regular-text" />
									<p class="description">Défaut: 60. Distance minimale entre des groupes d'articles non reliés.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Gravité centrale (Physique)</th>
								<td>
									<input type="number" step="0.1" name="sil_gravity" value="<?php echo esc_attr( get_option( 'sil_gravity', 2.0 ) ); ?>" class="regular-text" />
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
										<input type="checkbox" name="sil_exclude_noindex" value="1" <?php checked( get_option( 'sil_exclude_noindex' ), '1' ); ?>> Ne pas mailler les pages en <code>noindex</code>
									</label>
									<label>
										<input type="checkbox" name="sil_auto_link" value="1" <?php checked( get_option( 'sil_auto_link' ), '1' ); ?>> Indexer automatiquement à la publication
									</label>
								</td>
							</tr>

						</table>
						<div style="margin-top:20px; border-top:1px solid #eee; padding-top:20px;">
							<?php submit_button( '💾 Enregistrer les réglages', 'primary', 'submit', false ); ?>
						</div>
					</form>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Calcule le score de santé global du maillage (0-100)
	 */
	public function calculate_health_score() {
		global $wpdb;

		// Total published posts supported by SIL
		$total_posts = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND post_status = 'publish'",
			$this->plugin->post_types[0],
			$this->plugin->post_types[1]
		) );

		if ( 0 === $total_posts ) {
			return 0;
		}

		// Part 1: % of posts linked at least once (60%)
		$linked_posts_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT target_id) FROM {$wpdb->prefix}sil_links" );
		$linked_rate        = ( $linked_posts_count / $total_posts ) * 100;
		$score_part1        = $linked_rate * 0.6;

		// Part 2: Link density (40%)
		// Goal: average 2 links created per post
		$total_links = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$wpdb->prefix}sil_links" );
		$ratio       = $total_links / $total_posts;
		if ( $ratio > 2 ) {
			$ratio = 2;
		}
		$score_part2 = ( $ratio / 2 ) * 100 * 0.4;

		return min( 100, round( $score_part1 + $score_part2 ) );
	}

	/**
	 * Render the interactive mapping page.
	 */
	public function render_cartographie_page() {
		global $wpdb;
		$unique_clusters = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sil_cluster_id'" );
		if ( empty( $unique_clusters ) ) {
			$unique_clusters = range( 1, 5 );
		}
		?>
		<div class="wrap">
			<div class="sil-header">
				<h1>🗺️ Cartographie Interactive du Maillage</h1>
				<div class="sil-graph-toolbar" style="flex-wrap: wrap;">
					<div style="flex: 1 1 100%; display: flex; gap: 15px; align-items: center; margin-bottom: 12px;">
						<select id="sil-silo-filter">
							<option value="all">Tous les cocons (Vue globale)</option>
							<?php foreach ( $unique_clusters as $cluster_id ) : ?>
								<option value="<?php echo esc_attr( $cluster_id ); ?>">Silo <?php echo esc_html( $cluster_id ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="text" id="sil-node-search" list="sil-node-list" placeholder="🔍 Rechercher un article..." style="width: 250px;">
						<datalist id="sil-node-list"></datalist>
					</div>
					<div style="flex: 1 1 100%; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
						<button id="sil-refresh-graph" class="button" title="Force le recalcul complet (3h cache)">🔄 Rafraîchir l'Analyse</button>
						<span id="sil-last-update-hint" style="font-size: 11px; color: #64748b; margin-left: 5px;">(Cache: 3h)</span>
						<button id="sil-center-graph" class="button"><?php echo SIL_Icons::get_icon( 'target', [ 'size' => 16 ] ); ?> Centrer</button>
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
			if ( $health_score > 40 ) {
				$health_color = '#dba617';
			}
			if ( $health_score > 75 ) {
				$health_color = '#198754';
			}
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
			<div id="sil-graph-wrapper" style="position:relative; height:700px; background:#fff; border:1px solid #ccd0d4; border-radius:8px; overflow:hidden;">
				<div id="sil-graph-container" style="width:100%; height:100%;"></div>
				
				<!-- Sidebar de détails -->
				<div id="sil-graph-sidebar" class="sil-graph-sidebar" style="display:none; position:absolute; top:10px; right:10px; bottom:10px; width:380px; z-index:1000; background:#fff; border-radius:8px; box-shadow:-5px 0 25px rgba(0,0,0,0.15); border:1px solid #e2e8f0; overflow:hidden; flex-direction:column;">
					<div class="sidebar-header" style="display:flex; justify-content:space-between; align-items:center; padding:15px; background:#f8fafc; border-bottom:1px solid #e2e8f0; flex:0 0 auto;">
						<h3 style="margin:0; font-size:16px; color:#1e293b;">Détails de l'article</h3>
						<button id="sil-close-sidebar" class="sil-close-btn" style="background:none; border:none; font-size:24px; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
					</div>
					<div class="sidebar-body" id="sil-sidebar-content" style="flex:1; padding:20px; overflow-y:auto; min-height:0;">
						<!-- Dynamiquement rempli par graph.js -->
					</div>
				</div>

				<!-- Loader / Status -->
				<div id="sil-graph-loading" style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); z-index:100; text-align:center; background: rgba(255,255,255,0.8); padding: 20px; border-radius: 8px;">
					<div class="spinner is-active" style="float:none; margin:0 auto 10px auto;"></div>
					<div id="sil-graph-status-text" style="font-weight: 500; color: #1e293b;">Initialisation du moteur...</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the GSC settings page.
	 */
	public function render_gsc_settings_page() {
		global $wpdb;
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
						<?php settings_fields( 'sil_gsc_settings' ); ?>

						<h2 style="font-size: 1.2em; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 30px;">
							Configuration Google Cloud</h2>

						<table class="form-table sil-settings-table">
							<tr>
								<th><label for="sil_gsc_property_url">Propriété GSC (URL)</label></th>
								<td>
									<input type="url" id="sil_gsc_property_url" name="sil_gsc_property_url"
										value="<?php echo esc_attr( get_option( 'sil_gsc_property_url' ) ); ?>"
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
										value="<?php echo esc_attr( get_option( 'sil_gsc_client_id' ) ); ?>"
										class="regular-text" placeholder="ex: 123456789-abcdef.apps.googleusercontent.com">
								</td>
							</tr>
							<tr>
								<th><label for="sil_gsc_client_secret">Client Secret (OAuth 2.0)</label></th>
								<td>
									<input type="password" id="sil_gsc_client_secret" name="sil_gsc_client_secret"
										value="<?php echo esc_attr( get_option( 'sil_gsc_client_secret' ) ); ?>"
										class="regular-text" placeholder="ex: GOCSPX-123456789">
								</td>
							</tr>
							<tr>
								<th>Statut de connexion GSC</th>
								<td>
									<?php
									$tokens = get_option( 'sil_gsc_oauth_tokens' );

									// Vérifier si Client ID et Secret sont sauvegardés (nécessaire pour afficher le bouton)
									$has_credentials = ! empty( get_option( 'sil_gsc_client_id' ) ) && ! empty( get_option( 'sil_gsc_client_secret' ) );

									if ( ! empty( $tokens ) && isset( $tokens['refresh_token'] ) ) :
										?>
											<span style="color: green; font-weight: bold;">✅ Connecté à Google Search Console</span>
											<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=sil_gsc_oauth_disconnect&_wpnonce=' . wp_create_nonce( 'sil_gsc_disconnect' ) ) ); ?>"
												class="button button-small" style="margin-left: 10px;">Déconnecter</a>
									<?php else : ?>
											<span style="color: #ea580c; font-weight: bold;">❌ Non connecté</span>
											<?php if ( $has_credentials ) : ?>
													<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=sil_gsc_oauth_redirect&nonce=' . wp_create_nonce( 'sil_nonce' ) ) ); ?>"
														class="button button-primary" style="margin-left: 10px;">Se connecter à Google
														Search Console</a>
											<?php else : ?>
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
										<input type="checkbox" name="sil_gsc_auto_sync" value="1" <?php checked( get_option( 'sil_gsc_auto_sync' ), '1' ); ?>>
										Activer la synchronisation automatique quotidienne
									</label>
									<p class="description">Si désactivée, vous devrez cliquer sur "Force GSC Sync Now" pour
										actualiser les données.</p>
								</td>
							</tr>
						</table>

						<?php submit_button( 'Sauvegarder les Réglages GSC', 'primary', 'submit', true, [ 'style' => 'margin-top:20px;' ] ); ?>
					</form>

					<div style="margin-top: 25px; border-top: 1px solid #ddd; padding-top: 15px;">
						<button type="button" id="sil-force-gsc-sync" class="button button-secondary">
							Force GSC Sync Now
						</button>
						<span id="sil-gsc-sync-status" style="margin-left: 10px; font-weight: bold;"></span>
						<?php
						$last_sync = get_option( 'sil_gsc_last_sync' );
						if ( $last_sync ) {
							echo '<p class="description" style="display:inline-block; margin-left: 10px;">Dernière synchronisation : ' . esc_html( $last_sync ) . '</p>';
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
							$diagnostic_posts = get_posts( [
								'post_type'   => [ 'post', 'page' ],
								'post_status' => 'publish',
								'numberposts' => 10,
								'meta_query'  => [
									[
										'key'     => '_sil_gsc_data',
										'compare' => 'EXISTS',
									],
								],
								'orderby'     => 'post_modified',
								'order'       => 'DESC',
							] );

							if ( ! empty( $diagnostic_posts ) ) :
								foreach ( $diagnostic_posts as $post ) :
									if ( ! $post instanceof WP_Post ) {
										continue;
									}
									$gsc_json = get_post_meta( $post->ID, '_sil_gsc_data', true );
									$gsc_data = ! empty( $gsc_json ) && is_string( $gsc_json ) ? json_decode( $gsc_json, true ) : [];
									$queries  = isset( $gsc_data['top_queries'] ) ? $gsc_data['top_queries'] : [];
									?>
											<tr>
												<td>
													<a href="<?php echo admin_url( 'post.php?post=' . intval( $post->ID ) . '&action=edit' ); ?>" target="_blank">
														<strong><?php echo esc_html( $post->post_title ); ?></strong>
													</a><br>
													<small style="color: #666;">ID: <?php echo $post->ID; ?></small>
												</td>
												<td>
													<?php if ( ! empty( $queries ) ) : ?>
															<ol style="margin: 0; padding-left: 20px;">
																<?php foreach ( array_slice( $queries, 0, 5 ) as $query_row ) : ?>
																		<li>
																			<?php
																			$raw_keyword = isset( $query_row['query'] ) ? $query_row['query'] : ( isset( $query_row['keys'][0] ) ? $query_row['keys'][0] : 'Aucun mot-clé' );
																			// Capture les uXXXX avec ou sans antislash
																			$keyword = preg_replace_callback( '/(?:\\\\+)?u([0-9a-fA-F]{4})/', function ( $match ) {
																				return mb_convert_encoding( pack( 'H*', $match[1] ), 'UTF-8', 'UCS-2BE' );
																			}, $raw_keyword );
																			$keyword = wp_specialchars_decode( $keyword, ENT_QUOTES );
																			$clicks  = isset( $query_row['clicks'] ) ? intval( $query_row['clicks'] ) : 0;
																			echo esc_html( $keyword );
																			?>
																			<span style="color:#999; font-size:11px;">(Clics:
																				<?php echo $clicks; ?>)</span>
																		</li>
																<?php endforeach; ?>
															</ol>
													<?php else : ?>
															<span style="color: #ea580c;">Aucune requête (GSC n'a rien trouvé pour cette
																page)</span>
													<?php endif; ?>
												</td>
											</tr>
									<?php
								endforeach;
							else :
								?>
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
								<?php echo esc_url( admin_url( 'admin-ajax.php?action=sil_gsc_oauth_callback' ) ); ?>
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
		</div>
		<?php
	}

	/**
	 * Render the Content Gap page.
	 */
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
					<div style="padding:10px; background:#f0f0f1; border-bottom:1px solid #ccd0d4; font-weight:bold;">🕳️ Opportunités (Position > 40)</div>
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

	/**
	 * Render the Dashboard Orphan Widget.
	 */
	public function render_orphan_widget() {
		global $wpdb;

		// Fresh count and list
		$orphans = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts} 
			WHERE post_type = 'post' 
			AND post_status = 'publish' 
			AND ID NOT IN (SELECT DISTINCT target_id FROM {$wpdb->prefix}sil_links)
			ORDER BY post_date DESC
			LIMIT 5"
		) );

		$orphan_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(ID) FROM {$wpdb->posts} 
			WHERE post_type = 'post' 
			AND post_status = 'publish' 
			AND ID NOT IN (SELECT DISTINCT target_id FROM {$wpdb->prefix}sil_links)"
		) );
		
		set_transient( 'sil_orphan_count', $orphan_count, 12 * HOUR_IN_SECONDS );

		?>
		<div class="sil-dashboard-widget">
			<div style="display: flex; align-items: center; justify-content: space-between; padding: 15px; border-bottom: 1px solid #f0f0f1; background:#f8fafc;">
				<div style="font-weight: 500; color: #1e293b;">⚡ Articles Déconnectés</div>
				<span class="sil-badge-danger" style="background:#fecaca; color:#b91c1c; padding:2px 8px; border-radius:12px; font-weight:bold; font-size:11px;">
					<?php echo $orphan_count; ?> total
				</span>
			</div>
			
			<div class="sil-widget-body" style="padding: 10px;">
				<?php if ( empty( $orphans ) ) : ?>
					<div style="padding:20px; text-align:center; color:#10b981;">
						✅ Aucun orphelin détecté ! Votre maillage est sain.
					</div>
				<?php else : ?>
					<table style="width:100%; border-collapse: collapse;">
						<?php foreach ( $orphans as $o ) : ?>
							<tr style="border-bottom: 1px solid #f1f5f9;">
								<td style="padding:10px 0; font-size:13px;">
									<div style="font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px;" title="<?php echo esc_attr($o->post_title); ?>">
										<?php echo esc_html( $o->post_title ); ?>
									</div>
								</td>
								<td style="text-align:right; padding:10px 0;">
									<button class="button button-small sil-adopt-btn" style="background:#fee2e2; color:#b91c1c; border-color:#fecaca;" data-post-id="<?php echo $o->ID; ?>">Adopter</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>
			</div>

			<p style="text-align: center; border-top: 1px solid #f0f0f1; padding: 10px; margin: 0; background:#f8fafc;">
				<a href="<?php echo admin_url( 'admin.php?page=smart-internal-links' ); ?>" style="font-size:12px; text-decoration:none; color:var(--sil-primary);">
					Gérer tout dans SIL →
				</a>
			</p>
		</div>
		<style>
			#sil_orphan_widget .inside {
				padding: 0 !important;
				margin: 0 !important;
			}
			.sil-dashboard-widget {
				background: #fff;
			}
			.sil-dashboard-widget tr:last-child { border-bottom: none; }
		</style>
		<?php
	}

	/**
	 * Render the Cornerstone meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_cornerstone_box( $post ) {
		$is_cornerstone = get_post_meta( $post->ID, '_sil_is_cornerstone', true );
		$index_status   = get_post_meta( $post->ID, '_sil_gsc_index_status', true );
		if ( empty( $index_status ) ) {
			$index_status = 'Inconnu';
		}

		wp_nonce_field( 'sil_cornerstone_save', 'sil_cornerstone_nonce' );
		?>
		<div style="margin-top: 10px;">
			<label>
				<input type="checkbox" name="sil_is_cornerstone" value="1" <?php checked( $is_cornerstone, '1' ); ?> />
				<strong>Contenu Pilier (Cornerstone)</strong>
			</label>
			<p class="description" style="margin-top:5px;">
				Cochez cette case si cette page est stratégique. Elle sera mise en avant dans les suggestions de maillage.
			</p>
		</div>

		<hr style="margin: 15px 0;">
		<?php
	}
	/**
	 * Render the "Pilotage" (Command Center) page. v2.4
	 */
	public function render_pilotage_page() {
		// Scripts & styles are enqueued in SmartInternalLinks::enqueue_admin_scripts()
		// with the correct ajaxurl + nonce. No duplicate localize here.

		// Data fetching
		global $wpdb;
		$post_types = $this->plugin->post_types;
		$types_sql = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";

		$total_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type IN ($types_sql) AND post_status='publish'" );
		
		// IA Coverage: count unique posts in embeddings table that still EXIST and are published
		$indexed_posts = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT e.post_id) FROM {$this->plugin->table_name} e 
			JOIN $wpdb->posts p ON e.post_id = p.ID 
			WHERE p.post_type IN ($types_sql) AND p.post_status='publish'" );
		$index_rate = $total_posts > 0 ? min(100, round( ( $indexed_posts / $total_posts ) * 100 )) : 0;
		$index_class = $index_rate < 90 ? 'status-warn' : 'status-ok';

		// Orphan Density
		$orphan_count = (int) $wpdb->get_var( "SELECT COUNT(p.ID) FROM $wpdb->posts p 
			LEFT JOIN {$wpdb->prefix}sil_links l ON p.ID = l.target_id 
			WHERE p.post_type IN ($types_sql) AND p.post_status='publish' AND l.target_id IS NULL" );
		$orphan_density = $total_posts > 0 ? round( ( $orphan_count / $total_posts ) * 100 ) : 0;
		$orphan_class = $orphan_density > 15 ? 'status-crit' : ($orphan_density > 5 ? 'status-warn' : 'status-ok');

		?>
		<div class="sil-pilot-wrap">
			<header class="sil-pilot-header">
				<div class="sil-pilot-title">
					<h1>Command Center 💎 <span class="badge-beta">v2.5.2</span></h1>
					<p>Pilotez votre autorité sémantique et mesurez votre ROI.</p>
				</div>
				<div class="sil-pilot-tabs">
					<button class="pilot-tab-btn active" data-tab="dashboard">Tableau de Bord</button>
					<button class="pilot-tab-btn" data-tab="journal">Journal d'Action</button>
					<button class="pilot-tab-btn" data-tab="incubator">🌱 Incubateur</button>
					<button class="pilot-tab-btn" data-tab="diagnosis">Diagnostic 🛡️</button>
				</div>
				<div class="sil-pilot-controls">
					<button class="sil-btn-glass refresh-all"><span class="dashicons dashicons-update"></span> Synchro Totale</button>
				</div>
			</header>

			<div id="pilot-tab-dashboard" class="pilot-tab-content active">

			<div class="sil-pulse-grid">
				<div class="glass-card kpi-card <?php echo $index_class; ?>">
					<div class="kpi-icon index-icon">✨</div>
					<div class="kpi-value"><?php echo $index_rate; ?>%</div>
					<div class="kpi-label">Couverture IA (Embeddings)</div>
				</div>
				<div class="glass-card kpi-card <?php echo $orphan_class; ?>">
					<div class="kpi-icon orphan-icon">🕯️</div>
					<div class="kpi-value"><?php echo $orphan_density; ?>%</div>
					<div class="kpi-label">Densité Orphelines</div>
				</div>
				<div class="glass-card kpi-card">
					<div class="kpi-icon momentum-icon">🚀</div>
					<div class="kpi-value">+2.4</div>
					<div class="kpi-label">Momentum GSC (Moy)</div>
				</div>
				<div class="glass-card kpi-card">
					<div class="kpi-icon score-icon">💎</div>
					<div class="kpi-value">A-</div>
					<div class="kpi-label">Score d'Étanchéité Silo</div>
				</div>
			</div>

			<div class="sil-action-grid">
				<div class="glass-card action-card large">
					<h3>💡 Les Oubliés (Priorité Adoption)</h3>
					<p class="pilot-card-desc">Pages publiées sans lien interne, avec un fort potentiel d'impressions GSC.</p>
					<div class="action-list" id="sil-orphan-list">
						<p class="empty-state">Recherche d'orphelins à haut potentiel...</p>
					</div>
					<button class="sil-btn-primary">Gérer les Orphelins →</button>
				</div>
				<div class="glass-card action-card large">
					<h3>🎯 Boosters de Trafic (Striking Distance)</h3>
					<p class="pilot-card-desc">Keywords en position 6-15 : prêts à bondir en 1ère page via un maillage renforcé.</p>
					<div class="action-list" id="sil-booster-list">
						<p class="empty-state">Analyse des opportunités GSC...</p>
					</div>
					<button class="sil-btn-secondary">Gérer les Gaps →</button>
				</div>
			</div>

			<div class="sil-insight-grid">
				<div class="glass-card insight-card pointer-action" data-target="<?php echo admin_url( 'admin.php?page=sil-cartographie' ); ?>">
					<h4>⚠️ Fuites Sémantiques</h4>
					<div class="insight-value">12</div>
					<div class="insight-status warn">Action requise (Carto)</div>
				</div>
				<div class="glass-card insight-card">
					<h4>📢 Top Mégaphones</h4>
					<div class="insight-value">3</div>
					<div class="insight-status ok">Articles Piliers</div>
				</div>
				<div class="glass-card insight-card pointer-action" data-target="<?php echo admin_url( 'admin.php?page=sil-stats' ); ?>">
					<h4>📉 Content Decay</h4>
					<div class="insight-value">5</div>
					<div class="insight-status crit">Critique (Stats)</div>
				</div>
			</div>
			</div><!-- /#pilot-tab-dashboard -->

			<div id="pilot-tab-journal" class="pilot-tab-content">
				<div class="glass-card journal-controls">
					<h3>📝 Journal de Bord Stratégique</h3>
					<div class="manual-log-form">
						<div class="form-row">
							<input type="text" id="manual-post-search" placeholder="Chercher un article..." autocomplete="off">
							<select id="manual-action-type">
								<option value="rewrite">Réécriture IA (Deepsearch)</option>
								<option value="optimization">Optimisation Manuelle</option>
								<option value="audit">Audit Sémantique</option>
								<option value="other">Autre Action</option>
							</select>
							<button id="submit-manual-log" class="sil-btn-primary">Journaliser l'Action</button>
						</div>
						<div id="search-results-dropdown" class="glass-dropdown"></div>
						<input type="hidden" id="manual-post-id" value="">
						<textarea id="manual-action-note" placeholder="Note optionnelle sur l'hypothèse de gain (ex: +5 positions attendues)..."></textarea>
					</div>
				</div>

				<div class="glass-card journal-history">
					<h3>⏳ Actions en Incubation & Historique ROI</h3>
					<div id="sil-action-log-list">
						<p class="empty-state">Chargement du journal...</p>
					</div>
				</div>
			</div><!-- #pilot-tab-journal -->

			<div id="pilot-tab-incubator" class="pilot-tab-content">
				<div class="glass-card journal-controls">
					<h3>🌱 Brouillon de Maillage (Incubateur)</h3>
					<p class="pilot-card-desc">Préparez vos liens vers des articles encore en rédaction. Dès que la cible est publiée, un bouton Créer apparaîtra.</p>
					<div class="manual-log-form">
						<div class="form-row">
							<input type="text" id="inc-source-search" placeholder="Source (Publié)..." autocomplete="off" style="flex:1;">
							<input type="text" id="inc-anchor" placeholder="Ancre textuelle visée..." autocomplete="off" style="flex:1;">
							<input type="text" id="inc-target-search" placeholder="Cible (Brouillon/A venir)..." autocomplete="off" style="flex:1;">
						</div>
						<div class="form-row" style="margin-top: 10px;">
							<input type="text" id="inc-note" placeholder="Note optionnelle : contexte, emplacement de l'ancre (ex: dans l'intro, sous H2...)" autocomplete="off" style="flex:3;">
							<button id="submit-incubator" class="sil-btn-primary" style="flex:1;">Programmer</button>
						</div>
						<div id="inc-source-dropdown" class="glass-dropdown" style="left:0; width:33%;"></div>
						<div id="inc-target-dropdown" class="glass-dropdown" style="left:66%; width:33%;"></div>
						<input type="hidden" id="inc-source-id" value="">
						<input type="hidden" id="inc-target-id" value="">
					</div>
				</div>

				<div class="glass-card journal-history" style="margin-top:20px;">
					<h3>⏳ Liens en Attente de Publication</h3>
					<div id="sil-incubator-list">
						<p class="empty-state">Chargement de la file d'attente...</p>
					</div>
				</div>
			</div><!-- #pilot-tab-incubator -->

			<div id="pilot-tab-diagnosis" class="pilot-tab-content">
				<div class="glass-card">
					<h3>🛡️ Auto-Diagnostic Système (v2.5.3)</h3>
					<p>Si l'interface est bloquée, les informations ci-dessous aideront à identifier le verrou technique.</p>
					
					<div id="sil-diagnosis-report" class="diagnosis-report">
						<p class="empty-state">Lancement de l'auto-test...</p>
					</div>

					<div class="diagnosis-actions" style="margin-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 20px;">
						<button id="force-diagnosis" class="sil-btn-glass"><span class="dashicons dashicons-performance"></span> Relancer le Test</button>
					</div>
				</div>
			</div><!-- #pilot-tab-diagnosis -->
		</div>
		<style>
			@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&family=Syne:wght@700&display=swap');
		</style>
		<?php
	}
}
