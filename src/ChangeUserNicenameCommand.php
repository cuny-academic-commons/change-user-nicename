<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Changes a user's nicename.
 *
 * ## OPTIONS
 *
 * <old>
 * : The old nicename.
 *
 * <new>
 * : The new nicename.
 *
 * ## EXAMPLES
 *
 *     wp change-user-nicename old_nicename new_nicename
 *
 * @when after_wp_load
 */
WP_CLI::add_command( 'change-user-nicename', function( $args ) {
	$from_username = $args[0];
	$to_username   = $args[1];

	global $wpdb;

	// Sanity checks
	$from = sanitize_user( $from_username );
	$to   = sanitize_user( $to_username );

	if ( $from === $to ) {
		WP_CLI::error( 'Old and new usernames are the same.' );
	}

	// Find existing user.
	$user = get_user_by( 'slug', $from );
	if ( ! $user ) {
		WP_CLI::error( sprintf( 'Could not find a user with user_nicename %s', $from ) );
	}

	// Update user_login
	$q = $wpdb->prepare( "UPDATE $wpdb->users SET user_nicename = %s WHERE user_nicename = %s", $to, $from );
	if ( false === $wpdb->query( $q ) ) {
		WP_CLI::error( 'Could not update user_nicename.' );
	}

	WP_CLI::log( sprintf( 'Changed user_nicename from %s to %s.', $from, $to ) );

	if ( ! function_exists( 'buddypress' ) ) {
		WP_CLI::log( 'BuddyPress not active. Skipping BuddyPress tables.' );
		return;
	}

	WP_CLI::log( 'Updating BP profile lines in core tables.' );

	$replace_tables = [
		$wpdb->posts,
		$wpdb->comments,
		buddypress()->activity->table_name,
	];

	// URL swap.
	$members_base = bp_get_root_domain() . '/' . bp_get_members_root_slug() . '/';
	$from_url     = $members_base . $from_username . '/';
	$to_url       = $members_base . $to_username . '/';

	$url_sr_command = "search-replace --all-tables $from_url $to_url " . implode( ' ', $replace_tables );
	WP_CLI::runcommand(
		$url_sr_command,
		[ 'exit_error' => true ]
	);

	WP_CLI::log( 'Updated URLs in core tables.' );

	WP_CLI::log( 'Updating user mentions (e.g. @username) across all tables.' );

	$tables_by_field = [
		'post_content'    => [],
		'comment_content' => [],
		'content'         => [ buddypress()->activity->table_name ],
		'message'         => [ buddypress()->messages->table_name_messages ],
	];

	// All posts and comments for all sites on the network.
	$table_count = count( $tables_by_field );
	if ( is_multisite() ) {
		$site_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
		foreach ( $site_ids as $site_id ) {
			$prefix = $wpdb->get_blog_prefix( $site_id );

			$tables_by_field['post_content'][]    = $prefix . 'posts';
			$tables_by_field['comment_content'][] = $prefix . 'comments';

			$table_count += 2;
		}
	}

	// No need for wp search-replace because these are all text fields.
	// We handle them in separate mysql calls to allow for a progress bar.
	$progress = \WP_CLI\Utils\make_progress_bar( 'Updating user mentions', $table_count );
	foreach ( $tables_by_field as $field => $tables ) {
		foreach ( $tables as $table ) {
			// Mentions
			$command = "
				UPDATE $table
				SET $field = REGEXP_REPLACE(
					$field,
					'(^|[^a-zA-Z0-9_])(@$from_username)([^a-zA-Z0-9_]|$)',
					'\\\\1@$to_username\\\\3'
				)
				WHERE $field REGEXP '(^|[^a-zA-Z0-9_])@$from_username([^a-zA-Z0-9_]|$)';
			";

			$wpdb->query( $command );

			// URLs
			$untrailingslashed_from_url = untrailingslashit( $from_url );
			$untrailingslashed_to_url   = untrailingslashit( $to_url );

			$url_command = "
				UPDATE $table
				SET $field = REPLACE( $field, '$untrailingslashed_from_url', '$untrailingslashed_to_url' )
				WHERE $field LIKE '%$untrailingslashed_from_url%';
			";

			$wpdb->query( $url_command );

			$progress->tick();
		}
	}

	$progress->finish();

	WP_CLI::log( 'Updated user mentions in core tables.' );

	WP_CLI::success( sprintf( 'User nicename update from %s to %s. Don\'t forget to clear caches.', $from_username, $to_username ) );
} );
