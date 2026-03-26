jQuery(document).ready(function ($) {
    let cy = null;
    const $container = $('#sil-graph-container');
    const vibrantColors = ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#14b8a6'];

    function decodeHtmlEntity(str) {
        if (!str) return '';
        var txt = document.createElement('textarea');
        txt.innerHTML = str;
        return txt.value;
    }

    function getColorForCluster(clusterId) {
        let idStr = String(clusterId);
        if (idStr === '0' || idStr === '1000') return '#94a3b8';
        let hash = 0;
        for (let i = 0; i < idStr.length; i++) {
            hash = idStr.charCodeAt(i) + ((hash << 5) - hash);
        }
        return vibrantColors[Math.abs(hash) % vibrantColors.length];
    }

    if ($container.length) { loadGraphData(); }

    // --- TOOLBAR ---
    $('#sil-refresh-graph').on('click', function(e) {
        e.preventDefault();
        $('#sil-graph-loading').show();
        updateStatus(10, 'Rechargement forcé...', '');
        $.post(silGraphData.ajaxurl || ajaxurl, {
            action: 'sil_get_graph_data',
            nonce: silGraphData.nonce,
            force_refresh: 'true'
        }, function(response) {
            if (response.success) {
                processGraphData(response.data);
            } else {
                handleGraphError(response.data);
            }
        });
    });
    $('#sil-center-graph').on('click', function(e) { e.preventDefault(); if (cy) cy.fit(null, 50); });
    $('#sil-zoom-in').on('click', function(e) { e.preventDefault(); if (cy) cy.zoom(cy.zoom() * 1.2); });
    $('#sil-zoom-out').on('click', function(e) { e.preventDefault(); if (cy) cy.zoom(cy.zoom() / 1.2); });
    $('#sil-close-sidebar').on('click', function() { $('#sil-graph-sidebar').hide(); });

    // --- EXPORT FUNCTIONS ---
    $('#sil-export-png').on('click', function (e) {
        e.preventDefault();
        if (!cy) return;
        
        const b64 = cy.png({
            full: true,
            bg: 'white',
            scale: 2
        });

        const link = document.createElement('a');
        link.href = b64;
        link.download = 'sil-cartographie.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    });

    $('#sil-export-json').on('click', function (e) {
        e.preventDefault();
        if (!cy) return;

        // "Audit AI" Format with Metadata
        const graphData = {
            metadata: {
                site_url: window.location.hostname,
                export_date: new Date().toISOString(),
                node_count: cy.nodes('[^is_silo_parent]').length,
                edge_count: cy.edges().length,
                sil_version: "2.0",
                features: ["sil_pagerank", "permeability", "semantic_collision"]
            },
            elements: cy.elements().map(el => el.data())
        };

        const blob = new Blob([JSON.stringify(graphData, null, 2)], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const downloadAnchorNode = document.createElement('a');
        downloadAnchorNode.href = url;
        downloadAnchorNode.download = "sil-audit-data.json";
        document.body.appendChild(downloadAnchorNode);
        downloadAnchorNode.click();
        document.body.removeChild(downloadAnchorNode);
        URL.revokeObjectURL(url);
    });

    function updateStatus(percent, label, detail) {
        $('#sil-progress-bar').css('width', percent + '%');
        $('#sil-step-label').text(label);
        $('#sil-graph-status-text').text(detail); // Match the ID in renderer
    }

    function loadGraphData() {
        updateStatus(20, '\u00c9tape 1/3 : Extraction', 'Lecture des liens et statistiques GSC\u2026');
        $.post(silGraphData.ajaxurl || ajaxurl, {
            action: 'sil_get_graph_data',
            nonce: silGraphData.nonce
        }, function(response) {
            if (response.success) {
                updateStatus(75, '\u00c9tape 2/3 : Analyse', 'Calcul des silos s\u00e9mantiques\u2026');
                setTimeout(() => {
                    updateStatus(100, '\u00c9tape 3/3 : Rendu', 'G\u00e9n\u00e9ration de la carte\u2026');
                    processGraphData(response.data);
                }, 100);
            } else {
                handleGraphError(response.data);
            }
        }).fail((jqXHR, textStatus, errorThrown) => {
            let msg = 'Erreur serveur : ' + errorThrown;
            if (jqXHR.status === 504 || jqXHR.status === 503 || textStatus === 'timeout') {
                msg = 'Le serveur met trop de temps à répondre (Timeout).';
            } else if (jqXHR.status === 500) {
                msg = 'Erreur interne du serveur (500). Regardez les logs PHP ou l\'onglet Réseau de votre navigateur pour plus de détails.';
            }
            if (jqXHR.responseJSON && jqXHR.responseJSON.data) {
                msg += ' - Détail: ' + jqXHR.responseJSON.data;
            } else if (jqXHR.responseText) {
                try {
                    let json = JSON.parse(jqXHR.responseText);
                    if (json.data) msg += ' - Détail: ' + json.data;
                } catch(e) {}
            }
            handleGraphError(msg);
        });
    }

    function processGraphData(data) {
        if (data && data.metadata && data.metadata.generated_at) {
            $('#sil-last-update-hint').text('(Analysé le ' + data.metadata.generated_at + ')');
        }

        try {
            let maxImp = 1;
            let maxWeight = 1;
            const $datalist = $('#sil-node-list');
            $datalist.empty();

            // Construire un map clusterId -> label depuis les noeuds parents
            const siloLabels = {};
            if (data && data.nodes) {
                data.nodes.forEach(node => {
                    // Verifier si vrai boolean ou string 'true'
                    if (node.data && (node.data.is_silo_parent === 'true' || node.data.is_silo_parent === true)) {
                        siloLabels[String(node.data.cluster_id)] = node.data.label || ('Silo ' + node.data.cluster_id);
                    }
                });
            }

            // Mettre a jour le <select> avec les vrais noms
            const $select = $('#sil-silo-filter');
            $select.find('option[value!="all"]').remove();
            
            // Si jamais la liste est vide (pas de noeud parent), on logue pour le debug
            if (Object.keys(siloLabels).length === 0) {
                console.warn("Aucun nom de silo detecte dans data.nodes.");
            } else {
                // Tri numerique des ID de silo pour un bel affichage
                Object.keys(siloLabels).sort((a,b) => parseInt(a) - parseInt(b)).forEach(function(cid) {
                    $select.append('<option value="' + cid + '">' + siloLabels[cid] + '</option>');
                });
            }

            let maxPagerank = 0;
            if (data && data.nodes) {
                data.nodes.forEach(node => {
                    if (!node.data || node.data.is_silo_parent === 'true' || node.data.is_silo_parent === true) return;
                    node.data.label = decodeHtmlEntity(node.data.label || 'Sans titre');

                    // Autocomplete datalist
                    $('<option>').val(node.data.label).appendTo($datalist);

                    // Icones emojis
                    if (node.data.is_intruder === 'true' || node.data.is_intruder === true) {
                        node.data.label = '\ud83d\udc7e ' + node.data.label;
                    }
                    if (node.data.tags && node.data.tags.includes('orphan')) {
                        node.data.label = '\ud83d\udea9 ' + node.data.label;
                    }
                    if (node.data.tags && node.data.tags.includes('siphon')) {
                        node.data.label = '\ud83e\uddfb ' + node.data.label;
                    }

                    // Numeric casting for mapData
                    node.data.gsc_impressions = parseInt(node.data.gsc_impressions || 0);
                    node.data.sil_pagerank = parseFloat(node.data.sil_pagerank || 0);
                    
                    if (node.data.sil_pagerank > maxPagerank) maxPagerank = node.data.sil_pagerank;
                });
            }

            if (data && data.edges) {
                data.edges.forEach(e => { if (e.data.weight > maxWeight) maxWeight = e.data.weight; });
            }

            renderCytoscape(data, maxPagerank, maxWeight, siloLabels);
        } catch (e) {
            console.error(e);
            handleGraphError("Erreur de traitement des donnees locales : " + e.message);
        }
    }

    function renderCytoscape(data, maxPagerank, maxWeight, siloLabels) {
        try {
            // Init Cytoscape
            cy = cytoscape({
                container: $container[0],
                elements: data,
                style: [
                    {
                        selector: 'node[^is_silo_parent]',
                        style: {
                            'label': 'data(label)',
                            'width': `mapData(sil_pagerank, 0, ${maxPagerank > 0 ? maxPagerank : 1}, 70, 180)`,
                            'height': `mapData(sil_pagerank, 0, ${maxPagerank > 0 ? maxPagerank : 1}, 70, 180)`,
                            'background-color': n => getColorForCluster(n.data('cluster_id')),
                            'color': '#0f172a',
                            'font-size': '18px',
                            'font-weight': 'bold',
                            'min-zoomed-font-size': 10,
                            'text-valign': 'bottom',
                            'text-margin-y': '8px',
                            'text-background-color': '#ffffff',
                            'text-background-opacity': 0.85,
                            'text-background-padding': '4px',
                            'text-background-shape': 'roundrectangle',
                            'border-width': 2,
                            'border-color': '#ffffff'
                        }
                    },
                    {
                        selector: 'node[is_silo_parent = "true"]',
                        style: {
                            'label': 'data(label)',
                            'shape': 'roundrectangle',
                            'background-opacity': 0.15,
                            'background-color': n => getColorForCluster(n.data('cluster_id')),
                            'border-width': 2,
                            'border-style': 'dashed',
                            'border-color': '#94a3b8',
                            'text-valign': 'top',
                            'text-halign': 'center',
                            'font-size': '26px',
                            'font-weight': 'bold',
                            'color': '#475569',
                            'text-opacity': 0.6,
                            'text-transform': 'none'
                        }
                    },
                    {
                        selector: 'edge',
                        style: {
                            'width': `mapData(weight, 0, ${maxWeight > 0 ? maxWeight : 10}, 2, 8)`,
                            'line-color': '#94a3b8',
                            'target-arrow-shape': 'triangle',
                            'curve-style': 'bezier',
                            'opacity': 0.6
                        }
                    },
                    {
                        selector: '.dimmed',
                        style: { 
                            'opacity': 0.1, 
                            'text-opacity': 0 
                        }
                    },
                    { selector: ':selected', style: { 'border-width': 3, 'border-color': '#2563eb' } },
                    {
                        selector: 'node[is_orphan="true"]',
                        style: { 'border-width': 5, 'border-color': '#ef4444', 'border-style': 'solid' }
                    },
                    {
                        selector: 'node[is_intruder="true"]',
                        style: { 'border-width': 5, 'border-color': '#8b5cf6', 'border-style': 'dashed' }
                    },
                    {
                        selector: 'node[is_siphon="true"]',
                        style: { 'border-width': 5, 'border-color': '#f97316', 'border-style': 'double' }
                    },
                    {
                        selector: 'node[is_bridge="true"]',
                        style: { 'border-width': 6, 'border-color': '#eab308', 'border-style': 'double', 'shape': 'hexagon' }
                    },
                    {
                        selector: 'node[is_missing_reciprocity="true"][^is_silo_parent]',
                        style: { 
                            'border-width': 6, 
                            'border-color': '#f97316', 
                            'border-style': 'dashed'
                        }
                    },
                    {
                        selector: 'node[is_strategic="true"][!is_silo_parent]',
                        style: { 
                            'border-width': 8, 
                            'border-color': '#eab308', 
                            'border-style': 'solid'
                        }
                    }
                ]
            });
        } catch (err) {
            console.error("Cytoscape Init Error:", err);
            handleGraphError("Erreur d'initialisation du graphe : " + err.message + ". Essayez de vider le cache du navigateur.");
            return;
        }

        // Executer le layout de maniere controle et explicite
        const layout = cy.layout({
            name: 'cose',
            nodeDimensionsIncludeLabels: false, // Vital pour éviter le chevauchement des textes
            idealEdgeLength: 150,      // Plus d'espace pour la clarté
            nodeOverlap: 40,
            refresh: 20,
            fit: true,
            padding: 50,
            randomize: false,
            componentSpacing: parseInt(silGraphData.spacing) || 120,      // Plus d'écart entre silos        
            nodeRepulsion: function( node ){ 
                let baseRepulsion = parseInt(silGraphData.repulsion) || 8000; // Réduit drastiquement
                return baseRepulsion + (node.width() * 50); 
            }, 
            gravity: parseFloat(silGraphData.gravity) || 1.5,                      
            edgeElasticity: function( edge ){ return 100; },
            nestingFactor: 1.2,
            numIter: 1000,
            initialTemp: 200,
            coolingFactor: 0.95,
            minTemp: 1.0,
            animate: false
        });
        
        layout.run(); // Bloquant puisque animate: false

        // Repositionner Silo 0 apres le layout
        try {
            const mainNodes = cy.nodes().filter(n =>
                (!n.data('is_silo_parent') || n.data('is_silo_parent') === 'false') &&
                String(n.data('cluster_id')) !== '0' &&
                String(n.data('cluster_id')) !== '1000'
            );
            const silo0Nodes = cy.nodes().filter(n =>
                (!n.data('is_silo_parent') || n.data('is_silo_parent') === 'false') &&
                (String(n.data('cluster_id')) === '0' || String(n.data('cluster_id')) === '1000')
            );

            if (mainNodes.length > 0 && silo0Nodes.length > 0) {
                const mainBB  = mainNodes.boundingBox();
                const silo0BB = silo0Nodes.boundingBox();
                const mainCx  = (mainBB.x1 + mainBB.x2) / 2;
                const s0Cx    = (silo0BB.x1 + silo0BB.x2) / 2;
                const s0Cy    = (silo0BB.y1 + silo0BB.y2) / 2;
                
                // Placer le centre de Silo 0 pile sous le centre du graphe principal
                const targetX = mainCx;
                const targetY = mainBB.y2 + 100;
                
                silo0Nodes.shift({ x: targetX - s0Cx, y: targetY - s0Cy });
            }
        } catch (e) {
            console.error("Erreur au repositionnement du silo 0 :", e);
        }

        try { cy.fit(null, 40); } catch (e) {}
        $('#sil-graph-loading').fadeOut(300);

        // --- Clics et interactions ---
        $('#sil-node-count').text(cy.nodes('[^is_silo_parent]').length);

        $('#sil-silo-filter').on('change', function() {
            const val = $(this).val();
            cy.elements().removeClass('dimmed');
            if (val !== 'all') {
                cy.nodes().forEach(n => {
                    if (String(n.data('cluster_id')) !== String(val)) n.addClass('dimmed');
                });
                cy.edges().forEach(e => {
                    if (String(e.source().data('cluster_id')) !== String(val) ||
                        String(e.target().data('cluster_id')) !== String(val)) e.addClass('dimmed');
                });
            }
        });

        $('#sil-node-search').on('change input', function() {
            const search = $(this).val().toLowerCase().trim();
            cy.elements().removeClass('dimmed');
            if (!search) return;
            
            let matchedNodes = cy.collection();
            cy.nodes('[^is_silo_parent]').forEach(n => {
                const label = (n.data('label') || '').toLowerCase();
                if (label.includes(search)) {
                    matchedNodes = matchedNodes.add(n);
                } else {
                    n.addClass('dimmed');
                }
            });
            
            if (matchedNodes.length > 0 && matchedNodes.length < 5) {
                cy.animate({ fit: { eles: matchedNodes, padding: 80 } }, { duration: 400 });
            }
        });        // Node detail handler
        cy.on('tap', 'node[^is_silo_parent]', function(evt) {
            const node = evt.target;
            const postId = node.data('id');
            if (!postId) return;

            const $sidebar = $('#sil-graph-sidebar');
            const $content = $('#sil-sidebar-content');

            // Show sidebar and loading state
            $sidebar.show();
            $content.html('<div style="padding:40px; text-align:center;"><div class="spinner is-active" style="float:none; margin:0 auto 20px;"></div><p style="color:#64748b; font-size:13px;">Analyse s\u00e9mantique en cours...</p></div>');

            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_get_node_details',
                nonce: silGraphData.nonce,
                post_id: postId
            }, function(response) {
                if (!response.success) {
                    $content.html('<p style="color:#d63638; padding:20px;">Erreur : ' + (response.data || 'Impossible de charger les d\u00e9tails.') + '</p>');
                    return;
                }
                const d = response.data;
                const clusterId = node.data('cluster_id');
                const color = getColorForCluster(clusterId);
                const siloLabel = siloLabels[String(clusterId)] || ('Silo ' + clusterId);
                
                const isOrphan   = node.data('is_orphan') === 'true' || node.data('is_orphan') === true;
                const isSiphon   = node.data('is_siphon') === 'true' || node.data('is_siphon') === true;
                const isIntruder = d.is_intruder || node.data('is_intruder') === 'true' || node.data('is_intruder') === true;
                const isPivot     = node.data('is_pivot') === 'true' || node.data('is_pivot') === true;
                const isStrategic = node.data('is_strategic') === 'true' || node.data('is_strategic') === true;
                const hasReciprocity = node.data('has_reciprocal_link') === 'true' || node.data('has_reciprocal_link') === true;
                const cornerstoneId = node.data('cornerstone_id');

                const nodeLabel  = node.data('label') || 'Sans titre';
                const permeability = parseInt(node.data('cluster_permeability') || 0);
                const semanticTarget = node.data('semantic_target') || 'autre silo';

                let html = '<div style="border-top:4px solid ' + color + ';padding-top:12px;">';
                
                // Alert Badges
                if (isStrategic) {
                    html += '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px;margin-bottom:10px;font-size:12px;display:flex;align-items:center;gap:8px;">' + silGraphData.icons.star + ' <strong style="color:#b45309;">Pilier de Silo (Cornerstone)</strong> — Page d\'autorité principale.</div>';
                }

                if (isOrphan) {
                    html += '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:8px;margin-bottom:10px;font-size:12px;display:flex;align-items:center;gap:8px;">' + silGraphData.icons.flag + ' <strong style="color:#b91c1c;">Page Orpheline</strong> — Aucun lien interne entrant.</div>';
                }
                
                // Reciprocity Module (Silo Sealing)
                else if (!isStrategic && !hasReciprocity && cornerstoneId && String(clusterId) !== '0') {
                    html += `<div class="sil-reciprocity-block">
                        <h5>${silGraphData.icons.anchor || '🔗'} Maillage Réciproque Manquant</h5>
                        <p>Cet article appartient au <strong>${siloLabel}</strong> mais ne renvoie pas de lien vers son Pilier. Cela affaiblit la structure du silo.</p>
                        <button class="button sil-seal-btn" data-source="${postId}" data-target="${cornerstoneId}">
                            🚀 Sceller le Silo (Lien vers Pilier)
                        </button>
                    </div>`;
                }
 else if (isSiphon) {
                    html += '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:8px;margin-bottom:10px;font-size:12px;display:flex;align-items:center;gap:8px;">' + silGraphData.icons.droplets + ' <strong style="color:#c2410c;">Page Siphon</strong> — Capte le PageRank sans le redistribuer (Cul-de-sac).</div>';
                } else if (isIntruder) {
                    const proximity = (d.proximity || 0);
                    const proxPercent = Math.round(proximity * 100);
                    
                    // On distingue l'intrus sémantique (mauvais sujet) du parasite (mauvais maillage)
                    const isPureSemanticIntruder = proximity < 0.6;
                    const intruderIcon = isPureSemanticIntruder ? silGraphData.icons.ghost : silGraphData.icons.target;
                    const intruderTitle = isPureSemanticIntruder ? 'Intrus Sémantique Identifié' : 'Déséquilibre de Maillage (Parasite)';
                    
                    html += '<div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;padding:12px;margin-bottom:15px;font-size:12px;">';
                    html += '<div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">';
                    html += `${intruderIcon} <strong style="color:#6d28d9;">${intruderTitle}</strong>`;
                    html += '</div>';
                    
                    if (isPureSemanticIntruder) {
                        html += '<p style="margin:0 0 10px 0; color:#4338ca;">Ce contenu n\'est pas \u00e0 sa place. Sa coh\u00e9sion th\u00e9matique avec ce silo est de seulement <strong>' + proxPercent + '%</strong>.</p>';
                        
                        if (d.recommended_silo_name && d.closest_content_title) {
                            html += '<div style="margin-top:8px; padding-top:8px; border-top:1px solid #ddd6fe;">';
                            html += '<div style="display:flex; align-items:flex-start; gap:8px; color:#1e293b; line-height:1.4;">' + silGraphData.icons.lightbulb + ' <span><strong>Conseil IA :</strong> Déplacez cet article vers le silo <strong>' + d.recommended_silo_name + '</strong> (proche de <em>"' + d.closest_content_title + '"</em>).</span></div>';
                            html += '<div style="margin-top:12px; text-align:center;">';
                            html += '<button class="button button-primary sil-reco-bridge-btn" data-source="' + postId + '" data-target="' + d.closest_content_id + '" data-title="' + d.closest_content_title.replace(/"/g,'&quot;') + '" style="font-size:11px; padding:6px 16px; height:auto; line-height:1.4; border-radius:20px; display:inline-flex; align-items:center; gap:8px;">' + silGraphData.icons.bridge + ' Créer un Pont Sémantique Direct</button>';
                            html += '</div></div>';
                        }
                    } else {
                        html += '<p style="margin:0 0 10px 0; color:#4338ca;">Cet article est th\u00e9matiquement \u00e0 sa place (<strong>' + proxPercent + '%</strong>) mais se comporte comme un "Parasite" : il ne renvoie aucun lien vers son propre silo.</p>';
                        html += '<div style="margin-top:8px; padding-top:8px; border-top:1px solid #ddd6fe;">';
                        html += '<div style="display:flex; align-items:flex-start; gap:8px; color:#1e293b; line-height:1.4;">' + silGraphData.icons.lightbulb + ' <span><strong>Conseil IA :</strong> Ajoutez des liens sortants depuis cet article vers d\'autres pages de son silo actuel (<strong>' + siloLabel + '</strong>).</span></div>';
                        
                        if (cornerstoneId && cornerstoneId !== postId) {
                            html += '<div style="margin-top:12px; text-align:center;">';
                            html += '<button class="button button-primary sil-seal-btn" data-source="' + postId + '" data-target="' + cornerstoneId + '" style="font-size:11px; padding:6px 16px; height:auto; line-height:1.4; border-radius:20px; display:inline-flex; align-items:center; gap:8px;">' + silGraphData.icons.link + ' Lier au Pilier du Silo</button>';
                            html += '</div>';
                        }
                        html += '</div>';
                    }
                    html += '</div>';
                } else {
                    // Traffic Alerts
                    if (permeability > 35) {
                        const targetLabel = siloLabels[String(semanticTarget)] || ('Silo ' + semanticTarget);
                        html += '<div style="background:#fee2e2;border:1px solid #f87171;border-radius:6px;padding:8px;margin-bottom:10px;font-size:12px;">\u26a0\ufe0f <strong style="color:#b91c1c;">Fuite s\u00e9mantique (' + permeability + '%)</strong><br><span style="color:#7f1d1d;">Le jus s\'\u00e9chappe vers ' + targetLabel + '.</span></div>';
                    } else if (permeability < 5 && String(clusterId) !== '0') {
                        html += '<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:8px;margin-bottom:10px;font-size:12px;">\ud83d\udd12 <strong style="color:#d97706;">Silo cloisonn\u00e9 (' + permeability + '%)</strong><br><span style="color:#92400e;">Peu d\'interaction avec les autres silos.</span></div>';
                    }
                }

                // Title & Basic Stats
                html += '<h4 style="margin:0 0 10px;font-size:14px;color:#1d2327;">' + nodeLabel + '</h4>';
                html += '<div style="display:flex;gap:8px;margin-bottom:15px;">';
                html += '<div style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;text-align:center;"><strong style="font-size:22px;color:#2271b1;display:block;">' + (d.incoming || 0) + '</strong><span style="font-size:10px;color:#64748b;text-transform:uppercase;font-weight:600;">Entrants</span></div>';
                html += '<div style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;text-align:center;"><strong style="font-size:22px;color:#10b981;display:block;">' + (d.outgoing || 0) + '</strong><span style="font-size:10px;color:#64748b;text-transform:uppercase;font-weight:600;">Sortants</span></div>';
                html += '</div>';
                
                // --- NEW: Semantic Membership Info ---
                if (d.semantic_membership) {
                    const score = Math.round(d.semantic_membership.score * 100);
                    html += `<div style="margin-bottom:15px; background:#f8fafc; padding:10px; border-radius:8px; border:1px solid #e2e8f0;">`;
                    html += `<strong style="font-size:10px; text-transform:uppercase; color:#94a3b8; display:block; margin-bottom:5px;">Analyse Semantique (FCM)</strong>`;
                    html += `<div style="display:flex; align-items:center; gap:10px; margin-bottom:5px;">`;
                    html += `<div style="flex:1; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden;">
                                <div style="width:${score}%; height:100%; background:${color};"></div>
                             </div>`;
                    html += `<span style="font-size:12px; font-weight:700; color:${color};">${score}%</span>`;
                    html += `</div>`;
                    html += `<span style="font-size:11px; color:#475569;">Silo Principal : <strong>${siloLabel}</strong></span>`;
                    
                    if (d.secondary_membership) {
                        const secScore = Math.round(d.secondary_membership.score * 100);
                        html += `<div style="margin-top:8px; padding-top:8px; border-top:1px dashed #cbd5e1; font-size:10px; color:#b45309;">
                                    🌉 <strong>Pont détecté :</strong> ${secScore}% d'affinité avec le Silo ${d.secondary_membership.silo_id}
                                 </div>`;
                    }
                    html += `</div>`;
                } else {
                    html += '<div style="margin-bottom:12px;"><strong style="font-size:10px;text-transform:uppercase;color:#94a3b8;">Silo Actuel</strong><br><span style="background:' + color + '15; color:' + color + '; padding:4px 10px; border-radius:15px; font-size:12px; font-weight:700; border:1px solid ' + color + '33;">' + siloLabel + '</span></div>';
                }

                // SEO Data
                html += '<div style="background:#f1f5f9; border-radius:8px; padding:12px; margin-bottom:15px; border-left:4px solid #3b82f6;">';
                html += '<strong style="font-size:10px; text-transform:uppercase; color:#64748b;">SEO (RankMath)</strong>';
                html += '<div id="sil-seo-title-display" style="margin:6px 0 4px; font-size:12px; font-weight:700; color:#0f172a;">' + (d.seo_title || nodeLabel) + '</div>';
                html += '<div id="sil-seo-meta-display" style="font-size:11px; color:#475569; line-height:1.4;">' + (d.seo_meta || 'Aucune meta description d\u00e9tect\u00e9e.') + '</div>';
                html += '</div>';

                html += '<button class="button sil-ai-seo-btn" data-post-id="' + postId + '" style="width:100%; margin-bottom:12px; font-size:12px; background:#fff; border-color:#d1d5db;">\u2728 R\u00e9\u00e9crire via IA</button>';
                html += '<div id="sil-seo-ai-result" style="display:none;margin-bottom:15px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:12px;box-shadow:0 1px 2px rgba(0,0,0,0.05);"></div>';

                // Recommendations Section
                if (d.semantic_recommendations && d.semantic_recommendations.length > 0) {
                    html += '<div style="margin-bottom:20px; padding:12px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px;">';
                    html += '<h4 style="margin:0 0 10px; font-size:13px; color:#0369a1;">\ud83d\udca1 Suggestions de R\u00e9assignation</h4>';
                    html += '<ul style="margin:0; padding:0; list-style:none;">';
                    d.semantic_recommendations.forEach(function(reco) {
                        html += '<li style="margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid #e0f2fe; last-child:border-bottom:none;">';
                        html += '<div style="font-size:11px; font-weight:600; color:#1e293b; margin-bottom:4px;">' + reco.title + ' <span style="color:#0ea5e9; font-weight:700;">(' + reco.score + '%)</span></div>';
                        html += '<button class="button button-secondary sil-reco-bridge-btn" data-source="' + postId + '" data-target="' + reco.id + '" data-title="' + reco.title.replace(/"/g,'&quot;') + '" style="font-size:10px; padding:2px 8px; height:auto; line-height:1.5;">Cr\u00e9er un Pont S\u00e9mantique</button>';
                        html += '</li>';
                    });
                    html += '</ul></div>';
                }

                // Outgoing links list
                if (d.outgoing_links && d.outgoing_links.length > 0) {
                    html += '<div style="margin-top:15px; border-top:1px solid #e2e8f0; padding-top:12px;">';
                    html += '<strong style="font-size:10px; text-transform:uppercase; color:#94a3b8;">Liens sortants (' + d.outgoing_links.length + ')</strong>';
                    html += '<ul style="margin:8px 0 0; padding:0; list-style:none; max-height:150px; overflow-y:auto;">';
                    d.outgoing_links.forEach(function(lnk) {
                        let icon = '🏠';
                        let color = '#3b82f6';
                        if (lnk.type === 'external') { icon = '🌍'; color = '#8b5cf6'; }
                        else if (lnk.type === 'broken') { icon = '⚠️'; color = '#ef4444'; }

                        html += '<li class="sil-link-item" style="padding:4px 8px; border-bottom:1px solid #f1f5f9; font-size:11px; display:flex; align-items:center; gap:8px; cursor:pointer; transition:background 0.2s;" data-context-prev="' + (lnk.context_prev || '') + '" data-context-next="' + (lnk.context_next || '') + '" data-anchor="' + lnk.anchor + '" data-url="' + lnk.url + '">';
                        html += '<span style="font-size:12px;" title="' + lnk.type + '">' + icon + '</span>';
                        html += '<span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#334155;" title="' + lnk.url + '"><strong>' + (lnk.title || lnk.url) + '</strong><br/><small style="color:#64748b;">' + lnk.anchor + '</small></span>';
                        html += '<button class="sil-delete-link-btn" title="Supprimer" style="flex-shrink:0; background:none; border:1px solid #f87171; color:#dc2626; border-radius:4px; padding:0 4px; font-size:12px; line-height:1; cursor:pointer;" data-source="' + postId + '" data-target-url="' + lnk.url + '">\u00d7</button>';
                        html += '</li>';
                    });
                    html += '</ul>';
                    // Context Inspector Placeholder
                    html += '<div id="sil-link-inspector" style="display:none; margin-top:10px; padding:10px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); font-size:11px;">';
                    html += '<div style="font-weight:700; color:#1e293b; margin-bottom:5px;">🔍 Inspecteur de Contexte</div>';
                    html += '<div id="sil-inspector-content" style="color:#475569; line-height:1.5;"></div>';
                    html += '</div>';
                    html += '</div>';
                }

                // --- NEW: MISSING INLINKS MODULE ---
                html += '<div id="sil-missing-inlinks-container" style="margin-top:20px; padding-top:15px; border-top:1px solid #e2e8f0;">';
                html += '<strong style="font-size:10px; text-transform:uppercase; color:#0f172a;">🎯 Opportunités de Maillage (Même Silo)</strong>';
                html += '<div id="sil-missing-inlinks-list" style="margin-top:10px;">';
                html += '<div class="spinner is-active" style="float:none; margin:10px auto; display:block;"></div>';
                html += '</div></div>';

                // Actions Footer
                html += '<div style="margin-top:18px; border-top:1px solid #e2e8f0; padding-top:15px; display:flex; gap:8px;">';
                if (d.edit_url) html += '<a href="' + d.edit_url + '" target="_blank" class="button button-secondary" style="flex:1; text-align:center; text-decoration:none; font-size:12px;">\u270f\ufe0f \u00c9diter</a>';
                if (d.view_url) html += '<a href="' + d.view_url + '" target="_blank" class="button" style="flex:1; text-align:center; text-decoration:none; font-size:12px;">\ud83d\udd17 Voir</a>';
                html += '</div>';

                // Manual Bridge creation
                html += '<div style="margin-top:20px; border-top:1px solid #cbd5e1; padding-top:15px; position:relative;">';
                html += '<h4 style="margin:0 0 5px; font-size:13px; color:#1e293b;">\ud83c\udf09 Cr\u00e9er un Pont S\u00e9mantique Manual</h4>';
                html += '<p style="font-size:11px; color:#64748b; margin:0 0 10px;">Cherchez une cible th\u00e9matique pour renforcer le maillage.</p>';
                html += '<input type="text" class="sil-search-target" data-source-id="' + postId + '" placeholder="Saisissez le titre d\'un article..." style="width:100%; font-size:12px; padding:8px; border-radius:6px; border:1px solid #cbd5e1;">';
                html += '<div class="sil-search-results" style="display:none; max-height:180px; overflow-y:auto; background:#fff; border:1px solid #cbd5e1; position:absolute; width:100%; z-index:1000; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); border-radius:0 0 6px 6px; margin-top:-1px;"></div>';
                html += '<div class="sil-anchor-suggestions" style="display:none; margin-top:12px; font-size:11px; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">';
                html += '<p style="color:#475569; margin:0 0 8px 0; font-weight:700; text-transform:uppercase; font-size:9px;">Ancres sugg\u00e9r\u00e9es (GSC) :</p>';
                html += '<div class="sil-chip-container" style="display:flex; flex-wrap:wrap; gap:6px;"></div>';
                html += '</div>';
                html += '</div>';

                html += '</div>'; // End border-top wrapper

                $content.html(html);

                // Load Missing Inlinks via AJAX
                $.post(silGraphData.ajaxurl || ajaxurl, {
                    action: 'sil_get_missing_inlinks',
                    nonce: silGraphData.nonce,
                    post_id: postId
                }, function(res) {
                    const $list = $('#sil-missing-inlinks-list');
                    if (res.success && res.data && res.data.suggestions && res.data.suggestions.length > 0) {
                        let listHtml = '<ul style="margin:0; padding:0; list-style:none;">';
                        res.data.suggestions.forEach(item => {
                            listHtml += `<li style="margin-bottom:10px; padding:10px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; font-size:11px;">
                                <div style="font-weight:700; color:#1e293b; margin-bottom:5px;">${item.title}</div>
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <span style="color:#059669; font-weight:700;">Similarity: ${item.similarity}%</span>
                                    <button class="button button-small sil-reco-bridge-btn" 
                                            data-source="${item.id}" 
                                            data-target="${postId}" 
                                            data-title="${nodeLabel.replace(/"/g,'&quot;')}" 
                                            style="font-size:10px; height:24px; line-height:22px;">
                                        🔗 Lier vers ici
                                    </button>
                                </div>
                            </li>`;
                        });
                        listHtml += '</ul>';
                        $list.html(listHtml);
                    } else {
                        const msg = (res.data && res.data.message) ? res.data.message : 'Aucune opportunité détectée dans ce silo.';
                        $list.html('<p style="font-size:11px; color:#64748b; font-style:italic;">' + msg + '</p>');
                    }
                }).fail(function() {
                    $('#sil-missing-inlinks-list').html('<p style="font-size:11px; color:#ef4444;">Erreur lors du chargement des opportunités.</p>');
                });
            });
        });
        
        function escapeHtml(text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // --- NEW: Premium UI/UX Refinement (Luxury/Refined) ---
        (function injectPremiumStyles() {
            if ($('#sil-premium-styles').length) return;
            const style = `
                <link rel="preconnect" href="https://fonts.googleapis.com">
                <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
                <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Outfit:wght@100..900&display=swap" rel="stylesheet">
                <style id="sil-premium-styles">
                    #sil-graph-sidebar {
                        background: rgba(255, 255, 255, 0.7) !important;
                        backdrop-filter: blur(20px) !important;
                        -webkit-backdrop-filter: blur(20px) !important;
                        border-left: 1px solid rgba(255, 255, 255, 0.3) !important;
                        box-shadow: -10px 0 30px rgba(0,0,0,0.05) !important;
                        font-family: 'DM Sans', sans-serif !important;
                    }
                    .sil-premium-header { font-family: 'Outfit', sans-serif !important; font-weight: 700; letter-spacing: -0.02em; }
                    .sil-staggered { opacity: 0; transform: translateY(10px); animation: sil-fade-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
                    .sil-staggered-1 { animation-delay: 0.1s; }
                    .sil-staggered-2 { animation-delay: 0.2s; }
                    .sil-staggered-3 { animation-delay: 0.3s; }
                    .sil-staggered-4 { animation-delay: 0.4s; }
                    @keyframes sil-fade-in {
                        to { opacity: 1; transform: translateY(0); }
                    }
                    .sil-glass-card {
                        background: rgba(255, 255, 255, 0.5);
                        border: 1px solid rgba(255, 255, 255, 0.8);
                        border-radius: 12px;
                        padding: 16px;
                        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
                        transition: all 0.3s ease;
                    }
                    .sil-glass-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08); }
                    .sil-leak-alert { border-radius: 12px; padding: 14px; display: flex; gap: 12px; border: 1px solid transparent; }
                    .sil-leak-safe { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-color: #86efac; color: #166534; }
                    .sil-leak-danger { background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%); border-color: #fecdd3; color: #9f1239; }
                    .sil-action-btn { 
                        display: block; width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; 
                        background: #fff; color: #1e293b; font-weight: 600; text-align: center; cursor: pointer;
                        transition: all 0.2s ease; font-size: 12px; margin-bottom: 8px;
                    }
                    .sil-action-btn:hover { background: #f8fafc; border-color: #cbd5e1; transform: scale(1.02); }
                    .sil-action-btn-danger { color: #dc2626; border-color: #fee2e2; }
                    .sil-action-btn-danger:hover { background: #fef2f2; border-color: #fecdd3; }
                </style>
            `;
            $('head').append(style);
        })();
        
        // --- NEW: Edge click handler (Link Inspection) ---
        cy.on('tap', 'edge', function(evt) {
            const edge = evt.target;
            const sourceId = edge.data('source');
            const targetId = edge.data('target');
            
            if (sourceId.startsWith('parent-') || targetId.startsWith('parent-')) return;

            const $sidebar = $('#sil-graph-sidebar');
            const $content = $('#sil-sidebar-content');

            $sidebar.show();
            $content.html('<div style="padding:60px 40px; text-align:center;" class="sil-staggered sil-staggered-1"><div class="spinner is-active" style="float:none; margin:0 auto 24px; width:30px; height:30px;"></div><p class="sil-premium-header" style="color:#64748b; font-size:14px; letter-spacing:0.02em;">Analyse du contexte en cours...</p></div>');
            // Calculate proximity based on distance
            const sourceNode = cy.getElementById(sourceId);
            const targetNode = cy.getElementById(targetId);
            let proximityScore = 0;
            if (sourceNode.length && targetNode.length) {
                const p1 = sourceNode.position();
                const p2 = targetNode.position();
                const dist = Math.sqrt(Math.pow(p2.x - p1.x, 2) + Math.pow(p2.y - p1.y, 2));
                // Inverse distance as a score (approximate)
                proximityScore = Math.max(0, Math.min(100, Math.round(100 - (dist / 8))));
            }

            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_get_edge_context',
                nonce: silGraphData.nonce,
                source_id: sourceId,
                target_id: targetId,
                proximity: proximityScore
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    d.proximity = proximityScore; // Add it back to data
                }
                if (!response.success) {
                    $content.html('<div style="padding:40px; text-align:center;" class="sil-staggered sil-staggered-1"><span style="font-size:32px;display:block;margin-bottom:16px;">🔍</span><p class="sil-premium-header" style="color:#ef4444; font-size:14px; letter-spacing:0.02em;">Désolé, impossible de charger le contexte :<br><span style="font-weight:400; font-family:\'DM Sans\'; opacity:0.8;">' + escapeHtml(response.data || 'Erreur inconnue') + '</span></p></div>');
                    return;
                }
                const d = response.data;

                let html = '<div style="padding:16px;">';
                html += '<h4 class="sil-premium-header sil-staggered sil-staggered-1" style="margin:0 0 20px; font-size:18px; color:#0f172a; display:flex; align-items:center; gap:10px;">';
                html += '<span style="background:#f1f5f9; padding:6px; border-radius:8px; font-size:16px;">🔍</span> Inspection du Lien</h4>';
                
                // Connection Info (Glass Card)
                html += '<div class="sil-glass-card sil-staggered sil-staggered-2" style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">';
                html += '<div style="flex:1; text-align:right;"><small style="display:block; color:#64748b; font-size:10px; font-weight:700; text-transform:uppercase;">DE</small><strong style="font-size:12px; color:#334155;">' + escapeHtml(d.source_title) + '</strong></div>';
                html += '<div style="color:#94a3b8; font-size:16px;">→</div>';
                html += '<div style="flex:1;"><small style="display:block; color:#64748b; font-size:10px; font-weight:700; text-transform:uppercase;">VERS</small><strong style="font-size:12px; color:#334155;">' + escapeHtml(d.target_title) + '</strong></div>';
                html += '</div>';
 
                // Semantic Leak Alert
                if (d.is_leak) {
                    const isSafe = d.leak_percent < d.leak_threshold;
                    const alertClass = isSafe ? 'sil-leak-safe' : 'sil-leak-danger';
                    const icon = isSafe ? '✨' : '⚠️';
                    const statusText = isSafe ? 'Fuite Tolérée' : 'Fuite Sémantique !';

                    html += '<div class="sil-leak-alert sil-staggered sil-staggered-3 ' + alertClass + '" style="margin-bottom:20px;">';
                    html += '<span style="font-size:22px;">' + icon + '</span>';
                    html += '<div><strong style="display:block; font-size:14px; margin-bottom:2px;">' + statusText + ' (' + d.leak_percent + '%)</strong>';
                    html += '<span style="font-size:11px; opacity:0.9;">Traversée : <strong>' + escapeHtml(d.source_silo_label) + '</strong> ➔ <strong>' + escapeHtml(d.target_silo_label) + '</strong></span><br>';
                    html += '<small style="opacity:0.7; font-size:10px; font-weight:600;">SEUIL TOLÉRÉ : ' + d.leak_threshold + '%</small></div>';
                    html += '</div>';
                } else {
                    html += '<div class="sil-leak-alert sil-leak-safe sil-staggered sil-staggered-3" style="margin-bottom:20px;">';
                    html += '<span style="font-size:22px;">🛡️</span>';
                    html += '<div><strong style="display:block; font-size:14px; margin-bottom:2px;">Maillage Hermétique</strong>';
                    html += '<span style="font-size:11px; opacity:0.9;">Ce lien reste à l\'intérieur du silo : <strong>' + escapeHtml(d.source_silo_label) + '</strong></span></div>';
                    html += '</div>';
                }
  
                // Semantic Proximity
                html += '<div class="sil-staggered sil-staggered-4" style="background:rgba(248,250,252,0.5); border:1px solid #e2e8f0; border-radius:12px; padding:12px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">';
                html += '<span style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:0.03em;">Proximité Sémantique</span>';
                html += '<div style="display:flex; align-items:center; gap:8px;">';
                html += '<div style="width:80px; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden;"><div style="width:' + d.proximity + '%; height:100%; background:linear-gradient(90deg, #10b981, #3b82f6); border-radius:4px;"></div></div>';
                html += '<strong style="font-size:13px; color:#1e293b;">' + d.proximity + '%</strong>';
                html += '</div></div>';

                // Context & Anchor
                html += '<div class="sil-glass-card sil-staggered sil-staggered-5" style="margin-bottom:20px; position:relative; overflow:hidden;">';
                html += '<div style="position:absolute; top:0; left:0; width:4px; height:100%; background:#10b981;"></div>';
                html += '<strong style="font-size:10px; text-transform:uppercase; color:#64748b; display:block; margin-bottom:10px; letter-spacing:0.05em;">Ancre et Contexte</strong>';
                html += '<div style="font-size:13px; color:#334155; line-height:1.7; font-style:italic;">';
                html += '"...' + escapeHtml(d.context_prev || '') + ' <span style="background:linear-gradient(120deg, #fef08a 0%, #fde047 100%); padding:2px 6px; border-radius:4px; font-weight:800; font-style:normal; color:#854d0e; box-shadow:0 2px 4px rgba(250,204,21,0.2);">' + escapeHtml(d.anchor) + '</span> ' + escapeHtml(d.context_next || '') + '..."';
                html += '</div>';
                html += '</div>';
 
                // Actions
                html += '<div class="sil-staggered sil-staggered-6" style="display:flex; flex-direction:column; gap:4px;">';
                
                // Nofollow Toggle
                const nfClass = d.is_nofollow ? 'button-primary' : '';
                const nfText = d.is_nofollow ? '✨ Rel="nofollow" Actif' : '✋ Passer en nofollow';
                html += '<button class="sil-action-btn sil-toggle-nofollow-btn" data-source="' + sourceId + '" data-target-url="' + escapeHtml(d.target_url) + '">' + nfText + '</button>';
                
                // Delete Link
                html += '<button class="sil-action-btn sil-action-btn-danger sil-delete-link-edge-btn" data-source="' + sourceId + '" data-target-url="' + escapeHtml(d.target_url) + '">🗑️ Supprimer le lien</button>';
                
                html += '</div>';
 
                // Navigation
                html += '<div class="sil-staggered sil-staggered-7" style="margin-top:24px; border-top:1px solid #e2e8f0; padding-top:20px; display:flex; gap:10px;">';
                if (d.source_edit_url) html += '<a href="' + escapeHtml(d.source_edit_url) + '" target="_blank" class="sil-action-btn" style="flex:1; text-decoration:none; margin-bottom:0;">Editer Source</a>';
                if (d.target_edit_url) html += '<a href="' + escapeHtml(d.target_edit_url) + '" target="_blank" class="sil-action-btn" style="flex:1; text-decoration:none; margin-bottom:0;">Editer Cible</a>';
                html += '</div>';
 
                html += '</div>';
 
                $content.html(html);
            });
        });
    } // End of renderCytoscape

        $(document).on('click', '.sil-ai-seo-btn', function() {
            const $btn = $(this);
            const postId = $btn.data('post-id');
            $btn.prop('disabled', true).text('\u23f3 Analyse IA\u2026');
            $('#sil-seo-ai-result').hide();

            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_generate_seo_meta',
                nonce: silGraphData.nonce,
                post_id: postId
            }, function(r) {
                $btn.prop('disabled', false).text('\u2728 R\u00e9\u00e9crire Title/Meta via IA');
                if (r.success && r.data) {
                    const newTitle = r.data.title || '';
                    const newMeta  = r.data.meta  || '';
                    $('#sil-seo-ai-result').html(
                        '<strong style="color:#166534;">Suggestion IA :</strong><br>' +
                        '<strong>Title :</strong> ' + newTitle + '<br>' +
                        '<strong>Meta :</strong> <span style="color:#64748b;">' + newMeta + '</span><br><br>' +
                        '<button class="button button-primary sil-apply-seo-btn" data-post-id="' + postId + '" data-title="' + newTitle.replace(/"/g,'&quot;') + '" data-meta="' + newMeta.replace(/"/g,'&quot;') + '" style="font-size:12px;">\u2705 Appliquer</button>'
                    ).show();
                } else {
                    alert('Erreur IA : ' + (r.data || 'Inconnue'));
                }
            }).fail(() => { $btn.prop('disabled', false).text('\u2728 R\u00e9\u00e9crire Title/Meta via IA'); alert('Erreur r\u00e9seau.'); });
        });

        $(document).on('click', '.sil-apply-seo-btn', function() {
            const $btn = $(this);
            $btn.prop('disabled', true).text('\u23f3 Sauvegarde\u2026');
            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_update_seo_meta',
                nonce: silGraphData.nonce,
                post_id: $btn.data('post-id'),
                new_title: $btn.data('title'),
                new_meta: $btn.data('meta')
            }, function(r) {
                if (r.success) {
                    $('#sil-seo-title-display').text($btn.data('title'));
                    $('#sil-seo-meta-display').text($btn.data('meta'));
                    $('#sil-seo-ai-result').html('<span style="color:#166534;">\u2705 SEO mis \u00e0 jour !</span>');
                } else { alert('Erreur : ' + (r.data || 'Inconnue')); $btn.prop('disabled', false).text('\u2705 Appliquer'); }
            });
        });

        $(document).on('click', '.sil-delete-link-btn, .sil-delete-link-edge-btn', function() {
            const $btn = $(this);
            const isEdgeBtn = $btn.hasClass('sil-delete-link-edge-btn');
            if (!confirm('Supprimer ce lien ? Cette action modifiera le contenu de l\'article source.')) return;
            
            $btn.prop('disabled', true).text('\u2026');
            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_remove_internal_link',
                nonce: silGraphData.nonce,
                source_id: $btn.data('source'),
                target_url: $btn.data('target-url')
            }, function(r) {
                if (r.success) {
                    if (isEdgeBtn) {
                        $('#sil-graph-sidebar').hide();
                        // Supprimer l'edge du graphe if visible
                        if (cy) {
                            const edge = cy.edges().filter(e => 
                                String(e.source().id()) === String($btn.data('source')) && 
                                e.data('target_url') === $btn.data('target-url')
                            );
                            if (edge.length) edge.remove();
                        }
                    } else {
                        $btn.closest('li').fadeOut();
                    }
                } else { 
                    alert('Erreur : ' + (r.data || 'Inconnue')); 
                    $btn.prop('disabled', false).text(isEdgeBtn ? '🗑️ Supprimer' : '\u00d7'); 
                }
            });
        });

        $(document).on('click', '.sil-toggle-nofollow-btn', function() {
            const $btn = $(this);
            const sourceId = $btn.data('source');
            const targetUrl = $btn.data('target-url');
            
            $btn.prop('disabled', true).text('⌛ Mise à jour...');

            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_toggle_edge_nofollow',
                nonce: silGraphData.nonce,
                source_id: sourceId,
                target_url: targetUrl
            }, function(r) {
                if (r.success) {
                    const isNf = r.data.is_nofollow;
                    $btn.prop('disabled', false)
                        .toggleClass('button-primary', isNf)
                        .text(isNf ? '✅ Rel="nofollow" Actif' : '✋ Passer en nofollow');
                } else {
                    alert('Erreur : ' + (r.data || 'Inconnue'));
                    $btn.prop('disabled', false).text('✋ Réessayer');
                }
            });
        });

        $(document).on('click', '.sil-link-item', function(e) {
            if ($(e.target).closest('button').length > 0) return; 
            const $item = $(this);
            const prev = $item.data('context-prev');
            const anchor = $item.data('anchor');
            const next = $item.data('context-next');
            const url = $item.data('url');

            const $inspector = $('#sil-link-inspector');
            const $content = $('#sil-inspector-content');

            $('.sil-link-item').css('background', 'transparent');
            $item.css('background', '#e0f2fe');

            let snippet = prev + ' <strong style="background:#fde047; padding:0 2px; border-radius:2px;">' + anchor + '</strong> ' + next;
            $content.html('<div style="margin-bottom:8px; font-style:italic; border-left:3px solid #cbd5e1; padding-left:8px;">"' + snippet + '"</div>');
            $content.append('<div style="word-break:break-all; font-size:9px; color:#94a3b8;">URL: ' + url + '</div>');
            
            $inspector.slideDown(200);
        });

        // --- LOGIQUE PONT SÉMANTIQUE ---
        function debounce(func, wait) {
            let timeout;
            return function() { const context = this, args = arguments; clearTimeout(timeout); timeout = setTimeout(() => func.apply(context, args), wait); };
        }

        $(document).on('keyup', '.sil-search-target', debounce(function () {
            const $input = $(this);
            const val = $input.val();
            const $container = $input.closest('div');
            const $results = $container.find('.sil-search-results');
            const $anchors = $container.find('.sil-anchor-suggestions');

            if (val.length < 3) {
                $results.empty().hide();
                $anchors.hide();
                return;
            }

            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_search_posts_for_link',
                s: val,
                nonce: silGraphData.nonce
            }, function (response) {
                if (response.success && response.data.length > 0) {
                    let html = '';
                    response.data.forEach(item => {
                        let jsonKw = encodeURIComponent(JSON.stringify(item.keywords || []));
                        let safeTitle = item.title.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                        html += `<div class="sil-search-result-item" style="padding:8px;border-bottom:1px solid #f0f0f1;cursor:pointer;background:#fff;" onmouseover="this.style.background='#f6f7f7'" onmouseout="this.style.background='#fff'" data-id="${item.id}" data-keywords="${jsonKw}">
                            ${safeTitle}
                        </div>`;
                    });
                    $results.html(html).show();
                } else {
                    $results.html('<div style="padding:8px;color:#646970;">Aucun résultat</div>').show();
                }
            });
        }, 300));

        $(document).on('click', '.sil-search-result-item', function () {
            const $item = $(this);
            const targetId = $item.data('id');
            let keywords = [];
            try {
                keywords = JSON.parse(decodeURIComponent($item.data('keywords')));
            } catch(e) { console.error('Erreur parsing keywords', e); }
            const title = $item.text().trim();
            const $container = $item.closest('.sil-search-results').parent();
            const $input = $container.find('.sil-search-target');
            const sourceId = $input.data('source-id');

            $input.val(title);
            $container.find('.sil-search-results').hide();

            const $anchors = $container.find('.sil-anchor-suggestions');
            const $chipContainer = $anchors.find('.sil-chip-container');
            $chipContainer.empty();

            if (keywords && keywords.length > 0) {
                keywords.forEach(kw => {
                    let kwEscaped = kw.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    $chipContainer.append(`<span class="sil-anchor-chip" data-source-id="${sourceId}" data-target-id="${targetId}" data-anchor="${kwEscaped}" style="background:#e0f2fe;color:#0369a1;padding:3px 8px;border-radius:12px;cursor:pointer;border:1px solid #bae6fd;">${kw}</span>`);
                });
            } else {
                $chipContainer.append('<small style="color:#64748b;display:block;width:100%;margin-bottom:8px;">Aucun mot-clé GSC trouvé.</small>');
                $chipContainer.append(`<button class="button button-secondary sil-ai-invent-link-btn" data-source="${sourceId}" data-target="${targetId}">\ud83e\udd16 Inventer une ancre via IA</button>`);
            }
            $anchors.show();
        });

        $(document).on('click', '.sil-anchor-chip', function () {
            const $chip = $(this);
            const anchor = $chip.data('anchor') || $chip.text();
            const targetId = $chip.data('target-id');
            const sourceId = $chip.data('source-id');

            $chip.css('opacity', '0.5').css('pointer-events', 'none');

            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_add_internal_link_from_map',
                source_id: sourceId,
                target_id: targetId,
                anchor_text: anchor,
                nonce: silGraphData.nonce
            }, function (response) {
                if (response.success) {
                    $chip.remove();
                    alert("Lien local ajouté ! (Actualisez le graphe pour voir la nouvelle flèche)");
                } else {
                    const errorMsg = `<div class="sil-inline-error" style="color:#b91c1c;background:#fef2f2;border:1px solid #f87171;padding:10px;margin-top:10px;border-radius:4px;font-size:11px;">
                        ⚠️ <strong>Impossible :</strong><br>${response.data}
                        <div style="margin-top:8px;border-top:1px solid #fca5a5;padding-top:8px;">
                            <button class="button button-primary sil-local-bridge-btn" data-source="${sourceId}" data-target="${targetId}" data-anchor="${anchor}">🌉 Créer un pont sémantique via IA</button>
                        </div>
                    </div>`;
                    $chip.closest('.sil-anchor-suggestions').append(errorMsg);
                    $chip.css('opacity', '1').css('pointer-events', 'auto');
                }
            }).fail(function() {
                alert("Erreur réseau");
                $chip.css('opacity', '1').css('pointer-events', 'auto');
            });
        });

        $(document).on('click', '.sil-ai-invent-link-btn', function (e) {
            e.preventDefault();
            const $btn = $(this);
            $btn.prop('disabled', true).text('⏳...');
            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_invent_anchor_and_link',
                source_id: $btn.data('source'),
                target_id: $btn.data('target'),
                nonce: silGraphData.nonce
            }, function (r) {
                if (r.success) {
                    alert("Lien inventé et inséré !");
                    $btn.hide();
                } else {
                    alert("Erreur IA: " + r.data);
                    $btn.prop('disabled', false).text('🤖 Inventer une ancre via IA');
                }
            });
        });
        $(document).on('click', '.sil-seal-btn', function() {
            const $btn = $(this);
            const sourceId = $btn.data('source');
            const targetId = $btn.data('target');
            
            $btn.prop('disabled', true).html('<span class="spinner is-active"></span> Scellage...');

            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_seal_reciprocal_link',
                nonce: silGraphData.nonce,
                source_id: sourceId,
                target_id: targetId
            }, function(res) {
                if (res.success) {
                    $btn.removeClass('button').addClass('sil-badge sil-badge-success').html('✅ Silo Scellé');
                    // Optionnel : rafraîchir le noeud dans Cytoscape
                    const node = cy.getElementById(String(sourceId));
                    if (node.length) {
                        node.data('has_reciprocal_link', 'true');
                        node.style({ 'border-style': 'solid', 'border-color': '#10b981' });
                    }
                } else {
                    alert(res.data || 'Erreur lors du scellage');
                    $btn.prop('disabled', false).text('🚀 Réessayer le Scellage');
                }
            });
        });


        $(document).on('click', '.sil-reco-bridge-btn', function() {
            const $btn = $(this);
            const sourceId = $btn.data('source');
            const targetId = $btn.data('target');
            const targetTitle = $btn.data('title');
            
            // On switch sur la recherche pour charger les mots-cl\u00e9s de la cible
            const $container = $btn.closest('.sil-sidebar-content');
            const $input = $container.find('.sil-search-target');
            $input.val(targetTitle);
            
            // Simuler la s\u00e9lection pour charger les ancres
            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_search_posts_for_link',
                s: targetTitle,
                nonce: silGraphData.nonce
            }, function (response) {
                if (response.success && response.data.length > 0) {
                    // On prend le premier match qui correspond \u00e0 l'ID
                    const match = response.data.find(m => String(m.id) === String(targetId)) || response.data[0];
                    const keywords = match.keywords || [];
                    const $anchors = $container.find('.sil-anchor-suggestions');
                    const $chipContainer = $anchors.find('.sil-chip-container');
                    $chipContainer.empty();
                    
                    if (keywords.length > 0) {
                        keywords.forEach(kw => {
                            let kwEscaped = kw.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                            $chipContainer.append(`<span class="sil-anchor-chip" data-source-id="${sourceId}" data-target-id="${targetId}" data-anchor="${kwEscaped}" style="background:#e0f2fe;color:#0369a1;padding:3px 8px;border-radius:12px;cursor:pointer;border:1px solid #bae6fd;">${kw}</span>`);
                        });
                    } else {
                        $chipContainer.append('<small style="color:#64748b;display:block;width:100%;margin-bottom:8px;">Aucun mot-cl\u00e9 GSC trouv\u00e9.</small>');
                        $chipContainer.append(`<button class="button button-secondary sil-ai-invent-link-btn" data-source="${sourceId}" data-target="${targetId}">\ud83e\udd16 Inventer une ancre via IA</button>`);
                    }
                    $anchors.show();
                    // Scroll vers le bas
                    $container.animate({ scrollTop: $container[0].scrollHeight }, 500);
                }
            });
        });

        $(document).on('click', '.sil-local-bridge-btn', function () {
            const $btn = $(this);
            $btn.prop('disabled', true).text('⏳ Calcul sémantique...');

            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_generate_bridge_prompt',
                nonce: silGraphData.nonce,
                source_id: $btn.data('source'),
                target_id: $btn.data('target'),
                anchor_text: $btn.data('anchor')
            }, function (response) {
                $btn.prop('disabled', false).text('🌉 Créer un pont sémantique');
                if (response.success) {
                    if (typeof window.openBridgeModal === 'function') {
                        window.openBridgeModal(response.data);
                    }
                } else {
                    alert(response.data);
                }
            });
        });

        $(document).on('click', '#sil-ai-modal-close, #sil-ai-modal-cancel', function () {
            $('#sil-ai-modal-overlay').remove();
        });

        $(document).on('click', '#sil-ai-modal-confirm', function () {
            const $btnModal = $(this);
            $btnModal.prop('disabled', true).text('⏳ Enregistrement...');
            $.post(silGraphData.ajaxurl || ajaxurl, {
                action: 'sil_apply_anchor_context',
                nonce: silGraphData.nonce,
                source_id: $btnModal.data('source'),
                target_id: $btnModal.data('target'),
                original_text: $btnModal.data('original'),
                final_text: $('#sil-ai-modal-editor').val()
            }, function (response) {
                if (response.success) {
                    $('#sil-ai-modal-overlay').remove();
                    $('.sil-inline-error').remove();
                    alert('Pont inséré avec succès ! (Actualisez le graphe pour voir la flèche)');
                } else {
                    alert(response.data);
                    $btnModal.prop('disabled', false).text('Sauvegarder l\'insertion');
                }
            });
        });

    /**
     * Ouvre la modale de création de pont sémantique (Workflow IA).
     * Accessible globalement pour être appelée depuis le Dashboard (admin.js).
     */
    window.openBridgeModal = function(data) {
        const promptText = data.prompt;
        const modalHtml = `
        <div id="sil-ai-modal-overlay">
            <div class="sil-modal-container">
                <div class="sil-modal-header">
                    <h3>
                        <span style="background:var(--sil-primary);color:#fff;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;">🤖</span>
                        Workflow IA : Pont Sémantique Direct
                    </h3>
                    <button id="sil-ai-modal-close" class="sil-modal-close-btn">&times;</button>
                </div>
                <div class="sil-modal-body">
                    <div class="sil-modal-column alt-bg">
                        <label class="sil-modal-label">1. Copiez le Prompt</label>
                        <p class="sil-modal-desc">Utilisez ce contexte structuré dans Gemini ou ChatGPT pour générer un paragraphe d'insertion fluide.</p>
                        <textarea id="sil-prompt-text" class="sil-modal-textarea" readonly>${promptText}</textarea>
                        <button class="button button-secondary" style="margin-top:16px;width:100%;" onclick="const t=document.getElementById('sil-prompt-text');t.select();document.execCommand('copy');this.innerText='✅ Copié !';setTimeout(()=>this.innerText='📋 Copier le prompt',2000);">
                            📋 Copier le prompt
                        </button>
                    </div>
                    <div class="sil-modal-column">
                        <label class="sil-modal-label">2. Collez le résultat HTML</label>
                        <p class="sil-modal-desc">L'IA doit vous retourner un paragraphe contenant le lien <code>&lt;a href="..."&gt;</code>.</p>
                        <textarea id="sil-ai-modal-editor" class="sil-modal-textarea editor" placeholder="Collez ici le paragraphe généré..."></textarea>
                    </div>
                </div>
                <div class="sil-modal-footer">
                    <button id="sil-ai-modal-cancel" class="button">Annuler</button>
                    <button id="sil-ai-modal-confirm" class="button button-primary" data-source="${data.source_id}" data-target="${data.target_id}" data-original="${data.original}">🚀 Sauvegarder l'insertion</button>
                </div>
            </div>
        </div>`;
        $('body').append(modalHtml);
    };
    // End of Document event handlers

    function handleGraphError(msg) {
        $('#sil-graph-loading').hide();
        let displayMsg = msg;
        if (typeof msg === 'object' && msg !== null) {
            displayMsg = (msg.message || 'Erreur inconnue') + 
                         (msg.file ? '<br><small>Fichier: ' + msg.file + ' (Ligne ' + msg.line + ')</small>' : '');
        }
        $container.html('<div style="padding:30px;text-align:center;color:#b91c1c;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;"><strong>\u26a0\ufe0f \u00c9chec :</strong><br>' + displayMsg + '<br><br><button class="button sil-retry-btn">R\u00e9essayer</button></div>');
        $('.sil-retry-btn').on('click', function(e) {
            e.preventDefault();
            location.reload();
        });
    }
});