<?php
/*
Plugin Name: WP Emoji DB Update
Plugin URI: https://github.com/reaktivstudios/wp-emoji-db-update
Description: Check and update the database for emoji support
Version: 0.0.1
Author: Andrew Norcross
Author URI: http://andrewnorcross.com

	Copyright 2015 Andrew Norcross

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Start up the engine
class WP_Emoji_DB_Update
{

	/**
	 * fire it up
	 *
	 */
	public function init() {

		// bail on non admin
		if ( ! is_admin() ) {
			return;
		}

		// call the menu page
		add_action( 'admin_menu',                   array( $this, 'admin_pages'         )           );
		add_action( 'admin_init',                   array( $this, 'run_update'          )           );
	}

	/**
	 * load the settings submenu item under the tools menu
	 *
	 * @return [type] [description]
	 */
	public function admin_pages() {
		add_management_page( __( 'WP Emoji Database Check', 'wp-emoji-db-update' ), __( 'DB Emoji Check', 'wp-emoji-db-update' ), 'manage_options', 'db-emoji-check', array( __class__, 'settings_page' ) );
	}

	/**
	 * build the settings page
	 *
	 * @return [type] [description]
	 */
	public static function settings_page() {

		// bail without caps
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to view this page.', 'wp-emoji-db-update' ) );
		}

		// do our version check first
		if ( false === $wpvers = self::check_wp_version() ) {

			// render the page
			echo '<div class="wrap">';
			echo '<h2>' . esc_html( get_admin_page_title() ) . '</h2>';
			echo '<p>' . __( 'You must be running version 4.2.2 or greater. Please update your WordPress installation', 'wp-emoji-db-update' ) . '</p>';
			echo '</div>';

			// and bail
			return;
		}

		// check my DB version
		$check  = self::check_db_version();

		// check if we've already run it
		$hasrun = get_option( 'wp_emoji_db_run' );

		// begin the wrap
		echo '<div class="wrap">';

			// title it
			echo '<h2>' . esc_html( get_admin_page_title() ) . '</h2>';

			// hasn't run yet
			if ( empty( $hasrun ) ) {
				echo self::get_update_page( $check );
			}

			// it's run, and it worked
			if ( ! empty( $hasrun ) && ! empty( $check['done'] ) ) {
				echo '<p>' . __( 'Success! Your site has been updated to support emoji.', 'wp-emoji-db-update' ) . '&nbsp;<span style="font-size:120%;">&#128077;</span></p>';
			}

			// it's run, and it didnt work
			if ( ! empty( $hasrun ) && ! empty( $check['fail'] ) ) {
				echo '<p>' . __( 'The site was updated, but there was an error. Please contact your host.', 'wp-emoji-db-update' ) . '&nbsp;<span style="font-size:120%;">&#128077;</span></p>';
			}

		// close the wrap
		echo '</div>';
	}

	/**
	 * render the update form with possible failure messages
	 *
	 * @return mixed/HTML     the page
	 */
	public static function get_update_page( $check ) {

		// set my empty
		$page   = '';

		// if we are already done, say so
		if ( ! empty( $check['done'] ) ) {
			$page  .= '<p>' . __( 'Your site has already been updated to support emoji.', 'wp-emoji-db-update' ) . '&nbsp;<span style="font-size:120%;">&#128077;</span></p>';
		}

		// we have a failure somewhere. display the reason why
		if ( ! empty( $check['fail'] ) ) {
			$page  .= self::get_failure_text( $check['fail'], $check['name'], $check['vers'], $check['min'] );
		}

		// checks passed, but hasn't been run.
		if ( empty( $check['done'] ) && ! empty( $check['check'] ) ) {
			$page  .= self::get_upgrade_text();
		}

		// send it back
		return $page;
	}

	/**
	 * check the DB version and return it
	 *
	 * @return array          whether or not it passed, and reasons for it
	 */
	public static function check_db_version() {

		// call the global
		global $wpdb;

		// if we are already using it, just return
		if ( ! empty( $wpdb->charset ) && ! empty( $wpdb->collate ) && $wpdb->charset == 'utf8mb4' && $wpdb->collate == 'utf8mb4_unicode_ci' ) {

			// return an array that we're in the clear
			return array(
				'check' => true,
				'done'  => true,
			);
		}

		// fetch the info
		$info   = $wpdb->use_mysqli ? mysqli_get_server_info( $wpdb->dbh ) : mysql_get_server_info( $wpdb->dbh );

		// if we have no server info, bail
		if ( empty( $info ) ) {
			return array(
				'check' => false,
				'done'  => false,
				'fail'  => 'server',
				'name'  => __( 'unknown', 'wp-emoji-db-update' ),
				'min'   => false,
				'vers'  => false,
			);
		}

		// determine my DB name
		$name   = $wpdb->use_mysqli ? 'MySQL' : 'MySQL';

		// figure out the version
		$vers   = preg_replace( '/[^0-9.].*/', '', $info );

		// bail if our version is backwards
		if ( version_compare( $vers, '5.5.3', '<' ) ) {
			return array(
				'check' => false,
				'done'  => false,
				'fail'  => 'sql',
				'name'  => $name,
				'vers'  => $vers
			);
		}

		// get the client version
		$client = $wpdb->use_mysqli ? mysqli_get_client_info() : mysql_get_client_info();

		// if we have no client info, bail
		if ( empty( $client ) ) {
			return array(
				'check' => false,
				'done'  => false,
				'fail'  => 'client',
				'name'  => __( 'unknown', 'wp-emoji-db-update' ),
				'min'   => false,
				'vers'  => false,
			);
		}

		/*
		 * libmysql has supported utf8mb4 since 5.5.3, same as the MySQL server.
		 * mysqlnd has supported utf8mb4 since 5.0.9.
		 */
		if ( false !== strpos( $client, 'mysqlnd' ) ) {

			// figure out the client version
			$client = preg_replace( '/^\D+([\d.]+).*/', '$1', $client );

			// mysqlnd has supported utf8mb4 since 5.0.9.
			$check  = version_compare( $client, '5.0.9', '>=' ) >= 0 ? true : false;

			// the fail check
			$fail   = ! empty( $check ) ? null : 'library';

			// return the result
			return array(
				'check' => $check,
				'done'  => false,
				'name'  => 'MySQLnd',
				'fail'  => $fail,
				'min'   => '5.0.9',
				'vers'  => $client
			);
		} else {
			// libmysql has supported utf8mb4 since 5.5.3, same as the MySQL server.
			$check  = version_compare( $client, '5.5.3', '>=' ) >= 0 ? true : false;

			// the fail check
			$fail   = ! empty( $check ) ? '' : 'library';

			// return the result
			return array(
				'check' => $check,
				'done'  => false,
				'name'  => 'libmysql',
				'fail'  => $fail,
				'min'   => '5.5.3',
				'vers'  => $client
			);
		}
	}

	/**
	 * build out the failure message based on the issue
	 *
	 * @param  string $fail   the reason for failure
	 * @param  string $name   the name of what failed
	 * @param  string $vers   the version running
	 * @param  string $min    the minumum version
	 *
	 * @return mixed/HTML     the message
	 */
	public static function get_failure_text( $fail = '', $name = '', $vers = '', $min = '' ) {

		// do our case switch checks
		switch ( $fail ) {

			case 'server' :
				$text   = __( 'Your SQL server could not be reached.', 'wp-emoji-db-update' );
				break;

			case 'client' :
				$text   = __( 'Your SQL connection library type could not be determined.', 'wp-emoji-db-update' );
				break;

			case 'sql' :
				$text   = sprintf( __( 'Your version of %s is out of date. You must be running at least %s, and you are currently running %s.', 'wp-emoji-db-update' ), esc_attr( $name ), esc_attr( $vers ), esc_attr( $min ) );
				break;

			case 'library' :
				$text   = sprintf( __( 'Your version of the %s library is out of date. You must be running at least %s, and you are currently running %s', 'wp-emoji-db-update' ), esc_attr( $name ), esc_attr( $vers ), esc_attr( $min )  );
				break;

			default :
				$text   = __( 'The issue could not be determined.', 'wp-emoji-db-update' );
		}

		// send it back
		return '<p>' . esc_attr( $text ) . '</p>';
	}

	/**
	 * render the upgrade notice and button
	 *
	 * @return mixed/HTML     the text and button
	 */
	public static function get_upgrade_text() {

		// my upgrade label
		$label  = __( 'Upgrade Database.', 'wp-emoji-db-update' );

		// the empty
		$form   = '';

		// wrap the form
		$form  .= '<form method="post">';
		$form  .= '<p>' . __( 'Your site is capable of using emoji, but requires an update.', 'wp-emoji-db-update' ) . '</p>';

		// get our button
		$form  .= get_submit_button( $label );

		// our nonce
		$form  .= wp_nonce_field( 'wp_emoji_db_nonce', 'wp_emoji_db', false, false );

		// close the form
		$form  .= '</form>';

		// and return it
		return $form;
	}

	/**
	 * run the actual update by switching the DB version back a few
	 * then redirecting back to admin
	 *
	 * @return void
	 */
	public function run_update() {

		// not on our page. bail.
		if ( empty( $_GET['page'] ) || ! empty( $_GET['page'] ) && $_GET['page'] != 'db-emoji-check' ) {
			return;
		}

		// wrong version. bail.
		if ( false === $wpvers = self::check_wp_version() ) {
			return;
		}

		// verify our nonce
		if ( ! isset( $_POST['wp_emoji_db'] ) || ! wp_verify_nonce( $_POST['wp_emoji_db'], 'wp_emoji_db_nonce' ) ) {
			return;
		}

		// update our DB version
		update_option( 'db_version', 30135 );

		// set our option that we've run it
		update_option( 'wp_emoji_db_run', true, 'no' );

		// get our link
		$link   = menu_page_url( 'db-emoji-check', 0 );

		// and redirect
		wp_safe_redirect( esc_url( $link ), 302 );
		die();
	}

	/**
	 * check the current version of WP
	 *
	 * @return bool           whether or not we are at that version (or beyond)
	 */
	public static function check_wp_version() {

		// call the global
		global $wp_version;

		// return the true / false
		return version_compare( $wp_version, '4.2', '>=' ) ? true : false;
	}

/// end class
}

// Instantiate our class
$WP_Emoji_DB_Update = new WP_Emoji_DB_Update();
$WP_Emoji_DB_Update->init();
