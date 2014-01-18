<?php
/*
Plugin Name: Auto Reset

Plugin URI; http://trepmal.com/plugins/auto-reset
Description; Untested on Multisite!!! Automatically reset this site at regular intervals (default: 1 hour).
Author; Kailey Lampert
Author URI; http://kaileylampert.com

What this will do:
 - on given interval (default hourly) the site will be reset
 - during reset, all files in upload directory will be deleted
 - db tables will be dropped! ** yes, dropped, as in "no undo" **
 - site will be reinstalled with Title, User name/password/email, public/private setting (all configurable)
 - welcome dashboard will be hidden (configurable)
 - feature pointers will be enabled (configurable)
 - schedule its own next reset

Extras include:
 - shows demo user's credentials on login screen
 - countdown timer for next reset. uses JS for live-countdown
 - disabling edit of main user. prevents test-drivers from changing the password, potentially blocking others from loggin into the demo
 - adds a notice the user-edit is disabled for demo purposes only
 - create new user each time someone logs in (optional)

Other notes:
 - next_reset option saved as array so that it can't be edited via options.php

*/

// files won't be reset, so make sure the user doesn't change them
define( 'DISALLOW_FILE_MODS', true );

$auto_reset = new Auto_Reset();
class Auto_Reset {

	/**
	 * Interval, how often to reset in seconds
	 *
	 * @var int
	 */
	var $interval = 3600;

	/**
	 * Shorcuts, enable query arg shortcuts
	 *
	 * @var bool
	 */
	var $shortcuts = true; // enable 'resetnow', 'delay', and 'onehour' URL shortcuts

	/**
	 * Blog Title
	 *
	 * @var string
	 */
	var $blog_title = 'WP Testdrive';

	/**
	 * First user's username
	 *
	 * @var string
	 */
	var $user_name = 'demo';

	/**
	 * First user's email address
	 *
	 * @var string
	 */
	var $user_email = 'nobody@example.com';

	/**
	 * Blog privacy setting
	 *
	 * @var bool
	 */
	var $public = 0;

	/**
	 * First user's password
	 *
	 * @var sting
	 */
	var $user_password = 'demo';

	/**
	 * Plugins to auto-activate
	 *
	 * @var array
	 */
	var $plugins = array();
	// e.g. var $plugins = array( 'debug-bar-console/debug-bar-console.php', 'debug-bar-extender/debug-bar-extender.php', 'debug-bar/debug-bar.php', 'log-deprecated-notices/log-deprecated-notices.php', 'theme-check/theme-check.php', 'plugin-check/plugin-check.php' );

	/**
	 * Hide Akismet and Hello Dolly in admin
	 *
	 * @var bool
	 */
	var $hide_default_plugins = true;

	/**
	 * Remove uploaded files on reset
	 *
	 * @var bool
	 */
	var $remove_uploads_dir = true;

	/**
	 * Directories to preserve (within the uploads dir)
	 *
	 * @var array
	 */
	var $preserve_dirs = array();
	// e.g. var $preserve_dirs = array( 'nodelete' );

	/**
	 * Hide the Welcome panel on dashboard
	 *
	 * @var bool
	 */
	var $hide_welcome_dashboard = true;

	/**
	 * Show the feature pointers
	 *
	 * @var bool
	 */
	var $show_feature_pointers = true;

	/**
	 * Show the countdown
	 *
	 * @var bool
	 */
	var $show_countdown = true;

	/**
	 * Show the next set of login credentials on wp-login
	 *
	 * @var bool
	 */
	var $broadcast_credentials = true;

	/**
	 * Generate a new user everytime someone logs in as the last user
	 *
	 * @var bool
	 */
	var $generate_new_users = true;

	/**
	 * Randomize new passwords for new users.
	 * If false, password is same as the username
	 *
	 * @var bool
	 */
	var $randomize_new_passwords = true;

	function __construct( ) {

		$defaults = array(
			'interval'                => $this->interval,
			'shortcuts'               => $this->shortcuts,
			'blog_title'              => $this->blog_title,
			'user_name'               => $this->user_name,
			'user_email'              => $this->user_email,
			'public'                  => $this->public,
			'user_password'           => $this->user_password,
			'plugins'                 => $this->plugins,
			'hide_default_plugins'    => $this->hide_default_plugins,
			'remove_uploads_dir'      => $this->remove_uploads_dir,
			'preserve_dirs'           => $this->preserve_dirs,
			'hide_welcome_dashboard'  => $this->hide_welcome_dashboard,
			'show_feature_pointers'   => $this->show_feature_pointers,
			'show_countdown'          => $this->show_countdown,
			'broadcast_credentials'   => $this->broadcast_credentials,
			'generate_new_users'      => $this->generate_new_users,
			'randomize_new_passwords' => $this->randomize_new_passwords,
		);

		$this->settings = apply_filters( 'auto_reset', $defaults );

		add_option( 'next_reset', array( time() + $this->settings['interval'] ) );
		$next_reset = array_shift( get_option( 'next_reset' ) );

		$resetnow = false;
		if ( $this->settings['shortcuts'] ) {

			// delay the reset by the increment value
			if ( isset( $_GET['delay'] ) )
				update_option( 'next_reset', array( $next_reset + $this->settings['interval'] ) );

			// reset in one hour
			if ( isset( $_GET['onehour'] ) )
				update_option( 'next_reset', array( time() + ( 60 * 60 ) ) );

			// reset in X hours
			if ( isset( $_GET['hours'] ) && ! empty( $_GET['hours'] ) )
				update_option( 'next_reset', array( time() + ( intval( $_GET['hours'] ) * 3600 ) ) );

			// reset in X minutes
			if ( isset( $_GET['minutes'] ) && ! empty( $_GET['minutes'] ) )
				update_option( 'next_reset', array( time() + ( intval( $_GET['minutes'] ) * 60 ) ) );

			// reset now
			$resetnow = isset( $_GET['resetnow'] );
		}

		if ( $next_reset <= time() || $resetnow )
			add_action('admin_init', array( &$this, 'the_reset' ), 1 );

		// prevent editing/deleting of main 'demo' user, ensure someone can always log in
		add_filter( 'map_meta_cap', array( &$this, 'prevent_edit_of_primary_user' ), 10, 4 );
		add_filter( 'user_row_actions', array( &$this, 'no_user_edit_note' ), 10, 2 );

		// generate a new user when someone logs in
		if ( $this->settings['generate_new_users'] )
			add_action( 'wp_login', array( &$this, 'generate_new_user' ), 10, 2 );

		if ( $this->settings['broadcast_credentials'] )
	 		add_filter( 'login_message', array( &$this, 'show_credentials' ) );

		if ( $this->settings['show_countdown'] )
	 		add_action( 'admin_bar_menu', array( &$this, 'countdown' ), 100 );

		add_filter( 'all_plugins', array( &$this, 'all_plugins' ) );
		if ( ! get_option( 'active_plugins', false ) )
			update_option( 'active_plugins', $this->settings['plugins'] );

	}

	function the_reset() {

		if ( strpos( $_SERVER['REQUEST_URI'], 'wp-login.php' ) !== false ) {
			wp_redirect( admin_url('?resetnow') );
			exit();
		}

		if ( $this->settings['remove_uploads_dir'] ) {
			$dirs = wp_upload_dir();
			$this->basedir = trailingslashit( $dirs['basedir'] );
			$this->settings['preserve_dirs'] = array_map( array( &$this, 'prepend_upload_dirs' ), $this->settings['preserve_dirs'] );
			$this->remove_all_uploads( $this->basedir );
		}

		// get and remove all tables
		global $wpdb;
		$tables = array_merge( $wpdb->tables, $wpdb->global_tables );
		foreach( $tables as $tbl )
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$tbl}" );

		// make sure wp_install() is available
		if ( ! function_exists( 'wp_install' ) )
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		// install site with defaults
		$install = wp_install( $this->settings['blog_title'], $this->settings['user_name'], $this->settings['user_email'], $this->settings['public'], '', $this->settings['user_password'] );
		if ( $this->settings['generate_new_users'] )
			update_option( 'next_user', array( 'un' => $this->settings['user_name'], 'pw' => $this->settings['user_password'] ) );

		// disable the welcome dashboard
		if ( $this->settings['hide_welcome_dashboard'] )
		update_user_meta( $install['user_id'], 'show_welcome_panel', false );

		// enable feature pointers
		if ( $this->settings['show_feature_pointers'] )
		update_user_meta( $install['user_id'], 'dismissed_wp_pointers', '' );

		// schedule next reset
		update_option( 'next_reset', array( time() + $this->settings['interval'] ) );

		/*				DO MORE!					*
			You can do other things in here, too.
			Maybe create some extra pages & posts
			or active plugins. Go wild, have fun.
		*											*/

		// send user to login screen
		wp_redirect( admin_url('')  );
		exit();
	}

	function all_plugins( $get_plugins ) {

		// hide these without activating
		if ( $this->settings['hide_default_plugins'] ) {
			$this->settings['plugins'][] = 'akismet/akismet.php';
			$this->settings['plugins'][] = 'hello.php';
		}

		foreach( $this->settings['plugins'] as $b )
			if ( isset( $get_plugins[ $b ] ) ) unset( $get_plugins[ $b ] );

		return $get_plugins;
	}

	function remove_all_uploads( $dir ) {
		$here = array_merge( glob("$dir.*"), glob("$dir*.*") ); // get files, including hidden
		$dirs = glob("$dir*", GLOB_ONLYDIR|GLOB_MARK ); // get subdirectories

		// start with subs, less confusing
		foreach( $dirs as $k => $sdir )
			$this->remove_all_uploads( $sdir );

		// loop through files and delete
		foreach( $here as $file ) {
			if ( is_file( $file ) )
				unlink( $file );
		}

		// print_r( $this->settings['preserve_dirs'] );
		// die( $dir );
		if ( $dir == $this->basedir && count( $this->settings['preserve_dirs'] ) > 0 ) {
			// don't delete uploads/ if we preserved directories
		} else if ( is_dir( $dir ) && ! in_array( $dir, $this->settings['preserve_dirs'] ) )
			rmdir( $dir );
	}

		function prepend_upload_dirs( $input ) {
			return trailingslashit( $this->basedir . $input );
		}

	function prevent_edit_of_primary_user( $caps, $cap, $user_id, $args ) {
		if ( $cap == 'edit_user' && in_array( 1, $args ) )
			return false;
		if ( $cap == 'delete_users' && in_array( 1, $args ) )
			return false;

		return $caps;
	}

	function no_user_edit_note( $actions, $user ) {
		if ( $user->ID == 1 )
			$actions[] = __( 'For the purposes of this demo, this user cannot be edited.', 'auto-reset' );
		return $actions;
	}

	function generate_new_user( $user_login, $user ) {

		$next = get_option( 'next_user' );

		// if not logging in with the newest user, don't create another
		if ( $user_login != $next['un'] )
			return;

		$i = count( get_users() );
		$un = $pw = 'demo'.$i;

		if ( $this->settings['randomize_new_passwords'] )
			$pw = wp_generate_password( 10, false, false );

		$id = wp_create_user( $un, $pw );
		$user = new WP_User( $id );
		$user->set_role( 'administrator' );
		update_option( 'next_user', array( 'un' => $un, 'pw' => $pw ) );

	}

	function show_credentials() {
		extract( get_option( 'next_user', array( 'un' => $this->settings['user_name'], 'pw' => $this->settings['user_password'] ) ) );
		return '<p class="message">' . sprintf( __( 'Username: %s', 'auto-reset' ), $un ) . '<br />' . sprintf( __( 'Password: %s', 'auto-reset' ), $pw ) . '</p>';
	}

	function countdown( $wp_admin_bar ) {
		// get next scheduled reset
		$next = array_shift( get_option( 'next_reset' ) );
		$now = time();

		// get difference between then and now
		$diff = ( $next - $now );

		// convert it to hrs:mins:secs
		$time = date_i18n( 'H:i:s', $diff );

		// add live countdown to next reset to Toolbar
		$wp_admin_bar->add_menu( array(
			'id' => 'live-countdown',
			'title' => sprintf( __( 'Resetting in: %s', 'auto-reset' ), "<time id='javascript_countdown_time'>$time</time>" ) . $this->js( $diff )
		) );

		if ( $this->settings['shortcuts'] ) {
			$wp_admin_bar->add_menu( array(
				'parent' => 'live-countdown',
				'id' => 'live-countdown-reset-now',
				'title' => __( 'Reset now', 'auto-reset' ),
				'href' => admin_url('?resetnow')
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'live-countdown',
				'id' => 'live-countdown-onehour',
				'title' => __( 'Reset in one hour', 'auto-reset' ),
				'href' => admin_url('?onehour')
			) );
		}

		// add timestamp for next reset to Toolbar
		$wp_admin_bar->add_menu( array(
			'parent' => 'live-countdown',
			'id' => 'live-countdown-timestamp',
			'title' => sprintf( __( 'Next reset: %s', 'auto-reset' ), date_i18n( 'F j, Y H:i:s T', $next ) )
		) );

		// add current time, so we don't have to figure out UTC
		$wp_admin_bar->add_menu( array(
			'parent' => 'live-countdown',
			'id' => 'live-countdown-current-time',
			'title' => sprintf( __( 'Currently: %s', 'auto-reset' ), date_i18n( 'F j, Y H:i:s T', $now ) )
		) );
	}

	// this JS will probably bother you
	function js( $diff ) {
		// http://stuntsnippets.com/javascript-countdown/
		return "<script type='text/javascript'>
var javascript_countdown = function () {
	var time_left = 10; //number of seconds for countdown
	var output_element_id = 'javascript_countdown_time';
	var keep_counting = 1;
	var no_time_left_message = '" . __( 'Now!', 'auto-reset' ) . "';

	function countdown() {
		if(time_left < 2) {
			keep_counting = 0;
		}

		time_left = time_left - 1;
	}

	function add_leading_zero(n) {
		if(n.toString().length < 2) {
			return '0' + n;
		} else {
			return n;
		}
	}

	function format_output() {
		var hours, minutes, seconds;
		seconds = time_left % 60;
		minutes = Math.floor(time_left / 60) % 60;
		hours = Math.floor(time_left / 3600);

		seconds = add_leading_zero( seconds );
		minutes = add_leading_zero( minutes );
		hours = add_leading_zero( hours );

		return hours + ':' + minutes + ':' + seconds;
	}

	function show_time_left() {
		document.getElementById(output_element_id).innerHTML = format_output();//time_left;
	}

	function no_time_left() {
		document.getElementById(output_element_id).innerHTML = no_time_left_message;
	}

	return {
		count: function () {
			countdown();
			show_time_left();
		},
		timer: function () {
			javascript_countdown.count();

			if(keep_counting) {
				setTimeout(\"javascript_countdown.timer();\", 1000);
			} else {
				no_time_left();
			}
		},
		//Kristian Messer requested recalculation of time that is left
		setTimeLeft: function (t) {
			time_left = t;
			if(keep_counting == 0) {
				javascript_countdown.timer();
			}
		},
		init: function (t, element_id) {
			time_left = t;
			output_element_id = element_id;
			javascript_countdown.timer();
		}
	};
}();

//time to countdown in seconds, and element ID
javascript_countdown.init($diff, 'javascript_countdown_time');</script>";
	}
}

//eof