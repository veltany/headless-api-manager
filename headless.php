<?php
/**
 * Plugin Name: Headless API Manager
 * Description: Lightweight REST API endpoints for headless WordPress frontends.
 * Author: Engr Sam Chukwu
 * Version: 1.2.22
 * License: GPL2
 * Text Domain: headless-api-manager
 * Author URI: https://github.com/veltany 
 * GitHub Plugin URI: https://github.com/veltany/headless-api-manager
 * GitHub Branch: main
 * Requires at least: 6.6
 * Requires PHP: 8.2
 */

if (!defined('ABSPATH')) {
  exit;
}


// global helper
function hram_get_option($option, $default = null) {
    return untrailingslashit(
        get_option($option, $default)
    );
}

define('HEADLESS_API_PATH', plugin_dir_path(__FILE__));
define('HRAM_PATH', plugin_dir_path(__FILE__));
define('HRAM_PREFIX', 'HRAM');
define('HRAM_API_ROUTE', hram_get_option('hram_api_route', 'wp/v2/headless-api')); 
define('HRAM_DEBUG_MODE', hram_get_option('hram_debug_mode', false));
define('HRAM_VERSION', '1.2.8');
define('HRAM_FRONTEND_URL', hram_get_option('hram_frontend_url', '')); // set your frontend url here if needed
define('HRAM_SESSION_TTL', DAY_IN_SECONDS * 3); // 72 hours



//Temporary logging
function hram_log($message)
{
if(!HRAM_DEBUG_MODE) return;

$pluginlog = HRAM_PATH.'debug.log';  
$message.= "\n";
error_log(current_time('mysql').": $message", 3, $pluginlog);
}



// database
global $wpdb; 
// tables
define('HRAM_KV_TABLE', $wpdb->prefix . 'headless_kv_cache');
define('HRAM_ANALYTICS_TABLE', $wpdb->prefix . 'headless_rest_analytics_log');
define('HRAM_SONG_STATS_TABLE', $wpdb->prefix . 'headless_song_stats');
define('HRAM_USER_AFFINITY_TABLE', $wpdb->prefix . 'headless_user_song_affinity');
define('HRAM_COPLAY_TABLE', $wpdb->prefix . 'headless_song_coplay'); 
define('HRAM_SESSION_AFFINITY_TABLE', $wpdb->prefix . 'headless_session_song_affinity');



require_once HEADLESS_API_PATH . 'includes/helpers.php';
require_once HEADLESS_API_PATH . 'includes/menu.php';
require_once HEADLESS_API_PATH . 'includes/site-logo.php';
require_once HEADLESS_API_PATH . 'includes/frontend-redirect.php';
require_once HEADLESS_API_PATH . 'includes/custom_rest_fields.php';
require_once HEADLESS_API_PATH . 'includes/headless-kv-cache.php';
require_once HEADLESS_API_PATH . 'includes/headless-analytics.php';
require_once HEADLESS_API_PATH . 'includes/lyrics/lyrics.php';
require_once HEADLESS_API_PATH . 'includes/admin/settings.php';
require_once HEADLESS_API_PATH . 'includes/authorization.php';



//-------------------------------------
// PLUGIN UPDATES
 require HEADLESS_API_PATH . 'plugin-update/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;


$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://raw.githubusercontent.com/veltany/headless-api-manager/main/release.json',
    __FILE__,
    'headless-api-manager'
);



// Redirect frontend requests
add_action('init', 'headless_api_frontend_redirect', 1);


/**
 * Activate: create table
 */
register_activation_hook(__FILE__, function () {
    global $wpdb;

    $charset = $wpdb->get_charset_collate();

    $sql = "
    CREATE TABLE " . HRAM_KV_TABLE . " (
        cache_key VARCHAR(191) PRIMARY KEY,
        cache_value LONGTEXT NOT NULL,
        cache_tags TEXT NULL,
        expires_at INT NOT NULL,
        updated_at INT NOT NULL,
        INDEX expires_at (expires_at)
    ) $charset;
    
   CREATE TABLE ". HRAM_ANALYTICS_TABLE ." (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id BIGINT NULL,
  session_id VARCHAR(64),
  event VARCHAR(32),
  song_id BIGINT,
  artist_id BIGINT,
  timestamp INT,
  meta JSON,
  PRIMARY KEY (id)
) $charset;

CREATE TABLE ".HRAM_SONG_STATS_TABLE ." (
  song_id BIGINT NOT NULL,
  play_count INT DEFAULT 0,
  complete_count INT DEFAULT 0,
  playlist_add_count INT DEFAULT 0,
  score FLOAT DEFAULT 0,
  updated_at INT,
  PRIMARY KEY (song_id)
) $charset;


CREATE TABLE ". HRAM_USER_AFFINITY_TABLE ." (
  user_id BIGINT,
  song_id BIGINT,
  score FLOAT DEFAULT 0,
  last_interaction INT,
  PRIMARY KEY (user_id, song_id)
);
 
 
 CREATE TABLE ".HRAM_COPLAY_TABLE." (
  song_id BIGINT,
  related_song_id BIGINT,
  weight INT DEFAULT 0,
  PRIMARY KEY (song_id, related_song_id)
);  

CREATE TABLE ".HRAM_SESSION_AFFINITY_TABLE." (
  session_id VARCHAR(64) NOT NULL,
  song_id BIGINT NOT NULL,
  score FLOAT DEFAULT 0,
  last_interaction INT,
  PRIMARY KEY (session_id, song_id),
  KEY idx_song_id (song_id),
  KEY idx_session_id (session_id)
) $charset;

    
    "; 
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});


  
  
  
  register_activation_hook(__FILE__, function () {
  if (!wp_next_scheduled('hram_hourly_analytics_rollup')) {
    wp_schedule_event(time() + 60, 'hourly', 'hram_hourly_analytics_rollup');
  }

  if (!wp_next_scheduled('hram_daily_trending_decay')) {
    wp_schedule_event(time() + 120, 'daily', 'hram_daily_trending_decay');
  }

  if (!wp_next_scheduled('hram_daily_coplay_build')) {
    wp_schedule_event(time() + 180, 'daily', 'hram_daily_coplay_build');
  }
  if (!wp_next_scheduled('hram_daily_session_cleanup')) {
  wp_schedule_event(time(), 'daily', 'hram_daily_session_cleanup');
}
if (!wp_next_scheduled('hram_kv_cleanup')) {
    wp_schedule_event(time(), 'hourly', 'hram_kv_cleanup');
}

// Install object cache
if (  !get_option('hkvc_object_cache_installed')  && function_exists('hkvc_install_object_cache') )
{   //hkvc_install_object_cache();
 } 
});

register_deactivation_hook(__FILE__, function () {
  wp_clear_scheduled_hook('hram_hourly_analytics_rollup');
  wp_clear_scheduled_hook('hram_daily_trending_decay');
  wp_clear_scheduled_hook('hram_daily_coplay_build');
  wp_clear_scheduled_hook('hram_daily_session_cleanup');
  wp_clear_scheduled_hook('hram_kv_cleanup');
  if ( function_exists('hkvc_uninstall_object_cache') )
  {   //hkvc_uninstall_object_cache();
   }
});


