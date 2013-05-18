<?php
/**
 * Check for valid user via login form or stored cookie. Returns true or an error message
 *
 */
function yourls_is_valid_user() {
	static $valid = false;
	
	if( $valid )
		return true;
		
	// Allow plugins to short-circuit the whole function
	$pre = yourls_apply_filter( 'shunt_is_valid_user', null );
	if ( null !== $pre ) {
		$valid = ( $pre === true ) ;
		return $pre;
	}
	
	// $unfiltered_valid : are credentials valid? Boolean value. It's "unfiltered" to allow plugins to eventually filter it.
	$unfiltered_valid = false;

	// Logout request
	if( isset( $_GET['action'] ) && $_GET['action'] == 'logout' ) {
		yourls_do_action( 'logout' );
		yourls_store_cookie( null );
		return yourls__( 'Logged out successfully' );
	}
	
	// Check cookies or login request. Login form has precedence.

	yourls_do_action( 'pre_login' );

	// Determine auth method and check credentials
	if
		// API only: Secure (no login or pwd) and time limited token
		// ?timestamp=12345678&signature=md5(totoblah12345678)
		( yourls_is_API() &&
		  isset( $_REQUEST['timestamp'] ) && !empty($_REQUEST['timestamp'] ) &&
		  isset( $_REQUEST['signature'] ) && !empty($_REQUEST['signature'] )
		)
		{
			yourls_do_action( 'pre_login_signature_timestamp' );
			$unfiltered_valid = yourls_check_signature_timestamp();
		}
		
	elseif
		// API only: Secure (no login or pwd)
		// ?signature=md5(totoblah)
		( yourls_is_API() &&
		  !isset( $_REQUEST['timestamp'] ) &&
		  isset( $_REQUEST['signature'] ) && !empty( $_REQUEST['signature'] )
		)
		{
			yourls_do_action( 'pre_login_signature' );
			$unfiltered_valid = yourls_check_signature();
		}
	
	elseif
		// API or normal: login with username & pwd
		( isset( $_REQUEST['username'] ) && isset( $_REQUEST['password'] )
		  && !empty( $_REQUEST['username'] ) && !empty( $_REQUEST['password']  ) )
		{
			yourls_do_action( 'pre_login_username_password' );
			$unfiltered_valid = yourls_check_username_password();
		}
	
	elseif
		// Normal only: cookies
		( !yourls_is_API() && 
		  isset( $_COOKIE['yourls_username'] ) && isset( $_COOKIE['yourls_password'] ) )
		{
			yourls_do_action( 'pre_login_cookie' );
			$unfiltered_valid = yourls_check_auth_cookie();
		}
	
	// Regardless of validity, allow plugins to filter the boolean and have final word
	$valid = yourls_apply_filter( 'is_valid_user', $unfiltered_valid );

	// Login for the win!
	if ( $valid ) {
		yourls_do_action( 'login' );
		
		// (Re)store encrypted cookie if needed
		if ( !yourls_is_API() ) {
			yourls_store_cookie( YOURLS_USER );
			
			// Login form : redirect to requested URL to avoid re-submitting the login form on page reload
			if( isset( $_REQUEST['username'] ) && isset( $_REQUEST['password'] ) ) {
				$url = $_SERVER['REQUEST_URI'];
				// If password stored unencrypted, append query string. TODO: deprecate this when there's proper user management
				if( !yourls_has_hashed_password( $_REQUEST['username'] ) ) {
					$url = yourls_add_query_arg( array( 'login_msg' => 'pwdclear' ) );
				}
				yourls_redirect( $url );
			}
		}
		
		// Login successful
		return true;
	}
	
	// Login failed
	yourls_do_action( 'login_failed' );

	if ( isset( $_REQUEST['username'] ) || isset( $_REQUEST['password'] ) ) {
		return yourls__( 'Invalid username or password' );
	} else {
		return yourls__( 'Please log in' );
	}
}

/**
 * Check auth against list of login=>pwd. Sets user if applicable, returns bool
 *
 */
function yourls_check_username_password() {
	global $yourls_user_passwords;
	if( isset( $yourls_user_passwords[ $_REQUEST['username'] ] ) && yourls_check_password_hash( $_REQUEST['username'], $_REQUEST['password'] ) ) {
		yourls_set_user( $_REQUEST['username'] );
		return true;
	}
	return false;
}

/**
 * Check a submitted password sent in plain text against stored password which can be a salted hash
 *
 */
function yourls_check_password_hash( $user, $submitted_password ) {
	global $yourls_user_passwords;
	
	if( !isset( $yourls_user_passwords[ $user ] ) )
		return false;
		
	if( yourls_has_hashed_password( $user ) ) {
		// Stored password is a salted hash: "md5:<$r = rand(10000,99999)>:<md5($r.'thepassword')>"
		list( , $salt, ) = explode( ':', $yourls_user_passwords[ $user ] );
		return( $yourls_user_passwords[ $user ] == 'md5:'.$salt.':'.md5( $salt . $submitted_password ) );
	} else {
		// Password stored in clear text
		return( $yourls_user_passwords[ $user ] == $submitted_password );
	}
}

/**
 * Check if a user has a hashed password
 *
 * Check if a user password is 'md5:[38 chars]'. TODO: deprecate this when/if we have proper user management with
 * password hashes stored in the DB
 *
 * @since 1.7
 * @param string $user user login
 * @return bool true if password hashed, false otherwise
 */
function yourls_has_hashed_password( $user ) {
	global $yourls_user_passwords;
	return(    isset( $yourls_user_passwords[ $user ] )
			&& substr( $yourls_user_passwords[ $user ], 0, 4 ) == 'md5:'
			&& strlen( $yourls_user_passwords[ $user ] ) == 42 // http://www.google.com/search?q=the+answer+to+life+the+universe+and+everything
		   );
}

/**
 * Check auth against encrypted COOKIE data. Sets user if applicable, returns bool
 *
 */
function yourls_check_auth_cookie() {
	global $yourls_user_passwords;
	foreach( $yourls_user_passwords as $valid_user => $valid_password ) {
		if( 
			yourls_salt( $valid_user ) == $_COOKIE['yourls_username']
			&& yourls_salt( $valid_password ) == $_COOKIE['yourls_password'] 
		) {
			yourls_set_user( $valid_user );
			return true;
		}
	}
	return false;
}

/**
 * Check auth against signature and timestamp. Sets user if applicable, returns bool
 *
 */
function yourls_check_signature_timestamp() {
	// Timestamp in PHP : time()
	// Timestamp in JS: parseInt(new Date().getTime() / 1000)
	global $yourls_user_passwords;
	foreach( $yourls_user_passwords as $valid_user => $valid_password ) {
		if (
			(
				md5( $_REQUEST['timestamp'].yourls_auth_signature( $valid_user ) ) == $_REQUEST['signature']
				or
				md5( yourls_auth_signature( $valid_user ).$_REQUEST['timestamp'] ) == $_REQUEST['signature']
			)
			&&
			yourls_check_timestamp( $_REQUEST['timestamp'] )
			) {
			yourls_set_user( $valid_user );
			return true;
		}
	}
	return false;
}

/**
 * Check auth against signature. Sets user if applicable, returns bool
 *
 */
function yourls_check_signature() {
	global $yourls_user_passwords;
	foreach( $yourls_user_passwords as $valid_user => $valid_password ) {
		if ( yourls_auth_signature( $valid_user ) == $_REQUEST['signature'] ) {
			yourls_set_user( $valid_user );
			return true;
		}
	}
	return false;
}

/**
 * Generate secret signature hash
 *
 */
function yourls_auth_signature( $username = false ) {
	if( !$username && defined('YOURLS_USER') ) {
		$username = YOURLS_USER;
	}
	return ( $username ? substr( yourls_salt( $username ), 0, 10 ) : 'Cannot generate auth signature: no username' );
}

/**
 * Check if timestamp is not too old
 *
 */
function yourls_check_timestamp( $time ) {
	$now = time();
	// Allow timestamp to be a little in the future or the past -- see Issue 766
	return yourls_apply_filter( 'check_timestamp', abs( $now - $time ) < YOURLS_NONCE_LIFE, $time );
}

/**
 * Store new cookie. No $user will delete the cookie.
 *
 */
function yourls_store_cookie( $user = null ) {
	if( !$user ) {
		$pass = null;
		$time = time() - 3600;
	} else {
		global $yourls_user_passwords;
		if( isset($yourls_user_passwords[$user]) ) {
			$pass = $yourls_user_passwords[$user];
		} else {
			die( 'Stealing cookies?' ); // This should never happen
		}
		$time = time() + YOURLS_COOKIE_LIFE;
	}
	
	$domain   = yourls_apply_filter( 'setcookie_domain',   parse_url( YOURLS_SITE, 1 ) );
	$secure   = yourls_apply_filter( 'setcookie_secure',   yourls_is_ssl() );
	$httponly = yourls_apply_filter( 'setcookie_httponly', true );
		
	if ( !headers_sent() ) {
		// Set httponly if the php version is >= 5.2.0
		if( version_compare( phpversion(), '5.2.0', 'ge' ) ) {
			setcookie('yourls_username', yourls_salt( $user ), $time, '/', $domain, $secure, $httponly );
			setcookie('yourls_password', yourls_salt( $pass ), $time, '/', $domain, $secure, $httponly );
		} else {
			setcookie('yourls_username', yourls_salt( $user ), $time, '/', $domain, $secure );
			setcookie('yourls_password', yourls_salt( $pass ), $time, '/', $domain, $secure );
		}
	} else {
		// For some reason cookies were not stored: action to be able to debug that
		yourls_do_action( 'setcookie_failed', $user );
	}
}

/**
 * Set user name
 *
 */
function yourls_set_user( $user ) {
	if( !defined( 'YOURLS_USER' ) )
		define( 'YOURLS_USER', $user );
}

/**
 * Check if current session is valid and secure as configurated
 *
 */
function yourls_is_valid_session() {
	// TODO; @ozh J'avoue que je suis perdu dans les fonctions de verification. Comment verifier que la session active est loggee ?
	if ( !yourls_is_private() )
		return true;
	else
		return defined( 'YOURLS_USER' );
}
