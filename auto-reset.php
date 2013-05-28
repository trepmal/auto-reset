<?php
/*
Plugin Name: Auto Reset

// Periods just prevent this info from showing in the admin.
P.lugin URI: http://trepmal.com/plugins/auto-reset
D.escription: Untested on Multisite!!! Automatically reset this site at regular intervals (default: 1 hour).
A.uthor: Kailey Lampert
A.uthor URI: http://kaileylampert.com

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

new Auto_Reset();
class Auto_Reset {

	var $interval = 3600; //one hour
	var $shortcuts = true; //enable 'resetnow', 'delay', and 'onehour' URL shortcuts

	//define defaults
	var $blog_title = 'WP Testdrive';
	var $user_name = 'demo';
	var $user_email = 'nobody@example.com';
	var $public = 0;
	var $user_password = 'demo';

	//user experience
	var $hide_welcome_dashboard = true;
	var $show_feature_pointers = true;

	var $show_countdown = true;

	var $broadcast_credentials = true;

	var $generate_new_users = true;
	var $randomize_new_passwords = true;

	function __construct() {

		add_option( 'next_reset', array( time() + $this->interval ) );
		$next_reset = array_shift( get_option( 'next_reset' ) );

		$resetnow = false;
		if ( $this->shortcuts ) {

			//delay the reset by the increment value
			if ( isset( $_GET['delay'] ) )
				update_option( 'next_reset', array( $next_reset + $this->interval ) );

			//reset in one hour
			if ( isset( $_GET['onehour'] ) )
				update_option( 'next_reset', array( time() + ( 60 * 60 ) ) );

			//reset in X hours
			if ( isset( $_GET['hours'] ) )
				update_option( 'next_reset', array( time() + ( intval( $_GET['hours'] ) * 3600 ) ) );

			//reset in X minutes
			if ( isset( $_GET['minutes'] ) )
				update_option( 'next_reset', array( time() + ( intval( $_GET['minutes'] ) * 60 ) ) );

			//reset now
			$resetnow = isset( $_GET['resetnow'] );
		}

		if ( $next_reset <= time() || $resetnow )
			//add_action('setup_theme', array( &$this, 'the_reset' ) );
			add_action('admin_init', array( &$this, 'the_reset' ), 1 );

		//prevent editing/deleting of main 'demo' user, ensure someone can always log in
		add_filter( 'map_meta_cap', array( &$this, 'prevent_edit_of_primary_user' ), 10, 4 );
		add_filter( 'user_row_actions', array( &$this, 'no_user_edit_note' ), 10, 2 );

		//generate a new user when someone logs in
		if ( $this->generate_new_users )
			add_action( 'wp_login', array( &$this, 'generate_new_user' ), 10, 2 );
		if ( $this->broadcast_credentials )
	 		add_filter( 'login_message', array( &$this, 'show_credentials' ) );

		if ( $this->show_countdown )
	 		add_action( 'admin_bar_menu', array( &$this, 'countdown' ), 100 );
	}

	function the_reset() {

		if ( strpos( $_SERVER['REQUEST_URI'], 'wp-login.php' ) !== false ) {
			wp_redirect( admin_url('?resetnow') );
			exit();
		}

		$dirs = wp_upload_dir();
		$this->remove_all_uploads( $dirs['basedir'] );

		//get and remove all tables
		global $wpdb;
		$tables = array_merge( $wpdb->tables, $wpdb->global_tables );
		foreach( $tables as $tbl )
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$tbl}" );

		//make sure wp_install() is available
		if ( ! function_exists( 'wp_install' ) )
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		//install site with defaults
		$install = wp_install( $this->blog_title, $this->user_name, $this->user_email, $this->public, '', $this->user_password );
		if ( $this->generate_new_users )
			update_option( 'next_user', array( 'un' => $this->user_name, 'pw' => $this->user_password ) );

		//disable the welcome dashboard
		if ( $this->hide_welcome_dashboard )
		update_user_meta( $install['user_id'], 'show_welcome_panel', false );

		//enable feature pointers
		if ( $this->show_feature_pointers )
		update_user_meta( $install['user_id'], 'dismissed_wp_pointers', '' );

		//schedule next reset
		update_option( 'next_reset', array( time() + $this->interval ) );

		/*				DO MORE!					*
			You can do other things in here, too.
			Maybe create some extra pages & posts
			or active plugins. Go wild, have fun.
		*											*/

		//send user to login screen
		wp_redirect( admin_url('')  );
		exit();
	}

	function remove_all_uploads( $dir ) {
		$here = glob("$dir*.*" ); //get files
		$dirs = glob("$dir*", GLOB_ONLYDIR|GLOB_MARK ); //get subdirectories

		//start with subs, less confusing
		foreach ($dirs as $k => $sdir)
			$this->remove_all_uploads( $sdir );

		//loop through files and delete
		foreach ( $here as $file ) {
			if ( is_file( $file ) )
				unlink( $file );
		}
		if ( is_dir( $dir ) )
			rmdir( $dir );
	}

	function prevent_edit_of_primary_user( $caps, $cap, $user_id, $args ) {
		if ( $cap == 'edit_user' && in_array( 1, $args ) )
			return false;
		if ( $cap == 'delete_users' && in_array( 1, $args ) )
			return false;
		//during testing, I forgot to put 'return $caps' here
		//that gave all users all privs
		//so don't remove this, mkay?
		return $caps;
	}
	function no_user_edit_note( $actions, $user ) {
		if ($user->ID == 1)
			$actions[] = __( 'For the purposes of this demo, this user cannot be edited.', 'auto-reset' );
		return $actions;
	}

	function generate_new_user( $user_login, $user ) {

		$next = get_option( 'next_user' );

		//if not logging in with the newest user, don't create another
		if ( $user_login != $next['un'] )
			return;


		$i = count( get_users() );
		$un = $pw = 'demo'.$i;

		if ( $this->randomize_new_passwords )
			$pw = wp_generate_password( 10, false, false );

		$id = wp_create_user( $un, $pw );
		$user = new WP_User( $id );
		$user->set_role( 'administrator' );
		update_option( 'next_user', array( 'un' => $un, 'pw' => $pw ) );

	}

	function show_credentials() {
		extract( get_option( 'next_user', array( 'un' => $this->user_name, 'pw' => $this->user_password ) ) );
		return '<p class="message">' . sprintf( __( 'Username: %s', 'auto-reset' ), $un ) . '<br />' . sprintf( __( 'Password: %s', 'auto-reset' ), $pw ) . '</p>';
	}

	function countdown( $wp_admin_bar ) {
		//get next scheduled reset
		$next = array_shift( get_option( 'next_reset' ) );
		$now = time();

		//get difference between then and now
		$diff = ( $next - $now );

		//convert it to mins:secs
		$time = date_i18n( 'H:i:s', $diff );

		//add live countdown to next reset to Toolbar
		$wp_admin_bar->add_menu( array(
			'id' => 'live-countdown',
			'title' => sprintf( __( 'Resetting in: %s', 'auto-reset' ), "<time id='javascript_countdown_time'>$time</time>" ) . $this->js( $diff )
		) );

		//add timestam for next reset to Toolbar
		$wp_admin_bar->add_menu( array(
			'parent' => 'live-countdown',
			'id' => 'live-countdown-timestamp',
			'title' => sprintf( __( 'Reset at: %s', 'auto-reset' ), date_i18n( 'F j, Y H:i:sa T', $next ) )
		) );

		//add current time, so we don't have to figure out UTC
		$wp_admin_bar->add_menu( array(
			'parent' => 'live-countdown',
			'id' => 'live-countdown-current-time',
			'title' => sprintf( __( 'Currently: %s', 'auto-reset' ), date_i18n( 'F j, Y H:i:sa T' ) )
		) );
	}

	//this JS will probably bother you
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