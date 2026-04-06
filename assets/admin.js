(function($) {
    "use strict";
    jQuery(document).ready(function ($) {
    // --- DASHBOARD TRI-FORCE (Consolidé) ---
    window.silToast = window.silNotify;
    // Les gestionnaires de clics pour le Content Gap sont situés en bas du fichier 
    // dans la section "MODULE CONTENT GAP 2026".

    // --- 3. STRESS-TEST ---
    $(document).on('click', '#sil-run-unit-tests', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const $res = $('#sil-test-results');
        $btn.prop('disabled', true).text('⏳ Tests en cours...');
        
        $.post(silSharedData.ajaxurl, { action: 'sil_run_deep_unit_tests', nonce: silSharedData.nonce }, function(r) {
            $btn.prop('disabled', false).text('🚀 Relancer les tests');
            if (r && r.success) {
                let html = '';
                r.data.forEach(t => {
                    let colorClass = 'sil-test-error';
                    let icon = silSharedData.icons.x;
                    
                    if (t.status === true || t.status === 'success') {
                        colorClass = 'sil-test-success';
                        icon = silSharedData.icons.check;
                    } else if (t.status === 'warning') {
                        colorClass = 'sil-test-warning';
                        icon = '⚠️';
                    }

                    html += `<div class="sil-test-item ${colorClass}">
                        ${icon} <strong>${t.name}</strong><br><span class="sil-test-detail">Détail : ${t.val}</span>
                    </div>`;
                });
                $res.html(html);
            }
        });
    });

    // --- SLIDER UI ---
    $('#sil-gap-threshold').on('input', function() {
        $('#sil-gap-val').text($(this).val());
    });

    // --- 4. GLOBAL NOTIFICATION SYSTEM (Luxury UI) ---
    window.silNotify = function (message, type) {
        type = type || 'info';
        var $container = $('#sil-toast-container');
        if (!$container.length) {
            $container = $('<div id="sil-toast-container"></div>').appendTo('body');
        }

        var icon = 'ℹ️';
        if (type === 'success') icon = '✅';
        if (type === 'error') icon = '❌';
        if (type === 'warning') icon = '⚠️';

        var $toast = $('<div class="sil-toast ' + type + '">' +
            '<span>' + icon + '</span>' +
            '<div>' + message + '</div>' +
            '</div>').appendTo($container);

        setTimeout(function () {
            $toast.addClass('fade-out');
            setTimeout(function () { $toast.remove(); }, 400);
        }, 4000);
    };
    window.silToast = window.silNotify;





    // --- EXISTING LOGIC ---

    // Manual GSC Sync (Batched processing)
    $('#sil-force-gsc-sync-main, #sil-force-gsc-sync').on('click', function () {
        var $btn = $(this);
        var originalText = $btn.text();
        var $status = $('#sil-gsc-sync-status'); // If we're on the settings page

        $btn.prop('disabled', true).text('🤖 Préparation GSC...');
        if ($status.length) $status.addClass('sil-status-pending').text('Préparation...');

        // 1. Fetch the list of ALL post IDs to sync
        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_all_ids_for_gsc_sync',
            _ajax_nonce: silSharedData.nonce
        }, function (resIDs) {
            if (!resIDs.success || !resIDs.data || resIDs.data.length === 0) {
                $btn.prop('disabled', false).text(originalText);
                var errMsg = 'Aucune page à synchroniser ou erreur d\'API';
                if ($status.length) $status.addClass('sil-status-error').text(errMsg);
                else window.silNotify(errMsg, 'error');
                return;
            }

            var postIds = resIDs.data;
            var totalPages = postIds.length;
            var batchSize = 10;
            var processedPages = 0;
            var totalKeywordsSaved = 0;
            var chunks = [];

            // Chunk the IDs
            for (var i = 0; i < postIds.length; i += batchSize) {
                chunks.push(postIds.slice(i, i + batchSize));
            }

            var currentChunk = 0;

            function processNextChunk() {
                if (currentChunk >= chunks.length) {
                    // All finished
                    var successMsg = silSharedData.icons.check + ' ' + totalKeywordsSaved + ' mots-clés enregistrés pour ' + totalPages + ' pages !';
                    $btn.html(silSharedData.icons.check + ' Terminé');
                    if ($status.length) $status.removeClass('sil-status-pending').addClass('sil-status-success').html(successMsg);
                    else window.silNotify(totalKeywordsSaved + ' mots-clés enregistrés.');

                    setTimeout(function () { location.reload(); }, 2500);
                    return;
                }

                var idsToProcess = chunks[currentChunk];
                var progressText = 'Synchronisation... (' + processedPages + '/' + totalPages + ' pages)';
                $btn.text(progressText);
                if ($status.length) $status.text(progressText);

                $.post(silSharedData.ajaxurl, {
                    action: 'sil_force_gsc_sync_batch',
                    _ajax_nonce: silSharedData.nonce,
                    post_ids: idsToProcess
                }, function (chunkResponse) {
                    if (chunkResponse.success) {
                        currentChunk++;
                        processedPages += idsToProcess.length;
                        if (chunkResponse.data && chunkResponse.data.keywords_saved) {
                            totalKeywordsSaved += chunkResponse.data.keywords_saved;
                        }
                        processNextChunk(); // Recurse to next chunk
                    } else {
                        $btn.prop('disabled', false).text(originalText);
                        var chunkErrMsg = '❌ Erreur sur le lot ' + (currentChunk + 1) + ': ' + (chunkResponse.data || 'Inconnue');
                        if ($status.length) $status.addClass('sil-status-error').text(chunkErrMsg);
                        else window.silNotify(chunkErrMsg, 'error');
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text(originalText);
                    var failMsg = '❌ Erreur réseau lors du lot ' + (currentChunk + 1);
                    if ($status.length) $status.addClass('sil-status-error').text(failMsg);
                    else window.silNotify(failMsg, 'error');
                });
            }

            // Start processing the first chunk
            processNextChunk();

        }).fail(function () {
            $btn.prop('disabled', false).text(originalText);
            var initFailMsg = '❌ Erreur réseau au démarrage';
            if ($status.length) $status.addClass('sil-status-error').text(initFailMsg);
            else silToast(initFailMsg, 'error');
        });
    });

    // Rebuild Semantic Silos (Fuzzy C-Means)
    $('#sil-rebuild-semantic-silos').on('click', function () {
        var $btn = $(this);
        var originalText = $btn.text();
        var $status = $('#sil-silo-rebuild-status');

        if (!confirm('Cela va recalculer tous les cocons en fonction du sens des textes. Continuer ?')) return;

        $btn.prop('disabled', true).text('⏳ Calcul en cours...');
        $status.show().addClass('sil-status-pending').text('Calcul des centroïdes et des memberships...');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_rebuild_semantic_silos',
            nonce: silSharedData.nonce
        }, function (res) {
            $btn.prop('disabled', false).text(originalText);
            if (res.success) {
                var msg = silSharedData.icons.check + ' Silos recalculés ! ' + (res.data.count || 0) + ' articles classés, ' + (res.data.bridges || 0) + ' ponts détectés.';
                $status.removeClass('sil-status-pending').addClass('sil-status-success').html(msg);
                setTimeout(function () { location.reload(); }, 3000);
            } else {
                var errMsg = '❌ Erreur: ' + (res.data || 'Inconnue');
                $status.removeClass('sil-status-pending').addClass('sil-status-error').text(errMsg);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(originalText);
            $status.removeClass('sil-status-pending').addClass('sil-status-error').text('❌ Erreur réseau');
        });
    });

    // Refresh stats (and Graph if active)
    $('#sil-refresh-stats').on('click', function () {
        var $btn = $(this);

        // Sinon, comportement standard (stats)
        $btn.prop('disabled', true).text('⏳');
        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_stats',
            nonce: silSharedData.nonce
        }, function (r) {
            if (r.success) {
                // Compatibility for both wp_send_json and wp_send_json_success formats
                var data = r.data || r;
                $('#stat-total').text(data.total);
                $('#stat-indexed').text(data.indexed);
                $('#stat-to-index').text(data.to_index);
                $('#stat-broken-links').text(data.broken_links || 0);

                if (data.broken_links > 0) {
                    $('#card-broken-links').addClass('sil-danger');
                } else {
                    $('#card-broken-links').removeClass('sil-danger');
                }
            }
            $btn.prop('disabled', false).text('🔄');
        });
    });

    // Scanner de santé des liens
    $('#sil-scan-links').on('click', function () {
        var $btn = $(this);
        var originalText = $btn.text();

        $btn.prop('disabled', true).text('🔍 Analyse en cours...');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_check_links_health',
            nonce: silSharedData.nonce
        }, function (r) {
            if (r.success) {
                silToast('Analyse terminée ! ' + r.data.broken_found + ' nouveau(x) lien(s) cassé(s) identifié(s).', 'success');
                $('#sil-refresh-stats').click(); // Refresh counts
            } else {
                silToast('Erreur: ' + (r.data || 'Inconnue'), 'error');
            }
            $btn.prop('disabled', false).text(originalText);
        });
    });

    // Filters
    $(document).on('click', '.sil-filter-btn', function () {
        var $btn = $(this);
        var filter = $btn.data('filter');
        currentFilter = filter;

        $('.sil-filter-btn').removeClass('active');
        $btn.addClass('active');

        $('tr[data-post-id]').each(function () {
            var $row = $(this);
            var links = $row.data('links');
            var noMatch = $row.data('no-match') === true || $row.data('no-match') === 'true';
            var isDecaying = $row.data('decay') === true || $row.data('decay') === 'true';
            var $preview = $('.sil-preview-row[data-post-id="' + $row.data('post-id') + '"]');

            var show = (filter === 'all') ||
                (filter === 'no-match' && noMatch) ||
                (filter === 'decay' && isDecaying) ||
                (filter === 'orphan' && ($row.data('orphan') === true || $row.data('orphan') === 'true')) ||
                (filter === 'none' && links === 'none' && !noMatch) ||
                (filter === 'few' && links === 'few') ||
                (filter === 'good' && links === 'good');

            $row.toggle(show);
            if (!show) $preview.hide();
        });

        var count = $('tr[data-post-id]:visible').length;
        $('.sil-filter-count').text(count + ' contenu(s)');
    });

    // Reset no-match
    $(document).on('click', '.sil-reset-no-match', function (e) {
        e.preventDefault();
        var $link = $(this);
        var postId = $link.data('post-id');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_reset_no_match',
            nonce: silSharedData.nonce,
            post_id: postId
        }, function (r) {
            if (r.success) {
                var $row = $('tr[data-post-id="' + postId + '"]:first');
                $row.attr('data-no-match', 'false').data('no-match', false);
                $row.find('.sil-no-match-icon').remove();
                $link.remove();
            }
        });
    });

    // Preview single
    $(document).on('click', '.sil-preview-btn', function () {
        var $btn = $(this);
        var postId = $btn.data('post-id');
        var $row = $('tr[data-post-id="' + postId + '"]:first');
        var $preview = $('.sil-preview-row[data-post-id="' + postId + '"]');
        var $content = $preview.find('.sil-preview-content');

        $btn.prop('disabled', true).text('Analyse...');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_generate_links',
            nonce: silSharedData.nonce,
            post_id: postId,
            dry_run: 'true'
        }, function (r) {
            $btn.prop('disabled', false).text('Prévisualiser');

            if (r.success && r.links && r.links.length > 0) {
                var html = '<div class="sil-preview-box"><div class="sil-preview-title">' + silSharedData.icons.link + ' ' + r.links.length + ' lien(s) suggéré(s)</div>';
                r.links.forEach(function (link, idx) {
                    var id = 'link-' + postId + '-' + idx;

                    // Gestion affichage Cornerstone
                    const cornerstoneBadge = link.is_cornerstone ? ` <span class="sil-badge-cornerstone" title="Contenu Pilier">💎 PILLIER</span>` : '';
                    const itemClass = link.is_cornerstone ? 'sil-link-item-cornerstone' : '';

                    html += '<div class="sil-link-item ' + itemClass + '">';
                    html += '<input type="checkbox" id="' + id + '" class="sil-link-cb" checked ';
                    html += 'data-target-id="' + link.target_id + '" ';
                    html += 'data-target-url="' + escAttr(link.target_url) + '" ';
                    html += 'data-anchor="' + escAttr(link.anchor) + '" ';
                    html += 'data-paragraph-index="' + link.paragraph_index + '">';
                    html += '<label for="' + id + '">';
                    html += '<span class="sil-link-target">' + escHtml(link.target_title) + '</span>' + cornerstoneBadge + '<br>';
                    html += '<span class="sil-link-anchor">"' + escHtml(link.anchor) + '"</span>';
                    html += '</label>';
                    html += '<span class="sil-link-score">' + Math.round(link.similarity * 100) + '%</span>';
                    html += '</div>';
                });
                html += '<div class="sil-preview-actions">';
                html += '<button class="sil-btn sil-btn-primary sil-btn-sm sil-insert-selected" data-post-id="' + postId + '">Insérer les liens cochés</button>';
                html += '</div></div>';
                $content.html(html);
                $preview.show();
            } else {
                $content.html('<div class="sil-preview-box sil-warning-box">' + (r.message || 'Aucune correspondance trouvée') + '</div>');
                $preview.show();

                if (r.no_match) {
                    $row.attr('data-no-match', 'true').data('no-match', true);
                    if (!$row.find('.sil-no-match-icon').length) {
                        $row.find('.sil-article-title').append(' <span class="sil-no-match-icon">⚠️</span>');
                    }
                }
            }
        }).fail(function (xhr, status, error) {
            $btn.prop('disabled', false).text('Prévisualiser');
            var msg = 'Erreur de connexion (' + (xhr.status || status) + ')';
            if (xhr.responseText) {
                // Try to extract a fatal PHP error
                var match = xhr.responseText.match(/<b>Fatal error<\/b>:(.*?)<br/);
                if (match) msg += '<br><small>' + match[1] + '</small>';
            }
            $content.html('<div class="sil-preview-box sil-error-box">' + msg + '</div>');
            $preview.show();
        });
    });

    // Insert selected links
    $(document).on('click', '.sil-insert-selected', function () {
        var $btn = $(this);
        var postId = $btn.data('post-id');
        var $preview = $('.sil-preview-row[data-post-id="' + postId + '"]');
        var $row = $('tr[data-post-id="' + postId + '"]:first');

        var links = [];
        $preview.find('.sil-link-cb:checked').each(function () {
            links.push({
                target_id: $(this).data('target-id'),
                target_url: $(this).data('target-url'),
                anchor: $(this).data('anchor'),
                paragraph_index: $(this).data('paragraph-index')
            });
        });

        if (links.length === 0) return;

        $btn.prop('disabled', true).text('Insertion...');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_apply_selected_links',
            nonce: silSharedData.nonce,
            post_id: postId,
            links: JSON.stringify(links)
        }, function (r) {
            if (r.success && r.count > 0) {
                $btn.removeClass('sil-btn-primary').addClass('sil-btn-success').text('✓ ' + r.count + ' inséré(s)');
                $row.addClass('sil-row-done');
                $preview.find('.sil-link-cb:checked').closest('.sil-link-item').addClass('sil-link-item-success');
            } else {
                $btn.prop('disabled', false).text('Insérer les liens cochés');
                silToast(r.message || 'Erreur', 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Insérer les liens cochés');
        });
    });

    // Apply single (direct)
    $(document).on('click', '.sil-apply-btn', function () {
        var $btn = $(this);
        var postId = $btn.data('post-id');
        var $row = $('tr[data-post-id="' + postId + '"]:first');
        var $preview = $('.sil-preview-row[data-post-id="' + postId + '"]');
        var $content = $preview.find('.sil-preview-content');

        $btn.prop('disabled', true).text('...');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_generate_links',
            nonce: silSharedData.nonce,
            post_id: postId,
            dry_run: 'false'
        }, function (r) {
            if (r.success && r.links && r.links.length > 0) {
                $btn.removeClass('sil-btn-primary').addClass('sil-btn-success').text('✓ ' + r.links.length);
                $row.addClass('sil-row-done');

                var html = '<div class="sil-preview-box sil-success-box"><div class="sil-preview-title">✅ ' + r.links.length + ' lien(s) ajouté(s)</div>';
                r.links.forEach(function (link) {
                    html += '<div class="sil-link-item sil-link-item-success">';
                    html += '<span class="sil-link-target">' + escHtml(link.target_title) + '</span>';
                    html += ' → <span class="sil-link-anchor">"' + escHtml(link.anchor) + '"</span>';
                    html += '</div>';
                });
                html += '</div>';
                $content.html(html);
                $preview.show();
            } else {
                $btn.prop('disabled', false).text('Appliquer');
                $content.html('<div class="sil-preview-box sil-warning-box">' + (r.message || 'Aucun lien trouvé') + '</div>');
                $preview.show();

                if (r.no_match) {
                    $row.attr('data-no-match', 'true').data('no-match', true);
                }
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Appliquer');
        });
    });

    // Bulk select
    $('#sil-select-all').on('change', function () {
        $('tr[data-post-id]:visible .sil-post-cb').prop('checked', $(this).is(':checked'));
        updateBulkBtn();
    });

    $(document).on('change', '.sil-post-cb', updateBulkBtn);

    function updateBulkBtn() {
        var count = $('.sil-post-cb:checked').length;
        $('#sil-bulk-apply').prop('disabled', count === 0).text(
            count > 0 ? 'Appliquer à ' + count + ' contenu(s)' : 'Appliquer aux sélectionnés'
        );
    }

    // Bulk apply
    $('#sil-bulk-apply').on('click', function () {
        var $checked = $('.sil-post-cb:checked');
        var total = $checked.length;
        if (total === 0 || !confirm('Appliquer à ' + total + ' contenu(s) ?')) return;

        $(this).prop('disabled', true).text('En cours...');
        var processed = 0;

        $checked.each(function (i) {
            var postId = $(this).val();
            setTimeout(function () {
                $('.sil-apply-btn[data-post-id="' + postId + '"]').click();
                processed++;
                if (processed >= total) {
                    $('#sil-bulk-apply').text('Terminé !');
                    setTimeout(updateBulkBtn, 1500);
                }
            }, i * 1500);
        });
    });

    // Preview all filtered (Robust Queue)
    $('#sil-preview-filtered').on('click', function () {
        var $visible = $('tr[data-post-id]:visible');
        var total = $visible.length;
        if (total === 0) return;
        if (total > 20 && !confirm('Prévisualiser ' + total + ' contenus ?')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Initialisation...');

        var queue = [];
        $visible.each(function () {
            queue.push($(this).data('post-id'));
        });

        var processed = 0;
        var errors = 0;

        function processNext() {
            if (queue.length === 0) {
                $btn.prop('disabled', false).text('Prévisualiser tous');
                if (errors > 0) {
                    silToast('Terminé avec ' + errors + ' erreur(s).', 'warning');
                }
                return;
            }

            var postId = queue.shift();
            processed++;
            $btn.text('En cours (' + processed + '/' + total + ')...');

            // Trigger the individual preview button
            var $rowBtn = $('.sil-preview-btn[data-post-id="' + postId + '"]');

            // Avoid double-click if already running
            if ($rowBtn.prop('disabled')) {
                setTimeout(processNext, 100);
                return;
            }

            // Manually trigger click but capture completion via a small hack or just wait safely?
            // Better: Duplicate logic or trust atomic nature? 
            // Let's call the click and wait a safe delay, or - better - refactor preview logic to be callable.
            // For now, let's just click and wait fixed time, relying on the button's own error handling not to crash.
            $rowBtn.click();

            // Pace it slightly to avoid server kill
            setTimeout(processNext, 1500);
        }

        processNext();
    });

    // Regenerate embeddings
    $('#sil-regenerate').on('click', function () {
        if (!confirm('Indexer tout le contenu (articles + pages) ?')) return;

        var $btn = $(this);
        var $progress = $('#sil-progress');

        $btn.prop('disabled', true);
        $progress.show().find('.sil-progress-text').text('Récupération...');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_regenerate_embeddings',
            nonce: silSharedData.nonce
        }, function (r) {
            if (r.success && r.to_index && r.to_index.length > 0) {
                var toIndex = r.to_index;
                var total = toIndex.length;
                var processed = 0;
                var errors = 0;

                function processNext() {
                    if (processed >= total) {
                        $progress.find('.sil-progress-text').html('✅ Terminé ! ' + (total - errors) + '/' + total + ' indexés');
                        $btn.prop('disabled', false);
                        setTimeout(function () { location.reload(); }, 2000);
                        return;
                    }

                    $progress.find('.sil-progress-text').text('Indexation: ' + processed + '/' + total);

                    $.post(silSharedData.ajaxurl, {
                        action: 'sil_regenerate_embeddings',
                        nonce: silSharedData.nonce,
                        post_id: toIndex[processed]
                    }, function (res) {
                        if (!res.success) errors++;
                        processed++;
                        setTimeout(processNext, 300);
                    }).fail(function () {
                        errors++;
                        processed++;
                        setTimeout(processNext, 300);
                    });
                }

                processNext();
            } else {
                $progress.find('.sil-progress-text').html('✅ Tout le contenu est indexé');
                $btn.prop('disabled', false);
            }
        });
    });

    function updateDetailedPanel(node) {
        var html = '<div id="sil-lhf-warning-box" class="sil-hidden" style="margin-top:15px;">' +
            '<h4 class="sil-lhf-header">🚀 Opportunité (Low Hanging Fruit)</h4>' +
            '<p class="sil-lhf-text">Cette page a un fort potentiel (position entre 10 et 20 avec beaucoup d\'impressions) sur la requête <strong id="sil-lhf-query"></strong>.</p>' +
            '<a href="' + (typeof silSharedData !== 'undefined' ? silSharedData.admin_url : '') + '?page=smart-internal-links&target_post=' + node.id + '" id="sil-boost-lhf-btn" class="sil-btn sil-btn-primary sil-btn-sm">🔗 Booster avec des liens internes</a>' +
            '</div>' +

            // --- NEW: CANNIBALISATION WARNING ---
            '<div id="sil-cannibalisation-warning-box" class="sil-hidden sil-cannibal-box" style="margin-top:15px;">' +
            '<h4 class="sil-cannibal-header">🚨 Cannibalisation Sémantique</h4>' +
            '<p class="sil-cannibal-text">Cette page est en concurrence directe sur la requête <strong id="sil-cannibal-query"></strong> avec l\'article : <span id="sil-cannibal-rival" class="sil-rival-title"></span> (dans le même Silo).</p>' +
            '<p class="sil-cannibal-footer">Action reco : Fusionnez les contenus, pointez l\'un vers l\'autre en Canonical, ou différenciez les angles.</p>' +
            '</div>' +

            // --- NEW: GSC DATA ---
            '<div id="sil-gsc-data-box" class="sil-gsc-data-container">' +
            '</div>';

        $('.sil-panel-body').append(html);
    }


    // --- AUTO-TRIGGER GENERATION IF target_post IS PRESENT IN URL ---
    var urlParams = new URLSearchParams(window.location.search);
    var targetPostId = urlParams.get('target_post');
    if (targetPostId) {
        // Find the preview button for this post and click it
        setTimeout(function () {
            var $previewBtn = $('.sil-preview-btn[data-post-id="' + targetPostId + '"]');
            if ($previewBtn.length > 0) {
                // Un-filter everything to ensure the row is visible
                $('.sil-filter-btn[data-filter="all"]').trigger('click');

                // Scroll to the row smoothly
                $('html, body').animate({
                    scrollTop: $previewBtn.closest('tr').offset().top - 100
                }, 500);

                // Automatically trigger the semantic search
                setTimeout(function () {
                    $previewBtn.trigger('click');
                }, 600);
            }
        }, 300); // Slight delay to ensure table rendering is complete
    }
    // (Redirect to cartographie page message is handled by loadGraph if sil-graph-container is present)





    // --- HELPERS (If missing) ---
    if (typeof window.escHtml === 'undefined') {
        window.escHtml = function (text) {
            var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text ? text.replace(/[&<>"']/g, function (m) { return map[m]; }) : '';
        };
    }
    if (typeof window.escAttr === 'undefined') {
        window.escAttr = window.escHtml;
    }


    // --- ADOPTION DES ORPHELINS (Smart Picker) ---
    $(document).on('click', '.sil-adopt-btn', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const postId = $btn.data('post-id');
        
        $btn.prop('disabled', true).html('<span class="spinner is-active"></span>');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_orphan_adoption_info',
            nonce: silSharedData.admin_nonce || silSharedData.nonce,
            post_id: postId
        }, function (r) {
            $btn.prop('disabled', false).text('Adopter');
            if (r.success) {
                openAdoptionModal(postId, r.data);
            } else {
                silToast('Erreur : ' + (r.data || 'Impossible de trouver un Mégaphone.'), 'error');
            }
        });
    });

    function openAdoptionModal(orphanId, data) {
        const modalHtml = `
            <div id="sil-adopt-modal" class="sil-modal-overlay">
                <div class="sil-modal-content">
                    <div class="sil-modal-header">
                        <h3>🚀 Adoption Sémantique</h3>
                        <button class="sil-modal-close">&times;</button>
                    </div>
                    <div class="sil-modal-body">
                        <p class="sil-modal-desc">
                            L'IA a identifié l'article suivant comme le meilleur <strong>Mégaphone</strong> (Pivot) de ce silo pour accueillir un lien vers votre article orphelin.
                        </p>
                        
                        <div class="sil-megaphone-card">
                            <div class="card-icon">🏗️</div>
                            <div class="card-info">
                                <span class="card-label">Mégaphone cible :</span>
                                <span class="card-title">${data.megaphone_title}</span>
                            </div>
                        </div>

                        <div class="sil-anchor-selection">
                            <span class="section-title">Choisissez une ancre :</span>
                            <div class="anchor-options">
                                ${data.anchors.map((anchor, idx) => `
                                    <label class="anchor-option">
                                        <input type="radio" name="sil-selected-anchor" value="${escAttr(anchor)}" ${idx === 0 ? 'checked' : ''}>
                                        <span class="anchor-text">${escHtml(anchor)}</span>
                                        <span class="anchor-badge">${idx === 0 ? 'Titre' : (idx === 1 ? 'GSC' : 'IA')}</span>
                                    </label>
                                `).join('')}
                                <label class="anchor-option custom">
                                    <input type="radio" name="sil-selected-anchor" value="custom">
                                    <span class="anchor-text"><input type="text" id="sil-custom-anchor" placeholder="Personnaliser l'ancre..." disabled></span>
                                </label>
                            </div>
                        </div>
                        <div class="sil-v17-intelligence"></div>
                    </div>
                    <div class="sil-modal-footer">
                        <button class="sil-btn sil-btn-secondary sil-modal-close">Annuler</button>
                        <button id="sil-confirm-adopt" class="sil-btn sil-btn-primary" data-orphan-id="${orphanId}" data-megaphone-id="${data.megaphone_id}">🚀 Confirmer l'Adoption</button>
                        <button id="sil-bridge-adopt" class="sil-btn sil-btn-accent" data-orphan-id="${orphanId}" data-megaphone-id="${data.megaphone_id}" title="Créer une transition fluide via IA">🌉 Pont Sémantique</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);
        $('#sil-adopt-modal').fadeIn(200);

        // Events
        $(document).on('change', 'input[name="sil-selected-anchor"]', function() {
            const isCustom = $(this).val() === 'custom';
            $('#sil-custom-anchor').prop('disabled', !isCustom);
            if (isCustom) $('#sil-custom-anchor').focus();
        });

        $(document).on('click', '.sil-modal-close', function() {
            $('#sil-adopt-modal').fadeOut(200, function() { $(this).remove(); });
        });
    }

    $(document).on('click', '#sil-bridge-adopt', function() {
        const $btn = $(this);
        const orphanId = $btn.data('orphan-id');
        const megaphoneId = $btn.data('megaphone-id');
        
        let anchor = $('input[name="sil-selected-anchor"]:checked').val();
        if (anchor === 'custom') anchor = $('#sil-custom-anchor').val();
        if (!anchor || anchor === 'custom') anchor = 'Cliquez ici'; // Fallback for bridge anchor

        $('#sil-adopt-modal').remove(); // Close current modal
        
        if (window.SIL_Bridge) {
            window.SIL_Bridge.generate(megaphoneId, orphanId, anchor, $btn);
        } else {
            silToast('Bridge Manager non chargé.', 'error');
        }
    });

    $(document).on('click', '#sil-confirm-adopt', function() {
        const $btn = $(this);
        const orphanId = $btn.data('orphan-id');
        const megaphoneId = $btn.data('megaphone-id');
        let anchor = $('input[name="sil-selected-anchor"]:checked').val();

        if (anchor === 'custom') {
            anchor = $('#sil-custom-anchor').val();
        }

        if (!anchor) {
            silToast('Veuillez choisir ou saisir une ancre.', 'warning');
            return;
        }

        $btn.prop('disabled', true).text('Insertion en cours...');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_adopt_orphan',
            nonce: silSharedData.nonce,
            orphan_id: orphanId,
            megaphone_id: megaphoneId,
            anchor: anchor
        }, function(r) {
            if (r.success) {
                $btn.text('✅ Terminé !');
                setTimeout(function() {
                    $('#sil-adopt-modal').fadeOut(200, function() { 
                        $(this).remove(); 
                        location.reload(); 
                    });
                }, 1000);
            } else {
                if (!r.success) {
                    $('#sil-adopt-modal').find('.sil-modal-body').prepend('<div class="sil-error-msg">Erreur: ' + r.data + '</div>');
                    $btn.prop('disabled', false).text('Réessayer');
                }
            }
        });
    });

    // --- MODULE CONTENT GAP 2026 ---
    $('#sil-gap-threshold').on('input', function() { $('#sil-gap-val').text($(this).val()); });

    $('#sil-run-gap-analysis').on('click', function() {
        const $btn = $(this);
        const $loader = $('#sil-gap-loader');
        $btn.prop('disabled', true);
        $loader.show();
        $('#gap-striking, #gap-consolidation, #gap-true').html('<div style="text-align:center; padding:20px;">Chargement...</div>');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_content_gap',
            nonce: silSharedData.nonce,
            min_impressions: $('#sil-gap-threshold').val()
        }, function(response) {
            $btn.prop('disabled', false);
            $loader.hide();
            if (response.success) {
                renderGapTable('gap-striking', response.data.striking);
                renderGapTable('gap-consolidation', response.data.consolidation);
                renderGapTable('gap-true', response.data.true_gap);
                
                if (response.data.hasOwnProperty('silotage')) {
                    renderSilotageTable('gap-silotage', response.data.silotage);
                }
            } else {
                silToast('Erreur: ' + (response.data || 'Inconnue'), 'error');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $loader.hide();
            silToast('Erreur réseau ou serveur lors du scan.', 'error');
        });
    });

    function renderGapTable(containerId, data, btnLabel) {
        let html = '<table class="wp-list-table widefat fixed striped" style="border:none; table-layout: auto;">';
        if (data.length === 0) {
            html += '<tr><td colspan="3" style="padding:20px; color:#666; text-align:center;">Aucune donnée.</td></tr>';
        } else {
            data.forEach(item => {
                const isCannibal = item.urls.length > 1;
                const iconMap = {
                    'warning': 'Cannibalisation : Plusieurs URLs se partagent cette intention.',
                    'winner': '🥇 Page désignée comme Leader pour cette requête.',
                    'loser': '🥈 Page secondaire (à désoptimiser ou lier vers le leader).',
                    'waiting': '🕒 Période d\'observation : Google analyse vos derniers changements.'
                };
                const rowStyle = isCannibal ? 'background: #fffbeb; border-left: 4px solid #f59e0b;' : '';
                
                // On trie les URLs par position pour identifier la "forte" (gagnante) et la "faible" (perdante)
                const sortedUrls = [...item.urls].sort((a, b) => a.pos - b.pos);
                const winner = sortedUrls[0];
                const loser = isCannibal ? sortedUrls[1] : null;

                html += `<tr style="${rowStyle}">
                    <td style="padding: 12px 8px; width: 30%;">
                        <div style="font-weight:bold; font-size:14px; color:#1e293b; margin-bottom:4px;">
                            ${item.kw} 
                            ${isCannibal ? `<span class="dashicons dashicons-warning" style="color:#f59e0b; font-size:18px;" title="${iconMap.warning}"></span>` : ''}
                        </div>
                        <div style="font-size:11px; color:#64748b;">Top Imp: ${item.imp.toLocaleString()}</div>
                    </td>
                    <td style="padding: 12px 8px;">
                        <span style="font-size:10px; text-transform:uppercase; color:#94a3b8; display:block; margin-bottom:6px;">
                            ${isCannibal ? '⚠️ Pages en conflit :' : '📍 Page associée :'}
                        </span>
                        ${sortedUrls.map((u, idx) => {
                            const badge = idx === 0 && isCannibal ? `<span title="${iconMap.winner}">🥇</span>` : (isCannibal ? `<span title="${iconMap.loser}">🥈</span>` : '');
                            const waiting = u.is_waiting ? `<span style="color:#64748b; font-size:10px; background:#f1f5f9; padding:2px 5px; border-radius:3px; margin-left:5px; border:1px solid #e2e8f0;" title="${iconMap.waiting}">🕒 ${u.days_left}j</span>` : '';
                            return `<div style="margin-bottom:6px; line-height:1.2;">
                                <strong>${badge}</strong> <a href="${u.url}" target="_blank" style="text-decoration:none; font-size:11px; color:#2271b1;">${u.path}</a>
                                <span style="font-size:10px; color:#64748b; margin-left:4px;">(Pos: ${u.pos})</span> ${waiting}
                            </div>`;
                        }).join('')}
                    </td>
                    <td style="text-align:right; vertical-align: middle; width: 35%; padding: 12px 8px;">
                        <div style="display:flex; flex-direction:column; gap:5px; align-items: flex-end;">
                            ${!isCannibal && containerId === 'gap-true' ? 
                                `<button class="button button-small sil-create-gap-draft" ${item.any_waiting ? 'disabled' : ''} data-keyword="${item.kw.replace(/"/g, '&quot;')}">📝 Créer l'article</button>` : ''
                            }

                            ${isCannibal ? `
                                <button class="button button-small sil-opp-bridge" data-source="${loser.id}" data-target="${winner.id}" data-anchor="${item.kw.replace(/"/g, '&quot;')}" style="width:140px;">🌉 Créer un pont</button>
                                <a href="${loser.edit}" target="_blank" class="button button-small" style="width:140px;">✍️ Éditer (Retrait)</a>
                                <button class="button button-small sil-opp-deoptimize" data-id="${loser.id}" data-kw="${item.kw.replace(/"/g, '&quot;')}" style="width:140px; color:#b91c1c; border-color:#fca5a5;">📉 Désoptimiser (IA)</button>
                            ` : (containerId !== 'gap-true' ? `<a href="${winner.edit}" target="_blank" class="button button-small">✍️ Éditer Title/H1</a>` : '')}
                        </div>
                    </td>
                </tr>`;
            });
        }
        html += '</table>';
        html += '</table>';
        $('#' + containerId).html(html);
    }

    function renderSilotageTable(containerId, data) {
        if (!data || data.length === 0) {
            $('#' + containerId).html('<div style="padding:20px; color:#666; text-align:center;">Aucune recommandation de silotage pour le moment. Votre maillage est peut-être déjà optimal ou les clusters sont trop petits.</div>');
            return;
        }

        let html = '<table class="wp-list-table widefat fixed striped" style="border:none; table-layout: auto;">';
        data.forEach(item => {
            html += `<tr>
                <td style="padding: 12px 8px; width: 30%;">
                    <div style="font-weight:bold; font-size:14px; color:#1e293b; margin-bottom:4px;">
                        Silo #${item.cluster_id}
                    </div>
                    <div style="font-size:11px; color:#dc2626; font-weight:600;">
                        Perméabilité: ${Math.round(item.permeability)}% (Cible: ${item.target_permeability}%)
                    </div>
                </td>
                <td style="padding: 12px 8px;">
                    <span style="font-size:10px; text-transform:uppercase; color:#94a3b8; display:block; margin-bottom:6px;">
                        Action Recommandée :
                    </span>
                    <div style="font-size:12px; color:#475569;">
                        Ouvrir ce silo vers le Silo #<strong>${item.target_cluster}</strong> pour diffuser la puissance sémantique.
                    </div>
                    <div style="font-size:11px; margin-top:8px;">
                        De : <a href="${item.source_post.url}" target="_blank" style="color:#2271b1; text-decoration:none;">${item.source_post.title}</a>
                        <br>Vers : <a href="${item.target_post.url}" target="_blank" style="color:#2271b1; text-decoration:none;">${item.target_post.title}</a>
                    </div>
                </td>
                <td style="text-align:right; vertical-align: middle; width: 35%; padding: 12px 8px;">
                    <div style="display:flex; flex-direction:column; gap:5px; align-items: flex-end;">
                        <button class="button button-primary sil-gap-invent-link" 
                                data-source="${item.source_post.id}" 
                                data-target="${item.target_post.id}">
                            ✨ Inventer le lien (IA)
                        </button>
                    </div>
                </td>
            </tr>`;
        });
        html += '</table>';
        $('#' + containerId).html(html);
    }

    // --- GAP ACTIONS HANDLERS ---

    $(document).on('click', '.sil-create-gap-draft', function() {
        const $btn = $(this);
        const kw = $btn.data('keyword');
        $btn.prop('disabled', true).text('⏳...');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_create_gap_draft',
            nonce: silSharedData.nonce,
            keyword: kw
        }, function(response) {
            if (response.success) {
                $btn.text('✅ Prêt').addClass('button-disabled');
                silToast('Draft créé avec succès !', 'success');
            } else {
                silToast('Erreur: ' + response.data, 'error');
                $btn.prop('disabled', false).text('📝 Créer Article');
            }
        });
    });

    $(document).on('click', '.sil-gap-invent-link', function() {
        const $btn = $(this);
        const sourceId = $btn.data('source');
        const targetId = $btn.data('target');

        if (!confirm("L'IA va chercher le meilleur endroit dans l'article source pour insérer un lien vers la cible. Continuer ?")) return;

        $btn.prop('disabled', true).text('🤖 Invention...');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_invent_anchor_and_link',
            nonce: silSharedData.nonce,
            source_id: sourceId,
            target_id: targetId
        }, function(response) {
            if (response.success) {
                $btn.text('✅ Inséré').removeClass('button-primary').addClass('button-disabled');
                silToast(response.data, 'success');
            } else {
                silToast('Erreur: ' + response.data, 'error');
                $btn.prop('disabled', false).text('✨ Inventer le lien (IA)');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('✨ Inventer le lien (IA)');
            silToast('Erreur réseau.', 'error');
        });
    });

    // Action Désoptimisation (Remplace la Fusion)
    $(document).on('click', '.sil-opp-deoptimize', function() {
        const $btn = $(this);
        const postId = $btn.data('id');
        const kw = $btn.data('kw');
        
        $btn.prop('disabled', true).text('⏳ Analyse IA...');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_suggest_deoptimization',
            nonce: silSharedData.nonce,
            post_id: postId,
            forbidden_keyword: kw
        }, function(response) {
            $btn.prop('disabled', false).text('📉 Désoptimiser (IA)');
            if (response.success) {
                const newTitle = response.data.suggested_title;
                if (confirm("L'IA suggère ce nouveau titre pour désamorcer la cannibalisation :\n\n\"" + newTitle + "\"\n\nSouhaitez-vous appliquer ce changement immédiatement ?")) {
                    $.post(silSharedData.ajaxurl, {
                        action: 'sil_update_seo_meta',
                        nonce: silSharedData.nonce,
                        post_id: postId,
                        new_title: newTitle
                    }, function(res) {
                        if (res.success) {
                            silToast("✅ Page désoptimisée ! Le conflit sera automatiquement levé au prochain scan GSC.", 'success');
                        } else {
                            silToast("❌ Erreur : " + (res.data || "Impossible de mettre à jour le SEO."), 'error');
                        }
                    });
                }
            } else {
                silToast("❌ Erreur IA : " + (response.data || "Inconnue"), 'error');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('📉 Désoptimiser (IA)');
            silToast("❌ Erreur réseau lors de l'appel IA.", 'error');
        });
    });

    // --- PONT SÉMANTIQUE (Phase 2 BMAD) ---
    window.silFetchAndShowBridgePrompt = function(sourceId, targetId, anchor, pIndex = -1) {
        $.post(silSharedData.ajaxurl, {
            action: 'sil_generate_bridge_prompt',
            nonce: silSharedData.admin_nonce || silSharedData.nonce,
            source_id: sourceId,
            target_id: targetId,
            anchor_text: anchor,
            p_index: pIndex
        }, function(response) {
            if (response.success) {
                window.openBridgeModal(response.data);
            } else {
                silToast('Erreur: ' + (response.data || 'Impossible de générer le prompt.'), 'error');
            }
        }).fail(function() {
            // Restore UI state if it was loading
            const $refreshBtn = $('#sil-change-p-location');
            if ($refreshBtn.length) {
                $refreshBtn.text('🔄 Changer d\'emplacement').prop('disabled', false);
            }
            alert("Erreur réseau ou serveur. Impossible de générer l'emplacement.");
        });
    };

    // Action Pont Sémantique (Workflow Hybride) - Dashboard Content Gap
    $(document).on('click', '.sil-opp-bridge', function() {
        const $btn = $(this);
        const sourceId = $btn.data('source');
        const targetId = $btn.data('target');
        const anchorText = $btn.data('anchor') || 'Cliquez ici';
        
        window.silFetchAndShowBridgePrompt(sourceId, targetId, anchorText);
    });

    $(document).on('click', '.sil-local-bridge-btn', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const sourceId = $btn.data('source');
        const targetId = $btn.data('target');
        const anchor = $btn.data('anchor') || '';
        
        window.silFetchAndShowBridgePrompt(sourceId, targetId, anchor);
    });


    // Action Pont Rapide - Dashboard Intrus
    $(document).on('click', '.sil-auto-bridge-btn', function() {
        const $btn = $(this);
        const sourceId = $btn.closest('tr').data('post-id');
        const targetClusterId = $btn.data('target-cluster');
        
        // On récupère le pivot du silo cible pour créer le pont
        $btn.prop('disabled', true).text('⏳ Recherche pivot...');
        
        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_orphan_adoption_info',
            nonce: silSharedData.nonce,
            orphan_id: sourceId // On utilise l'intrus comme orphelin pour trouver la cible
        }, function(r) {
            if (r.success) {
                window.SIL_Bridge.generate(r.data.megaphone_id, sourceId, r.data.anchors[0], $btn);
            } else {
                silToast('Impossible de trouver un pivot pour ce silo.', 'error');
                $btn.prop('disabled', false).text('🌉 Pont Rapide');
            }
        });
    });

    // --- 2. AUDIT DE COHÉRENCE (DASHBOARD) ---
    $(document).on('click', '#sil-run-semantic-audit', function(e) {
        e.preventDefault();
        const $btn = $(this);
        $btn.prop('disabled', true);
        $('#sil-audit-loader').show();
        
        $.post(silSharedData.ajaxurl, { action: 'sil_run_semantic_audit', nonce: silSharedData.nonce }, function(r) {
            $btn.prop('disabled', false);
            $('#sil-audit-loader').hide();
            if(r && r.success) {
                const nodes = r.data.nodes || 0;
                const msg = `<div class="notice notice-success inline" style="margin:0;"><p>✅ <strong>Audit terminé sur ${nodes} pages !</strong> Les algorithmes ont recalculé la cohérence.<br><br>👉 <strong>Allez maintenant dans l'onglet "Cartographie"</strong> pour voir les résultats du maillage.</p></div>`;
                $('#gap-striking').html(msg);
                if ($('#sil-audit-feedback-settings').length) {
                    $('#sil-audit-feedback-settings').html(msg).fadeIn();
                }
            } else {
                silToast('Erreur : ' + (r ? r.data : 'Erreur inconnue'), 'error');
            }
        });
    });

    function renderSemanticResults(intruders) {
        const $container = $('#sil-semantic-results');
        
        if (!intruders || intruders.length === 0) {
            $container.html('<div class="notice notice-success inline"><p>✅ Félicitations ! Aucune page mal maillée n\'a été détectée. Votre cohérence sémantique est parfaite.</p></div>');
            return;
        }

        // 1. Calcul du ROI et Tri
        intruders.sort((a, b) => {
            const impressionsA = parseInt(a.impressions) || 0;
            const impressionsB = parseInt(b.impressions) || 0;
            const scoreA = parseFloat(a.score) || 0;
            const scoreB = parseFloat(b.score) || 0;
            const roiA = impressionsA * (1 - scoreA);
            const roiB = impressionsB * (1 - scoreB);
            return roiB - roiA;
        });

        let html = `
            <div style="margin-top:20px;">
                <p><strong>${intruders.length} Intrus détectés</strong>. Priorité aux pages à fort trafic (ROI).</p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Page (Intrus)</th>
                            <th>Silo Actuel</th>
                            <th>Silo Idéal</th>
                            <th>ROI Sémantique</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>`;

        intruders.forEach(item => {
            const impressions = parseInt(item.impressions) || 0;
            const score = parseFloat(item.score) || 0;
            const roiIndex = Math.round(impressions * (1 - score));
            html += `
                <tr>
                    <td><strong>${item.title}</strong></td>
                    <td><span class="badge" style="background:#e2e8f0; padding:3px 8px; border-radius:12px;">#${item.current}</span></td>
                    <td><span class="badge" style="background:#f3e8ff; color:#6b21a8; padding:3px 8px; border-radius:12px; font-weight:bold;">#${item.ideal}</span></td>
                    <td><span style="color:#a855f7; font-weight:bold;">${roiIndex.toLocaleString()} pts</span></td>
                    <td>
                        <a href="${item.edit_url || 'post.php?post=' + item.id + '&action=edit'}" class="button button-small" target="_blank">✏️ Corriger</a>
                        <button class="button button-small sil-auto-bridge-btn" data-target-cluster="${item.ideal}">🌉 Pont Rapide</button>
                    </td>
                </tr>`;
        });

        html += `</tbody></table></div>`;
        $container.html(html);
    }

    // --- 1. INDEXATION SÉMANTIQUE ---
    $(document).on('click', '#sil-start-indexing', function(e) {
        e.preventDefault();
        const $btn = $(this);
        $btn.prop('disabled', true).text('⏳ Initialisation...');
        $('#sil-indexing-progress-container').show();
        runEmbeddingBatch();
    });

    function runEmbeddingBatch() {
        const $statusText = $('#sil-indexing-status-text');
        $statusText.text('Traitement du lot en cours...');

        $.post(silSharedData.ajaxurl, { action: 'sil_index_embeddings_batch', nonce: silSharedData.nonce }, function(r) {
            if (r && r.success) {
                if (r.data.finished) {
                    $('#sil-start-indexing').text('✅ Indexation terminée').prop('disabled', false);
                    $statusText.text('Calcul terminé !');
                    $('#sil-indexing-bar').css('width', '100%');
                    updateIndexingStats(); // Final update
                } else {
                    updateIndexingStats();
                    // On ajoute un petit délai de 500ms pour laisser souffler le serveur
                    setTimeout(runEmbeddingBatch, 500);
                }
            } else {
                const errorMsg = (r && r.data) ? r.data : 'Erreur API ou Timeout';
                silToast('Erreur: ' + errorMsg, 'error');
                $('#sil-start-indexing').prop('disabled', false).text('🔍 Relancer');
                $statusText.text('Erreur lors du traitement.');
            }
        }).fail(function(xhr) {
            const status = xhr.status;
            let msg = 'Erreur réseau ou serveur (' + status + ')';
            if (status === 500) msg = 'Erreur interne du serveur (500). Vérifiez vos logs PHP.';
            if (status === 504) msg = 'Gateway Timeout (504). Le serveur est trop lent.';
            
            silToast('L\'indexation a échoué : ' + msg, 'error');
            $('#sil-start-indexing').prop('disabled', false).text('🔍 Relancer');
            $statusText.text('Processus interrompu.');
        });
    }

    function updateIndexingStats() {
        $.post(silSharedData.ajaxurl, { action: 'sil_get_indexing_status', nonce: silSharedData.nonce }, function(r) {
            if (r && r.success) {
                const p = (r.data.indexed / r.data.total) * 100;
                $('#sil-indexing-bar').css('width', p + '%');
                $('#sil-indexing-stats').text(r.data.indexed + ' / ' + r.data.total + ' contenus indexés');
            }
        });
    }

});

})(jQuery);

