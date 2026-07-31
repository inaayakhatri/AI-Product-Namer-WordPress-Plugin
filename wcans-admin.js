jQuery(function ($) {

    var $btn = $('#wcans-suggest-btn');
    var $result = $('#wcans-result');
    var $suggestedText = $('#wcans-suggested-text');
    var $loading = $('#wcans-loading');
    var $error = $('#wcans-error');
    var $useBtn = $('#wcans-use-name-btn');
    var $pickBtn = $('#wcans-pick-image-btn');
    var $preview = $('#wcans-image-preview');
    var $previewImg = $('#wcans-image-preview-img');
    var $attachmentIdField = $('#wcans-attachment-id');

    var mediaFrame;

    $pickBtn.on('click', function (e) {
        e.preventDefault();

        if (mediaFrame) {
            mediaFrame.open();
            return;
        }

        mediaFrame = wp.media({
            title: 'Select or Upload Image for Naming',
            button: { text: 'Use this image' },
            multiple: false,
            library: { type: 'image' }
        });

        mediaFrame.on('select', function () {
            var attachment = mediaFrame.state().get('selection').first().toJSON();

            $attachmentIdField.val(attachment.id);
            $previewImg.attr('src', attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url);
            $preview.show();
            $btn.prop('disabled', false);

            // Reset any previous suggestion since the image changed
            $result.hide();
            $error.hide();
        });

        mediaFrame.open();
    });

    $btn.on('click', function () {
        $result.hide();
        $error.hide();
        $loading.show();
        $btn.prop('disabled', true);

        $.ajax({
            url: wcans_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'wcans_suggest_name',
                nonce: wcans_ajax.nonce,
                post_id: wcans_ajax.post_id,
                attachment_id: $attachmentIdField.val()
            },
            success: function (response) {
                $loading.hide();
                $btn.prop('disabled', false);

                if (response.success) {
                    $suggestedText.text(response.data.name);
                    $result.show();
                } else {
                    $error.text(response.data || 'Something went wrong.').show();
                }
            },
            error: function () {
                $loading.hide();
                $btn.prop('disabled', false);
                $error.text('Request failed. Please try again.').show();
            }
        });
    });

    function setProductTitle(name) {
        var $titleField = $('#title');
        if ($titleField.length) {
            $titleField.val(name).trigger('change');
            $titleField.focus();
            return true;
        }

        var $postTitleField = $('input[name="post_title"], textarea[name="post_title"]');
        if ($postTitleField.length) {
            $postTitleField.val(name).trigger('change');
            $postTitleField.first().focus();
            return true;
        }

        if (window.wp && wp.data && wp.data.dispatch && wp.data.select) {
            var editorStore = wp.data.dispatch('core/editor');
            if (editorStore && typeof editorStore.editPost === 'function') {
                editorStore.editPost({ title: name });
                return true;
            }
        }

        return false;
    }

    $useBtn.on('click', function () {
        var name = $suggestedText.text();
        if (!name) {
            return;
        }

        setProductTitle(name);
    });

    var $pendingBtn = $('#wcans-pending-name-btn');
    var $statusMsg = $('#wcans-status-msg');
    var $showPendingBtn = $('#wcans-show-pending-btn');
    var $pendingSection = $('#wcans-pending-section');

    function saveNameStatus(status) {
        var name = $suggestedText.text();
        if (!name) {
            return;
        }

        $.ajax({
            url: wcans_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'wcans_save_name_status',
                nonce: wcans_ajax.nonce,
                name: name,
                status: status
            },
            success: function (response) {
                if (response.success) {
                    $statusMsg.css('color', '#2271b1').text(response.data.message).show();
                    loadNameLists(); // refresh the inline lists in place, no page change
                } else {
                    $statusMsg.css('color', '#d63638').text(response.data || 'Failed to save.').show();
                }
            },
            error: function () {
                $statusMsg.css('color', '#d63638').text('Request failed. Please try again.').show();
            }
        });
    }

    $pendingBtn.on('click', function () {
        saveNameStatus('pending');
    });

    $showPendingBtn.on('click', function () {
        $pendingSection.show();
    });

    /**
     * Inline pending list — loaded and updated in place on the
     * product edit page itself, no navigating to a separate admin page.
     */
    var $pendingList = $('#wcans-pending-inline-list');

    function escapeHtml(str) {
        return $('<div>').text(str).html();
    }

    function renderList($container, names) {
        if (!names || !names.length) {
            $container.html('<p class="wcans-empty-msg" style="color:#666;font-size:12px;">None yet.</p>');
            return;
        }

        var rows = '';
        for (var i = 0; i < names.length; i++) {
            var name = names[i];
            rows += '<div class="wcans-inline-row" data-name="' + escapeHtml(name) + '" ' +
                'style="display:flex;align-items:center;justify-content:space-between;padding:4px 0;border-bottom:1px solid #eee;font-size:12px;">' +
                '<span>' + escapeHtml(name) + '</span>' +
                '<span style="display:flex;gap:4px;">' +
                    '<button type="button" class="button button-small wcans-inline-use-btn" data-name="' + escapeHtml(name) + '">Use</button>' +
                    '<button type="button" class="button button-small wcans-inline-remove-btn" data-name="' + escapeHtml(name) + '">Remove</button>' +
                '</span>' +
                '</div>';
        }
        $container.html(rows);
    }

    function loadNameLists() {
        $.ajax({
            url: wcans_ajax.ajax_url,
            method: 'POST',
            timeout: 10000,
            data: {
                action: 'wcans_get_names',
                nonce: wcans_ajax.list_nonce
            },
            success: function (response) {
                if (response.success) {
                    renderList($pendingList, response.data.pending);
                } else {
                    $pendingList.html('<p style="color:#d63638;font-size:12px;">Could not load.</p>');
                }
            },
            error: function (xhr, status) {
                var message = (status === 'timeout') ? 'Request timed out. Please refresh and try again.' : 'Could not load.';
                $pendingList.html('<p style="color:#d63638;font-size:12px;">' + message + '</p>');
            }
        });
    }

    function setProductTitle(name) {
        var $titleField = $('#title');
        if ($titleField.length) {
            $titleField.val(name).trigger('change');
            $titleField.focus();
            return true;
        }

        var $postTitleField = $('input[name="post_title"], textarea[name="post_title"]');
        if ($postTitleField.length) {
            $postTitleField.val(name).trigger('change');
            $postTitleField.first().focus();
            return true;
        }

        if (window.wp && wp.data && wp.data.dispatch && wp.data.select) {
            var editorStore = wp.data.dispatch('core/editor');
            if (editorStore && typeof editorStore.editPost === 'function') {
                editorStore.editPost({ title: name });
                return true;
            }
        }

        return false;
    }

    // Use button clicks, delegated since rows are rebuilt on every refresh
    $(document).on('click', '.wcans-inline-use-btn', function () {
        var name = $(this).data('name');
        if (!name) {
            return;
        }
        setProductTitle(name);
    });

    // Remove button clicks, delegated since rows are rebuilt on every refresh
    $(document).on('click', '.wcans-inline-remove-btn', function () {
        var $btn = $(this);
        var name = $btn.data('name');
        var action = 'wcans_remove_pending_name';

        if (!confirm('Remove "' + name + '" from pending names?')) {
            return;
        }

        $.ajax({
            url: wcans_ajax.ajax_url,
            method: 'POST',
            data: {
                action: action,
                nonce: wcans_ajax.list_nonce,
                name: name
            },
            success: function (response) {
                if (response.success) {
                    $btn.closest('.wcans-inline-row').remove();
                } else {
                    alert('Could not remove — please try again.');
                }
            },
            error: function () {
                alert('Request failed — please try again.');
            }
        });
    });

    // Load both lists as soon as the product edit page is ready
    loadNameLists();

});
