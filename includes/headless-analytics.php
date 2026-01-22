<?php
if (!defined('ABSPATH')) {
  exit;
} 

/**
 * Register analytics REST endpoint
 */
add_action('rest_api_init', function () {
  register_rest_route(HRAM_API_ROUTE, '/analytics/log', [
    'methods'  => 'POST',
    'callback' => 'hram_log_analytics',
    'permission_callback' => '__return_true',
  ]);
});

/**
 * Log analytics event
 */
function hram_log_analytics(WP_REST_Request $request)
{
  global $wpdb;

  $table = HRAM_ANALYTICS_TABLE;
  $data  = $request->get_json_params();

  if (empty($data['event'])) {
    return new WP_REST_Response([
      'success' => false,
      'message' => 'Event type is required'
    ], 400);
  }

  // Sanitize & normalize
  $insert = [
    'user_id'    => isset($data['user_id']) ? intval($data['user_id']) : null,
    'session_id' => isset($data['session_id']) ? sanitize_text_field($data['session_id']) : null,
    'event'      => sanitize_key($data['event']),
    'song_id'    => isset($data['song_id']) ? intval($data['song_id']) : null,
    'artist_id'  => isset($data['artist_id']) ? intval($data['artist_id']) : null,
    'timestamp'  => isset($data['timestamp']) ? intval($data['timestamp']) : time(),
    'meta'       => isset($data['meta']) ? wp_json_encode($data['meta']) : null,
  ];

  $formats = [
    '%d', // user_id
    '%s', // session_id
    '%s', // event
    '%d', // song_id
    '%d', // artist_id
    '%d', // timestamp
    '%s', // meta
  ];

  $result = $wpdb->insert($table, $insert, $formats);

  if ($result === false) {
    return new WP_REST_Response([
      'success' => false,
      'message' => 'Failed to log analytics event',
      'error'   => $wpdb->last_error,
    ], 500);
  }

  return new WP_REST_Response([
    'success' => true,
    'id' => $wpdb->insert_id,
  ], 201);
}



/**
 * cron jobs
 */
  
// helperes ---------------------------------------------------

function hram_get_last_rollup_time() {
  return (int) get_option('hram_last_analytics_rollup', 0);
}

function hram_set_last_rollup_time($ts) {
  update_option('hram_last_analytics_rollup', (int) $ts, false);
}

function hram_parse_exclude_ids(WP_REST_Request $request) {
  $raw = $request->get_param('excludePostIds');

  if (empty($raw)) {
    return [];
  }

  if (is_string($raw)) {
    $raw = explode(',', $raw);
  }

  if (!is_array($raw)) {
    return [];
  }

  return array_values(array_filter(array_map('intval', $raw)));
}

function hram_build_not_in_clause($ids, $column) {
  global $wpdb;

  if (empty($ids)) {
    return '';
  }

  $placeholders = implode(',', array_fill(0, count($ids), '%d'));
  return $wpdb->prepare(" AND {$column} NOT IN ($placeholders)", ...$ids);
}

// ---------------------------------------------------


// This job processes only recent events (last hour).
add_action('hram_hourly_analytics_rollup', 'hram_rollup_hourly_events');

function hram_rollup_hourly_events() {
  global $wpdb;

  $now   = time();
  $since = hram_get_last_rollup_time();

  if ($since === 0) {
    $since = $now - HOUR_IN_SECONDS;
  }

  /**
   * 1. SONG STATS 
   */
  $wpdb->query("
    INSERT INTO " . HRAM_SONG_STATS_TABLE . " 
      (song_id, play_count, complete_count, playlist_add_count, score, updated_at)
    SELECT 
      song_id,
      SUM(event = 'play'),
      SUM(event = 'play_complete'),
      SUM(event = 'song_add_to_playlist'),
      SUM(
        CASE event
          WHEN 'play' THEN 1
          WHEN 'play_progress_50' THEN 2
          WHEN 'play_complete' THEN 3
          WHEN 'song_add_to_playlist' THEN 4
          ELSE 0
        END
      ),
      $now
    FROM " . HRAM_ANALYTICS_TABLE . "
    WHERE timestamp > $since
      AND song_id IS NOT NULL
    GROUP BY song_id
    ON DUPLICATE KEY UPDATE
      play_count = play_count + VALUES(play_count),
      complete_count = complete_count + VALUES(complete_count),
      playlist_add_count = playlist_add_count + VALUES(playlist_add_count),
      score = score + VALUES(score),
      updated_at = $now
  ");

  /**
   * 2. SESSION × SONG AFFINITY (NEW)
   */
  $wpdb->query("
    INSERT INTO " . HRAM_SESSION_AFFINITY_TABLE . "
      (session_id, song_id, score, last_interaction)
    SELECT
      session_id,
      song_id,
      SUM(
        CASE event
          WHEN 'play' THEN 1
          WHEN 'play_progress_50' THEN 2
          WHEN 'play_complete' THEN 3
          WHEN 'song_add_to_playlist' THEN 4
           WHEN 'song_download' THEN 4
          ELSE 0
        END
      ),
      MAX(timestamp)
    FROM " . HRAM_ANALYTICS_TABLE . "
    WHERE timestamp > $since
      AND session_id IS NOT NULL
      AND song_id IS NOT NULL
    GROUP BY session_id, song_id
    ON DUPLICATE KEY UPDATE
      score = score + VALUES(score),
      last_interaction = VALUES(last_interaction)
  ");

  hram_set_last_rollup_time($now);
  hram_log('hram_hourly_analytics_rollup ran successfully.');
}


 
 // Without decay, old hits dominate forever.
 add_action('hram_daily_trending_decay', function () {
  global $wpdb;

  $wpdb->query("
    UPDATE " . HRAM_SONG_STATS_TABLE . "
    SET score = score * 0.95
    WHERE score > 0
  "); 
  hram_log("hram_daily_trending_decay cron run successfully.");
});





//  Songs played in the same session → related.
add_action('hram_daily_coplay_build', function () {
  global $wpdb;

  $since = time() - DAY_IN_SECONDS;

  $wpdb->query("
    INSERT INTO " . HRAM_COPLAY_TABLE . " (song_id, related_song_id, weight)
    SELECT
      a.song_id,
      b.song_id,
      COUNT(*) AS weight
    FROM " . HRAM_ANALYTICS_TABLE . " a
    JOIN " . HRAM_ANALYTICS_TABLE . " b
      ON a.session_id = b.session_id
      AND a.song_id < b.song_id
    WHERE a.event = 'play'
      AND b.event = 'play'
      AND a.timestamp >= $since
    GROUP BY a.song_id, b.song_id
    ON DUPLICATE KEY UPDATE
      weight = weight + VALUES(weight)
  ");
  hram_log("hram_daily_coplay_build cron run successfully.");
});



//---------------------------------------------------
add_action('rest_api_init', function () {
  register_rest_route(HRAM_API_ROUTE, '/recommend/session', [
    'methods'  => 'GET',
    'callback' => 'hram_recommend_by_session',
    'permission_callback' => '__return_true',
  ]);
});

function hram_recommend_by_session(WP_REST_Request $request) {
  global $wpdb;

  $session_id = sanitize_text_field($request->get_param('session_id'));
  $limit      = min(50, max(1, intval($request->get_param('limit') ?? 20)));
  $excludeIds = hram_parse_exclude_ids($request);

  if (!$session_id) {
    return new WP_REST_Response([
      'success' => false,
      'message' => 'session_id is required'
    ], 400);
  }

  $excludeSql = hram_build_not_in_clause($excludeIds, 's.song_id');

  $sql = "
    SELECT
      s.song_id,
      SUM(s.score) +
      IFNULL(SUM(c.weight), 0) +
      IFNULL(st.score, 0) AS rank_score
    FROM " . HRAM_SESSION_AFFINITY_TABLE . " s
    LEFT JOIN " . HRAM_COPLAY_TABLE . " c
      ON s.song_id = c.song_id
    LEFT JOIN " . HRAM_SONG_STATS_TABLE . " st
      ON s.song_id = st.song_id
    WHERE s.session_id = %s
      {$excludeSql}
    GROUP BY s.song_id
    ORDER BY rank_score DESC
    LIMIT %d
  ";

  $results = $wpdb->get_results(
    $wpdb->prepare($sql, $session_id, $limit),
    ARRAY_A
  );

  return [
    'success' => true,
    'source'  => 'session',
    'data'    => $results
  ];
}


//---------------------------------------------------
add_action('rest_api_init', function () {
  register_rest_route(HRAM_API_ROUTE, '/recommend/user', [
    'methods'  => 'GET',
    'callback' => 'hram_recommend_by_user',
    'permission_callback' => '__return_true',
  ]);
});
function hram_recommend_by_user(WP_REST_Request $request) {
  global $wpdb;

  $user_id    = intval($request->get_param('user_id'));
  $limit      = min(50, max(1, intval($request->get_param('limit') ?? 20)));
  $excludeIds = hram_parse_exclude_ids($request);

  if (!$user_id) {
    return new WP_REST_Response([
      'success' => false,
      'message' => 'user_id is required'
    ], 400);
  }

  $excludeSql = hram_build_not_in_clause($excludeIds, 'ua.song_id');

  $sql = "
    SELECT
      ua.song_id,
      ua.score + IFNULL(st.score, 0) AS rank_score
    FROM " . HRAM_USER_AFFINITY_TABLE . " ua
    LEFT JOIN " . HRAM_SONG_STATS_TABLE . " st
      ON ua.song_id = st.song_id
    WHERE ua.user_id = %d
      {$excludeSql}
    ORDER BY rank_score DESC
    LIMIT %d
  ";

  $results = $wpdb->get_results(
    $wpdb->prepare($sql, $user_id, $limit),
    ARRAY_A
  );

  return [
    'success' => true,
    'source'  => 'user',
    'data'    => $results
  ];
}


//---------------------------------------------------
add_action('rest_api_init', function () {
  register_rest_route(HRAM_API_ROUTE, '/recommend/similar', [
    'methods'  => 'GET',
    'callback' => 'hram_recommend_similar',
    'permission_callback' => '__return_true',
  ]);
});

function hram_recommend_similar(WP_REST_Request $request) {
  global $wpdb;

  $song_id    = intval($request->get_param('song_id'));
  $limit      = min(50, max(1, intval($request->get_param('limit') ?? 20)));
  $excludeIds = hram_parse_exclude_ids($request);

  if (!$song_id) {
    return new WP_REST_Response([
      'success' => false,
      'message' => 'song_id is required'
    ], 400);
  }

  $excludeSql = hram_build_not_in_clause($excludeIds, 'related_song_id');

  $sql = "
    SELECT
      related_song_id AS song_id,
      weight
    FROM " . HRAM_COPLAY_TABLE . "
    WHERE song_id = %d
      {$excludeSql}
    ORDER BY weight DESC
    LIMIT %d
  ";

  $results = $wpdb->get_results(
    $wpdb->prepare($sql, $song_id, $limit),
    ARRAY_A
  );

  return [
    'success' => true,
    'source'  => 'coplay',
    'data'    => $results
  ];
}


//---------------------------------------------------
add_action('rest_api_init', function () {
  register_rest_route(HRAM_API_ROUTE, '/recommend/for-you', [
    'methods'  => 'GET',
    'callback' => 'hram_recommend_for_you',
    'permission_callback' => '__return_true',
  ]);
});
function hram_recommend_for_you(WP_REST_Request $request) {
  global $wpdb;

  $session_id = sanitize_text_field($request->get_param('session_id'));
  $user_id    = intval($request->get_param('user_id'));
  $limit      = min(50, max(1, intval($request->get_param('limit') ?? 20)));
  $excludeIds = hram_parse_exclude_ids($request);

  $w_session = $session_id ? 0.5 : 0;
  $w_user    = $user_id ? 0.3 : 0;
  $w_global  = 1 - ($w_session + $w_user);

  $excludeSql = hram_build_not_in_clause($excludeIds, 'ss.song_id');

  $sql = "
    SELECT
      ss.song_id,
      (
        COALESCE(sa.score, 0) * %f +
        COALESCE(ua.score, 0) * %f +
        COALESCE(ss.score, 0) * %f
      ) AS rank_score
    FROM " . HRAM_SONG_STATS_TABLE . " ss

    LEFT JOIN " . HRAM_SESSION_AFFINITY_TABLE . " sa
      ON ss.song_id = sa.song_id
      " . ($session_id ? $wpdb->prepare("AND sa.session_id = %s", $session_id) : "") . "

    LEFT JOIN " . HRAM_USER_AFFINITY_TABLE . " ua
      ON ss.song_id = ua.song_id
      " . ($user_id ? $wpdb->prepare("AND ua.user_id = %d", $user_id) : "") . "

    WHERE 1=1
      {$excludeSql}

    ORDER BY rank_score DESC
    LIMIT %d
  ";

  $prepared = $wpdb->prepare(
    $sql,
    $w_session,
    $w_user,
    $w_global,
    $limit
  );

  $results = $wpdb->get_results($prepared, ARRAY_A);

  return [
    'success' => true,
    'source'  => 'hybrid',
    'weights' => [
      'session' => $w_session,
      'user'    => $w_user,
      'global'  => $w_global,
    ],
    'data' => $results,
  ];
}

//---------------------------------------------------
add_action('rest_api_init', function () {
  register_rest_route(HRAM_API_ROUTE, '/recommend/trending', [
    'methods'  => 'GET',
    'callback' => 'hram_recommend_trending',
    'permission_callback' => '__return_true',
  ]);
});
function hram_recommend_trending(WP_REST_Request $request) {
  global $wpdb;

  $limit      = min(50, max(1, intval($request->get_param('limit') ?? 20)));
  $excludeIds = hram_parse_exclude_ids($request);

  // Optional time window (seconds)
  // example: ?since=86400 (last 24h)
  $since = intval($request->get_param('since'));

  $excludeSql = hram_build_not_in_clause($excludeIds, 'ss.song_id');
  $timeSql    = $since > 0
    ? $wpdb->prepare(" AND ss.updated_at >= %d", time() - $since)
    : '';

  /**
   * Freshness-aware trending score
   */
  $sql = "
    SELECT
      ss.song_id,

      (
        ss.score *
        CASE
          WHEN ss.updated_at >= UNIX_TIMESTAMP() - 21600 THEN 1.0   -- 6h
          WHEN ss.updated_at >= UNIX_TIMESTAMP() - 86400 THEN 0.7   -- 24h
          WHEN ss.updated_at >= UNIX_TIMESTAMP() - 259200 THEN 0.4  -- 3 days
          ELSE 0.2
        END
      ) AS rank_score

    FROM " . HRAM_SONG_STATS_TABLE . " ss

    WHERE ss.score > 0
      {$excludeSql}
      {$timeSql}

    ORDER BY rank_score DESC
    LIMIT %d
  ";

  $results = $wpdb->get_results(
    $wpdb->prepare($sql, $limit),
    ARRAY_A
  );

  return [
    'success' => true,
    'source'  => 'trending',
    'data'    => $results
  ];
}

//---------------------------------------------------

add_action('hram_daily_session_cleanup', 'hram_cleanup_abandoned_sessions');
function hram_cleanup_abandoned_sessions() {
  global $wpdb;

  $threshold = time() - HRAM_SESSION_TTL;

  /**
   * 1. Find dead session_ids
   * - guest only
   * - inactive
   */
  $dead_sessions = $wpdb->get_col(
    $wpdb->prepare("
      SELECT DISTINCT session_id
      FROM " . HRAM_SESSION_AFFINITY_TABLE . "
      WHERE last_interaction < %d
    ", $threshold)
  );

  if (empty($dead_sessions)) {
    hram_log('Session cleanup: nothing to clean.');
    return;
  }

  $placeholders = implode(',', array_fill(0, count($dead_sessions), '%s'));

  /**
   * 2. Delete session affinity rows
   */
  $wpdb->query(
    $wpdb->prepare("
      DELETE FROM " . HRAM_SESSION_AFFINITY_TABLE . "
      WHERE session_id IN ($placeholders)
    ", ...$dead_sessions)
  );

  /**
   * 3. (Optional but recommended)
   * Delete old analytics events for dead sessions
   */
  $wpdb->query(
    $wpdb->prepare("
      DELETE FROM " . HRAM_ANALYTICS_TABLE . "
      WHERE session_id IN ($placeholders)
        AND timestamp < %d
    ", ...array_merge($dead_sessions, [$threshold]))
  );

  hram_log(
    sprintf(
      'Session cleanup: removed %d abandoned sessions.',
      count($dead_sessions)
    )
  );
}













