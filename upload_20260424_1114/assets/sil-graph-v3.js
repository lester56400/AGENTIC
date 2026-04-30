(function($) {
    "use strict";
    $(function () {

    // Fallback for silNotify/silToast (admin.js is NOT loaded on Cartographie page)
    if (typeof window.silNotify !== 'function') {
        window.silNotify = function(msg, type) {
            console.warn('SIL Notify (' + (type || 'info') + '):', msg);
            alert((type === 'error' ? '❌ ' : '✅ ') + msg);
        };
    }
    if (typeof window.silToast !== 'function') {
        window.silToast = window.silNotify;
    }
    if (typeof window.escHtml !== 'function') {
        window.escHtml = function(text) {
            var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text ? String(text).replace(/[&<>"']/g, function(m) { return map[m]; }) : '';
        };
    }
    if (typeof window.escAttr !== 'function') {
        window.escAttr = window.escHtml;
    }

    let cy = null;
    let rawGraphData = null;

    // Debounce helper for search
    function debounce(func, wait) {
        let timeout;
        return function() {
            const context = this, args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), wait);
        };
    }
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
        $.post(silSharedData.ajaxurl || ajaxurl, {
            action: 'sil_get_graph_data',
            nonce: silSharedData.nonce,
            force_refresh: 'true'
        }, function(response) {
            if (response.success) {
                rawGraphData = response.data;
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

        // "Audit AI" Format with Metadata — Enrichi pour exploitation LLM
        const graphData = {
            metadata: {
                site_url: window.location.hostname,
                export_date: new Date().toISOString(),
                node_count: cy.nodes('[^is_silo_parent]').length,
                edge_count: cy.edges().length,
                sil_version: "2.5",
                features: ["sil_pagerank", "permeability", "semantic_collision", "opportunities", "decay_critical"],
                silo_distances: (rawGraphData && rawGraphData.metadata && rawGraphData.metadata.silo_distances) ? rawGraphData.metadata.silo_distances : {}
            },
            elements: cy.elements().map(el => el.data()),
            opportunities: (rawGraphData && rawGraphData.opportunities) ? rawGraphData.opportunities : {},
            stats_summary: (rawGraphData && rawGraphData.stats_summary) ? rawGraphData.stats_summary : {}
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
        $.post(silSharedData.ajaxurl || ajaxurl, {
            action: 'sil_get_graph_data',
            nonce: silSharedData.nonce
        }, function(response) {
            if (response.success) {
                rawGraphData = response.data;
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
            
            // Si jamais la liste est vide (pas de noeud parent), on ignore silencieusement en production
            if (Object.keys(siloLabels).length !== 0) {
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
                    node.data.gsc_impressions = parseInt(node.data.gsc_impressions) || 0;
                    node.data.gsc_clicks = parseInt(node.data.gsc_clicks) || 0;
                    node.data.sil_pagerank = parseFloat(node.data.sil_pagerank || 0);
                    
                    if (node.data.sil_pagerank > maxPagerank) maxPagerank = node.data.sil_pagerank;
                });
            }

            if (data && data.edges) {
                data.edges.forEach(e => { if (e.data.weight > maxWeight) maxWeight = e.data.weight; });
            }

            renderCytoscape(data, maxPagerank, maxWeight, siloLabels);
        } catch (e) {
            handleGraphError("Erreur de traitement des donnees locales : " + e.message);
        }
    }

    // PHASE 15: Detect if semantic projection is available
    let hasProjection = false;
    let projectionCoords = {};

    function renderCytoscape(data, maxPagerank, maxWeight, siloLabels) {
        try {
            // --- PHASE 15: Semantic Projection Mode ---
            if (data && data.metadata && data.metadata.projection_coords) {
                hasProjection = true;
                projectionCoords = data.metadata.projection_coords;

                // Strip 'parent' from article nodes to remove compound nesting
                if (data.nodes) {
                    data.nodes.forEach(node => {
                        if (node.data && !node.data.is_silo_parent) {
                            delete node.data.parent;
                        }
                    });
                }
            }

            // Init Cytoscape
            cy = cytoscape({
                container: $container[0],
                elements: data,
                style: [
                    {
                        selector: 'node[^is_silo_parent]',
                        style: {
                            'label': 'data(label)',
                            'width': 'mapData(gsc_impressions, 0, 1500, 90, 200)',
                            'height': 'mapData(gsc_impressions, 0, 1500, 90, 200)',
                            'background-color': n => getColorForCluster(n.data('cluster_id')),
                            'color': '#0f172a',
                            'font-size': '14px',
                            'font-weight': 'bold',
                            'min-zoomed-font-size': 8,
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
                            'display': hasProjection ? 'none' : 'element',
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
                    },
                    {
                        selector: '.ghost-edge',
                        style: {
                            'visibility': 'hidden',
                            'width': 0,
                            'pointer-events': 'none',
                            'curve-style': 'haystack' // High performance for invisible edges
                        }
                    }
                ]
            });
        } catch (err) {
            handleGraphError("Erreur d'initialisation du graphe : " + err.message + ". Essayez de vider le cache du navigateur.");
            return;
        }

        // PHASE 15: Layout conditionnel — Preset (sémantique) ou Cose (topologique)
        const layoutConfig = hasProjection ? {
            name: 'preset',
            positions: function(node) {
                const id = node.id();
                if (projectionCoords[id]) {
                    return { x: projectionCoords[id].x, y: projectionCoords[id].y };
                }
                // Silo parents or nodes without embedding: place at origin
                return { x: 0, y: 0 };
            },
            fit: true,
            padding: 60
        } : {
            name: 'cose',
            nodeDimensionsIncludeLabels: false,
            idealEdgeLength: 150,
            nodeOverlap: 40,
            refresh: 20,
            fit: true,
            padding: 50,
            randomize: false,
            componentSpacing: parseInt(silSharedData.spacing) || 120,
            nodeRepulsion: function( node ){ 
                let baseRepulsion = parseInt(silSharedData.repulsion) || 8000;
                return baseRepulsion + (node.width() * 50); 
            }, 
            gravity: parseFloat(silSharedData.gravity) || 1.5,                      
            edgeElasticity: function( edge ){ 
                return edge.data('is_ghost') ? 50 : 100; 
            },
            nestingFactor: 0.8,
            numIter: 1500,
            initialTemp: 200,
            coolingFactor: 0.95,
            minTemp: 1.0,
            animate: false
        };

        const layout = cy.layout(layoutConfig);
        layout.run();

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
            // Silo 0 repositioning failure is non-fatal
        }

        try { cy.fit(null, 40); } catch (e) {}

        // --- PHASE 15: Canvas Underlay (Silo Heatmap) ---
        if (hasProjection) {
            try {
                const underlayCanvas = document.createElement('canvas');
                underlayCanvas.id = 'sil-semantic-underlay';
                underlayCanvas.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:0;';
                $container[0].insertBefore(underlayCanvas, $container[0].firstChild);

                function drawSiloHeatmap() {
                    const canvas = document.getElementById('sil-semantic-underlay');
                    if (!canvas || !cy) return;
                    const rect = $container[0].getBoundingClientRect();
                    canvas.width = rect.width;
                    canvas.height = rect.height;
                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);

                    // Group article nodes by cluster_id
                    const clusterNodes = {};
                    cy.nodes().forEach(n => {
                        if (n.data('is_silo_parent')) return;
                        const cid = n.data('cluster_id');
                        if (!cid || cid === '0' || cid === '1000') return;
                        if (!clusterNodes[cid]) clusterNodes[cid] = [];
                        const rp = n.renderedPosition();
                        clusterNodes[cid].push(rp);
                    });

                    Object.keys(clusterNodes).forEach(cid => {
                        const points = clusterNodes[cid];
                        if (points.length < 2) return;

                        // Barycentre 2D
                        let cx = 0, cy2 = 0;
                        points.forEach(p => { cx += p.x; cy2 += p.y; });
                        cx /= points.length;
                        cy2 /= points.length;

                        // Rayon = écart-type
                        let variance = 0;
                        points.forEach(p => {
                            variance += (p.x - cx) ** 2 + (p.y - cy2) ** 2;
                        });
                        const radius = Math.max(80, Math.sqrt(variance / points.length) * 1.8);

                        const color = getColorForCluster(cid);
                        const grad = ctx.createRadialGradient(cx, cy2, 0, cx, cy2, radius);
                        grad.addColorStop(0, color + '22');
                        grad.addColorStop(0.5, color + '11');
                        grad.addColorStop(1, color + '00');

                        ctx.fillStyle = grad;
                        ctx.beginPath();
                        ctx.arc(cx, cy2, radius, 0, Math.PI * 2);
                        ctx.fill();
                    });
                }

                cy.on('render viewport', drawSiloHeatmap);
                setTimeout(drawSiloHeatmap, 300);
            } catch (heatmapErr) {
                console.warn('[SIL] Heatmap underlay error:', heatmapErr);
            }

            // --- PHASE 15: Edge Tension Coloring ---
            try {
                cy.edges().forEach(edge => {
                    if (edge.hasClass('ghost-edge')) return;
                    const s = cy.getElementById(edge.data('source'));
                    const t = cy.getElementById(edge.data('target'));
                    if (!s.length || !t.length) return;
                    const dx = s.position('x') - t.position('x');
                    const dy = s.position('y') - t.position('y');
                    const dist = Math.sqrt(dx * dx + dy * dy);
                    const ratio = Math.min(1, dist / 1200);
                    const r = Math.round(20 + ratio * 219);
                    const g = Math.round(185 - ratio * 145);
                    const b = Math.round(80 - ratio * 40);
                    edge.style('line-color', `rgb(${r}, ${g}, ${b})`);
                    edge.style('target-arrow-color', `rgb(${r}, ${g}, ${b})`);
                    edge.data('_sem_dist', dist);
                    edge.data('_tension_ratio', Math.min(1, dist / 1200));
                });
            } catch (tensionErr) {
                console.warn('[SIL] Edge tension coloring error:', tensionErr);
            }

            // --- PHASE 15: Tension Slider (protected, always shows if projection exists) ---
            $('#sil-tension-slider-wrap').css('display', 'flex');
            $('#sil-tension-slider').on('input', function() {
                const sliderVal = parseInt($(this).val());
                const threshold = sliderVal / 100;
                $('#sil-tension-value').text(sliderVal + '%');
                
                cy.batch(() => {
                    cy.edges().forEach(edge => {
                        if (edge.hasClass('ghost-edge')) return;
                        const tensionRatio = edge.data('_tension_ratio') || 0;
                        if (sliderVal === 0) {
                            edge.style('opacity', 0.6);
                        } else if (tensionRatio >= threshold) {
                            edge.style('opacity', 0.9);
                            edge.style('width', 3);
                        } else {
                            edge.style('opacity', 0.04);
                            edge.style('width', 1);
                        }
                    });
                });
            });
        }

        $('#sil-graph-loading').fadeOut(300);

        // NOUVEAU : Auto-focus basé sur l'URL
        const urlParams = new URLSearchParams(window.location.search);
        const focusId = urlParams.get('focus');
        
        if (focusId && cy) {
            const targetNode = cy.getElementById(focusId);
            if (targetNode.length > 0) {
                // Petit délai pour laisser Cytoscape finir son layout initial
                setTimeout(() => {
                    cy.animate({
                        center: { eles: targetNode },
                        zoom: 1.5
                    }, { duration: 800 });
                    
                    // Simule le clic pour ouvrir la sidebar et mettre en surbrillance
                    targetNode.trigger('tap'); 
                }, 500);
            }
        } else {
            // Comportement normal si pas de paramètre focus
            setTimeout(() => {
                cy.resize();
                cy.fit(null, 50);
            }, 200);
        }

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
                const idStr = String(n.data('id') || '');
                if (label.includes(search) || idStr.includes(search)) {
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
            $sidebar.css('display', 'flex');
            $content.html('<div style="padding:40px; text-align:center;"><div class="spinner is-active" style="float:none; margin:0 auto 20px;"></div><p style="color:#64748b; font-size:13px;">Analyse s\u00e9mantique en cours...</p></div>');

            $.post(silSharedData.ajaxurl || ajaxurl, {
                action: 'sil_get_node_details',
                nonce: silSharedData.nonce,
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
                    html += '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:8px;margin-bottom:10px;font-size:12px;display:flex;align-items:center;gap:8px;">' + silSharedData.icons.star + ' <strong style="color:#b45309;">Pilier de Silo (Cornerstone)</strong> — Page d\'autorité principale.</div>';
                }

                if (isOrphan) {
                    html += '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:8px;margin-bottom:10px;font-size:12px;display:flex;align-items:center;gap:8px;">' + silSharedData.icons.flag + ' <strong style="color:#b91c1c;">Page Orpheline</strong> — Aucun lien interne entrant.</div>';
                }
                
                // Reciprocity Module (Silo Sealing)
                else if (!isStrategic && !hasReciprocity && cornerstoneId && String(clusterId) !== '0') {
                    html += `<div class="sil-reciprocity-block">
                        <h5>${silSharedData.icons.anchor || '🔗'} Maillage Réciproque Manquant</h5>
                        <p>Cet article appartient au <strong>${siloLabel}</strong> mais ne renvoie pas de lien vers son Pilier. Cela affaiblit la structure du silo.</p>
                        <button class="button sil-seal-btn" data-source="${postId}" data-target="${cornerstoneId}">
                            🚀 Sceller le Silo (Lien vers Pilier)
                        </button>
                    </div>`;
                }
 else if (isSiphon) {
                    html += '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:8px;margin-bottom:10px;font-size:12px;display:flex;align-items:center;gap:8px;">' + silSharedData.icons.droplets + ' <strong style="color:#c2410c;">Page Siphon</strong> — Capte le PageRank sans le redistribuer (Cul-de-sac).</div>';
                } else if (isIntruder) {
                    const proximity = (d.proximity || 0);
                    const proxPercent = Math.round(proximity * 100);
                    
                    // On distingue l'intrus sémantique (mauvais sujet) du parasite (mauvais maillage)
                    const isPureSemanticIntruder = proximity < 0.6;
                    const intruderIcon = isPureSemanticIntruder ? silSharedData.icons.ghost : silSharedData.icons.target;
                    const intruderTitle = isPureSemanticIntruder ? 'Intrus Sémantique Identifié' : 'Déséquilibre de Maillage (Parasite)';
                    
                    html += '<div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;padding:12px;margin-bottom:15px;font-size:12px;">';
                    html += '<div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">';
                    html += `${intruderIcon} <strong style="color:#6d28d9;">${intruderTitle}</strong>`;
                    html += '</div>';
                    
                    if (isPureSemanticIntruder) {
                        html += '<p style="margin:0 0 10px 0; color:#4338ca;">Ce contenu n\'est pas \u00e0 sa place. Sa coh\u00e9sion th\u00e9matique avec ce silo est de seulement <strong>' + proxPercent + '%</strong>.</p>';
                        
                        if (d.recommended_silo_name && d.closest_content_title) {
                            html += '<div style="margin-top:8px; padding-top:8px; border-top:1px solid #ddd6fe;">';
                            html += '<div style="display:flex; align-items:flex-start; gap:8px; color:#1e293b; line-height:1.4;">' + silSharedData.icons.lightbulb + ' <span><strong>Conseil IA :</strong> Déplacez cet article vers le silo <strong>' + d.recommended_silo_name + '</strong> (proche de <em>"' + d.closest_content_title + '"</em>).</span></div>';
                            html += '<div style="margin-top:12px; text-align:center;">';
                            html += '<button class="button button-primary sil-reco-bridge-btn" data-source="' + postId + '" data-target="' + d.closest_content_id + '" data-title="' + d.closest_content_title.replace(/"/g,'&quot;') + '" style="font-size:11px; padding:6px 16px; height:auto; line-height:1.4; border-radius:20px; display:inline-flex; align-items:center; gap:8px;">' + silSharedData.icons.bridge + ' Créer le prompt pour le pont</button>';
                            html += '</div></div>';
                        }
                    } else {
                        html += '<p style="margin:0 0 10px 0; color:#4338ca;">Cet article est th\u00e9matiquement \u00e0 sa place (<strong>' + proxPercent + '%</strong>) mais se comporte comme un "Parasite" : il ne renvoie aucun lien vers son propre silo.</p>';
                        html += '<div style="margin-top:8px; padding-top:8px; border-top:1px solid #ddd6fe;">';
                        html += '<div style="display:flex; align-items:flex-start; gap:8px; color:#1e293b; line-height:1.4;">' + silSharedData.icons.lightbulb + ' <span><strong>Conseil IA :</strong> Ajoutez des liens sortants depuis cet article vers d\'autres pages de son silo actuel (<strong>' + siloLabel + '</strong>).</span></div>';
                        
                        if (cornerstoneId && cornerstoneId !== postId) {
                            html += '<div style="margin-top:12px; text-align:center;">';
                            html += '<button class="button button-primary sil-seal-btn" data-source="' + postId + '" data-target="' + cornerstoneId + '" style="font-size:11px; padding:6px 16px; height:auto; line-height:1.4; border-radius:20px; display:inline-flex; align-items:center; gap:8px;">' + silSharedData.icons.link + ' Lier au Pilier du Silo</button>';
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

                // Manual Bridge creation
                html += '<div style="margin-top:20px; border-top:1px solid #cbd5e1; padding-top:15px; position:relative;">';
                html += '<h4 style="margin:0 0 5px; font-size:13px; color:#1e293b;">🔍 Chercher une Cible Manuelle</h4>';
                html += '<p style="font-size:11px; color:#64748b; margin:0 0 10px;">Renforcez le maillage en liant vers un article thématique.</p>';
                html += '<input type="text" class="sil-search-target" data-source-id="' + postId + '" placeholder="Saisissez le titre d\'article cible..." style="width:100%; font-size:12px; padding:8px; border-radius:6px; border:1px solid #cbd5e1;">';
                html += '<div class="sil-search-results" style="display:none; max-height:180px; overflow-y:auto; background:#fff; border:1px solid #cbd5e1; position:absolute; width:100%; z-index:1000; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); border-radius:0 0 6px 6px; margin-top:-1px;"></div>';
                html += '<div class="sil-anchor-suggestions" style="display:none; margin-top:12px; font-size:11px; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">';
                html += '<p style="color:#475569; margin:0 0 8px 0; font-weight:700; text-transform:uppercase; font-size:9px;">Ancres GSC / Suggestions :</p>';
                html += '<div class="sil-chip-container" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px;"></div>';
                html += '<div style="border-top: 1px dashed #cbd5e1; padding-top:10px; display:flex; gap:8px;">';
                html += '<button class="button button-primary sil-manual-bridge-trigger" id="sil-sidebar-manual-btn" data-source="' + postId + '" style="flex:1; font-size:10px;">🌉 Créer le prompt pour le pont</button>';
                html += '</div>';
                html += '</div>';
                html += '</div>';

                // SEO Data
                html += '<div style="background:#f1f5f9; border-radius:8px; padding:12px; margin-top:20px; border-left:4px solid #3b82f6;">';
                html += '<strong style="font-size:10px; text-transform:uppercase; color:#64748b;">SEO (RankMath)</strong>';
                html += '<div id="sil-seo-title-display" style="margin:6px 0 4px; font-size:12px; font-weight:700; color:#0f172a;">' + (d.seo_title || nodeLabel) + '</div>';
                html += '<div id="sil-seo-meta-display" style="font-size:11px; color:#475569; line-height:1.4;">' + (d.seo_meta || 'Aucune meta description d\u00e9tect\u00e9e.') + '</div>';
                html += '</div>';

                html += '<button class="button sil-ai-seo-btn" data-post-id="' + postId + '" style="width:100%; margin-top:12px; margin-bottom:12px; font-size:12px; background:#fff; border-color:#d1d5db;">\u2728 R\u00e9\u00e9crire via IA</button>';
                html += '<div id="sil-seo-ai-result" style="display:none;margin-bottom:15px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:12px;box-shadow:0 1px 2px rgba(0,0,0,0.05);"></div>';

                // Recommendations Section
                if (d.semantic_recommendations && d.semantic_recommendations.length > 0) {
                    html += '<div style="margin-bottom:20px; padding:12px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px;">';
                    html += '<h4 style="margin:0 0 10px; font-size:13px; color:#0369a1;">\ud83d\udca1 Suggestions de R\u00e9assignation</h4>';
                    html += '<ul style="margin:0; padding:0; list-style:none;">';
                    d.semantic_recommendations.forEach(function(reco) {
                        html += '<li style="margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid #e0f2fe; last-child:border-bottom:none;">';
                        html += '<div style="font-size:11px; font-weight:600; color:#1e293b; margin-bottom:4px;">' + reco.title + ' <span style="color:#0ea5e9; font-weight:700;">(' + reco.score + '%)</span></div>';
                        html += '<div style="display:flex; gap:5px;">';
                        html += '<button class="button button-primary sil-reco-bridge-btn sil-manual-bridge-trigger" data-source="' + postId + '" data-target="' + reco.id + '" data-title="' + reco.title.replace(/"/g,'&quot;') + '" style="font-size:10px; padding:2px 8px; flex:1;">🌉 Créer le prompt pour le pont</button>';
                        html += '</div>';
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

                // Actions Footer
                html += '<div style="margin-top:18px; border-top:1px solid #e2e8f0; padding-top:15px; display:flex; gap:8px;">';
                if (d.edit_url) html += '<a href="' + d.edit_url + '" target="_blank" class="button button-secondary" style="flex:1; text-align:center; text-decoration:none; font-size:12px;">\u270f\ufe0f \u00c9diter</a>';
                if (d.view_url) html += '<a href="' + d.view_url + '" target="_blank" class="button" style="flex:1; text-align:center; text-decoration:none; font-size:12px;">\ud83d\udd17 Voir</a>';
                html += '</div>';

                // --- NEW: MISSING INLINKS MODULE ---
                html += '<div id="sil-missing-inlinks-container" style="margin-top:20px; padding-top:15px; border-top:1px solid #e2e8f0;">';
                html += '<strong style="font-size:10px; text-transform:uppercase; color:#0f172a;">🎯 Opportunités de Maillage (Même Silo)</strong>';
                html += '<div id="sil-missing-inlinks-list" style="margin-top:10px;">';
                html += '<div class="spinner is-active" style="float:none; margin:10px auto; display:block;"></div>';
                html += '</div></div>';

                html += '</div>'; // End border-top wrapper

                $content.html(html);

                // Load Missing Inlinks via AJAX
                $.post(silSharedData.ajaxurl || ajaxurl, {
                    action: 'sil_get_missing_inlinks',
                    nonce: silSharedData.nonce,
                    post_id: postId
                }, function(res) {
                    const $list = $('#sil-missing-inlinks-list');
                    if (res.success && res.data && res.data.suggestions && res.data.suggestions.length > 0) {
                        let nativeList = res.data.suggestions.filter(s => s.is_native);
                        let bridgeList = res.data.suggestions.filter(s => !s.is_native);
                        
                        let listHtml = '';
                        
                        if (nativeList.length > 0) {
                            listHtml += `<div style="padding:10px 0 5px; font-size:11px; font-weight:800; color:#1e293b; text-transform:uppercase; letter-spacing:0.05em;">🎯 Même Silo (Rectangle)</div>`;
                            listHtml += '<ul style="margin:0 0 15px 0; padding:0; list-style:none;">';
                            nativeList.forEach(item => {
                                listHtml += `<li class="sil-staggered sil-glass-card" style="margin-bottom:8px; padding:12px; font-size:11px; border-left: 3px solid #6366f1;">
                                    <div style="font-weight:700; color:#1e293b; margin-bottom:5px;">${item.title}</div>
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <span style="color:#6366f1; font-weight:700;">Affinité: ${item.similarity}%</span>
                                        <button class="button button-small sil-manual-bridge-trigger" 
                                                data-source="${item.id}" 
                                                data-target="${postId}" 
                                                data-title="${escapeHtml(nodeLabel)}" 
                                                style="font-size:10px; height:24px; line-height:22px; background:#6366f1; color:#fff; border:none;">
                                            🔗 Lier
                                        </button>
                                    </div>
                                </li>`;
                            });
                            listHtml += '</ul>';
                        }
                        
                        if (bridgeList.length > 0) {
                            listHtml += `<div style="padding:10px 0 5px; font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">🌉 Ponts Sémantiques (Voisins)</div>`;
                            listHtml += '<ul style="margin:0; padding:0; list-style:none;">';
                            bridgeList.forEach(item => {
                                listHtml += `<li class="sil-staggered sil-glass-card" style="margin-bottom:8px; padding:12px; font-size:11px; border-left: 3px solid #94a3b8; opacity: 0.8;">
                                    <div style="font-weight:700; color:#64748b; margin-bottom:5px;">${item.title} <small style="font-weight:400; color:#94a3b8;">(Silo ${item.primary_silo_id})</small></div>
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <span style="color:#94a3b8; font-weight:700;">Affinité: ${item.similarity}%</span>
                                        <button class="button button-small sil-manual-bridge-trigger" 
                                                data-source="${item.id}" 
                                                data-target="${postId}" 
                                                data-title="${escapeHtml(nodeLabel)}" 
                                                style="font-size:10px; height:24px; line-height:22px; border-color:#cbd5e1; color:#64748b;">
                                            🔗 Créer pont
                                        </button>
                                    </div>
                                </li>`;
                            });
                            listHtml += '</ul>';
                        }
                        
                        $list.html(listHtml);
                    } else {
                        const msg = (res.data && res.data.message) ? res.data.message : 'Aucune opportunité détectée dans ce silo.';
                        $list.html('<p style="font-size:11px; color:#64748b; font-style:italic; padding:10px;">' + msg + '</p>');
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
                        background: rgba(255, 255, 255, 0.95) !important;
                        backdrop-filter: blur(10px) !important;
                        border-left: 2px solid #000 !important;
                        box-shadow: -10px 0 30px rgba(0,0,0,0.1) !important;
                        font-family: 'DM Sans', sans-serif !important;
                    }
                    .sil-swiss-mode #sil-graph-sidebar {
                        background: #fff !important;
                        border-left: 4px solid #000 !important;
                        font-family: 'Inter', sans-serif !important;
                    }
                    .sil-premium-header { font-family: 'Outfit', sans-serif !important; font-weight: 700; letter-spacing: -0.02em; }
                    .sil-swiss-mode .sil-premium-header { font-family: 'Inter', sans-serif !important; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 900; }
                    
                    .sil-staggered { opacity: 0; transform: translateY(10px); animation: sil-fade-in 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
                    .sil-staggered-1 { animation-delay: 0.05s; }
                    .sil-staggered-2 { animation-delay: 0.1s; }
                    .sil-staggered-3 { animation-delay: 0.15s; }
                    .sil-staggered-4 { animation-delay: 0.2s; }
                    
                    @keyframes sil-fade-in {
                        to { opacity: 1; transform: translateY(0); }
                    }
                    .sil-glass-card {
                        background: rgba(255, 255, 255, 0.5);
                        border: 1px solid rgba(255, 255, 255, 0.8);
                        border-radius: 12px;
                        padding: 16px;
                        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                        transition: all 0.3s ease;
                    }
                    .sil-swiss-mode .sil-glass-card {
                        background: #fff;
                        border: 2px solid #000;
                        border-radius: 0;
                        box-shadow: 4px 4px 0 #000;
                        margin-bottom: 15px;
                    }
                    .sil-leak-alert { border-radius: 12px; padding: 14px; display: flex; gap: 12px; border: 1px solid transparent; }
                    .sil-swiss-mode .sil-leak-alert { border-radius: 0; border: 2px solid #000; font-family: var(--sil-swiss-mono); }
                    
                    .sil-leak-safe { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-color: #86efac; color: #166534; }
                    .sil-leak-danger { background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%); border-color: #fecdd3; color: #9f1239; }
                    
                    .sil-swiss-mode .sil-leak-safe { background: #fff !important; border-color: #10b981 !important; color: #10b981 !important; }
                    .sil-swiss-mode .sil-leak-danger { background: #000 !important; border-color: #ef4444 !important; color: #ef4444 !important; }
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

            $sidebar.css('display', 'flex');
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

            $.post(silSharedData.ajaxurl || ajaxurl, {
                action: 'sil_get_edge_context',
                nonce: silSharedData.nonce,
                source_id: sourceId,
                target_id: targetId,
                proximity: proximityScore
            }, function(response) {
                if (response.success) {
                    const d = response.data;
                    // Use backend proximity if available, otherwise fallback to visual distance
                    if (typeof d.proximity === 'undefined' || d.proximity === 0) {
                        d.proximity = proximityScore;
                    }
                }
                if (!response.success) {
                    $content.html('<div class="sil-graph-error-box sil-staggered sil-staggered-1"><span>🔍</span><p>Désolé, impossible de charger le contexte :<br><span>' + escapeHtml(response.data || 'Erreur inconnue') + '</span></p></div>');
                    return;
                }
                const d = response.data;

                let html = '<div style="padding:16px;">';
                html += '<h4 class="sil-premium-header sil-staggered sil-staggered-1">Inspection du Lien</h4>';
                
                html += '<div class="sil-glass-card sil-staggered sil-staggered-2" style="display:flex; flex-direction:column; gap:10px; padding:20px;">';
                
                html += '<div style="display:flex; align-items:center; gap:12px;">';
                html += '<div style="background:#000; color:#fff; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-family:var(--sil-swiss-mono); font-size:10px; font-weight:900; flex-shrink:0;">DE</div>';
                html += '<div style="font-weight:900; font-size:13px; color:#000; line-height:1.2; flex:1;">' + escapeHtml(d.source_title) + '</div>';
                html += '</div>';

                html += '<div style="padding-left:36px; color:#000; font-size:18px; font-weight:900;">↓</div>';

                html += '<div style="display:flex; align-items:center; gap:12px;">';
                html += '<div style="background:#000; color:#fff; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-family:var(--sil-swiss-mono); font-size:10px; font-weight:900; flex-shrink:0;">À</div>';
                html += '<div style="font-weight:900; font-size:13px; color:#000; line-height:1.2; flex:1;">' + escapeHtml(d.target_title) + '</div>';
                html += '</div>';
                
                html += '</div>';
 
                if (d.is_leak) {
                    const isSafe = d.leak_percent < d.leak_threshold;
                    const bgColor = isSafe ? '#f0fdf4' : '#fee2e2';
                    const borderColor = isSafe ? '#86efac' : '#f87171';
                    const icon = isSafe ? '✨' : '⚠️';
                    const titleColor = isSafe ? '#166534' : '#b91c1c';
                    const textColor = isSafe ? '#15803d' : '#7f1d1d';
                    const statusText = isSafe ? 'Fuite Tolérée' : 'Fuite Sémantique !';

                    html += '<div class="sil-staggered sil-staggered-3" style="background:' + bgColor + '; border:1px solid ' + borderColor + '; padding:12px; margin-bottom:15px; font-size:12px; border-radius:6px;">';
                    html += '<div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">';
                    html += '<span>' + icon + '</span>';
                    html += '<strong style="color:' + titleColor + ';">' + statusText + ' (' + d.leak_percent + '%)</strong>';
                    html += '</div>';
                    html += '<div style="color:' + textColor + '; font-size:11px; line-height:1.4;">';
                    html += 'Le jus s\'échappe vers <strong>' + escapeHtml(d.target_silo_label) + '</strong>';
                    html += '<br><small style="opacity:0.7; text-transform:uppercase;">SEUIL : ' + d.leak_threshold + '%</small>';
                    html += '</div>';
                    html += '</div>';
                } else {
                    html += '<div class="sil-leak-alert sil-leak-safe sil-staggered sil-staggered-3">';
                    html += '<span>🛡️</span>';
                    html += '<div><strong>Maillage Hermétique</strong>';
                    html += '<span>Ce lien reste à l\'intérieur du silo : <strong>' + escapeHtml(d.source_silo_label) + '</strong></span></div>';
                    html += '</div>';
                }
  
                html += '<div class="sil-staggered sil-staggered-4 sil-glass-card">';
                html += '<span>Proximité Sémantique</span>';
                html += '<div style="display:flex; align-items:center; gap:8px;">';
                html += '<div style="width:80px; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden;"><div style="width:' + d.proximity + '%; height:100%; background:linear-gradient(90deg, #10b981, #3b82f6); border-radius:4px;"></div></div>';
                html += '<strong>' + d.proximity + '%</strong>';
                html += '</div></div>';

                html += '<div class="sil-glass-card sil-staggered sil-staggered-5">';
                html += '<strong>Ancre et Contexte</strong>';
                html += '<div>"' + escapeHtml(d.context_prev || '') + ' <span>' + escapeHtml(d.anchor) + '</span> ' + escapeHtml(d.context_next || '') + '..."</div>';
                html += '</div>';
 
                html += '<div class="sil-staggered sil-staggered-6">';
                const nfClass = d.is_nofollow ? 'button-primary' : '';
                const nfText = d.is_nofollow ? '✨ Rel="nofollow" Actif' : '✋ Passer en nofollow';
                html += '<button class="sil-action-btn sil-toggle-nofollow-btn" data-source="' + sourceId + '" data-target-url="' + escapeHtml(d.target_url) + '">' + nfText + '</button>';
                html += '<button class="sil-action-btn sil-action-btn-danger sil-delete-link-edge-btn" data-source="' + sourceId + '" data-target-url="' + escapeHtml(d.target_url) + '">🗑️ Supprimer le lien</button>';
                html += '</div>';
 
                html += '<div class="sil-staggered sil-staggered-7">';
                if (d.source_edit_url) html += '<a href="' + escapeHtml(d.source_edit_url) + '" target="_blank" class="sil-action-btn">Editer Source</a>';
                if (d.target_edit_url) html += '<a href="' + escapeHtml(d.target_edit_url) + '" target="_blank" class="sil-action-btn">Editer Cible</a>';
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

            $.post(silSharedData.ajaxurl || ajaxurl, {
                action: 'sil_generate_seo_meta',
                nonce: silSharedData.nonce,
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
                    window.silToast('Erreur IA : ' + (r.data || 'Inconnue'), 'error');
                }
            }).fail(() => { $btn.prop('disabled', false).text('\u2728 R\u00e9\u00e9crire Title/Meta via IA'); window.silToast('Erreur r\u00e9seau.', 'error'); });
        });

        $(document).on('click', '.sil-apply-seo-btn', function() {
            const $btn = $(this);
            $btn.prop('disabled', true).text('\u23f3 Sauvegarde\u2026');
            $.post(silSharedData.ajaxurl || ajaxurl, {
                action: 'sil_update_seo_meta',
                nonce: silSharedData.nonce,
                post_id: $btn.data('post-id'),
                new_title: $btn.data('title'),
                new_meta: $btn.data('meta')
            }, function(r) {
                if (r.success) {
                    $('#sil-seo-title-display').text($btn.data('title'));
                    $('#sil-seo-meta-display').text($btn.data('meta'));
                    $('#sil-seo-ai-result').html('<span style="color:#166534;">\u2705 SEO mis \u00e0 jour !</span>');
                } else { window.silToast('Erreur : ' + (r.data || 'Inconnue'), 'error'); $btn.prop('disabled', false).text('\u2705 Appliquer'); }
            });
        });

        $(document).on('click', '.sil-delete-link-btn, .sil-delete-link-edge-btn', function() {
            const $btn = $(this);
            const isEdgeBtn = $btn.hasClass('sil-delete-link-edge-btn');
            if (!confirm('Supprimer ce lien ? Cette action modifiera le contenu de l\'article source.')) return;
            
            $btn.prop('disabled', true).text('\u2026');
            $.post(silSharedData.ajaxurl || ajaxurl, {
                action: 'sil_remove_internal_link',
                nonce: silSharedData.nonce,
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
                    window.silToast('Erreur : ' + (r.data || 'Inconnue'), 'error'); 
                    $btn.prop('disabled', false).text(isEdgeBtn ? '🗑️ Supprimer' : '\u00d7'); 
                }
            });
        });

        $(document).on('click', '.sil-toggle-nofollow-btn', function() {
            const $btn = $(this);
            const sourceId = $btn.data('source');
            const targetUrl = $btn.data('target-url');
            
            $btn.prop('disabled', true).text('⌛ Mise à jour...');

            $.post(silSharedData.ajaxurl || ajaxurl, {
                action: 'sil_toggle_edge_nofollow',
                nonce: silSharedData.nonce,
                source_id: sourceId,
                target_url: targetUrl
            }, function(r) {
                if (r.success) {
                    const isNf = r.data.is_nofollow;
                    $btn.prop('disabled', false)
                        .toggleClass('button-primary', isNf)
                        .text(isNf ? '✅ Rel="nofollow" Actif' : '✋ Passer en nofollow');
                } else {
                    window.silToast('Erreur : ' + (r.data || 'Inconnue'), 'error');
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

            $results.html('<div style="padding:8px; color:#64748b; font-style:italic;">🔍 Recherche en cours...</div>').show();

            $.post(silSharedData.ajaxurl || ajaxurl, {
                action: 'sil_search_posts_for_link',
                s: val,
                nonce: silSharedData.nonce
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

            // BMAD Fix: Sync target info to the Manual Bridge button
            const $manualBtn = $container.find('#sil-sidebar-manual-btn');
            if ($manualBtn.length) {
                $manualBtn.attr('data-target', targetId);
                $manualBtn.data('target', targetId);
                $manualBtn.attr('data-title', title);
                $manualBtn.data('title', title);
                $manualBtn.addClass('sil-target-ready');
            }

            const $anchors = $container.find('.sil-anchor-suggestions');
            const $chipContainer = $anchors.find('.sil-chip-container');
            $chipContainer.empty();

            if (keywords && keywords.length > 0) {
                keywords.forEach(kw => {
                    // Fix unicodes that lost their backslash from PHP json_encode DB saving (e.g. u00e0 -> \u00e0)
                    kw = kw.replace(/u([0-9a-fA-F]{4})/g, (match, hex) => String.fromCharCode(parseInt(hex, 16)));
                    
                    let kwEscaped = kw.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    $chipContainer.append(`<span class="sil-anchor-chip" data-source-id="${sourceId}" data-target-id="${targetId}" data-anchor="${kwEscaped}" style="background:#e0f2fe;color:#0369a1;padding:3px 8px;border-radius:12px;cursor:pointer;border:1px solid #bae6fd;">${kw}</span>`);
                });
            } else {
                $chipContainer.append('<small style="color:#64748b;display:block;width:100%;margin-bottom:8px;">Aucun mot-clé GSC trouvé.</small>');
            }
            $anchors.show();
        });

        $(document).on('click', '.sil-anchor-chip', function () {
            const $chip = $(this);
            const anchor = $chip.data('anchor') || $chip.text();
            const targetId = $chip.data('target-id');
            const sourceId = $chip.data('source-id');

            $chip.css('opacity', '0.5').css('pointer-events', 'none');

            $.post(silSharedData.ajaxurl || ajaxurl, {
                action: 'sil_add_internal_link_from_map',
                source_id: sourceId,
                target_id: targetId,
                anchor_text: anchor,
                nonce: silSharedData.nonce
            }, function (response) {
                if (response.success) {
                    $chip.remove();
                    window.silToast("Lien local ajouté !", "success");
                } else {
                    const errorMsg = `<div class="sil-inline-error" style="color:#b91c1c;background:#fef2f2;border:1px solid #f87171;padding:10px;margin-top:10px;border-radius:4px;font-size:11px;">
                        ⚠️ <strong>Impossible :</strong><br>${response.data}
                        <div style="margin-top:8px;border-top:1px solid #fca5a5;padding-top:8px; display:flex; gap:5px;">
                            <button class="button button-primary sil-manual-bridge-trigger" data-source="${sourceId}" data-target="${targetId}" data-anchor="${anchor}" style="flex:1; font-size:10px;">🌉 Créer le prompt pour le pont</button>
                        </div>
                    </div>`;
                    $chip.closest('.sil-anchor-suggestions').append(errorMsg);
                    $chip.css('opacity', '1').css('pointer-events', 'auto');
                }
            }).fail(function() {
                window.silToast("Erreur réseau", "error");
                $chip.css('opacity', '1').css('pointer-events', 'auto');
            });
        });

        $(document).on('click', '.sil-seal-btn', function() {
            const $btn = $(this);
            const sourceId = $btn.data('source');
            const targetId = $btn.data('target');
            
            $btn.prop('disabled', true).html('<span class="spinner is-active"></span> Scellage...');

            $.post(silSharedData.ajaxurl || ajaxurl, {
                action: 'sil_seal_reciprocal_link',
                nonce: silSharedData.nonce,
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
                    window.silToast(res.data || 'Erreur lors du scellage', "error");
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
            $.post(silSharedData.ajaxurl || ajaxurl, {
                action: 'sil_search_posts_for_link',
                s: targetTitle,
                nonce: silSharedData.nonce
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
                        $chipContainer.append('<small style="color:#64748b;display:block;width:100%;margin-bottom:8px;">Aucun mot-clé GSC trouvé.</small>');
                    }
                    $anchors.show();
                    // Scroll vers le bas
                    $container.animate({ scrollTop: $container[0].scrollHeight }, 500);
                }
            });
        });
        $(document).on('click', '.sil-create-bridge-btn, .sil-local-bridge-btn', function () {
            const $btn = $(this);
            const sourceId = $btn.data('source');
            const targetId = $btn.data('target');
            const anchorText = $btn.data('anchor');
            
            // Use SIL_Bridge.generate (always available via sil-bridge-manager.js)
            if (typeof window.SIL_Bridge !== 'undefined') {
                window.SIL_Bridge.generate(sourceId, targetId, anchorText, $btn);
            } else {
                window.silToast('Le moteur du pont sémantique n\'est pas chargé.', 'error');
            }
        });

        $(document).on('sil_bridge_applied', function(e, sourceId, targetId) {
            window.silToast('Pont inséré avec succès !', 'success');
            // Note: In the future, we could trigger a local node refresh here if cy is available
        });
    // End of Document event handlers

    function handleGraphError(msg) {
        $('#sil-graph-loading').hide();
        let displayMsg = msg;
        if (typeof msg === 'object' && msg !== null) {
            displayMsg = (msg.message || 'Erreur inconnue') + 
                         (msg.file ? '<br><small>Fichier: ' + msg.file + ' (Ligne ' + msg.line + ')</small>' : '');
        }
        $container.html('<div class="sil-graph-error-box"><strong>⚠️ Échec :</strong><br>' + displayMsg + '<br><br><button class="button sil-retry-btn">Réessayer</button></div>');
        $('.sil-retry-btn').on('click', function(e) {
            e.preventDefault();
            location.reload();
        });
    }
});
})(jQuery);