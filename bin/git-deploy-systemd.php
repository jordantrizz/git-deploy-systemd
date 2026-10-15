<?php
/**
 * Plugin Name: Git Deploy
 * Description: Webhook and admin UI for triggering git pull via .git-pull-trigger files
 * Version:     0.0.1
 * Author:      jordantrizz
 * Plugin URI:  https://github.com/jordantrizz/git-deploy-systemd
 *
 * This is a MU-Plugin (must-use). Place in wp-content/mu-plugins/git-deploy.php
 * or the full directory structure at wp-content/mu-plugins/git-deploy/.
 *
 * Components:
 *   1. Admin UI  — Tools > Git Deploy page with trigger buttons + webhook config
 *   2. REST API  — /wp-json/git-deploy/v1/trigger endpoint
 *   3. Settings  — JSON file stored in wp-content/uploads/git-deploy-settings.json
 *   4. Logging   — JSON file stored in wp-content/uploads/git-deploy-logs.json
 */

defined( 'ABSPATH' ) || exit;

define( 'GIT_DEPLOY_VERSION', '0.0.1' );
define( 'GIT_DEPLOY_SETTINGS_FILE', WP_CONTENT_DIR . '/uploads/git-deploy-settings.json' );
define( 'GIT_DEPLOY_LOGS_FILE', WP_CONTENT_DIR . '/uploads/git-deploy-logs.json' );
define( 'GIT_DEPLOY_REST_NAMESPACE', 'git-deploy/v1' );
define( 'GIT_DEPLOY_COMMIT_INFO_FILE', WP_CONTENT_DIR . '/uploads/git-deploy-commit-info.json' );

// ==============================================================================
// SETTINGS HELPERS
// ==============================================================================

/**
 * Read the settings JSON file. Returns an array with a 'sites' key.
 */
function git_deploy_get_settings() {
    if ( ! file_exists( GIT_DEPLOY_SETTINGS_FILE ) ) {
        return array( 'sites' => array(), 'settings' => array() );
    }
    $data = file_get_contents( GIT_DEPLOY_SETTINGS_FILE );
    $decoded = json_decode( $data, true );
    if ( ! is_array( $decoded ) || ! isset( $decoded['sites'] ) ) {
        return array( 'sites' => array(), 'settings' => array() );
    }
    if ( ! isset( $decoded['settings'] ) ) {
        $decoded['settings'] = array();
    }
    return $decoded;
}

/**
 * Write the full settings array to the JSON file.
 */
function git_deploy_save_settings( $settings ) {
    $dir = dirname( GIT_DEPLOY_SETTINGS_FILE );
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    file_put_contents(
        GIT_DEPLOY_SETTINGS_FILE,
        wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
    );
}

/**
 * Check whether PHP's exec() function is available for use.
 *
 * Hosting environments often disable exec() via php.ini's disable_functions.
 * When it's unavailable, we fall back to reading the .git directory directly
 * using PHP filesystem functions.
 *
 * @return bool True if exec() is available and not disabled.
 */
function git_deploy_can_exec() {
    if ( ! function_exists( 'exec' ) ) {
        return false;
    }
    $disabled = ini_get( 'disable_functions' );
    if ( false === $disabled || '' === $disabled ) {
        return true;
    }
    $functions = array_map( 'trim', explode( ',', strtolower( $disabled ) ) );
    // Also check shell_exec since it's commonly disabled alongside exec.
    return ! in_array( 'exec', $functions, true );
}

// ==============================================================================
// LOGGING HELPERS
// ==============================================================================

/**
 * Read the logs JSON file. Returns an array keyed by trigger path.
 */
function git_deploy_get_logs() {
    if ( ! file_exists( GIT_DEPLOY_LOGS_FILE ) ) {
        return array();
    }
    $data   = file_get_contents( GIT_DEPLOY_LOGS_FILE );
    $decoded = json_decode( $data, true );
    return is_array( $decoded ) ? $decoded : array();
}

/**
 * Append a log entry for a given trigger path. Keeps max 5 entries per path.
 */
function git_deploy_append_log( $trigger_path, $source, $result ) {
    $logs = git_deploy_get_logs();

    $entry = array(
        'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
        'source'    => $source,
        'result'    => $result,
    );

    if ( ! isset( $logs[ $trigger_path ] ) ) {
        $logs[ $trigger_path ] = array();
    }

    array_unshift( $logs[ $trigger_path ], $entry );

    // Keep max 5
    $logs[ $trigger_path ] = array_slice( $logs[ $trigger_path ], 0, 5 );

    $dir = dirname( GIT_DEPLOY_LOGS_FILE );
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    file_put_contents(
        GIT_DEPLOY_LOGS_FILE,
        wp_json_encode( $logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
    );
}

/**
 * Get git status info (branch, commit, message, time ago) for a repository.
 *
 * Uses exec() by default. Falls back to reading the .git directory directly
 * when exec() is disabled.
 *
 * @param string $repo_dir Absolute path to the git repository.
 * @return array{branch:string,commit:string,commit_full:string,message:string,timeago:string,timestamp:int,error:string|null}
 */
function git_deploy_get_git_info( $repo_dir ) {
    $info = array(
        'branch'      => '',
        'commit'      => '',
        'commit_full' => '',
        'message'     => '',
        'timeago'     => '',
        'timestamp'   => 0,
        'error'       => null,
    );

    if ( ! is_dir( $repo_dir ) ) {
        $info['error'] = 'Directory does not exist.';
        return $info;
    }

    // Check whether the repo dir contains a .git directory (file for submodules, or directory)
    $git_dir = rtrim( $repo_dir, '/' ) . '/.git';
    if ( ! file_exists( $git_dir ) ) {
        $info['error'] = 'Not a git repository.';
        return $info;
    }

    if ( git_deploy_can_exec() ) {
        return git_deploy_get_git_info_exec( $repo_dir, $info );
    }

    return git_deploy_get_git_info_fs( $repo_dir, $git_dir, $info );
}

/**
 * Get git info using exec().
 *
 * @param string $repo_dir Absolute path to the git repository.
 * @param array  $info     Default info array.
 * @return array
 */
function git_deploy_get_git_info_exec( $repo_dir, $info ) {
    $escaped = escapeshellarg( $repo_dir );

    // Check if it's a git repository
    exec( "git -C {$escaped} rev-parse --is-inside-work-tree 2>/dev/null", $output, $exit_code );
    if ( 0 !== $exit_code ) {
        $info['error'] = 'Not a git repository.';
        return $info;
    }

    // Branch name
    $branch = trim( exec( "git -C {$escaped} rev-parse --abbrev-ref HEAD 2>/dev/null" ) );
    $info['branch'] = $branch;

    // Short commit hash
    $commit = trim( exec( "git -C {$escaped} rev-parse --short HEAD 2>/dev/null" ) );
    $info['commit'] = $commit;

    // Full commit hash
    $commit_full = trim( exec( "git -C {$escaped} rev-parse HEAD 2>/dev/null" ) );
    $info['commit_full'] = $commit_full;

    // Commit message (first line)
    $message = trim( exec( "git -C {$escaped} log -1 --format=%s 2>/dev/null" ) );
    $info['message'] = $message;

    // Commit timestamp (unix)
    $timestamp = trim( exec( "git -C {$escaped} log -1 --format=%ct 2>/dev/null" ) );
    if ( is_numeric( $timestamp ) ) {
        $info['timestamp'] = (int) $timestamp;
        $info['timeago'] = git_deploy_human_time_ago( (int) $timestamp );
    }

    return $info;
}

/**
 * Get git info by reading the .git directory directly via PHP filesystem functions.
 *
 * Used when exec() is disabled. Provides branch and commit hash. Commit message
 * is a best-effort from COMMIT_EDITMSG; timestamp is unavailable without git.
 *
 * @param string $repo_dir Absolute path to the git repository.
 * @param string $git_dir  Absolute path to the .git directory (or file for submodules).
 * @param array  $info     Default info array.
 * @return array
 */
function git_deploy_get_git_info_fs( $repo_dir, $git_dir, $info ) {
    // For submodules or worktrees, .git is a file pointing to the actual git dir.
    if ( is_file( $git_dir ) ) {
        $contents = @file_get_contents( $git_dir );
        if ( preg_match( '/^gitdir:\s*(.+)$/m', $contents, $m ) ) {
            $git_dir = rtrim( trim( $m[1] ), '/' );
            // Resolve relative paths
            if ( '/' !== $git_dir[0] ) {
                $git_dir = rtrim( $repo_dir, '/' ) . '/' . $git_dir;
            }
        } else {
            $info['error'] = 'Not a git repository.';
            return $info;
        }
    }

    // Read HEAD to get the current branch or detached commit
    $head_file = $git_dir . '/HEAD';
    if ( ! is_file( $head_file ) ) {
        $info['error'] = 'Not a git repository.';
        return $info;
    }

    $head = @file_get_contents( $head_file );
    if ( false === $head || '' === trim( $head ) ) {
        $info['error'] = 'Could not read HEAD.';
        return $info;
    }

    $head = trim( $head );

    // Detect detached HEAD: a full commit hash instead of "ref: refs/heads/..."
    if ( preg_match( '/^ref:\s+refs\/heads\/(.+)$/', $head, $m ) ) {
        $info['branch'] = trim( $m[1] );
    } elseif ( preg_match( '/^[0-9a-f]{40}$/', $head ) ) {
        // Detached HEAD — use short hash as branch indicator
        $info['branch'] = 'HEAD (detached)';
        $info['commit'] = substr( $head, 0, 7 );
        $info['commit_full'] = $head;

        // Try reading a ref from the reflog or packed-refs as best-effort
        // Fall through to read the ref below which will fail gracefully.
    } else {
        $info['error'] = 'Unrecognized HEAD format.';
        return $info;
    }

    // Read commit hash from the branch ref
    if ( empty( $info['commit_full'] ) && ! empty( $info['branch'] ) && 'HEAD (detached)' !== $info['branch'] ) {
        $ref_file = $git_dir . '/refs/heads/' . $info['branch'];
        if ( is_file( $ref_file ) ) {
            $ref = trim( @file_get_contents( $ref_file ) );
            if ( ! empty( $ref ) && preg_match( '/^[0-9a-f]{40}$/', $ref ) ) {
                $info['commit_full'] = $ref;
                $info['commit']      = substr( $ref, 0, 7 );
            }
        }
    }

    // Best-effort: read the last saved commit message from COMMIT_EDITMSG
    $msg_file = $git_dir . '/COMMIT_EDITMSG';
    if ( empty( $info['message'] ) && is_file( $msg_file ) ) {
        $msg = trim( @file_get_contents( $msg_file ) );
        if ( ! empty( $msg ) ) {
            // Take only the first line
            $lines = explode( "\n", $msg );
            $info['message'] = trim( $lines[0] );
        }
    }

    // Timestamp is intentionally left as 0 — deriving it requires parsing
    // the compressed git object, which is too fragile without exec().

    return $info;
}

/**
 * Convert a unix timestamp to a human-readable relative time string.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function git_deploy_human_time_ago( $timestamp ) {
    $diff = time() - $timestamp;

    if ( $diff < 60 ) {
        return 'just now';
    }
    if ( $diff < 3600 ) {
        $mins = floor( $diff / 60 );
        return $mins . ' min ago';
    }
    if ( $diff < 86400 ) {
        $hours = floor( $diff / 3600 );
        return $hours . ' hour' . ( $hours > 1 ? 's' : '' ) . ' ago';
    }
    if ( $diff < 604800 ) {
        $days = floor( $diff / 86400 );
        return $days . ' day' . ( $days > 1 ? 's' : '' ) . ' ago';
    }
    if ( $diff < 2592000 ) {
        $weeks = floor( $diff / 604800 );
        return $weeks . ' week' . ( $weeks > 1 ? 's' : '' ) . ' ago';
    }

    $months = floor( $diff / 2592000 );
    return $months . ' month' . ( $months > 1 ? 's' : '' ) . ' ago';
}

/**
 * Read the commit info JSON file written by the watcher after each deploy.
 *
 * @return array|null
 */
function git_deploy_get_commit_info() {
    // 1. Try reading the JSON file written by the watcher after each deploy.
    if ( file_exists( GIT_DEPLOY_COMMIT_INFO_FILE ) ) {
        $data = file_get_contents( GIT_DEPLOY_COMMIT_INFO_FILE );
        $decoded = json_decode( $data, true );
        if ( is_array( $decoded ) && ! empty( $decoded['commit'] ) ) {
            return $decoded;
        }
    }

    // 2. Fallback: detect git info directly from configured site directories.
    //    This allows the banner to work even when the watcher hasn't written
    //    the commit info file yet (e.g., first deploy hasn't happened, or
    //    write_commit_info is not enabled for the site).
    $settings = git_deploy_get_settings();
    foreach ( $settings['sites'] as $site ) {
        $repo_dir = dirname( $site['trigger_path'] );
        if ( ! is_dir( $repo_dir ) ) {
            continue;
        }
        $git_info = git_deploy_get_git_info( $repo_dir );
        if ( ! $git_info['error'] && ! empty( $git_info['commit'] ) ) {
            return array(
                'branch'      => $git_info['branch'],
                'commit'      => $git_info['commit'],
                'commit_full' => $git_info['commit_full'] ?? $git_info['commit'],
                'message'     => $git_info['message'],
                'timestamp'   => $git_info['timestamp'],
                'deployed_at' => null,
            );
        }
    }

    return null;
}

// ==============================================================================
// TRIGGER HELPER
// ==============================================================================

/**
 * Write a timestamp to the trigger file and log the attempt.
 * Returns true on success, false on failure.
 */
function git_deploy_write_trigger( $trigger_path, $source ) {
    $result = 'success';
    if ( file_put_contents( $trigger_path, gmdate( 'Y-m-d\TH:i:s\Z' ) . "\n" ) === false ) {
        $result = 'write_error';
    }
    git_deploy_append_log( $trigger_path, $source, $result );
    return 'success' === $result;
}

/**
 * Generate a cryptographically secure random secret.
 */
function git_deploy_generate_secret() {
    return bin2hex( random_bytes( 16 ) );
}

/**
 * Check if the LiteSpeed Cache plugin is active.
 *
 * @return bool
 */
function git_deploy_is_litespeed_active() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return is_plugin_active( 'litespeed-cache/litespeed-cache.php' );
}

/**
 * Clear caches based on plugin settings after a successful trigger.
 *
 * @return array List of status messages.
 */
function git_deploy_clear_caches() {
    $settings = git_deploy_get_settings();
    $opts     = isset( $settings['settings'] ) ? $settings['settings'] : array();
    $messages = array();

    // Clear standard WordPress cache.
    if ( ! empty( $opts['clear_cache'] ) ) {
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
            $messages[] = 'WordPress cache flushed.';
        } elseif ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
            $messages[] = 'W3 Total Cache flushed.';
        } elseif ( git_deploy_can_exec() ) {
            exec( 'wp cache flush 2>/dev/null', $output, $rc );
            $messages[] = 0 === $rc
                ? 'WordPress cache flushed (via WP-CLI).'
                : 'WordPress cache flush skipped (no compatible cache detected).';
        } else {
            $messages[] = 'WordPress cache flush skipped (exec() unavailable).';
        }
    }

    // Clear LiteSpeed cache.
    if ( ! empty( $opts['clear_litespeed_cache'] ) && git_deploy_is_litespeed_active() ) {
        if ( defined( 'LSCWP_V' ) && class_exists( '\LiteSpeed\Purge' ) ) {
            \LiteSpeed\Purge::purge_all();
            $messages[] = 'LiteSpeed cache purged.';
        } elseif ( git_deploy_can_exec() ) {
            exec( 'wp litespeed-purge all 2>/dev/null', $output, $rc );
            if ( 0 === $rc ) {
                $messages[] = 'LiteSpeed cache purged (via WP-CLI).';
            }
        } else {
            $messages[] = 'LiteSpeed cache purge via WP-CLI skipped (exec() unavailable).';
        }
    }

    return $messages;
}

// ==============================================================================
// WEBHOOK — REST API ENDPOINT
// ==============================================================================

add_action( 'rest_api_init', function () {
    register_rest_route( GIT_DEPLOY_REST_NAMESPACE, '/trigger', array(
        'methods'             => array( 'GET', 'POST' ),
        'callback'            => 'git_deploy_rest_trigger',
        'permission_callback' => '__return_true', // secret validation inside callback
        'args'                => array(
            'path'   => array(
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'secret' => array(
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ) );
} );

/**
 * Handle the webhook trigger request.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function git_deploy_rest_trigger( $request ) {
    $encoded_path = $request->get_param( 'path' );
    $secret       = $request->get_param( 'secret' );

    // Decode path
    $trigger_path = base64_decode( $encoded_path, true );
    if ( false === $trigger_path || empty( $trigger_path ) ) {
        return new WP_REST_Response( array( 'status' => 'invalid_path' ), 400 );
    }

    $settings = git_deploy_get_settings();

    // Find the site by trigger path
    $matched_site = null;
    foreach ( $settings['sites'] as $site ) {
        if ( $site['trigger_path'] === $trigger_path ) {
            $matched_site = $site;
            break;
        }
    }

    if ( is_null( $matched_site ) ) {
        // Path not found in settings — log failure
        git_deploy_append_log( $trigger_path, 'webhook', 'not_found' );
        return new WP_REST_Response( array( 'status' => 'not_found' ), 404 );
    }

    // Check for GitHub ping event
    $github_event = $request->get_header( 'X-GitHub-Event' );
    if ( 'ping' === $github_event ) {
        foreach ( $settings['sites'] as &$site ) {
            if ( $site['trigger_path'] === $trigger_path ) {
                $site['last_ping'] = gmdate( 'Y-m-d\TH:i:s\Z' );
                break;
            }
        }
        unset( $site );
        git_deploy_save_settings( $settings );
        return new WP_REST_Response( array( 'status' => 'pong' ), 200 );
    }

    // Timing-safe secret comparison
    if ( ! hash_equals( $matched_site['webhook_secret'], $secret ) ) {
        git_deploy_append_log( $trigger_path, 'webhook', 'forbidden' );
        return new WP_REST_Response( array( 'status' => 'forbidden' ), 403 );
    }

    // Write the trigger file
    if ( git_deploy_write_trigger( $trigger_path, 'webhook' ) ) {
        git_deploy_clear_caches();
        return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
    }

    return new WP_REST_Response( array( 'status' => 'write_error' ), 500 );
}

// ==============================================================================
// ADMIN UI
// ==============================================================================

add_action( 'admin_menu', function () {
    add_management_page(
        'Git Deploy',
        'Git Deploy',
        'activate_plugins',
        'git-deploy',
        'git_deploy_admin_page'
    );
} );

/**
 * Render the Tools > Git Deploy admin page.
 */
function git_deploy_admin_page() {
    $settings      = git_deploy_get_settings();
    $logs          = git_deploy_get_logs();
    $sites         = $settings['sites'];
    $current_user  = wp_get_current_user();
    $uploads_dir   = WP_CONTENT_DIR . '/uploads';
    $settings_file = GIT_DEPLOY_SETTINGS_FILE;
    $logs_file     = GIT_DEPLOY_LOGS_FILE;

    // Handle form submissions
    $save_message = '';
    $save_error   = '';

    if ( isset( $_POST['git_deploy_action'] ) && check_admin_referer( 'git_deploy_save' ) ) {
        $action = sanitize_text_field( $_POST['git_deploy_action'] );

        if ( 'add_path' === $action ) {
            $label        = sanitize_text_field( $_POST['label'] ?? '' );
            $trigger_path = sanitize_text_field( $_POST['trigger_path'] ?? '' );

            if ( empty( $label ) || empty( $trigger_path ) ) {
                $save_error = 'Both label and trigger path are required.';
            } else {
                $settings['sites'][] = array(
                    'label'         => $label,
                    'trigger_path'  => $trigger_path,
                    'webhook_secret' => git_deploy_generate_secret(),
                );
                git_deploy_save_settings( $settings );
                $save_message = 'Path added successfully.';
            }
        } elseif ( 'remove_path' === $action ) {
            $remove_index = intval( $_POST['site_index'] ?? -1 );
            if ( isset( $settings['sites'][ $remove_index ] ) ) {
                array_splice( $settings['sites'], $remove_index, 1 );
                git_deploy_save_settings( $settings );
                $save_message = 'Path removed successfully.';
            }
        } elseif ( 'trigger_pull' === $action ) {
            $trigger_index = intval( $_POST['site_index'] ?? -1 );
            if ( isset( $settings['sites'][ $trigger_index ] ) ) {
                $site          = $settings['sites'][ $trigger_index ];
                $trigger_path  = $site['trigger_path'];
                $trigger_dir   = dirname( $trigger_path );

                if ( ! file_exists( $trigger_dir ) ) {
                    $save_error = 'Trigger directory does not exist: <code>' . esc_html( $trigger_dir ) . '</code>';
                } elseif ( ! is_writable( $trigger_dir ) ) {
                    $save_error = 'Trigger directory is not writable: <code>' . esc_html( $trigger_dir ) . '</code>';
                } elseif ( git_deploy_write_trigger( $trigger_path, 'manual' ) ) {
                    $save_message = '<strong>Git pull triggered.</strong> Created: <code>' . esc_html( $trigger_path ) . '</code>';
                    $cache_messages = git_deploy_clear_caches();
                    if ( ! empty( $cache_messages ) ) {
                        $save_message .= '<br><span style="color:green;">' . implode( '</span><br><span style="color:green;">', array_map( 'esc_html', $cache_messages ) ) . '</span>';
                    }
                } else {
                    $save_error = 'Failed to write trigger file: <code>' . esc_html( $trigger_path ) . '</code>';
                }
            }
        } elseif ( 'edit_path' === $action ) {
            $edit_index = intval( $_POST['site_index'] ?? -1 );
            $new_label  = sanitize_text_field( $_POST['label'] ?? '' );
            $new_path   = sanitize_text_field( $_POST['trigger_path'] ?? '' );

            if ( isset( $settings['sites'][ $edit_index ] ) && ! empty( $new_label ) && ! empty( $new_path ) ) {
                $settings['sites'][ $edit_index ]['label']        = $new_label;
                $settings['sites'][ $edit_index ]['trigger_path']  = $new_path;
                git_deploy_save_settings( $settings );
                $save_message = 'Path updated successfully.';
            } else {
                $save_error = 'Both label and trigger path are required.';
            }
        } elseif ( 'regenerate_secret' === $action ) {
            $secret_index = intval( $_POST['site_index'] ?? -1 );
            if ( isset( $settings['sites'][ $secret_index ] ) ) {
                $settings['sites'][ $secret_index ]['webhook_secret'] = git_deploy_generate_secret();
                git_deploy_save_settings( $settings );
                $save_message = 'Webhook secret regenerated.';
            }
        } elseif ( 'save_settings' === $action ) {
            $new_settings = array(
                'clear_cache'           => ! empty( $_POST['clear_cache'] ),
                'clear_litespeed_cache' => ! empty( $_POST['clear_litespeed_cache'] ),
                'show_deploy_banner'    => ! empty( $_POST['show_deploy_banner'] ),
            );
            $settings['settings'] = $new_settings;
            git_deploy_save_settings( $settings );
            $save_message = 'Post-deploy settings saved.';
        }

        // Re-read settings after changes
        $settings = git_deploy_get_settings();
        $sites    = $settings['sites'];
        $logs     = git_deploy_get_logs();
    }

    ?>
    <div class="wrap">
        <h1>Git Deploy</h1>

        <?php if ( $save_message ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo wp_kses_post( $save_message ); ?></p>
            </div>
        <?php endif; ?>
        <?php if ( $save_error ) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo wp_kses_post( $save_error ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( ! is_writable( dirname( GIT_DEPLOY_SETTINGS_FILE ) ) ) : ?>
            <div class="notice notice-warning">
                <p>Warning: The uploads directory is not writable. Settings and logs cannot be saved.</p>
            </div>
        <?php endif; ?>

        <hr>

        <?php if ( empty( $sites ) ) : ?>
            <p>No paths configured. Add your first path below.</p>
        <?php else : ?>
            <h2>Configured Paths</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Trigger Path</th>
                        <th>Webhook URL</th>
                        <th>Secret</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $sites as $i => $site ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $site['label'] ); ?></strong>
                                <?php if ( ! empty( $site['last_ping'] ) ) : ?>
                                    <span style="color:green; font-size:0.85em; margin-left:8px;">
                                        &#10003; Ping received: <?php echo esc_html( $site['last_ping'] ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html( $site['trigger_path'] ); ?></code>
                                <?php
                                $tp_dir = dirname( $site['trigger_path'] );
                                if ( ! str_ends_with( $site['trigger_path'], '.git-pull-trigger' ) ) : ?>
                                    <br><span class="warning" style="color:#a00;">Missing .git-pull-trigger suffix</span>
                                <?php elseif ( ! file_exists( $tp_dir ) ) : ?>
                                    <br><span class="warning" style="color:#a00;">Directory does not exist: <?php echo esc_html( $tp_dir ); ?></span>
                                <?php elseif ( ! is_writable( $tp_dir ) ) : ?>
                                    <br><span class="warning" style="color:#a00;">Directory is not writable by PHP</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:4px; align-items:center;">
                                    <input type="text" readonly="readonly" style="flex:1;"
                                        id="webhook-<?php echo esc_attr( $i ); ?>"
                                        value="<?php echo esc_url( rest_url( GIT_DEPLOY_REST_NAMESPACE . '/trigger' ) ); ?>?path=<?php echo esc_attr( base64_encode( $site['trigger_path'] ) ); ?>&secret=<?php echo esc_attr( $site['webhook_secret'] ); ?>"
                                        onclick="this.select()">
                                    <button type="button" class="button button-small"
                                        onclick="
                                            var i = document.getElementById('webhook-<?php echo esc_attr( $i ); ?>');
                                            i.select();
                                            navigator.clipboard.writeText(i.value);
                                            this.textContent='Copied!';
                                            setTimeout(() => { this.textContent='Copy'; }, 2000);
                                        ">Copy</button>
                                </div>
                            </td>
                            <td>
                                <code id="secret-<?php echo esc_attr( $i ); ?>">
                                    <?php echo esc_html( substr( $site['webhook_secret'], 0, 8 ) . '…' ); ?>
                                </code>
                                <button type="button" class="button button-small"
                                    onclick="
                                        var s = document.getElementById('secret-<?php echo esc_attr( $i ); ?>');
                                        s.textContent = s.textContent.includes('…')
                                            ? '<?php echo esc_js( $site['webhook_secret'] ); ?>'
                                            : '<?php echo esc_js( substr( $site['webhook_secret'], 0, 8 ) . '…' ); ?>';
                                    ">Reveal</button>
                            </td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field( 'git_deploy_save' ); ?>
                                    <input type="hidden" name="git_deploy_action" value="trigger_pull">
                                    <input type="hidden" name="site_index" value="<?php echo esc_attr( $i ); ?>">
                                    <button type="submit" class="button button-primary button-small"
                                        <?php echo ( ! file_exists( dirname( $site['trigger_path'] ) ) ) ? 'disabled' : ''; ?>
                                        onclick="this.textContent='Triggering...';"
                                    >Trigger Pull</button>
                                </form>

                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field( 'git_deploy_save' ); ?>
                                    <input type="hidden" name="git_deploy_action" value="regenerate_secret">
                                    <input type="hidden" name="site_index" value="<?php echo esc_attr( $i ); ?>">
                                    <button type="submit" class="button button-small">New Secret</button>
                                </form>

                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field( 'git_deploy_save' ); ?>
                                    <input type="hidden" name="git_deploy_action" value="remove_path">
                                    <input type="hidden" name="site_index" value="<?php echo esc_attr( $i ); ?>">
                                    <button type="submit" class="button button-small button-link-delete"
                                        onclick="return confirm('Remove this path?')">Remove</button>
                                </form>

                                <button type="button" class="button button-small"
                                    onclick="
                                        var row = document.getElementById('edit-<?php echo esc_attr( $i ); ?>');
                                        row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
                                    ">Edit</button>
                            </td>
                        </tr>

                        <!-- Edit row for this path -->
                        <tr id="edit-<?php echo esc_attr( $i ); ?>" style="display:none; background:#fff;">
                            <td colspan="5" style="padding:12px;">
                                <form method="post" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <?php wp_nonce_field( 'git_deploy_save' ); ?>
                                    <input type="hidden" name="git_deploy_action" value="edit_path">
                                    <input type="hidden" name="site_index" value="<?php echo esc_attr( $i ); ?>">
                                    <input type="text" name="label" value="<?php echo esc_attr( $site['label'] ); ?>"
                                        placeholder="Label" required style="min-width:100px;">
                                    <input type="text" name="trigger_path" value="<?php echo esc_attr( $site['trigger_path'] ); ?>"
                                        placeholder="e.g. /var/www/site1/.git-pull-trigger" required style="min-width:300px;">
                                    <button type="submit" class="button button-primary button-small">Save</button>
                                    <button type="button" class="button button-small"
                                        onclick="document.getElementById('edit-<?php echo esc_attr( $i ); ?>').style.display='none';">Cancel</button>
                                </form>
                            </td>
                        </tr>

                        <!-- Git status for this path -->
                        <?php
                        $tp_dir = dirname( $site['trigger_path'] );
                        $git_info = file_exists( $tp_dir ) ? git_deploy_get_git_info( $tp_dir ) : null;
                        ?>
                        <?php if ( isset( $git_info ) ) : ?>
                            <tr style="background:#f0f8ff;">
                                <td colspan="5" style="padding:8px 12px;">
                                    <?php if ( $git_info['error'] ) : ?>
                                        <span style="color:#856404;">⚠️ <?php echo esc_html( $git_info['error'] ); ?></span>
                                    <?php else : ?>
                                        <strong>Git Status:</strong>
                                        <span style="margin-left:12px;">🔀 Branch: <code><?php echo esc_html( $git_info['branch'] ); ?></code></span>
                                        <span style="margin-left:12px;">📝 Commit: <code><?php echo esc_html( $git_info['commit'] ); ?></code></span>
                                        <span style="margin-left:12px;">💬 <?php echo esc_html( $git_info['message'] ); ?></span>
                                        <?php if ( $git_info['timeago'] ) : ?>
                                            <span style="margin-left:12px;color:#666;">⏱ <?php echo esc_html( $git_info['timeago'] ); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <!-- Log rows for this site -->
                        <?php if ( isset( $logs[ $site['trigger_path'] ] ) && ! empty( $logs[ $site['trigger_path'] ] ) ) : ?>
                            <tr style="background:#f9f9f9;">
                                <td colspan="5" style="padding:8px 12px;">
                                    <strong>Last 5 attempts:</strong>
                                    <ul style="margin:4px 0 0 16px; list-style:disc;">
                                        <?php foreach ( $logs[ $site['trigger_path'] ] as $log_entry ) : ?>
                                            <li>
                                                <?php echo esc_html( $log_entry['timestamp'] ); ?>
                                                &mdash;
                                                <strong><?php echo esc_html( $log_entry['source'] ); ?></strong>
                                                &mdash;
                                                <span style="color:<?php echo 'success' === $log_entry['result'] ? 'green' : 'red'; ?>">
                                                    <?php echo esc_html( $log_entry['result'] ); ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <hr>

        <h2>Add New Path</h2>
        <form method="post">
            <?php wp_nonce_field( 'git_deploy_save' ); ?>
            <input type="hidden" name="git_deploy_action" value="add_path">
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="label">Label</label></th>
                    <td><input type="text" name="label" id="label" class="regular-text"
                            placeholder="e.g. My Site" required></td>
                </tr>
                <tr>
                    <th><label for="trigger_path">Trigger File Path</label></th>
                    <td>
                        <input type="text" name="trigger_path" id="trigger_path" class="large-text"
                            placeholder="e.g. /var/www/site1/.git-pull-trigger"
                            value="<?php echo esc_attr( ABSPATH . '.git-pull-trigger' ); ?>" required>
                        <p class="description">
                            Full absolute path ending in <code>.git-pull-trigger</code>.
                            Example: <code>/var/www/site1/wp-content/plugins/my-plugin/.git-pull-trigger</code><br>
                            The watcher service on the server will detect writes to this file
                            and run <code>git pull</code>.<br>
                            <strong style="color:#a00;">Important:</strong> The path must end with <code>.git-pull-trigger</code>.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Add Path', 'primary', 'submit', false ); ?>
        </form>

        <hr>

        <h2>Post-Deploy Cache Clearing</h2>
        <?php
        $cache_settings = isset( $settings['settings'] ) ? $settings['settings'] : array();
        $litespeed_active = git_deploy_is_litespeed_active();
        ?>
        <form method="post">
            <?php wp_nonce_field( 'git_deploy_save' ); ?>
            <input type="hidden" name="git_deploy_action" value="save_settings">
            <fieldset style="margin-bottom:16px;">
                <legend class="screen-reader-text">Post-deploy cache clearing options</legend>
                <label style="display:block; margin-bottom:8px;">
                    <input type="checkbox" name="clear_cache" value="1"
                        <?php checked( ! empty( $cache_settings['clear_cache'] ) ); ?>>
                    Clear WordPress cache after successful deployment
                </label>
                <label style="display:block; margin-bottom:4px;">
                    <input type="checkbox" name="clear_litespeed_cache" value="1"
                        <?php checked( ! empty( $cache_settings['clear_litespeed_cache'] ) ); ?>
                        <?php disabled( ! $litespeed_active ); ?>>
                    Clear LiteSpeed cache after successful deployment
                    <?php if ( ! $litespeed_active ) : ?>
                        <em style="color:#856404;">(Warning: LiteSpeed cache plugin is not detected. The setting will be saved but will have no effect until LiteSpeed is active.)</em>
                    <?php endif; ?>
                </label>
                <label style="display:block; margin-bottom:4px;">
                    <input type="checkbox" name="show_deploy_banner" value="1"
                        <?php checked( ! empty( $cache_settings['show_deploy_banner'] ) ); ?>>
                    Show deploy notice on admin pages
                    <?php if ( ! empty( $cache_settings['show_deploy_banner'] ) ) : ?>
                        <em style="color:#666; font-size:0.9em;">Displays git commit, message, and deploy time as an admin notice.</em>
                    <?php else : ?>
                        <em style="color:#856404; font-size:0.9em;">(When enabled, shows a notice with current git commit info on admin pages.)</em>
                    <?php endif; ?>
                </label>
            </fieldset>
            <?php submit_button( 'Save Cache Settings', 'primary', 'submit', false ); ?>
        </form>

        <hr>

        <h2>Documentation</h2>

        <div class="git-deploy-tabs">
            <nav class="git-deploy-tab-nav">
                <button class="git-deploy-tab-btn active" onclick="gitDeploySwitchTab(event, 'webhook-setup')">
                    Webhook Setup
                </button>
                <button class="git-deploy-tab-btn" onclick="gitDeploySwitchTab(event, 'github-token')">
                    GitHub Personal Access Token
                </button>
            </nav>

            <div id="git-deploy-tab-webhook-setup" class="git-deploy-tab-panel active">
                <p>Add a webhook in your Git provider pointing to the URL shown in the table above.
                Each site has its own unique webhook URL that includes the trigger path and a secret.
                The plugin validates the secret using a timing-safe comparison.</p>

                <h3>GitHub</h3>
                <ol>
                    <li>Go to your repository: <strong>Settings → Webhooks → Add webhook</strong></li>
                    <li><strong>Payload URL:</strong> Paste the full webhook URL from the table above</li>
                    <li><strong>Content type:</strong> <code>application/json</code></li>
                    <li><strong>Secrets:</strong> Paste the webhook secret shown in the table above</li>
                    <li><strong>Events:</strong> Choose <code>Just the push event</code> or customize</li>
                    <li>Click <strong>Add webhook</strong></li>
                </ol>

                <h3>GitLab</h3>
                <ol>
                    <li>Go to your project: <strong>Settings → Webhooks</strong></li>
                    <li><strong>URL:</strong> Paste the full webhook URL from the table above</li>
                    <li><strong>Secret token:</strong> Paste the webhook secret shown in the table above</li>
                    <li><strong>Trigger:</strong> Check <code>Push events</code></li>
                    <li>Click <strong>Add webhook</strong></li>
                </ol>
            </div>

            <div id="git-deploy-tab-github-token" class="git-deploy-tab-panel">
                <p>A GitHub Personal Access Token (PAT) allows the plugin to authenticate with the
                GitHub API. This is useful for accessing private repositories or creating deployment
                statuses when deploying via webhooks.</p>

                <h3>Creating a Token</h3>
                <ol>
                    <li>Go to <strong>GitHub.com → Settings → Developer settings → Personal access tokens → Fine-grained tokens</strong></li>
                    <li>Click <strong>Generate new token</strong></li>
                    <li>Set a <strong>Token name</strong> (e.g. &ldquo;Git Deploy on My Server&rdquo;)</li>
                    <li>Set an <strong>Expiration</strong> &mdash; choose <code>No expiration</code> for long-lived deployments, or a shorter window for security</li>
                    <li>Under <strong>Repository access</strong>, choose <code>Only select repositories</code> and pick the repos to deploy, or <code>All repositories</code></li>
                    <li>Under <strong>Permissions</strong>, grant the following:
                        <ul style="margin-top:4px;">
                            <li><strong>Contents:</strong> <code>Read-only</code> (required to clone/fetch the repository)</li>
                            <li><strong>Metadata:</strong> <code>Read-only</code> (auto-selected, required)</li>
                            <li><strong>Webhooks:</strong> <code>Read &amp; write</code> (optional &mdash; if you want the plugin to manage webhooks automatically)</li>
                        </ul>
                    </li>
                    <li>Click <strong>Generate token</strong></li>
                </ol>

                <h3>Using the Token</h3>
                <p>Once created, the token can be used in the HTTPS remote URL format:</p>
                <pre style="background:#f0f0f1; padding:8px 12px; border-radius:4px; overflow-x:auto;">
<code>git remote set-url origin https://<strong style="color:#c00;">TOKEN</strong>@github.com/owner/repo.git</code>
                </pre>
                <p>Replace <code>TOKEN</code> with your generated token. The deploy user on your server
                should have this remote configured so that <code>git pull</code> can authenticate as
                that token.</p>

                <h3>Security Notes</h3>
                <ul>
                    <li>Treat your PAT like a password &mdash; never share it or commit it to your repository</li>
                    <li>Use the minimum required permissions (start with <code>Contents: Read-only</code> and add more only if needed)</li>
                    <li>Set an expiration date when possible and rotate tokens regularly</li>
                    <li>If a token is compromised, revoke it immediately from GitHub settings and generate a new one</li>
                    <li>For production servers, consider using a <strong>deploy key</strong> (SSH) instead of a PAT when possible</li>
                </ul>
            </div>
        </div>

        <style>
        .git-deploy-tabs {
            margin-top: 8px;
        }
        .git-deploy-tab-nav {
            display: flex;
            gap: 4px;
            margin-bottom: 0;
            border-bottom: 1px solid #c3c4c7;
            padding-left: 4px;
        }
        .git-deploy-tab-btn {
            background: #f0f0f1;
            border: 1px solid #c3c4c7;
            border-bottom: none;
            padding: 8px 16px;
            cursor: pointer;
            font-size: 13px;
            color: #50575e;
            border-radius: 4px 4px 0 0;
            margin-bottom: -1px;
            transition: background 0.15s;
        }
        .git-deploy-tab-btn:hover {
            background: #e0e0e0;
            color: #1d2327;
        }
        .git-deploy-tab-btn.active {
            background: #fff;
            color: #1d2327;
            border-bottom-color: #fff;
        }
        .git-deploy-tab-panel {
            display: none;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-top: none;
            padding: 16px 20px;
        }
        .git-deploy-tab-panel.active {
            display: block;
        }
        .git-deploy-tab-panel h3 {
            margin-top: 16px;
        }
        .git-deploy-tab-panel h3:first-child {
            margin-top: 0;
        }
        .git-deploy-tab-panel ol li,
        .git-deploy-tab-panel ul li {
            margin-bottom: 6px;
        }
        .git-deploy-tab-panel code {
            background: #f0f0f1;
            padding: 1px 5px;
            border-radius: 3px;
        }
        .git-deploy-tab-panel pre code {
            background: none;
            padding: 0;
        }
        </style>

        <script>
        function gitDeploySwitchTab(event, tabId) {
            // Prevent default button behavior
            event.preventDefault();

            // Get the parent tabs container
            var container = event.target.closest('.git-deploy-tabs');
            if (!container) return;

            // Deactivate all tab buttons
            var buttons = container.querySelectorAll('.git-deploy-tab-btn');
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].classList.remove('active');
            }

            // Activate clicked button
            event.target.classList.add('active');

            // Hide all panels inside this container
            var panels = container.querySelectorAll('.git-deploy-tab-panel');
            for (var j = 0; j < panels.length; j++) {
                panels[j].classList.remove('active');
            }

            // Show the selected panel
            var targetPanel = document.getElementById('git-deploy-tab-' + tabId);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
        }
        </script>

        <?php if ( WP_DEBUG ) : ?>
            <hr>
            <h3>Debug Information</h3>
            <p><strong>Settings file:</strong> <code><?php echo esc_html( $settings_file ); ?></code>
                &mdash; <?php echo file_exists( $settings_file ) ? 'exists' : 'not yet created'; ?></p>
            <p><strong>Logs file:</strong> <code><?php echo esc_html( $logs_file ); ?></code>
                &mdash; <?php echo file_exists( $logs_file ) ? 'exists' : 'not yet created'; ?></p>
            <p><strong>Uploads writable:</strong> <?php echo is_writable( $uploads_dir ) ? 'yes' : 'no'; ?></p>
        <?php endif; ?>

        <script>
        // Scroll to show notices after form submission
        (function() {
            var notice = document.querySelector('.notice');
            if (notice) {
                window.scrollTo(0, notice.offsetTop - 40);
            }
        })();
        </script>
    </div>
    <?php
}

// ==============================================================================
// ACTIVATION — create empty settings file on first load
// ==============================================================================

add_action( 'init', function () {
    if ( ! file_exists( GIT_DEPLOY_SETTINGS_FILE ) ) {
        $dir = dirname( GIT_DEPLOY_SETTINGS_FILE );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        git_deploy_save_settings( array( 'sites' => array() ) );
    }
} );

// ==============================================================================
// DEPLOY BANNER
// ==============================================================================

/**
 * Render the deploy info banner in the admin area.
 *
 * Only appears on backend admin pages. Skipped in the Customizer
 * (Appearance → Customize) to keep the preview area clean.
 */
function git_deploy_render_admin_banner() {
    // Do not show in the Customizer (controls or preview).
    if ( is_customize_preview() ) {
        return;
    }

    $settings = git_deploy_get_settings();
    $opts     = isset( $settings['settings'] ) ? $settings['settings'] : array();

    if ( empty( $opts['show_deploy_banner'] ) ) {
        return;
    }

    $info = git_deploy_get_commit_info();
    if ( ! $info ) {
        return;
    }

    $timeago = ! empty( $info['timestamp'] ) ? git_deploy_human_time_ago( (int) $info['timestamp'] ) : '';
    ?>
    <div class="notice notice-info git-deploy-admin-banner" style="border-left-color:#1d2327; padding:8px 12px; margin-top:10px;">
        <span style="font-size:13px;">
            <strong>⚡ Deploy:</strong>
            <?php echo esc_html( $info['branch'] ?? '' ); ?>
            @ <code><?php echo esc_html( $info['commit'] ?? '' ); ?></code>
            &mdash; &ldquo;<?php echo esc_html( $info['message'] ?? '' ); ?>&rdquo;
            <?php if ( $timeago ) : ?>
                &mdash; <span style="color:#666;"><?php echo esc_html( $timeago ); ?></span>
            <?php endif; ?>
        </span>
    </div>
    <?php
}

// Hook the banner into admin notices only (not on frontend or Customizer).
add_action( 'admin_notices', 'git_deploy_render_admin_banner' );

