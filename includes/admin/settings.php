<?php

add_action('admin_menu', function () {
    add_options_page(
        'Headless API Manager',
        'Headless API Manager',
        'manage_options',
        'headless-api-manager',
        'hram_render_settings_page'
    );
});


add_action('admin_init', function () {
    register_setting(
        'hram_settings_group',
        'hram_frontend_url',
        [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]
    );
});


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
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
