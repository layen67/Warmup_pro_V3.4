(function($) {
    'use strict';

    const ChainsManager = {
        init: function() {
            this.container = $('#pw-chains-container');
            if (!this.container.length) return;

            this.template = $('#pw-chain-item-template').html();
            this.bindEvents();
            this.loadChains();
        },

        bindEvents: function() {
            $(document).on('click', '.pw-chain-create-btn', this.openCreateModal.bind(this));
            $(document).on('click', '.pw-chain-expand-btn', this.expandChain.bind(this));
        },

        loadChains: function() {
            // Fetch templates and process chains
            // Using existing data in pwAdmin if available or AJAX
            // For now, we reuse the loaded templates in DOM or reload
            // Logic:
            // 1. Find root templates (no suffix)
            // 2. Group by root
            // 3. Render

            // Note: This logic requires the templates list.
            // We can access window.pwTemplatesData if we expose it from templates-manager.js
            // Or fetch via AJAX pw_get_all_templates

            $.post(pwAdmin.ajaxurl, {
                action: 'pw_get_all_templates',
                nonce: pwAdmin.nonce
            }, (response) => {
                if (response.success) {
                    this.renderChains(response.data.templates);
                } else {
                    this.container.html('<div class="notice notice-error"><p>Erreur lors du chargement : ' + (response.data.message || 'Inconnue') + '</p></div>');
                }
            }).fail((xhr, status, error) => {
                console.error('Chains Load Error:', error);
                this.container.html('<div class="notice notice-error"><p>Erreur réseau lors du chargement des chaînes.</p></div>');
            });
        },

        renderChains: function(templates) {
            const suffix = pwAdmin.thread_suffix || '_reply';
            const chains = {};

            // 1. Group
            templates.forEach(tpl => {
                let name = tpl.name;
                let depth = 0;
                let root = name;

                if (name.includes(suffix)) {
                    const parts = name.split(suffix);
                    root = parts[0];
                    depth = parseInt(parts[1]) || 0;
                }

                if (!chains[root]) chains[root] = [];
                chains[root][depth] = tpl;
            });

            // 2. Render
            let html = '';
            for (const [root, items] of Object.entries(chains)) {
                // Check if it's a valid chain (has root or replies)
                if (Object.keys(items).length < 1) continue;
                if (root === 'null') continue;

                html += this.buildChainHTML(root, items);
            }

            this.container.html(html || '<div class="pw-empty-state"><p>Aucune chaîne détectée.</p></div>');
        },

        buildChainHTML: function(root, items) {
            const maxDepth = parseInt(pwAdmin.thread_max) || 3;
            let steps = '';

            for (let i = 0; i <= maxDepth; i++) {
                const tpl = items[i];
                const exists = !!tpl;
                const statusClass = exists ? 'active' : 'missing';
                const name = i === 0 ? root : `${root}_reply${i}`;

                steps += `
                    <div class="pw-chain-step ${statusClass}">
                        <div class="pw-chain-card">
                            <div class="pw-chain-icon">${i === 0 ? '📩' : '↩️'}</div>
                            <div class="pw-chain-info">
                                <span class="pw-chain-name">${name}</span>
                                ${exists ? `<span class="pw-status-dot ${tpl.status}"></span>` : ''}
                            </div>
                            <div class="pw-chain-actions">
                                ${exists ?
                                    `<button class="button button-small pw-edit-template-btn" data-id="${tpl.id}">Éditer</button>` :
                                    `<button class="button button-small button-primary pw-chain-create-btn" data-name="${name}">Créer</button>`
                                }
                            </div>
                        </div>
                        ${i < maxDepth ? '<div class="pw-chain-arrow">→</div>' : ''}
                    </div>
                `;
            }

            return `
                <div class="pw-chain-row">
                    <div class="pw-chain-header">
                        <h3>${root}</h3>
                        <span class="pw-chain-count">${Object.keys(items).length} templates</span>
                    </div>
                    <div class="pw-chain-flow">
                        ${steps}
                    </div>
                </div>
            `;
        },

        openCreateModal: function(e) {
            const name = $(e.currentTarget).data('name');
            // Trigger creation via AJAX
            if (!confirm(`Créer le template "${name}" ?`)) return;

            $.post(pwAdmin.ajaxurl, {
                action: 'pw_create_chain_template',
                name: name,
                nonce: pwAdmin.nonce
            }, (res) => {
                if (res.success) {
                    alert('Template créé !');
                    this.loadChains(); // Reload
                } else {
                    alert('Erreur : ' + res.data.message);
                }
            });
        }
    };

    $(document).ready(function() {
        // Init if tab is active or on click
        $('.pw-tab-link[data-tab="chains"]').on('click', function() {
            ChainsManager.init();
        });
    });

})(jQuery);
