<?php

// Add settings page to the admin menu
add_action('admin_menu', function () {
    add_options_page(
        'Headless API Manager',
        'Headless API Manager',
        'manage_options',
        'headless-api-manager',
        'hram_render_settings_page'
    );
});

// Register all plugin settings
add_action('admin_init', function () {
    // Frontend URL
    register_setting(
        'hram_settings_group',
        'hram_frontend_url',
        [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]
    );

    // API Key
    register_setting(
        'hram_settings_group',
        'hram_api_key',
        [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]
    );

    // Debug mode (boolean)
    register_setting(
        'hram_settings_group',
        'hram_debug_mode',
        [
            'type'              => 'boolean',
            'sanitize_callback' => 'hram_sanitize_checkbox',
            'default'           => false,
        ]
    );

    // API route (string)
    register_setting(
        'hram_settings_group',
        'hram_api_route',
        [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'wp/v2/headless-api',
        ]
    );
});

// Custom sanitization for checkbox
function hram_sanitize_checkbox($value) {
    return (bool) $value;
}

// Render the settings page
function hram_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Headless API Manager</h1>

        <form method="post" action="options.php">
            <?php settings_fields('hram_settings_group'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="hram_frontend_url">Frontend URL</label>
                    </th>
                    <td>
                        <input
                            type="url"
                            id="hram_frontend_url"
                            name="hram_frontend_url"
                            value="<?php echo esc_attr(get_option('hram_frontend_url')); ?>"
                            class="regular-text"
                            placeholder="https://frontend.example.com"
                            required
                        />
                        <p class="description">
                            Used for frontend redirects, CORS, and audio URLs.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="hram_api_key">API Key</label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="hram_api_key"
                            name="hram_api_key"
                            value="<?php echo esc_attr(get_option('hram_api_key')); ?>"
                            class="regular-text"
                            placeholder="x-api-key-here"
                        
                        />
                        <p class="description">
                            Used for frontend WP JSON API access.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="hram_debug_mode">Debug Mode</label>
                    </th>
                    <td>
                        <input
                            type="checkbox"
                            id="hram_debug_mode"
                            name="hram_debug_mode"
                            value="1"
                            <?php checked(1, get_option('hram_debug_mode')); ?>
                        />
                        <p class="description">
                            Enable debug mode for logging and troubleshooting.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="hram_api_route">API Route</label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="hram_api_route"
                            name="hram_api_route"
                            value="<?php echo esc_attr(get_option('hram_api_route')); ?>"
                            class="regular-text"
                            placeholder="wp/v2/headless-api"
                        />
                        <p class="description">
                            Custom API route for your headless endpoints.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
