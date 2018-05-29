<?php
/**
 * Friends Access Control
 *
 * This contains the functions for access control.
 *
 * @package Friends
 */

/**
 * This is the class for the Friends Plugin Access Control.
 *
 * @since 0.6
 *
 * @package Friends
 * @author Alex Kirk
 */
class Friends_Access_Control {
	/**
	 * States whether this is an authenticated feed call.
	 *
	 * @var boolean
	 */
	private $feed_authenticated = null;

	/**
	 * Contains a reference to the Friends class.
	 *
	 * @var Friends
	 */
	private $friends = null;

	/**
	 * Constructor
	 *
	 * @param Friends $friends A reference to the Friends object.
	 */
	public function __construct( Friends $friends ) {
		$this->friends = $friends;
		$this->register_hooks();
	}

	/**
	 * Register the WordPress hooks
	 */
	private function register_hooks() {
		add_filter( 'determine_current_user', array( $this, 'authenticate' ), 1 );
		add_action( 'set_user_role', array( $this, 'update_friend_request_token' ), 10, 3 );
		add_action( 'delete_user', array( $this, 'delete_friend_token' ) );
		add_action( 'init', array( $this, 'remote_login' ) );
	}

	/**
	 * Whether the feed is authenticated
	 *
	 * @return bool The authentication status of the feed.
	 */
	public function feed_is_authenticated() {
		if ( is_null( $this->feed_authenticated ) ) {
			$this->authenticate( 0 );
		}
		return $this->feed_authenticated;
	}

	/**
	 * Convert a site URL to a username
	 *
	 * @param  string $site_url The site URL in question.
	 * @return string The corresponding username.
	 */
	public function get_user_login_for_site_url( $site_url ) {
		$host = wp_parse_url( $site_url, PHP_URL_HOST );
		$path = wp_parse_url( $site_url, PHP_URL_PATH );

		$user_login = trim( preg_replace( '#[^a-z0-9.-]+#', '_', strtolower( $host . '_' . $path ) ), '_' );
		return $user_login;
	}

	/**
	 * Checks whether a user already exists for a site URL.
	 *
	 * @param  string $site_url The site URL for which to create the user.
	 * @return WP_User|false Whether the user already exists
	 */
	public function get_user_for_site_url( $site_url ) {
		$user_login = $this->get_user_login_for_site_url( $site_url );
		return get_user_by( 'login', $user_login );
	}

	/**
	 * Create a WP_User with a specific Friends-related role
	 *
	 * @param  string $site_url The site URL for which to create the user.
	 * @param  string $role     The role: subscription, pending_friend_request, friend_request, or friend.
	 * @param  string $name     The user's nickname.
	 * @param  string $email    The user e-mail address.
	 * @return WP_User|WP_Error The created user or an error.
	 */
	public function create_user( $site_url, $role, $name = null, $email = null ) {

		$role_rank = array_flip(
			array(
				'subscription',
				'pending_friend_request',
				'friend_request',
			)
		);
		if ( ! isset( $role_rank[ $role ] ) ) {
			return new WP_Error( 'invalid_role', 'Invalid role for creation specified' );
		}

		$user = $this->get_user_for_site_url( $site_url );
		if ( $user && ! is_wp_error( $user ) ) {
			foreach ( $role_rank as $_role => $rank ) {
				if ( $rank > $role_rank[ $role ] ) {
					break;
				}
				if ( $user->has_cap( $_role ) ) {
					// Upgrade user role.
					$user->set_role( $role );
					break;
				}
			}
			return $user;
		}

		$userdata = array(
			'user_login' => $this->get_user_login_for_site_url( $site_url ),
			'nickname'   => $name,
			'user_email' => $email,
			'user_url'   => $site_url,
			'user_pass'  => wp_generate_password( 256 ),
			'role'       => $role,
		);
		$user_id  = wp_insert_user( $userdata );

		update_user_option( $user_id, 'friends_new_friend', true );

		return new WP_User( $user_id );
	}

	/**
	 * Verify a friend token
	 *
	 * @param  string $token The token to verify.
	 * @return int|bool The user id or false.
	 */
	protected function verify_token( $token ) {
		$user_id = get_option( 'friends_in_token_' . $token );
		if ( ! $user_id ) {
			return false;
		}
		settype( $user_id, 'int' );
		if ( get_user_option( 'friends_in_token', $user_id ) !== $token ) {
			return false;
		}

		return $user_id;
	}

	/**
	 * Log in a friend via URL parameter
	 */
	public function remote_login() {
		if ( ! isset( $_GET['friend_auth'] ) ) {
			return;
		}

		$user_id = $this->verify_token( $_GET['friend_auth'] );
		if ( ! $user_id ) {
			return;
		}
		$user = new WP_User( $user_id );
		if ( ! $user->has_cap( 'friend' ) ) {
			return;
		}

		wp_set_auth_cookie( $user_id );
		wp_safe_redirect( str_replace( array( '?friend_auth=' . $_GET['friend_auth'], '&friend_auth=' . $_GET['friend_auth'] ), '', $_SERVER['REQUEST_URI'] ) );
		exit;
	}

	/**
	 * Authenticate a user for a feed.
	 *
	 * @param  int $incoming_user_id An already authenticated user.
	 * @return int The new authenticated user.
	 */
	public function authenticate( $incoming_user_id ) {
		if ( ! isset( $_GET['friend'] ) ) {
			return $incoming_user_id;
		}

		$user_id = $this->verify_token( $_GET['friend'] );
		if ( ! $user_id ) {
			return $incoming_user_id;
		}

		$user = new WP_User( $user_id );
		if ( ! $user->has_cap( 'friend' ) ) {
			return $incoming_user_id;
		}

		$this->feed_authenticated = $user_id;
		return $user_id;
	}

	/**
	 * Delete options associated with a user
	 *
	 * @param  int $user_id The user id.
	 * @return The old token.
	 */
	public function delete_friend_token( $user_id ) {
		$current_secret = get_user_option( 'friends_in_token', $user_id );

		if ( $current_secret ) {
			delete_option( 'friends_in_token_' . $current_secret );
			delete_option( 'friends_accept_token_' . $current_secret );
		}

		$user = new WP_User( $user_id );
		delete_option( 'friends_request_token_' . sha1( $user->user_url ) );

		// No need to delete user options as the user will be deleted.
		return $current_secret;
	}

	/**
	 * Update the friend_in_token
	 *
	 * @param  int $user_id The user_id to give an friend_in_token.
	 * @return string The new friend_in_token.
	 */
	public function update_in_token( $user_id ) {
		$in_token = sha1( wp_generate_password( 256 ) );
		if ( update_user_option( $user_id, 'friends_in_token', $in_token ) ) {
			update_option( 'friends_in_token_' . $in_token, $user_id );
		}

		return $in_token;
	}

	/**
	 * Update a friend request token
	 *
	 * @param  int    $user_id   The user id.
	 * @param  string $new_role  The new role.
	 * @param  string $old_roles The old roles.
	 */
	public function update_friend_request_token( $user_id, $new_role, $old_roles ) {
		if ( 'friend_request' !== $new_role || in_array( $new_role, $old_roles, true ) ) {
			return;
		}

		$token = sha1( wp_generate_password( 256 ) );
		update_user_option( $user_id, 'friends_request_token', $token );

		do_action( 'notify_new_friend_request', new WP_User( $user_id ) );
	}

	/**
	 * Convert a user to a friend
	 *
	 * @param  WP_User $user  The user to become a friend of the blog.
	 * @param  string  $out_token The token to talk to the remote.
	 * @return WP_User|WP_Error The user or an error.
	 */
	public function make_friend( WP_User $user, $out_token ) {
		if ( ! $user || is_wp_error( $user ) ) {
			return $user;
		}
		update_user_option( $user->ID, 'friends_out_token', $out_token );
		$user->set_role( 'friend' );

		return $user;
	}
}
