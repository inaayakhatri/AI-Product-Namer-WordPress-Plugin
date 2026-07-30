<?php
/**
 * Plugin Name: WooCommerce AI Product Name Suggester (Gemini - Free)
 * Description: Suggests a product name based on the Featured Image and the theme of your existing product names, using Google Gemini's free API.
 * Version: 1.2
 * Author: Custom
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ---------------------------------------------------
 * 1. Settings page: store the Gemini API key
 * ---------------------------------------------------
 */
add_action( 'admin_menu', 'wcans_add_settings_page' );
function wcans_add_settings_page() {
    add_options_page(
        'AI Product Namer Settings',
        'AI Product Namer',
        'manage_options',
        'wcans-settings',
        'wcans_render_settings_page'
    );
}

add_action( 'admin_init', 'wcans_register_settings' );
function wcans_register_settings() {
    register_setting( 'wcans_settings_group', 'wcans_gemini_api_key', array(
        'sanitize_callback' => 'sanitize_text_field',
    ) );
}

function wcans_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>AI Product Namer Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'wcans_settings_group' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wcans_gemini_api_key">Google Gemini API Key</label></th>
                    <td>
                        <input type="password" id="wcans_gemini_api_key" name="wcans_gemini_api_key"
                               value="<?php echo esc_attr( get_option( 'wcans_gemini_api_key' ) ); ?>"
                               class="regular-text" autocomplete="off" />
                        <p class="description">
                            Free key, no card needed. Get one at
                            <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com/app/apikey</a>
                            (sign in with any Google account &rarr; "Create API Key").
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * ---------------------------------------------------
 * 2. Meta box on the product edit screen
 * ---------------------------------------------------
 */
add_action( 'add_meta_boxes', 'wcans_add_meta_box' );
function wcans_add_meta_box() {
    add_meta_box(
        'wcans_name_suggester',
        'AI Product Name Suggester',
        'wcans_render_meta_box',
        'product',
        'side',
        'low'
    );
}

function wcans_render_meta_box( $post ) {
    $has_key = get_option( 'wcans_gemini_api_key' ) ? true : false;
    ?>
    <div id="wcans-box">
        <?php if ( ! $has_key ) : ?>
            <p style="color:#d63638;">
                No API key set. <a href="<?php echo esc_url( admin_url( 'options-general.php?page=wcans-settings' ) ); ?>">Add one here (free)</a>.
            </p>
        <?php endif; ?>

        <p style="color:#666;font-size:12px;">Pick any photo</p>

        <button type="button" id="wcans-pick-image-btn" class="button" style="width:100%;margin-bottom:8px;">
            📷 Select Image for Naming
        </button>

        <div id="wcans-image-preview" style="display:none;margin-bottom:8px;text-align:center;">
            <img id="wcans-image-preview-img" src="" style="max-width:100%;max-height:150px;border:1px solid #ddd;" />
        </div>

        <input type="hidden" id="wcans-attachment-id" value="" />

        <button type="button" id="wcans-suggest-btn" class="button button-secondary" style="width:100%;" disabled>
            ✨ Suggest Product Name
        </button>

        <div id="wcans-result" style="margin-top:10px;display:none;">
            <p style="font-weight:600;margin-bottom:4px;">Suggested name:</p>
            <p id="wcans-suggested-text" style="padding:8px;background:#f0f6fc;border-left:3px solid #2271b1;"></p>
            <button type="button" id="wcans-use-name-btn" class="button button-primary" style="width:100%;margin-bottom:6px;">✅ Use this name</button>
            <div style="display:flex;gap:6px;">
                <button type="button" id="wcans-pending-name-btn" class="button" style="flex:1;">🕒 Add to Pending</button>
                <button type="button" id="wcans-reject-name-btn" class="button" style="flex:1;">❌ Reject</button>
            </div>
            <p id="wcans-status-msg" style="margin-top:6px;display:none;color:#2271b1;"></p>
        </div>

        <div id="wcans-loading" style="display:none;margin-top:10px;">Analyzing image...</div>
        <div id="wcans-error" style="display:none;margin-top:10px;color:#d63638;"></div>

        <hr style="margin:14px 0;" />

        <p style="font-weight:600;margin-bottom:6px;">📋 Pending Names</p>
        <div id="wcans-pending-inline-list" style="margin-bottom:10px;">
            <p class="wcans-empty-msg" style="color:#666;font-size:12px;">Loading…</p>
        </div>

        <p style="font-weight:600;margin-bottom:6px;">❌ Rejected Names <span style="font-weight:normal;font-size:11px;color:#666;">(reference)</span></p>
        <div id="wcans-rejected-inline-list" style="margin-bottom:6px;">
            <p class="wcans-empty-msg" style="color:#666;font-size:12px;">Loading…</p>
        </div>
    </div>
    <?php
}

/**
 * ---------------------------------------------------
 * 3. Enqueue JS only on product edit screen
 * ---------------------------------------------------
 */
add_action( 'admin_enqueue_scripts', 'wcans_enqueue_scripts' );
function wcans_enqueue_scripts( $hook ) {
    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    $post_type = $screen && isset( $screen->post_type ) ? $screen->post_type : '';

    if ( 'product' !== $post_type ) {
        return;
    }

    wp_enqueue_media();

    wp_enqueue_script(
        'wcans-script',
        plugin_dir_url( __FILE__ ) . 'wcans-admin.js',
        array( 'jquery' ),
        '1.2',
        true
    );

    wp_localize_script( 'wcans-script', 'wcans_ajax', array(
        'ajax_url'   => admin_url( 'admin-ajax.php' ),
        'nonce'      => wp_create_nonce( 'wcans_nonce' ),
        'list_nonce' => wp_create_nonce( 'wcans_pending_page_nonce' ),
        'post_id'    => isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0,
    ) );
}

/**
 * ---------------------------------------------------
 * 4. Name-quality validation helpers
 * ---------------------------------------------------
 */

/**
 * Rejects names that are empty, single letters/words, or common
 * "I couldn't do it" filler the model sometimes returns instead
 * of an actual name.
 */
function wcans_is_valid_name( $name ) {
    $name = trim( (string) $name );

    if ( '' === $name ) {
        return false;
    }

    // Reject if any individual word is a single character (e.g. "A", "Xz" is fine, "A B" is not).
    $words = preg_split( '/\s+/', $name );
    foreach ( $words as $word ) {
        $letters_only = preg_replace( '/[^\p{L}]/u', '', $word );
        if ( '' === $letters_only || mb_strlen( $letters_only ) < 2 ) {
            return false;
        }
    }

    // Reject known "no suggestion" filler phrases the model might return.
    $banned_phrases = array(
        'no name', 'n/a', 'na', 'none', 'unable', 'cannot generate',
        "can't generate", 'no suggestion', 'unknown', 'error', 'null',
    );
    $lower = mb_strtolower( $name );
    foreach ( $banned_phrases as $phrase ) {
        if ( false !== strpos( $lower, $phrase ) ) {
            return false;
        }
    }

    return true;
}

/**
 * ---------------------------------------------------
 * 5. AJAX handler: analyze image + suggest name via Gemini
 * ---------------------------------------------------
 */
add_action( 'wp_ajax_wcans_suggest_name', 'wcans_suggest_name' );
function wcans_suggest_name() {
    check_ajax_referer( 'wcans_nonce', 'nonce' );

    if ( ! current_user_can( 'edit_products' ) ) {
        wp_send_json_error( 'Not allowed' );
    }

    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    $attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
    $api_key = get_option( 'wcans_gemini_api_key' );

    if ( ! $api_key ) {
        wp_send_json_error( 'No API key configured. Go to Settings > AI Product Namer.' );
    }

    // Fall back to the Featured Image if no attachment was explicitly picked
    if ( ! $attachment_id && $post_id ) {
        $attachment_id = get_post_thumbnail_id( $post_id );
    }

    if ( ! $attachment_id ) {
        wp_send_json_error( 'No image selected. Click "Select Image for Naming" first.' );
    }

    // Validate the attachment actually is a media-library image attachment,
    // not an arbitrary ID pointing at some other file/post on the site.
    if ( 'attachment' !== get_post_type( $attachment_id ) ) {
        wp_send_json_error( 'Invalid image selection.' );
    }

    // Get the image file
    $file_path = get_attached_file( $attachment_id );

    if ( ! $file_path || ! file_exists( $file_path ) ) {
        wp_send_json_error( 'Could not locate the image file on the server.' );
    }

    // HEIC is intentionally excluded: Gemini's vision endpoint does not reliably
    // accept image/heic as an inline_data mime type, so we only allow formats
    // it consistently supports.
    $mime_type = get_post_mime_type( $attachment_id );
    if ( ! in_array( $mime_type, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
        wp_send_json_error( 'Unsupported image type. Use JPEG, PNG, or WEBP.' );
    }

    $image_data = file_get_contents( $file_path );
    if ( false === $image_data ) {
        wp_send_json_error( 'Failed to read the image file.' );
    }
    $base64_image = base64_encode( $image_data );

    // Gather a sample of existing product names to establish "theme"
    $existing_products = get_posts( array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'post__not_in'   => array( $post_id ),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ) );

    $existing_titles = array();
    foreach ( $existing_products as $pid ) {
        $existing_titles[] = get_the_title( $pid );
    }
    $titles_text = ! empty( $existing_titles ) ? implode( "\n- ", $existing_titles ) : '(No existing products yet — just suggest a clean, appealing name.)';

    $pending_names = get_option( 'wcans_pending_names', array() );
    if ( ! is_array( $pending_names ) ) {
        $pending_names = array();
    }
    $pending_names_text = ! empty( $pending_names ) ? implode( "\n- ", array_slice( $pending_names, 0, 15 ) ) : '(No pending names yet.)';

    $rejected_names = get_option( 'wcans_rejected_names', array() );
    if ( ! is_array( $rejected_names ) ) {
        $rejected_names = array();
    }
    $rejected_names_text = ! empty( $rejected_names ) ? implode( "\n- ", array_slice( $rejected_names, 0, 15 ) ) : '(No rejected names yet.)';

    $prompt = "You are a naming assistant for a clothing store. Always return one usable product name. Never reply with placeholder or refusal text such as 'no name', 'not available', 'N/A', 'unknown', 'unable', or anything equivalent. Never return a single letter, an initial, or an abbreviation.\n\n"
        . "Use the style of the existing product names and the pending names below as your main theme guide. Do not imitate the rejected names below; avoid them entirely.\n\n"
        . "Existing product names:\n- " . $titles_text . "\n\n"
        . "Pending names (theme guide):\n- " . $pending_names_text . "\n\n"
        . "Rejected names (do not use):\n- " . $rejected_names_text . "\n\n"
        . "Look at the attached image of a clothing item. Suggest ONE product name for it that matches the style and theme of the existing and pending names above (word choice, tone, length, poetic/descriptive style, etc.). If there are no existing names, create a clean, appealing, sellable name based on the image.\n\n"
        . "STRICT RULES for the name:\n"
        . "- Use real Urdu or Hindi word(s), written in English/Roman letters (for example: 'Gulabi', 'Noor', 'Sitara', 'Anaar'), not English words.\n"
        . "- Every word MUST be a real, complete, recognizable Urdu/Hindi word with at least 2 letters. NEVER respond with a single letter, an initial, or an abbreviation.\n"
        . "- Always produce an actual name, even for a plain or hard-to-describe image.\n"
        . "- The name should be a real, recognizable Urdu/Hindi word or short phrase, not gibberish.\n"
        . "- You may reference the color(s) of the item in the image using an Urdu/Hindi color word if it fits naturally.\n"
        . "- Keep it short (1-3 words), sellable, and easy for an English-speaking customer to read and pronounce.\n\n"
        . "Respond with ONLY the product name text, in Roman/English letters. No quotes, no explanation, no punctuation at the end.";

    // Gemini API (free tier) - gemini-2.5-flash supports vision
    $model = 'gemini-2.5-flash';
    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . rawurlencode( $api_key );

    $body = array(
        'contents' => array(
            array(
                'parts' => array(
                    array( 'text' => $prompt ),
                    array(
                        'inline_data' => array(
                            'mime_type' => $mime_type,
                            'data'      => $base64_image,
                        ),
                    ),
                ),
            ),
        ),
        'generationConfig' => array(
            'maxOutputTokens' => 40,
            'temperature'     => 0.8,
        ),
    );

    // Try a few times if the model returns a single letter, empty text,
    // or refusal filler instead of a usable name — the user never sees
    // an intermediate failure, only the final good name or one clear error.
    $max_attempts = 3;
    $suggested_name = '';
    $last_error = '';

    for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
        $response = wp_remote_post( $endpoint, array(
            'headers' => array(
                'content-type' => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            $last_error = 'Request failed: ' . $response->get_error_message();
            continue;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $response_code ) {
            $last_error = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : 'Unknown API error (HTTP ' . $response_code . ')';
            continue;
        }

        $candidate_name = '';
        if ( isset( $response_body['candidates'][0]['content']['parts'] ) && is_array( $response_body['candidates'][0]['content']['parts'] ) ) {
            foreach ( $response_body['candidates'][0]['content']['parts'] as $part ) {
                if ( isset( $part['text'] ) ) {
                    $candidate_name .= $part['text'];
                }
            }
        }

        $candidate_name = trim( $candidate_name );
        $candidate_name = trim( $candidate_name, "\"'“”‘’ \n\r\t" );

        if ( wcans_is_valid_name( $candidate_name ) ) {
            $suggested_name = $candidate_name;
            break;
        }

        $last_error = 'Model returned an unusable name.';
    }

    if ( empty( $suggested_name ) ) {
        wp_send_json_error( 'Couldn\'t come up with a good name from this photo after a few tries — try a clearer or different image, or click Suggest again.' );
    }

    wp_send_json_success( array( 'name' => $suggested_name ) );
}

/**
 * Return both lists as JSON, for the inline display in the meta box
 * (so the product edit page never has to navigate away to show them).
 */
add_action( 'wp_ajax_wcans_get_names', 'wcans_get_names' );
function wcans_get_names() {
    check_ajax_referer( 'wcans_pending_page_nonce', 'nonce' );

    if ( ! current_user_can( 'edit_products' ) ) {
        wp_send_json_error( 'Not allowed' );
    }

    $pending  = get_option( 'wcans_pending_names', array() );
    $rejected = get_option( 'wcans_rejected_names', array() );

    wp_send_json_success( array(
        'pending'  => is_array( $pending ) ? array_values( $pending ) : array(),
        'rejected' => is_array( $rejected ) ? array_values( $rejected ) : array(),
    ) );
}

/**
 * ---------------------------------------------------
 * 6. Pending / Rejected name storage
 * ---------------------------------------------------
 */
add_action( 'wp_ajax_wcans_save_name_status', 'wcans_save_name_status' );
function wcans_save_name_status() {
    check_ajax_referer( 'wcans_nonce', 'nonce' );

    if ( ! current_user_can( 'edit_products' ) ) {
        wp_send_json_error( 'Not allowed' );
    }

    $name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';

    if ( empty( $name ) || ! in_array( $status, array( 'pending', 'rejected' ), true ) ) {
        wp_send_json_error( 'Invalid data.' );
    }

    $option_key = ( 'pending' === $status ) ? 'wcans_pending_names' : 'wcans_rejected_names';
    $list = get_option( $option_key, array() );
    if ( ! is_array( $list ) ) {
        $list = array();
    }

    // Avoid exact duplicates
    if ( ! in_array( $name, $list, true ) ) {
        $list[] = $name;
    }

    $saved = update_option( $option_key, $list, false ); // false = do not autoload, keeps this light on every page load

    // update_option() returns false both on failure AND when the new value
    // is identical to what's already stored (e.g. re-adding a duplicate) —
    // so we verify by re-reading rather than trusting the return value.
    $verify = get_option( $option_key, array() );
    if ( ! is_array( $verify ) || ! in_array( $name, $verify, true ) ) {
        wp_send_json_error( 'Could not save — please try again.' );
    }

    wp_send_json_success( array( 'message' => ( 'pending' === $status ) ? 'Added to Pending Names.' : 'Marked as Rejected.' ) );
}

/**
 * Remove a name from the pending list (used from the Pending Names page)
 */
add_action( 'wp_ajax_wcans_remove_pending_name', 'wcans_remove_pending_name' );
function wcans_remove_pending_name() {
    check_ajax_referer( 'wcans_pending_page_nonce', 'nonce' );

    if ( ! current_user_can( 'edit_products' ) ) {
        wp_send_json_error( 'Not allowed' );
    }

    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $list = get_option( 'wcans_pending_names', array() );

    if ( is_array( $list ) ) {
        $list = array_values( array_diff( $list, array( $name ) ) );
        update_option( 'wcans_pending_names', $list, false );
    }

    wp_send_json_success();
}

/**
 * Remove a name from the rejected list (used from the Pending Names page)
 */
add_action( 'wp_ajax_wcans_remove_rejected_name', 'wcans_remove_rejected_name' );
function wcans_remove_rejected_name() {
    check_ajax_referer( 'wcans_pending_page_nonce', 'nonce' );

    if ( ! current_user_can( 'edit_products' ) ) {
        wp_send_json_error( 'Not allowed' );
    }

    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $list = get_option( 'wcans_rejected_names', array() );

    if ( is_array( $list ) ) {
        $list = array_values( array_diff( $list, array( $name ) ) );
        update_option( 'wcans_rejected_names', $list, false );
    }

    wp_send_json_success();
}

/**
 * ---------------------------------------------------
 * 7. Pending Names admin page (under Products menu)
 * ---------------------------------------------------
 */
add_action( 'admin_menu', 'wcans_add_pending_names_page' );
function wcans_add_pending_names_page() {
    add_submenu_page(
        'edit.php?post_type=product',
        'Pending Product Names',
        'Pending Names',
        'edit_products',
        'wcans-pending-names',
        'wcans_render_pending_names_page'
    );
}

function wcans_render_pending_names_page() {
    $pending  = get_option( 'wcans_pending_names', array() );
    $rejected = get_option( 'wcans_rejected_names', array() );
    if ( ! is_array( $pending ) ) {
        $pending = array();
    }
    if ( ! is_array( $rejected ) ) {
        $rejected = array();
    }

    $nonce = wp_create_nonce( 'wcans_pending_page_nonce' );
    ?>
    <div class="wrap">
        <h1>Pending Product Names</h1>
        <p>Names added here from the AI Product Name Suggester can be reused for other products. Click "Copy" to copy a name to your clipboard, then paste it as a product title.</p>

        <?php if ( empty( $pending ) ) : ?>
            <p><em>No pending names yet.</em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:600px;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="wcans-pending-list">
                    <?php foreach ( $pending as $name ) : ?>
                        <tr data-name="<?php echo esc_attr( $name ); ?>">
                            <td><?php echo esc_html( $name ); ?></td>
                            <td>
                                <button type="button" class="button wcans-copy-btn" data-name="<?php echo esc_attr( $name ); ?>">Copy</button>
                                <button type="button" class="button wcans-remove-btn" data-name="<?php echo esc_attr( $name ); ?>" data-list="pending">Remove</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2 style="margin-top:30px;">Rejected Names <span style="font-weight:normal;font-size:14px;color:#666;">(for reference only)</span></h2>
        <?php if ( empty( $rejected ) ) : ?>
            <p><em>No rejected names yet.</em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:600px;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th style="width:80px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="wcans-rejected-list">
                    <?php foreach ( $rejected as $name ) : ?>
                        <tr data-name="<?php echo esc_attr( $name ); ?>">
                            <td><?php echo esc_html( $name ); ?></td>
                            <td>
                                <button type="button" class="button wcans-remove-btn" data-name="<?php echo esc_attr( $name ); ?>" data-list="rejected">Remove</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
    jQuery(function ($) {
        var nonce = '<?php echo esc_js( $nonce ); ?>';

        $('.wcans-copy-btn').on('click', function () {
            var name = $(this).data('name');
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(name).then(function () {
                    alert('Copied: ' + name);
                }, function () {
                    window.prompt('Copy this name:', name);
                });
            } else {
                // Clipboard API needs HTTPS/localhost; fall back to a manual copy prompt.
                window.prompt('Copy this name:', name);
            }
        });

        $('.wcans-remove-btn').on('click', function () {
            var $btn = $(this);
            var name = $btn.data('name');
            var list = $btn.data('list'); // 'pending' or 'rejected'
            var action = (list === 'rejected') ? 'wcans_remove_rejected_name' : 'wcans_remove_pending_name';

            if (!confirm('Remove "' + name + '" from ' + list + ' names?')) {
                return;
            }
            $.post(ajaxurl, {
                action: action,
                nonce: nonce,
                name: name
            }, function (response) {
                if (response.success) {
                    $btn.closest('tr').remove();
                } else {
                    alert('Could not remove — please refresh and try again.');
                }
            }).fail(function () {
                alert('Request failed — please refresh and try again.');
            });
        });
    });
    </script>
    <?php
}
