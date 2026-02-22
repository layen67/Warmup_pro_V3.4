/**
 * admin/js/templates-manager-v3.1.js 
 * Gestionnaire principal Templates v3.1 
 */

(function($) { 
    'use strict'; 
     
    /**
     * Category Manager Logic
     */
    const CategoryManager = {
        treeData: null,

        open(data = null) {
            const $modal = $('#pw-category-modal');
            const $form = $('#pw-category-form');
            if (!$modal.length || !$form.length) return;

            $form[0].reset();
            
            // Populate parent select
            this.populateFolderSelect($('#pw-category-parent'), data ? data.id : null);

            if (data) {
                $('#pw-category-modal-title').text('Modifier la catégorie');
                $('#pw-category-id').val(data.id);
                $('#pw-category-name').val(data.name);
                $('#pw-category-parent').val(data.parent_id || '');
                $('#pw-category-color').val(data.color || '#2271b1');
            } else {
                $('#pw-category-modal-title').text('Nouvelle catégorie');
                $('#pw-category-id').val('');
                $('#pw-category-color').val('#2271b1');
            }

            $modal.css('display', 'flex');
        },

        populateFolderSelect($select, excludeId = null) {
            if (!$select.length) return;

            const render = (folders) => {
                const $first = $select.find('option:first');
                $select.empty().append($first);

                const addOptions = (items, level = 0) => {
                    if (!Array.isArray(items)) return;
                    items.forEach(f => {
                        if (excludeId && f.id == excludeId) return;
                        const indent = '—'.repeat ? '—'.repeat(level) : (new Array(level + 1).join('—'));
                        $select.append(`<option value="${f.id}">${indent} ${f.name}</option>`);
                        if (f.children && f.children.length > 0) {
                            addOptions(f.children, level + 1);
                        }
                    });
                };
                addOptions(folders);
            };

            if (this.treeData) {
                render(this.treeData);
            } else {
                this.refreshTree().then(() => {
                    if (this.treeData) render(this.treeData);
                });
            }
        },

        async refreshTree() {
            if (typeof pwAdmin === 'undefined') return;
            try {
                const response = await $.post(pwAdmin.ajaxurl, {
                    action: 'pw_get_categories',
                    nonce: pwAdmin.nonce
                });

                if (response && response.success && response.data && response.data.tree) {
                    this.treeData = response.data.tree;
                    this.renderTree(this.treeData);
                    
                    // Update all dropdowns to keep them in sync
                    this.populateFolderSelect($('#pw-category-parent'), $('#pw-category-id').val());
                    this.populateFolderSelect($('#pw-editor-folder'));
                    this.populateFolderSelect($('#pw-filter-folder'));
                }
            } catch (error) {
                console.error('Error refreshing tree:', error);
            }
        },

        renderTree(tree) {
            const $container = $('#pw-folders-tree');
            if (!$container.length) return;

            // Keep "All templates" item
            const $allTemplates = $container.find('.pw-folder-item[data-folder-id=""]').detach();
            $container.empty().append($allTemplates);

            const renderRecursive = (folders, $parent) => {
                if (!Array.isArray(folders)) return;
                folders.forEach(f => {
                    // Hide actions for protected folder "Non catégorisé"
                    const isProtected = f.name && f.name.toLowerCase().trim().includes('non catégorisé');
                    
                    const html = `
                        <li class="pw-folder-item ${f.children && f.children.length > 0 ? 'has-children' : ''}" 
                            data-folder-id="${f.id}"
                            data-name="${f.name}"
                            data-parent="${f.parent_id || ''}"
                            data-color="${f.color}"
                            droppable="true">
                            
                            <div class="pw-folder-row">
                                <span class="pw-folder-icon dashicons dashicons-category" style="color: ${f.color}"></span> 
                                <span class="pw-folder-name">${f.name}</span> 
                                <span class="pw-folder-count">${f.count}</span>
                                
                                ${!isProtected ? `
                                <div class="pw-folder-actions">
                                    <button class="pw-edit-folder-btn" title="Modifier">
                                        <span class="dashicons dashicons-edit"></span>
                                    </button>
                                    <button class="pw-delete-folder-btn" title="Supprimer">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>` : ''}
                            </div>
                            ${f.children && f.children.length > 0 ? '<ul class="pw-folder-children"></ul>' : ''}
                        </li>`;
                    
                    const $item = $(html);
                    $parent.append($item);
                    
                    if (f.children && f.children.length > 0) {
                        renderRecursive(f.children, $item.find('.pw-folder-children'));
                    }
                });
            };

            renderRecursive(tree, $container);
            
            // Re-apply active state if needed
            if (TemplatesManager.state.selectedFolder !== null) {
                $container.find(`.pw-folder-item[data-folder-id="${TemplatesManager.state.selectedFolder}"]`).addClass('active');
            } else {
                $allTemplates.addClass('active');
            }
        },

        async save() {
            if (typeof pwAdmin === 'undefined') return;
            const $form = $('#pw-category-form');
            const data = $form.serialize();
            const $btn = $('#pw-save-category-btn');
            
            $btn.prop('disabled', true).text('Enregistrement...');

            try {
                const response = await $.post(pwAdmin.ajaxurl, data + '&action=pw_save_category&nonce=' + pwAdmin.nonce);
                if (response.success) {
                    $('#pw-category-modal').hide();
                    this.refreshTree();
                } else {
                    alert(response.data.message || 'Erreur lors de la sauvegarde');
                    $btn.prop('disabled', false).text('Enregistrer');
                }
            } catch (error) {
                console.error('Error saving category:', error);
                $btn.prop('disabled', false).text('Enregistrer');
            }
        },

        async delete(id, name) {
            if (typeof pwAdmin === 'undefined') return;
            if (!confirm(`Êtes-vous sûr de vouloir supprimer la catégorie "${name}" ?\nLes templates seront déplacés vers "Non catégorisé".`)) return;

            try {
                const response = await $.post(pwAdmin.ajaxurl, {
                    action: 'pw_delete_category',
                    nonce: pwAdmin.nonce,
                    id: id
                });

                if (response.success) {
                    this.refreshTree();
                    // If deleted folder was selected, reset filter
                    if (TemplatesManager.state.selectedFolder == id) {
                        TemplatesManager.filterByFolder('');
                    }
                } else {
                    alert(response.data.message || 'Erreur lors de la suppression');
                }
            } catch (error) {
                console.error('Error deleting category:', error);
            }
        }
    };
     
    const TemplatesManager = { 
         
        state: { 
            templates: [], 
            filteredTemplates: [], 
            currentView: 'grid', 
            selectedFolder: null, 
            selectedTags: [], 
            favoriteOnly: false,
            searchQuery: '', 
            sortBy: 'name', 
            sortOrder: 'asc' 
        }, 
         
        init() { 
            this.bindEvents(); 
            this.loadTemplates(); 
            this.initSearch(); 
            // In a real app, drag & drop would need initialization here
            this.initDragDrop();
        }, 
         
        bindEvents() { 
            // New folder/category
            $(document).on('click', '#pw-add-folder-btn', (e) => {
                e.preventDefault();
                CategoryManager.open();
            });

            // Edit folder
            $(document).on('click', '.pw-edit-folder-btn', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const $item = $(e.currentTarget).closest('.pw-folder-item');
                CategoryManager.open({
                    id: $item.data('folder-id'),
                    name: $item.data('name'),
                    parent_id: $item.data('parent'),
                    color: $item.data('color')
                });
            });

            // Delete folder
            $(document).on('click', '.pw-delete-folder-btn', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const $item = $(e.currentTarget).closest('.pw-folder-item');
                CategoryManager.delete($item.data('folder-id'), $item.data('name'));
            });

            // New template 
            $('#pw-new-template-btn, #pw-new-template-empty-btn').on('click', (e) => { 
                e.preventDefault();
                this.openEditor(); 
            }); 
             
            // Edit 
            $(document).on('click', '.pw-edit-template-btn', (e) => { 
                e.preventDefault();
                const name = $(e.currentTarget).data('template-name') || $(e.currentTarget).closest('.pw-template-card').data('template-name'); 
                this.openEditor(name); 
            }); 
             
            // Preview 
            $(document).on('click', '.pw-preview-template-btn', (e) => { 
                e.preventDefault();
                const $card = $(e.currentTarget).closest('.pw-template-card'); 
                const name = $card.data('template-name'); 
                this.openPreview(name); 
            }); 
             
            // Favorite 
            $(document).on('click', '.pw-card-favorite-btn', (e) => { 
                e.preventDefault();
                e.stopPropagation(); 
                const $card = $(e.currentTarget).closest('.pw-template-card'); 
                this.toggleFavorite($card); 
            }); 
             
            // Delete 
            $(document).on('click', '.pw-delete-btn', (e) => { 
                e.preventDefault(); 
                const $card = $(e.currentTarget).closest('.pw-template-card'); 
                this.deleteTemplate($card); 
            }); 

            // Duplicate
            $(document).on('click', '.pw-duplicate-btn', (e) => {
                e.preventDefault();
                const $card = $(e.currentTarget).closest('.pw-template-card');
                this.duplicateTemplate($card);
            });

            // Move
            $(document).on('click', '.pw-move-btn', (e) => {
                e.preventDefault();
                const $card = $(e.currentTarget).closest('.pw-template-card');
                this.openMoveModal($card);
            });

            // Archive
            $(document).on('click', '.pw-archive-btn', (e) => {
                e.preventDefault();
                const $card = $(e.currentTarget).closest('.pw-template-card');
                this.archiveTemplate($card);
            });

            // Versions
            $(document).on('click', '.pw-versions-btn', (e) => {
                e.preventDefault();
                const $card = $(e.currentTarget).closest('.pw-template-card');
                this.openVersionsModal($card);
            });
             
            // Folders 
            $(document).on('click', '.pw-folder-item', (e) => { 
                const folderId = $(e.currentTarget).data('folder-id'); 
                this.filterByFolder(folderId); 
            }); 

            // System filter - Direct access to configuration
            $(document).on('click', '.pw-system-item', (e) => {
                e.preventDefault();
                this.openEditor('null');
            });

            // Favorite filter
            $(document).on('click', '.pw-quick-link[data-filter="favorite"]', (e) => {
                e.preventDefault();
                this.filterFavorite();
            });
             
            // Tags 
            $(document).on('click', '.pw-tag-pill', (e) => { 
                const tag = $(e.currentTarget).data('tag'); 
                this.toggleTagFilter(tag, $(e.currentTarget)); 
            }); 
             
            // Copy shortcode 
            $(document).on('click', '.pw-copy-shortcode-btn', (e) => { 
                const $card = $(e.currentTarget).closest('.pw-template-card');
                const name = $card.data('template-name');
                const format = $card.find('.pw-shortcode-select').val();
                
                // Use 'template' attribute as it is the standard and confirmed working
                // Remove 'label' to allow dynamic retrieval from template settings
                let shortcode = `[warmup_mailto template="${name}"]`;
                if (format === 'button') {
                    shortcode = `[warmup_mailto template="${name}" style="button"]`;
                }
                
                this.copyToClipboard(shortcode, $(e.currentTarget)); 
            }); 
             
            // Filters 
            $('#pw-filter-status, #pw-filter-folder').on('change', () => { 
                this.applyFilters(); 
            }); 
             
            // Toggle view 
            $('#pw-toggle-view').on('click', () => { 
                this.toggleView(); 
            }); 

            // Modal closes
            $(document).on('click', '.pw-modal-close, .pw-modal-cancel', (e) => {
                $(e.currentTarget).closest('.pw-modal').hide();
            });

            // Tabs
            $(document).on('click', '.pw-tab-btn', (e) => {
                const tab = $(e.currentTarget).data('tab');
                const $modal = $(e.currentTarget).closest('.pw-modal');
                $modal.find('.pw-tab-btn').removeClass('active');
                $(e.currentTarget).addClass('active');
                $modal.find('.pw-tab-content').removeClass('active');
                $modal.find('#pw-tab-' + tab).addClass('active');
            });
        }, 
         
        async loadTemplates() { 
            // Only load templates for grid rendering if we are on the templates page
            if (!$('#pw-templates-container').length) return;

            try { 
                const response = await $.post(pwAdmin.ajaxurl, { 
                    action: 'pw_get_all_templates', 
                    nonce: pwAdmin.nonce 
                }); 
                 
                if (response.success) { 
                    this.state.templates = response.data.templates; 
                    this.state.filteredTemplates = [...this.state.templates]; 
                    this.renderTemplates(); 
                } else {
                    this.showFatalError(response.data.message || 'Erreur inconnue lors du chargement');
                }
            } catch (error) { 
                console.error('Erreur chargement templates:', error); 
                this.showFatalError('Erreur de connexion au serveur (AJAX error).');
            } 
        }, 
         
        openEditor(templateName = null) { 
            if (window.PWTemplateEditor) {
                window.PWTemplateEditor.open(templateName);
            }
        }, 

        openPreview(templateName) {
            if (window.PWTemplatePreview) {
                window.PWTemplatePreview.open(templateName);
            }
        },
         
        async toggleFavorite($card) { 
            const templateId = $card.data('template-id'); 
            const $btn = $card.find('.pw-card-favorite-btn'); 
            const isFavorite = $btn.hasClass('active'); 
             
            try { 
                const response = await $.post(pwAdmin.ajaxurl, { 
                    action: 'pw_toggle_favorite', 
                    nonce: pwAdmin.nonce, 
                    template_id: templateId, 
                    favorite: !isFavorite 
                }); 
                 
                if (response.success) { 
                    $btn.toggleClass('active'); 
                    $btn.find('.dashicons') 
                        .toggleClass('dashicons-star-filled') 
                        .toggleClass('dashicons-star-empty'); 
                    
                    // Update state
                    const tpl = this.state.templates.find(t => t.id == templateId);
                    if (tpl) tpl.is_favorite = !isFavorite;

                    // Update UI count
                    const favCount = this.state.templates.filter(t => t.is_favorite).length;
                    $('#pw-favorites-count').text(favCount);
                } 
            } catch (error) { 
                console.error('Erreur toggle favori:', error); 
            } 
        }, 

        async deleteTemplate($card) {
            const name = $card.data('template-name');
            if (!confirm(`⚠️ ATTENTION : La suppression du template "${name}" est DÉFINITIVE.\n\nIl sera effacé de la base de données ainsi que son historique.\nConfirmez-vous ?`)) return;

            try {
                const response = await $.post(pwAdmin.ajaxurl, {
                    action: 'pw_delete_template',
                    nonce: pwAdmin.nonce,
                    name: name
                });

                if (response.success) {
                    $card.fadeOut(() => {
                        this.state.templates = this.state.templates.filter(t => t.name !== name);
                        this.applyFilters();
                    });
                } else {
                    alert(response.data.message || 'Erreur lors de la suppression');
                }
            } catch (error) {
                console.error('Erreur suppression:', error);
            }
        },

        async duplicateTemplate($card) {
            const name = $card.data('template-name');
            if (window.PWTemplateDuplicate) {
                window.PWTemplateDuplicate.open(name);
            }
        },

        openMoveModal($card) {
            const $modal = $('#pw-template-move-modal');
            const templateId = $card.data('template-id');
            const currentFolder = $card.data('folder');
            
            $modal.find('#pw-move-folder-select').val(currentFolder);
            $('#pw-confirm-move-btn').off('click').on('click', () => {
                this.moveTemplate(templateId, $modal.find('#pw-move-folder-select').val());
            });
            
            $modal.show();
        },

        async moveTemplate(id, folderId) {
            try {
                const response = await $.post(pwAdmin.ajaxurl, {
                    action: 'pw_move_template',
                    nonce: pwAdmin.nonce,
                    template_id: id,
                    folder_id: folderId
                });
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Erreur lors du déplacement');
                }
            } catch (error) {
                console.error('Error moving:', error);
            }
        },

        async archiveTemplate($card) {
            const id = $card.data('template-id');
            const currentStatus = $card.data('status');
            const newStatus = (currentStatus === 'archived') ? 'active' : 'archived';
            
            try {
                const response = await $.post(pwAdmin.ajaxurl, {
                    action: 'pw_update_template_status',
                    nonce: pwAdmin.nonce,
                    template_id: id,
                    status: newStatus
                });
                if (response.success) {
                    location.reload();
                }
            } catch (error) {
                console.error('Error archiving:', error);
            }
        },

        async openVersionsModal($card) {
            const id = $card.data('template-id');
            const $modal = $('#pw-template-versions-modal');
            const $list = $('#pw-versions-list');
            
            $list.html('<div class="pw-loading">Chargement...</div>');
            $modal.show();

            try {
                const response = await $.post(pwAdmin.ajaxurl, {
                    action: 'pw_get_template_versions',
                    nonce: pwAdmin.nonce,
                    template_id: id
                });

                if (response.success) {
                    const versions = response.data.versions;
                    if (versions.length === 0) {
                        $list.html('<p>Aucun historique disponible.</p>');
                    } else {
                        $list.empty();
                        const template = $('#pw-version-item-template').html();
                        versions.forEach(v => {
                            let itemHtml = template
                                .replace(/<%- id %>/g, v.id)
                                .replace(/<%- version_number %>/g, v.version_number)
                                .replace(/<%- created_at %>/g, v.created_at)
                                .replace(/<%- author_name %>/g, v.author_name || 'Inconnu')
                                .replace(/<%- comment %>/g, v.comment || '')
                                .replace(/<%- diff_summary %>/g, v.diff_summary || '');
                            
                            // Simple cleanup for template tags
                            if (!v.comment) itemHtml = itemHtml.replace(/<% if \(comment\) \{ %>[\s\S]*?<% \} %>/, '');
                            else itemHtml = itemHtml.replace(/<% if \(comment\) \{ %>([\s\S]*?)<% \} %>/, '$1');

                            if (!v.diff_summary) itemHtml = itemHtml.replace(/<% if \(diff_summary\) \{ %>[\s\S]*?<% \} %>/, '');
                            else itemHtml = itemHtml.replace(/<% if \(diff_summary\) \{ %>([\s\S]*?)<% \} %>/, '$1');

                            $list.append(itemHtml);
                        });

                        // Bind actions
                        $('.pw-restore-version-btn').on('click', (e) => {
                            this.restoreVersion($(e.currentTarget).data('id'));
                        });
                    }
                }
            } catch (error) {
                console.error('Error loading versions:', error);
            }
        },

        async restoreVersion(versionId) {
            if (!confirm('Êtes-vous sûr de vouloir restaurer cette version ?')) return;

            try {
                const response = await $.post(pwAdmin.ajaxurl, {
                    action: 'pw_restore_template_version',
                    nonce: pwAdmin.nonce,
                    version_id: versionId
                });

                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Erreur lors de la restauration');
                }
            } catch (error) {
                console.error('Error restoring:', error);
            }
        },
         
        filterByFolder(folderId) { 
            this.state.selectedFolder = folderId; 
            this.state.favoriteOnly = false;
             
            $('.pw-folder-item').removeClass('active'); 
            $('.pw-system-item, .pw-quick-link').removeClass('active');
            $(`.pw-folder-item[data-folder-id="${folderId}"]`).addClass('active'); 
             
            this.applyFilters(); 
        }, 

        filterFavorite() {
            this.state.selectedFolder = null;
            this.state.favoriteOnly = true;

            $('.pw-folder-item, .pw-system-item').removeClass('active');
            $('.pw-quick-link[data-filter="favorite"]').addClass('active');

            this.applyFilters();
        },

        toggleTagFilter(tag, $el) {
            const index = this.state.selectedTags.indexOf(tag);
            if (index > -1) {
                this.state.selectedTags.splice(index, 1);
                $el.removeClass('active');
            } else {
                this.state.selectedTags.push(tag);
                $el.addClass('active');
            }
            this.applyFilters();
        },
         
        applyFilters() { 
            let filtered = [...this.state.templates]; 
            
            // ALWAYS exclude 'null' from the grid in all views
            filtered = filtered.filter(t => t.name !== 'null');

            if (this.state.favoriteOnly) {
                filtered = filtered.filter(t => t.is_favorite);
            }

            if (this.state.selectedFolder !== null && this.state.selectedFolder !== '') { 
                filtered = filtered.filter(t => t.folder_id == this.state.selectedFolder); 
            }
             
            const status = $('#pw-filter-status').val(); 
            if (status) { 
                filtered = filtered.filter(t => t.status === status); 
            } 
             
            if (this.state.selectedTags.length > 0) { 
                filtered = filtered.filter(t => { 
                    return this.state.selectedTags.every(tag =>  
                        t.tags && t.tags.some(ttag => ttag.name === tag) 
                    ); 
                }); 
            } 
             
            if (this.state.searchQuery) { 
                const query = this.state.searchQuery.toLowerCase(); 
                filtered = filtered.filter(t => { 
                    return t.name.toLowerCase().includes(query) || 
                           (t.tags && t.tags.some(tag => tag.name.toLowerCase().includes(query))); 
                }); 
            } 
             
            this.state.filteredTemplates = filtered; 
            this.renderTemplates(); 
        }, 
         
        initSearch() { 
            let timeout; 
             
            $('#pw-search-input').on('input', (e) => { 
                clearTimeout(timeout); 
                const query = $(e.target).val(); 
                 
                timeout = setTimeout(() => { 
                    this.state.searchQuery = query; 
                    this.applyFilters(); 
                    $('.pw-search-clear').toggle(query.length > 0); 
                }, 300); 
            }); 
             
            $('.pw-search-clear').on('click', () => { 
                $('#pw-search-input').val('').trigger('input'); 
            }); 
        }, 
         
        initDragDrop() { 
            const self = this;

            // Drag Start
            $(document).on('dragstart', '.pw-template-card', function(e) {
                const card = $(this);
                const id = card.data('template-id');
                // Use standard dataTransfer
                e.originalEvent.dataTransfer.setData('text/plain', id);
                e.originalEvent.dataTransfer.effectAllowed = 'move';
                card.addClass('pw-dragging');
            });

            $(document).on('dragend', '.pw-template-card', function(e) {
                $(this).removeClass('pw-dragging');
                $('.pw-folder-item').removeClass('pw-drag-over');
            });

            // Drag Over (Folders)
            $(document).on('dragover', '.pw-folder-item', function(e) {
                e.preventDefault();
                e.originalEvent.dataTransfer.dropEffect = 'move';
                $(this).addClass('pw-drag-over');
            });

            $(document).on('dragleave', '.pw-folder-item', function(e) {
                $(this).removeClass('pw-drag-over');
            });

            // Drop
            $(document).on('drop', '.pw-folder-item', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('pw-drag-over');
                
                const templateId = e.originalEvent.dataTransfer.getData('text/plain');
                const folderId = $(this).data('folder-id');

                if (templateId && folderId !== undefined) {
                    // Visual feedback immediately
                    const $card = $(`.pw-template-card[data-template-id="${templateId}"]`);
                    $card.fadeOut(200, function() {
                        self.moveTemplate(templateId, folderId);
                    });
                }
            });
        }, 
         
        renderTemplates() { 
            const $container = $('#pw-templates-container'); 
            const templates = this.state.filteredTemplates; 
             
            if (templates.length === 0) { 
                $container.hide(); 
                $('.pw-empty-state').show(); 
                return; 
            } 
             
            $container.show(); 
            $('.pw-empty-state').hide(); 

            // In a real app, we would re-render cards or just toggle visibility
            $('.pw-template-card').hide();
            templates.forEach(t => {
                if (t.id) {
                    $(`.pw-template-card[data-template-id="${t.id}"]`).show();
                } else {
                    $(`.pw-template-card[data-template-name="${t.name}"]`).show();
                }
            });
        }, 
         
        copyToClipboard(text, $btn) { 
            if (navigator.clipboard && window.isSecureContext) { 
                navigator.clipboard.writeText(text).then(() => { 
                    this.showCopyFeedback($btn); 
                }); 
            } else { 
                const $temp = $('<textarea>').val(text).appendTo('body').select(); 
                document.execCommand('copy'); 
                $temp.remove(); 
                this.showCopyFeedback($btn); 
            } 
        }, 
         
        showCopyFeedback($btn) { 
            const originalHtml = $btn.html(); 
            $btn.addClass('copied').html('<span class="dashicons dashicons-yes"></span>'); 
            setTimeout(() => { 
                $btn.removeClass('copied').html(originalHtml); 
            }, 2000); 
        }, 
         
        toggleView() { 
            this.state.currentView = this.state.currentView === 'grid' ? 'list' : 'grid'; 
            $('#pw-templates-container') 
                .toggleClass('pw-templates-grid') 
                .toggleClass('pw-templates-list'); 
            $('#pw-toggle-view .dashicons') 
                .toggleClass('dashicons-grid-view') 
                .toggleClass('dashicons-list-view'); 
        },

        showFatalError(message) {
            $('#pw-templates-container').html(`
                <div class="notice notice-error" style="margin: 20px 0; padding: 20px; background: #fff5f5; border-left: 4px solid #dc3232;">
                    <h3 style="color: #dc3232;">🚨 Erreur de Base de Données</h3>
                    <p>${message}</p>
                    <p>Si vous voyez des erreurs de type "Unknown column", utilisez le bouton <strong>🔧 Initialiser les tables</strong> en haut de la page.</p>
                </div>
            `);
        }
    }; 

    /**
     * Template Editor Logic
     */
    const TemplateEditor = {
        open(templateName = null) {
            const $modal = $('#pw-template-editor-modal');
            this.resetForm();
            
            if (templateName) {
                $('#pw-editor-title').text('Modifier le Template : ' + templateName);
                this.loadTemplate(templateName);
            } else {
                $('#pw-editor-title').text('Nouveau Template');
                // Add one default variant for each
                this.addVariant('subject');
                this.addVariant('from_name');
                this.addVariant('text');
                this.addVariant('html');
            }
            
            $modal.show();

            // Bind Toolbar Events (once)
            this.bindToolbarEvents();
        },

        bindToolbarEvents() {
            if (this._toolbarBound) return;
            this._toolbarBound = true;
            
            this.bindBase64Events();

            // Toolbar: Expand/Focus Mode
            $(document).on('click', '.pw-expand-btn', function(e) {
                e.preventDefault();
                const $editor = $(this).closest('.pw-variant-item').find('.pw-variant-editor');
                const $textarea = $editor.find('.pw-variant-input');
                
                // Create fullscreen modal
                const $overlay = $('<div class="pw-focus-overlay"><div class="pw-focus-container"><div class="pw-focus-header"><h3>Mode Édition (Focus)</h3><button class="pw-focus-close">&times;</button></div><textarea class="pw-focus-textarea"></textarea></div></div>');
                
                $('body').append($overlay);
                $overlay.find('textarea').val($textarea.val()).focus();
                
                // Sync back on close
                const closeFocus = () => {
                    $textarea.val($overlay.find('textarea').val()).trigger('input');
                    $overlay.remove();
                };
                
                $overlay.find('.pw-focus-close').on('click', closeFocus);
                $overlay.on('click', function(e) {
                    if ($(e.target).hasClass('pw-focus-overlay')) closeFocus();
                });
            });

            // Toolbar: Insert Variable (Modified to NOT insert on change if using Copy button workflow)
            // But user asked for copy capability. Does he want ONLY copy or BOTH? 
            // "je puisse copier les variable dans le presse papier" implies copy. 
            // The previous logic was "insert on change". This is conflicting if "change" triggers insert immediately.
            // Let's change behavior: "change" -> Selects value but doesn't insert automatically if we want to copy?
            // Or better: keep insert on change for convenience, but the copy button grabs the value BEFORE reset?
            // Ah, the reset `$(this).val('')` clears it.
            // Correction: Remove auto-insert on change. User selects, then clicks Insert or Copy.
            // OR: Keep auto-insert but remove the reset? 
            // Most standard editors: Select from list -> Inserts immediately.
            // To support Copy: We need a way to select without inserting.
            
            // New Logic: 
            // 1. Change event: Does NOTHING but update internal state or just stay selected?
            // If I remove auto-insert, existing users might be confused.
            // Let's change the UI slightly:
            // [ Select Variable ] [ Insert ] [ Copy ] ? Too cluttered.
            // Current: [ Select (Change triggers insert) ] [ Copy ]
            // If I click Copy, I haven't changed the select yet.
            // So: User selects a variable. It stays selected. User can click "Copy". User can click "Insert" (we need an insert button?)
            
            // Let's add an explicit "Insert" button to avoid accidental insertions and allow copying.
            
            // Toolbar: Insert Variable Button
            $(document).on('click', '.pw-insert-var-btn', function(e) {
                e.preventDefault();
                const $select = $(this).closest('.pw-toolbar-group').find('.pw-var-select');
                const val = $select.val();
                 if (val) {
                    const $container = $(this).closest('.pw-variant-item');
                    const $textarea = $container.find('textarea.pw-variant-input');
                    if ($textarea.length) {
                        TemplateEditor.insertAtCursor($textarea[0], val);
                    }
                } else {
                    alert('Veuillez d\'abord sélectionner une variable dans la liste.');
                    $select.focus();
                }
            });

            // Toolbar: Copy Variable
            $(document).on('click', '.pw-copy-var-btn', function(e) {
                e.preventDefault();
                const $select = $(this).closest('.pw-toolbar-group').find('.pw-var-select');
                const val = $select.val();
                
                if (val) {
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(val);
                    } else {
                        const $temp = $('<textarea>').val(val).appendTo('body').select();
                        document.execCommand('copy');
                        $temp.remove();
                    }
                    
                    const $btn = $(this);
                    const originalHtml = $btn.html();
                    $btn.html('<span class="dashicons dashicons-yes" style="font-size:14px; width:14px; height:14px; margin-top:3px; color:green;"></span>');
                    setTimeout(() => $btn.html(originalHtml), 1500);
                } else {
                    alert('Sélectionnez une variable d\'abord.');
                }
            });

            // Toolbar: Insert Spintax
            $(document).on('click', '.pw-spintax-btn', function(e) {
                e.preventDefault();
                const $container = $(this).closest('.pw-variant-item');
                const $textarea = $container.find('textarea.pw-variant-input');
                
                if ($textarea.length) {
                    TemplateEditor.insertAtCursor($textarea[0], '{ | }');
                }
            });

            // Toolbar: Code / Preview Toggle
            $(document).on('click', '.pw-toggle-btn', function(e) {
                e.preventDefault();
                const $btn = $(this);
                if ($btn.hasClass('active')) return;

                const mode = $btn.data('mode');
                const $group = $btn.closest('.pw-view-toggle');
                const $container = $btn.closest('.pw-variant-item');
                const $editor = $container.find('.pw-variant-editor');
                const $textarea = $editor.find('.pw-variant-input');
                const $preview = $editor.find('.pw-variant-preview');

                // Toggle buttons
                $group.find('.pw-toggle-btn').removeClass('active');
                $btn.addClass('active');

                if (mode === 'preview') {
                    let content = $textarea.val();
                    
                    // Simple HTML rendering. 
                    // Note: This does not process spintax or variables fully server-side, 
                    // it serves as a quick visual check for HTML structure.
                    
                    // If content is empty
                    if (!content.trim()) {
                        content = '<em style="color:#999">Contenu vide...</em>';
                    } else {
                        // Basic Spintax Highlighting (Optional visual aid)
                        // content = content.replace(/\{([^{}]*)\}/g, '<span style="background:#fff3cd; padding:0 2px; border-radius:2px;" title="Spintax">{$1}</span>');
                        
                        // Render HTML
                        // We rely on the browser's rendering. 
                        // IMPORTANT: For text content (not HTML), we might want to nl2br?
                        // But the editor is generic.
                    }

                    $preview.html(content);
                    $textarea.hide();
                    $preview.show();
                } else {
                    $textarea.show();
                    $preview.hide();
                }
            });
        },

        insertAtCursor(field, value) {
            if (!field) return;
            
            // Modern browsers support setRangeText
            if (typeof field.setRangeText === 'function') {
                field.setRangeText(value);
                // Move cursor to end of inserted text
                field.selectionStart = field.selectionEnd = field.selectionEnd + value.length;
                
                // If inserting spintax, place cursor inside braces
                if (value === '{ | }') {
                    field.selectionStart = field.selectionEnd - 4; // Inside { | } -> { | }
                }
            } else {
                // Fallback
                if (document.selection) {
                    field.focus();
                    var sel = document.selection.createRange();
                    sel.text = value;
                } else if (field.selectionStart || field.selectionStart == '0') {
                    var startPos = field.selectionStart;
                    var endPos = field.selectionEnd;
                    field.value = field.value.substring(0, startPos) + value + field.value.substring(endPos, field.value.length);
                } else {
                    field.value += value;
                }
            }
            
            $(field).trigger('input').focus();
        },

        // Toolbar: Base64 Encode
        bindBase64Events() {
             $(document).on('click', '.pw-base64-btn', function() {
                const $textarea = $(this).closest('.pw-variant-item').find('.pw-variant-input');
                const val = $textarea.val();
                if (val) {
                    try {
                        const encoded = btoa(unescape(encodeURIComponent(val)));
                        $textarea.val(encoded).trigger('input');
                    } catch (e) {
                        alert('Erreur d\'encodage Base64.');
                    }
                }
            });

            $(document).on('click', '.pw-base64-decode-btn', function() {
                const $textarea = $(this).closest('.pw-variant-item').find('.pw-variant-input');
                const val = $textarea.val();
                if (val) {
                    try {
                        const decoded = decodeURIComponent(escape(window.atob(val)));
                        $textarea.val(decoded).trigger('input');
                    } catch (e) {
                        alert('Le contenu ne semble pas être en Base64 valide.');
                    }
                }
            });
        },

        resetForm() {
            $('#pw-template-editor-form')[0].reset();
            $('#pw-editor-template-id').val('');
            $('#pw-editor-name').prop('readonly', false);
            $('#pw-system-template-info').hide();
            $('.pw-variants-container').empty();
            $('.pw-tab-btn[data-tab="general"]').click();
        },

        async loadTemplate(name) {
            try {
                const response = await $.post(pwAdmin.ajaxurl, {
                    action: 'pw_get_template',
                    nonce: pwAdmin.nonce,
                    name: name
                });

                if (response.success) {
                    const tpl = response.data;
                    const tplName = tpl.name || name;
                    
                    $('#pw-editor-template-id').val(tpl.id);
                    $('#pw-editor-name').val(tplName);
                    
                    if (tplName === 'null') {
                        $('#pw-editor-name').prop('readonly', true).addClass('readonly-field');
                        $('#pw-system-template-info').show();
                    } else {
                        $('#pw-editor-name').prop('readonly', false); // Non-null templates CAN be renamed
                        $('#pw-editor-name').removeClass('readonly-field');
                        $('#pw-system-template-info').hide();
                    }

                    $('#pw-editor-folder').val(tpl.folder_id || '');
                    $('#pw-editor-status').val(tpl.status || 'active');
                    
                    if (tpl.tags && Array.isArray(tpl.tags)) {
                        const tagNames = tpl.tags.map(t => typeof t === 'object' ? t.name : t).join(', ');
                        $('#pw-editor-tags').val(tagNames);
                    }

                    // Load Default Label and Timezone (Fix)
                    $('#pw-editor-default-label').val(tpl.default_label || '');
                    $('#pw-editor-timezone').val(tpl.timezone || '');

                    // Load variants
                    const variantTypes = ['subject', 'text', 'html', 'from_name', 'reply_to', 'mailto_subject', 'mailto_body', 'mailto_from_name'];
                    variantTypes.forEach(type => {
                        if (tpl[type] && Array.isArray(tpl[type])) {
                            tpl[type].forEach(val => this.addVariant(type, val));
                        }
                    });
                } else {
                    alert('Erreur: ' + (response.data ? response.data.message : 'inconnue'));
                }
            } catch (error) {
                console.error('Error loading template:', error);
            }
        },

        openBulkModal(type) {
            this.currentBulkType = type;
            $('#pw-bulk-add-textarea').val('');
            $('#pw-bulk-add-modal').show();
            // Show/Hide specific info based on type
            if (['html', 'text'].includes(type)) {
                $('.pw-bulk-info-text').show();
            } else {
                $('.pw-bulk-info-text').hide();
            }
            $('#pw-bulk-add-textarea').focus();
        },

        confirmBulkAdd() {
            const content = $('#pw-bulk-add-textarea').val();
            if (!content.trim()) return;

            const type = this.currentBulkType;
            let variants = [];

            // Special handling for HTML/Text which might be multi-line
            if (['html', 'text'].includes(type) && content.includes('---')) {
                variants = content.split(/\n---\n|---\n|\n---/g).map(v => v.trim()).filter(v => v);
            } else {
                // Default: line by line
                variants = content.split('\n').map(v => v.trim()).filter(v => v);
            }

            variants.forEach(val => this.addVariant(type, val));
            $('#pw-bulk-add-modal').hide();
        },

        addVariant(type, value = '') {
            const $container = $('#pw-variants-' + type);
            if (!$container.length) return;

            const template = $('#pw-variant-item-template').html();
            // Use placeholder and then set value safely
            const html = template
                .replace(/<%- type %>/g, type)
                .replace(/<%- value %>/g, ''); 
            
            const $item = $(html);
            $item.find('textarea').val(value);
            
            $container.append($item);
            
            $item.find('.pw-remove-variant').on('click', function() {
                const required = ['subject', 'text', 'html', 'from_name'];
                if ($container.find('.pw-variant-item').length > 1 || !required.includes(type)) {
                    $item.remove();
                } else {
                    alert('Au moins une variante est requise pour ce champ.');
                }
            });
        },

        async save() {
            const name = $('#pw-editor-name').val();
            const id = $('#pw-editor-template-id').val();
            const isNew = !id;

            // Prevent renaming or creating a template named "null"
            if (name.toLowerCase() === 'null' && !$('#pw-system-template-info').is(':visible')) {
                alert('Le nom "null" est réservé au template système.');
                return;
            }

            const formData = $('#pw-template-editor-form').serialize();
            const $btn = $('#pw-save-template-btn');
            $btn.prop('disabled', true).text('Sauvegarde...');

            try {
                const response = await $.post(pwAdmin.ajaxurl, formData + '&action=pw_save_template&nonce=' + pwAdmin.nonce);
                if (response.success) {
                    $('#pw-template-editor-modal').hide();
                    location.reload(); // Simplest way to refresh everything
                } else {
                    alert(response.data.message || 'Erreur lors de la sauvegarde');
                    $btn.prop('disabled', false).text('💾 Sauvegarder');
                }
            } catch (error) {
                console.error('Error saving template:', error);
                $btn.prop('disabled', false).text('💾 Sauvegarder');
            }
        }
    };

    /**
     * Template Preview Logic
     */
    const TemplatePreview = {
        currentTemplate: null,
        currentTemplateName: null,

        open(templateName) {
            const $modal = $('#pw-template-preview-modal');
            $('#pw-preview-title').text('Aperçu : ' + templateName);
            
            this.currentTemplateName = templateName;
            this.loadPreview();
            
            $modal.show();

            // Bind context change
            $('#pw-preview-context').off('change').on('change', () => {
                this.renderCurrent();
            });
        },

        async loadPreview() {
            try {
                const response = await $.post(pwAdmin.ajaxurl, {
                    action: 'pw_get_template',
                    nonce: pwAdmin.nonce,
                    name: this.currentTemplateName
                });
                if (response.success) {
                    this.currentTemplate = response.data;
                    this.renderCurrent();
                }
            } catch (error) {
                console.error('Preview error:', error);
            }
        },

        async renderCurrent() {
            if (!this.currentTemplate) return;
            
            const contextType = $('#pw-preview-context').val();
            
            // Pick first variants
            const subjectRaw = this.currentTemplate.subject[0] || '';
            const htmlRaw = this.currentTemplate.html[0] || this.currentTemplate.text[0] || '';
            const fromRaw = this.currentTemplate.from_name[0] || '';

            $('#pw-preview-subject').html('<i>Chargement...</i>');
            $('#pw-preview-from').html('<i>Chargement...</i>');

            // Parallel render
            Promise.all([
                this.apiRender(subjectRaw, contextType),
                this.apiRender(fromRaw, contextType),
                this.apiRender(htmlRaw, contextType)
            ]).then(([subject, from, html]) => {
                $('#pw-preview-subject').text(subject);
                $('#pw-preview-from').text(from);
                $('#pw-preview-frame').contents().find('body').html(html);
            });
        },

        async apiRender(content, contextType) {
            try {
                const response = await $.post(pwAdmin.ajaxurl, {
                    action: 'pw_render_preview',
                    nonce: pwAdmin.nonce,
                    content: content,
                    context_type: contextType
                });
                return response.success ? response.data.rendered : content;
            } catch (e) {
                return content;
            }
        }
    };
     
    /**
     * Template Duplicate Logic
     */
    const TemplateDuplicate = {
        open(sourceName) {
            const $modal = $('#pw-template-duplicate-modal');
            $modal.find('#pw-duplicate-source-name').val(sourceName);
            $modal.find('#pw-duplicate-new-name').val(sourceName).select().focus();
            $modal.show();
        },

        async confirm() {
            const $modal = $('#pw-template-duplicate-modal');
            const sourceName = $modal.find('#pw-duplicate-source-name').val();
            const newName = $modal.find('#pw-duplicate-new-name').val().trim();
            const $btn = $('#pw-confirm-duplicate-btn');

            if (!newName) {
                alert('Veuillez entrer un nom pour le nouveau template.');
                $modal.find('#pw-duplicate-new-name').focus();
                return;
            }

            if (newName === sourceName) {
                alert('Le nouveau nom doit être différent de l\'original.');
                $modal.find('#pw-duplicate-new-name').focus();
                return;
            }

            $btn.prop('disabled', true).text('Duplication...');

            try {
                // Ensure parameters are sent as an object and new_name is not empty
                const ajaxData = {
                    action: 'pw_duplicate_template',
                    nonce: pwAdmin.nonce,
                    name: sourceName,
                    new_name: newName
                };

                const response = await $.ajax({
                    url: pwAdmin.ajaxurl,
                    method: 'POST',
                    data: ajaxData,
                    dataType: 'json'
                });

                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data ? response.data.message : 'Erreur lors de la duplication');
                    $btn.prop('disabled', false).text('🚀 Dupliquer');
                }
            } catch (error) {
                console.error('Error duplicating template:', error);
                alert('Une erreur réseau est survenue lors de la duplication.');
                $btn.prop('disabled', false).text('🚀 Dupliquer');
            }
        }
    };
     
    $(document).ready(() => { 
        if ($('.pw-templates-v31, .pw-dashboard').length) { 
            TemplatesManager.init(); 
            
            // Expose for modal usage
            window.PWTemplateEditor = TemplateEditor;
            window.PWTemplatePreview = TemplatePreview;
            window.PWTemplateDuplicate = TemplateDuplicate;
            window.PWCategoryManager = CategoryManager;

            $('#pw-confirm-duplicate-btn').on('click', function(e) {
                e.preventDefault();
                TemplateDuplicate.confirm();
            });

            $('#pw-duplicate-new-name').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    TemplateDuplicate.confirm();
                }
            });

            $('.pw-add-variant').on('click', function() {
                TemplateEditor.addVariant($(this).data('type'));
            });

            // Bulk Add Triggers
            $('.pw-bulk-add-btn').on('click', function() {
                TemplateEditor.openBulkModal($(this).data('type'));
            });

            $('#pw-bulk-add-confirm').on('click', function() {
                TemplateEditor.confirmBulkAdd();
            });

            $('#pw-save-template-btn').on('click', function() {
                TemplateEditor.save();
            });
            $('#pw-save-category-btn').on('click', () => CategoryManager.save());

            // --- IMPORT / EXPORT LOGIC ---
            
            // Export
            $(document).on('click', '.pw-export-btn', function(e) {
                e.preventDefault();
                const $card = $(this).closest('.pw-template-card');
                const name = $card.data('template-name');
                if (!name) return;
                
                // Trigger download via iframe or direct location
                // We use a hidden form to POST if needed, but here simple GET or POST with location is enough if not too large.
                // Actually, AJAX download is tricky. Best is to open new tab or location.href with query params if GET.
                // But our AjaxHandler expects POST for 'pw_export_template'.
                // So we create a form dynamically.
                
                const $form = $('<form action="' + pwAdmin.ajaxurl + '" method="post" target="_blank">' +
                    '<input type="hidden" name="action" value="pw_export_template">' +
                    '<input type="hidden" name="nonce" value="' + pwAdmin.nonce + '">' +
                    '<input type="hidden" name="name" value="' + name + '">' +
                    '</form>');
                $('body').append($form);
                $form.submit().remove();
            });

            // Import Button Click
            $('#pw-import-btn').on('click', function(e) {
                e.preventDefault();
                $('#pw-import-file').click();
            });

            // Import File Change
            $('#pw-import-file').on('change', function(e) {
                const file = this.files[0];
                if (!file) return;

                const formData = new FormData();
                formData.append('action', 'pw_import_templates');
                formData.append('nonce', pwAdmin.nonce);
                formData.append('file', file);

                const $btn = $('#pw-import-btn');
                const oldText = $btn.html();
                $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Import...');

                $.ajax({
                    url: pwAdmin.ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message || 'Import réussi !');
                            location.reload();
                        } else {
                            alert('Erreur : ' + (response.data.message || 'Format invalide'));
                        }
                    },
                    error: function() {
                        alert('Erreur réseau lors de l\'import.');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html(oldText);
                        $('#pw-import-file').val(''); // Reset
                    }
                });
            });
        } 
    }); 
     
    window.PWTemplatesManager = TemplatesManager; 
     
})(jQuery); 
