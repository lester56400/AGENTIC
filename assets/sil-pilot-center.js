(function($) {
    "use strict";
    jQuery(document).ready(function($) {

    // Global Safety Check
    const isDataValid = () => typeof silSharedData !== 'undefined' && silSharedData.ajaxurl && silSharedData.nonce;

    if (!isDataValid()) {
        $('.action-list').html('<p class="empty-state">❌ Erreur de configuration (Metadata).</p>');
        return;
    }

    // --- Core Logic ---
    function init() {
        try {
            initAnimations();
            loadPilotActions();
            // Load journal only if tab active, else deferred
        } catch (e) {
        }
    }

    function initAnimations() {
        $('.glass-card').addClass('sil-staggered').each(function(index) {
            $(this).addClass('sil-staggered-' + ((index % 4) + 1));
        });
    }

    function loadPilotActions() {
        const list = $('#sil-orphan-list, #sil-booster-list');
        // Reset state
        $('#sil-orphan-list').html('<p class="empty-state">Recherche d\'orphelins...</p>');
        $('#sil-booster-list').html('<p class="empty-state">Analyse des opportunités...</p>');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_pilotage_actions',
            nonce: silSharedData.nonce
        }, function(response) {
            if (response.success) {
                renderOrphans(response.data.orphans || []);
                renderBoosters(response.data.boosters || []);
            } else {
                list.html('<p class="empty-state">❌ Erreur Serveur: ' + (response.data || 'Inconnu') + '</p>');
                window.silNotify('Erreur : ' + response.data, 'error');
            }
        }).fail(function(xhr, status, error) {
            const statusCode = xhr.status;
            let msg = 'Erreur réseau ou serveur (' + statusCode + ')';
            if (statusCode === 500) msg = 'Erreur interne du serveur (500). Vérifiez les logs PHP ou l\'allocation mémoire.';
            if (statusCode === 504) msg = 'Gateway Timeout (504). Le scan est trop lourd pour le serveur.';
            
            list.html('<p class="empty-state">❌ ' + msg + '</p>');
            window.silNotify(msg, 'error');
            console.error('SIL BMAD DEBUG:', status, error, xhr.responseText);
        });
    }

    function renderOrphans(orphans) {
        const target = $('#sil-orphan-list');
        if (orphans.length === 0) {
            target.html('<p class="empty-state">✅ Aucun orphelin critique.</p>');
            return;
        }
        let html = '<ul class="pilot-action-items">';
        orphans.forEach(o => {
            html += `<li>
                <span class="item-title">${o.title}</span>
                <span class="item-meta">${o.impressions} imps</span>
                <button class="mini-adopt sil-pilot-bridge-trigger" data-source-id="${o.id}" data-source-title="${o.title.replace(/"/g,'&quot;')}">Adopter 🚀</button>
            </li>`;
        });
        target.html(html + '</ul>');
    }

    function renderBoosters(boosters) {
        const target = $('#sil-booster-list');
        if (boosters.length === 0) {
            target.html('<p class="empty-state">Tout est dans le Top 5 !</p>');
            return;
        }
        let html = '<ul class="pilot-action-items">';
        boosters.forEach(b => {
            html += `<li>
                <span class="item-title">"${b.kw}"</span>
                <span class="item-meta">Pos ${b.pos} | ${b.impressions} imps</span>
                <button class="mini-bridge sil-pilot-bridge-trigger" data-source-id="${b.post_id}" data-source-title="${b.title.replace(/"/g,'&quot;')}" data-anchor="${b.kw.replace(/"/g,'&quot;')}">Booster 🎯</button>
            </li>`;
        });
        target.html(html + '</ul>');
    }

    // --- Tabs ---
    $('.pilot-tab-btn').on('click', function() {
        const tab = $(this).data('tab');
        $('.pilot-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.pilot-tab-content').removeClass('active');
        $(`#pilot-tab-${tab}`).addClass('active');

        if (tab === 'journal') loadJournal();
        if (tab === 'diagnosis') loadDiagnosis();
        if (tab === 'incubator') loadIncubator();
    });

    // --- Diagnostics ---
    function loadDiagnosis() {
        const report = $('#sil-diagnosis-report');
        report.html('<p class="empty-state">Analyse en cours...</p>');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_pilotage_diagnostics',
            nonce: silSharedData.nonce
        }, function(response) {
            if (response.success) {
                let d = response.data;
                let html = '<div class="diag-container">';

                // Environment
                html += '<div class="diag-section-title">🌍 Environnement</div>';
                html += `<div class="diag-line"><span class="diag-label">Horodatage</span> <span class="diag-val">${d.timestamp}</span></div>`;
                html += `<div class="diag-line"><span class="diag-label">PHP Version</span> <span class="diag-val">${d.env.php}</span></div>`;
                html += `<div class="diag-line"><span class="diag-label">WordPress</span> <span class="diag-val">${d.env.wp}</span></div>`;
                html += `<div class="diag-line"><span class="diag-label">Memory Limit</span> <span class="diag-val">${d.env.memory_limit}</span></div>`;
                html += `<div class="diag-line"><span class="diag-label">Max Exec Time</span> <span class="diag-val">${d.env.max_execution_time}s</span></div>`;

                // Database
                html += '<div class="diag-section-title">🗃️ Tables</div>';
                Object.entries(d.database).forEach(([key, val]) => {
                    html += `<div class="diag-line"><span class="diag-label">${key}</span> <span class="diag-val">${val}</span></div>`;
                });

                // Counts
                if (d.counts) {
                    html += '<div class="diag-section-title">📊 Volumes</div>';
                    html += `<div class="diag-line"><span class="diag-label">Liens Internes</span> <span class="diag-val">${d.counts.total_links}</span></div>`;
                    html += `<div class="diag-line"><span class="diag-label">Embeddings</span> <span class="diag-val">${d.counts.total_embeddings}</span></div>`;
                    html += `<div class="diag-line"><span class="diag-label">Action Logs</span> <span class="diag-val">${d.counts.total_logs}</span></div>`;
                }

                // Logic Tests
                html += '<div class="diag-section-title">🧪 Tests Logiques</div>';
                html += `<div class="diag-line"><span class="diag-label">Orphan Query</span> <span class="diag-val">${d.logic_test.orphan_query}</span></div>`;
                html += `<div class="diag-line"><span class="diag-label">Booster Query</span> <span class="diag-val">${d.logic_test.booster_query}</span></div>`;

                // AJAX Routes
                if (d.ajax_routes) {
                    html += '<div class="diag-section-title">🔌 Routes AJAX</div>';
                    Object.entries(d.ajax_routes).forEach(([key, val]) => {
                        html += `<div class="diag-line"><span class="diag-label">${key}</span> <span class="diag-val">${val}</span></div>`;
                    });
                }

                // Recent Errors (Option B)
                if (d.recent_errors && d.recent_errors.length > 0) {
                    html += '<div class="diag-section-title">📜 Logs d\'Erreurs Récents (PHP)</div>';
                    html += '<div class="diag-error-log" style="background:#1e293b; color:#cbd5e1; padding:12px; border-radius:8px; font-family:monospace; font-size:10px; max-height:200px; overflow-y:auto; margin-top:10px; line-height:1.4;">';
                    d.recent_errors.forEach(err => {
                        let color = '#94a3b8';
                        if (err.toLowerCase().includes('fatal') || err.toLowerCase().includes('error')) color = '#f87171';
                        else if (err.toLowerCase().includes('warning')) color = '#fbbf24';
                        
                        html += `<div style="color:${color}; border-bottom:1px solid #334155; padding-bottom:4px; margin-bottom:4px;">${err}</div>`;
                    });
                    html += '</div>';
                }

                html += '</div>';
                report.html(html);
            } else {
                report.html('<p class="diag-val error">❌ Erreur AJAX Diagnostic: ' + (response.data || 'Réponse vide') + '</p>');
                window.silNotify('Erreur Diagnostic : ' + (response.data || 'Réponse vide'), 'error');
            }
        }).fail(function(xhr, textStatus, errorThrown) {
            const status = xhr.status;
            let errorMsg = '❌ Erreur Réseau (' + status + ')';
            if (status === 0) {
                errorMsg = '❌ Erreur Réseau: Impossible de joindre le serveur. Vérifiez la connexion.';
            } else if (status === 400) {
                errorMsg = '❌ Route AJAX non enregistrée (400). La route "sil_get_pilotage_diagnostics" est peut-être absente.';
            } else if (status === 403) {
                errorMsg = '❌ Nonce expiré ou permissions insuffisantes (403).';
            } else if (status === 500) {
                errorMsg = '❌ Erreur interne du serveur (500). Vérifiez les logs PHP.';
            }
            
            report.html('<p class="diag-val error">' + errorMsg + '</p>');
            window.silNotify(errorMsg, 'error');
        });
    }

    $('#force-diagnosis').on('click', loadDiagnosis);

    // --- Journal ---
    function loadJournal() {
        const list = $('#sil-action-log-list');
        list.html('<p class="empty-state">Récupération des actions...</p>');

        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_action_logs',
            nonce: silSharedData.nonce
        }, function(response) {
            if (response.success) {
                renderJournal(response.data);
            } else {
                list.html('<p class="empty-state">Erreur serveur journal.</p>');
                window.silNotify('Erreur de chargement du journal.', 'error');
            }
        }).fail(function(xhr) {
            const status = xhr.status;
            let msg = '❌ Erreur Réseau journal (' + status + ')';
            if (status === 403) msg = '❌ Session expirée (403/Journal).';
            
            list.html('<p class="empty-state">' + msg + '</p>');
            window.silNotify(msg, 'error');
        });
    }

    function renderJournal(logs) {
        const list = $('#sil-action-log-list');
        if (!logs || logs.length === 0) {
            list.html('<p class="empty-state">Aucune action journalisée pour le moment.</p>');
            return;
        }

        let html = '<table class="journal-table"><thead><tr><th>Date</th><th>Action</th><th>Cible</th><th>ROI</th></tr></thead><tbody>';
        logs.forEach(log => {
            html += `<tr>
                <td class="col-date">${log.human_time}</td>
                <td class="col-type"><span class="badge-action ${log.action_type}">${log.action_type}</span></td>
                <td class="col-title">${log.target_title || log.source_title || 'N/A'}</td>
                <td class="col-roi">Pos T0</td>
            </tr>`;
        });
        list.html(html + '</tbody></table>');
    }

    // --- Search ---
    let searchTimeout;
    $('#manual-post-search').on('input', function() {
        const q = $(this).val();
        if (q.length < 3) { $('#search-results-dropdown').hide(); return; }
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            $.post(silSharedData.ajaxurl, {
                action: 'sil_search_posts',
                nonce: silSharedData.nonce,
                q: q
            }, (res) => {
                if (res.success && res.data.length > 0) {
                    let html = '';
                    res.data.forEach(p => html += `<div class="dropdown-item" data-id="${p.id}" data-title="${p.title}">${p.title}</div>`);
                    $('#search-results-dropdown').html(html).show();
                }
            });
        }, 300);
    });

    $(document).on('click', '.dropdown-item', function() {
        $('#manual-post-id').val($(this).data('id'));
        $('#manual-post-search').val($(this).data('title'));
        $('#search-results-dropdown').hide();
    });

    $('#submit-manual-log').on('click', function() {
        const btn = $(this);
        const pid = $('#manual-post-id').val();
        if (!pid) { window.silNotify('Sélectionnez un article.', 'error'); return; }
        
        btn.prop('disabled', true).html('<span class="sil-spinner"></span>...');
        $.post(silSharedData.ajaxurl, {
            action: 'sil_log_manual_action',
            nonce: silSharedData.nonce,
            post_id: pid,
            action_type: $('#manual-action-type').val(),
            note: $('#manual-action-note').val()
        }, function(res) {
            btn.prop('disabled', false).text('Enregistrer Action');
            if (res.success) {
                $('#manual-post-search, #manual-action-note').val('');
                $('#manual-post-id').val('');
                window.silNotify('✅ Action enregistrée avec succès.');
                loadJournal();
            } else {
                window.silNotify('Erreur: ' + res.data, 'error');
            }
        });
    });

    // --- Bridge Trigger: Recherche de cible inline puis modale IA ---
    $(document).on('click', '.sil-pilot-bridge-trigger', function(e) {
        e.stopPropagation();
        const $btn = $(this);
        const sourceId = $btn.data('source-id');
        const sourceTitle = $btn.data('source-title');
        const presetAnchor = $btn.data('anchor') || '';

        // Remove any existing popover
        $('.sil-pilot-target-popover').remove();

        const popoverHtml = `
        <div class="sil-pilot-target-popover">
            <div class="sil-popover-header">
                <h4>🌉 Créer un Pont Sémantique</h4>
                <button class="sil-popover-close">&times;</button>
            </div>
            <div class="sil-popover-section">
                <div class="sil-popover-label">📄 Source</div>
                <div class="sil-popover-value">${sourceTitle}</div>
            </div>
            <div class="sil-mt-2">
                <label class="sil-popover-label">🎯 Article Cible</label>
                <input type="text" class="sil-popover-target-search sil-popover-input" placeholder="Tapez le titre d'un article...">
                <div class="sil-popover-results"></div>
            </div>
            <div class="sil-mt-2 sil-flex-container" style="display:flex; gap:10px;">
                <div style="flex:1;">
                    <label class="sil-popover-label">🔗 Ancre</label>
                    <input type="text" class="sil-popover-anchor sil-popover-input" value="${presetAnchor}" placeholder="Ex: SEO">
                </div>
                <div style="flex:2;">
                    <label class="sil-popover-label">📝 Notes</label>
                    <input type="text" class="sil-popover-note sil-popover-input" placeholder="Ex: conclusion...">
                </div>
            </div>
            <div class="sil-popover-target-info sil-mt-2">
                <div class="sil-popover-label">🎯 Cible sélectionnée</div>
                <div class="sil-popover-target-name sil-popover-value" style="color:#166534;"></div>
            </div>
            <button class="button button-primary sil-popover-confirm sil-w-full sil-mt-4" disabled>🤖 Générer le Prompt IA</button>
            <input type="hidden" class="sil-popover-source-id" value="${sourceId}">
            <input type="hidden" class="sil-popover-target-id" value="">
        </div>
        <div class="sil-pilot-target-overlay"></div>`;

        $('body').append(popoverHtml);
        $('.sil-popover-target-search').focus();
    });

    // Close popover
    $(document).on('click', '.sil-popover-close, .sil-pilot-target-overlay', function() {
        $('.sil-pilot-target-popover, .sil-pilot-target-overlay').remove();
    });

    // Target search within popover
    let popoverSearchTimeout;
    $(document).on('input', '.sil-popover-target-search', function() {
        const $input = $(this);
        const val = $input.val();
        const $results = $input.siblings('.sil-popover-results');
        if (val.length < 3) { $results.hide().empty(); return; }
        clearTimeout(popoverSearchTimeout);
        popoverSearchTimeout = setTimeout(() => {
            $.post(silSharedData.ajaxurl, {
                action: 'sil_search_posts',
                nonce: silSharedData.nonce,
                q: val
            }, function(res) {
                if (res.success && res.data.length > 0) {
                    let html = '';
                    res.data.forEach(p => {
                        html += `<div class="sil-popover-result-item" data-id="${p.id}" data-title="${p.title}">${p.title}</div>`;
                    });
                    $results.html(html).show();
                } else {
                    $results.html('<div style="padding:8px 12px;color:#94a3b8;font-size:12px;">Aucun résultat</div>').show();
                }
            });
        }, 300);
    });

    // Select target from results
    $(document).on('click', '.sil-popover-result-item', function() {
        const targetId = $(this).data('id');
        const targetTitle = $(this).data('title');
        const $popover = $(this).closest('.sil-pilot-target-popover');
        $popover.find('.sil-popover-target-id').val(targetId);
        $popover.find('.sil-popover-target-search').val(targetTitle);
        $popover.find('.sil-popover-results').hide();
        $popover.find('.sil-popover-target-name').text(targetTitle);
        $popover.find('.sil-popover-target-info').show();
        $popover.find('.sil-popover-confirm').prop('disabled', false);
    });

    // Confirm: trigger Bridge Manager
    $(document).on('click', '.sil-popover-confirm', function() {
        const sourceId = $('.sil-popover-source-id').val();
        const targetId = $('.sil-popover-target-id').val();
        const anchor   = $('.sil-popover-anchor').val();
        const note     = $('.sil-popover-note').val(); // Capture the manual note

        if (!targetId) return;

        // Close popover and launch Bridge Manager
        $('.sil-pilot-target-popover, .sil-pilot-target-overlay').remove();

        if (typeof window.SIL_Bridge !== 'undefined') {
            // Create a temporary button for the spinner state
            const $tempBtn = $('<button class="button">⏳ Calcul sémantique...</button>');
            $('body').append($tempBtn.hide());
            window.SIL_Bridge.generate(sourceId, targetId, anchor, $tempBtn, note);
        } else {
            window.silNotify("Le moteur du pont sémantique IA n'est pas chargé.", 'error');
        }
    });

    $('.refresh-all').on('click', function() {
        $(this).addClass('rotating');
        loadPilotActions();
        setTimeout(() => $(this).removeClass('rotating'), 1000);
    });

    // --- Incubator ---
    function loadIncubator() {
        const list = $('#sil-incubator-list');
        list.html("<p class='empty-state'>Chargement de la file d'attente...</p>");

        $.post(silSharedData.ajaxurl, {
            action: 'sil_get_scheduled_links',
            nonce: silSharedData.nonce
        }, function(response) {
            if (response.success && Object.keys(response.data).length > 0) {
                renderIncubator(response.data);
            } else {
                list.html("<p class='empty-state'>L'incubateur est vide.</p>");
            }
        });
    }

    function renderIncubator(links) {
        let html = '<table class="journal-table"><thead><tr><th>Source</th><th>Ancre Prévue</th><th>Cible</th><th>Statut/Action</th></tr></thead><tbody>';
        // handle object/array
        const linksArr = Array.isArray(links) ? links : Object.values(links);
        linksArr.forEach(l => {
            const isTargetPublish = (l.target_status === 'publish');
            
            let btnAction = '';
            if (l.status === 'completed') {
                btnAction = '<span class="status-pill ok">✅ Créé</span>';
            } else if (isTargetPublish) {
                btnAction = `<button class="mini-bridge btn-create-bridge" data-id="${l.id}" data-source="${l.source_id}" data-target="${l.target_id}" data-anchor="${l.anchor}" data-note="${l.note || ''}">Créer Pont 🚀</button>`;
            } else {
                btnAction = `<button class="mini-adopt" disabled style="opacity:0.5;cursor:not-allowed;">Attente Publication...</button>`;
            }

            html += `<tr>
                <td class="col-title">${l.source_title || 'N/A' }</td>
                <td class="sil-text-italic">
                    <span class="sil-text-muted">"${l.anchor}"</span>
                    ${l.note ? `<br><small class="sil-text-note">💬 ${l.note}</small>` : ''}
                </td>
                <td class="col-title">${l.target_title || 'N/A'}</td>
                <td>
                    ${btnAction}
                    <button class="btn-delete-scheduled sil-btn-icon-danger" data-id="${l.id}" title="Supprimer">🗑️</button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        $('#sil-incubator-list').html(html);
    }

    let searchIncTimeout;
    function setupIncubatorSearch(inputId, dropdownId, hiddenId, postStatus) {
        $(`#${inputId}`).on('input', function() {
            const q = $(this).val();
            if (q.length < 3) { $(`#${dropdownId}`).hide(); return; }
            clearTimeout(searchIncTimeout);
            searchIncTimeout = setTimeout(() => {
                $.post(silSharedData.ajaxurl, {
                    action: 'sil_search_posts',
                    nonce: silSharedData.nonce,
                    post_status: postStatus,
                    q: q
                }, (res) => {
                    if (res.success && res.data.length > 0) {
                        let html = '';
                        res.data.forEach(p => html += `<div class="dropdown-item" data-id="${p.id}" data-title="${p.title}">${p.title}</div>`);
                        $(`#${dropdownId}`).html(html).show();
                    }
                });
            }, 300);
        });

        $(document).on('click', `#${dropdownId} .dropdown-item`, function() {
            $(`#${hiddenId}`).val($(this).data('id'));
            $(`#${inputId}`).val($(this).data('title'));
            $(`#${dropdownId}`).hide();
        });
    }

    setupIncubatorSearch('inc-source-search', 'inc-source-dropdown', 'inc-source-id', ['publish']);
    setupIncubatorSearch('inc-target-search', 'inc-target-dropdown', 'inc-target-id', ['draft', 'future', 'pending']);

    $('#submit-incubator').on('click', function() {
        const btn = $(this);
        const source_id = $('#inc-source-id').val();
        const target_id = $('#inc-target-id').val();
        const anchor = $('#inc-anchor').val();
        const note = $('#inc-note').val();

        if (!source_id || !target_id || !anchor) {
            window.silNotify('Veuillez remplir les champs obligatoires.', 'error');
            return;
        }

        btn.prop('disabled', true).html('<span class="sil-spinner"></span>...');
        $.post(silSharedData.ajaxurl, {
            action: 'sil_schedule_link',
            nonce: silSharedData.nonce,
            source_id: source_id,
            target_id: target_id,
            anchor: anchor,
            note: note
        }, function(res) {
            btn.prop('disabled', false).text('Programmer');
            if (res.success) {
                $('#inc-source-search, #inc-target-search, #inc-anchor, #inc-note').val('');
                $('#inc-source-id, #inc-target-id').val('');
                window.silNotify('🌱 Lien programmé avec succès.');
                loadIncubator();
            } else {
                window.silNotify(res.data, 'error');
            }
        });
    });

    $(document).on('click', '.btn-delete-scheduled', function() {
        if (!confirm('Voulez-vous supprimer ce pont programmé ?')) return;
        const id = $(this).data('id');
        $.post(silSharedData.ajaxurl, {
            action: 'sil_delete_scheduled_link',
            nonce: silSharedData.nonce,
            id: id
        }, function(res) {
            if(res.success) loadIncubator();
        });
    });

    $(document).on('click', '.btn-create-bridge', function() {
        const btn = $(this);
        const source_id = btn.data('source');
        const target_id = btn.data('target');
        const anchor = btn.data('anchor');
        const note = btn.data('note');

        if (typeof window.SIL_Bridge !== 'undefined') {
            window.SIL_Bridge.generate(source_id, target_id, anchor, btn, note);
        } else {
            window.silNotify("Le moteur du pont sémantique IA n'est pas chargé sur cette page.", 'error');
        }
    });

    // Écouter le succès du pont sémantique
    $(document).on('sil_bridge_applied', function(e, sourceId, targetId) {
        $.post(silSharedData.ajaxurl, {
            action: 'sil_complete_scheduled_link',
            nonce: silSharedData.nonce,
            source_id: sourceId,
            target_id: targetId
        }, function() {
            if ($('#pilot-tab-incubator').hasClass('active')) {
                loadIncubator();
            }
        });
    });


    // Start
    init();
});
})(jQuery);

