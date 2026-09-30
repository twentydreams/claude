<?php
/**
 * Plugin Name: Shatania Profile System
 * Plugin URI:  https://shataniaveyra.com
 * Description: Complete private member & photo community — login, registration, approval, web + Android feed access, blur/unlock, admin panel, Google Drive APK link, download protection, push notifications, live presence, chat celebrations, silent message deletion & link previews.
 * Version:     2.10.1
 * Author:      Shatania
 * Text Domain: shatania
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// CONSTANTS
// ============================================================
define( 'SHAT_VER',                '2.4.0' );
define( 'SHAT_FROM_EMAIL',         'hi@shataniaveyra.com' );
define( 'SHAT_FROM_NAME',          'Shatania' );
define( 'SHAT_PAYPAL',             'shataniaveyra@gmail.com' );
define( 'SHAT_SITE_NAME',          'Shatania' );
define( 'SHAT_PROFILE_PIC',        'https://shataniaveyra.com/wp-content/uploads/2026/06/PINK-php.jpg' );
define( 'SHAT_UNLOCK_EMAIL',       'shataniaveyra@proton.me' );
define( 'SHAT_PATREON_URL',        'https://www.patreon.com/shataniaveyra' ); // update to your real Patreon page URL
define( 'SHAT_PATREON_GO_URL',     'https://shataniaveyra.com/go/patreon/' );  // tracked link used on locked "exclusive" posts
// 2.3.0 — Telegram/Snapchat handles removed. All member contact now runs
// through the built-in chat (see shat_contact_line() below), so there is no
// off-platform handle to keep in sync or leak.

// Cloudflare Turnstile keys
define( 'SHAT_CF_SITE_KEY',        '0x4AAAAAADie791uHF_oy3-w' );
define( 'SHAT_CF_SECRET_KEY',      '0x4AAAAAADie7_PxzitOrT76zrQJZu-nBk8' );

// NOWPayments — crypto payment gateway (USDT/TRC20)
// Fill these in once your NOWPayments account + API key are ready.
// SHAT_NOWPAY_PRICE is in USD — NOWPayments converts to USDT at checkout.
define( 'SHAT_NOWPAY_API_KEY',     '' ); // your NOWPayments API key
define( 'SHAT_NOWPAY_IPN_SECRET',  '' ); // your NOWPayments IPN secret key (for verifying webhook authenticity)
define( 'SHAT_NOWPAY_PRICE',       10 );  // flat price in USD for feed access / renewal (30 days)
define( 'SHAT_NOWPAY_CURRENCY',    'usdttrc20' ); // pay_currency code NOWPayments expects for USDT-TRC20
define( 'SHAT_NOWPAY_ACCESS_DAYS', 30 );  // how many days of feed access one payment grants

/* ============================================================================
   ============================================================================
   SHATANIA CHAT — direct-message system between Shatania and members.
   One implicit thread per member (keyed by member_id — no groups table).
   Shatania always sends first; a member can only reply into their own
   thread and can never message anyone else. No push/FCM — plain email
   notifications instead. Uses the "schat_" prefix throughout so nothing
   here collides with the existing "shat_" functions above; it calls into
   shat_mail() / shat_email_wrap() / shat_is_approved() / shat_page_url()
   / shat_custom_login_url() / SHAT_PROFILE_PIC where available.
   ============================================================================
   ============================================================================ */

define( 'SCHAT_ADMIN_EMAIL', 'shataniaveyra@gmail.com' );  // where "new message" alerts go
define( 'SCHAT_FROM_EMAIL',  'hi@shataniaveyra.com' );      // sending address
define( 'SCHAT_FROM_NAME',   'Shatania' );
define( 'SCHAT_UPLOAD_DIR',  WP_CONTENT_DIR . '/uploads/shatania-chat/' );
define( 'SCHAT_UPLOAD_URL',  WP_CONTENT_URL . '/uploads/shatania-chat/' );
define( 'SCHAT_MAX_FILE_MB', 8 );
/* How many messages load per request — on first open, and per batch when
   scrolling up. Older messages are fetched only when the user actually
   scrolls back to them, so opening a long thread costs one small query
   instead of pulling the whole history. */
define( 'SCHAT_PAGE_SIZE',   25 );
define( 'SCHAT_VERSION',     '1.5.0' );

/* ------------------------------------------------------------------------
   LIVE ACTIVITY (1.5.0)
   ------------------------------------------------------------------------
   How long each reported state stays believable after its last ping.
   The client re-pings well inside each window, so a state only decays
   when someone actually stopped doing that thing (or walked away).
   'viewing' gets a long leash because its heartbeat is deliberately lazy
   — one ping every ~12s rather than one per poll.
   ------------------------------------------------------------------------ */
define( 'SCHAT_ACT_TYPING_TTL', 7 );
define( 'SCHAT_ACT_SCROLL_TTL', 6 );
define( 'SCHAT_ACT_VIEW_TTL',   35 );

/* ------------------------------------------------------------------------
   PUSH NOTIFICATIONS (Firebase Cloud Messaging)
   ------------------------------------------------------------------------
   Lets the Android app show a push when a new chat message arrives and
   the recipient isn't currently looking at the thread. Fully self
   contained in this file — no Composer/Firebase Admin SDK required, just
   PHP's built-in openssl extension to sign the OAuth2 JWT ourselves.

   SETUP: the Firebase service account JSON is pasted into the WordPress
   admin (Shatania menu -> Push Setup) and stored in the database. Nothing
   sits in the web root, so there's no key file to protect. See
   schat_get_service_account() below and shat_admin_push_setup() further
   down for the settings page.

   (Legacy fallback: if SCHAT_FCM_SERVICE_ACCOUNT_PATH is defined and points
   to a readable file, that file is used instead. This is optional and only
   for anyone who set things up the old file-based way — you don't need it.)
   ------------------------------------------------------------------------ */
define( 'SCHAT_PRESENCE_TTL_SECONDS', 90 ); // see note near schat_user_is_viewing_chat()

/**
 * Returns the decoded Firebase service account as an associative array,
 * or false if none is configured / it's invalid.
 *
 * Source of truth is the WordPress option 'schat_fcm_service_account'
 * (set via the Push Setup admin page). Falls back to a file only if the
 * old SCHAT_FCM_SERVICE_ACCOUNT_PATH constant is defined and readable.
 */
function schat_get_service_account() {
	$json = get_option( 'schat_fcm_service_account', '' );

	if ( ( ! is_string( $json ) || trim( $json ) === '' )
	     && defined( 'SCHAT_FCM_SERVICE_ACCOUNT_PATH' )
	     && is_readable( SCHAT_FCM_SERVICE_ACCOUNT_PATH ) ) {
		$json = file_get_contents( SCHAT_FCM_SERVICE_ACCOUNT_PATH );
	}

	if ( ! is_string( $json ) || trim( $json ) === '' ) return false;

	$sa = json_decode( $json, true );
	if ( empty( $sa['private_key'] ) || empty( $sa['client_email'] ) || empty( $sa['project_id'] ) ) {
		return false;
	}
	return $sa;
}

/* ------------------------------------------------------------------------
   APP-ONLY FEED GATE
   ------------------------------------------------------------------------
   Non-admin members can only see the feed when they're viewing it inside
   the Android app. Browser visitors get a "download the app" screen.

   The app identifies itself two ways, and BOTH must match:
     1. A custom token appended to its WebView User-Agent string.
     2. A secret header (X-Shatania-App) sent on the initial page request.
   The shared secret is stored in wp_options so it can be rotated without
   an app rebuild if it ever leaks (change it in the app + here together).

   NOTE (be honest about this): this is a soft gate to steer people to the
   app, not unbreakable DRM. A determined technical user could extract the
   token from the APK and spoof it. That's fine — the real content
   protection is still the login + approval + blur/unlock system behind it.
   This just makes the app the clear, default path to the feed.
   ------------------------------------------------------------------------ */
define( 'SHAT_APP_UA_TAG', 'ShataniaApp' ); // appended to the app's WebView User-Agent

// The shared secret the app sends in the X-Shatania-App header. Stored in
// options so it can be changed later. Seeded with a fixed default that
// matches the value baked into the app build, so they agree out of the box.
define( 'SHAT_APP_SECRET_DEFAULT', '1bbecfbcd298677e30b8aa4b837194f0df3062896cd440b2' );
function shat_app_secret() {
	$s = get_option( 'shat_app_secret', '' );
	if ( ! $s ) {
		$s = SHAT_APP_SECRET_DEFAULT;
		update_option( 'shat_app_secret', $s, false );
	}
	return $s;
}

/**
 * Is the current request coming from inside the Shatania Android app?
 *
 * The app appends SHAT_APP_UA_TAG to its User-Agent (present on every
 * request), and sends the secret X-Shatania-App header on its initial
 * page load. Because a WebView only attaches custom headers to the first
 * loadUrl (not to in-page navigations), we "latch" a verified flag into
 * a cookie the first time we see a valid header, then trust the UA tag +
 * that cookie for the rest of the session.
 */
function shat_request_is_from_app() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
	$ua_ok = ( strpos( $ua, SHAT_APP_UA_TAG ) !== false );
	if ( ! $ua_ok ) return false; // UA tag must always be present in the app

	// Did the app send the secret header on this request? If so, latch it.
	$hdr = isset( $_SERVER['HTTP_X_SHATANIA_APP'] ) ? $_SERVER['HTTP_X_SHATANIA_APP'] : '';
	if ( $hdr !== '' && hash_equals( shat_app_secret(), (string) $hdr ) ) {
		if ( ! headers_sent() ) {
			// Session cookie (expires when app/browser process ends),
			// HttpOnly so page JS can't read it. Marks this session as
			// app-verified so subsequent in-app navigations pass the gate.
			setcookie( 'shat_app_verified', '1', [
				'expires'  => 0,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			] );
		}
		$_COOKIE['shat_app_verified'] = '1'; // honor it immediately this request
		return true;
	}

	// No header this request — trust the latched cookie (still requires UA tag).
	return isset( $_COOKIE['shat_app_verified'] ) && $_COOKIE['shat_app_verified'] === '1';
}

/* ------------------------------------------------------------------------
   BROWSER ACCESS (2.5.5)
   ------------------------------------------------------------------------
   The app-only gate above is now optional. When browser access is ON
   (the default), approved members can read the whole feed from any
   ordinary browser — desktop or mobile — and the app is offered to them
   as a banner at the top of the feed rather than as a wall in front of it.

   Turn it OFF on Shatania -> App Download to restore the old behaviour
   where non-admins must be inside the Android app.

   Stored in the 'shat_browser_access' option ('1' = web open, '0' = app only).
   ------------------------------------------------------------------------ */
function shat_browser_access_enabled() {
	return get_option( 'shat_browser_access', '1' ) !== '0';
}

/**
 * Should this user be allowed to see the feed content (vs the download
 * screen)? Admins always can, from anywhere. Everyone else gets in too
 * as long as browser access is enabled; otherwise they must be in the app.
 */
function shat_feed_allowed_here() {
	if ( current_user_can( 'manage_options' ) ) return true; // admin bypass
	if ( shat_browser_access_enabled() )        return true; // the web is open
	return shat_request_is_from_app();
}


// ---- DB setup (self-healing, same defensive pattern as the rest of this file) ----
function schat_create_table() {
	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}schat_messages (
		id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		member_id BIGINT UNSIGNED NOT NULL,
		sender_is_admin TINYINT(1) NOT NULL DEFAULT 0,
		message TEXT,
		image_url VARCHAR(500) DEFAULT '',
		sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		read_at DATETIME DEFAULT NULL,
		loved TINYINT(1) NOT NULL DEFAULT 0,
		reply_to BIGINT UNSIGNED DEFAULT NULL,
		is_deleted TINYINT(1) NOT NULL DEFAULT 0,
		INDEX idx_member_time (member_id, sent_at),
		INDEX idx_member_id_asc (member_id, id)
	) $charset;" );

	// Typing indicator — one row per (member thread, side). Tiny + self-expiring.
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}schat_typing (
		member_id BIGINT UNSIGNED NOT NULL,
		is_admin TINYINT(1) NOT NULL DEFAULT 0,
		updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (member_id, is_admin)
	) $charset;" );

	/* Live activity — one row per (member thread, side), same shape as the
	   typing table but carrying WHAT they're doing rather than just "is
	   typing". The row is never deleted on the way out: an 'away' row (or
	   simply a stale one) doubles as the "last active" timestamp, which is
	   what lets the other side read "not in the chat · active 6 mins ago". */
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}schat_activity (
		member_id BIGINT UNSIGNED NOT NULL,
		is_admin TINYINT(1) NOT NULL DEFAULT 0,
		state VARCHAR(16) NOT NULL DEFAULT 'viewing',
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (member_id, is_admin)
	) $charset;" );

	// Self-heal: add reply_to to installs created before this column existed.
	$col = $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}schat_messages LIKE 'reply_to'" );
	if ( ! $col ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}schat_messages ADD COLUMN reply_to BIGINT UNSIGNED DEFAULT NULL" );
	}

	// Tracks whether the admin already emailed the member about a message,
	// so the "Notify by email" action can show as already sent after a reload.
	$col = $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}schat_messages LIKE 'notified_at'" );
	if ( ! $col ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}schat_messages ADD COLUMN notified_at DATETIME DEFAULT NULL" );
	}

	// Records when a message was last edited, so the client can show an
	// "edited" marker. NULL means never edited.
	$col = $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}schat_messages LIKE 'edited_at'" );
	if ( ! $col ) {
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}schat_messages ADD COLUMN edited_at DATETIME DEFAULT NULL" );
	}

	// Push notifications — device tokens (one row per device, FCM tokens
	// are unique) and chat presence (is this user looking at the thread
	// right now, so we know whether to bother pushing to them).
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}schat_device_tokens (
		id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		user_id BIGINT UNSIGNED NOT NULL,
		fcm_token VARCHAR(255) NOT NULL,
		platform VARCHAR(20) NOT NULL DEFAULT 'android',
		updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		UNIQUE KEY uniq_token (fcm_token),
		INDEX idx_user (user_id)
	) $charset;" );

	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}schat_chat_presence (
		user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		is_active TINYINT(1) NOT NULL DEFAULT 0,
		updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
	) $charset;" );

	if ( ! file_exists( SCHAT_UPLOAD_DIR ) ) {
		wp_mkdir_p( SCHAT_UPLOAD_DIR );
		file_put_contents( SCHAT_UPLOAD_DIR . '.htaccess', "Options -Indexes\n" );
	}
	update_option( 'schat_version', SCHAT_VERSION );
}
register_activation_hook( __FILE__, 'schat_create_table' );

add_action( 'init', function() {
	global $wpdb;
	if ( get_option( 'schat_table_checked' ) === SCHAT_VERSION ) return;
	try {
		$table = $wpdb->prefix . 'schat_messages';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			schat_create_table();
		} else {
			// Table exists but may predate reply_to / the typing table.
			schat_create_table();
		}
		update_option( 'schat_table_checked', SCHAT_VERSION );

		/* ------------------------------------------------------------
		   One-time migration: rows written before v1.2.3 used
		   current_time('mysql') (site-local) while the client parses
		   timestamps as UTC. Shift the existing rows by the site's GMT
		   offset once so old messages stop showing a skewed time.
		   ------------------------------------------------------------ */
		if ( ! get_option( 'schat_utc_migrated' ) ) {
			$offset_hours = (float) get_option( 'gmt_offset', 0 );
			if ( abs( $offset_hours ) > 0.01 ) {
				$secs = (int) round( $offset_hours * 3600 );
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}schat_messages
					 SET sent_at = DATE_SUB(sent_at, INTERVAL %d SECOND)
					 WHERE sent_at IS NOT NULL",
					$secs
				) );
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}schat_messages
					 SET read_at = DATE_SUB(read_at, INTERVAL %d SECOND)
					 WHERE read_at IS NOT NULL",
					$secs
				) );
			}
			update_option( 'schat_utc_migrated', 1 );
		}
	} catch ( \Throwable $e ) {
		error_log( '[Shatania Chat] Table self-heal error (non-fatal): ' . $e->getMessage() );
	}
} );

// ---- Upload handling (photo attachments) ----
/**
 * The smallest of PHP's upload_max_filesize and post_max_size, in MB.
 * Anything larger than this never reaches WordPress.
 */
function schat_php_upload_limit_mb() {
	$to_bytes = function ( $val ) {
		$val = trim( (string) $val );
		if ( $val === '' ) return 0;
		$unit = strtolower( substr( $val, -1 ) );
		$num  = (float) $val;
		if ( $unit === 'g' ) $num *= 1024 * 1024 * 1024;
		elseif ( $unit === 'm' ) $num *= 1024 * 1024;
		elseif ( $unit === 'k' ) $num *= 1024;
		return $num;
	};

	$vals = array_filter( [
		$to_bytes( ini_get( 'upload_max_filesize' ) ),
		$to_bytes( ini_get( 'post_max_size' ) ),
	] );

	if ( empty( $vals ) ) return SCHAT_MAX_FILE_MB;

	// Leave a little headroom for the other POST fields riding along.
	return max( 1, (int) floor( ( min( $vals ) * 0.9 ) / ( 1024 * 1024 ) ) );
}

function schat_upload_dir_filter( $dirs ) {
	$dirs['subdir'] = '/shatania-chat';
	$dirs['path']   = $dirs['basedir'] . '/shatania-chat';
	$dirs['url']    = $dirs['baseurl'] . '/shatania-chat';
	return $dirs;
}

function schat_allowed_image_mimes() {
	return [
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
		'gif'      => 'image/gif',
	];
}

function schat_handle_photo_upload( $file_key = 'photo' ) {
	if ( empty( $_FILES[ $file_key ] ) || empty( $_FILES[ $file_key ]['name'] ) ) {
		return '';
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$file = $_FILES[ $file_key ];

	/* PHP reports its own upload problems here, and they are the ones a
	   subscriber is most likely to hit. Translate them instead of letting
	   wp_handle_upload return a vague failure. */
	if ( isset( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
		switch ( $file['error'] ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return new WP_Error( 'too_large', 'That photo is larger than this server accepts (' . schat_php_upload_limit_mb() . 'MB).' );
			case UPLOAD_ERR_PARTIAL:
				return new WP_Error( 'partial', 'The upload was interrupted. Please try again.' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return new WP_Error( 'server', 'The server could not save the photo. Please contact support.' );
			default:
				return new WP_Error( 'upload_failed', 'The photo could not be uploaded. Please try again.' );
		}
	}

	if ( $file['size'] > SCHAT_MAX_FILE_MB * 1024 * 1024 ) {
		return new WP_Error( 'too_large', 'Photo must be under ' . SCHAT_MAX_FILE_MB . 'MB.' );
	}
	$filetype = wp_check_filetype( $file['name'], schat_allowed_image_mimes() );
	if ( empty( $filetype['ext'] ) ) {
		return new WP_Error( 'bad_type', 'Only JPG, PNG, WEBP or GIF photos are allowed.' );
	}

	add_filter( 'upload_dir', 'schat_upload_dir_filter' );
	/* Create the folder explicitly. Members are subscribers, and on some
	   hosts the directory is only auto-created during an admin-side upload,
	   so the first photo a member ever sends would otherwise fail. */
	$dir = wp_upload_dir();
	if ( ! empty( $dir['path'] ) && ! file_exists( $dir['path'] ) ) {
		wp_mkdir_p( $dir['path'] );
	}
	$moved = wp_handle_upload( $file, [ 'test_form' => false, 'mimes' => schat_allowed_image_mimes() ] );
	remove_filter( 'upload_dir', 'schat_upload_dir_filter' );

	if ( empty( $moved ) || ! empty( $moved['error'] ) ) {
		return new WP_Error( 'upload_failed', $moved['error'] ?? 'Upload failed.' );
	}
	return esc_url_raw( $moved['url'] );
}

// ---- Access helpers ----
function schat_can_chat( $uid = 0 ) {
	$uid = $uid ?: get_current_user_id();
	if ( ! $uid ) return false;
	return (bool) shat_is_approved( $uid ); // reuses this file's own approval check
}

function schat_is_site_admin( $uid = 0 ) {
	$uid = $uid ?: get_current_user_id();
	return $uid && user_can( $uid, 'manage_options' );
}

// ---- Mail (reuses shat_mail()/shat_email_wrap() already defined below in this file) ----

/**
 * Should a member's new message email the admin?
 *
 * Off by default (2.5.5) — messages are read in the admin Chat page and
 * delivered by push, so there's no reason to fill an inbox as well.
 * Toggle on Shatania -> App Download. Option: 'schat_admin_email_notify'
 * ('1' = send, anything else = stay quiet).
 */
function schat_admin_email_enabled() {
	return get_option( 'schat_admin_email_notify', '0' ) === '1';
}

function schat_notify_admin_new_message( $member, $message_text, $has_photo ) {
	$preview = $has_photo
		? ( $message_text !== '' ? wp_trim_words( $message_text, 20, '…' ) . ' 📷' : '📷 Sent a photo' )
		: wp_trim_words( $message_text, 20, '…' );

	$dashboard_url = admin_url( 'admin.php?page=shatania-chat&member=' . intval( $member->ID ) );

	$body = '<p style="margin:0 0 14px;font-size:15px;color:#333;">'
		. esc_html( $member->display_name ) . ' sent you a new message:</p>'
		. '<p style="margin:0 0 18px;padding:14px 16px;background:#f6f6f6;border-radius:10px;font-size:14.5px;color:#222;">'
		. esc_html( $preview ) . '</p>'
		. '<a href="' . esc_url( $dashboard_url ) . '" style="display:inline-block;padding:12px 24px;background:#D9A9AC;color:#141414;text-decoration:none;border-radius:10px;font-weight:600;">Reply now &rarr;</a>';

	shat_mail(
		SCHAT_ADMIN_EMAIL,
		'New message on Shataniaveyra.com',
		shat_email_wrap( 'New message on Shataniaveyra.com', $body )
	);
}

// Triggered only when Shatania explicitly clicks "Notify by email" on a
// message she sent — not automatic, unlike the member-to-admin notification.
function schat_notify_member_of_reply( $member, $message_text, $has_photo ) {
	if ( empty( $member->user_email ) ) return false;

	$preview = $has_photo
		? ( $message_text !== '' ? wp_trim_words( $message_text, 20, '…' ) . ' 📷' : '📷 Sent a photo' )
		: wp_trim_words( $message_text, 20, '…' );

	$chat_url = shat_page_url( 'shatania-feed' ) ?: home_url( '/' );

	$body = '<p style="margin:0 0 14px;font-size:15px;color:#333;">You have a new message:</p>'
		. '<p style="margin:0 0 18px;padding:14px 16px;background:#f6f6f6;border-radius:10px;font-size:14.5px;color:#222;">'
		. esc_html( $preview ) . '</p>'
		. '<a href="' . esc_url( $chat_url ) . '" style="display:inline-block;padding:12px 24px;background:#D9A9AC;color:#141414;text-decoration:none;border-radius:10px;font-weight:600;">Open chat &rarr;</a>';

	shat_mail(
		$member->user_email,
		'Shatania sent you a message',
		shat_email_wrap( 'New message from Shatania', $body )
	);
	return true;
}

/* ============================================================================
   PUSH NOTIFICATIONS — FCM HTTP v1 sender
   ============================================================================
   Self-contained: signs its own OAuth2 JWT with the service account key
   using PHP's built-in openssl extension, so there's no Composer/Firebase
   Admin SDK dependency to install. Requires only that the `openssl` PHP
   extension is enabled (virtually always is on any normal host).

   SETUP: paste your Firebase service account JSON into the WordPress
   admin at Shatania -> Push Setup. It's stored in the database — nothing
   to upload to the server, no key file in the web root to protect.
   ============================================================================ */

// OAuth2 access token (cached in a transient — tokens are valid 1hr, we
// refresh at 55 minutes to be safe).
function schat_fcm_get_access_token() {
	$cached = get_transient( 'schat_fcm_access_token' );
	if ( $cached ) return $cached;

	$sa = schat_get_service_account();
	if ( ! $sa ) {
		error_log( '[Shatania Push] No valid service account configured (Shatania -> Push Setup).' );
		return false;
	}

	$now = time();
	$header = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
	$claims = [
		'iss'   => $sa['client_email'],
		'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
		'aud'   => 'https://oauth2.googleapis.com/token',
		'iat'   => $now,
		'exp'   => $now + 3600,
	];

	$b64 = function ( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	};

	$segments = $b64( wp_json_encode( $header ) ) . '.' . $b64( wp_json_encode( $claims ) );

	$signature = '';
	$ok = openssl_sign( $segments, $signature, $sa['private_key'], 'SHA256' );
	if ( ! $ok ) {
		error_log( '[Shatania Push] Failed to sign JWT — check openssl extension and private key format.' );
		return false;
	}

	$jwt = $segments . '.' . $b64( $signature );

	$response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
		'timeout' => 15,
		'body'    => [
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion'  => $jwt,
		],
	] );

	if ( is_wp_error( $response ) ) {
		error_log( '[Shatania Push] Token request failed: ' . $response->get_error_message() );
		return false;
	}

	$body_resp = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body_resp['access_token'] ) ) {
		error_log( '[Shatania Push] No access_token in response: ' . wp_remote_retrieve_body( $response ) );
		return false;
	}

	set_transient( 'schat_fcm_access_token', $body_resp['access_token'], 55 * MINUTE_IN_SECONDS );

	return $body_resp['access_token'];
}

/**
 * The URL a chat notification should open. Adding ?schat=open makes the
 * feed page open the chat panel by itself on load, so the app only has to
 * load this URL — no extra native wiring beyond passing it through.
 */
function schat_chat_deeplink( $member_id = 0 ) {
	$base = shat_page_url( 'shatania-feed' ) ?: home_url( '/' );
	$args = [ 'schat' => 'open' ];
	// Admins have many threads, so the link has to name one. Members only
	// ever have their own, and the param is harmless there.
	if ( $member_id ) $args['member'] = intval( $member_id );
	return add_query_arg( $args, $base );
}

/**
 * Send a data-only push to a single FCM token via HTTP v1.
 * Data-only (not "notification") payload so the Android app has full
 * control over whether/how to display it — this is what lets us honor
 * "don't notify if they're already in the chat" without racing the OS's
 * own auto-display of a notification payload.
 *
 * Returns true on success, false on failure. On an invalid/unregistered
 * token, the token is deleted from our table so we stop trying to send
 * to a dead device.
 */
function schat_fcm_send_to_token( $token, $title, $body_text, $extra = [] ) {
	$sa = schat_get_service_account();
	if ( ! $sa || empty( $sa['project_id'] ) ) {
		error_log( '[Shatania Push] No valid service account configured (Shatania -> Push Setup).' );
		return false;
	}

	$access_token = schat_fcm_get_access_token();
	if ( ! $access_token ) return false;

	$url = "https://fcm.googleapis.com/v1/projects/{$sa['project_id']}/messages:send";

	$payload = [
		'message' => [
			'token' => $token,
			/* `click_url` is what the Android app should load when the
			   notification is tapped; `type` lets it decide whether to
			   open the chat panel or just the feed. Data-only payload, so
			   the app is fully in control of the tap. */
			'data'  => array_merge( [
				'title' => (string) $title,
				'body'  => (string) $body_text,
			], array_map( 'strval', (array) $extra ) ),
			'android' => [
				'priority' => 'high',
			],
		],
	];

	$response = wp_remote_post( $url, [
		'timeout' => 15,
		'headers' => [
			'Authorization' => 'Bearer ' . $access_token,
			'Content-Type'  => 'application/json',
		],
		'body' => wp_json_encode( $payload ),
	] );

	if ( is_wp_error( $response ) ) {
		error_log( '[Shatania Push] Send failed: ' . $response->get_error_message() );
		return false;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code === 200 ) return true;

	$resp_body = wp_remote_retrieve_body( $response );
	error_log( "[Shatania Push] FCM error {$code}: {$resp_body}" );

	// Token no longer valid (app uninstalled, token rotated, etc.) — remove it.
	if ( $code === 404 || $code === 400 ) {
		$decoded = json_decode( $resp_body, true );
		$status  = $decoded['error']['status'] ?? '';
		if ( in_array( $status, [ 'NOT_FOUND', 'INVALID_ARGUMENT', 'UNREGISTERED' ], true ) ) {
			global $wpdb;
			$wpdb->delete( "{$wpdb->prefix}schat_device_tokens", [ 'fcm_token' => $token ] );
		}
	}

	return false;
}

/**
 * Send a push to every registered device for a given WP user, skipping
 * silently if the user has no tokens or is currently viewing the chat.
 */
function schat_fcm_send_to_user( $user_id, $title, $body_text, $extra = [] ) {
	global $wpdb;
	$user_id = intval( $user_id );
	if ( ! $user_id ) return;

	/* Are they looking at THIS conversation right now?
	   Checked before the global flag because the global flag cannot tell
	   one thread from another: without this, Shatania reading one member's
	   thread would silence pushes from every other member too. */
	$thread_id = isset( $extra['member_id'] ) ? intval( $extra['member_id'] ) : 0;
	if ( $thread_id && schat_is_viewing_thread( $user_id, $thread_id ) ) {
		return; // Sitting in this very conversation — a push would be noise.
	}

	/* The older, whole-app presence flag, which the Android app sets from
	   its own chat screen. A member only ever has one conversation, so for
	   them "in the chat" and "in this thread" are the same thing and this
	   is safe. For the admin it is not, so she is judged on the per-thread
	   signal above — she should still hear about other people. */
	if ( ! schat_is_site_admin( $user_id ) && schat_user_is_viewing_chat( $user_id ) ) {
		return;
	}
	if ( ! $thread_id && schat_user_is_viewing_chat( $user_id ) ) {
		return; // Not about a specific thread — fall back to the old behaviour.
	}

	$tokens = $wpdb->get_col( $wpdb->prepare(
		"SELECT fcm_token FROM {$wpdb->prefix}schat_device_tokens WHERE user_id = %d",
		$user_id
	) );

	foreach ( $tokens as $token ) {
		schat_fcm_send_to_token( $token, $title, $body_text, $extra );
	}
}

/**
 * Broadcast a push to all APPROVED members who have a registered device,
 * plus all administrators (so Shatania also gets new-post alerts on her
 * own device). Used for "new post" notifications. Unlike chat pushes, this
 * ignores the chat-presence check (it's not about a chat thread).
 * Returns the number of devices messaged.
 */
function schat_fcm_broadcast_to_approved( $title, $body_text, $extra = [] ) {
	global $wpdb;

	// Approved members. shat_get_approved_users() returns WP_User objects.
	$approved = function_exists( 'shat_get_approved_users' ) ? shat_get_approved_users() : [];
	$ids = array_map( function( $u ) { return intval( is_object( $u ) ? $u->ID : $u ); }, (array) $approved );

	// Always include administrators (Shatania) so she gets the alert too,
	// whether or not her account appears in the approved-members list.
	$admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
	$ids = array_merge( $ids, array_map( 'intval', (array) $admin_ids ) );

	// Dedupe + clean, so an admin who is also "approved" isn't messaged twice.
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	if ( empty( $ids ) ) return 0;

	$in = implode( ',', array_map( 'intval', $ids ) );
	$tokens = $wpdb->get_col(
		"SELECT fcm_token FROM {$wpdb->prefix}schat_device_tokens WHERE user_id IN ($in)"
	);

	$sent = 0;
	foreach ( $tokens as $token ) {
		if ( schat_fcm_send_to_token( $token, $title, $body_text, $extra ) ) $sent++;
	}
	return $sent;
}

/* ============================================================
   AJAX: register (or refresh) this device's FCM token
   ============================================================ */
add_action( 'wp_ajax_schat_register_device', 'schat_ajax_register_device' );
function schat_ajax_register_device() {
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	global $wpdb;
	$user_id  = get_current_user_id();
	$token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$platform = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : 'android';

	if ( $token === '' ) wp_send_json_error( 'Missing token.' );

	// A token belongs to exactly one user at a time. If this token was
	// previously registered to a different user (shared/reset device, or
	// someone logged out and a different member logged in without the
	// token rotating), re-point it rather than erroring — tokens are
	// device-scoped, not permanently tied to whoever first registered them.
	$existing = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, user_id FROM {$wpdb->prefix}schat_device_tokens WHERE fcm_token = %s",
		$token
	) );

	if ( $existing ) {
		$wpdb->update(
			"{$wpdb->prefix}schat_device_tokens",
			[ 'user_id' => $user_id, 'platform' => $platform ],
			[ 'id' => $existing->id ]
		);
	} else {
		$wpdb->insert( "{$wpdb->prefix}schat_device_tokens", [
			'user_id'   => $user_id,
			'fcm_token' => $token,
			'platform'  => $platform,
		] );
	}

	wp_send_json_success();
}

/* ============================================================
   AJAX: report whether the user is currently viewing the chat panel
   ============================================================ */
add_action( 'wp_ajax_schat_set_chat_presence', 'schat_ajax_set_chat_presence' );
function schat_ajax_set_chat_presence() {
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	global $wpdb;
	$user_id = get_current_user_id();
	$active  = ! empty( $_POST['active'] ) && $_POST['active'] === '1' ? 1 : 0;

	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}schat_chat_presence (user_id, is_active, updated_at)
		 VALUES (%d, %d, %s)
		 ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), updated_at = VALUES(updated_at)",
		$user_id, $active, current_time( 'mysql' )
	) );

	wp_send_json_success();
}

/**
 * Is this user currently viewing the chat panel?
 *
 * Trusts the "active" flag only if it was set recently (within
 * SCHAT_PRESENCE_TTL_SECONDS). This protects against a stale "active=1"
 * getting stuck forever if the app is killed/crashes without ever
 * sending "active=0" on the way out (Android doesn't always give a
 * backgrounded app time to fire that last request) — after ~90 seconds
 * with no fresh ping, we stop trusting it and notifications resume.
 */
/**
 * Is this person looking at one particular conversation right now?
 *
 * Reads the per-thread activity row the chat page maintains (typing /
 * scrolling / viewing / away), which the page reports itself — so it is
 * true however they arrived, including straight from a notification tap.
 *
 * @param int $user_id   Whoever the push is aimed at.
 * @param int $member_id The thread in question.
 */
function schat_is_viewing_thread( $user_id, $member_id ) {
	global $wpdb;
	$user_id   = intval( $user_id );
	$member_id = intval( $member_id );
	if ( ! $user_id || ! $member_id ) return false;

	$is_admin = schat_is_site_admin( $user_id ) ? 1 : 0;
	// A member only ever has their own thread; anything else isn't theirs.
	if ( ! $is_admin && $user_id !== $member_id ) return false;

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT state, updated_at FROM {$wpdb->prefix}schat_activity
		  WHERE member_id = %d AND is_admin = %d",
		$member_id, $is_admin
	) );
	if ( ! $row || $row->state === 'away' ) return false;

	/* Same staleness rule as the presence flag: if the last ping is old
	   enough, assume the app died without saying goodbye and let the
	   notification through rather than silently swallowing it. */
	$age = time() - strtotime( $row->updated_at . ' UTC' );
	return $age <= SCHAT_ACT_VIEW_TTL;
}

function schat_user_is_viewing_chat( $user_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT is_active, updated_at FROM {$wpdb->prefix}schat_chat_presence WHERE user_id = %d",
		intval( $user_id )
	) );
	if ( ! $row || ! $row->is_active ) return false;

	$age = time() - strtotime( $row->updated_at . ' UTC' );
	return $age <= SCHAT_PRESENCE_TTL_SECONDS;
}

/* ============================================================================
   PUSH SETUP — admin settings page
   ============================================================================
   Lets the admin paste the Firebase service account JSON straight into the
   dashboard. It's stored in the wp_options table (option
   'schat_fcm_service_account'), so there's no key file sitting in the web
   root. Includes a "send test push to myself" button to verify end to end.
   ============================================================================ */
function shat_admin_push_setup() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	global $wpdb;
	$notice = '';

	// --- Save pasted JSON ---
	if ( isset( $_POST['schat_push_save'] ) && check_admin_referer( 'schat_push_setup' ) ) {
		$raw = isset( $_POST['schat_fcm_json'] ) ? trim( wp_unslash( $_POST['schat_fcm_json'] ) ) : '';

		if ( $raw === '' ) {
			// Empty = clear the stored key.
			delete_option( 'schat_fcm_service_account' );
			delete_transient( 'schat_fcm_access_token' );
			$notice = '<div class="notice notice-warning"><p>Service account cleared. Push notifications are now disabled until you paste a key.</p></div>';
		} else {
			$decoded = json_decode( $raw, true );
			if ( empty( $decoded['private_key'] ) || empty( $decoded['client_email'] ) || empty( $decoded['project_id'] ) ) {
				$notice = '<div class="notice notice-error"><p>That doesn\'t look like a valid Firebase service account JSON (missing private_key / client_email / project_id). Nothing was saved.</p></div>';
			} else {
				// Store it. autoload = no, so it's not loaded on every page.
				update_option( 'schat_fcm_service_account', $raw, false );
				delete_transient( 'schat_fcm_access_token' ); // force a fresh token next send
				$notice = '<div class="notice notice-success"><p>Saved! Service account for project <code>' . esc_html( $decoded['project_id'] ) . '</code> is now active.</p></div>';
			}
		}
	}

	// --- Send a test push to the current admin ---
	if ( isset( $_POST['schat_push_test'] ) && check_admin_referer( 'schat_push_setup' ) ) {
		$me = get_current_user_id();
		$tokens = $wpdb->get_col( $wpdb->prepare(
			"SELECT fcm_token FROM {$wpdb->prefix}schat_device_tokens WHERE user_id = %d", $me
		) );
		if ( empty( $tokens ) ) {
			$notice = '<div class="notice notice-warning"><p>No device is registered to your admin account yet. Log into the Android app with this account first, then try the test.</p></div>';
		} else {
			$sent = false;
			foreach ( $tokens as $t ) {
				$ok = schat_fcm_send_to_token( $t, 'Test notification', 'Push is working end to end 🎉' );
				$sent = $sent || $ok;
			}
			$notice = $sent
				? '<div class="notice notice-success"><p>Test push sent. Check your device (make sure the app is in the background, not open on the chat).</p></div>'
				: '<div class="notice notice-error"><p>FCM rejected the test. Double-check the JSON is correct and current. If it keeps failing, the key may have been revoked in Firebase.</p></div>';
		}
	}

	// --- Current status ---
	$sa = schat_get_service_account();
	$configured = (bool) $sa;
	$token_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}schat_device_tokens" );

	echo '<div class="wrap">';
	echo '<h1>Push Setup</h1>';
	echo wp_kses_post( $notice );

	// Status box
	echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px 20px;margin:16px 0;max-width:760px;">';
	echo '<h2 style="margin-top:0;">Status</h2>';
	echo '<p>Service account: ' . ( $configured
		? '<strong style="color:#16a34a;">✓ Configured</strong> (project <code>' . esc_html( $sa['project_id'] ) . '</code>)'
		: '<strong style="color:#dc2626;">✗ Not configured</strong>' ) . '</p>';
	echo '<p>Registered devices: <strong>' . $token_count . '</strong></p>';
	echo '<p>openssl (required to sign requests): ' . ( function_exists( 'openssl_sign' )
		? '<strong style="color:#16a34a;">✓ Available</strong>'
		: '<strong style="color:#dc2626;">✗ Missing — contact your host</strong>' ) . '</p>';
	echo '</div>';

	// Paste form
	echo '<form method="post" style="max-width:760px;">';
	wp_nonce_field( 'schat_push_setup' );
	echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px 20px;margin:16px 0;">';
	echo '<h2 style="margin-top:0;">Firebase service account</h2>';
	echo '<p style="color:#555;">Paste the entire contents of your Firebase service account JSON file below. '
		. 'Get it from the <a href="https://console.firebase.google.com/" target="_blank" rel="noopener">Firebase Console</a> → '
		. 'Project Settings → Service Accounts → Generate new private key. The key is stored securely in your '
		. 'database — you do <strong>not</strong> need to upload any file to the server.</p>';

	// Show a masked preview if already configured, but never the private key.
	$placeholder = $configured
		? "A key is already saved for project {$sa['project_id']}.\nPaste a new one here to replace it, or leave blank and Save to clear it."
		: '{
  "type": "service_account",
  "project_id": "shatania",
  "private_key_id": "...",
  "private_key": "-----BEGIN PRIVATE KEY-----\n...",
  "client_email": "...@shatania.iam.gserviceaccount.com",
  ...
}';

	echo '<textarea name="schat_fcm_json" rows="12" style="width:100%;font-family:monospace;font-size:12px;" placeholder="' . esc_attr( $placeholder ) . '"></textarea>';
	echo '<p style="margin-top:14px;">';
	echo '<button type="submit" name="schat_push_save" class="button button-primary">Save service account</button> ';
	if ( $configured ) {
		echo '<button type="submit" name="schat_push_test" class="button">Send test push to me</button>';
	}
	echo '</p>';
	echo '</div>';
	echo '</form>';

	echo '<p style="color:#888;max-width:760px;font-size:13px;">Tip: if you ever rotate the key in Firebase (recommended if it was ever exposed), just paste the new JSON here and Save — the old one is replaced instantly, no server files to touch.</p>';
	echo '</div>';
}

/* ============================================================================
   APP DOWNLOAD — admin page to upload/replace the APK
   ============================================================================ */
function shat_admin_app_download() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$notice = '';
	$dir = shat_apk_dir();

	// Ensure the storage dir exists and is protected.
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	// Drop a hardening .htaccess: block directory listing and block direct
	// access to the raw file (it's only served through our download endpoint).
	$ht = $dir . '/.htaccess';
	if ( ! file_exists( $ht ) ) {
		@file_put_contents( $ht, "Options -Indexes\n<Files *.apk>\n    Require all denied\n</Files>\n" );
	}

	// --- Handle upload ---
	if ( isset( $_POST['shat_apk_nonce'] ) && wp_verify_nonce( $_POST['shat_apk_nonce'], 'shat_apk_upload' ) ) {
		if ( ! empty( $_FILES['shat_apk_file']['name'] ) ) {
			$f = $_FILES['shat_apk_file'];
			$name = $f['name'];
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

			if ( $f['error'] !== UPLOAD_ERR_OK ) {
				$notice = '<div class="notice notice-error"><p>Upload failed (error code ' . intval( $f['error'] ) . '). The file may exceed your server\'s upload size limit.</p></div>';
			} elseif ( $ext !== 'apk' ) {
				$notice = '<div class="notice notice-error"><p>That\'s not an .apk file. Please upload the Android app package.</p></div>';
			} else {
				// Remove any previous APK(s) so we don't accumulate old builds.
				foreach ( glob( $dir . '/*.apk' ) as $old ) { @unlink( $old ); }

				// Store with a fixed, unguessable-ish internal name.
				$stored = 'shatania-' . time() . '.apk';
				$dest   = $dir . '/' . $stored;

				if ( move_uploaded_file( $f['tmp_name'], $dest ) ) {
					update_option( 'shat_apk_filename', $stored, false );
					update_option( 'shat_apk_uploaded_at', current_time( 'mysql' ), false );
					update_option( 'shat_apk_version_label', sanitize_text_field( $_POST['shat_apk_version'] ?? '' ), false );
					$notice = '<div class="notice notice-success"><p>APK uploaded successfully. It\'s now live on the download screen.</p></div>';
				} else {
					$notice = '<div class="notice notice-error"><p>Could not save the uploaded file. Check folder permissions on wp-content/uploads.</p></div>';
				}
			}
		} elseif ( isset( $_POST['shat_apk_remove'] ) ) {
			foreach ( glob( $dir . '/*.apk' ) as $old ) { @unlink( $old ); }
			delete_option( 'shat_apk_filename' );
			$notice = '<div class="notice notice-warning"><p>APK removed. The download screen will show a &ldquo;coming soon&rdquo; message until you upload a new one.</p></div>';
		}
	}

	// --- Handle the access / Drive-link settings form ---
	if ( isset( $_POST['shat_access_nonce'] ) && wp_verify_nonce( $_POST['shat_access_nonce'], 'shat_access_save' ) ) {
		$drive = trim( (string) wp_unslash( $_POST['shat_apk_drive_url'] ?? '' ) );

		if ( $drive === '' ) {
			delete_option( 'shat_apk_drive_url' );
			$drive_msg = 'Google Drive link cleared.';
		} else {
			$clean = esc_url_raw( $drive );
			$host  = strtolower( (string) wp_parse_url( $clean, PHP_URL_HOST ) );
			if ( ! $clean || ! preg_match( '#^https?://#i', $clean ) ) {
				$notice = '<div class="notice notice-error"><p>That doesn\'t look like a valid link. Paste the full URL, starting with <code>https://</code>.</p></div>';
				$drive_msg = '';
			} else {
				update_option( 'shat_apk_drive_url', $clean, false );
				$drive_msg = 'Download link saved.';
				/* Not a hard requirement — any direct link works — but a
				   non-Drive host is worth flagging in case of a paste slip. */
				if ( strpos( $host, 'google.com' ) === false && strpos( $host, 'googleusercontent.com' ) === false ) {
					$drive_msg .= ' (Heads up: that isn\'t a Google Drive address — double-check it\'s the link you meant.)';
				}
			}
		}

		update_option( 'shat_browser_access', empty( $_POST['shat_browser_access'] ) ? '0' : '1', false );
		update_option( 'schat_admin_email_notify', empty( $_POST['schat_admin_email_notify'] ) ? '0' : '1', false );

		if ( $notice === '' ) {
			$notice = '<div class="notice notice-success"><p>Settings saved. ' . esc_html( $drive_msg ) . '</p></div>';
		}
	}

	$current = shat_apk_stored_filename();
	$url     = shat_apk_download_url();
	$uploaded_at = get_option( 'shat_apk_uploaded_at', '' );
	$ver     = get_option( 'shat_apk_version_label', '' );
	$drive_url   = shat_apk_drive_url();
	$web_open    = shat_browser_access_enabled();
	$email_on    = schat_admin_email_enabled();

	// Figure out the server's max upload size, to warn if it's too small.
	$max_upload = min(
		wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ),
		wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) )
	);
	$max_mb = round( $max_upload / 1048576 );

	echo '<div class="wrap">';
	echo '<h1>App Download</h1>';
	echo wp_kses_post( $notice );

	/* ---- Google Drive link + access settings (the main controls now) ---- */
	echo '<form method="post" style="max-width:760px;">';
	wp_nonce_field( 'shat_access_save', 'shat_access_nonce' );
	echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px 20px;margin:16px 0;">';

	echo '<h2 style="margin-top:0;">Google Drive download link</h2>';
	echo '<p style="color:#555;">Upload the latest <code>.apk</code> to your Google Drive, set it to <strong>&ldquo;Anyone with the link&rdquo;</strong>, then paste the share link here. Members see this link on the feed and after signing up.</p>';
	echo '<p><input type="url" name="shat_apk_drive_url" value="' . esc_attr( $drive_url ) . '" placeholder="https://drive.google.com/file/d/.../view?usp=sharing" style="width:100%;max-width:700px;" spellcheck="false"></p>';
	if ( $drive_url ) {
		echo '<p style="color:#16a34a;">&#10003; Live &mdash; <a href="' . esc_url( $drive_url ) . '" target="_blank" rel="noopener">test the link</a></p>';
	} else {
		echo '<p style="color:#666;font-size:13px;">Leave empty to fall back to an APK uploaded below (if any).</p>';
	}
	echo '<p style="color:#888;font-size:13px;">To ship an update: replace the file in Drive (or upload a new one and paste the new link). Nothing else needs changing.</p>';

	echo '<hr style="margin:22px 0;border:0;border-top:1px solid #eee;">';

	echo '<h2>Who can see the feed</h2>';
	echo '<p><label><input type="checkbox" name="shat_browser_access" value="1"' . checked( $web_open, true, false ) . '> '
		. '<strong>Let members read the feed in a normal browser</strong></label></p>';
	echo '<p style="color:#555;margin-left:24px;">On (recommended): approved members get the full feed on any device, and the app is offered as a banner at the top. Off: only the Android app can open the feed &mdash; everyone else hits the download screen. You, as admin, always get in either way.</p>';

	echo '<hr style="margin:22px 0;border:0;border-top:1px solid #eee;">';

	echo '<h2>Email me when a member messages</h2>';
	echo '<p><label><input type="checkbox" name="schat_admin_email_notify" value="1"' . checked( $email_on, true, false ) . '> '
		. 'Send an email to <code>' . esc_html( SCHAT_ADMIN_EMAIL ) . '</code> for every new member message</label></p>';
	echo '<p style="color:#555;margin-left:24px;">Off by default. Messages still arrive in <strong>Shatania &rarr; Chat</strong>, and push notifications still fire if Push Setup is configured &mdash; your inbox just stays quiet.</p>';

	echo '<p style="margin-top:18px;"><button type="submit" class="button button-primary">Save settings</button></p>';
	echo '</div>';
	echo '</form>';

	// Status
	echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px 20px;margin:16px 0;max-width:760px;">';
	echo '<h2 style="margin-top:0;">Current app</h2>';
	if ( $url ) {
		echo '<p>Status: <strong style="color:#16a34a;">✓ Download link is live</strong> '
			. ( $drive_url ? '(Google Drive)' : '(self-hosted APK)' ) . '</p>';
		if ( ! $drive_url && $ver )         echo '<p>Version label: <code>' . esc_html( $ver ) . '</code></p>';
		if ( ! $drive_url && $uploaded_at ) echo '<p>Uploaded: ' . esc_html( $uploaded_at ) . '</p>';
		echo '<p>Link members get: <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a></p>';
		if ( $drive_url && $current ) {
			echo '<p style="color:#b45309;font-size:13px;">An APK is also uploaded to this server, but the Google Drive link takes priority. Clear the link above to go back to the uploaded file.</p>';
		}
	} else {
		echo '<p>Status: <strong style="color:#dc2626;">✗ No download link yet</strong></p>';
		echo '<p style="color:#666;">Paste a Google Drive link above (or upload an APK below). Until then members see a &ldquo;coming soon&rdquo; message instead of a download button.</p>';
	}
	echo '</div>';

	// Upload form
	echo '<form method="post" enctype="multipart/form-data" style="max-width:760px;">';
	wp_nonce_field( 'shat_apk_upload', 'shat_apk_nonce' );
	echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px 20px;margin:16px 0;">';
	echo '<h2 style="margin-top:0;">Upload a new APK <span style="font-weight:400;color:#888;font-size:14px;">(optional fallback)</span></h2>';
	echo '<p style="color:#555;">Only needed if you would rather host the app on this server than on Google Drive. A Drive link, if one is set above, always wins over anything uploaded here.</p>';
	echo '<p style="color:#555;">Select your built <code>.apk</code> file. Uploading a new one replaces the current app immediately.</p>';
	echo '<p><input type="file" name="shat_apk_file" accept=".apk"></p>';
	echo '<p><label>Optional version label (e.g. "1.0"): <input type="text" name="shat_apk_version" value="" style="width:120px;"></label></p>';

	if ( $max_mb < 60 ) {
		echo '<p style="color:#b45309;font-size:13px;">⚠ Your server\'s max upload size is about ' . esc_html( $max_mb ) . ' MB. APKs can be larger than this — if the upload fails, ask your host to raise <code>upload_max_filesize</code> and <code>post_max_size</code>, or upload the APK via FTP into <code>' . esc_html( str_replace( ABSPATH, '', $dir ) ) . '</code> and rename it, then set the option manually.</p>';
	}

	echo '<p style="margin-top:14px;"><button type="submit" class="button button-primary">Upload APK</button></p>';
	echo '</div>';
	echo '</form>';

	// Remove form
	if ( $current ) {
		echo '<form method="post" style="max-width:760px;" onsubmit="return confirm(\'Remove the current APK? The download screen will show coming-soon until you upload a new one.\');">';
		wp_nonce_field( 'shat_apk_upload', 'shat_apk_nonce' );
		echo '<input type="hidden" name="shat_apk_remove" value="1">';
		echo '<button type="submit" class="button button-link-delete" style="color:#b32d2e;">Remove current APK</button>';
		echo '</form>';
	}

	echo '</div>';

	// App-gate secret — the value the app must send to unlock the feed.
	echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px 20px;margin:16px 0;max-width:760px;">';
	echo '<h2 style="margin-top:0;">App verification secret</h2>';
	echo '<p style="color:#555;">This is the secret the Android app sends to prove the feed is being opened inside the app. It\'s already baked into the app build I set up. You only need this if you rebuild the app or want to rotate the secret.</p>';
	echo '<p><code style="background:#f6f6f6;padding:6px 10px;border-radius:6px;display:inline-block;user-select:all;">' . esc_html( shat_app_secret() ) . '</code></p>';
	echo '<p style="color:#888;font-size:13px;">If you ever change this, you must update it in the app\'s code too (the <code>APP_SECRET</code> value) and rebuild — otherwise the app won\'t be able to open the feed.</p>';
	echo '</div>';
}

// ---- Data access ----
/* Fetch a slice of a thread.

   Three modes:
     $after_id  > 0  — live polling: everything newer than that id.
     $before_id > 0  — back-paging: the $limit messages immediately older
                       than that id (used when scrolling up).
     neither         — initial open: the newest $limit messages.

   $limit defaults to SCHAT_PAGE_SIZE rather than the whole thread, so a
   long conversation no longer ships hundreds of rows on every open. */
function schat_get_thread( $member_id, $after_id = 0, $limit = 0, $before_id = 0 ) {
	global $wpdb;
	$member_id = intval( $member_id );
	$after_id  = intval( $after_id );
	$before_id = intval( $before_id );
	$limit     = intval( $limit ) > 0 ? intval( $limit ) : SCHAT_PAGE_SIZE;

	if ( $after_id > 0 ) {
		/* Live poll. Still capped: without a LIMIT, a client that was
		   offline for a long time would pull the entire backlog at once. */
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}schat_messages
			 WHERE member_id = %d AND id > %d AND is_deleted = 0
			 ORDER BY id ASC LIMIT %d",
			$member_id, $after_id, $limit
		) );
	} elseif ( $before_id > 0 ) {
		/* Back-page: walk backwards from the oldest row the client holds,
		   then flip to chronological order for rendering. */
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}schat_messages
			 WHERE member_id = %d AND id < %d AND is_deleted = 0
			 ORDER BY id DESC LIMIT %d",
			$member_id, $before_id, $limit
		) );
		$rows = array_reverse( $rows );
	} else {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}schat_messages
			 WHERE member_id = %d AND is_deleted = 0
			 ORDER BY id DESC LIMIT %d",
			$member_id, $limit
		) );
		$rows = array_reverse( $rows );
	}
	return $rows;
}

/* Is there anything older than $before_id in this thread?
   Drives the client's "load more on scroll-up" behaviour, so it can stop
   asking once the top is reached. EXISTS-style LIMIT 1 — no COUNT(*)
   over the whole thread. */
function schat_has_older( $member_id, $before_id ) {
	global $wpdb;
	$before_id = intval( $before_id );
	if ( $before_id <= 0 ) return false;
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}schat_messages
		 WHERE member_id = %d AND id < %d AND is_deleted = 0
		 ORDER BY id DESC LIMIT 1",
		intval( $member_id ), $before_id
	) );
}

function schat_thread_exists( $member_id ) {
	global $wpdb;
	$member_id = intval( $member_id );
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}schat_messages WHERE member_id = %d AND is_deleted = 0",
		$member_id
	) );
}

/* ------------------------------------------------------------------
   Typing indicator. A row is upserted on each keystroke (throttled
   client-side) and considered "live" for 6 seconds. No cron needed —
   stale rows are simply ignored and overwritten.
   ------------------------------------------------------------------ */
function schat_set_typing( $member_id, $is_admin ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}schat_typing (member_id, is_admin, updated_at)
		 VALUES (%d, %d, %s)
		 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
		intval( $member_id ), $is_admin ? 1 : 0, gmdate( 'Y-m-d H:i:s' )
	) );
}

function schat_clear_typing( $member_id, $is_admin ) {
	global $wpdb;
	$wpdb->delete( "{$wpdb->prefix}schat_typing", [
		'member_id' => intval( $member_id ),
		'is_admin'  => $is_admin ? 1 : 0,
	] );
}

// Is the OTHER side currently typing in this thread?
function schat_is_other_typing( $member_id, $viewer_is_admin ) {
	global $wpdb;
	$other = $viewer_is_admin ? 0 : 1;
	$ts = $wpdb->get_var( $wpdb->prepare(
		"SELECT updated_at FROM {$wpdb->prefix}schat_typing WHERE member_id = %d AND is_admin = %d",
		intval( $member_id ), $other
	) );
	if ( ! $ts ) return false;
	return ( strtotime( gmdate( 'Y-m-d H:i:s' ) ) - strtotime( $ts ) ) <= 6;
}

/* ------------------------------------------------------------------
   LIVE ACTIVITY — a richer presence than "typing / not typing".

   Four states travel to the other side:
     typing     — mid-keystroke
     scrolling  — reading back through the thread
     viewing    — thread open, sitting still
     away       — thread closed, tab hidden, or gone quiet long enough
                  that we stop believing the last ping

   The row persists after someone leaves so its updated_at doubles as a
   "last active" stamp. Writes are UTC (gmdate) to match schat_typing and
   the message timestamps — the client renders everything in local time.
   ------------------------------------------------------------------ */
function schat_activity_states() {
	return [ 'typing', 'scrolling', 'viewing', 'away' ];
}

function schat_set_activity( $member_id, $is_admin, $state ) {
	global $wpdb;
	if ( ! in_array( $state, schat_activity_states(), true ) ) $state = 'viewing';
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}schat_activity (member_id, is_admin, state, updated_at)
		 VALUES (%d, %d, %s, %s)
		 ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = VALUES(updated_at)",
		intval( $member_id ), $is_admin ? 1 : 0, $state, gmdate( 'Y-m-d H:i:s' )
	) );
}

function schat_clear_activity( $member_id, $is_admin = null ) {
	global $wpdb;
	$where = [ 'member_id' => intval( $member_id ) ];
	if ( $is_admin !== null ) $where['is_admin'] = $is_admin ? 1 : 0;
	$wpdb->delete( "{$wpdb->prefix}schat_activity", $where );
}

/**
 * What is the OTHER side doing in this thread right now?
 *
 * Returns [ 'state' => one of schat_activity_states(), 'ago' => human
 * string or '' ]. 'ago' is only filled in when they're away and we have a
 * timestamp worth showing.
 */
function schat_other_activity( $member_id, $viewer_is_admin ) {
	global $wpdb;
	$other = $viewer_is_admin ? 0 : 1;

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT state, updated_at FROM {$wpdb->prefix}schat_activity
		  WHERE member_id = %d AND is_admin = %d",
		intval( $member_id ), $other
	) );

	$state = 'away';
	$stamp = 0;

	if ( $row ) {
		$stamp = strtotime( $row->updated_at . ' UTC' );
		$age   = time() - $stamp;

		if ( $row->state === 'away' ) {
			$state = 'away';
		} elseif ( $row->state === 'typing' && $age <= SCHAT_ACT_TYPING_TTL ) {
			$state = 'typing';
		} elseif ( $row->state === 'scrolling' && $age <= SCHAT_ACT_SCROLL_TTL ) {
			$state = 'scrolling';
		} elseif ( $age <= SCHAT_ACT_VIEW_TTL ) {
			/* Still here, just settled — a typing or scrolling ping that has
			   aged past its own window still proves they're present. */
			$state = 'viewing';
		}
	}

	/* The typing table stays the authority for the bouncing-dots bubble.
	   Defer to it so the header line and the bubble can never disagree. */
	if ( $state !== 'away' && schat_is_other_typing( $member_id, $viewer_is_admin ) ) {
		$state = 'typing';
	}

	$ago = '';
	if ( $state === 'away' && $stamp > 0 ) {
		$gap = time() - $stamp;
		if ( $gap < 60 )                    $ago = 'just now';
		elseif ( $gap < 30 * DAY_IN_SECONDS ) $ago = human_time_diff( $stamp ) . ' ago';
	}

	return [ 'state' => $state, 'ago' => $ago ];
}

/* ------------------------------------------------------------------
   CELEBRATION — the confetti button.

   Deliberately ephemeral: a celebration is a moment, not a message, so
   it never touches schat_messages and leaves no history behind. The
   latest one per thread lives in a short transient; each side's poll
   picks it up once, plays it, and remembers the sequence number so a
   reopened panel never replays an old party.
   ------------------------------------------------------------------ */
function schat_celebration_key( $member_id ) {
	return 'schat_party_' . intval( $member_id );
}

/** Records a celebration and returns its sequence number (ms since epoch). */
function schat_push_celebration( $member_id, $is_admin ) {
	$seq = (int) round( microtime( true ) * 1000 );
	set_transient( schat_celebration_key( $member_id ), [
		'seq' => $seq,
		'by'  => $is_admin ? 1 : 0,
	], 2 * MINUTE_IN_SECONDS );
	return $seq;
}

/** The pending celebration for a thread, or null. */
function schat_get_celebration( $member_id ) {
	$v = get_transient( schat_celebration_key( $member_id ) );
	if ( ! is_array( $v ) || empty( $v['seq'] ) ) return null;
	return [ 'seq' => (float) $v['seq'], 'by_admin' => intval( $v['by'] ) ];
}

/* ------------------------------------------------------------------
   Seen receipts: the highest message id *I* sent that the other side
   has already read. Drives the "Seen" label under the last bubble.
   ------------------------------------------------------------------ */
function schat_last_seen_id( $member_id, $viewer_is_admin ) {
	global $wpdb;
	$mine = $viewer_is_admin ? 1 : 0;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}schat_messages
		 WHERE member_id = %d AND sender_is_admin = %d AND read_at IS NOT NULL AND is_deleted = 0",
		intval( $member_id ), $mine
	) );
}

// Admin-only: permanently remove an entire conversation (messages +
// their uploaded photos + typing rows). This is a HARD delete.
function schat_delete_thread( $member_id ) {
	global $wpdb;
	$member_id = intval( $member_id );
	if ( ! $member_id ) return 0;

	$images = $wpdb->get_col( $wpdb->prepare(
		"SELECT image_url FROM {$wpdb->prefix}schat_messages WHERE member_id = %d AND image_url <> ''",
		$member_id
	) );
	foreach ( $images as $url ) {
		$path = SCHAT_UPLOAD_DIR . basename( parse_url( $url, PHP_URL_PATH ) );
		if ( $path && file_exists( $path ) ) @unlink( $path );
	}

	$wpdb->delete( "{$wpdb->prefix}schat_typing", [ 'member_id' => $member_id ] );
	schat_clear_activity( $member_id );
	delete_transient( schat_celebration_key( $member_id ) );
	return $wpdb->delete( "{$wpdb->prefix}schat_messages", [ 'member_id' => $member_id ] );
}

// Admin-only: soft-delete a message. Members can never call this on their
// own messages — enforced in the AJAX handler, not just here.
function schat_delete_message( $message_id ) {
	global $wpdb;
	return $wpdb->update(
		"{$wpdb->prefix}schat_messages",
		[ 'is_deleted' => 1 ],
		[ 'id' => intval( $message_id ) ]
	);
}

function schat_unread_count_for_member( $member_id ) {
	global $wpdb;
	$member_id = intval( $member_id );
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}schat_messages
		 WHERE member_id = %d AND sender_is_admin = 1 AND read_at IS NULL AND is_deleted = 0",
		$member_id
	) );
}

function schat_unread_count_for_admin( $member_id = 0 ) {
	global $wpdb;
	if ( $member_id ) {
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}schat_messages
			 WHERE member_id = %d AND sender_is_admin = 0 AND read_at IS NULL AND is_deleted = 0",
			intval( $member_id )
		) );
	}
	return (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}schat_messages
		 WHERE sender_is_admin = 0 AND read_at IS NULL AND is_deleted = 0"
	);
}

/**
 * ID of the oldest message the viewer hasn't read yet, in this thread.
 * Drives the "New Messages" divider on the client. Must be read BEFORE
 * schat_mark_read() runs, or everything is already marked read and this
 * returns 0.
 *
 * @param int  $member_id       Thread owner.
 * @param bool $viewer_is_admin True when the admin is viewing.
 * @return int Message id, or 0 when there is nothing unread.
 */
function schat_first_unread_id( $member_id, $viewer_is_admin ) {
	global $wpdb;
	// The viewer's unread messages are the ones the OTHER side sent.
	$sender_flag = $viewer_is_admin ? 0 : 1;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT MIN(id) FROM {$wpdb->prefix}schat_messages
		 WHERE member_id = %d AND sender_is_admin = %d
		   AND read_at IS NULL AND is_deleted = 0",
		intval( $member_id ), $sender_flag
	) );
}

function schat_mark_read( $member_id, $as_admin ) {
	global $wpdb;
	$member_id   = intval( $member_id );
	$sender_flag = $as_admin ? 0 : 1;
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}schat_messages
		 SET read_at = %s
		 WHERE member_id = %d AND sender_is_admin = %d AND read_at IS NULL",
		gmdate( 'Y-m-d H:i:s' ), $member_id, $sender_flag
	) );
}

function schat_get_all_threads() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT member_id,
		        MAX(sent_at) AS last_sent_at,
		        SUM(CASE WHEN sender_is_admin = 0 AND read_at IS NULL THEN 1 ELSE 0 END) AS unread
		 FROM {$wpdb->prefix}schat_messages
		 WHERE is_deleted = 0
		 GROUP BY member_id
		 ORDER BY last_sent_at DESC"
	);
	$out = [];
	foreach ( $rows as $row ) {
		$user = get_user_by( 'id', $row->member_id );
		if ( ! $user ) continue;
		$last_msg = $wpdb->get_row( $wpdb->prepare(
			"SELECT message, image_url, sender_is_admin FROM {$wpdb->prefix}schat_messages
			 WHERE member_id = %d AND is_deleted = 0 ORDER BY id DESC LIMIT 1",
			$row->member_id
		) );
		$out[] = [
			'member_id' => (int) $row->member_id,
			'name'      => $user->display_name,
			'email'     => $user->user_email,
			'unread'    => (int) $row->unread,
			'last_at'   => $row->last_sent_at,
			'last_msg'  => $last_msg,
		];
	}
	return $out;
}

function schat_get_members_without_thread() {
	global $wpdb;
	$existing_ids = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT member_id FROM {$wpdb->prefix}schat_messages" ) );

	$users = get_users( [ 'fields' => [ 'ID', 'display_name', 'user_email' ] ] );
	$out = [];
	foreach ( $users as $u ) {
		if ( in_array( (int) $u->ID, $existing_ids, true ) ) continue;
		if ( schat_is_site_admin( $u->ID ) ) continue;
		if ( ! shat_is_approved( $u->ID ) ) continue;
		$out[] = $u;
	}
	return $out;
}

function schat_time_label( $datetime_str ) {
	if ( empty( $datetime_str ) ) return '';
	$ts   = strtotime( $datetime_str . ' UTC' );
	$now  = time();
	$diff = $now - $ts;

	if ( $diff < 60 ) return 'now';
	if ( $diff < 3600 ) return floor( $diff / 60 ) . 'm';
	if ( date( 'Y-m-d', $ts ) === date( 'Y-m-d', $now ) ) return date( 'g:i a', $ts );
	if ( $diff < 172800 ) return 'Yesterday';
	return date( 'M j', $ts );
}

// ---- Front-end assets ----
// Everything (CSS + JS + config) is bundled into ONE function that
// gets called directly from inside the shortcode output itself
// (schat_sc_button() / schat_render_admin_front_widget()), rather
// than relying on wp_head/wp_footer/wp_enqueue_scripts firing at the
// right time. A shortcode's own output always reaches the page if the
// shortcode is being processed at all — this removes every hook-order
// or theme-template dependency as a possible failure point.
add_action( 'wp_enqueue_scripts', 'schat_enqueue_front' );
function schat_enqueue_front() {
	if ( ! is_user_logged_in() ) return;
	wp_enqueue_script( 'jquery' ); // still needed: WordPress core, always registered
}

/**
 * Returns the full <style> + config <script> + behavior <script> block,
 * ready to be echoed directly inline wherever the chat widget renders.
 * Guarded by a static flag so it only prints once per page even if
 * called from multiple places (e.g. button + panel on the same page).
 */
function schat_inline_assets() {
	static $printed = false;
	if ( $printed ) return '';
	$printed = true;

	$config = [
		'ajaxurl'     => admin_url( 'admin-ajax.php' ),
		'nonce'       => wp_create_nonce( 'schat_nonce' ),
		'is_admin'    => schat_is_site_admin() ? 1 : 0,
		'my_user_id'  => get_current_user_id(),
		'max_file_mb' => SCHAT_MAX_FILE_MB,
		/* PHP's own ceiling, which is often lower than ours and is what
		   actually bites. A POST over post_max_size is discarded by PHP
		   before WordPress runs at all, so the nonce check fails and the
		   member sees a confusing auth error instead of "too large". */
		'php_upload_mb' => schat_php_upload_limit_mb(),
	];

	$out  = schat_css_string();
	$out .= schat_polish_css();
	$out .= schat_chat_wallpaper_css();
	$out .= schat_celebration_css();
	$out .= '<script>var schatAjax = ' . wp_json_encode( $config ) . ';</script>';
	$out .= '<script>' . schat_js_string() . '</script>';
	return $out;
}

// Kept as a no-op wrapper so any remaining call sites don't error;
// styling is now bundled into schat_inline_assets() above.
function schat_css() { /* intentionally empty — see schat_inline_assets() */ }

/**
 * Presence line + celebration styling (1.5.0).
 *
 * Kept in its own block rather than folded into the main stylesheet so
 * the two new features can be read — and adjusted — without scrolling
 * through two thousand lines of existing chat CSS.
 *
 * Everything animates on transform/opacity only, which is what keeps it
 * smooth inside the Android WebView on a mid-range phone.
 */
function schat_celebration_css() {
	return <<<'SCHATPARTY'
<style id="schat-party">
/* ============================================================
   PRESENCE LINE — the header subtitle, now live
   ============================================================ */
.schat-header .schat-h-sub.schat-pres{
	display:flex !important;
	align-items:center !important;
	gap:6px !important;
	min-height:15px !important;
}
.schat-pres .schat-pres-txt{
	overflow:hidden !important;
	text-overflow:ellipsis !important;
	white-space:nowrap !important;
	transition:opacity .22s ease !important;
}
.schat-pres.is-swapping .schat-pres-txt{ opacity:0 !important; }

.schat-pres .schat-pres-dot{
	flex:0 0 auto !important;
	width:7px !important; height:7px !important;
	border-radius:50% !important;
	background:var(--sec,#9A9A9A) !important;
	opacity:0 !important;
	/* Left non-important so the scrolling state's drift keyframe can move
	   it — an important transform here would freeze every animated dot. */
	transform:scale(.6);
	transition:opacity .25s ease, transform .25s ease, background .25s ease, box-shadow .25s ease !important;
}
/* Unknown = first paint, before any poll has landed. No dot at all. */
.schat-pres.schat-pres-unknown .schat-pres-dot{ opacity:0 !important; }

.schat-pres.schat-pres-away .schat-pres-dot{
	opacity:1 !important; transform:scale(1) !important;
	background:rgba(255,255,255,.26) !important;
}
.schat-pres.schat-pres-viewing .schat-pres-dot{
	opacity:1 !important; transform:scale(1) !important;
	background:#5BD08A !important;
	box-shadow:0 0 0 3px rgba(91,208,138,.16) !important;
}
.schat-pres.schat-pres-scrolling .schat-pres-dot{
	opacity:1 !important; transform:scale(1);
	background:#F3C969 !important;
	box-shadow:0 0 0 3px rgba(243,201,105,.16) !important;
	animation:schat-pres-drift 1.5s ease-in-out infinite !important;
}
.schat-pres.schat-pres-typing .schat-pres-dot{
	opacity:1 !important; transform:scale(1) !important;
	background:var(--gold,#D9A9AC) !important;
	animation:schat-pres-pulse 1.25s ease-in-out infinite !important;
}
@keyframes schat-pres-pulse{
	0%,100%{ box-shadow:0 0 0 0 rgba(217,169,172,.5); }
	70%    { box-shadow:0 0 0 6px rgba(217,169,172,0); }
}
@keyframes schat-pres-drift{
	0%,100%{ transform:scale(1) translateY(0); }
	50%    { transform:scale(1) translateY(-2px); }
}

/* The live states earn a slightly brighter line than "away". */
.schat-pres.schat-pres-typing .schat-pres-txt{ color:var(--gold,#D9A9AC) !important; }
.schat-pres.schat-pres-viewing .schat-pres-txt,
.schat-pres.schat-pres-scrolling .schat-pres-txt{ color:#C9C9C9 !important; }

/* ============================================================
   COMPOSER ICON BUTTONS — restore the 36px circular box

   The theme-proofing reset near the top of the main stylesheet,
   `.schat-root button{ all:unset !important }`, has specificity (0,1,1)
   and therefore OUTRANKS `.schat-icon-btn{...}` at (0,1,0) — !important
   on both sides, so specificity decides. Any icon button without a rule
   of its own at (0,2,0) or better collapses to its content size with
   border-radius 0: a square hover fill, sitting lower than its
   neighbours because the row is bottom-aligned.

   .schat-photo-btn and .schat-send-btn each happen to carry such a rule
   already, which is why only the newer button looked wrong. This covers
   every icon button in the composer instead of patching them one by one,
   so the next one added inherits a correct box for free.

   :not(.schat-send-btn) keeps the send button's gold fill — it is also
   a .schat-icon-btn, and would otherwise be flattened to transparent.
   ============================================================ */
.schat-root .schat-input-row .schat-icon-btn:not(.schat-send-btn){
	width:36px !important; height:36px !important;
	min-width:36px !important; min-height:36px !important;
	flex:0 0 36px !important;
	padding:0 !important; margin:0 !important; border:0 !important;
	border-radius:50% !important;
	background:transparent !important; background-image:none !important;
	box-shadow:none !important;
	color:var(--sec,#9A9A9A) !important;
	display:inline-flex !important;
	align-items:center !important; justify-content:center !important;
	align-self:flex-end !important;
	overflow:hidden !important;
	line-height:1 !important;
	cursor:pointer !important;
	-webkit-appearance:none !important; appearance:none !important;
	transition:background-color .18s ease, color .18s ease !important;
}
/* The hover fill needs the radius restated: the base :hover rule sets
   only a background, so without this it paints into a square box. */
.schat-root .schat-input-row .schat-icon-btn:not(.schat-send-btn):hover,
.schat-root .schat-input-row .schat-icon-btn:not(.schat-send-btn):focus,
.schat-root .schat-input-row .schat-icon-btn:not(.schat-send-btn):active{
	border-radius:50% !important;
	background:rgba(255,255,255,.08) !important;
	background-image:none !important;
	box-shadow:none !important;
	outline:none !important;
}

/* ============================================================
   CELEBRATION BUTTON
   ============================================================ */
.schat-input-row .schat-party-btn{ position:relative !important; }
/* A touch of gold on hover — the one button whose whole job is joy. */
.schat-root .schat-input-row .schat-party-btn:not(.schat-send-btn):hover{
	color:var(--gold,#D9A9AC) !important;
}
/* Matched to .schat-photo-btn svg exactly (21px / 1.8 stroke) so the two
   composer icons sit on the same optical line and read the same weight.
   The base .schat-icon-btn svg rule is 22px, which is a visible mismatch
   right next to the photo button. */
.schat-input-row .schat-party-btn svg{
	width:21px !important; height:21px !important;
	stroke-width:1.8 !important;
	display:block !important;
	transition:transform .2s cubic-bezier(.2,.8,.3,1) !important;
}
.schat-input-row .schat-party-btn:hover svg{ transform:rotate(-12deg) scale(1.06); }
.schat-input-row .schat-party-btn:disabled{ opacity:.45 !important; }
.schat-input-row .schat-party-btn.pop svg{
	animation:schat-party-pop .55s cubic-bezier(.2,.9,.3,1) !important;
	color:var(--gold,#D9A9AC) !important;
}
@keyframes schat-party-pop{
	0%  { transform:scale(1) rotate(0deg); }
	35% { transform:scale(1.32) rotate(-16deg); }
	70% { transform:scale(.94) rotate(8deg); }
	100%{ transform:scale(1) rotate(0deg); }
}

/* ============================================================
   CELEBRATION OVERLAY
   Lives inside .schat-panel, above the messages, and never takes
   pointer events so the chat stays usable mid-party.
   ============================================================ */
/* The panel already contains absolutely-positioned children by virtue of
   its transform, but state it outright — a future transform:none would
   otherwise quietly let the confetti escape to the viewport. */
.schat-overlay .schat-panel{ position:relative !important; }

.schat-party{
	position:absolute !important; inset:0 !important;
	overflow:hidden !important;
	pointer-events:none !important;
	z-index:60 !important;
	border-radius:inherit !important;
}
.schat-party-flash{
	position:absolute !important; inset:0 !important;
	background:radial-gradient(58% 44% at 50% 56%, rgba(217,169,172,.30) 0%, rgba(217,169,172,0) 72%) !important;
	animation:schat-flash 1200ms ease-out forwards !important;
}
@keyframes schat-flash{
	0%  { opacity:0; transform:scale(.72); }
	18% { opacity:1; transform:scale(1); }
	100%{ opacity:0; transform:scale(1.18); }
}

.schat-confetti{
	position:absolute !important;
	top:-24px !important;
	left:var(--x) !important;
	width:var(--w) !important;
	height:var(--h) !important;
	background:var(--c) !important;
	border-radius:var(--r) !important;
	/* NOT !important: an important author declaration outranks a keyframe,
	   which would pin these at opacity 0 and show nothing at all. */
	opacity:0;
	will-change:transform,opacity !important;
	animation:schat-fall var(--d) cubic-bezier(.18,.5,.32,1) var(--delay) forwards !important;
}
@keyframes schat-fall{
	0%  { opacity:0; transform:translate3d(0,0,0) rotate(0deg); }
	10% { opacity:1; }
	85% { opacity:1; }
	100%{ opacity:0; transform:translate3d(var(--dx), var(--fall), 0) rotate(var(--rot)); }
}

.schat-heartp{
	position:absolute !important;
	bottom:-26px !important;
	left:var(--x) !important;
	width:var(--s) !important;
	height:var(--s) !important;
	color:var(--c) !important;
	opacity:0; /* see the note on .schat-confetti */
	will-change:transform,opacity !important;
	animation:schat-rise var(--d) cubic-bezier(.25,.6,.35,1) var(--delay) forwards !important;
}
/* The main stylesheet carries `.schat-root svg{ fill:none !important }` to
   stop the theme colouring in the outline icons. Every icon in this chat is
   a stroke drawing, so that is right for all of them — except the heart,
   which is a solid shape with no stroke at all. Without this it renders as
   nothing while still occupying its box. Scoped at (0,2,1) so it beats the
   reset outright rather than relying on which stylesheet is printed last. */
.schat-root .schat-heartp svg,
.schat-root .schat-party-badge svg{
	fill:currentColor !important;
	stroke:none !important;
}
.schat-heartp svg{ width:100% !important; height:100% !important; display:block !important; }
@keyframes schat-rise{
	0%  { opacity:0; transform:translate3d(0,0,0) scale(.35) rotate(0deg); }
	14% { opacity:1; transform:translate3d(0,-8%,0) scale(1) rotate(-6deg); }
	72% { opacity:1; }
	100%{ opacity:0; transform:translate3d(var(--dx), var(--rise), 0) scale(1.18) rotate(var(--rot)); }
}

.schat-party-badge{
	position:absolute !important;
	left:50% !important; top:44% !important;
	transform:translate(-50%,-50%) scale(.6); /* animated — see .schat-confetti */
	display:flex !important; align-items:center !important; gap:9px !important;
	padding:12px 20px !important;
	border-radius:999px !important;
	background:rgba(28,22,23,.82) !important;
	border:1px solid rgba(217,169,172,.34) !important;
	box-shadow:0 14px 44px rgba(0,0,0,.45), 0 0 0 1px rgba(255,255,255,.04) inset !important;
	backdrop-filter:blur(8px) !important;
	-webkit-backdrop-filter:blur(8px) !important;
	color:#FFF !important;
	font-family:"Sen", sans-serif !important;
	font-size:14.5px !important; font-weight:600 !important;
	letter-spacing:.2px !important;
	white-space:nowrap !important;
	opacity:0; /* animated — see .schat-confetti */
	animation:schat-badge 2100ms cubic-bezier(.2,.85,.3,1) forwards !important;
}
.schat-party-badge svg{
	width:19px !important; height:19px !important;
	color:var(--gold,#D9A9AC) !important; flex:0 0 auto !important;
}
@keyframes schat-badge{
	0%  { opacity:0; transform:translate(-50%,-50%) scale(.6); }
	14% { opacity:1; transform:translate(-50%,-50%) scale(1.06); }
	24% { transform:translate(-50%,-50%) scale(1); }
	76% { opacity:1; transform:translate(-50%,-50%) scale(1); }
	100%{ opacity:0; transform:translate(-50%,-62%) scale(.96); }
}

/* Anyone who asked the OS for less motion gets the moment without the
   movement: the glow and the badge, no falling or flying pieces. */
/* ============================================================
   REACTION — the message takes the stage

   A copy of the bubble glides to the centre of the screen, gets a heart
   and a small confetti puff, then eases back into its place in the
   thread. Everything moves on transform/opacity only, and the layer
   never takes pointer events, so the chat stays usable throughout.
   ============================================================ */
.schat-lovestage{
	position:fixed !important;
	inset:0 !important;
	z-index:100050 !important;
	pointer-events:none !important;
	overflow:hidden !important;
}

/* A soft dim so the centred message is readable over the thread. Gentle
   on purpose — this is a moment, not a modal. */
.schat-lovescrim{
	position:absolute !important; inset:0 !important;
	background:radial-gradient(70% 50% at 50% 50%, rgba(12,8,9,.52) 0%, rgba(12,8,9,.30) 55%, rgba(12,8,9,0) 100%) !important;
	opacity:0 !important;
	transition:opacity .42s ease !important;
}
.schat-lovescrim.on{ opacity:1 !important; }

.schat-loveghost{
	position:absolute !important;
	transform:translate3d(0,0,0) scale(1);
	/* Out: a long, soft ease with the faintest overshoot. Back: plainer
	   and quicker, so the return reads as settling rather than launching. */
	transition:transform .56s cubic-bezier(.22,.9,.26,1.04) !important;
	will-change:transform !important;
	filter:drop-shadow(0 18px 42px rgba(0,0,0,.45)) !important;
}
.schat-loveghost.returning{
	transition:transform .47s cubic-bezier(.4,0,.25,1), opacity .47s ease !important;
	opacity:0 !important;
}
/* The clone must sit exactly where the original did, so the flight
   starts from the message and not from an offset. */
.schat-loveghost .schat-row{
	display:block !important;
	margin:0 !important;
	padding:0 !important;
	transform:none !important;
	animation:none !important;
	opacity:1 !important;
	width:100% !important;
	max-width:100% !important;
}
/* No width here on purpose: the script sets the exact used width in px.
   A percentage would be re-rounded and could re-wrap the text. */
.schat-loveghost .schat-bubble{
	margin:0 !important;
	animation:none !important;
	transform:none !important;
	opacity:1 !important;
}
/* The reaction badge rides along, but must not drag the box taller. */
.schat-loveghost .schat-loved{ animation:none !important; }

/* The real bubble steps aside while its copy is out, keeping its space
   in the thread so nothing below it shifts. */
.schat-bubble.schat-love-away{ opacity:0 !important; transition:opacity .12s linear !important; }

/* The heart that lands on the message once it arrives. */
.schat-lovepop{
	position:absolute !important;
	font-size:40px !important;
	line-height:1 !important;
	/* opacity and transform are driven by the keyframes below, so they are
	   deliberately NOT !important: an important author declaration outranks
	   a keyframe and would pin the heart invisible at its starting scale. */
	transform:translate(-50%,-50%) scale(.2);
	opacity:0;
	animation:schat-lovepop .62s cubic-bezier(.2,.9,.28,1.2) forwards !important;
	filter:drop-shadow(0 6px 16px rgba(217,169,172,.5)) !important;
}
@keyframes schat-lovepop{
	0%  { opacity:0; transform:translate(-50%,-50%) scale(.2) rotate(-18deg); }
	55% { opacity:1; transform:translate(-50%,-62%) scale(1.18) rotate(6deg); }
	100%{ opacity:1; transform:translate(-50%,-58%) scale(1) rotate(0deg); }
}
.schat-lovepop.leaving{
	animation:schat-lovepop-out .42s ease forwards !important;
}
@keyframes schat-lovepop-out{
	from{ opacity:1; transform:translate(-50%,-58%) scale(1); }
	to  { opacity:0; transform:translate(-50%,-76%) scale(.78); }
}

/* The confetti puff — small, short-lived, thrown outward from the centre. */
.schat-lovep{
	position:absolute !important;
	display:block !important;
	background:var(--c,#D9A9AC) !important;
	border-radius:1.5px;
	opacity:0;
	will-change:transform,opacity;
	animation:schat-lovep 1.15s cubic-bezier(.15,.7,.3,1) forwards !important;
}
@keyframes schat-lovep{
	0%  { opacity:0; transform:translate(-50%,-50%) scale(.5) rotate(0deg); }
	18% { opacity:1; }
	70% { opacity:1; }
	100%{ opacity:0; transform:translate(calc(-50% + var(--dx)), calc(-50% + var(--dy))) scale(.85) rotate(var(--rot)); }
}

/* Reduced motion: a quiet acknowledgement in place, no journey. */
.schat-bubble.schat-love-nudge{
	animation:schat-love-nudge .42s ease !important;
}
@keyframes schat-love-nudge{
	0%,100%{ transform:scale(1); }
	45%    { transform:scale(1.035); }
}

@media (prefers-reduced-motion: reduce){
	.schat-lovestage{ display:none !important; }
}

/* ============================================================
   LINKS + LINK PREVIEW CARDS

   Two bubble backgrounds to satisfy: the dark incoming bubble and the
   gold outgoing one. Everything below is written twice where it needs
   to be, rather than hoping one colour reads on both.
   ============================================================ */
.schat-root .schat-text a.schat-link{
	color:var(--gold,#D9A9AC) !important;
	text-decoration:underline !important;
	text-decoration-thickness:1px !important;
	text-underline-offset:2px !important;
	text-decoration-color:rgba(217,169,172,.45) !important;
	word-break:break-word !important;
	overflow-wrap:anywhere !important;
}
.schat-root .schat-text a.schat-link:hover{
	text-decoration-color:var(--gold,#D9A9AC) !important;
}
/* On my own (gold) bubble the text is dark, so a gold link vanishes. */
.schat-root .schat-row.mine .schat-text a.schat-link{
	color:#5A2F34 !important;
	text-decoration-color:rgba(90,47,52,.45) !important;
}
.schat-root .schat-row.mine .schat-text a.schat-link:hover{
	text-decoration-color:#5A2F34 !important;
}

.schat-root a.schat-linkcard{
	display:flex !important;
	align-items:stretch !important;
	gap:0 !important;
	margin:8px 0 2px !important;
	border-radius:12px !important;
	overflow:hidden !important;
	text-decoration:none !important;
	background:rgba(255,255,255,.055) !important;
	border:1px solid rgba(255,255,255,.10) !important;
	max-width:100% !important;
	transition:background-color .18s ease !important;
}
.schat-root a.schat-linkcard:hover{ background:rgba(255,255,255,.09) !important; }
.schat-root .schat-row.mine a.schat-linkcard{
	background:rgba(20,10,12,.10) !important;
	border-color:rgba(20,10,12,.16) !important;
}
.schat-root .schat-row.mine a.schat-linkcard:hover{ background:rgba(20,10,12,.16) !important; }

.schat-root .schat-lc-thumb{
	flex:0 0 72px !important;
	width:72px !important;
	align-self:stretch !important;
	background:rgba(255,255,255,.05) !important;
	display:block !important;
	overflow:hidden !important;
}
.schat-root .schat-lc-thumb img{
	width:100% !important; height:100% !important;
	min-height:72px !important;
	object-fit:cover !important;
	display:block !important;
}

.schat-root .schat-lc-body{
	flex:1 1 auto !important;
	min-width:0 !important;
	display:flex !important;
	flex-direction:column !important;
	justify-content:center !important;
	gap:2px !important;
	padding:9px 11px !important;
}
.schat-root .schat-lc-title{
	display:block !important;
	font-family:"Sen", sans-serif !important;
	font-size:13px !important;
	font-weight:600 !important;
	line-height:1.3 !important;
	color:var(--text,#F2F2F2) !important;
	/* Two lines, then ellipsis — a long title must not stretch the bubble. */
	display:-webkit-box !important;
	-webkit-line-clamp:2 !important;
	-webkit-box-orient:vertical !important;
	overflow:hidden !important;
}
.schat-root .schat-lc-desc{
	display:-webkit-box !important;
	-webkit-line-clamp:2 !important;
	-webkit-box-orient:vertical !important;
	overflow:hidden !important;
	font-family:"Sen", sans-serif !important;
	font-size:11.5px !important;
	line-height:1.4 !important;
	color:var(--sec,#9A9A9A) !important;
}
.schat-root .schat-lc-host{
	display:block !important;
	font-family:"Sen", sans-serif !important;
	font-size:10.5px !important;
	letter-spacing:.04em !important;
	text-transform:uppercase !important;
	color:var(--gold,#D9A9AC) !important;
	margin-top:2px !important;
	white-space:nowrap !important;
	overflow:hidden !important;
	text-overflow:ellipsis !important;
}
.schat-root .schat-row.mine .schat-lc-title{ color:#21161A !important; }
.schat-root .schat-row.mine .schat-lc-desc{ color:rgba(33,22,26,.72) !important; }
.schat-root .schat-row.mine .schat-lc-host{ color:#5A2F34 !important; }

/* A photo bubble is edge-to-edge; a card inside it needs its own inset. */
.schat-root .schat-bubble.schat-has-photo a.schat-linkcard{ margin:8px 10px 10px !important; }

@media (max-width:420px){
	.schat-root .schat-lc-thumb{ flex-basis:62px !important; width:62px !important; }
	.schat-root .schat-lc-thumb img{ min-height:62px !important; }
}

@media (prefers-reduced-motion: reduce){
	.schat-confetti, .schat-heartp{ display:none !important; }
	.schat-party-badge{ animation-duration:1600ms !important; }
	.schat-pres .schat-pres-dot{ animation:none !important; }
	.schat-input-row .schat-party-btn.pop svg{ animation:none !important; }
}
</style>
SCHATPARTY;
}

/**
 * Chat wallpaper, set in Shatania -> Chat Style. Emitted after the main
 * chat stylesheet so it layers under the existing ambient gradients.
 * Returns '' when no wallpaper is configured.
 */
function schat_chat_wallpaper_css() {
	$wp = get_option( 'shat_chat_wallpaper', [] );
	if ( empty( $wp['enabled'] ) || empty( $wp['url'] ) ) return '';

	$url = esc_url( $wp['url'] );
	$vis = isset( $wp['visibility'] ) ? max( 0, min( 100, intval( $wp['visibility'] ) ) ) : 35;

	/* A scrim sits between the photo and the messages so text stays
	   readable at any visibility. 100% visibility still keeps a light
	   veil (.14) — without it, bubbles fight the image. */
	$scrim = round( 1 - ( $vis / 100 ) * 0.86, 3 );

	return '<style id="schat-wallpaper">'
		. '.schat-root .schat-body{'
		. 'background-image:'
		. 'radial-gradient(70% 40% at 12% 0%, rgba(220,175,178,.07) 0%, transparent 60%),'
		. 'linear-gradient(rgba(21,16,17,' . $scrim . '), rgba(21,16,17,' . $scrim . ')),'
		. 'url(' . $url . ') !important;'
		. 'background-size:auto, auto, cover !important;'
		. 'background-position:center, center, center !important;'
		. 'background-repeat:no-repeat, no-repeat, no-repeat !important;'
		. 'background-attachment:scroll, scroll, scroll !important;'
		. 'background-color:#151011 !important;'
		. '}'
		. '</style>';
}

/**
 * Is the polished look switched on? Defaults to on; Shatania -> Chat
 * Style -> "Polished look" turns it off again without touching a file.
 */
function schat_polish_enabled() {
	return get_option( 'shat_chat_polish', '1' ) !== '0';
}

/**
 * Refinements layered over the dark chat skin. Returns '' when the
 * switch is off, so the chat falls back to the previous look exactly.
 */
function schat_polish_css() {
	if ( ! schat_polish_enabled() ) return '';
	return <<<'SCHATPOLISH'
<style id="schat-polish">
/* ============================================================
   DARK POLISH v1 — refinements to the existing dark skin.

   Deliberately small. It keeps every colour, font and spacing the
   chat already has, and changes only the handful of things that
   made it read as unfinished:

     • the pink glow haloing your own bubbles
     • the muddy grey quote block inside a pink bubble
     • press / tap feedback that was missing or abrupt
     • the send button snapping, rather than easing, into place

   No font-family is declared anywhere in this block, on purpose.

   Printed only while Shatania -> Chat Style -> "Polished look" is
   ticked (see schat_polish_enabled()). Untick it and the chat is
   byte-for-byte the previous version.
   ============================================================ */

.schat-root{
	--polish-ease:cubic-bezier(.22,.9,.32,1);
	--polish-spring:cubic-bezier(.34,1.32,.64,1);
}

/* ---- 1. Grounded bubbles, no halo ----
   Same gradient and ink as before. Only the shadow changes: the pink
   glow read as neon; a tight dark contact shadow reads as a surface. */
.schat-row.mine .schat-bubble{
	box-shadow:
		inset 0 1px 0 rgba(255,255,255,.45),
		0 1px 2px rgba(0,0,0,.35),
		0 10px 20px -14px rgba(0,0,0,.70) !important;
}
.schat-row.theirs .schat-bubble{
	box-shadow:
		inset 0 1px 0 rgba(255,255,255,.035),
		0 1px 2px rgba(0,0,0,.40),
		0 10px 20px -14px rgba(0,0,0,.70) !important;
}
/* Photos and emoji keep their own treatment. */
.schat-row .schat-bubble.schat-emoji-only{ box-shadow:none !important; }

/* ---- 2. Quoted reply, readable on both sides ----
   Inside your pink bubble the quote was a grey-on-pink smudge. Now a
   pale inset card with a firm rule, so it reads as a quote at a glance. */
.schat-row.mine .schat-reply-quote{
	background:rgba(255,255,255,.34) !important;
	border-left:2.5px solid rgba(43,26,30,.42) !important;
	color:#3A2328 !important;
	opacity:1 !important;
}
.schat-row.mine .schat-reply-quote .schat-reply-who{
	color:#2B1A1E !important;
	opacity:1 !important;
}
.schat-row.theirs .schat-reply-quote{
	background:rgba(255,255,255,.05) !important;
	border-left:2.5px solid var(--gold) !important;
	opacity:1 !important;
}
.schat-row.theirs .schat-reply-quote .schat-reply-who{
	color:var(--gold) !important;
	opacity:1 !important;
}
/* The old pressed state brightened the quote, which washed the pink
   version out entirely. A small press-in instead. */
.schat-reply-quote:active{
	filter:none !important;
	transform:scale(.985) !important;
}

/* ---- 3. Press feedback, consistently soft ---- */
.schat-row.schat-pressing .schat-bubble{
	transform:scale(.972) !important;
	transition:transform .14s var(--polish-ease) !important;
}
.schat-header .schat-back-btn,
.schat-header .schat-close-btn,
.schat-input-row .schat-photo-btn{
	transition:opacity .2s var(--polish-ease),
	           background .2s var(--polish-ease),
	           transform .22s var(--polish-spring) !important;
}
.schat-header .schat-back-btn:active,
.schat-header .schat-close-btn:active,
.schat-input-row .schat-photo-btn:active{ transform:scale(.88) !important; }

.schat-fab{ transition:transform .22s var(--polish-spring), box-shadow .24s var(--polish-ease) !important; }
.schat-fab:active{ transform:scale(.93) !important; }

/* ---- 4. Send button eases in ----
   While empty it rests a touch smaller; the first character you type
   lets it grow back to full size, so the composer answers you. */
.schat-send-btn,
.schat-input-row .schat-send-btn{
	transition:transform .26s var(--polish-spring),
	           box-shadow .22s var(--polish-ease),
	           opacity .22s var(--polish-ease),
	           background .22s var(--polish-ease) !important;
}
.schat-send-btn:active{ transform:scale(.92) !important; }
.schat-send-btn:disabled,
.schat-send-btn:disabled:hover,
.schat-send-btn:disabled:active,
.schat-input-row .schat-send-btn:disabled,
.schat-input-row .schat-send-btn:disabled:hover,
.schat-input-row .schat-send-btn:disabled:active{
	transform:scale(.88) !important;
}

/* ---- 5. Arrival motion ----
   One smooth rise-and-settle instead of a springy overshoot. A burst
   from one person arrives quicker, so six messages don't read as six
   separate events. */
.schat-row{ animation:schatPolishIn .38s var(--polish-ease) both !important; }
.schat-row.schat-grouped-cont{ animation-duration:.28s !important; }
@keyframes schatPolishIn{
	from{ opacity:0; transform:translateY(8px) scale(.982); }
	to  { opacity:1; transform:translateY(0)   scale(1); }
}

/* ---- 6. Timestamps don't jitter ---- */
.schat-meta{ font-variant-numeric:tabular-nums !important; }

/* ---- Motion off ---- */
@media (prefers-reduced-motion:reduce){
	.schat-row{ animation:none !important; }
	.schat-send-btn,
	.schat-send-btn:disabled,
	.schat-fab{ transition:none !important; transform:none !important; }
}
</style>
SCHATPOLISH;
}

function schat_css_string() {
	return <<<'SCHATCSS'
<style>
.schat-root{
	--bg:#141414; --card:#1C1C1C; --text:#F2F2F2; --sec:#9A9A9A;
	--gold:#D9A9AC; --gold-light:#E8C4C6; --gold-dark:#C08A8E;
	--line:rgba(255,255,255,.08);
	--font:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;
	font-family:var(--font); -webkit-font-smoothing:antialiased;
}
.schat-root *{box-sizing:border-box;font-family:inherit;}

/* Hard reset so the active theme (Kadence, etc.) cannot leak its own
   button/input/svg styling into the chat widget. Every rule below is
   scoped under .schat-root and marked !important where it matters. */
.schat-root button{
	all:unset !important;
	box-sizing:border-box !important;
	cursor:pointer !important;
	display:inline-flex !important;
	align-items:center !important;
	justify-content:center !important;
	font-family:var(--font) !important;
	-webkit-tap-highlight-color:rgba(0,0,0,0) !important;
}

/* ------------------------------------------------------------------
   Remove the native tap/focus square on every chat control. This is
   deliberately NOT scoped to .schat-root, because the launcher fab is
   rendered inside the profile header, outside that wrapper. The square
   comes from three separate sources: the Android/iOS tap highlight, the
   UA focus ring, and the default button background — all killed here.
   :focus-visible is left intact for keyboard users further down.
   ------------------------------------------------------------------ */
.schat-root button,
.schat-root a,
.schat-fab,
.schat-fab-icon,
.schat-icon-btn,
.schat-send-btn,
.schat-photo-btn,
.schat-back-btn,
.schat-close-btn,
.schat-inbox-row,
.schat-ctx button,
.shat-ig-logout{
	-webkit-tap-highlight-color:rgba(0,0,0,0) !important;
	-webkit-touch-callout:none !important;
	-webkit-appearance:none !important;
	appearance:none !important;
	outline:none !important;
	-webkit-focus-ring-color:transparent !important;
}
.schat-root button:focus,
.schat-root button:active,
.schat-root a:focus,
.schat-fab:focus,
.schat-fab:active,
.schat-fab-icon:focus,
.schat-fab-icon:active,
.schat-icon-btn:focus,
.schat-icon-btn:active,
.schat-send-btn:focus,
.schat-send-btn:active,
.schat-photo-btn:focus,
.schat-photo-btn:active,
.schat-back-btn:focus,
.schat-back-btn:active,
.schat-close-btn:focus,
.schat-close-btn:active,
.schat-ctx button:focus,
.shat-ig-logout:focus,
.shat-ig-logout:active{
	outline:none !important;
	box-shadow:none !important;
	-webkit-tap-highlight-color:rgba(0,0,0,0) !important;
}
/* Keyboard users still get a visible ring — but a rounded one, never a square. */
.schat-root button:focus-visible,
.schat-fab-icon:focus-visible,
.schat-icon-btn:focus-visible,
.shat-ig-logout:focus-visible{
	outline:none !important;
	box-shadow:0 0 0 2px rgba(217,169,172,.55) !important;
}
.schat-root svg{
	display:block !important;
	flex-shrink:0 !important;
	fill:none !important;
	pointer-events:none;
}
.schat-root input:not([type="file"]),
.schat-root textarea{
	all:unset !important;
	box-sizing:border-box !important;
	font-family:var(--font) !important;
}
.schat-root input[type="file"],
.schat-root input.schat-file-input{
	/* Android WebView will NOT open a file chooser for a display:none input.
	   Keep it in the layout but visually hidden and non-interruptive: 1px,
	   clipped, zero opacity, pinned so it never affects the composer row. */
	position:absolute !important;
	width:1px !important; height:1px !important;
	padding:0 !important; margin:-1px !important;
	overflow:hidden !important; clip:rect(0 0 0 0) !important;
	white-space:nowrap !important; border:0 !important;
	opacity:0 !important; pointer-events:none !important;
}

.schat-fab{
	position:fixed !important; right:20px !important; bottom:24px !important; z-index:9998 !important;
	width:58px !important; height:58px !important; border-radius:50% !important;
	background:var(--gold) !important; color:#141414 !important;
	box-shadow:0 8px 28px rgba(0,0,0,.35) !important;
	transition:transform .2s cubic-bezier(.34,1.56,.64,1);
}
.schat-fab:active{transform:scale(.92);}
.schat-fab svg{width:25px !important;height:25px !important;stroke:#141414 !important;stroke-width:2 !important;}

/* Overrides the circular fixed-position fab for the static inline
   "Message Shatania" pill button — same class (.schat-fab) is kept so
   the existing click-delegation JS still matches it, but visually and
   positionally it behaves like a normal inline button, not a floating
   circle. Rules here come after .schat-fab above so they win on the
   properties both set (equal specificity + !important => source order). */
.schat-fab-pill{
	position:relative !important; right:auto !important; bottom:auto !important; z-index:auto !important;
	width:auto !important; height:auto !important; border-radius:24px !important;
	padding:10px 20px !important; gap:8px !important;
	box-shadow:none !important;
}
.schat-fab-pill svg{width:17px !important;height:17px !important;}
.schat-fab-pill .schat-fab-badge{ top:-6px !important; right:-6px !important; }

/* Icon-only chat button — mirrors .shat-ig-logout exactly and sits
   directly beneath it in the profile header. Applies to BOTH the member
   button (#schat-fab-btn) and the admin inbox button (#schat-admin-fab-btn).
   Click handlers/IDs/classes are untouched, so open+close behaviour and the
   panel/overlay positions are unchanged. */
.schat-fab-icon,
#schat-fab-btn.schat-fab-icon,
#schat-admin-fab-btn.schat-fab-icon{
	position:absolute !important; top:62px !important; right:18px !important;
	left:auto !important; bottom:auto !important; z-index:5 !important;
	width:36px !important; height:36px !important;
	padding:0 !important; gap:0 !important; border-radius:50% !important;
	display:inline-flex !important; align-items:center !important; justify-content:center !important;
	background:#141414 !important; border:1px solid var(--line) !important;
	color:var(--sec) !important; box-shadow:none !important;
	font-size:0 !important; line-height:0 !important; text-indent:0 !important;
	cursor:pointer !important;
	transition:background .3s ease,color .3s ease,border-color .3s ease,transform .25s cubic-bezier(.34,1.56,.64,1) !important;
}
.schat-fab-icon:hover{
	background:var(--btn) !important; color:var(--btn-text) !important; border-color:var(--btn) !important;
	transform:translateY(-1px) !important;
}
.schat-fab-icon:active{ transform:scale(.9) !important; }
.schat-fab-icon svg{
	width:15px !important; height:15px !important; display:block !important;
	stroke:currentColor !important; fill:none !important; stroke-width:2 !important;
}
.schat-fab-icon .schat-fab-badge{
	top:-4px !important; right:-4px !important;
	min-width:17px !important; height:17px !important;
	font-size:10px !important; line-height:1 !important;
	border-width:2px !important;
}
/* The chat shortcode wrapper must not create a stacking/offset context
   inside the header, or the absolute positioning above would be relative
   to the wrapper instead of .shat-ig-header. */
.shat-ig-header .schat-root{
	position:static !important;
	height:0 !important; margin:0 !important; padding:0 !important;
}
/* .shat-ig-header has overflow:hidden AND an animation (which creates a
   containing block), both of which break position:fixed for descendants.
   The overlays are re-parented to <body> by JS on load, and this class is
   the marker for that moved wrapper. */
.schat-detached-overlays{ position:static !important; }

.schat-fab .schat-fab-badge{
	position:absolute; top:-2px; right:-2px;
	min-width:20px; height:20px; padding:0 5px; border-radius:10px;
	background:#E24C4C; color:#fff; font-size:11px; font-weight:700;
	display:flex; align-items:center; justify-content:center;
	border:2px solid var(--bg);
}

.schat-overlay{
	position:fixed !important; z-index:9999 !important;
	left:0 !important; right:0 !important;
	background:rgba(0,0,0,.55) !important;
	display:none !important; align-items:flex-end; justify-content:center;
	/* Pin to the *visual* viewport so the keyboard doesn't leave a gap.

	   Fixed elements are laid out against the LAYOUT viewport, which the
	   on-screen keyboard does not shrink — so the panel keeps its full
	   height and its bottom hides behind the keys.

	   Sized directly from the measured visual height rather than a vh
	   unit or a computed keyboard inset, so the box is exactly as tall as
	   what is actually on screen. translateY then shifts it down by
	   however far the visual viewport itself has been pushed. This does
	   not depend on --schat-kb at all; that var is now used only to paint
	   the strip behind the keyboard. */
	top:0 !important;
	bottom:auto !important;
	height:var(--schat-vh, 100dvh) !important;
	transform:translateY(var(--schat-vtop, 0px)) !important;
}
.schat-overlay.open{display:flex !important;}

.schat-panel{
	width:100% !important; max-width:460px !important;
	/* The overlay is already inset above the keyboard (see --schat-kb),
	   so the panel just fills it. Both the mobile and desktop media
	   queries below override these, so they're only a pre-media-query
	   fallback. */
	height:100% !important;
	max-height:100% !important;
	background:var(--bg) !important; border-radius:20px 20px 0 0 !important;
	display:flex !important; flex-direction:column !important; overflow:hidden !important;
	box-shadow:0 -8px 40px rgba(0,0,0,.5) !important;
	transform:translateY(100%); transition:transform .28s cubic-bezier(.32,.72,0,1);
}
.schat-overlay.open .schat-panel{transform:translateY(0);}

/* Mobile: fill the *visible* area exactly, so the composer sits flush on
   top of the keyboard with no dead space beneath it. */
@media(max-width:640px){
	.schat-overlay{ align-items:stretch !important; }
	.schat-panel{
		height:100% !important; max-height:100% !important;
		border-radius:0 !important;
	}
}

/* ---- Desktop: dock like Instagram DMs, not a centered modal ---- */
@media(min-width:641px){
	.schat-overlay{
		align-items:center !important; justify-content:flex-end !important;
		background:rgba(0,0,0,.35) !important;
		padding-right:28px;
	}
	.schat-panel{
		width:380px; max-width:380px;
		height:640px !important; max-height:82vh !important;
		border-radius:16px; transform:translateX(24px) translateY(0);
		box-shadow:0 12px 48px rgba(0,0,0,.55);
	}
	.schat-overlay.open .schat-panel{transform:translateX(0);}
}

.schat-standalone-mode{position:relative !important;background:transparent !important;display:flex !important;padding:0 !important;}
.schat-standalone-mode .schat-panel{width:100%;max-width:640px;height:74vh;border-radius:16px;transform:none !important;}

.schat-header{
	display:flex !important; align-items:center !important; gap:12px !important;
	padding:14px 16px !important; border-bottom:1px solid var(--line) !important;
	background:var(--card) !important; flex-shrink:0 !important;
}
.schat-header img{width:38px !important;height:38px !important;border-radius:50% !important;object-fit:cover !important;flex-shrink:0 !important;}
.schat-header .schat-h-info{flex:1 !important;min-width:0 !important;}
.schat-header .schat-h-name{font-size:14.5px !important;font-weight:600 !important;color:var(--text) !important;line-height:1.3 !important;margin:0 !important;}
.schat-header .schat-h-sub{font-size:12px !important;color:var(--sec) !important;margin-top:1px !important;line-height:1.3 !important;}
.schat-header .schat-h-actions{display:flex !important;align-items:center !important;gap:2px !important;flex-shrink:0 !important;}
.schat-header .schat-h-actions button{
	width:32px !important;height:32px !important;border-radius:50% !important;
	color:var(--sec) !important; transition:background .15s;
}
.schat-header .schat-h-actions button:hover{background:rgba(255,255,255,.08) !important;}
.schat-header .schat-h-actions button svg{width:18px !important;height:18px !important;stroke:currentColor !important;stroke-width:2 !important;}
.schat-close-btn svg{width:19px !important;height:19px !important;}

.schat-notify-btn{
	display:inline-flex !important; align-items:center !important; gap:4px !important;
	width:auto !important; height:auto !important; padding:3px 9px !important;
	border-radius:14px !important; font-size:11px !important; color:var(--sec) !important;
	border:1px solid var(--line) !important; background:transparent !important;
}
.schat-notify-btn svg{width:13px !important;height:13px !important;stroke:currentColor !important;stroke-width:2 !important;}
.schat-notify-btn.sent{color:#7FBF7F !important; border-color:rgba(127,191,127,.35) !important;}

.schat-body{
	flex:1 !important; overflow-y:auto !important; padding:16px 14px !important;
	display:flex !important; flex-direction:column !important; gap:10px !important;
	background:var(--bg) !important;
}

.schat-empty{
	flex:1 !important; display:flex !important; flex-direction:column !important; align-items:center !important; justify-content:center !important;
	text-align:center !important; color:var(--sec) !important; padding:32px 24px !important; gap:10px !important;
}

/* "Loading earlier messages…" row, shown at the top of the list while a
   scroll-up batch is in flight. flex-shrink:0 so it can't be squashed by
   the message rows around it. */
.schat-older-loading{
	flex-shrink:0 !important;
	text-align:center !important; color:var(--sec) !important;
	font-size:12px !important; padding:10px 0 6px !important;
	opacity:.75 !important;
}
.schat-empty svg{width:44px !important;height:44px !important;opacity:.4;stroke:var(--sec) !important;stroke-width:1.5 !important;}
.schat-empty p{font-size:14px !important;line-height:1.5 !important;margin:0 !important;color:var(--sec) !important;}

.schat-row{display:flex !important; max-width:78% !important; width:auto !important;}
.schat-row.mine{align-self:flex-end !important; justify-content:flex-end !important; margin-left:auto !important;}
.schat-row.theirs{align-self:flex-start !important; justify-content:flex-start !important; margin-right:auto !important;}

.schat-bubble{
	padding:10px 14px !important; border-radius:16px !important; font-size:14.5px !important; line-height:1.45 !important;
	word-wrap:break-word !important; position:relative !important; display:block !important;
}
.schat-row.theirs .schat-bubble{
	background:var(--card) !important; color:var(--text) !important; border:1px solid var(--line) !important;
	border-bottom-left-radius:4px !important;
}
.schat-row.mine .schat-bubble{
	background:var(--gold) !important; color:#141414 !important;
	border-bottom-right-radius:4px !important;
}
.schat-bubble img.schat-photo{
	display:block !important; max-width:100% !important; border-radius:12px !important; margin-bottom:6px !important; cursor:pointer !important;
}
/* Meta (time / actions) now sits OUTSIDE and BELOW the bubble. */
.schat-meta{
	display:flex !important; align-items:center !important; gap:6px !important;
	margin:3px 2px 0 !important;
	font-size:10.5px !important; color:var(--sec) !important; opacity:.75 !important;
	line-height:1 !important;
}
.schat-row.mine .schat-meta{justify-content:flex-end !important;}
/* ---- Instagram-style reaction: floats OUTSIDE, hanging off the bubble corner ---- */
.schat-bubble.has-loved{ margin-bottom:10px !important; }
.schat-loved{
	position:absolute !important; bottom:-12px !important; z-index:3 !important;
	display:inline-flex !important; align-items:center !important; justify-content:center !important;
	width:22px !important; height:22px !important; font-size:12px !important; line-height:1 !important;
	border-radius:50% !important;
	background:var(--card) !important; border:1.5px solid var(--bg, #141414) !important;
	box-shadow:0 1px 4px rgba(0,0,0,.35) !important;
	pointer-events:none !important; opacity:1 !important; margin:0 !important;
	animation:schatLoveIn .28s cubic-bezier(.34,1.56,.64,1) both;
}
.schat-row.theirs .schat-loved{ left:10px !important; right:auto !important; }
.schat-row.mine   .schat-loved{ right:10px !important; left:auto !important; }
@keyframes schatLoveIn{ from{opacity:0;transform:scale(.4);} to{opacity:1;transform:scale(1);} }

/* Row becomes a column so bubble + meta stack vertically. */
.schat-row{ flex-direction:column !important; }
.schat-row.mine{ align-items:flex-end !important; }
.schat-row.theirs{ align-items:flex-start !important; }

/* ---- Back button (admin: return to the inbox list) ---- */
.schat-back-btn{
	width:32px !important; height:32px !important; border-radius:50% !important;
	background:transparent !important; color:var(--sec) !important;
	display:none !important; align-items:center !important; justify-content:center !important;
	flex-shrink:0 !important; margin-right:-4px !important; border:none !important;
	transition:background .18s ease,color .18s ease,transform .18s ease;
}
.schat-overlay.schat-has-back .schat-back-btn{ display:inline-flex !important; }
.schat-back-btn:hover{ background:transparent !important; color:var(--text) !important; }
.schat-back-btn:active{ transform:scale(.9) !important; }
.schat-back-btn svg{ width:20px !important; height:20px !important; stroke:currentColor !important; stroke-width:2.2 !important; fill:none !important; }

/* ---- Seen receipt ---- */
.schat-seen{
	align-self:flex-end !important;
	font-size:10.5px !important; color:var(--sec) !important;
	opacity:.75 !important; margin:-4px 4px 2px 0 !important;
	display:flex !important; align-items:center !important; gap:4px !important;
	animation:schatSeenIn .3s ease both;
}
.schat-seen svg{ width:12px !important; height:12px !important; stroke:var(--gold) !important; fill:none !important; stroke-width:2.5 !important; }
@keyframes schatSeenIn{ from{opacity:0;transform:translateY(-3px);} to{opacity:.75;transform:translateY(0);} }

/* ---- Typing indicator — Apple-style three-dot bounce ---- */
.schat-typing{
	display:none; align-items:center !important; gap:8px !important;
	padding:2px 2px 4px !important; align-self:flex-start !important;
	animation:schatTypeIn .28s cubic-bezier(.22,.9,.32,1) both;
}
.schat-typing.show{ display:flex !important; }
@keyframes schatTypeIn{ from{opacity:0;transform:translateY(6px) scale(.96);} to{opacity:1;transform:translateY(0) scale(1);} }
.schat-typing-bubble{
	display:inline-flex !important; align-items:center !important; gap:4px !important;
	background:var(--card) !important; border:1px solid var(--line) !important;
	padding:10px 13px !important; border-radius:16px !important; border-bottom-left-radius:4px !important;
}
.schat-typing-bubble i{
	width:6px !important; height:6px !important; border-radius:50% !important;
	background:var(--sec) !important; display:block !important;
	animation:schatDot 1.25s infinite ease-in-out both;
}
.schat-typing-bubble i:nth-child(2){ animation-delay:.16s !important; }
.schat-typing-bubble i:nth-child(3){ animation-delay:.32s !important; }
@keyframes schatDot{
	0%,80%,100%{ transform:translateY(0) scale(.85); opacity:.45; }
	40%{ transform:translateY(-4px) scale(1); opacity:1; }
}
.schat-typing-text{ font-size:11.5px !important; color:var(--sec) !important; opacity:.8 !important; }

/* ---- Quoted reply inside a bubble ---- */
.schat-reply-quote{
	display:block !important; margin:-2px 0 6px !important; padding:6px 10px !important;
	border-left:3px solid rgba(255,255,255,.35) !important;
	background:rgba(0,0,0,.16) !important; border-radius:8px !important;
	font-size:12.5px !important; line-height:1.35 !important; opacity:.9 !important;
}
.schat-row.mine .schat-reply-quote{ border-left-color:rgba(0,0,0,.3) !important; background:rgba(0,0,0,.10) !important; }
.schat-reply-quote .schat-reply-who{ display:block !important; font-weight:700 !important; font-size:11.5px !important; margin-bottom:1px !important; opacity:.85 !important; }

/* ---- Composer reply bar ---- */
.schat-reply-bar{
	display:none; align-items:center !important; gap:10px !important;
	padding:8px 12px !important; margin-bottom:6px !important;
	background:rgba(255,255,255,.05) !important; border-radius:10px !important;
	border-left:3px solid var(--gold) !important;
	animation:schatTypeIn .22s ease both;
}
.schat-reply-bar.show{ display:flex !important; }
.schat-reply-bar .schat-reply-info{ flex:1 !important; min-width:0 !important; }
.schat-reply-bar .schat-reply-label{ font-size:11.5px !important; color:var(--gold) !important; font-weight:600 !important; }
.schat-reply-bar .schat-reply-text{
	font-size:12.5px !important; color:var(--sec) !important; margin-top:1px !important;
	white-space:nowrap !important; overflow:hidden !important; text-overflow:ellipsis !important;
}
.schat-reply-cancel{
	width:26px !important; height:26px !important; border-radius:50% !important; flex-shrink:0 !important;
	background:transparent !important; color:var(--sec) !important; border:none !important;
	display:inline-flex !important; align-items:center !important; justify-content:center !important;
}
.schat-reply-cancel:hover{ background:rgba(255,255,255,.08) !important; }
.schat-reply-cancel svg{ width:14px !important; height:14px !important; stroke:currentColor !important; stroke-width:2 !important; }

/* ---- Composer edit bar (shown while rewriting an existing message) ---- */
.schat-edit-bar{
	display:none; align-items:center !important; gap:10px !important;
	padding:8px 12px !important; margin-bottom:6px !important;
	background:rgba(217,169,172,.08) !important; border-radius:10px !important;
	border-left:3px solid var(--gold) !important;
	animation:schatTypeIn .22s ease both;
}
.schat-edit-bar.show{ display:flex !important; }
.schat-edit-bar .schat-edit-info{ flex:1 !important; min-width:0 !important; }
.schat-edit-bar .schat-edit-label{
	display:flex !important; align-items:center !important; gap:6px !important;
	font-size:12px !important; color:var(--gold) !important; font-weight:600 !important;
}
.schat-edit-bar .schat-edit-label svg{ width:14px !important; height:14px !important; stroke:var(--gold) !important; fill:none !important; }
.schat-edit-cancel{
	width:26px !important; height:26px !important; border-radius:50% !important; flex-shrink:0 !important;
	background:transparent !important; color:var(--sec) !important; border:none !important;
	display:inline-flex !important; align-items:center !important; justify-content:center !important;
}
.schat-edit-cancel:hover{ background:rgba(255,255,255,.08) !important; }
.schat-edit-cancel svg{ width:14px !important; height:14px !important; stroke:currentColor !important; stroke-width:2 !important; }

/* ---- "edited" marker in the meta row ---- */
.schat-edited{
	font-size:10.5px !important; color:var(--sec) !important; opacity:.7 !important;
	font-style:italic !important;
}
.schat-edited::before{ content:"· " !important; font-style:normal !important; }

/* ---- Swipe-to-reply (WhatsApp-style) ---- */
.schat-row{ position:relative !important; will-change:transform; }
.schat-row.schat-swiping .schat-bubble{ transition:none !important; }
/* Reply arrow that sits behind the bubble and is revealed as you drag. */
.schat-row .schat-swipe-hint,
.schat-row::before{
	content:"" !important;
	position:absolute !important; left:-38px !important; top:50% !important;
	width:28px !important; height:28px !important; margin-top:-14px !important;
	border-radius:50% !important;
	background:var(--card) !important; border:1px solid var(--line) !important;
	background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23D9A9AC' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'><polyline points='9 17 4 12 9 7'/><path d='M20 18v-2a4 4 0 0 0-4-4H4'/></svg>") !important;
	background-repeat:no-repeat !important; background-position:center !important; background-size:15px 15px !important;
	opacity:0 !important; transform:scale(.6) !important;
	transition:opacity .12s ease, transform .12s ease !important;
	pointer-events:none !important;
}
.schat-row.schat-swiping::before{ opacity:.5 !important; }
.schat-row.schat-swipe-ready::before{ opacity:1 !important; transform:scale(1) !important; }

/* ---- Flash the target message after tapping a reply quote ---- */
@keyframes schatFlash{
	0%   { background:rgba(217,169,172,.28); }
	100% { background:transparent; }
}
.schat-row.schat-flash .schat-bubble{
	animation:schatFlash 1.4s ease-out both !important;
}
@media (prefers-reduced-motion: reduce){
	.schat-row.schat-flash .schat-bubble{ animation:none !important; }
}

/* ---- Right-click / long-press context menu ---- */
.schat-ctx{
	position:fixed !important; z-index:10001 !important;
	background:rgba(38,38,38,.92) !important;
	-webkit-backdrop-filter:blur(20px) saturate(180%) !important;
	backdrop-filter:blur(20px) saturate(180%) !important;
	border:1px solid rgba(255,255,255,.10) !important;
	border-radius:13px !important; padding:5px !important; min-width:172px !important;
	box-shadow:0 12px 40px rgba(0,0,0,.5) !important;
	display:none; flex-direction:column !important; gap:1px !important;
	transform-origin:top left; animation:schatCtxIn .16s cubic-bezier(.22,.9,.32,1) both;
}
.schat-ctx.show{ display:flex !important; }
@keyframes schatCtxIn{ from{opacity:0;transform:scale(.94);} to{opacity:1;transform:scale(1);} }
.schat-ctx button{
	display:flex !important; align-items:center !important; gap:10px !important;
	width:100% !important; padding:9px 11px !important; border-radius:9px !important;
	background:transparent !important; border:none !important; color:#F2F2F2 !important;
	font-size:13.5px !important; text-align:left !important; cursor:pointer !important;
	font-family:var(--font) !important;
}
.schat-ctx button:hover{ background:rgba(255,255,255,.10) !important; }
.schat-ctx button.danger{ color:#FF6B6B !important; }
.schat-ctx button.sent{ color:#7FBF7F !important; cursor:default !important; }
.schat-ctx button:disabled{ opacity:.6 !important; cursor:default !important; }
.schat-ctx button svg{ width:15px !important; height:15px !important; stroke:currentColor !important; fill:none !important; stroke-width:2 !important; flex-shrink:0 !important; }

/* Long-press affordance on touch */
.schat-row.schat-pressing .schat-bubble{ transform:scale(.97); transition:transform .12s ease; }

.schat-del-btn{
	width:auto !important;height:auto !important;
	color:inherit !important;opacity:.55 !important;padding:0 !important;
}
.schat-del-btn:hover{opacity:1 !important;background:transparent !important;}
.schat-del-btn svg{width:12px !important;height:12px !important;stroke:currentColor !important;stroke-width:2 !important;}

.schat-day-sep{
	align-self:center; font-size:11.5px; color:var(--sec);
	background:var(--card); padding:4px 12px; border-radius:20px; margin:6px 0;
}

/* ============================================================
   BUTTERY PATCH v1 — motion, tails, sending state, retry chip.
   Additive only. Uses existing palette vars so nothing new is introduced.
   ============================================================ */
.schat-row{ animation:schatRowIn .34s cubic-bezier(.22,.9,.32,1) both; }
@keyframes schatRowIn{
	from{ opacity:0; transform:translateY(8px) scale(.985); }
	to  { opacity:1; transform:translateY(0)   scale(1);   }
}
.schat-row.schat-settled{ animation:none !important; }
@media (prefers-reduced-motion: reduce){
	.schat-row{ animation:none !important; }
}

/* Continuation rows rise a shorter distance so a burst of messages flows
   in as one motion instead of several big jumps. */
.schat-row.schat-grouped-cont{ animation:schatContIn .26s cubic-bezier(.22,.9,.32,1) both !important; }
@keyframes schatContIn{
	from{ opacity:0; transform:translateY(4px); }
	to  { opacity:1; transform:translateY(0);   }
}
@media (prefers-reduced-motion: reduce){
	.schat-row.schat-grouped-cont{ animation:none !important; }
}

/* Tails removed — the masked wedge rendered as a stray blob in some
   Android WebView versions. Rounded bubbles read clean on their own. */
.schat-row.mine .schat-bubble::after,
.schat-row.theirs .schat-bubble::after{ content:none !important; display:none !important; }

/* Optimistic "sending" state */
.schat-row.schat-pending .schat-bubble{
	opacity:.72 !important; position:relative !important; overflow:hidden !important;
}
.schat-row.schat-pending .schat-bubble::before{
	content:"" !important; position:absolute !important; inset:0 !important;
	background:linear-gradient(100deg,transparent 20%,rgba(255,255,255,.28) 50%,transparent 80%) !important;
	transform:translateX(-120%) !important;
	animation:schatShimmer 1.15s ease-in-out infinite !important;
	pointer-events:none !important;
}
@keyframes schatShimmer{ to{ transform:translateX(120%); } }
.schat-row.schat-pending .schat-meta{ opacity:.5 !important; }

/* Failed send: inline retry chip */
.schat-row.schat-failed .schat-bubble{
	opacity:1 !important;
	box-shadow:0 0 0 1px rgba(226,76,76,.55) inset !important;
}
.schat-retry{
	display:inline-flex !important; align-items:center !important; gap:5px !important;
	margin-top:2px !important; cursor:pointer !important;
	font-size:11px !important; color:#E24C4C !important; font-weight:600 !important;
	background:transparent !important; border:none !important; padding:2px 2px !important;
	-webkit-tap-highlight-color:transparent !important;
}
.schat-retry svg{ width:12px !important; height:12px !important; stroke:#E24C4C !important; stroke-width:2.2 !important; fill:none !important; }
.schat-retry:active{ transform:scale(.94) !important; }

/* Composer: a hair more premium */
.schat-input-row textarea:focus{
	border-color:var(--gold) !important;
	box-shadow:0 0 0 3px rgba(217,169,172,.14) !important;
}
.schat-send-btn:not(:disabled){
	box-shadow:0 2px 10px rgba(217,169,172,.35) !important;
}

/* Panel open easing */
.schat-panel{ transition:transform .34s cubic-bezier(.32,.72,0,1) !important; }

.schat-footer{
	border-top:1px solid var(--line) !important; background:var(--card) !important;
	padding:10px 12px !important; flex-shrink:0 !important;
}
.schat-attach-preview{
	display:none; align-items:center; gap:10px; padding:8px 10px;
	background:var(--bg); border-radius:12px; margin-bottom:8px; border:1px solid var(--line);
}
.schat-attach-preview.show{display:flex !important;}
.schat-attach-preview img{width:44px;height:44px;border-radius:8px;object-fit:cover;}
.schat-attach-preview .schat-attach-name{flex:1;font-size:12.5px;color:var(--sec);}
.schat-attach-preview button{
	width:26px !important;height:26px !important;border-radius:50% !important;color:var(--sec) !important;
}
.schat-attach-preview button svg{width:14px !important;height:14px !important;stroke:currentColor !important;stroke-width:2 !important;}

.schat-input-row{display:flex !important; align-items:center !important; gap:6px !important; width:100% !important;}
.schat-input-row textarea{
	display:block !important;
	box-sizing:border-box !important;
	flex:1 1 auto !important; resize:none !important; min-height:38px !important;
	padding:9px 14px !important; border-radius:20px !important; border:1.5px solid var(--line) !important;
	background:var(--bg) !important; color:var(--text) !important; font-size:14.5px !important; line-height:1.4 !important;
	transition:border-color .15s;
	width:auto !important;
	overflow-y:hidden !important;
	-webkit-appearance:none !important; appearance:none !important;
	outline:none !important; box-shadow:none !important;
}
/* Height is governed entirely by JS (auto-grow up to 5 lines). Past the cap
   the box scrolls internally. The scrollbar is hidden (see below) but touch
   and wheel scrolling still work so long messages stay readable. */
.schat-input-row textarea.schat-scrollable{ overflow-y:auto !important; }
.schat-input-row textarea:focus{border-color:var(--gold) !important;}
.schat-input-row textarea::placeholder{color:#5C5C5C !important;opacity:1 !important;}

/* Composer: the textarea's min-height is matched to the 36px buttons so
   a single-line input and the icons share the same baseline box. Both
   sides are bottom-aligned, so the icons stay put as the box grows. */
.schat-input-row{ align-items:flex-end !important; }
.schat-input-row textarea{
	min-height:36px !important;
	padding-top:8px !important; padding-bottom:8px !important;
}
.schat-input-row .schat-icon-btn,
.schat-input-row .schat-photo-btn,
.schat-input-row .schat-send-btn{ margin-bottom:0 !important; align-self:flex-end !important; }

/* ---------------------------------------------------------------
   Refined scrollbars — Apple-style: no arrow buttons, no track,
   thin translucent thumb that only reads as a hint.
   Applies to the composer textarea and the message list.
   --------------------------------------------------------------- */
.schat-input-row textarea,
.schat-body,
.schat-inbox-list{
	scrollbar-width:thin !important;
	scrollbar-color:rgba(255,255,255,.22) transparent !important;
	overscroll-behavior:contain !important;
	-webkit-overflow-scrolling:touch !important;
}
.schat-input-row textarea::-webkit-scrollbar,
.schat-body::-webkit-scrollbar,
.schat-inbox-list::-webkit-scrollbar{
	width:6px !important; height:6px !important;
	background:transparent !important;
}
.schat-input-row textarea::-webkit-scrollbar-track,
.schat-body::-webkit-scrollbar-track,
.schat-inbox-list::-webkit-scrollbar-track{
	background:transparent !important; border:none !important; box-shadow:none !important;
}
.schat-input-row textarea::-webkit-scrollbar-thumb,
.schat-body::-webkit-scrollbar-thumb,
.schat-inbox-list::-webkit-scrollbar-thumb{
	background-color:rgba(255,255,255,.18) !important;
	border-radius:99px !important; border:none !important;
	transition:background-color .2s ease;
}
.schat-input-row textarea:hover::-webkit-scrollbar-thumb,
.schat-body:hover::-webkit-scrollbar-thumb,
.schat-inbox-list:hover::-webkit-scrollbar-thumb{
	background-color:rgba(255,255,255,.30) !important;
}
/* Kill the native stepper arrows / corner that were showing up */
.schat-input-row textarea::-webkit-scrollbar-button,
.schat-body::-webkit-scrollbar-button,
.schat-inbox-list::-webkit-scrollbar-button{
	display:none !important; width:0 !important; height:0 !important;
}
.schat-input-row textarea::-webkit-scrollbar-corner,
.schat-body::-webkit-scrollbar-corner,
.schat-inbox-list::-webkit-scrollbar-corner{
	background:transparent !important;
}

/* Composer only: no visible scrollbar at all. It still scrolls internally
   once past the 5-line cap, but the bar is fully hidden so the input reads
   as a clean rounded pill (Telegram-style). The message list keeps its
   subtle Apple-style thumb from the rules above. */
.schat-input-row textarea{
	scrollbar-width:none !important;          /* Firefox */
	-ms-overflow-style:none !important;       /* old Edge */
}
.schat-input-row textarea::-webkit-scrollbar{
	width:0 !important; height:0 !important;
	display:none !important;                  /* WebKit/Blink */
}

.schat-icon-btn{
	width:36px !important;height:36px !important;
	min-width:36px !important; min-height:36px !important;
	padding:0 !important; border:none !important;
	border-radius:50% !important;
	background:transparent !important; color:var(--sec) !important; flex-shrink:0;
	display:inline-flex !important; align-items:center !important; justify-content:center !important;
	-webkit-appearance:none !important; appearance:none !important;
	-webkit-tap-highlight-color:transparent !important;
	overflow:hidden !important;
	transition:background-color .18s ease,color .18s ease,transform .18s cubic-bezier(.34,1.56,.64,1);
}
.schat-icon-btn:hover{background:rgba(255,255,255,.08) !important;}
.schat-icon-btn:focus,
.schat-icon-btn:focus-visible{ outline:none !important; box-shadow:none !important; }
.schat-icon-btn svg{width:22px !important;height:22px !important;stroke:currentColor !important;stroke-width:1.8 !important;}

/* Send button — perfect circle, gold fill, no square hover bleed. */
.schat-send-btn,
.schat-input-row .schat-send-btn{
	background:var(--gold) !important; color:#141414 !important;
	border-radius:50% !important; border:none !important;
	width:36px !important; height:36px !important;
	min-width:36px !important; min-height:36px !important;
	padding:0 !important; overflow:hidden !important;
	display:inline-flex !important; align-items:center !important; justify-content:center !important;
}
.schat-send-btn svg{
	stroke:#141414 !important; fill:none !important;
	width:18px !important; height:18px !important;
	position:relative !important; left:-1px !important; /* optically centre the paper plane */
}
.schat-send-btn:hover{ background:var(--gold-light) !important; transform:scale(1.05) !important; }
.schat-send-btn:active{ transform:scale(.92) !important; }
.schat-send-btn:disabled{ opacity:.4 !important; cursor:default !important; transform:none !important; }
.schat-send-btn:disabled:hover{ background:var(--gold) !important; transform:none !important; }

/* While a chat is open the page behind must not scroll — on Android
   that scroll is what exposes a blank strip under the panel. */
body.schat-locked{
	overflow:hidden !important;
	overscroll-behavior:none !important;
	touch-action:none !important;
}
body.schat-locked .schat-overlay,
body.schat-locked .schat-inbox-overlay{ touch-action:auto !important; }

/* The overlay stops at the top of the keyboard, so during the keyboard's
   slide-in animation (and on any browser that reports the resize late)
   the strip underneath would briefly show the feed. This paints that
   strip the chat's own background colour so there's nothing to see. */
/* The overlay is only as tall as the visible viewport, so everything
   below it — the keyboard's strip, and any layout-viewport overhang the
   browser still paints — would show the feed. This covers that whole
   region in the chat's own background colour. Height is generous on
   purpose: it is behind the keyboard, so overshooting is invisible, but
   undershooting leaves the exact gap we are fixing. */
body.schat-locked::after{
	content:'' !important;
	position:fixed !important;
	left:0 !important; right:0 !important;
	/* Start exactly where the overlay ends and run well past the bottom
	   of the screen. top is the overlay's own offset plus its height. */
	top:calc(var(--schat-vtop, 0px) + var(--schat-vh, 100dvh)) !important;
	bottom:auto !important;
	height:100dvh !important;
	background:var(--bg, #141414) !important;
	z-index:9996 !important;
	pointer-events:none !important;
}

/* ---- Admin front-end inbox widget ---- */
.schat-inbox-overlay{
	position:fixed !important; z-index:9997 !important;
	left:0 !important; right:0 !important;
	background:rgba(0,0,0,.55) !important; display:none !important;
	align-items:flex-end; justify-content:center;
	/* Same visual-viewport pinning as .schat-overlay above. */
	top:0 !important;
	bottom:auto !important;
	height:var(--schat-vh, 100dvh) !important;
	transform:translateY(var(--schat-vtop, 0px)) !important;
}
.schat-inbox-overlay.open{ display:flex !important; }
.schat-inbox-panel{
	width:100%; max-width:460px; height:80dvh; max-height:100%; background:#141414;
	border-radius:20px 20px 0 0; overflow:hidden; display:flex; flex-direction:column;
	box-shadow:0 -8px 40px rgba(0,0,0,.5);
}
@media(min-width:641px){
	.schat-inbox-overlay{ align-items:center !important; justify-content:flex-end !important; padding-right:28px; background:rgba(0,0,0,.35) !important; }
	.schat-inbox-panel{ width:380px; max-width:380px; height:640px; max-height:82vh; border-radius:16px; box-shadow:0 12px 48px rgba(0,0,0,.55); }
}
.schat-inbox-head{
	padding:14px 16px; border-bottom:1px solid rgba(255,255,255,.08);
	display:flex; align-items:center; justify-content:space-between; background:#1C1C1C; flex-shrink:0;
}
.schat-inbox-head h3{ margin:0; color:#F2F2F2; font-size:14.5px; font-weight:600; font-family:"Inter",sans-serif; }
.schat-inbox-head button{
	all:unset !important; box-sizing:border-box !important;
	background:none !important;color:#9A9A9A !important;cursor:pointer !important;
	width:32px !important;height:32px !important;display:inline-flex !important;
	align-items:center !important;justify-content:center !important;border-radius:50% !important;
}
.schat-inbox-head button:hover{ background:rgba(255,255,255,.08) !important; }
.schat-inbox-head button svg{ display:block !important; width:18px !important;height:18px !important; stroke:currentColor !important; fill:none !important; }
.schat-inbox-list{ flex:1; overflow-y:auto; }
.schat-inbox-row{ display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid rgba(255,255,255,.06); cursor:pointer; font-family:"Inter",sans-serif; }
.schat-inbox-row:hover{ background:rgba(255,255,255,.03); }
.schat-inbox-row .schat-inbox-name{ color:#F2F2F2; font-size:14px; font-weight:600; }
.schat-inbox-row .schat-inbox-preview{ color:#9A9A9A; font-size:12.5px; margin-top:2px; }
.schat-inbox-row .schat-inbox-time{ color:#7A7A7A; font-size:11px; white-space:nowrap; }
.schat-inbox-row .schat-inbox-badge{ background:#E24C4C;color:#fff;border-radius:10px;padding:1px 7px;font-size:10.5px;font-weight:700; }
.schat-inbox-empty{ padding:40px 24px; text-align:center; color:#9A9A9A; font-family:"Inter",sans-serif; font-size:13.5px; }

/* ===================================================================
   PREMIUM LAYER — iMessage-inspired, warm & feminine.
   Loaded last so it wins on specificity. Everything below is purely
   visual; no layout/scroll behaviour is touched.
   =================================================================== */

.schat-root{
	/* Neutral near-black base. */
	--bg:#0D0D0E;
	--card:#191919;
	--text:#F5F5F6;
	--sec:#9E9E9E;
	--line:rgba(255,255,255,.07);

	/* Received-bubble surface — lifted charcoal, never pure grey. */
	--recv:#212122;
	--recv-2:#1A1A1B;

	--font:-apple-system,BlinkMacSystemFont,"SF Pro Text","SF Pro Display","Inter","Segoe UI",Helvetica,Arial,sans-serif;
	font-family:var(--font) !important;
	-webkit-font-smoothing:antialiased !important;
	-moz-osx-font-smoothing:grayscale !important;
	text-rendering:optimizeLegibility !important;
	font-feature-settings:"kern" 1,"liga" 1,"calt" 1 !important;
}

/* ---- Panel: soft vignette instead of a flat slab ---- */
.schat-panel{
	background:
		radial-gradient(120% 70% at 50% 0%, rgba(255,255,255,.030) 0%, rgba(255,255,255,0) 62%),
		linear-gradient(180deg,#131314 0%, var(--bg) 42%, #080809 100%) !important;
	box-shadow:0 -10px 60px rgba(0,0,0,.62), 0 0 0 .5px rgba(255,255,255,.05) inset !important;
}
@media(min-width:641px){
	.schat-panel{ box-shadow:0 24px 70px rgba(0,0,0,.62), 0 0 0 .5px rgba(255,255,255,.06) inset !important; }
}

/* ---- Header: frosted glass, like iOS navigation bars ---- */
.schat-header{
	background:linear-gradient(180deg, rgba(34,34,36,.92) 0%, rgba(25,25,25,.82) 100%) !important;
	-webkit-backdrop-filter:saturate(180%) blur(22px) !important;
	backdrop-filter:saturate(180%) blur(22px) !important;
	border-bottom:.5px solid rgba(255,255,255,.09) !important;
	padding:13px 16px !important;
}
.schat-header img{
	width:40px !important; height:40px !important;
	box-shadow:0 0 0 1.5px rgba(255,255,255,.16), 0 3px 10px rgba(0,0,0,.45) !important;
}
.schat-header .schat-h-name{
	font-size:15.5px !important; font-weight:650 !important;
	letter-spacing:-.017em !important; color:#FAFAFB !important;
}
.schat-header .schat-h-sub{
	font-size:11.5px !important; letter-spacing:.01em !important;
	color:var(--sec) !important; opacity:.9 !important;
}

/* ---- Message list: a touch more air between bubbles ---- */
.schat-body{
	/* Extra bottom padding so the last bubble — or a "Seen" receipt under
	   it — never sits flush against the composer. */
	padding:18px 14px 20px !important;
	gap:3px !important;
	background:transparent !important;
}
/* Consecutive bubbles from the same side hug; a change of side opens up.
   This is the single biggest thing that makes a thread read as iMessage. */
.schat-row + .schat-row{ margin-top:3px !important; }
.schat-row.mine + .schat-row.theirs,
.schat-row.theirs + .schat-row.mine{ margin-top:11px !important; }

/* ---- BUBBLES ---- */
.schat-bubble{
	padding:9px 15px 10px !important;
	border-radius:20px !important;
	font-size:15.5px !important;
	line-height:1.38 !important;
	letter-spacing:-.011em !important;
	font-weight:420 !important;
	position:relative !important;
	transition:transform .22s cubic-bezier(.34,1.42,.64,1) !important;
}

/* Sent — same charcoal weight as received, lifted with a faint rose tint.
   Just enough hue to read as "mine" at a glance; the text stays white. */
.schat-row.mine .schat-bubble{
	background:linear-gradient(168deg,#3A2E33 0%,#31262A 100%) !important;
	color:#F7F2F3 !important;
	font-weight:450 !important;
	border:.5px solid rgba(233,175,190,.13) !important;
	border-bottom-right-radius:7px !important;
	box-shadow:
		0 1px 2px rgba(0,0,0,.30),
		inset 0 1px 0 rgba(255,220,229,.075) !important;
}

/* Received — lifted charcoal with a hairline rim so it reads as a
   physical surface, not a flat rectangle */
.schat-row.theirs .schat-bubble{
	background:linear-gradient(168deg, var(--recv) 0%, var(--recv-2) 100%) !important;
	color:#EDEDEE !important;
	border:.5px solid rgba(255,255,255,.085) !important;
	border-bottom-left-radius:7px !important;
	box-shadow:
		0 1px 2px rgba(0,0,0,.34),
		inset 0 1px 0 rgba(255,255,255,.055) !important;
}

/* Entrance — bubbles rise and settle with a little spring */
.schat-row{ animation:schatRise .34s cubic-bezier(.22,1.18,.36,1) both !important; }
@keyframes schatRise{
	from{ opacity:0; transform:translateY(9px) scale(.975); }
	to  { opacity:1; transform:translateY(0)   scale(1); }
}

/* Long-press / active feedback */
.schat-row.schat-pressing .schat-bubble{ transform:scale(.965) !important; }

/* ---- Photos ---- */
.schat-bubble img.schat-photo{
	border-radius:15px !important;
	box-shadow:0 3px 14px rgba(0,0,0,.42) !important;
}

/* ---- Timestamp / meta: quieter, more refined ---- */
/* ---- Timestamp: hidden until the bubble is tapped ----
   Collapsed with grid-template-rows so it animates open smoothly instead
   of popping, and takes zero space while closed. */
.schat-meta{
	font-size:10.5px !important;
	letter-spacing:.028em !important;
	font-weight:480 !important;
	color:#8A8A8C !important;
	margin:0 4px !important;
	display:grid !important;
	grid-template-rows:0fr !important;
	opacity:0 !important;
	overflow:hidden !important;
	transition:grid-template-rows .26s cubic-bezier(.32,.72,0,1),
	           opacity .2s ease,
	           margin-top .26s cubic-bezier(.32,.72,0,1) !important;
}
.schat-meta > *{ min-height:0 !important; }
.schat-row.schat-show-time .schat-meta{
	grid-template-rows:1fr !important;
	opacity:.78 !important;
	margin-top:4px !important;
}
/* Admin action buttons must stay reachable without tapping first. */
.schat-meta .schat-del-btn,
.schat-meta .schat-notify-btn{ display:none !important; }
.schat-row.schat-show-time .schat-meta .schat-del-btn{ display:inline-flex !important; }
.schat-row.schat-show-time .schat-meta .schat-notify-btn{ display:inline-flex !important; }

.schat-bubble{ cursor:pointer !important; }

/* ============================================================
   MESSAGE GROUPING (iMessage-style clustering) — 9.5 pass
   Rows the JS tagged as a continuation (.schat-grouped-cont) sit tight
   under the previous one; the previous row (.schat-grouped-prev) flattens
   the corner that now faces into the run, so a run of bubbles reads as one
   cohesive column with a single tail at the bottom.
   ============================================================ */
/* Default gap between rows stays as-is; tighten only within a run. */
.schat-row.schat-grouped-cont{ margin-top:2px !important; }

/* MINE: the corner facing up-into-the-run is the bottom-right.
   A continuation flattens its TOP-right; the prev flattens its BOTTOM-right. */
.schat-row.mine.schat-grouped-cont .schat-bubble{
	border-top-right-radius:6px !important;
}
.schat-row.mine.schat-grouped-prev .schat-bubble{
	border-bottom-right-radius:6px !important;
}

/* THEIRS: mirror on the left side. */
.schat-row.theirs.schat-grouped-cont .schat-bubble{
	border-top-left-radius:6px !important;
}
.schat-row.theirs.schat-grouped-prev .schat-bubble{
	border-bottom-left-radius:6px !important;
}

/* A hearted bubble hangs its badge below itself, so never flatten/tighten
   into it — give it room like a run tail. */
.schat-row .schat-bubble.has-loved{
	border-radius:16px !important;
}
.schat-row.schat-grouped-prev + .schat-row{ }  /* reserved for future tuning */


/* ---- Timestamp header inside the Reply/Copy menu ---- */
.schat-ctx-time{
	padding:9px 13px 8px !important;
	font-size:11px !important;
	font-weight:600 !important;
	letter-spacing:.03em !important;
	color:#8A8A8C !important;
	border-bottom:.5px solid rgba(255,255,255,.09) !important;
	margin-bottom:4px !important;
	white-space:nowrap !important;
	pointer-events:none !important;
}

/* ---- Reaction badge: match the new palette ---- */
.schat-loved{
	background:linear-gradient(168deg,#2B2B2C,#202021) !important;
	border:1.5px solid #0D0D0E !important;
	box-shadow:0 2px 7px rgba(0,0,0,.5) !important;
}

/* ---- Day separator: airy pill, not a chip ---- */
.schat-day-sep{
	background:transparent !important;
	color:#87878A !important;
	font-size:11px !important;
	font-weight:600 !important;
	letter-spacing:.07em !important;
	text-transform:uppercase !important;
	margin:16px 0 10px !important;
	padding:0 !important;
}

/* ---- Typing indicator ---- */
.schat-typing-bubble{
	background:linear-gradient(168deg, var(--recv) 0%, var(--recv-2) 100%) !important;
	border:.5px solid rgba(255,255,255,.085) !important;
	border-radius:20px !important; border-bottom-left-radius:7px !important;
	box-shadow:0 1px 2px rgba(0,0,0,.34) !important;
}
.schat-typing-bubble i{ background:var(--sec) !important; opacity:.7 !important; }

/* ---- Quoted reply ---- */
.schat-reply-quote{
	border-radius:11px !important;
	border-left:3px solid rgba(255,255,255,.32) !important;
	background:rgba(255,255,255,.055) !important;
	font-size:12.5px !important;
}
.schat-row.mine .schat-reply-quote{
	border-left-color:rgba(255,255,255,.28) !important;
	background:rgba(0,0,0,.20) !important;
}
.schat-reply-quote .schat-reply-who{ letter-spacing:-.005em !important; }

/* ---- Seen receipt ---- */
.schat-seen{
	font-size:10.5px !important; letter-spacing:.03em !important;
	color:#8A8A8C !important;
	/* Sits BELOW the last sent bubble as its own row. The base sheet pulls
	   it up with a negative margin, which clipped it against the bubble —
	   give it real clearance instead. */
	align-self:flex-end !important;
	margin:5px 4px 2px 0 !important;
	opacity:.72 !important;
	line-height:1 !important;
	flex-shrink:0 !important;
}
/* The row it follows needs breathing room so the receipt never collides
   with the bubble above. Class is applied by JS rather than :has(), which
   older Android WebView builds don't support. */
.schat-row.schat-has-seen{ margin-bottom:2px !important; }
/* A hearted bubble hangs a badge 12px below itself — push the receipt
   clear of it so the two never overlap. Class set by JS (no :has()). */
.schat-row.schat-has-seen.schat-seen-loved{ margin-bottom:10px !important; }
.schat-seen svg{ stroke:#9A9A9C !important; }

/* ---- "New Messages" divider ---- */
.schat-new-sep{
	display:flex !important;
	align-items:center !important;
	gap:10px !important;
	align-self:stretch !important;
	width:100% !important;
	margin:14px 0 12px !important;
	animation:schatNewIn .42s cubic-bezier(.22,1.1,.36,1) both !important;
}
.schat-new-sep::before,
.schat-new-sep::after{
	content:"" !important;
	flex:1 1 auto !important;
	height:1px !important;
	background:linear-gradient(90deg, rgba(233,175,190,0) 0%, rgba(233,175,190,.32) 100%) !important;
}
.schat-new-sep::after{
	background:linear-gradient(90deg, rgba(233,175,190,.32) 0%, rgba(233,175,190,0) 100%) !important;
}
.schat-new-sep span{
	flex:0 0 auto !important;
	font-size:10.5px !important;
	font-weight:700 !important;
	letter-spacing:.09em !important;
	text-transform:uppercase !important;
	color:#E9AFBE !important;
	background:rgba(233,175,190,.10) !important;
	border:.5px solid rgba(233,175,190,.20) !important;
	border-radius:20px !important;
	padding:4px 12px !important;
	white-space:nowrap !important;
	line-height:1 !important;
}
@keyframes schatNewIn{
	from{ opacity:0; transform:translateY(-5px); }
	to  { opacity:1; transform:translateY(0); }
}
/* Retired once the user scrolls past it — collapses away rather than
   vanishing, so the thread doesn't jump. */
.schat-new-sep.schat-new-sep-out{
	animation:none !important;
	opacity:0 !important;
	margin:0 !important;
	max-height:0 !important;
	overflow:hidden !important;
	transition:opacity .28s ease, max-height .38s cubic-bezier(.32,.72,0,1),
	           margin .38s cubic-bezier(.32,.72,0,1) !important;
}
.schat-new-sep{ max-height:40px !important; }
@media (prefers-reduced-motion:reduce){
	.schat-new-sep{ animation:none !important; }
	.schat-new-sep.schat-new-sep-out{ transition:none !important; }
}

/* ---- Composer: floating capsule ---- */
.schat-footer{
	background:linear-gradient(180deg, rgba(25,25,25,.80) 0%, rgba(16,16,17,.96) 100%) !important;
	-webkit-backdrop-filter:saturate(180%) blur(22px) !important;
	backdrop-filter:saturate(180%) blur(22px) !important;
	border-top:.5px solid rgba(255,255,255,.08) !important;
	padding:10px 12px 12px !important;
}
.schat-input-row textarea{
	background:rgba(255,255,255,.055) !important;
	border:1px solid rgba(255,255,255,.10) !important;
	border-radius:21px !important;
	color:var(--text) !important;
	font-size:15.5px !important;
	letter-spacing:-.011em !important;
	padding-left:15px !important; padding-right:15px !important;
	transition:border-color .2s ease, background .2s ease, box-shadow .2s ease !important;
}
.schat-input-row textarea:focus{
	border-color:rgba(255,255,255,.30) !important;
	background:rgba(255,255,255,.085) !important;
	box-shadow:0 0 0 3.5px rgba(255,255,255,.06) !important;
}
.schat-input-row textarea::placeholder{ color:#6A6A6C !important; }

/* ---- Send button: the one saturated accent in the UI ---- */
.schat-send-btn{
	background:linear-gradient(168deg,#F7F3F4 0%,#E4DDDF 100%) !important;
	color:#1A1518 !important;
	box-shadow:0 2px 8px -3px rgba(0,0,0,.45), inset 0 1px 0 rgba(255,255,255,.55) !important;
	transition:transform .2s cubic-bezier(.34,1.5,.64,1), opacity .2s ease, box-shadow .2s ease !important;
}
.schat-send-btn svg{ stroke:#1A1518 !important; fill:none !important; }
.schat-send-btn:active{ transform:scale(.9) !important; }
/* Enabled hover — stays neutral; the base sheet used --gold-light here. */
.schat-send-btn:hover:not(:disabled){
	background:linear-gradient(168deg,#FFFFFF 0%,#EFE9EB 100%) !important;
	transform:scale(1.05) !important;
}

/* Disabled: fully inert. The base stylesheet has a
   `.schat-send-btn:disabled:hover{background:var(--gold)}` rule, and on
   touch devices a tap fires :hover — which repainted the button pink on
   an empty composer. These cover every state so nothing lights up. */
.schat-send-btn:disabled,
.schat-send-btn:disabled:hover,
.schat-send-btn:disabled:active,
.schat-send-btn:disabled:focus,
.schat-send-btn:disabled:focus-visible,
.schat-input-row .schat-send-btn:disabled,
.schat-input-row .schat-send-btn:disabled:hover,
.schat-input-row .schat-send-btn:disabled:active{
	opacity:.32 !important;
	background:rgba(255,255,255,.09) !important;
	background-image:none !important;
	box-shadow:none !important;
	transform:none !important;
	cursor:default !important;
	pointer-events:none !important;
	-webkit-tap-highlight-color:transparent !important;
}
.schat-send-btn:disabled svg,
.schat-send-btn:disabled:hover svg,
.schat-send-btn:disabled:active svg{ stroke:var(--sec) !important; }

/* ---- Empty state ---- */
.schat-empty svg{ stroke:var(--sec) !important; opacity:.34 !important; }
.schat-empty p{
	font-size:14.5px !important; letter-spacing:-.008em !important;
	color:#9A9A9C !important; max-width:250px !important;
}

/* ---- FAB (floating circle / pill only — NOT the small header icon) ---- */
.schat-fab:not(.schat-fab-icon){
	background:linear-gradient(168deg,#2B2B2C,#202021) !important;
	border:.5px solid rgba(255,255,255,.10) !important;
	box-shadow:0 6px 20px -6px rgba(0,0,0,.55), 0 2px 8px rgba(0,0,0,.35) !important;
}
.schat-fab:not(.schat-fab-icon) svg{ stroke:#F2F2F2 !important; }

/* The small header icon button is deliberately left alone so it keeps
   matching the sign-out button sitting directly above it. */

/* Respect reduced-motion preferences. */
@media (prefers-reduced-motion:reduce){
	.schat-row, .schat-loved{ animation:none !important; }
	.schat-bubble{ transition:none !important; }
}
/* ============================================================
   SOFT SKIN v1 — warmer palette, rounder bubbles, gentler light.
   Additive only: appended last so it wins without editing the
   rules above. Remove this whole block to revert exactly.
   ============================================================ */
.schat-root{
	--bg:#151011 !important;
	--card:#211A1C !important;
	--text:#F6F1F2 !important;
	--sec:#A79BA0 !important;
	--line:rgba(255,255,255,.07) !important;
	--gold:#DCAFB2 !important;
	--gold-light:#EDCACC !important;
	--gold-dark:#C08A8E !important;
	--font:"Sen","Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif !important;
	--ink:#2B1A1E;
}

/* ---- Thread surface: ambient warmth instead of flat black ---- */
.schat-body{
	padding:20px 14px 16px !important;
	gap:12px !important;
	background:
		radial-gradient(70% 40% at 12% 0%, rgba(220,175,178,.07) 0%, transparent 60%),
		radial-gradient(60% 45% at 92% 100%, rgba(139,92,246,.05) 0%, transparent 62%),
		var(--bg) !important;
}

/* ---- Bubbles ---- */
.schat-bubble{
	padding:11px 15px !important;
	border-radius:22px !important;
	font-size:14.5px !important;
	line-height:1.5 !important;
	letter-spacing:-.1px !important;
	transition:transform .18s cubic-bezier(.34,1.56,.64,1) !important;
}
.schat-row.theirs .schat-bubble{
	background:linear-gradient(180deg,#241C1F 0%,#1E1719 100%) !important;
	color:var(--text) !important;
	border:1px solid rgba(255,255,255,.06) !important;
	border-bottom-left-radius:8px !important;
	box-shadow:0 1px 0 rgba(255,255,255,.03) inset, 0 6px 18px rgba(0,0,0,.28) !important;
}
.schat-row.mine .schat-bubble{
	background:linear-gradient(180deg,#EBC7C9 0%,#DCAFB2 55%,#CE9DA5 100%) !important;
	color:var(--ink) !important;
	border:none !important;
	border-bottom-right-radius:8px !important;
	box-shadow:0 1px 0 rgba(255,255,255,.45) inset, 0 6px 20px rgba(220,175,178,.20) !important;
}
.schat-bubble img.schat-photo{ border-radius:16px !important; margin-bottom:8px !important; }

/* ---- Header ---- */
.schat-header{
	padding:15px 16px !important;
	background:linear-gradient(180deg,#241C1F 0%,#1F191B 100%) !important;
	border-bottom:1px solid rgba(255,255,255,.06) !important;
}
.schat-header img{
	width:40px !important; height:40px !important;
	border:1.5px solid rgba(220,175,178,.45) !important;
	box-shadow:0 0 0 3px rgba(220,175,178,.10) !important;
}
.schat-header .schat-h-name{
	font-family:"Zilla Slab",Georgia,serif !important;
	font-size:16px !important; font-weight:600 !important;
	letter-spacing:-.2px !important;
}
.schat-header .schat-h-sub{ letter-spacing:.2px !important; }

/* ---- Day separator ---- */
.schat-day-sep{
	font-size:10.5px !important;
	text-transform:uppercase !important;
	letter-spacing:1.6px !important;
	text-indent:1.6px !important;
	font-weight:600 !important;
	color:rgba(220,175,178,.75) !important;
	background:rgba(220,175,178,.09) !important;
	border:1px solid rgba(220,175,178,.14) !important;
	padding:5px 14px !important;
	border-radius:100px !important;
	margin:10px 0 !important;
}

/* ---- Typing dots ---- */
.schat-typing-bubble{
	border-radius:20px !important;
	background:linear-gradient(180deg,#241C1F,#1E1719) !important;
	border:1px solid rgba(255,255,255,.06) !important;
}
.schat-typing-bubble i{ background:var(--gold) !important; }

/* ---- Reaction chip sits on the new surface ---- */
.schat-loved{
	background:#241C1F !important;
	border:1.5px solid var(--bg) !important;
	box-shadow:0 2px 8px rgba(0,0,0,.45) !important;
}

/* ---- Composer ---- */
.schat-input-row textarea{
	border-radius:22px !important;
	padding:10px 16px !important;
	background:#1B1517 !important;
	border:1.5px solid rgba(255,255,255,.07) !important;
	letter-spacing:-.1px !important;
	transition:border-color .2s ease, box-shadow .2s ease !important;
}
.schat-input-row textarea:focus{
	border-color:rgba(220,175,178,.55) !important;
	box-shadow:0 0 0 3px rgba(220,175,178,.10) !important;
}
.schat-input-row textarea::placeholder{ color:#6B5F63 !important; }

.schat-send-btn,
.schat-input-row .schat-send-btn{
	background:linear-gradient(180deg,#EDCACC 0%,#DCAFB2 100%) !important;
	box-shadow:0 1px 0 rgba(255,255,255,.5) inset, 0 4px 14px rgba(220,175,178,.28) !important;
	transition:transform .18s cubic-bezier(.34,1.56,.64,1), box-shadow .2s ease !important;
}
.schat-send-btn svg{ stroke:var(--ink) !important; }
.schat-send-btn:hover{
	background:linear-gradient(180deg,#F2D5D7 0%,#E5BCBF 100%) !important;
	box-shadow:0 1px 0 rgba(255,255,255,.5) inset, 0 8px 20px rgba(220,175,178,.36) !important;
}

/* ---- Empty state ---- */
.schat-empty svg{ stroke:var(--gold) !important; opacity:.35 !important; }
.schat-empty p{ color:#8E8388 !important; }

/* ---- Softer entrance ---- */
@keyframes schatRowIn{
	from{ opacity:0; transform:translateY(10px) scale(.96); }
	to  { opacity:1; transform:translateY(0)   scale(1);    }
}
.schat-row{ animation-duration:.42s !important; }

/* ---- Thin warm scrollbar ---- */
.schat-body::-webkit-scrollbar{ width:6px !important; }
.schat-body::-webkit-scrollbar-thumb{
	background:rgba(220,175,178,.22) !important;
	border-radius:100px !important;
}
.schat-body::-webkit-scrollbar-track{ background:transparent !important; }

/* ---- Full-screen reaction blast (Telegram style) ----
   Fixed to the viewport and pointer-transparent, so it plays over the whole
   screen without ever blocking the thread. No !important on opacity or the
   animation shorthand: an !important declaration outranks a running
   animation and would pin these invisible. ---- */
.schat-blast{
	position:fixed !important;
	inset:0 !important;
	z-index:100050 !important;
	pointer-events:none !important;
	overflow:hidden !important;
	contain:strict;
}

.schat-blast b,
.schat-blast i{
	position:absolute !important;
	top:0 !important; left:0 !important;
	font-style:normal !important;
	font-weight:400 !important;
	line-height:1 !important;
	will-change:transform, opacity;
}

/* Hero heart: bubble -> centre screen, swelling as it goes. */
.schat-blast b{
	font-size:22px;
	filter:drop-shadow(0 12px 30px rgba(220,120,140,.38));
	/* Long and eased-out rather than quick and springy: it should bloom,
	   hold, and dissolve, not pop. */
	animation:schatBlastHero 2.5s cubic-bezier(.18,.72,.24,1) both;
}
@keyframes schatBlastHero{
	0%{
		opacity:0;
		transform:translate(-50%,-50%) translate(var(--x0), var(--y0)) scale(.55);
	}
	14%{
		opacity:.92;
		transform:translate(-50%,-50%) translate(var(--x0), var(--y0)) scale(1);
	}
	48%{
		opacity:1;
		transform:translate(-50%,-50%) translate(var(--x1), var(--y1)) scale(4.3);
	}
	64%{
		opacity:1;
		transform:translate(-50%,-50%) translate(var(--x1), var(--y1)) scale(4.55);
	}
	80%{
		opacity:.62;
		transform:translate(-50%,-50%) translate(var(--x1), var(--y1)) scale(4.75);
	}
	100%{
		opacity:0;
		transform:translate(-50%,-50%) translate(var(--x1), var(--y1)) scale(5.1);
	}
}

/* Spray: thrown out from centre, tumbling and drifting down as it fades. */
.schat-blast i{
	font-size:18px;
	filter:drop-shadow(0 4px 10px rgba(220,120,140,.25));
	/* Duration is set per-heart in JS; this is the fallback. */
	animation:schatBlastSpray 2.3s cubic-bezier(.12,.62,.24,1) both;
}
@keyframes schatBlastSpray{
	0%{
		opacity:0;
		transform:translate(-50%,-50%) translate(var(--px), var(--py)) scale(.25) rotate(0deg);
	}
	16%{
		opacity:.95;
		transform:translate(-50%,-50%) translate(var(--px), var(--py)) scale(var(--sc)) rotate(0deg);
	}
	55%{
		opacity:.8;
		transform:translate(-50%,-50%)
		          translate(calc(var(--px) + var(--dx) * .62), calc(var(--py) + var(--dy) * .62 - 14px))
		          scale(var(--sc)) rotate(calc(var(--rot) * .55));
	}
	100%{
		opacity:0;
		transform:translate(-50%,-50%)
		          translate(calc(var(--px) + var(--dx)), calc(var(--py) + var(--dy) + 26px))
		          scale(calc(var(--sc) * .82)) rotate(var(--rot));
	}
}

@media (prefers-reduced-motion:reduce){
	.schat-blast{ display:none !important; }
}

/* ---- Emoji-only messages: no bubble, just the emoji ----
   Selectors are written two-classes-deep on purpose: `.schat-row.mine
   .schat-bubble` scores higher than `.schat-bubble.schat-emoji-only`, so
   the shorter form lost even with !important on both sides. ---- */
.schat-row .schat-bubble.schat-emoji-only,
.schat-row.mine .schat-bubble.schat-emoji-only,
.schat-row.theirs .schat-bubble.schat-emoji-only{
	background:none !important;
	background-image:none !important;
	border:none !important;
	box-shadow:none !important;
	padding:2px 4px !important;
	border-radius:18px !important;
	color:var(--text) !important;
}
.schat-bubble.schat-emoji-only .schat-text{
	font-size:44px !important;
	line-height:1.15 !important;
	letter-spacing:2px !important;
}
.schat-row.mine .schat-bubble.schat-emoji-only .schat-text{ text-align:right !important; }
.schat-bubble.schat-emoji-only .schat-loved{ bottom:-8px !important; }

/* ---- Grouping, retuned for the 22px bubble ---- */
.schat-row.mine.schat-grouped-prev  .schat-bubble{ border-bottom-right-radius:9px !important; }
.schat-row.mine.schat-grouped-cont  .schat-bubble{ border-top-right-radius:9px !important; }
.schat-row.theirs.schat-grouped-prev .schat-bubble{ border-bottom-left-radius:9px !important; }
.schat-row.theirs.schat-grouped-cont .schat-bubble{ border-top-left-radius:9px !important; }
.schat-row.schat-grouped-cont{ margin-top:-7px !important; }
.schat-row .schat-bubble.has-loved{ border-radius:22px !important; }
.schat-row.schat-grouped-cont .schat-bubble.schat-emoji-only,
.schat-row.schat-grouped-prev .schat-bubble.schat-emoji-only{ border-radius:18px !important; }

/* ---- Jump-to-original highlight ----
   The old flash animated `background` on .schat-bubble, but every bubble
   carries `background: ... !important` from the palette, and an !important
   declaration outranks a running animation — so the flash never showed on
   either side. The highlight now lives on a pseudo-element behind the
   bubble, where nothing competes with it. ---- */
.schat-row{ position:relative !important; }
.schat-row .schat-bubble{ z-index:1 !important; }

.schat-row.schat-flash::after{
	content:"";
	position:absolute;
	top:-6px; bottom:-6px; left:-10px; right:-10px;
	border-radius:26px;
	background:rgba(220,175,178,.20);
	box-shadow:0 0 0 1px rgba(220,175,178,.34) inset;
	pointer-events:none;
	z-index:0;
	opacity:0;
	animation:schatFlashRing 1.75s cubic-bezier(.22,.9,.32,1) both;
}
@keyframes schatFlashRing{
	0%   { opacity:0;   transform:scale(.97); }
	12%  { opacity:1;   transform:scale(1);   }
	62%  { opacity:1;   transform:scale(1);   }
	100% { opacity:0;   transform:scale(1.01); }
}

/* The quote itself should look tappable. */
.schat-reply-quote{ cursor:pointer !important; transition:filter .18s ease, transform .18s ease !important; }
.schat-reply-quote:active{ filter:brightness(1.12) !important; transform:scale(.985) !important; }

@media (prefers-reduced-motion: reduce){
	.schat-row.schat-flash::after{ animation:none !important; opacity:1 !important; }
}

/* ---- Composer photo button ---- */
.schat-input-row .schat-photo-btn{
	background:transparent !important;
	border:none !important;
	color:var(--sec) !important;
	width:36px !important; height:36px !important;
	min-width:36px !important;
	border-radius:50% !important;
	display:inline-flex !important; align-items:center !important; justify-content:center !important;
	padding:0 !important; cursor:pointer !important;
	transition:background .18s ease, color .18s ease !important;
}
.schat-input-row .schat-photo-btn svg{ width:21px !important; height:21px !important; stroke-width:1.8 !important; }
.schat-input-row .schat-photo-btn:hover{ background:rgba(220,175,178,.12) !important; color:var(--gold) !important; }
.schat-input-row .schat-photo-btn:active{ transform:scale(.92) !important; }

/* ---- Attachment chip above the composer ---- */
.schat-attach-preview{
	display:none;
	align-items:center !important; gap:10px !important;
	margin:0 0 8px !important; padding:8px 10px !important;
	background:#1B1517 !important;
	border:1px solid rgba(255,255,255,.07) !important;
	border-radius:14px !important;
}
.schat-attach-preview.show{ display:flex !important; }
.schat-attach-preview img{ width:42px !important; height:42px !important; border-radius:10px !important; object-fit:cover !important; }
.schat-attach-preview .schat-attach-name{
	flex:1 !important; min-width:0 !important;
	font-size:12.5px !important; color:var(--sec) !important;
	overflow:hidden !important; text-overflow:ellipsis !important; white-space:nowrap !important;
}
.schat-attach-remove{
	background:transparent !important; border:none !important; color:var(--sec) !important;
	width:28px !important; height:28px !important; border-radius:50% !important;
	display:inline-flex !important; align-items:center !important; justify-content:center !important;
	cursor:pointer !important; flex-shrink:0 !important; padding:0 !important;
}
.schat-attach-remove:hover{ background:rgba(255,255,255,.08) !important; color:var(--text) !important; }
.schat-attach-remove svg{ width:15px !important; height:15px !important; }

/* ---- Full-screen photo viewer ---- */
.schat-viewer{
	position:fixed !important; inset:0 !important;
	z-index:100060 !important;
	display:flex !important; align-items:center !important; justify-content:center !important;
	background:rgba(10,7,8,.94) !important;
	backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
	/* !important on both states: this sits outside the chat root, so a
	   theme's global rules can otherwise reach it. */
	opacity:0 !important;
	visibility:hidden !important;
	transition:opacity .22s ease !important;
	touch-action:none !important;
	overscroll-behavior:contain !important;
	pointer-events:auto !important;
}
.schat-viewer.open{ opacity:1 !important; visibility:visible !important; }

.schat-viewer-img{
	/* The gestures are handled in JS, so the browser must not claim them. */
	touch-action:none !important;
	-webkit-touch-callout:none !important;
	max-width:100% !important;
	max-height:100% !important;
	object-fit:contain !important;
	transform-origin:center center !important;
	transition:transform .18s cubic-bezier(.22,.9,.32,1);
	will-change:transform;
	cursor:zoom-in;
	user-select:none;
	-webkit-user-drag:none;
}
.schat-viewer.zoomed .schat-viewer-img{
	transition:none;
	cursor:grab;
}

/* Shown until the full photo has actually decoded. */
.schat-viewer-spin{
	position:absolute !important;
	width:30px !important; height:30px !important;
	border-radius:50% !important;
	border:2px solid rgba(255,255,255,.22) !important;
	border-top-color:#fff !important;
	animation:schatViewerSpin .8s linear infinite !important;
}
.schat-viewer.loaded .schat-viewer-spin{ display:none !important; }
/* No transition here on purpose - .schat-viewer-img carries the
   transform transition the gestures depend on. */
.schat-viewer:not(.loaded) .schat-viewer-img{ opacity:0 !important; }
@keyframes schatViewerSpin{ to{ transform:rotate(360deg); } }

.schat-viewer-close{
	position:absolute !important;
	top:calc(14px + env(safe-area-inset-top, 0px)) !important;
	right:14px !important;
	width:38px !important; height:38px !important;
	border-radius:50% !important;
	border:none !important;
	background:rgba(255,255,255,.12) !important;
	color:#fff !important;
	display:flex !important; align-items:center !important; justify-content:center !important;
	cursor:pointer !important;
	padding:0 !important;
}
.schat-viewer-close:hover{ background:rgba(255,255,255,.2) !important; }
.schat-viewer-close svg{ width:17px !important; height:17px !important; }

/* Chat photos read as tappable, and must not open the OS image menu on a
   long press — that press belongs to the reply/react gesture. */
.schat-bubble img.schat-photo{
	cursor:zoom-in !important;
	-webkit-touch-callout:none !important;
	-webkit-user-select:none !important;
	user-select:none !important;
	-webkit-user-drag:none !important;
}

/* ---- Photo messages ----
   Written two classes deep because `.schat-row.mine .schat-bubble` outranks
   a shorter selector even with !important on both. ---- */

/* Photo with no caption: the image IS the bubble. No frame at all. */
.schat-row .schat-bubble.schat-photo-only,
.schat-row.mine .schat-bubble.schat-photo-only,
.schat-row.theirs .schat-bubble.schat-photo-only{
	background:none !important;
	background-image:none !important;
	border:none !important;
	padding:0 !important;
	box-shadow:0 8px 24px rgba(0,0,0,.32) !important;
	border-radius:20px !important;
	overflow:hidden !important;
	line-height:0 !important;
}
.schat-row.mine   .schat-bubble.schat-photo-only{ border-bottom-right-radius:8px !important; }
.schat-row.theirs .schat-bubble.schat-photo-only{ border-bottom-left-radius:8px !important; }

.schat-bubble.schat-photo-only img.schat-photo{
	display:block !important;
	width:100% !important;
	max-width:100% !important;
	max-height:400px !important;
	object-fit:cover !important;
	margin:0 !important;
	border-radius:inherit !important;
}

/* Photo with a caption: a slim frame, image bleeding to its edges. */
.schat-row .schat-bubble.schat-has-photo:not(.schat-photo-only),
.schat-row.mine .schat-bubble.schat-has-photo:not(.schat-photo-only),
.schat-row.theirs .schat-bubble.schat-has-photo:not(.schat-photo-only){
	padding:4px 4px 9px !important;
	border-radius:20px !important;
}
.schat-bubble.schat-has-photo:not(.schat-photo-only) img.schat-photo{
	display:block !important;
	width:100% !important;
	max-height:360px !important;
	object-fit:cover !important;
	border-radius:16px !important;
	margin:0 0 7px !important;
}
.schat-bubble.schat-has-photo:not(.schat-photo-only) .schat-text{
	padding:0 8px !important;
}

/* Quoted reply above a photo keeps its own breathing room. */
.schat-bubble.schat-has-photo .schat-reply-quote{
	margin:4px 4px 7px !important;
}

/* The heart badge needs a surface to sit on now that the frame is gone. */
.schat-bubble.schat-photo-only.has-loved{
	overflow:visible !important;
}
.schat-bubble.schat-photo-only.has-loved img.schat-photo{
	border-radius:20px !important;
}

/* Photos fade in rather than snapping in once decoded. */
@keyframes schatPhotoIn{ from{ opacity:0; } to{ opacity:1; } }
.schat-bubble img.schat-photo{ animation:schatPhotoIn .3s ease both; }

/* ---- Upload bar ----
   Shown INSTEAD of the photo while it uploads. Fixed height is the whole
   point: the bubble occupies its final size on the very first frame, so the
   thread cannot grow underneath the scroll and leave the message straddling
   the keyboard. ---- */
.schat-bubble.schat-uploading{
	min-width:210px !important;
	padding:10px 12px !important;
}

.schat-uploadbar{
	display:flex !important;
	align-items:center !important;
	gap:10px !important;
	height:34px !important;
}

.schat-uploadbar-icon{
	display:flex !important;
	align-items:center !important;
	justify-content:center !important;
	width:26px !important; height:26px !important;
	flex-shrink:0 !important;
	border-radius:8px !important;
	background:rgba(43,26,30,.14) !important;
	color:currentColor !important;
	opacity:.75 !important;
}
.schat-uploadbar-icon svg{ width:15px !important; height:15px !important; }

.schat-uploadbar-body{
	display:flex !important;
	flex-direction:column !important;
	gap:5px !important;
	flex:1 1 auto !important;
	min-width:0 !important;
}

.schat-uploadbar-label{
	font-family:"Sen", sans-serif !important;
	font-size:11.5px !important;
	font-weight:600 !important;
	line-height:1 !important;
	opacity:.8 !important;
	white-space:nowrap !important;
}

.schat-uploadbar-track{
	position:relative !important;
	display:block !important;
	height:3px !important;
	border-radius:100px !important;
	background:rgba(43,26,30,.18) !important;
	overflow:hidden !important;
}
.schat-row.theirs .schat-uploadbar-track{ background:rgba(255,255,255,.14) !important; }

.schat-uploadbar-track i{
	position:absolute !important;
	left:0 !important; top:0 !important; bottom:0 !important;
	width:0%;
	border-radius:100px !important;
	background:currentColor !important;
	opacity:.85 !important;
	transition:width .18s linear !important;
}

.schat-uploadbar-pct{
	font-family:"Sen", sans-serif !important;
	font-size:11.5px !important;
	font-weight:700 !important;
	line-height:1 !important;
	min-width:30px !important;
	text-align:right !important;
	opacity:.85 !important;
	flex-shrink:0 !important;
}

/* Before there is a real percentage, the bar just sweeps. */
.schat-uploadbar.indeterminate .schat-uploadbar-track i{
	width:38% !important;
	transition:none !important;
	animation:schatBarSweep 1.15s ease-in-out infinite !important;
}
@keyframes schatBarSweep{
	0%   { transform:translateX(-105%); }
	100% { transform:translateX(275%); }
}

/* The revealed photo eases in rather than appearing abruptly. */
@keyframes schatPhotoReveal{
	from{ opacity:0; transform:scale(.985); }
	to  { opacity:1; transform:none; }
}
.schat-bubble.schat-photo-only img.schat-photo,
.schat-bubble.schat-has-photo img.schat-photo{
	animation:schatPhotoReveal .34s cubic-bezier(.22,.9,.32,1) both !important;
}

@media (prefers-reduced-motion: reduce){
	.schat-uploadbar.indeterminate .schat-uploadbar-track i{ animation:none !important; }
	.schat-bubble img.schat-photo{ animation:none !important; }
}
}

/* ---- Why a message failed ---- */
.schat-fail-reason{
	font-family:"Sen", sans-serif !important;
	font-size:11.5px !important;
	line-height:1.4 !important;
	color:#E8A0A0 !important;
	margin:5px 2px 0 !important;
	max-width:100% !important;
}
.schat-row.mine .schat-fail-reason{ text-align:right !important; }

</style>
SCHATCSS;
}

function schat_js_string() {
	return <<<'SCHATJS'
/**
 * Shatania Chat — front-end behavior.
 * Uses event delegation on document + jQuery, so it works no matter
 * when this file actually executes relative to the DOM being painted
 * (immune to caching/minify/defer plugins reordering things), and no
 * matter how many chat panels are on the page at once.
 */
(function ($) {
	'use strict';

	var state = {}; // per-member state: { lastId, pollTimer, pendingFile }

	// Display names used by reply quotes and the typing indicator.
	var SCHAT_ADMIN_NAME = 'Shatania';
	function SCHAT_MEMBER_NAME(memberId) {
		var ov = document.getElementById('schat-overlay-' + memberId);
		var el = ov && ov.querySelector('.schat-h-name');
		var n = el ? (el.textContent || '').trim() : '';
		return n || 'Member';
	}

	function getState(memberId) {
		if (!state[memberId]) {
			state[memberId] = { lastId: 0, sending: false, loading: false, newFromId: 0, pollTimer: null, pendingFile: null, lastRenderedDay: null, replyTo: 0, editing: 0, lastSeenId: 0, typingSentAt: 0, typingStopTimer: null,
				// Scroll-up paging: whether older messages remain on the
				// server, and whether a batch request is already in flight.
				hasOlder: false, backfilling: false,
				// Live activity: what we last told the server we were doing,
				// when we told it, and the timer that settles "scrolling"
				// back down to "viewing" once the thumb stops.
				actState: '', actSentAt: 0, actSettleTimer: null, lastPresLabel: '',
				autoScrollUntil: 0,
				// Celebration: the highest sequence number we've already
				// played, so a reopened panel never replays an old party.
				lastPartySeq: 0 };
		}
		return state[memberId];
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s || '';
		return d.innerHTML;
	}

	/* Server stores 'YYYY-MM-DD HH:MM:SS' in UTC. Parse it explicitly as
	   UTC, then all rendering below uses the *viewer's* own timezone —
	   so the same message reads 4:17 PM in IST and 3:47 AM in PDT. */
	function parseUTC(dt) {
		if (!dt) return new Date(NaN);
		if (dt instanceof Date) return dt;
		var s = String(dt).trim().replace(' ', 'T');
		// Only tag as UTC if no zone designator is already present.
		if (!/(Z|[+-]\d{2}:?\d{2})$/.test(s)) s += 'Z';
		var d = new Date(s);
		if (!isNaN(d.getTime())) return d;
		// Safari fallback for any unexpected format.
		var m = String(dt).match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
		if (m) return new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]));
		return new Date(NaN);
	}

	function dayLabel(dt) {
		var d = parseUTC(dt);
		var now = new Date();
		var isToday = d.toDateString() === now.toDateString();
		var yest = new Date(now); yest.setDate(now.getDate() - 1);
		var isYest = d.toDateString() === yest.toDateString();
		if (isToday) return 'Today';
		if (isYest) return 'Yesterday';
		return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: d.getFullYear() !== now.getFullYear() ? 'numeric' : undefined });
	}

	function timeLabel(dt) {
		var d = parseUTC(dt);
		if (isNaN(d.getTime())) return '';
		return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
	}

	function findOverlay(memberId) {
		return document.getElementById('schat-overlay-' + memberId);
	}

	/* ============================================================
	   Back-paging ("buffer") — older messages load only when the user
	   actually scrolls up to them.

	   On open the server sends the newest SCHAT_PAGE_SIZE messages. Each
	   time the list is scrolled near the top we ask for the batch older
	   than the oldest row we hold. st.hasOlder (from the server) stops
	   the requests once the top of the thread is reached, and st.backfilling
	   guards against firing a second request while one is in flight.
	   ============================================================ */
	function loadOlder(memberId, isAdmin) {
		var st = getState(memberId);
		if (st.backfilling || !st.hasOlder) return;

		var body = document.getElementById('schat-body-' + memberId);
		if (!body) return;

		// Oldest row currently on screen = our cursor.
		var firstRow = body.querySelector('.schat-row[data-message-id]');
		if (!firstRow) return;
		var beforeId = parseInt(firstRow.getAttribute('data-message-id'), 10) || 0;
		if (!beforeId) return;

		st.backfilling = true;
		showOlderSpinner(memberId, true);

		$.post(schatAjax.ajaxurl, {
			action: 'schat_fetch_messages',
			nonce: schatAjax.nonce,
			member_id: memberId,
			before_id: beforeId
		}, function (r) {
			showOlderSpinner(memberId, false);
			st.backfilling = false;
			if (!r || !r.success) return;

			var msgs = (r.data && r.data.messages) ? r.data.messages : [];
			st.hasOlder = !!(r.data && r.data.has_older);
			if (!msgs.length) { st.hasOlder = false; return; }

			/* Anchor the scroll position. Prepending grows the content
			   above the viewport, which would otherwise yank the view
			   downward; we restore the offset from the bottom instead,
			   so the message the user was looking at stays put. */
			var prevHeight = body.scrollHeight;
			var prevTop    = body.scrollTop;

			/* Render into a detached container, then move the nodes to the
			   top of the live list. lastRenderedDay is rewound so the older
			   batch gets its own day headers, and restored afterwards so
			   live appends keep working from where they were. */
			var savedDay = st.lastRenderedDay;
			var savedNew = st.newFromId;
			st.lastRenderedDay = null;
			st.newFromId = 0; // never redraw the unread divider mid-history

			var holder = document.createElement('div');
			try {
				msgs.forEach(function (m) {
					try { renderMessage(memberId, m, isAdmin, holder); }
					catch (e) { if (window.console) console.warn('schat backfill skipped', m && m.id, e); }
				});
			} finally {
				// Restore even if a row threw, or live rendering breaks.
				st.lastRenderedDay = savedDay;
				st.newFromId = savedNew;
			}

			/* Our last day header may repeat the list's current first one
			   (same calendar day spanning the batch boundary) — drop the
			   duplicate so the date isn't printed twice in a row. */
			var seps = holder.querySelectorAll('.schat-day-sep');
			var lastNewSep = seps.length ? seps[seps.length - 1] : null;
			var firstOldSep = body.querySelector('.schat-day-sep');
			if (lastNewSep && firstOldSep &&
			    lastNewSep.textContent === firstOldSep.textContent) {
				firstOldSep.parentNode.removeChild(firstOldSep);
			}

			// Move rendered nodes to the top, preserving their order.
			var frag = document.createDocumentFragment();
			while (holder.firstChild) frag.appendChild(holder.firstChild);
			body.insertBefore(frag, body.firstChild);

			// Restore the viewing position.
			body.scrollTop = prevTop + (body.scrollHeight - prevHeight);
		}).fail(function () {
			showOlderSpinner(memberId, false);
			st.backfilling = false;
		});
	}

	/* Small "loading older messages" row pinned above the list. */
	function showOlderSpinner(memberId, on) {
		var body = document.getElementById('schat-body-' + memberId);
		if (!body) return;
		var el = body.querySelector('.schat-older-loading');
		if (on) {
			if (!el) {
				el = document.createElement('div');
				el.className = 'schat-older-loading';
				el.textContent = 'Loading earlier messages…';
				body.insertBefore(el, body.firstChild);
			}
		} else if (el) {
			el.parentNode.removeChild(el);
		}
	}

	/* $target lets a caller render into a detached container (used by the
	   scroll-up backfill) instead of straight into the live list. */
	/* ============================================================
	   LINKS IN MESSAGES

	   Two parts: turning a URL into a clickable link, and asking the
	   server for that page's title/description/image to show underneath.

	   Order matters for safety. esc() runs FIRST, so by the time
	   linkify sees the text there is no live markup in it — the only
	   tags in the result are the <a> elements we put there ourselves.
	   The pattern also matches http/https/www only, so a "javascript:"
	   or "data:" string can never become an href.
	   ============================================================ */

	// Runs against ALREADY-ESCAPED text. `&` is `&amp;` at this point,
	// which is exactly what an href attribute wants anyway.
	var SCHAT_URL_RE = /\b(https?:\/\/[^\s<]+|www\.[a-z0-9][^\s<]*)/gi;

	/* Trailing punctuation usually belongs to the sentence, not the link:
	   "see example.com." or "(example.com)". Entities count as one unit —
	   a trailing &quot; is a quote mark, not part of the address. */
	function schatTrimUrl(u) {
		var prev;
		do {
			prev = u;
			u = u.replace(/(&quot;|&#0?39;|&apos;|&gt;|&lt;|&amp;)+$/i, '');
			/* A trailing ')' is part of the address when its '(' is in there
			   too — en.wikipedia.org/wiki/Foo_(bar) is one link, while
			   "(see example.com)" is a link wrapped in brackets. Check this
			   BEFORE stripping, or the bracket is gone either way. */
			if (/\)$/.test(u)) {
				var opens  = (u.match(/\(/g) || []).length;
				var closes = (u.match(/\)/g) || []).length;
				if (opens >= closes) break;
			}
			u = u.replace(/[.,;:!?)\]}'"»]+$/, '');
		} while (u !== prev);
		return u;
	}

	function schatLinkify(escaped) {
		return escaped.replace(SCHAT_URL_RE, function (match) {
			var url = schatTrimUrl(match);
			if (!url) return match;
			var tail = match.slice(url.length);
			var href = /^www\./i.test(url) ? 'https://' + url : url;
			return '<a href="' + href + '" class="schat-link" target="_blank" rel="noopener noreferrer nofollow">'
				+ url + '</a>' + tail;
		});
	}

	/* The first previewable URL in a message, as a plain (unescaped)
	   string — or '' if there isn't one. Works off the raw text so it
	   never has to undo entity encoding. */
	function schatFirstLink(raw) {
		if (!raw) return '';
		SCHAT_URL_RE.lastIndex = 0;
		var m = SCHAT_URL_RE.exec(esc(raw));
		SCHAT_URL_RE.lastIndex = 0;
		if (!m) return '';
		var url = schatTrimUrl(m[1]);
		if (!url) return '';
		// Undo the escaping for the value we send to the server.
		var d = document.createElement('textarea');
		d.innerHTML = url;
		url = d.value;
		return /^www\./i.test(url) ? 'https://' + url : url;
	}

	/* One in-flight request and one result per URL, shared across every
	   message that links to it — a URL pasted five times is fetched once. */
	var schatLinkCache = {};

	function schatLinkPreview(url, cb) {
		if (Object.prototype.hasOwnProperty.call(schatLinkCache, url)) {
			var hit = schatLinkCache[url];
			if (hit && hit.pending) { hit.waiting.push(cb); return; }
			cb(hit);
			return;
		}
		schatLinkCache[url] = { pending: true, waiting: [cb] };
		$.post(schatAjax.ajaxurl, {
			action: 'schat_link_preview', nonce: schatAjax.nonce, url: url
		}, function (r) {
			var data = (r && r.success && r.data) ? r.data : null;
			var entry = schatLinkCache[url];
			schatLinkCache[url] = data;
			if (entry && entry.waiting) entry.waiting.forEach(function (f) { f(data); });
		}).fail(function () {
			var entry = schatLinkCache[url];
			schatLinkCache[url] = null;
			if (entry && entry.waiting) entry.waiting.forEach(function (f) { f(null); });
		});
	}

	/**
	 * Hangs a preview card under a bubble once the metadata arrives.
	 * Renders nothing at all when the page has no usable metadata — an
	 * empty card is worse than no card.
	 */
	function schatAttachLinkCard(memberId, bubble, url) {
		if (!bubble || bubble.getAttribute('data-link-done') === '1') return;
		bubble.setAttribute('data-link-done', '1');

		schatLinkPreview(url, function (data) {
			if (!data || !data.url) return;
			if (!bubble.isConnected) return;

			var body = document.getElementById('schat-body-' + memberId);
			var atBottom = body ? schatAtBottom(body) : false;

			var a = document.createElement('a');
			a.className = 'schat-linkcard';
			a.href = data.url;
			a.target = '_blank';
			a.rel = 'noopener noreferrer nofollow';

			var html = '';
			if (data.image) {
				// onerror: a dead thumbnail collapses instead of leaving a gap.
				html += '<span class="schat-lc-thumb"><img src="' + esc(data.image) + '" alt="" loading="lazy"'
					+ ' onerror="this.parentNode.remove()"></span>';
			}
			html += '<span class="schat-lc-body">';
			if (data.title) html += '<span class="schat-lc-title">' + esc(data.title) + '</span>';
			if (data.desc)  html += '<span class="schat-lc-desc">' + esc(data.desc) + '</span>';
			html += '<span class="schat-lc-host">' + esc(data.site || data.host || '') + '</span>';
			html += '</span>';
			a.innerHTML = html;

			bubble.appendChild(a);
			if (atBottom) scrollToBottom(memberId, true);
		});
	}

	function renderMessage(memberId, m, isAdmin, target) {
		var overlay = findOverlay(memberId);
		if (!overlay) return;
		var liveBody = document.getElementById('schat-body-' + memberId);
		var body = target || liveBody;
		if (!body) return;
		var st = getState(memberId);

		// Idempotency guard. A poll can land while a send is still in flight
		// (or vice versa) and hand us a message that's already on screen —
		// that was the "sent twice" duplicate. Never render the same id twice.
		if (m && m.id && liveBody &&
		    liveBody.querySelector('.schat-row[data-message-id="' + m.id + '"]')) return;

		var mine = isAdmin ? (parseInt(m.sender_is_admin, 10) === 1) : (parseInt(m.sender_is_admin, 10) === 0);

		var day = dayLabel(m.sent_at);
		if (day !== st.lastRenderedDay) {
			var sep = document.createElement('div');
			sep.className = 'schat-day-sep';
			sep.textContent = day;
			body.appendChild(sep);
			st.lastRenderedDay = day;
		}

		// "New Messages" divider. st.newFromId is set once per open from the
		// server's first_unread_id; it marks where the unread run begins.
		// Cleared immediately after use so it can only ever render once.
		if (st.newFromId && parseInt(m.id, 10) === parseInt(st.newFromId, 10)) {
			var nsep = document.createElement('div');
			nsep.className = 'schat-new-sep';
			nsep.setAttribute('data-from-id', String(st.newFromId));
			nsep.innerHTML = '<span>New Messages</span>';
			body.appendChild(nsep);
			st.newFromId = 0;
		}

		var row = document.createElement('div');
		row.className = 'schat-row ' + (mine ? 'mine' : 'theirs');
		row.setAttribute('data-id', m.id);

		var bubble = document.createElement('div');
		bubble.className = 'schat-bubble';

		// Quoted reply preview, if this message replies to another.
		if (m.reply_snippet && m.reply_snippet.text) {
			var q = document.createElement('div');
			q.className = 'schat-reply-quote';
			// Tapping the quote jumps to the original message (see the
			// delegated '.schat-reply-quote' click handler below).
			if (m.reply_to) q.setAttribute('data-reply-to', m.reply_to);
			var who = document.createElement('span');
			who.className = 'schat-reply-who';
			who.textContent = (parseInt(m.reply_snippet.was_admin, 10) === 1)
				? (isAdmin ? 'You' : SCHAT_ADMIN_NAME)
				: (isAdmin ? SCHAT_MEMBER_NAME(memberId) : 'You');
			q.appendChild(who);
			var qt = document.createElement('span');
			qt.textContent = m.reply_snippet.text;
			q.appendChild(qt);
			bubble.appendChild(q);
		}

		if (m.image_url) {
			/* A photo should read as the bubble itself, not as a picture
			   sitting inside a coloured box. These two classes let the CSS
			   strip the padding and background when there is nothing else
			   in the bubble to hold. */
			bubble.classList.add('schat-has-photo');
			if (!m.message) bubble.classList.add('schat-photo-only');

			var img = document.createElement('img');
			img.className = 'schat-photo';
			img.src = m.image_url;
			// The URL lives on the element so a single delegated handler can
			// serve every photo, including ones added later by polling.
			img.setAttribute('data-full', m.image_url);
			bubble.appendChild(img);
			// Only follow along if the reader is already at the end, so an
			// incoming photo never yanks them away from older messages.
			schatPinPhoto(memberId, img, false);
		}
		if (m.message) {
			var txt = document.createElement('div');
			txt.className = 'schat-text';
			// esc() first, THEN linkify — see the note above schatLinkify.
			txt.innerHTML = schatLinkify(esc(m.message)).replace(/\n/g, '<br>');
			bubble.appendChild(txt);
			/* Emoji-only messages drop the bubble and render large. Only when
			   the message stands alone — a photo or a quoted reply still needs
			   its container. */
			if (!m.image_url && !m.reply_snippet && schatIsEmojiOnly(m.message)) {
				bubble.classList.add('schat-emoji-only');
			}
			/* Preview card for the first link. Skipped when the message
			   already carries a photo — one picture per bubble is enough. */
			if (!m.image_url) {
				var firstLink = schatFirstLink(m.message);
				if (firstLink) schatAttachLinkCard(memberId, bubble, firstLink);
			}
		}
		// Stash the raw text on the bubble so the edit flow can prefill it
		// without having to un-escape the rendered HTML.
		bubble.setAttribute('data-raw', m.message || '');

		var meta = document.createElement('div');
		meta.className = 'schat-meta';
		var metaText = document.createElement('span');
		metaText.textContent = timeLabel(m.sent_at);
		var fullDate = parseUTC(m.sent_at);
		if (!isNaN(fullDate.getTime())) {
			metaText.title = fullDate.toLocaleString(undefined, {
				weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
				hour: 'numeric', minute: '2-digit', timeZoneName: 'short'
			});
		}
		meta.appendChild(metaText);

		if (m.edited_at) {
			var ed = document.createElement('span');
			ed.className = 'schat-edited';
			ed.textContent = 'edited';
			meta.appendChild(ed);
		}

		if (isAdmin && mine) {
			var notifyBtn = document.createElement('button');
			notifyBtn.type = 'button';
			notifyBtn.className = 'schat-notify-btn schat-icon-btn schat-js-notify';
			notifyBtn.setAttribute('data-message-id', m.id);
			notifyBtn.title = 'Notify by email';
			notifyBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 6l-10 7L2 6"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg><span>Notify</span>';
			meta.appendChild(notifyBtn);
		}

		/* Shatania can delete anything; a member only what they sent
		   themselves. The server enforces the same rule — this just keeps
		   the button off screen where it would be refused anyway. */
		if (isAdmin || mine) {
			var delBtn = document.createElement('button');
			delBtn.type = 'button';
			delBtn.className = 'schat-del-btn schat-js-delete';
			delBtn.setAttribute('data-message-id', m.id);
			delBtn.title = 'Delete message';
			delBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>';
			meta.appendChild(delBtn);
		}

		row.appendChild(bubble);
		bubble.setAttribute('data-message-id', m.id);
		bubble.setAttribute('data-mine', mine ? '1' : '0');
		row.setAttribute('data-message-id', m.id);
		row.setAttribute('data-sent-at', m.sent_at || '');
		if (m.notified_at) row.setAttribute('data-notified', '1');
		row.appendChild(meta);

		if (!mine) {
			row.classList.add('schat-js-lovable');
		}
		if (parseInt(m.loved, 10) === 1) {
			var loved = document.createElement('div');
			loved.className = 'schat-loved';
			loved.textContent = '❤️';
			bubble.classList.add('has-loved');
			bubble.appendChild(loved);
		}

		row.setAttribute('data-mine', mine ? '1' : '0');
		body.appendChild(row);

		/* ---- Message grouping (iMessage-style clustering) ----
		   Runs AFTER the row is in the DOM so previousElementSibling is valid.
		   If the row immediately above is a message from the SAME sender and
		   nothing (day sep / new-messages line) sits between them, the previous
		   row becomes a mid-run bubble (bottom corner flattens) and this row
		   becomes the run's new tail. Purely presentational. */
		(function group() {
			var prev = row.previousElementSibling;
			if (prev && prev.classList && prev.classList.contains('schat-row')) {
				var prevMine = prev.getAttribute('data-mine') === '1';
				if (prevMine === mine) {
					prev.classList.add('schat-grouped-prev');
					prev.classList.remove('schat-run-tail');
					row.classList.add('schat-grouped-cont');
				}
			}
			row.classList.add('schat-run-tail');
		})();
	}

	function showEmptyState(memberId, isAdmin) {
		var body = document.getElementById('schat-body-' + memberId);
		if (!body) return;
		body.innerHTML = '';
		var empty = document.createElement('div');
		empty.className = 'schat-empty';
		empty.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>' +
			'<p>' + (isAdmin ? 'No messages yet. Say hello to start the conversation.' : 'Send a message to start chatting with Shatania.') + '</p>';
		body.appendChild(empty);
	}

	// True when the list is already parked at (or near) the bottom.
	function schatAtBottom(body) {
		if (!body) return true;
		return (body.scrollHeight - body.scrollTop - body.clientHeight) < 80;
	}

	// force = true  -> always jump (opening the chat, sending a message)
	// force = false -> only follow along if the user hasn't scrolled up
	/* ------------------------------------------------------------------
	   Keeping a just-sent photo fully in view.

	   scrollToBottom() runs the moment the bubble is added, but at that
	   instant the <img> has no dimensions yet — the browser has not decoded
	   it. The thread then grows by the photo's height a few frames later,
	   which pushes the bubble down past the fold, half of it hidden behind
	   the keyboard.

	   Two fixes work together:
	     1. Reserve the photo's height up front via aspect-ratio, so the
	        thread never grows after the scroll.
	     2. Re-pin to the bottom once the image has actually decoded, and
	        again after the keyboard finishes animating.
	   ------------------------------------------------------------------ */
	function schatPinPhoto(memberId, img, force) {
		if (!img) return;

		function reserve() {
			// Once we know the real proportions, lock them in so the box
			// occupies its final height from the first paint.
			if (img.naturalWidth && img.naturalHeight && !img.style.aspectRatio) {
				img.style.aspectRatio = img.naturalWidth + ' / ' + img.naturalHeight;
			}
		}

		function pin() {
			reserve();
			scrollToBottom(memberId, force);
			/* The keyboard animates over roughly 250ms on Android and the
			   visual viewport only settles at the end of it, so one more
			   pass after it lands is what actually keeps the photo clear of
			   the keys. */
			setTimeout(function () { scrollToBottom(memberId, force); }, 90);
			setTimeout(function () { scrollToBottom(memberId, force); }, 320);
		}

		if (img.complete && img.naturalWidth) { pin(); return; }

		img.addEventListener('load', pin, { once: true });
		// A broken image still changes the layout, so re-pin either way.
		img.addEventListener('error', function () { scrollToBottom(memberId, force); }, { once: true });

		if (img.decode) {
			// decode() resolves once the bitmap is ready to paint, which is
			// slightly later than 'load' and is the frame that matters.
			img.decode().then(pin).catch(function () {});
		}
	}

	function scrollToBottom(memberId, force) {
		var body = document.getElementById('schat-body-' + memberId);
		if (!body) return;
		if (!force && !schatAtBottom(body)) return;
		/* Mark this as OUR scroll, not theirs. The resulting scroll event
		   would otherwise be reported as "is scrolling the chat" every time
		   a message lands and we jump to the bottom for them. */
		getState(memberId).autoScrollUntil = Date.now() + 400;
		body.scrollTop = body.scrollHeight;
	}

	// Park the "New Messages" line near the top of the viewport so the
	// unread run reads as the beginning of the conversation. If the unread
	// block is short enough that the thread is already at its end, fall
	// back to the bottom so there's no dead space below.
	function scrollToDivider(memberId, sepEl) {
		var body = document.getElementById('schat-body-' + memberId);
		if (!body || !sepEl) return;
		var target = sepEl.offsetTop - 12;
		var max = body.scrollHeight - body.clientHeight;
		body.scrollTop = Math.max(0, Math.min(target, max));
	}

	function loadInitial(memberId, isAdmin) {
		var st = getState(memberId);
		// A full reload rebuilds the list from scratch, so the cursor must be
		// rewound too. Leaving a stale lastId here made later polls fetch
		// nothing — the "only one message after refresh" symptom.
		st.lastId = 0;
		st.lastRenderedDay = null;
		st.loading = true;
		// A full reload replaces the list, so paging starts fresh too.
		st.hasOlder = false;
		st.backfilling = false;
		$.post(schatAjax.ajaxurl, { action: 'schat_fetch_messages', nonce: schatAjax.nonce, member_id: memberId, after_id: 0 }, function (r) {
			st.loading = false;
			if (r && r.success) {
				var body = document.getElementById('schat-body-' + memberId);
				if (!body) return;
				body.innerHTML = '';
				st.lastRenderedDay = null;
				// Where the unread run starts, for the "New Messages" line.
				// Server computes this before mark_read, so it's only set
				// when there were genuinely unread messages waiting.
				st.newFromId = parseInt(r.data.first_unread_id, 10) || 0;
				// Does the thread continue past the top of this batch?
				st.hasOlder = !!r.data.has_older;
				var msgs = (r.data && r.data.messages) ? r.data.messages : [];
				// If the unread run starts at the very first message, the
				// divider would sit above the whole thread and say nothing
				// useful — drop it.
				if (st.newFromId && msgs.length &&
				    parseInt(msgs[0].id, 10) === parseInt(st.newFromId, 10)) {
					st.newFromId = 0;
				}
				// Already crossed this run in a previous open — don't show
				// it again, even if mark_read hasn't landed server-side yet.
				if (st.newFromId && newSepDismissed(memberId, st.newFromId)) {
					st.newFromId = 0;
				}
				var hadNew = st.newFromId > 0;
				var newRunFrom = st.newFromId; // renderMessage zeroes st.newFromId
				if (msgs.length === 0) {
					showEmptyState(memberId, isAdmin);
				} else {
					// Render defensively: one bad row must not abort the rest
					// of the thread and leave a half-empty list.
					msgs.forEach(function (m) {
						try { renderMessage(memberId, m, isAdmin); }
						catch (e) { if (window.console) console.warn('schat render skipped', m && m.id, e); }
					});
					st.lastId = parseInt(msgs[msgs.length - 1].id, 10) || 0;
					// If a divider was drawn, park it near the top so the
					// unread run reads as the start of the thread. Otherwise
					// just go to the newest message.
					var sepEl = hadNew ? body.querySelector('.schat-new-sep') : null;
					if (sepEl) {
						scrollToDivider(memberId, sepEl);
						watchNewSep(memberId, sepEl, newRunFrom);
					} else {
						scrollToBottom(memberId, true);
					}
				}
				markRead(memberId, isAdmin);
				showTyping(memberId, !!r.data.typing, isAdmin);
				applyPresence(memberId, r.data.activity, r.data.activity_ago, isAdmin);
				// silent: opening a thread must not replay an old party
				consumeCelebration(memberId, r.data.celebrate, isAdmin, true);
				applySeen(memberId, r.data.last_seen_id, isAdmin);
				syncReactions(memberId, r.data.loved_ids);
				setBadge(r.data.unread_total);
			}
		}).fail(function () { st.loading = false; });
	}

	/* ============================================================
	   Seen receipt — renders "Seen" under the newest message I sent
	   that the other side has read.
	   ============================================================ */
	function applySeen(memberId, lastSeenId, isAdmin) {
		var body = document.getElementById('schat-body-' + memberId);
		if (!body) return;
		var st = getState(memberId);
		lastSeenId = parseInt(lastSeenId, 10) || 0;
		if (lastSeenId === st.lastSeenId) return;
		st.lastSeenId = lastSeenId;

		var old = body.querySelector('.schat-seen');
		if (old) old.remove();
		// Clear the spacing class from whichever row previously held it.
		var prev = body.querySelector('.schat-row.schat-has-seen');
		if (prev) { prev.classList.remove('schat-has-seen'); prev.classList.remove('schat-seen-loved'); }
		if (!lastSeenId) return;

		var row = body.querySelector('.schat-row.mine[data-message-id="' + lastSeenId + '"]')
			|| body.querySelector('.schat-row.mine [data-message-id="' + lastSeenId + '"]');
		var target = row ? (row.closest ? row.closest('.schat-row') : row) : null;
		if (!target) {
			var mineRows = body.querySelectorAll('.schat-row.mine');
			target = mineRows.length ? mineRows[mineRows.length - 1] : null;
		}
		if (!target) return;

		var seen = document.createElement('div');
		seen.className = 'schat-seen';
		seen.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg><span>Seen</span>';
		target.classList.add('schat-has-seen');
		// A hearted bubble hangs its badge below itself; add clearance.
		if (target.querySelector('.has-loved')) target.classList.add('schat-seen-loved');
		target.insertAdjacentElement('afterend', seen);

		// The receipt adds height below the last bubble. If the user was
		// already parked at the bottom, follow it down so "Seen" isn't left
		// hidden behind the composer.
		if (schatAtBottom(body)) {
			body.scrollTop = body.scrollHeight;
		}
	}

	/* ============================================================
	   Typing indicator
	   ============================================================ */
	function showTyping(memberId, on, isAdmin) {
		var box = document.getElementById('schat-typing-' + memberId);
		var txt = document.getElementById('schat-typing-text-' + memberId);
		if (!box) return;
		if (on) {
			if (txt) {
				txt.textContent = (isAdmin ? SCHAT_MEMBER_NAME(memberId) : SCHAT_ADMIN_NAME) + ' is typing…';
			}
			if (!box.classList.contains('show')) {
				box.classList.add('show');
				scrollToBottom(memberId);
			}
		} else {
			box.classList.remove('show');
		}
	}

	// Throttled: at most one ping every 2.5s while actively typing.
	function pingTyping(memberId) {
		var st = getState(memberId);
		var now = Date.now();
		if (now - st.typingSentAt > 2500) {
			st.typingSentAt = now;
			// The typing endpoint sets the activity row to 'typing' server
			// side, so mirror that locally rather than posting twice.
			st.actState = 'typing';
			st.actSentAt = now;
			$.post(schatAjax.ajaxurl, { action: 'schat_typing', nonce: schatAjax.nonce, member_id: memberId });
		}
		if (st.typingStopTimer) clearTimeout(st.typingStopTimer);
		st.typingStopTimer = setTimeout(function () { stopTyping(memberId); }, 3500);
	}
	function stopTyping(memberId) {
		var st = getState(memberId);
		if (st.typingStopTimer) { clearTimeout(st.typingStopTimer); st.typingStopTimer = null; }
		st.typingSentAt = 0;
		// The stop ping drops the server back to 'viewing' — mirror it.
		st.actState = 'viewing';
		st.actSentAt = Date.now();
		$.post(schatAjax.ajaxurl, { action: 'schat_typing', nonce: schatAjax.nonce, member_id: memberId, stop: 1 });
	}

	/* ============================================================
	   PUSH PRESENCE

	   Tells the server whether this person currently has the chat on
	   screen, so it can skip a push they don't need.

	   This used to be reported only by the Android app, through the
	   ShataniaNative.onChatOpened() bridge below. That works when the app
	   opens the chat through its own screen lifecycle, but NOT when a
	   notification tap deep-links straight into a thread — the bridge
	   never fires, the server never learns they arrived, and the pushes
	   keep coming while they are sitting in the conversation.

	   The page always knows whether the panel is open, so the page is the
	   honest source. The native call is kept as well; both agree, and
	   either alone is enough.
	   ============================================================ */
	var schatPresence = { active: null, sentAt: 0 };
	var SCHAT_PRESENCE_BEAT = 30000; // server trusts a ping for 90s

	function sendPresence(active, force) {
		active = active ? 1 : 0;
		var now = Date.now();
		// Re-send an unchanged value only as a heartbeat.
		if (!force && active === schatPresence.active && (now - schatPresence.sentAt) < SCHAT_PRESENCE_BEAT) return;
		schatPresence.active = active;
		schatPresence.sentAt = now;
		$.post(schatAjax.ajaxurl, {
			action: 'schat_set_chat_presence', nonce: schatAjax.nonce, active: String(active)
		});
	}

	/* Is any thread actually on screen right now? Presence is per person,
	   not per thread, so one open panel is enough — and a hidden tab or a
	   backgrounded app counts as not being here at all. */
	function schatAnyPanelOpen() {
		if (document.hidden) return false;
		return !!document.querySelector('.schat-overlay.open');
	}

	/* Last-gasp "I've left" when the page goes away. Best effort: the
	   browser may kill it, which is why the server also ages presence out. */
	function schatBeaconPresence(active) {
		try {
			if (!navigator.sendBeacon) return false;
			var fd = new FormData();
			fd.append('action', 'schat_set_chat_presence');
			fd.append('nonce', schatAjax.nonce);
			fd.append('active', active ? '1' : '0');
			return navigator.sendBeacon(schatAjax.ajaxurl, fd);
		} catch (e) { return false; }
	}

	/* ============================================================
	   LIVE ACTIVITY

	   Four states go up to the server — typing, scrolling, viewing,
	   away — and the other side's state comes back down on every poll,
	   rendered into the header line under the name.

	   Each state has its own minimum gap between repeat pings, chosen to
	   sit comfortably inside that state's server-side TTL. "viewing" is
	   the lazy one: a heartbeat every 12s against a 35s window, so an
	   idle thread costs almost nothing. "typing" and "scrolling" re-ping
	   every 3s against ~6s windows, so they decay quickly and honestly
	   the moment someone actually stops.
	   ============================================================ */
	var SCHAT_ACT_MIN = { typing: 3000, scrolling: 3000, viewing: 12000, away: 0 };

	function sendActivity(memberId, stateName, force) {
		var st = getState(memberId);
		var now = Date.now();
		var min = (SCHAT_ACT_MIN[stateName] != null) ? SCHAT_ACT_MIN[stateName] : 8000;
		// Repeating the same state is a heartbeat — rate-limit it. A change
		// of state always goes out immediately; that's the whole point.
		if (!force && stateName === st.actState && (now - st.actSentAt) < min) return;
		st.actState = stateName;
		st.actSentAt = now;
		$.post(schatAjax.ajaxurl, {
			action: 'schat_activity', nonce: schatAjax.nonce,
			member_id: memberId, state: stateName
		});
	}

	/* Called from the thread's scroll handler. Reports "scrolling", then
	   settles back to "viewing" a beat after the scrolling stops — unless
	   they've started typing in the meantime, which outranks it. */
	function markScrolling(memberId) {
		var st = getState(memberId);
		if (st.typingStopTimer) return; // mid-sentence — typing wins
		if (Date.now() < (st.autoScrollUntil || 0)) return; // our own jump, not a read
		sendActivity(memberId, 'scrolling');
		if (st.actSettleTimer) clearTimeout(st.actSettleTimer);
		st.actSettleTimer = setTimeout(function () {
			st.actSettleTimer = null;
			var s = getState(memberId);
			if (s.typingStopTimer) return;
			sendActivity(memberId, 'viewing', true);
		}, 2600);
	}

	function presenceLabel(stateName, ago, isAdmin, memberId) {
		var who = isAdmin ? SCHAT_MEMBER_NAME(memberId) : SCHAT_ADMIN_NAME;
		if (stateName === 'typing')    return who + ' is typing…';
		if (stateName === 'scrolling') return who + ' is scrolling the chat';
		if (stateName === 'viewing')   return who + ' is in the chat';
		if (ago) return who + ' is not in the chat · active ' + ago;
		return who + ' is not in the chat';
	}

	/* Paints the header line. Cross-fades the text so a state change reads
	   as a change rather than a jump, and swaps the dot's colour class. */
	function applyPresence(memberId, stateName, ago, isAdmin) {
		var el = document.getElementById('schat-pres-' + memberId);
		if (!el) return;
		var st = getState(memberId);

		stateName = stateName || 'away';
		var label = presenceLabel(stateName, ago, isAdmin, memberId);
		if (label === st.lastPresLabel) return;
		var first = !st.lastPresLabel;
		st.lastPresLabel = label;

		el.className = 'schat-h-sub schat-pres schat-pres-' + stateName;

		var txt = el.querySelector('.schat-pres-txt');
		if (!txt) return;
		if (first) { txt.textContent = label; return; }

		el.classList.add('is-swapping');
		setTimeout(function () {
			txt.textContent = label;
			el.classList.remove('is-swapping');
		}, 200);
	}

	/* ============================================================
	   CELEBRATION

	   A celebration is a moment, not a message — nothing is written to
	   the thread, so there's no history to scroll past later. The server
	   keeps only the latest one per thread, for two minutes, tagged with
	   a sequence number. Both sides poll it; whoever didn't send it
	   plays it once and records the sequence so it never repeats.
	   ============================================================ */
	var SCHAT_PARTY_COLORS = ['#D9A9AC', '#E8C4C6', '#F7A1C4', '#FFD36E', '#8FD3C7', '#FFFFFF', '#C08A8E'];
	var SCHAT_HEART_SVG = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7-4.5-9.5-9A5.5 5.5 0 0112 6a5.5 5.5 0 019.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg>';

	function rand(min, max) { return min + Math.random() * (max - min); }
	function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

	function playCelebration(memberId, fromOther) {
		var overlay = findOverlay(memberId);
		if (!overlay) return;
		var panel = overlay.querySelector('.schat-panel');
		if (!panel) return;

		// Never stack parties — a second burst replaces the first.
		var old = panel.querySelector('.schat-party');
		if (old) old.remove();

		var rect = panel.getBoundingClientRect();
		var h = Math.max(320, rect.height || 560);
		var w = Math.max(240, rect.width || 380);

		var layer = document.createElement('div');
		layer.className = 'schat-party';
		layer.setAttribute('aria-hidden', 'true');

		var html = '<div class="schat-party-flash"></div>';

		/* Confetti falls the full height of the panel plus a margin, so
		   pieces leave the frame cleanly instead of piling at the bottom. */
		var fall = Math.round(h + 80);
		var reduced = false;
		try {
			reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		} catch (e) {}

		var confettiCount = reduced ? 0 : (w < 420 ? 54 : 76);
		var heartCount    = reduced ? 0 : (w < 420 ? 16 : 22);

		var i;
		for (i = 0; i < confettiCount; i++) {
			var cw = rand(5, 10);
			var ch = rand(8, 16);
			// A third of the pieces are round, which stops the whole burst
			// reading as a shower of identical rectangles.
			var radius = Math.random() < 0.33 ? '50%' : '2px';
			html += '<span class="schat-confetti" style="'
				+ '--x:' + rand(-4, 100).toFixed(2) + '%;'
				+ '--w:' + cw.toFixed(1) + 'px;'
				+ '--h:' + ch.toFixed(1) + 'px;'
				+ '--c:' + pick(SCHAT_PARTY_COLORS) + ';'
				+ '--r:' + radius + ';'
				+ '--dx:' + rand(-70, 70).toFixed(0) + 'px;'
				+ '--fall:' + fall + 'px;'
				+ '--rot:' + rand(-720, 720).toFixed(0) + 'deg;'
				+ '--d:' + rand(1900, 3000).toFixed(0) + 'ms;'
				+ '--delay:' + rand(0, 700).toFixed(0) + 'ms;'
				+ '"></span>';
		}

		var rise = Math.round(h * 0.86 + 60);
		for (i = 0; i < heartCount; i++) {
			var size = rand(16, 30);
			html += '<span class="schat-heartp" style="'
				+ '--x:' + rand(4, 92).toFixed(2) + '%;'
				+ '--s:' + size.toFixed(1) + 'px;'
				+ '--c:' + pick(['#D9A9AC', '#F7A1C4', '#E8C4C6', '#FF8FA3']) + ';'
				+ '--dx:' + rand(-50, 50).toFixed(0) + 'px;'
				+ '--rise:-' + rise + 'px;'
				+ '--rot:' + rand(-30, 30).toFixed(0) + 'deg;'
				+ '--d:' + rand(2100, 3200).toFixed(0) + 'ms;'
				+ '--delay:' + rand(0, 900).toFixed(0) + 'ms;'
				+ '">' + SCHAT_HEART_SVG + '</span>';
		}

		var who = fromOther
			? (schatAjax.is_admin == 1 ? SCHAT_MEMBER_NAME(memberId) : SCHAT_ADMIN_NAME) + ' is celebrating!'
			: 'You sent a celebration!';
		html += '<div class="schat-party-badge">' + SCHAT_HEART_SVG + '<span>' + esc(who) + '</span></div>';

		layer.innerHTML = html;
		panel.appendChild(layer);

		// Two short taps for an incoming party, one for your own.
		schatHaptic(fromOther ? 18 : 12);
		if (fromOther) setTimeout(function () { schatHaptic(12); }, 160);

		setTimeout(function () {
			if (layer && layer.parentNode) layer.parentNode.removeChild(layer);
		}, 4200);
	}

	/* Reads the `celebrate` blob off a poll response. Returns nothing —
	   its whole job is the side effect of playing (or quietly absorbing)
	   the party, and bumping the watermark either way. */
	function consumeCelebration(memberId, party, isAdmin, silent) {
		var st = getState(memberId);
		if (!party || !party.seq) return;
		var seq = parseFloat(party.seq) || 0;
		if (seq <= st.lastPartySeq) return;
		st.lastPartySeq = seq;

		// `silent` is the initial load: absorb whatever is pending without
		// playing it, so opening a thread doesn't replay someone's party
		// from a minute ago.
		if (silent) return;

		var mine = (parseInt(party.by_admin, 10) === 1) === (isAdmin === true || isAdmin == 1);
		if (mine) return; // our own echo — we already played it locally
		playCelebration(memberId, true);
	}

	/* ============================================================
	   Reply composer bar
	   ============================================================ */
	function setReplyTarget(memberId, messageId, text, whoLabel) {
		var st = getState(memberId);
		st.replyTo = parseInt(messageId, 10) || 0;
		var bar = document.getElementById('schat-reply-bar-' + memberId);
		var lbl = document.getElementById('schat-reply-label-' + memberId);
		var txt = document.getElementById('schat-reply-text-' + memberId);
		if (!bar) return;
		if (st.replyTo) {
			if (lbl) lbl.textContent = 'Replying to ' + (whoLabel || '');
			if (txt) txt.textContent = text || '';
			bar.classList.add('show');
			var ta = document.querySelector('#schat-overlay-' + memberId + ' .schat-input-row textarea');
			if (ta) ta.focus();
		} else {
			bar.classList.remove('show');
		}
	}
	function clearReply(memberId) { setReplyTarget(memberId, 0, '', ''); }

	/* ============================================================
	   Edit mode — reuses the composer to rewrite an already-sent
	   message. While active, the Send button commits the edit
	   (schat_edit_message) instead of posting a new message.
	   ============================================================ */
	function startEdit(memberId, messageId, bubble) {
		var overlay = findOverlay(memberId);
		if (!overlay) return;
		var st = getState(memberId);
		// Editing and replying are mutually exclusive — clear any pending reply.
		clearReply(memberId);
		st.editing = parseInt(messageId, 10) || 0;

		var raw = bubble ? (bubble.getAttribute('data-raw') || '') : '';
		var ta = overlay.querySelector('.schat-input-row textarea');
		if (ta) {
			ta.value = raw;
			ta.focus();
			// Grow to fit (capped at 5 lines) and put the caret at the end.
			schatAutoGrow(ta);
			try { ta.setSelectionRange(raw.length, raw.length); } catch (e) {}
		}
		var bar = document.getElementById('schat-edit-bar-' + memberId);
		if (bar) bar.classList.add('show');
		updateSendState(memberId);
	}
	function clearEdit(memberId) {
		var st = getState(memberId);
		st.editing = 0;
		var bar = document.getElementById('schat-edit-bar-' + memberId);
		if (bar) bar.classList.remove('show');
		var overlay = findOverlay(memberId);
		var ta = overlay && overlay.querySelector('.schat-input-row textarea');
		if (ta) { ta.value = ''; schatSetHeight(ta, 'auto'); ta.style.overflowY = 'hidden'; ta.classList.remove('schat-scrollable'); }
		updateSendState(memberId);
	}

	// Commit an in-progress edit to the server, then repaint the bubble.
	function schatCommitEdit(memberId, isAdmin) {
		var overlay = findOverlay(memberId);
		if (!overlay) return;
		var st = getState(memberId);
		var mid = st.editing;
		if (!mid) return;
		var ta = overlay.querySelector('.schat-input-row textarea');
		var text = ta ? (ta.value || '').trim() : '';
		if (!text) return; // empty edit ignored (delete is a separate action)

		var body = document.getElementById('schat-body-' + memberId);
		var row = body && body.querySelector('.schat-row[data-message-id="' + mid + '"]');
		var textEl = row && row.querySelector('.schat-bubble .schat-text');
		var bubble = row && row.querySelector('.schat-bubble');
		var prevHtml = textEl ? textEl.innerHTML : null;

		// Optimistic repaint.
		if (textEl) textEl.innerHTML = esc(text).replace(/\n/g, '<br>');
		if (bubble) bubble.setAttribute('data-raw', text);
		if (row && !row.querySelector('.schat-edited')) {
			var meta = row.querySelector('.schat-meta');
			if (meta) {
				var ed = document.createElement('span');
				ed.className = 'schat-edited';
				ed.textContent = 'edited';
				meta.insertBefore(ed, meta.children[1] || null);
			}
		}
		clearEdit(memberId);
		schatHaptic(8);

		$.post(schatAjax.ajaxurl, {
			action: 'schat_edit_message',
			nonce: schatAjax.nonce,
			message_id: mid,
			message: text
		}, function (r) {
			if (!r || !r.success) {
				if (textEl && prevHtml !== null) textEl.innerHTML = prevHtml;
				alert((r && r.data) ? r.data : 'Could not save edit.');
			}
		}).fail(function () {
			if (textEl && prevHtml !== null) textEl.innerHTML = prevHtml;
			alert('Could not save edit.');
		});
	}

	/* ============================================================
	   Context menu (right-click on desktop, long-press on touch)
	   ============================================================ */
	var schatCtxEl = null;
	function ctxMenu() {
		if (!schatCtxEl) {
			schatCtxEl = document.createElement('div');
			schatCtxEl.className = 'schat-ctx schat-root';
			document.body.appendChild(schatCtxEl);
		}
		return schatCtxEl;
	}
	function hideCtx() { if (schatCtxEl) schatCtxEl.classList.remove('show'); }

	function openCtxFor(bubble, x, y, memberId, isAdmin) {
		var menu = ctxMenu();
		var messageId = bubble.getAttribute('data-message-id');
		var mine = bubble.getAttribute('data-mine') === '1';
		var raw = (bubble.innerText || '').trim().split('\n')[0].slice(0, 60);
		var who = mine ? 'yourself' : (isAdmin ? SCHAT_MEMBER_NAME(memberId) : SCHAT_ADMIN_NAME);

		menu.innerHTML = '';

		// Timestamp header. The inline time under every bubble is hidden by
		// default now, so this is where the exact send time lives.
		var row = bubble.closest('.schat-row');
		var stamp = row && row.getAttribute('data-sent-at');
		if (stamp) {
			var head = document.createElement('div');
			head.className = 'schat-ctx-time';
			var d = parseUTC(stamp);
			head.textContent = isNaN(d.getTime()) ? '' :
				(dayLabel(stamp) + ' at ' + timeLabel(stamp));
			if (head.textContent) menu.appendChild(head);
		}

		var reply = document.createElement('button');
		reply.type = 'button';
		reply.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg><span>Reply</span>';
		reply.addEventListener('click', function () {
			setReplyTarget(memberId, messageId, raw, who);
			hideCtx();
		});
		menu.appendChild(reply);

		var copy = document.createElement('button');
		copy.type = 'button';
		copy.innerHTML = '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><span>Copy</span>';
		copy.addEventListener('click', function () {
			try { navigator.clipboard.writeText(raw); } catch (e) {}
			hideCtx();
		});
		menu.appendChild(copy);

		// Edit — only on your OWN messages, and only if they carry text.
		var bubbleHasText = !!(bubble.getAttribute('data-raw') || '').length;
		if (mine && bubbleHasText) {
			var edit = document.createElement('button');
			edit.type = 'button';
			edit.innerHTML = '<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><span>Edit</span>';
			edit.addEventListener('click', function () {
				hideCtx();
				startEdit(memberId, messageId, bubble);
			});
			menu.appendChild(edit);
		}

		// Admin only, and only on her OWN messages — this emails the member
		// to tell them a new message is waiting.
		if (isAdmin && mine) {
			var notify = document.createElement('button');
			notify.type = 'button';
			var alreadySent = row && row.getAttribute('data-notified') === '1';
			notify.innerHTML = '<svg viewBox="0 0 24 24"><path d="M22 6l-10 7L2 6"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg>' +
				'<span>' + (alreadySent ? 'Notified' : 'Notify by email') + '</span>';
			if (alreadySent) notify.classList.add('sent');
			notify.addEventListener('click', function () {
				if (alreadySent) { hideCtx(); return; }
				var span = notify.querySelector('span');
				if (span) span.textContent = 'Sending…';
				notify.disabled = true;
				$.post(schatAjax.ajaxurl, {
					action: 'schat_notify_member',
					nonce: schatAjax.nonce,
					message_id: messageId
				}, function (r) {
					if (r && r.success) {
						if (row) row.setAttribute('data-notified', '1');
						var inline = row && row.querySelector('.schat-js-notify');
						if (inline) {
							inline.classList.add('sent');
							inline.disabled = true;
							var isp = inline.querySelector('span');
							if (isp) isp.textContent = 'Notified';
						}
					} else {
						alert((r && r.data) ? r.data : 'Could not send notification.');
					}
					hideCtx();
				}).fail(function () {
					alert('Could not send notification.');
					hideCtx();
				});
			});
			menu.appendChild(notify);
		}

		if (isAdmin || mine) {
			var del = document.createElement('button');
			del.type = 'button';
			del.className = 'danger';
			del.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg><span>Delete</span>';
			del.addEventListener('click', function () {
				hideCtx();
				var rowEl = bubble.closest('.schat-row');
				deleteMessage(messageId, rowEl);
			});
			menu.appendChild(del);
		}

		menu.classList.add('show');
		var w = menu.offsetWidth || 180, h = menu.offsetHeight || 120;
		menu.style.left = Math.max(8, Math.min(x, window.innerWidth - w - 8)) + 'px';
		menu.style.top  = Math.max(8, Math.min(y, window.innerHeight - h - 8)) + 'px';
	}

	$(document).on('contextmenu', '.schat-bubble', function (e) {
		var overlay = this.closest('.schat-overlay');
		if (!overlay) return;
		e.preventDefault();
		openCtxFor(this, e.clientX, e.clientY, overlay.getAttribute('data-member-id'), schatAjax.is_admin == 1);
	});

	// Long-press for touch devices — AND WhatsApp-style swipe-to-reply.
	// A single touch stream on a bubble can become either gesture:
	//   • hold still ~480ms            -> context menu (Reply / Copy / …)
	//   • drag horizontally past ~60px -> reply to this message
	// We track the start point, follow horizontal movement by translating
	// the row, and decide on release. Vertical movement (scrolling the
	// thread) cancels both and leaves the list to scroll normally.
	var lpTimer = null, lpMoved = false;

	/* Where the finger went down, and when.
	   lpMoved flips on the very first touchmove - a one-pixel tremor is
	   enough - so it cannot tell a tap from a drag. Almost every real tap
	   moves a little, which is why tapping a photo did nothing. Distance
	   and duration are measured instead. */
	var tapX = 0, tapY = 0, tapAt = 0;
	var TAP_SLOP  = 12;   // px of travel still counted as a tap, capped
	                      // below SW_LOCK — past that distance the swipe
	                      // tracker has already claimed the gesture
	var TAP_TIME  = 600;  // ms: longer than this is a press, not a tap
	var GHOST_GAP = 700;  // ms after a touch in which a click is a ghost
	var schatLastTouchAt = 0;

	function schatWasTap(e) {
		var oe = e.originalEvent || e;
		var t  = (oe.changedTouches && oe.changedTouches[0]) || null;
		if (!t || !tapAt) return false;
		if (Date.now() - tapAt > TAP_TIME) return false;          // a press
		if (schatCtxEl && schatCtxEl.classList.contains('show')) return false;
		var slop = Math.min(TAP_SLOP, SW_LOCK - 1);
		return Math.abs(t.clientX - tapX) <= slop &&
		       Math.abs(t.clientY - tapY) <= slop;
	}
	var swStartX = 0, swStartY = 0, swRow = null, swActive = false, swFired = false;
	var SW_THRESHOLD = 60;   // px of drag needed to trigger a reply
	var SW_MAX       = 84;   // cap how far the bubble follows the finger
	var SW_LOCK      = 10;   // px before we commit to horizontal vs vertical

	function swReset() {
		if (swRow) {
			swRow.style.transition = 'transform .18s cubic-bezier(.22,.9,.32,1)';
			swRow.style.transform = '';
			swRow.classList.remove('schat-swiping', 'schat-swipe-ready');
			var r = swRow;
			setTimeout(function () { if (r) { r.style.transition = ''; r.style.transform = ''; } }, 200);
		}
		swRow = null; swActive = false; swFired = false;
	}

	$(document).on('touchstart', '.schat-bubble', function (e) {
		var self = this, t = e.touches[0];
		lpMoved = false;
		var rowEl = self.closest('.schat-row');
		if (rowEl) rowEl.classList.add('schat-pressing');

		// Prime the swipe tracker (direction not yet decided).
		swStartX = t.clientX; swStartY = t.clientY;
		tapX = t.clientX; tapY = t.clientY;
		tapAt = Date.now(); schatLastTouchAt = tapAt;
		swRow = rowEl; swActive = false; swFired = false;
		if (swRow) swRow.style.transition = '';

		lpTimer = setTimeout(function () {
			if (lpMoved || swActive) return;
			var overlay = self.closest('.schat-overlay');
			if (!overlay) return;
			if (navigator.vibrate) navigator.vibrate(8);
			openCtxFor(self, t.clientX, t.clientY, overlay.getAttribute('data-member-id'), schatAjax.is_admin == 1);
		}, 480);
	});

	$(document).on('touchmove', '.schat-bubble', function (e) {
		lpMoved = true;
		if (!swRow) return;
		var t = e.touches[0];
		var dx = t.clientX - swStartX;
		var dy = t.clientY - swStartY;

		// Decide direction once past the small lock distance.
		if (!swActive) {
			if (Math.abs(dx) < SW_LOCK && Math.abs(dy) < SW_LOCK) return;
			if (Math.abs(dy) > Math.abs(dx)) { swRow = null; return; } // vertical → let it scroll
			swActive = true;
			swRow.classList.add('schat-swiping');
		}

		// Only a rightward pull reveals the reply affordance. Rubber-band
		// past the cap so it feels springy, never runs off screen.
		var pull = dx;
		if (pull < 0) pull = 0;
		var shown = pull > SW_MAX ? SW_MAX + (pull - SW_MAX) * 0.18 : pull;
		swRow.style.transform = 'translateX(' + shown + 'px)';

		var ready = pull >= SW_THRESHOLD;
		if (ready && !swFired) {
			swFired = true;
			swRow.classList.add('schat-swipe-ready');
			if (navigator.vibrate) navigator.vibrate(6);
		} else if (!ready && swFired) {
			swFired = false;
			swRow.classList.remove('schat-swipe-ready');
		}
		// We're clearly dragging horizontally — keep the page from scrolling.
		if (e.cancelable) e.preventDefault();
	});

	$(document).on('touchend touchcancel', '.schat-bubble', function (e) {
		if (lpTimer) clearTimeout(lpTimer);
		var rowEl = this.closest('.schat-row');
		if (rowEl) rowEl.classList.remove('schat-pressing');

		/* Tapping a photo opens the viewer.

		   This lives here rather than on a listener attached to the image
		   because the image's own click never fires on a phone: the swipe
		   handler above calls preventDefault(), and a browser will not
		   synthesise a click after that. This handler is the one that
		   demonstrably runs — it is what powers long-press and swipe — so
		   the tap is read from it directly.

		   Guards: nothing during a swipe or a long-press, and nothing if the
		   finger moved. The 290ms defer exists because a photo from the
		   other side is also double-tappable to react; a second tap cancels
		   the viewer. */
		if (e.type === 'touchend' && schatWasTap(e) && !swActive && !swFired) {
			var tapped = e.target && e.target.closest
				? e.target.closest('img.schat-photo')
				: null;
			var full = tapped && tapped.getAttribute('data-full');
			if (full) {
				/* Only a photo from the other side is double-tappable to
				   react, so only that one sits out the second tap. Your own
				   photos open on the first tap, with no delay. */
				if (!(rowEl && rowEl.classList.contains('schat-js-lovable'))) {
					schatOpenViewer(full);
				} else if (schatPhotoTapTimer) {
					clearTimeout(schatPhotoTapTimer);
					schatPhotoTapTimer = null;
				} else {
					schatPhotoTapTimer = setTimeout(function () {
						schatPhotoTapTimer = null;
						schatOpenViewer(full);
					}, 290);
				}
			}
		}

		// If the drag crossed the threshold, open the reply composer for
		// this exact message.
		if (swActive && swFired) {
			var overlay = this.closest('.schat-overlay');
			if (overlay) {
				var mid = overlay.getAttribute('data-member-id');
				var messageId = this.getAttribute('data-message-id');
				var mine = this.getAttribute('data-mine') === '1';
				var raw = (this.getAttribute('data-raw') || this.innerText || '').trim().split('\n')[0].slice(0, 60);
				var who = mine ? 'yourself' : (schatAjax.is_admin == 1 ? SCHAT_MEMBER_NAME(mid) : SCHAT_ADMIN_NAME);
				if (messageId) setReplyTarget(mid, messageId, raw, who);
			}
		}
		swReset();
	});

	/* Tap a quoted reply → scroll up to the original message and flash it.
	   Bound before the bubble's timestamp toggle and stops propagation so
	   the tap doesn't also reveal the time. */
	function scrollToMessage(memberId, messageId, tries) {
		var body = document.getElementById('schat-body-' + memberId);
		if (!body || !messageId) return;
		tries = tries || 0;

		var target = body.querySelector('.schat-row[data-message-id="' + messageId + '"]');
		if (!target) {
			/* The original is further back than what is loaded. Pull older
			   batches and retry. Previously this gave up after a single
			   attempt, so anything more than one page back was unreachable. */
			var st = getState(memberId);
			if (tries < 10 && st.hasOlder) {
				if (!st.backfilling) loadOlder(memberId, schatAjax.is_admin == 1);
				setTimeout(function () { scrollToMessage(memberId, messageId, tries + 1); }, 260);
			}
			return;
		}

		/* Measure with rects rather than offsetTop. .schat-body is not a
		   positioned element, so offsetTop was being reported against a
		   distant ancestor and the jump landed in the wrong place. Centre
		   the message in view where there is room to. */
		var bodyRect = body.getBoundingClientRect();
		var tRect    = target.getBoundingClientRect();
		var delta    = (tRect.top - bodyRect.top) - (body.clientHeight / 2) + (tRect.height / 2);
		var dest     = body.scrollTop + delta;
		var max      = body.scrollHeight - body.clientHeight;
		dest = Math.max(0, Math.min(dest, max));

		if (typeof body.scrollTo === 'function') {
			var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			body.scrollTo({ top: dest, behavior: reduce ? 'auto' : 'smooth' });
		} else {
			body.scrollTop = dest;
		}

		schatHaptic(8);
		target.classList.remove('schat-flash');
		void target.offsetWidth; // restart the animation
		target.classList.add('schat-flash');
		setTimeout(function () { target.classList.remove('schat-flash'); }, 1800);
	}

	/* Tap a quoted reply -> jump to the message it quotes.
	   The member id is read off the thread container rather than the
	   overlay, so this also works in standalone mode where there is no
	   .schat-overlay wrapper at all. */
	function schatMemberIdFrom(el) {
		var bodyEl = el.closest('[id^="schat-body-"]');
		if (bodyEl) return bodyEl.id.slice('schat-body-'.length);
		var overlay = el.closest('.schat-overlay');
		return overlay ? overlay.getAttribute('data-member-id') : null;
	}

	/* ------------------------------------------------------------------
	   Opening a photo.

	   A plain click listener is not enough on a phone. The bubble carries
	   swipe-to-reply and long-press handlers, and once those call
	   preventDefault the browser never synthesises the click — so tapping a
	   photo did nothing in the app. The tap is therefore detected from
	   touchend directly, with the same guards the reaction handler uses.
	   ------------------------------------------------------------------ */
	var schatPhotoTapTimer = null;

	/* Desktop / mouse. On a touch device the click either never arrives or
	   arrives as a ghost after touchend has already handled things, so it is
	   ignored there. */
	$(document).on('click', 'img.schat-photo', function (e) {
		var src = this.getAttribute('data-full');
		if (!src) return;
		e.stopPropagation();
		e.preventDefault();
		/* Ignore only the ghost click that follows a real touch. Testing
		   for touch *capability* broke every touchscreen laptop, where a
		   mouse click is the only event that ever arrives. */
		if (Date.now() - schatLastTouchAt < GHOST_GAP) return;
		schatOpenViewer(src);
	});

	$(document).on('click', '.schat-reply-quote', function (e) {
		e.stopPropagation();
		e.preventDefault();
		var mid = schatMemberIdFrom(this);
		var target = this.getAttribute('data-reply-to');
		if (mid && target) scrollToMessage(mid, target);
	});

	/* Tap a bubble to reveal its timestamp; tap again (or tap another) to
	   hide it. Long-press still opens the Reply/Copy menu, which also shows
	   the full date — this is just the quick glance. */
	$(document).on('click', '.schat-bubble', function (e) {
		// Don't hijack taps on a photo, a link, or a quoted reply inside.
		if (e.target.closest('a, img.schat-photo, .schat-reply-quote')) return;
		var rowEl = this.closest('.schat-row');
		if (!rowEl) return;
		var body = rowEl.parentNode;
		var wasOn = rowEl.classList.contains('schat-show-time');
		if (body) {
			var open = body.querySelectorAll('.schat-row.schat-show-time');
			for (var i = 0; i < open.length; i++) open[i].classList.remove('schat-show-time');
		}
		if (!wasOn) rowEl.classList.add('schat-show-time');
	});
	// NOTE: jQuery's .on() has no capture-phase argument — passing `true`
	// there is read as event *data*, which silently breaks the binding.
	// Use a native listener when capture is genuinely needed.
	document.addEventListener('click', function (e) {
		if (schatCtxEl && schatCtxEl.classList.contains('show') && !schatCtxEl.contains(e.target)) hideCtx();
	});
	document.addEventListener('scroll', function () { hideCtx(); }, true);

	$(document).on('click', '.schat-reply-cancel', function () {
		var overlay = this.closest('.schat-overlay');
		if (overlay) clearReply(overlay.getAttribute('data-member-id'));
	});

	$(document).on('click', '.schat-edit-cancel', function () {
		var overlay = this.closest('.schat-overlay');
		if (overlay) clearEdit(overlay.getAttribute('data-member-id'));
	});

	/* ============================================================
	   Live unread badge — polls even when no panel is open.
	   ============================================================ */
	function setBadge(count) {
		count = parseInt(count, 10) || 0;
		document.querySelectorAll('.schat-fab').forEach(function (fab) {
			var b = fab.querySelector('.schat-fab-badge');
			if (count > 0) {
				if (!b) {
					b = document.createElement('span');
					b.className = 'schat-fab-badge';
					fab.appendChild(b);
				}
				b.textContent = count > 9 ? '9+' : count;
			} else if (b) {
				b.remove();
			}
		});
	}

	function pollGlobalState() {
		if (!window.schatAjax) return;
		$.post(schatAjax.ajaxurl, { action: 'schat_poll_state', nonce: schatAjax.nonce }, function (r) {
			if (!r || !r.success) return;
			setBadge(r.data.unread_total);
			(r.data.threads || []).forEach(function (t) {
				var row = document.querySelector('.schat-inbox-row[data-member-id="' + t.member_id + '"]');
				if (!row) return;
				var badge = row.querySelector('.schat-inbox-badge');
				if (t.unread > 0) {
					if (!badge) {
						badge = document.createElement('span');
						badge.className = 'schat-inbox-badge';
						var right = row.querySelector('.schat-inbox-time');
						(right && right.parentNode ? right.parentNode : row).appendChild(badge);
					}
					badge.textContent = t.unread;
				} else if (badge) {
					badge.remove();
				}
			});
		});
	}
	setInterval(pollGlobalState, 7000);
	$(document).ready(pollGlobalState);

	/* ============================================================
	   Keyboard-aware viewport.
	   On mobile the on-screen keyboard shrinks the *visual* viewport
	   but NOT the layout viewport, so a vh-sized panel keeps its full
	   height and the bottom of it hides behind the keyboard — showing
	   as a dead gap where the page behind bleeds through.

	   Fixed-position elements are laid out against the layout viewport,
	   so moving the panel up via top/offsetTop does not work on Android.
	   Instead we publish --schat-kb (the height the keyboard covers) and
	   the overlays reserve it as a bottom inset.
	   ============================================================ */
	function schatSyncViewport() {
		var vv = window.visualViewport;
		var root = document.documentElement;
		if (!vv) {
			root.style.removeProperty('--schat-vh');
			root.style.removeProperty('--schat-vtop');
			root.style.removeProperty('--schat-kb');
			return;
		}
		// Only write when the value actually changed. Rewriting these on
		// every scroll frame forces a re-layout of the panel, which resets
		// the message list's scrollTop — the "jumps back to the top" loop.
		var h = Math.round(vv.height), t = Math.round(vv.offsetTop || 0);

		/* Height of the strip the on-screen keyboard covers.

		   Measured against a remembered baseline rather than
		   window.innerHeight. On Android, innerHeight moves around as the
		   browser's own toolbar collapses and expands, and offsetTop is
		   nonzero for reasons unrelated to the keyboard — subtracting it
		   under-reports the keyboard by roughly the toolbar height, which
		   is exactly the leftover gap above the keys.

		   The baseline is the largest visual height seen while no keyboard
		   was up; anything below it is the keyboard. It's re-armed on
		   orientationchange, since the tallest-ever value is meaningless
		   after a rotation. */
		if (schatSyncViewport._base == null || h > schatSyncViewport._base) {
			schatSyncViewport._base = h;
		}
		var kb = Math.round(schatSyncViewport._base - h);

		/* Small deltas are browser chrome (URL bar), not a keyboard. Once
		   we're past that floor, report the real value — do NOT snap it to
		   0, or a half-animated keyboard leaves the panel oversized. */
		if (kb < 90) kb = 0;

		if (schatSyncViewport._h !== h) {
			schatSyncViewport._h = h;
			// Kept published for any future rule that needs the visible
			// height directly; the overlays themselves size off --schat-kb.
			root.style.setProperty('--schat-vh', h + 'px');
		}
		if (schatSyncViewport._t !== t) {
			schatSyncViewport._t = t;
			root.style.setProperty('--schat-vtop', t + 'px');
		}
		if (schatSyncViewport._kb !== kb) {
			schatSyncViewport._kb = kb;
			root.style.setProperty('--schat-kb', kb + 'px');
		}

		/* ---- TEMPORARY DIAGNOSTIC — remove once the gap is fixed ----
		   Add ?schatdebug=1 to the feed URL to show live viewport numbers
		   in the corner. Nothing renders without that query flag. */
		if (location.search.indexOf('schatdebug=1') !== -1) {
			var d = document.getElementById('schat-dbg');
			if (!d) {
				d = document.createElement('div');
				d.id = 'schat-dbg';
				d.style.cssText = 'position:fixed;top:0;left:0;z-index:99999;'
					+ 'background:rgba(0,0,0,.85);color:#0f0;font:11px/1.45 monospace;'
					+ 'padding:6px 8px;pointer-events:none;white-space:pre;';
				document.body.appendChild(d);
			}
			var ov = document.querySelector('.schat-overlay.open');
			var pn = ov && ov.querySelector('.schat-panel');
			var ft = ov && ov.querySelector('.schat-footer');
			d.textContent =
				'innerH   ' + window.innerHeight + '\n' +
				'vv.h     ' + h + '\n' +
				'vv.top   ' + t + '\n' +
				'base     ' + schatSyncViewport._base + '\n' +
				'kb       ' + kb + '\n' +
				(ov ? 'ovl.bot  ' + Math.round(ov.getBoundingClientRect().bottom) + '\n' : '') +
				(pn ? 'pnl.bot  ' + Math.round(pn.getBoundingClientRect().bottom) + '\n' : '') +
				(ft ? 'ftr.bot  ' + Math.round(ft.getBoundingClientRect().bottom) : '');
		}
	}

	if (window.visualViewport) {
		window.visualViewport.addEventListener('resize', function () {
			schatSyncViewport();
			/* The keyboard just opened or closed. Any thread that was parked
			   at its end must stay there, otherwise the last message ends up
			   sitting behind the keys. Only threads already at the bottom
			   are touched, so nobody reading history gets yanked. */
			document.querySelectorAll('.schat-overlay.open[data-member-id]').forEach(function (ov) {
				var mid = ov.getAttribute('data-member-id');
				var body = document.getElementById('schat-body-' + mid);
				if (body && schatAtBottom(body)) scrollToBottom(mid, true);
			});
		});
		// NOTE: deliberately NOT bound to visualViewport 'scroll' — that fires
		// continuously while the user drags the message list and caused an
		// infinite scroll-reset loop on mobile.
	}
	window.addEventListener('orientationchange', function () {
		// Tallest-ever height is meaningless after a rotation — re-arm it.
		schatSyncViewport._base = null;
		setTimeout(schatSyncViewport, 250);
		setTimeout(schatSyncViewport, 600);
	});
	$(document).ready(schatSyncViewport);
	schatSyncViewport();

	// When the composer gains focus the keyboard animates in; re-sync a
	// few times and keep the latest message visible above it.
	$(document).on('focus', '.schat-input-row textarea', function () {
		var overlay = this.closest('.schat-overlay');
		var mid = overlay && overlay.getAttribute('data-member-id');
		/* Android fires visualViewport 'resize' once, but the keyboard
		   slides in over ~250ms — so poll briefly with rAF to track the
		   inset while it animates, instead of snapping at fixed offsets
		   and leaving a gap in between. Capped so this can never spin. */
		var until = Date.now() + 700;
		(function track() {
			schatSyncViewport();
			if (mid) scrollToBottom(mid, true);
			if (Date.now() < until) requestAnimationFrame(track);
		})();
	});
	$(document).on('blur', '.schat-input-row textarea', function () {
		/* Same on the way out — the keyboard slides down over a similar
		   period, and the overlay must grow back in step with it. The
		   baseline also self-corrects here: once the keys are gone the
		   visual height is at its true maximum again, so if the first
		   ever reading had been taken with a keyboard already up, this
		   raises the baseline to the correct value. */
		var until = Date.now() + 700;
		(function track() {
			schatSyncViewport();
			if (Date.now() < until) requestAnimationFrame(track);
		})();
	});

	function poll(memberId, isAdmin) {
		var st = getState(memberId);
		var overlay = findOverlay(memberId);
		if (!overlay || !overlay.classList.contains('open')) return; // don't poll closed panels
		if (document.hidden) return;  // tab/app backgrounded — save the round trip
		if (st.sending) return; // a send is in flight — avoid the duplicate race
		if (st.loading) return; // initial load in flight — it owns the list
		if (st.polling) return; // previous poll still open — never stack requests
		st.polling = true;
		$.post(schatAjax.ajaxurl, { action: 'schat_fetch_messages', nonce: schatAjax.nonce, member_id: memberId, after_id: st.lastId }, function (r) {
			st.polling = false;
			if (!r || !r.success) return;
			var got = r.data.messages && r.data.messages.length > 0;
			if (got) {
				// A gentle tick for genuinely inbound traffic (never our echoes).
				var anyInbound = r.data.messages.some(function (mm) {
					var mineMsg = isAdmin ? (parseInt(mm.sender_is_admin,10)===1)
					                      : (parseInt(mm.sender_is_admin,10)===0);
					return !mineMsg;
				});
				if (anyInbound) schatHaptic(6);
				var body = document.getElementById('schat-body-' + memberId);
				var wasEmpty = body && body.querySelector('.schat-empty');
				if (wasEmpty) body.innerHTML = '';
				var atBottom = schatAtBottom(body);
				r.data.messages.forEach(function (m) {
					try { renderMessage(memberId, m, isAdmin); }
					catch (e) { if (window.console) console.warn('schat render skipped', m && m.id, e); }
				});
				st.lastId = Math.max(parseInt(st.lastId, 10) || 0, parseInt(r.data.messages[r.data.messages.length - 1].id, 10) || 0);
				if (atBottom) scrollToBottom(memberId);
				markRead(memberId, isAdmin);
				schatBumpActivity(memberId, isAdmin); // fresh traffic → poll fast again
			}
			showTyping(memberId, !!r.data.typing, isAdmin);
			applyPresence(memberId, r.data.activity, r.data.activity_ago, isAdmin);
			consumeCelebration(memberId, r.data.celebrate, isAdmin, false);
			if (r.data.typing) schatBumpActivity(memberId, isAdmin); // they're typing → stay hot
			// Someone actively reading or typing means the thread is live —
			// keep polling fast so the state line stays honest.
			if (r.data.activity === 'typing' || r.data.activity === 'scrolling') {
				schatBumpActivity(memberId, isAdmin);
			}
			syncDeletions(memberId, r.data.deleted_ids);
			applySeen(memberId, r.data.last_seen_id, isAdmin);
			syncReactions(memberId, r.data.loved_ids);
			setBadge(r.data.unread_total);
		}).fail(function () { st.polling = false; });
	}

	function markRead(memberId, isAdmin) {
		$.post(schatAjax.ajaxurl, { action: 'schat_mark_read', nonce: schatAjax.nonce, member_id: memberId });
	}

	/* ------------------------------------------------------------------
	   "New Messages" divider suppression.

	   The server computes first_unread_id before mark_read runs, so a
	   quick close/reopen can race the pending mark_read and hand back the
	   same unread ids — the divider would flash a second time. We also
	   want it gone the moment the user actually scrolls past it.

	   sessionStorage keeps this per-tab and per-thread, so it survives a
	   reload but doesn't leak across sessions.
	   ------------------------------------------------------------------ */
	function newSepKey(memberId) { return 'schat_seen_new_' + memberId; }

	/* An earlier build stored the NEWEST message id here instead of the id
	   the unread run starts at, which suppressed every later divider. Wipe
	   those stale keys once so existing sessions recover. */
	(function purgeLegacyNewSepKeys() {
		try {
			if (window.sessionStorage.getItem('schat_seen_new_v') === '2') return;
			var kill = [];
			for (var i = 0; i < window.sessionStorage.length; i++) {
				var k = window.sessionStorage.key(i);
				if (k && k.indexOf('schat_seen_new_') === 0 && k !== 'schat_seen_new_v') kill.push(k);
			}
			for (var j = 0; j < kill.length; j++) window.sessionStorage.removeItem(kill[j]);
			window.sessionStorage.setItem('schat_seen_new_v', '2');
		} catch (e) {}
	})();

	// Keyed on the id the unread run STARTS at, not the newest message.
	// Storing the newest id meant that closing the thread suppressed every
	// future divider too, because a later run's start id is always lower
	// than a previously stored newest id.
	function newSepDismissed(memberId, fromId) {
		try {
			var v = parseInt(window.sessionStorage.getItem(newSepKey(memberId)), 10) || 0;
			return v > 0 && v === (parseInt(fromId, 10) || 0);
		} catch (e) { return false; }
	}

	function dismissNewSep(memberId, fromId) {
		try {
			var next = parseInt(fromId, 10) || 0;
			if (next > 0) window.sessionStorage.setItem(newSepKey(memberId), String(next));
		} catch (e) {}
	}

	// Once the divider scrolls out of view (or the user reaches the end of
	// the thread), retire it for good and fade it out of the current view.
	function watchNewSep(memberId, sepEl, fromId) {
		var body = document.getElementById('schat-body-' + memberId);
		if (!body || !sepEl) return;

		// Mark this run as seen for FUTURE opens straight away. The divider
		// has been shown, which is the whole contract — it should never
		// appear for the same run again, whatever the user does next.
		dismissNewSep(memberId, fromId);

		var done = false;
		// Visually retire it only once the user has genuinely scrolled past
		// it, so it stays put while they're still reading the new run.
		function retire() {
			if (done) return;
			done = true;
			body.removeEventListener('scroll', onScroll);
			sepEl.classList.add('schat-new-sep-out');
			setTimeout(function () {
				if (sepEl && sepEl.parentNode) sepEl.parentNode.removeChild(sepEl);
			}, 420);
		}
		var startTop = body.scrollTop;
		function onScroll() {
			// Require real downward movement first, so the initial settle
			// after scrollToDivider doesn't count as "crossed".
			if (body.scrollTop <= startTop + 8) return;
			if ((sepEl.offsetTop + sepEl.offsetHeight) < body.scrollTop) retire();
		}
		body.addEventListener('scroll', onScroll, { passive: true });
	}

	/* Adaptive polling. HOT (1s) for ~12s after any activity, then WARM
	   (2.5s), then COOL (5s) once the thread goes quiet. Self-chaining
	   setTimeout — never overlaps (poll() also guards with st.polling), and
	   pauses automatically while the app is backgrounded. */
	var SCHAT_HOT = 1000, SCHAT_WARM = 2500, SCHAT_COOL = 5000, SCHAT_HOT_FOR = 12000;

	function schatCurrentDelay(st) {
		if (document.hidden) return SCHAT_COOL;
		var since = Date.now() - (st.lastActivityAt || 0);
		if (since < SCHAT_HOT_FOR) return SCHAT_HOT;
		if (since < SCHAT_HOT_FOR * 3) return SCHAT_WARM;
		return SCHAT_COOL;
	}
	function schatBumpActivity(memberId /*, isAdmin */) {
		var st = getState(memberId);
		st.lastActivityAt = Date.now();
	}

	/* Tiny haptic tap on key moments (send confirmed, reaction). Uses the
	   Vibration API when the WebView exposes it; silently no-ops elsewhere.
	   Kept very short (6–10ms) so it reads as a "tick", never a buzz. */
	function schatHaptic(ms) {
		try { if (navigator.vibrate) navigator.vibrate(ms || 8); } catch (e) {}
	}
	function startPolling(memberId, isAdmin) {
		var st = getState(memberId);
		stopPolling(memberId);
		st.lastActivityAt = Date.now(); // opening counts as activity → start HOT
		st.pollStopped = false;
		(function tick() {
			if (st.pollStopped) return;
			var overlay = findOverlay(memberId);
			if (!overlay || !overlay.classList.contains('open')) { st.pollTimer = null; return; }
			poll(memberId, isAdmin);
			/* Presence heartbeat. sendActivity rate-limits a repeated state
			   to one ping per 12s for 'viewing', so riding the poll tick
			   costs a request every twelfth beat, not every beat. */
			if (!document.hidden) {
				sendActivity(memberId, (st.actState && st.actState !== 'away') ? st.actState : 'viewing');
				// Keep the push-suppression flag from going stale (90s server TTL).
				sendPresence(1);
			}
			st.pollTimer = setTimeout(tick, schatCurrentDelay(st));
		})();
	}
	function stopPolling(memberId) {
		var st = getState(memberId);
		st.pollStopped = true;
		if (st.pollTimer) { clearTimeout(st.pollTimer); st.pollTimer = null; }
	}

	/* On foreground return, poll once immediately so the thread is never
	   stale for up to 5s on resume. */
	document.addEventListener('visibilitychange', function () {
		var hidden = document.hidden;
		/* Backgrounded with the chat still open still means they are not
		   reading it, so pushes must start flowing again right away — and
		   stop again the moment they come back. */
		sendPresence(hidden ? 0 : schatAnyPanelOpen(), true);
		document.querySelectorAll('.schat-overlay.open').forEach(function (ov) {
			var mid = ov.getAttribute('data-member-id');
			if (!mid) return;
			if (hidden) {
				/* Backgrounded with the thread still open. Honest answer is
				   that they're no longer in the chat, so say so rather than
				   leaving a stale green dot for the other side. */
				sendActivity(mid, 'away', true);
				return;
			}
			schatBumpActivity(mid);
			sendActivity(mid, 'viewing', true);
			poll(mid, schatAjax.is_admin == 1);
		});
	});

	/* Last-gasp goodbye when the page itself goes away. Best effort only —
	   the browser may kill the request in flight, which is exactly why the
	   server also ages presence out on its own. */
	window.addEventListener('pagehide', function () {
		schatBeaconPresence(0); // leaving the page = leaving the chat
		document.querySelectorAll('.schat-overlay.open').forEach(function (ov) {
			var mid = ov.getAttribute('data-member-id');
			if (!mid) return;
			try {
				if (navigator.sendBeacon) {
					var fd = new FormData();
					fd.append('action', 'schat_activity');
					fd.append('nonce', schatAjax.nonce);
					fd.append('member_id', mid);
					fd.append('state', 'away');
					navigator.sendBeacon(schatAjax.ajaxurl, fd);
				}
			} catch (e) {}
		});
	});

	/* ============================================================
	   Celebration button
	   ============================================================ */
	$(document).on('click', '.schat-party-btn', function () {
		var btn = this;
		var ov = btn.closest ? btn.closest('.schat-overlay') : null;
		var mid = ov && ov.getAttribute('data-member-id');
		if (!mid || btn.disabled) return;

		/* Cosmetic cooldown — the server enforces its own. Long enough that
		   the burst can finish before a second one can replace it. */
		btn.disabled = true;
		setTimeout(function () { btn.disabled = false; }, 2500);
		btn.classList.add('pop');
		setTimeout(function () { btn.classList.remove('pop'); }, 600);

		// Play ours instantly; the round trip only matters to the other side.
		playCelebration(mid, false);
		schatBumpActivity(mid);

		$.post(schatAjax.ajaxurl, {
			action: 'schat_celebrate', nonce: schatAjax.nonce, member_id: mid
		}, function (r) {
			// Record our own sequence so the poll doesn't play it back at us.
			if (r && r.success && r.data && r.data.seq) {
				var st = getState(mid);
				var seq = parseFloat(r.data.seq) || 0;
				if (seq > st.lastPartySeq) st.lastPartySeq = seq;
			}
		});
	});

	/* Bind the scroll-up loader once per body element. Marked with a data
	   flag so reopening the panel doesn't stack duplicate listeners. */
	function bindBackfill(memberId, isAdmin) {
		var body = document.getElementById('schat-body-' + memberId);
		if (!body || body.getAttribute('data-backfill-bound') === '1') return;
		body.setAttribute('data-backfill-bound', '1');
		body.addEventListener('scroll', function () {
			// ~200px of runway so the batch arrives before the user
			// actually hits the top and sees a blank stall.
			if (body.scrollTop < 200) loadOlder(memberId, isAdmin);
			// Reading back through the thread is worth showing the other side.
			markScrolling(memberId);
		}, { passive: true });
	}

	function openChat(memberId, isAdmin) {
		var overlay = findOverlay(memberId);
		if (!overlay) return;
		overlay.classList.add('open');
		document.body.classList.add('schat-locked');
		document.body.style.overflow = 'hidden';
		schatSyncViewport();
		loadInitial(memberId, isAdmin);
		bindBackfill(memberId, isAdmin);
		startPolling(memberId, isAdmin);
		sendActivity(memberId, 'viewing', true); // "I'm here" — don't wait for the heartbeat
		/* Suppress pushes from this moment on. Fired here rather than only
		   from the native bridge below, so it is just as true for a
		   notification tap that deep-links straight into this thread. */
		sendPresence(1, true);

		// Tell the Android app (if we're running inside it) that chat is
		// now on screen, so it can suppress push notifications for this
		// user until they leave. Silently ignored in a normal browser
		// tab — window.ShataniaNative only exists inside the app.
		if (window.ShataniaNative && typeof window.ShataniaNative.onChatOpened === 'function') {
			try { window.ShataniaNative.onChatOpened(); } catch (e) {}
		}
	}

	function closeChat(memberId) {
		var overlay = findOverlay(memberId);
		if (!overlay) return;
		overlay.classList.remove('open');
		document.body.classList.remove('schat-locked');
		document.body.style.overflow = '';
		stopPolling(memberId);
		stopTyping(memberId);
		/* Say goodbye explicitly so the other side flips to "not in the
		   chat" now, instead of waiting ~35s for the state to go stale. */
		var stc = getState(memberId);
		if (stc.actSettleTimer) { clearTimeout(stc.actSettleTimer); stc.actSettleTimer = null; }
		sendActivity(memberId, 'away', true);
		stc.lastPresLabel = ''; // next open repaints from scratch
		// Out of the chat → notifications should reach them again.
		if (!schatAnyPanelOpen()) sendPresence(0, true);
		// Closing the thread counts as having seen the new run — the
		// divider must not come back on the next open.
		var st = getState(memberId);
		st.newFromId = 0;
		showTyping(memberId, false, schatAjax.is_admin == 1);
		hideCtx();

		if (window.ShataniaNative && typeof window.ShataniaNative.onChatClosed === 'function') {
			try { window.ShataniaNative.onChatClosed(); } catch (e) {}
		}
	}

	function updateSendState(memberId) {
		var overlay = findOverlay(memberId);
		if (!overlay) return;
		var textarea = overlay.querySelector('.schat-input-row textarea');
		var sendBtn = overlay.querySelector('.schat-send-btn');
		var st = getState(memberId);
		if (!textarea || !sendBtn) return;
		sendBtn.disabled = !((textarea.value || '').trim().length > 0 || st.pendingFile);
	}

	/* Optimistic send. The user's bubble is painted immediately (dimmed,
	   with a soft sheen) and reconciled when the server replies:
	     • success → the temp bubble is removed and the REAL one rendered
	       (renderMessage's id-dedupe means a racing poll can't double it);
	     • failure → the temp bubble flips to a "Not delivered · Retry" state,
	       and the composer text is restored so nothing is ever lost. */
	var schatTempSeq = 0;

	function schatCurrentReplySnippet(memberId) {
		var st = getState(memberId);
		if (!st.replyTo) return null;
		var textEl = document.getElementById('schat-reply-text-' + memberId);
		var txt = textEl ? (textEl.textContent || '').trim() : '';
		return txt ? { text: txt } : null;
	}

	function schatMakePending(memberId, isAdmin, text, imageDataUrl, replySnippet, uploadingPhoto) {
		var body = document.getElementById('schat-body-' + memberId);
		if (!body) return null;
		var wasEmpty = body.querySelector('.schat-empty');
		if (wasEmpty) body.innerHTML = '';

		var row = document.createElement('div');
		row.className = 'schat-row mine schat-pending schat-settled';
		var tempId = 'tmp-' + (++schatTempSeq);
		row.setAttribute('data-temp-id', tempId);
		row.setAttribute('data-mine', '1'); // optimistic bubbles are always mine

		var bubble = document.createElement('div');
		bubble.className = 'schat-bubble';

		if (replySnippet && replySnippet.text) {
			var q = document.createElement('div');
			q.className = 'schat-reply-quote';
			var who = document.createElement('span');
			who.className = 'schat-reply-who';
			who.textContent = 'You';
			q.appendChild(who);
			var qt = document.createElement('span');
			qt.textContent = replySnippet.text;
			q.appendChild(qt);
			bubble.appendChild(q);
		}
		/* While a photo is uploading we deliberately do NOT show it.

		   A local preview has an unknown height until the browser decodes
		   it, so the thread grew underneath the scroll and the bubble ended
		   up straddling the keyboard. A fixed-height progress bar has a
		   known size from the very first frame, so nothing moves. The real
		   photo appears only once it is on the server, and is scrolled into
		   place then. */
		if (uploadingPhoto) {
			bubble.classList.add('schat-uploading');

			var bar = document.createElement('div');
			bar.className = 'schat-uploadbar';
			bar.innerHTML =
				'<span class="schat-uploadbar-icon">' +
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
				'<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg>' +
				'</span>' +
				'<span class="schat-uploadbar-body">' +
				'<span class="schat-uploadbar-label">Uploading photo</span>' +
				'<span class="schat-uploadbar-track"><i></i></span>' +
				'</span>' +
				'<span class="schat-uploadbar-pct">0%</span>';
			bubble.appendChild(bar);
		}
		if (text) {
			var t = document.createElement('div');
			t.innerHTML = esc(text).replace(/\n/g, '<br>');
			bubble.appendChild(t);
		}
		row.appendChild(bubble);

		var meta = document.createElement('div');
		meta.className = 'schat-meta';
		var mt = document.createElement('span');
		mt.textContent = timeLabel(new Date());
		meta.appendChild(mt);
		row.appendChild(meta);

		body.appendChild(row);
		// Cluster the optimistic bubble against the previous one if it's also mine.
		(function group() {
			var prev = row.previousElementSibling;
			if (prev && prev.classList && prev.classList.contains('schat-row') &&
			    prev.getAttribute('data-mine') === '1') {
				prev.classList.add('schat-grouped-prev');
				prev.classList.remove('schat-run-tail');
				row.classList.add('schat-grouped-cont');
			}
			row.classList.add('schat-run-tail');
		})();
		scrollToBottom(memberId, true);
		return tempId;
	}

	function schatRemoveTemp(memberId, tempId) {
		if (!tempId) return;
		var body = document.getElementById('schat-body-' + memberId);
		var el = body && body.querySelector('.schat-row[data-temp-id="' + tempId + '"]');
		if (el && el.parentNode) el.parentNode.removeChild(el);
	}

	function schatMarkFailed(memberId, tempId, payload, reason) {
		var body = document.getElementById('schat-body-' + memberId);
		var row = body && body.querySelector('.schat-row[data-temp-id="' + tempId + '"]');
		if (!row) return;
		row.classList.remove('schat-pending');
		row.classList.add('schat-failed');
		if (row.querySelector('.schat-retry')) return;

		/* Show the server's actual reason. Without this every failure looked
		   identical, so a member hitting a size or permission problem got the
		   same "Not delivered" as someone with a flaky connection. */
		if (reason) {
			var why = document.createElement('div');
			why.className = 'schat-fail-reason';
			why.textContent = reason;
			row.appendChild(why);
		}

		var retry = document.createElement('button');
		retry.type = 'button';
		retry.className = 'schat-retry';
		retry.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg><span>Not delivered · Retry</span>';
		retry.addEventListener('click', function () {
			schatRemoveTemp(memberId, tempId);
			schatDoSend(memberId, payload.isAdmin, payload);
		});
		row.appendChild(retry);
	}

	/* ------------------------------------------------------------------
	   Client-side photo compression.

	   Phone cameras produce 4-6MB, 4000px-wide JPEGs. Nothing in the chat
	   ever displays a photo wider than ~800 CSS pixels, so shipping the
	   original is pure waiting. Resizing to 1600px on the long edge at 82%
	   quality typically cuts 5MB to ~350KB — a 10-15x faster upload with no
	   visible difference in the thread or the zoom viewer.

	   Falls back to the original file on any failure, and never touches GIFs
	   (it would flatten the animation) or files that are already small.
	   ------------------------------------------------------------------ */
	var SCHAT_MAX_EDGE = 1600;
	var SCHAT_JPEG_Q   = 0.82;
	var SCHAT_SKIP_UNDER = 320 * 1024; // already small enough to not bother

	function schatCompressImage(file, done) {
		try {
			if (!file || !/^image\//.test(file.type) || /gif/i.test(file.type)) return done(file);
			if (file.size < SCHAT_SKIP_UNDER) return done(file);
			if (!window.URL || !window.HTMLCanvasElement) return done(file);
		} catch (e) { return done(file); }

		var url = URL.createObjectURL(file);
		var img = new Image();
		var settled = false;

		function finish(result) {
			if (settled) return;
			settled = true;
			try { URL.revokeObjectURL(url); } catch (e) {}
			done(result || file);
		}

		// Never let a decode hang the send.
		setTimeout(function () { finish(file); }, 8000);

		img.onerror = function () { finish(file); };
		img.onload = function () {
			try {
				var w = img.naturalWidth, h = img.naturalHeight;
				if (!w || !h) return finish(file);

				var scale = Math.min(1, SCHAT_MAX_EDGE / Math.max(w, h));
				var tw = Math.round(w * scale);
				var th = Math.round(h * scale);

				var canvas = document.createElement('canvas');
				canvas.width = tw; canvas.height = th;
				var ctx = canvas.getContext('2d');
				ctx.imageSmoothingEnabled = true;
				ctx.imageSmoothingQuality = 'high';
				ctx.drawImage(img, 0, 0, tw, th);

				canvas.toBlob(function (blob) {
					// Only take the compressed version if it actually helps.
					if (!blob || blob.size >= file.size) return finish(file);
					var name = (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';
					try {
						finish(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
					} catch (e) {
						blob.name = name;          // Safari/older WebView
						finish(blob);
					}
				}, 'image/jpeg', SCHAT_JPEG_Q);
			} catch (e) { finish(file); }
		};
		img.src = url;
	}

	/** Paints upload progress onto the pending bubble's bar. */
	function schatSetUploadProgress(memberId, tempId, pct, label) {
		var body = document.getElementById('schat-body-' + memberId);
		var row = body && body.querySelector('.schat-row[data-temp-id="' + tempId + '"]');
		var bar = row && row.querySelector('.schat-uploadbar');
		if (!bar) return;

		if (pct === null) return; // bubble is about to be replaced anyway

		var clamped = Math.max(0, Math.min(100, Math.round(pct)));
		var fill = bar.querySelector('.schat-uploadbar-track i');
		var txt  = bar.querySelector('.schat-uploadbar-pct');
		var lab  = bar.querySelector('.schat-uploadbar-label');

		var preparing = (label === 'Preparing');
		bar.classList.toggle('indeterminate', preparing);

		if (fill) fill.style.width = clamped + '%';
		if (txt)  txt.textContent = preparing ? '' : clamped + '%';
		if (lab)  lab.textContent = preparing ? 'Preparing photo' : 'Uploading photo';
	}

	// Core sender. `retryPayload` is present only on a retry (text/file kept).
	function schatDoSend(memberId, isAdmin, retryPayload) {
		var overlay = findOverlay(memberId);
		if (!overlay) return;
		var textarea   = overlay.querySelector('.schat-input-row textarea');
		var sendBtn    = overlay.querySelector('.schat-send-btn');
		var fileInput  = overlay.querySelector('.schat-file-input');
		var attachPrev = document.getElementById('schat-attach-preview-' + memberId);
		var st = getState(memberId);

		var isRetry = !!retryPayload;
		var text = isRetry ? retryPayload.text : (textarea.value || '').trim();
		var file = isRetry ? retryPayload.file : st.pendingFile;
		var replyTo = isRetry ? retryPayload.replyTo : st.replyTo;
		var replySnip = isRetry ? retryPayload.replySnippet : schatCurrentReplySnippet(memberId);

		if (!isRetry) {
			if (st.sending) return;
			if (!text && !file) return;
		}
		st.sending = true;
		if (sendBtn) sendBtn.disabled = true;

		// No local preview any more — see schatMakePending() for why.
		var imgPreview = null;
		var tempId = schatMakePending(memberId, isAdmin, text, null, replySnip, !!file);
		schatBumpActivity(memberId, isAdmin);

		var restore = { text: text, file: file, replyTo: replyTo, replySnippet: replySnip, isAdmin: isAdmin };
		if (!isRetry) {
			textarea.value = ''; schatSetHeight(textarea, 'auto'); textarea.style.overflowY = 'hidden';
			textarea.classList.remove('schat-scrollable');
			st.pendingFile = null;
			if (fileInput) fileInput.value = '';
			if (attachPrev) attachPrev.classList.remove('show');
			clearReply(memberId);
			stopTyping(memberId);
			updateSendState(memberId);
			// Telegram-style: keep the keyboard up. Clearing the value / the
			// send tap can drop focus on Android WebView, which collapses the
			// keyboard. Re-assert focus on the composer right away so it stays.
			try { textarea.focus({ preventScroll: true }); } catch (e) { try { textarea.focus(); } catch (e2) {} }
		}

		/* Shrink the photo first, then post. The bubble is already on screen
		   showing the local preview, so this happens behind a "Preparing"
		   label rather than a frozen UI. */
		if (file) schatSetUploadProgress(memberId, tempId, 0, 'Preparing');

		schatCompressImage(file, function (outFile) {

		var fd = new FormData();
		fd.append('action', 'schat_send_message');
		fd.append('nonce', schatAjax.nonce);
		fd.append('member_id', memberId);
		fd.append('message', text);
		if (replyTo) fd.append('reply_to', replyTo);
		if (outFile) fd.append('photo', outFile);

		$.ajax({
			url: schatAjax.ajaxurl, type: 'POST', data: fd, processData: false, contentType: false,
			xhr: function () {
				var xhr = new window.XMLHttpRequest();
				if (outFile && xhr.upload) {
					xhr.upload.addEventListener('progress', function (e) {
						if (!e.lengthComputable) return;
						var pct = (e.loaded / e.total) * 100;
						// Hold at 99 until the server actually answers, so the
						// ring never sits at 100% while WordPress is still
						// writing the file.
						schatSetUploadProgress(memberId, tempId, Math.min(99, pct), 'Uploading');
					}, false);
				}
				return xhr;
			},
			success: function (r) {
				st.sending = false;
				if (imgPreview) { try { URL.revokeObjectURL(imgPreview); } catch (e) {} }
				schatSetUploadProgress(memberId, tempId, null);
				if (r && r.success) {
					schatRemoveTemp(memberId, tempId);
					var body = document.getElementById('schat-body-' + memberId);
					var wasEmpty = body && body.querySelector('.schat-empty');
					if (wasEmpty) body.innerHTML = '';
					renderMessage(memberId, r.data.message, isAdmin);
					st.lastId = Math.max(parseInt(st.lastId, 10) || 0, parseInt(r.data.message.id, 10) || 0);
					scrollToBottom(memberId, true);
					/* The photo is on the server now. Wait for it to decode,
					   then park it fully in view above the keyboard. */
					if (r.data.message.image_url) {
						var newRow = body && body.querySelector(
							'.schat-row[data-message-id="' + r.data.message.id + '"]');
						var newImg = newRow && newRow.querySelector('img.schat-photo');
						if (newImg) schatPinPhoto(memberId, newImg, true);
					}
					schatHaptic(8); // confirmed — soft tick
					/* More photos queued from a multi-select: send the next
					   one on its own, with no text attached. */
					if (!isRetry && st.pendingQueue && st.pendingQueue.length) {
						st.pendingFile = st.pendingQueue.shift();
						setTimeout(function () { schatDoSend(memberId, isAdmin, null); }, 140);
					}
				} else {
					var why = (r && typeof r.data === 'string') ? r.data : '';
					schatMarkFailed(memberId, tempId, restore, why);
				}
				updateSendState(memberId);
			},
			error: function (xhr) {
				st.sending = false;
				if (imgPreview) { try { URL.revokeObjectURL(imgPreview); } catch (e) {} }
				schatSetUploadProgress(memberId, tempId, null);
				/* A 413 means the request never reached WordPress — the web
				   server rejected it on size alone, which is the one failure
				   a nonce-based handler can never report itself. */
				var why = '';
				if (xhr && xhr.status === 413) why = 'That photo was too large for the server to accept.';
				else if (xhr && xhr.status === 0) why = 'Connection lost. Check your signal and retry.';
				schatMarkFailed(memberId, tempId, restore, why);
				updateSendState(memberId);
			}
		});

		});
	}

	// Public entry point kept under the original name so every existing
	// caller (Send button, etc.) works unchanged. When an edit is in
	// progress the same tap commits the edit instead of posting anew.
	function sendMessage(memberId, isAdmin) {
		var st = getState(memberId);
		if (st.editing) { schatCommitEdit(memberId, isAdmin); return; }
		schatDoSend(memberId, isAdmin, null);
	}

	/* ------------------------------------------------------------------
	   Reactions: burst animation + cross-device sync.

	   The heart itself is added by renderMessage (initial paint) or by
	   toggleLove (the tapper's own device). syncReactions() is what makes
	   the OTHER side see it: the live poll ships the current loved set for
	   the recent slice of the thread, and we diff it against what is on
	   screen. Newly loved bubbles get the heart plus a burst; un-loved ones
	   lose it silently.
	   ------------------------------------------------------------------ */

	/* Bumped by every reaction. A run whose token is stale has been
	   superseded and must keep its hands off the DOM. */
	var schatLoveRun = 0;

	function schatHeartBurst(bubble) {
		if (!bubble) return;

		/* The message itself takes the stage.

		   A copy of the bubble lifts out of the thread and glides to the
		   middle of the screen, where a heart lands on it and a small
		   confetti puff goes off; it holds for a beat, then eases back down
		   into exactly the spot it came from and fades as it arrives. The
		   real bubble is hidden for the trip, so there is only ever one of
		   it on screen, and its place in the thread never moves.

		   Both people see this: the sender from their own tap, the other
		   side when the reaction arrives on the next poll.

		   Timings are deliberately unhurried — the previous version threw
		   fifteen hearts across the viewport in a quarter of a second,
		   which is what made it feel harsh. */
		var reduced = false;
		try {
			reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		} catch (e) {}
		if (reduced) {
			// Acknowledge without the journey.
			bubble.classList.add('schat-love-nudge');
			setTimeout(function () { bubble.classList.remove('schat-love-nudge'); }, 420);
			return;
		}

		/* One at a time — a second tap mid-flight would fight the first.
		   Each run takes a token; the timers below check it before touching
		   anything, so a superseded run can never un-hide the bubble the
		   NEW run is currently flying. */
		var myRun = ++schatLoveRun;
		var running = document.querySelector('.schat-lovestage');
		if (running && running.parentNode) running.parentNode.removeChild(running);
		document.querySelectorAll('.schat-bubble.schat-love-away').forEach(function (el) {
			el.classList.remove('schat-love-away');
		});

		var r = bubble.getBoundingClientRect();
		if (!r.width || !r.height) return;

		var cx = window.innerWidth / 2;
		var cy = window.innerHeight / 2;
		var dx = cx - (r.left + r.width / 2);
		var dy = cy - (r.top + r.height / 2);

		/* The stage carries .schat-root so the copy inherits the chat's CSS
		   variables and every rule scoped under it. Without this the clone
		   renders as an unstyled bubble — no gold for your own messages,
		   different padding, and therefore a different size. */
		var stage = document.createElement('div');
		stage.className = 'schat-root schat-lovestage';

		var scrim = document.createElement('div');
		scrim.className = 'schat-lovescrim';
		stage.appendChild(scrim);

		// The travelling copy, pinned exactly over the original to begin with.
		/* Width has to come from the computed style, not from the rect.
		   getBoundingClientRect() reports the PAINTED box, which is rounded
		   to a sub-pixel grid: a bubble whose used width is 43.125px
		   measures 43.1214px. A short message is shrink-to-fit, so it sits
		   at exactly max-content — give it a hundredth of a pixel less and
		   the text wraps, which is how "hi" ended up as "h" / "i".
		   Using the used width, and setting it in px rather than as a
		   percentage, keeps the copy's line breaks identical to the real
		   bubble's. */
		var usedW = parseFloat(getComputedStyle(bubble).width);
		if (!(usedW > 0)) usedW = r.width;

		var ghost = document.createElement('div');
		ghost.className = 'schat-loveghost';
		ghost.style.left   = r.left + 'px';
		ghost.style.top    = r.top + 'px';
		ghost.style.width  = usedW + 'px';
		var clone = bubble.cloneNode(true);
		clone.classList.remove('schat-love-away');
		clone.removeAttribute('id');
		/* Pin the copy to the same used width. setProperty(..., 'important')
		   because the stylesheet's own rules are !important and would
		   otherwise win over a plain inline style. */
		clone.style.setProperty('width', usedW + 'px', 'important');
		clone.style.setProperty('max-width', 'none', 'important');
		clone.style.setProperty('min-width', '0', 'important');

		/* ...and it sits inside a copy of its own row, because which side a
		   bubble is on is expressed as `.schat-row.mine .schat-bubble`.
		   Lift it out of that and your own messages lose their colour. */
		var srcRow = bubble.closest ? bubble.closest('.schat-row') : null;
		var rowClone = document.createElement('div');
		rowClone.className = srcRow ? srcRow.className : 'schat-row';
		// Drop the transient interaction classes; this copy is not being touched.
		['schat-pressing', 'schat-swiping', 'schat-swipe-ready', 'schat-show-time',
		 'schat-has-seen', 'schat-seen-loved'].forEach(function (c) { rowClone.classList.remove(c); });
		rowClone.appendChild(clone);
		ghost.appendChild(rowClone);
		stage.appendChild(ghost);

		document.body.appendChild(stage);
		bubble.classList.add('schat-love-away');

		/* Two frames before moving: one for the node to be laid out, one
		   for the browser to accept the start values as the transition's
		   origin. Skipping this makes the bubble jump instead of glide. */
		var launched = false;
		function launch() {
			if (launched || myRun !== schatLoveRun) return;
			launched = true;
			scrim.classList.add('on');
			ghost.style.transform = 'translate3d(' + dx.toFixed(1) + 'px,' + dy.toFixed(1) + 'px,0) scale(1.07)';
		}
		requestAnimationFrame(function () { requestAnimationFrame(launch); });
		/* Belt and braces: a backgrounded WebView stops servicing
		   requestAnimationFrame entirely, which would leave the copy parked
		   on top of the message with the real one hidden underneath. Timers
		   keep running, so this guarantees the trip starts either way. */
		setTimeout(launch, 60);

		// Heart + confetti land once it has arrived.
		var ARRIVE = 560, HOLD = 950, BACK = 470;

		setTimeout(function () {
			if (myRun !== schatLoveRun || !stage.parentNode) return;

			var heart = document.createElement('div');
			heart.className = 'schat-lovepop';
			heart.textContent = '❤️';
			/* Anchored to the SCALED top edge, not the raw height, so the
			   heart rests on the bubble by the same small amount whether
			   the message is one word or five lines. Left as a fixed
			   clearance rather than a fraction: a fraction would bury a
			   short bubble under the heart. */
			heart.style.left = cx + 'px';
			heart.style.top  = (cy - (r.height * 1.07) / 2 - 12) + 'px';
			stage.appendChild(heart);

			var COLORS = ['#D9A9AC', '#E8C4C6', '#F7A1C4', '#FFD36E', '#8FD3C7', '#FFFFFF'];
			var n = window.innerWidth < 420 ? 14 : 18;
			var reach = Math.min(window.innerWidth, window.innerHeight) * 0.20;

			for (var k = 0; k < n; k++) {
				// An even ring with a little jitter, so it reads as a puff
				// rather than a pattern.
				var ang  = (Math.PI * 2 * k / n) + (Math.random() - 0.5) * 0.5;
				var dist = reach * (0.55 + Math.random() * 0.5);
				var p = document.createElement('i');
				p.className = 'schat-lovep';
				p.style.left = cx + 'px';
				p.style.top  = cy + 'px';
				p.style.setProperty('--dx', (Math.cos(ang) * dist).toFixed(1) + 'px');
				p.style.setProperty('--dy', (Math.sin(ang) * dist).toFixed(1) + 'px');
				p.style.setProperty('--rot', (Math.random() * 300 - 150).toFixed(0) + 'deg');
				p.style.setProperty('--c', COLORS[Math.floor(Math.random() * COLORS.length)]);
				p.style.width  = (4 + Math.random() * 4).toFixed(1) + 'px';
				p.style.height = (7 + Math.random() * 6).toFixed(1) + 'px';
				if (Math.random() < 0.3) p.style.borderRadius = '50%';
				p.style.animationDelay = (Math.random() * 90).toFixed(0) + 'ms';
				stage.appendChild(p);
			}
			schatHaptic(8);
		}, ARRIVE);

		// ...then it goes home.
		setTimeout(function () {
			if (myRun !== schatLoveRun || !stage.parentNode) return;
			ghost.classList.add('returning');
			ghost.style.transform = 'translate3d(0,0,0) scale(1)';
			var pop = stage.querySelector('.schat-lovepop');
			if (pop) pop.classList.add('leaving');
			scrim.classList.remove('on');
		}, ARRIVE + HOLD);

		// Reveal the real bubble underneath just as the copy settles onto it.
		setTimeout(function () {
			if (myRun !== schatLoveRun) return; // a newer run owns the stage now
			bubble.classList.remove('schat-love-away');
		}, ARRIVE + HOLD + BACK - 70);

		setTimeout(function () {
			if (stage.parentNode) stage.parentNode.removeChild(stage);
			if (myRun !== schatLoveRun) return;
			bubble.classList.remove('schat-love-away');
		}, ARRIVE + HOLD + BACK + 140);
	}

	function schatAddHeart(bubble, burst) {
		if (!bubble || bubble.querySelector('.schat-loved')) return;
		var loved = document.createElement('div');
		loved.className = 'schat-loved';
		loved.textContent = '❤️';
		bubble.classList.add('has-loved');
		bubble.appendChild(loved);
		if (burst) schatHeartBurst(bubble);
	}

	function schatRemoveHeart(bubble) {
		if (!bubble) return;
		var existing = bubble.querySelector('.schat-loved');
		if (existing) existing.remove();
		bubble.classList.remove('has-loved');
	}

	function syncReactions(memberId, lovedIds) {
		if (!lovedIds || !lovedIds.length && !Array.isArray(lovedIds)) return;
		var body = document.getElementById('schat-body-' + memberId);
		if (!body) return;

		var st = getState(memberId);
		/* First sync after opening a thread just establishes the baseline —
		   hearts already painted by the initial render must not all burst. */
		var burst = !!st.lovedReady;
		st.lovedReady = true;

		var set = {};
		for (var i = 0; i < lovedIds.length; i++) set[parseInt(lovedIds[i], 10)] = 1;

		var bubbles = body.querySelectorAll('.schat-bubble[data-message-id]');
		for (var j = 0; j < bubbles.length; j++) {
			var b  = bubbles[j];
			var id = parseInt(b.getAttribute('data-message-id'), 10);
			if (!id) continue;
			var has = !!b.querySelector('.schat-loved');
			if (set[id] && !has) {
				schatAddHeart(b, burst);
				if (burst) schatHaptic(10);
			} else if (!set[id] && has) {
				schatRemoveHeart(b);
			}
		}
	}

	/* True for a message that is nothing but emoji (up to a few of them).
	   Those render big and bare, with no bubble — the way every phone
	   messenger does it. Falls back to false wherever Unicode property
	   escapes are unsupported. */
	/* ------------------------------------------------------------------
	   Photo viewer. window.open() is blocked inside the Android WebView,
	   so tapping a photo used to do nothing there. This is a self-contained
	   overlay with pinch-to-zoom, double-tap zoom and drag-to-pan.
	   ------------------------------------------------------------------ */
	var schatViewer = null, schatViewerPrevOverflow = '';

	function schatOpenViewer(src) {
		if (!src) return;
		schatCloseViewer();

		var wrap = document.createElement('div');
		wrap.className = 'schat-viewer';

		var closeBtn = document.createElement('button');
		closeBtn.type = 'button';
		closeBtn.className = 'schat-viewer-close';
		closeBtn.setAttribute('aria-label', 'Close photo');
		closeBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

		var img = document.createElement('img');
		img.className = 'schat-viewer-img';
		img.src = src;
		img.alt = '';

		/* A big photo takes a moment over mobile data. Without this the
		   overlay opened on to an empty black screen and read as broken. */
		var spin = document.createElement('div');
		spin.className = 'schat-viewer-spin';

		wrap.setAttribute('role', 'dialog');
		wrap.setAttribute('aria-modal', 'true');
		wrap.setAttribute('aria-label', 'Photo');
		wrap.appendChild(spin);
		wrap.appendChild(img);
		wrap.appendChild(closeBtn);
		document.body.appendChild(wrap);

		function ready() { wrap.classList.add('loaded'); }
		if (img.complete && img.naturalWidth) ready();
		else { img.addEventListener('load', ready); img.addEventListener('error', ready); }

		/* Hold the page still underneath. Whatever it was set to is put
		   back on close, so this nests safely inside the chat overlay,
		   which locks scrolling itself. */
		schatViewerPrevOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';

		requestAnimationFrame(function () { wrap.classList.add('open'); });

		var scale = 1, tx = 0, ty = 0;
		var startDist = 0, startScale = 1;
		var dragging = false, lastX = 0, lastY = 0;
		var lastTap = 0;

		function apply() {
			img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')';
			wrap.classList.toggle('zoomed', scale > 1.02);
		}
		function clampPan() {
			// Keep the image from being dragged entirely off screen.
			var limX = (img.clientWidth  * (scale - 1)) / 2;
			var limY = (img.clientHeight * (scale - 1)) / 2;
			tx = Math.max(-limX, Math.min(limX, tx));
			ty = Math.max(-limY, Math.min(limY, ty));
		}
		function reset() { scale = 1; tx = 0; ty = 0; apply(); }

		/* Zoom straight to 1:1 with the photo's own pixels where that is
		   sensible, so a high-resolution shot actually resolves instead of
		   magnifying the fitted-down version. */
		function zoomTarget() {
			var shown = img.clientWidth || 0;
			if (!shown || !img.naturalWidth) return 2.5;
			return Math.max(2, Math.min(4, img.naturalWidth / shown));
		}

		function dist(t) {
			var dx = t[0].clientX - t[1].clientX;
			var dy = t[0].clientY - t[1].clientY;
			return Math.sqrt(dx * dx + dy * dy);
		}

		img.addEventListener('touchstart', function (e) {
			if (e.touches.length === 2) {
				startDist = dist(e.touches);
				startScale = scale;
				dragging = false;
			} else if (e.touches.length === 1) {
				dragging = scale > 1.02;
				lastX = e.touches[0].clientX;
				lastY = e.touches[0].clientY;
			}
		}, { passive: true });

		img.addEventListener('touchmove', function (e) {
			if (e.touches.length === 2 && startDist > 0) {
				e.preventDefault();
				scale = Math.max(1, Math.min(5, startScale * (dist(e.touches) / startDist)));
				clampPan();
				apply();
			} else if (dragging && e.touches.length === 1) {
				e.preventDefault();
				tx += e.touches[0].clientX - lastX;
				ty += e.touches[0].clientY - lastY;
				lastX = e.touches[0].clientX;
				lastY = e.touches[0].clientY;
				clampPan();
				apply();
			}
		}, { passive: false });

		img.addEventListener('touchend', function (e) {
			startDist = 0;
			if (scale < 1.02) reset();

			if (e.touches.length === 0 && e.changedTouches.length === 1) {
				var now = Date.now();
				if (now - lastTap < 300) {
					// Double tap toggles between fit and 2.5x.
					if (scale > 1.02) reset();
					else { scale = zoomTarget(); apply(); }
					lastTap = 0;
				} else {
					lastTap = now;
				}
			}
		}, { passive: true });

		// Desktop: wheel to zoom, double-click to toggle.
		img.addEventListener('wheel', function (e) {
			e.preventDefault();
			scale = Math.max(1, Math.min(5, scale - e.deltaY * 0.0016));
			if (scale <= 1.02) { tx = 0; ty = 0; }
			clampPan();
			apply();
		}, { passive: false });

		img.addEventListener('dblclick', function () {
			if (scale > 1.02) reset(); else { scale = zoomTarget(); apply(); }
		});

		closeBtn.addEventListener('click', schatCloseViewer);
		wrap.addEventListener('click', function (e) {
			if (e.target === wrap) schatCloseViewer();
		});

		schatViewer = wrap;
		document.addEventListener('keydown', schatViewerKey);
	}

	function schatViewerKey(e) {
		if (e.key === 'Escape') schatCloseViewer();
	}

	function schatCloseViewer() {
		document.removeEventListener('keydown', schatViewerKey);
		if (!schatViewer) return;
		var w = schatViewer;
		schatViewer = null;
		document.body.style.overflow = schatViewerPrevOverflow;
		w.classList.remove('open');
		setTimeout(function () { if (w.parentNode) w.parentNode.removeChild(w); }, 220);
	}

	function schatIsEmojiOnly(text) {
		if (!text) return false;
		var t = String(text).replace(/[\s‍️]/g, '');
		if (!t || t.length > 12) return false;
		try {
			return /^(?:\p{Extended_Pictographic}|\p{Emoji_Presentation})+$/u.test(t);
		} catch (e) { return false; }
	}

	/* Messages with a reaction request in flight. Guards against the same
	   gesture being delivered twice by different events. */
	var schatLoveBusy = {};

	function toggleLove(messageId, rowEl) {
		messageId = parseInt(messageId, 10) || 0;
		if (!messageId || schatLoveBusy[messageId]) return;

		var bubble = rowEl.querySelector('.schat-bubble');
		if (!bubble) return;

		/* Send the state we WANT, not "flip it". A blind toggle turns a
		   duplicated request into an undo; an explicit value just sets the
		   same thing twice. */
		var want = bubble.querySelector('.schat-loved') ? 0 : 1;

		schatLoveBusy[messageId] = true;
		// Paint immediately — the reaction should feel instant, not awaited.
		if (want) { schatAddHeart(bubble, true); schatHaptic(12); }
		else      { schatRemoveHeart(bubble); }

		$.post(schatAjax.ajaxurl, {
			action: 'schat_toggle_love', nonce: schatAjax.nonce,
			message_id: messageId, love: String(want)
		}, function (r) {
			delete schatLoveBusy[messageId];
			if (!r || !r.success) {
				// Server said no — put it back the way it was.
				if (want) schatRemoveHeart(bubble); else schatAddHeart(bubble, false);
				return;
			}
			// Reconcile with what actually got stored, without re-animating.
			if (r.data.loved) schatAddHeart(bubble, false);
			else              schatRemoveHeart(bubble);
		}).fail(function () {
			delete schatLoveBusy[messageId];
			if (want) schatRemoveHeart(bubble); else schatAddHeart(bubble, false);
		});
	}

	function notifyMember(messageId, btnEl) {
		btnEl.disabled = true;
		$.post(schatAjax.ajaxurl, { action: 'schat_notify_member', nonce: schatAjax.nonce, message_id: messageId }, function (r) {
			if (r && r.success) {
				btnEl.classList.add('sent');
				var span = btnEl.querySelector('span');
				if (span) span.textContent = 'Notified';
			} else {
				btnEl.disabled = false;
				alert((r && r.data) ? r.data : 'Could not send notification.');
			}
		});
	}

	function deleteMessage(messageId, rowEl) {
		/* Honest wording: this hides the message, it does not erase it.
		   Shatania keeps the full conversation in WordPress. */
		if (!confirm('Delete this message?\n\nIt disappears from the chat for both of you.')) return;
		$.post(schatAjax.ajaxurl, { action: 'schat_delete_message', nonce: schatAjax.nonce, message_id: messageId }, function (r) {
			if (r && r.success) {
				var memberId = rowEl.closest('.schat-overlay').getAttribute('data-member-id');
				removeMessageRow(memberId, messageId);
			} else {
				alert((r && r.data) ? r.data : 'Could not delete message.');
			}
		});
	}

	/**
	 * Take a message off the screen, leaving nothing behind.
	 *
	 * Deliberately silent — no "this message was deleted" placeholder. The
	 * other person simply stops seeing it, which is the whole point.
	 */
	function removeMessageRow(memberId, messageId) {
		var body = document.getElementById('schat-body-' + memberId);
		if (!body) return false;

		var row = body.querySelector('.schat-row[data-message-id="' + messageId + '"]');
		if (!row) {
			var inner = body.querySelector('[data-message-id="' + messageId + '"]');
			row = inner && inner.closest ? inner.closest('.schat-row') : null;
		}
		if (!row) return false;

		var st = getState(memberId);
		var atBottom = schatAtBottom(body);

		// A "Seen" receipt pinned to this row would be orphaned by its removal.
		var seen = row.nextElementSibling;
		if (seen && seen.classList && seen.classList.contains('schat-seen')) seen.remove();
		// Same for a reply bar quoting it, or an edit in progress on it.
		if (parseInt(st.replyTo, 10) === parseInt(messageId, 10)) setReplyTarget(memberId, 0, '', '');
		if (parseInt(st.editing, 10) === parseInt(messageId, 10)) {
			st.editing = 0;
			var eb = document.getElementById('schat-edit-bar-' + memberId);
			if (eb) eb.classList.remove('show');
		}

		row.remove();
		// Force the next applySeen to repaint: the receipt it was tracking
		// may have just left the DOM with this row.
		st.lastSeenId = -1;

		if (!body.querySelector('.schat-row')) {
			showEmptyState(memberId, schatAjax.is_admin == 1);
		} else if (atBottom) {
			scrollToBottom(memberId, true);
		}
		return true;
	}

	/* Drops any rendered message the server now reports as deleted. Runs on
	   every poll, so a deletion by either side reaches the other within one
	   poll interval without a reload. */
	function syncDeletions(memberId, ids) {
		if (!ids || !ids.length) return;
		for (var i = 0; i < ids.length; i++) {
			removeMessageRow(memberId, parseInt(ids[i], 10));
		}
	}

	/* ============================================================
	   Event delegation — attached to document, so it works
	   regardless of when/how this script loads relative to the
	   DOM, and covers panels added to the page after this script runs.
	   ============================================================ */
	/* The chat button now lives inside .shat-ig-header, which has
	   overflow:hidden and an animation — both break position:fixed for
	   descendants, so the overlays would be clipped/mispositioned. Move the
	   overlays (NOT the button) to <body> once, on load. Behaviour, IDs,
	   classes and handlers are all unchanged; only the DOM parent moves. */
	function schatDetachOverlays() {
		if (!document.body) return;
		var moved = document.querySelector('body > .schat-detached-overlays');
		if (!moved) {
			moved = document.createElement('div');
			moved.className = 'schat-root schat-detached-overlays';
			document.body.appendChild(moved);
		}
		document.querySelectorAll('.schat-overlay, .schat-inbox-overlay').forEach(function (ov) {
			if (ov.parentNode !== moved) moved.appendChild(ov);
		});
	}
	schatDetachOverlays();
	$(document).ready(schatDetachOverlays);

	$(document).on('click', '.schat-fab:not(.schat-admin-fab)', function () {
		// Overlays are re-parented to <body>, so closest('.schat-root') no
		// longer contains them. Prefer the member id the server rendered.
		var mid = this.getAttribute('data-member-id');
		if (!mid) {
			var root = this.closest('.schat-root');
			var ov = (root && root.querySelector('.schat-overlay'))
				|| document.querySelector('.schat-overlay');
			mid = ov && ov.getAttribute('data-member-id');
		}
		if (mid) openChat(mid, schatAjax.is_admin == 1);
	});

	$(document).on('click', '#schat-admin-fab-btn', function () {
		var overlay = document.getElementById('schat-inbox-overlay');
		if (overlay) {
			overlay.classList.add('open');
			document.body.classList.add('schat-locked');
			schatSyncViewport();
		}
	});
	$(document).on('click', '#schat-inbox-close', function () {
		var overlay = document.getElementById('schat-inbox-overlay');
		if (overlay) overlay.classList.remove('open');
		document.body.classList.remove('schat-locked');
	});
	$(document).on('click', '#schat-inbox-overlay', function (e) {
		if (e.target.id === 'schat-inbox-overlay') {
			e.target.classList.remove('open');
			document.body.classList.remove('schat-locked');
		}
	});
	$(document).on('click', '.schat-inbox-row', function () {
		var memberId = this.getAttribute('data-member-id');
		var inboxOverlay = document.getElementById('schat-inbox-overlay');
		if (inboxOverlay) inboxOverlay.classList.remove('open');
		var ov = findOverlay(memberId);
		// Opened FROM the inbox, so offer a way back to it.
		if (ov) ov.classList.add('schat-has-back');
		openChat(memberId, true);
	});

	// Back → close this thread and reopen the inbox list.
	$(document).on('click', '.schat-back-btn', function () {
		var overlay = this.closest('.schat-overlay');
		if (!overlay) return;
		var memberId = overlay.getAttribute('data-member-id');
		closeChat(memberId);
		overlay.classList.remove('schat-has-back');
		var inbox = document.getElementById('schat-inbox-overlay');
		if (inbox) {
			inbox.classList.add('open');
			document.body.classList.add('schat-locked');
			document.body.style.overflow = 'hidden';
		}
		pollGlobalState();
	});

	$(document).on('click', '.schat-close-btn', function () {
		var overlay = this.closest('.schat-overlay');
		if (!overlay) return;
		if (overlay.classList.contains('schat-standalone-mode')) {
			window.history.back();
		} else {
			closeChat(overlay.getAttribute('data-member-id'));
		}
	});

	$(document).on('click', '.schat-overlay', function (e) {
		if (e.target === this && !this.classList.contains('schat-standalone-mode')) {
			closeChat(this.getAttribute('data-member-id'));
		}
	});

	/* Telegram-style auto-grow: the composer expands line by line as you
	   type, up to a hard cap of 5 lines, then stops growing. Past that the
	   text scrolls internally. The scrollbar is hidden in CSS, but touch and
	   wheel scrolling stay enabled so long drafts remain readable.

	   The cap is derived from the textarea's OWN computed line-height +
	   vertical padding + border (box-sizing:border-box), so it stays correct
	   across fonts and zoom rather than relying on a magic pixel number. */
	var SCHAT_MAX_LINES = 5;
	function schatMaxHeight(ta) {
		var cs = window.getComputedStyle(ta);
		var lh = parseFloat(cs.lineHeight);
		if (!lh || isNaN(lh)) lh = (parseFloat(cs.fontSize) || 14.5) * 1.4;
		var padV = (parseFloat(cs.paddingTop) || 0) + (parseFloat(cs.paddingBottom) || 0);
		var borderV = (parseFloat(cs.borderTopWidth) || 0) + (parseFloat(cs.borderBottomWidth) || 0);
		// border-box: the height we set already includes padding + border.
		return Math.ceil(lh * SCHAT_MAX_LINES + padV + borderV);
	}
	/* The composer's height MUST be set as an important inline value.
	   `.schat-root textarea` carries `all:unset !important` as part of the
	   theme-proofing reset at the top of the stylesheet, and `all` includes
	   `height` — an author !important declaration outranks a plain inline
	   style, so a normal `ta.style.height = …` was being thrown away and the
	   box stayed locked at one row however much was typed. An important
	   inline declaration is the one thing that outranks it. */
	function schatSetHeight(ta, value) {
		ta.style.setProperty('height', value, 'important');
	}

	function schatAutoGrow(ta) {
		if (!ta) return;
		var max = schatMaxHeight(ta);
		// Collapse first so scrollHeight reflects the true content height,
		// not the previously-set (possibly taller) height.
		schatSetHeight(ta, 'auto');
		var full = ta.scrollHeight; // border-box → includes padding
		var h = Math.min(full, max);
		schatSetHeight(ta, h + 'px');
		// Only allow internal scrolling once we've actually hit the cap.
		var overflowing = full > max + 1;
		ta.classList.toggle('schat-scrollable', overflowing);
		// Direct fallback in case the class rule is overridden somewhere.
		ta.style.overflowY = overflowing ? 'auto' : 'hidden';
	}

	$(document).on('input', '.schat-input-row textarea', function () {
		schatAutoGrow(this);
		var overlay = this.closest('.schat-overlay');
		if (overlay) {
			var mid = overlay.getAttribute('data-member-id');
			updateSendState(mid);
			if ((this.value || '').trim().length > 0) pingTyping(mid);
			else stopTyping(mid);
		}
	});

	/* Enter sends the message; Shift+Enter inserts a new line. Escape
	   cancels an in-progress edit. */
	$(document).on('keydown', '.schat-input-row textarea', function (e) {
		var overlay = this.closest('.schat-overlay');
		if (!overlay) return;
		var mid = overlay.getAttribute('data-member-id');
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			sendMessage(mid, schatAjax.is_admin == 1);
			return;
		}
		if (e.key === 'Escape') {
			var st = getState(mid);
			if (st.editing) { e.preventDefault(); clearEdit(mid); }
		}
	});

	/* Stop the Send button from taking focus away from the textarea. Without
	   this, tapping Send blurs the composer and Android drops the keyboard.
	   preventDefault on the press keeps focus where it is; the click still
	   fires normally afterwards. */
	$(document).on('mousedown touchstart', '.schat-send-btn', function (e) {
		e.preventDefault();
	});
	$(document).on('click', '.schat-send-btn', function () {
		var overlay = this.closest('.schat-overlay');
		if (!overlay) return;
		var mid = overlay.getAttribute('data-member-id');
		sendMessage(mid, schatAjax.is_admin == 1);
		// Belt-and-braces: some WebViews blur on the synthetic click anyway.
		var ta = overlay.querySelector('.schat-input-row textarea');
		if (ta) { try { ta.focus({ preventScroll: true }); } catch (e) { try { ta.focus(); } catch (e2) {} } }
	});

	/* NOTE: the photo button must NOT preventDefault on touchstart. Doing so
	   cancels the synthetic click that follows on mobile, so the file picker
	   never opens. Opening a picker naturally takes focus anyway, which is
	   expected here — so we just let the tap through to the click handler. */
	$(document).on('click', '.schat-photo-btn', function () {
		var overlay = this.closest('.schat-overlay');
		var fileInput = overlay && overlay.querySelector('.schat-file-input');
		if (fileInput) fileInput.click();
	});

	$(document).on('change', '.schat-file-input', function () {
		var overlay = this.closest('.schat-overlay');
		if (!overlay) return;
		var memberId = overlay.getAttribute('data-member-id');
		var st = getState(memberId);
		var picked = Array.prototype.slice.call(this.files || []);
		if (!picked.length) return;

		/* Multi-select: the first photo rides with whatever is typed, the
		   rest queue up and go out on their own straight after. Capped so a
		   stray "select all" in the gallery can't fire off fifty uploads. */
		var MAX_PHOTOS = 6;
		/* Whichever is smaller: our own rule, or what PHP will physically
		   accept. Compression usually brings photos far under both, but a
		   PNG screenshot or a already-small file skips compression. */
		var limitMb = Math.min(
			schatAjax.max_file_mb || 8,
			schatAjax.php_upload_mb || schatAjax.max_file_mb || 8
		);
		var tooBig = [];
		var ok = [];

		picked.forEach(function (file) {
			if (file.size > limitMb * 1024 * 1024) tooBig.push(file.name);
			else if (ok.length < MAX_PHOTOS) ok.push(file);
		});

		if (tooBig.length) {
			alert((tooBig.length === 1 ? 'This photo is' : tooBig.length + ' photos are') +
			      ' over ' + limitMb + 'MB and will be skipped.');
		}
		if (!ok.length) { this.value = ''; return; }

		var f = ok[0];
		st.pendingFile  = f;
		st.pendingQueue = ok.slice(1);
		var attachThumb = document.getElementById('schat-attach-thumb-' + memberId);
		var attachName = document.getElementById('schat-attach-name-' + memberId);
		var attachPrev = document.getElementById('schat-attach-preview-' + memberId);
		var reader = new FileReader();
		reader.onload = function (e) {
			if (attachThumb) attachThumb.src = e.target.result;
			if (attachName) {
				var extra = st.pendingQueue ? st.pendingQueue.length : 0;
				attachName.textContent = extra ? (f.name + '  +' + extra + ' more') : f.name;
			}
			if (attachPrev) attachPrev.classList.add('show');
			updateSendState(memberId);
		};
		reader.readAsDataURL(f);
	});

	$(document).on('click', '.schat-attach-remove', function () {
		var overlay = this.closest('.schat-overlay');
		if (!overlay) return;
		var memberId = overlay.getAttribute('data-member-id');
		var st = getState(memberId);
		var fileInput = overlay.querySelector('.schat-file-input');
		var attachPrev = document.getElementById('schat-attach-preview-' + memberId);
		st.pendingFile = null;
		st.pendingQueue = [];
		if (fileInput) fileInput.value = '';
		if (attachPrev) attachPrev.classList.remove('show');
		updateSendState(memberId);
	});

	/* Touch double-tap. Mobile browsers fire `dblclick` inconsistently (and
	   not at all once a touch handler calls preventDefault upstream), so the
	   double-tap is detected directly from touchend. 320ms window, and the
	   two taps must land within ~28px of each other so a quick scroll-flick
	   is never mistaken for a reaction. */
	$(document).on('touchend', '.schat-js-lovable', function (e) {
		var row = this;
		var t   = (e.originalEvent && e.originalEvent.changedTouches && e.originalEvent.changedTouches[0]) || null;
		var now = Date.now();
		var x   = t ? t.clientX : 0;
		var y   = t ? t.clientY : 0;

		if (row._lastTapAt && (now - row._lastTapAt) < 320 &&
		    Math.abs(x - row._lastTapX) < 28 && Math.abs(y - row._lastTapY) < 28) {
			row._lastTapAt = 0;
			if (row.classList.contains('schat-swiping') || row.classList.contains('schat-pressing')) return;
			var mid = row.getAttribute('data-message-id');
			if (mid) toggleLove(mid, row);
			return;
		}

		row._lastTapAt = now;
		row._lastTapX  = x;
		row._lastTapY  = y;
	});

	$(document).on('dblclick', '.schat-js-lovable', function () {
		/* On a phone this fires IN ADDITION to the touchend detector above,
		   for the very same double-tap. Acting on both toggled the heart on
		   and straight back off, which is why a like never stuck. Same
		   ghost-gap rule the photo viewer uses: ignore a dblclick that
		   follows a real touch, keep it for actual mice. */
		if (Date.now() - schatLastTouchAt < GHOST_GAP) return;
		var messageId = this.getAttribute('data-message-id');
		toggleLove(messageId, this);
	});

	$(document).on('click', '.schat-js-notify', function () {
		var messageId = this.getAttribute('data-message-id');
		notifyMember(messageId, this);
	});

	$(document).on('click', '.schat-js-delete', function () {
		var messageId = this.getAttribute('data-message-id');
		deleteMessage(messageId, this.closest('.schat-row'));
	});

	/* Standalone panels (dedicated full-page chat) open themselves
	   immediately on page load rather than waiting for a FAB click. */
	$(function () {
		document.querySelectorAll('.schat-overlay.schat-standalone-mode').forEach(function (overlay) {
			var memberId = overlay.getAttribute('data-member-id');
			overlay.classList.add('open');
			loadInitial(memberId, schatAjax.is_admin == 1);
			bindBackfill(memberId, schatAjax.is_admin == 1);
			startPolling(memberId, schatAjax.is_admin == 1);
		});
	});

	/* ------------------------------------------------------------------
	   Opening the chat from a push notification.

	   Two ways in, so the Android side can use whichever is easier:

	   1. URL — load the feed with ?schat=open (or #schat). The push
	      payload already carries this as data.click_url, so the app can
	      simply load that URL on tap and nothing else is needed.

	   2. Bridge — call window.schatOpenChat() from Kotlin/Java via
	      webView.evaluateJavascript(...). Use this when the app keeps the
	      page alive and does not want to reload it.

	   Both funnel through the same routine, which waits for the panel to
	   exist before acting (the notification can arrive before the DOM is
	   ready) and then strips the query string so a later refresh does not
	   reopen the chat unexpectedly.
	   ------------------------------------------------------------------ */
	/* wantedMember is optional. Admins have one panel per thread, so a
	   notification has to say whose. Members only ever have their own and
	   can pass nothing. */
	function schatOpenChat(wantedMember, tries) {
		tries = tries || 0;

		var isAdmin = schatAjax.is_admin == 1;

		if (wantedMember === undefined || wantedMember === null) {
			wantedMember = schatQueryParam('member');
		}

		// Standalone page: the thread is already the whole screen.
		var standalone = document.querySelector('.schat-overlay.schat-standalone-mode');
		if (standalone) { schatStripOpenParam(); return true; }

		var overlay = wantedMember
			? document.querySelector('.schat-overlay[data-member-id="' + wantedMember + '"]')
			: document.querySelector('.schat-overlay[data-member-id]');

		if (!overlay) {
			// Panel markup may not be in the DOM yet — retry briefly.
			if (tries < 20) {
				setTimeout(function () { schatOpenChat(wantedMember, tries + 1); }, 150);
				return false;
			}
			/* Asked for a thread this page never rendered (an older member
			   who has scrolled out of the inbox list). Fall back to opening
			   the inbox so the tap still lands somewhere useful. */
			var inbox = document.getElementById('schat-inbox-overlay');
			if (isAdmin && inbox) {
				inbox.classList.add('open');
				document.body.classList.add('schat-locked');
			}
			schatStripOpenParam();
			return false;
		}

		var memberId = overlay.getAttribute('data-member-id');

		// Coming in from a notification is the same as coming in from the
		// inbox, so give her the back arrow to the thread list.
		if (isAdmin) overlay.classList.add('schat-has-back');

		var openInbox = document.getElementById('schat-inbox-overlay');
		if (openInbox) openInbox.classList.remove('open');

		if (!overlay.classList.contains('open')) openChat(memberId, isAdmin);
		schatStripOpenParam();
		return true;
	}

	function schatQueryParam(name) {
		try {
			return new URL(window.location.href).searchParams.get(name);
		} catch (e) { return null; }
	}

	function schatStripOpenParam() {
		try {
			if (!window.history || !history.replaceState) return;
			var u = new URL(window.location.href);
			if (!u.searchParams.has('schat') && u.hash !== '#schat') return;
			u.searchParams.delete('schat');
			u.searchParams.delete('member');
			var clean = u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : '');
			history.replaceState(null, '', clean);
		} catch (e) {}
	}

	// Exposed for the Android app's evaluateJavascript() call.
	window.schatOpenChat = schatOpenChat;

	$(function () {
		var qs = window.location.search || '';
		var wantsChat = /[?&]schat=open/.test(qs) || window.location.hash === '#schat';
		if (wantsChat) schatOpenChat(schatQueryParam('member'));
	});

})(jQuery);
SCHATJS;
}



/* ============================================================
   SHARED PANEL SHELL
   $member_id — whose thread this is.
   $standalone — true renders inline (no dimmed overlay), for
   embedding directly on a page rather than as a popup.

   Rules baked in here:
   - Anyone approved can send the FIRST message — no lock state.
   - Members can never delete a message they sent (no delete UI for them).
   - Admin gets a delete icon on every message (soft-delete).
   - Admin gets a manual "Notify by email" button per admin-sent message,
     instead of auto-emailing on every admin reply.
   - Member-sent messages ALWAYS auto-email the admin.
   ============================================================ */
function schat_render_panel_shell( $member_id, $standalone = false, $skip_own_root = false ) {
	$member = get_user_by( 'id', $member_id );
	if ( ! $member ) return;

	$is_admin_viewer = schat_is_site_admin();
	$avatar          = defined( 'SHAT_PROFILE_PIC' ) ? SHAT_PROFILE_PIC : get_option( 'shat_profile_pic', '' );
	$header_name     = $is_admin_viewer ? esc_html( $member->display_name ) : 'Shatania';
	$header_sub      = $is_admin_viewer ? esc_html( $member->user_email ) : 'Usually replies within a day';
	// When $skip_own_root is true, the caller already opened a .schat-root
	// wrapper around both the trigger button AND this panel (so that
	// closest('.schat-root') + querySelector('.schat-overlay') in the JS
	// finds this exact overlay) — so we don't open a second, separate one.
	if ( ! $skip_own_root ) : ?>
	<div class="schat-root">
	<?php endif; ?>
		<div class="schat-overlay <?php echo $standalone ? 'schat-standalone-mode' : ''; ?>" id="schat-overlay-<?php echo esc_attr( $member_id ); ?>" data-member-id="<?php echo esc_attr( $member_id ); ?>">
			<div class="schat-panel">
				<div class="schat-header">
					<button type="button" class="schat-back-btn" aria-label="Back to conversations">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
					</button>
					<?php if ( ! $is_admin_viewer && $avatar ) : ?>
						<img src="<?php echo esc_url( $avatar ); ?>" alt="Shatania">
					<?php endif; ?>
					<div class="schat-h-info">
						<div class="schat-h-name"><?php echo $header_name; ?></div>
						<?php /* Presence line. Starts on the old static subtitle and the
						         dot stays hidden until the first poll tells us what the
						         other side is actually doing — no misleading flash. */ ?>
						<div class="schat-h-sub schat-pres schat-pres-unknown" id="schat-pres-<?php echo esc_attr( $member_id ); ?>">
							<span class="schat-pres-dot" aria-hidden="true"></span>
							<span class="schat-pres-txt"><?php echo $header_sub; ?></span>
						</div>
					</div>
					<?php if ( ! $standalone ) : ?>
					<div class="schat-h-actions">
						<button type="button" class="schat-close-btn" aria-label="Close chat" title="Close">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
						</button>
					</div>
					<?php endif; ?>
				</div>

				<div class="schat-body" id="schat-body-<?php echo esc_attr( $member_id ); ?>">
					<!-- messages render here -->
				</div>

				<div class="schat-typing" id="schat-typing-<?php echo esc_attr( $member_id ); ?>">
					<span class="schat-typing-bubble"><i></i><i></i><i></i></span>
					<span class="schat-typing-text" id="schat-typing-text-<?php echo esc_attr( $member_id ); ?>"></span>
				</div>

				<div class="schat-footer">
					<div class="schat-reply-bar" id="schat-reply-bar-<?php echo esc_attr( $member_id ); ?>">
						<div class="schat-reply-info">
							<div class="schat-reply-label" id="schat-reply-label-<?php echo esc_attr( $member_id ); ?>">Replying to</div>
							<div class="schat-reply-text" id="schat-reply-text-<?php echo esc_attr( $member_id ); ?>"></div>
						</div>
						<button type="button" class="schat-reply-cancel" aria-label="Cancel reply">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
						</button>
					</div>
					<div class="schat-edit-bar" id="schat-edit-bar-<?php echo esc_attr( $member_id ); ?>">
						<div class="schat-edit-info">
							<div class="schat-edit-label">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
								<span>Editing message</span>
							</div>
						</div>
						<button type="button" class="schat-edit-cancel" aria-label="Cancel edit">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
						</button>
					</div>
					<div class="schat-attach-preview" id="schat-attach-preview-<?php echo esc_attr( $member_id ); ?>">
						<img id="schat-attach-thumb-<?php echo esc_attr( $member_id ); ?>" alt="">
						<span class="schat-attach-name" id="schat-attach-name-<?php echo esc_attr( $member_id ); ?>"></span>
						<button type="button" class="schat-attach-remove" aria-label="Remove photo">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
						</button>
					</div>
					<div class="schat-input-row">
						<input type="file" class="schat-file-input" accept="image/*" multiple style="display:none;">
						<button type="button" class="schat-icon-btn schat-photo-btn" aria-label="Add photo">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg>
						</button>
						<?php /* A centred sparkle. The earlier party-popper cone hung its
						         visual weight in the bottom-left of the viewBox, so next to
						         the evenly-filled photo icon it read as smaller and lower
						         even though both boxes were the same size. */ ?>
						<button type="button" class="schat-icon-btn schat-party-btn" aria-label="Send a celebration" title="Celebrate">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.8l2.05 5.6a3 3 0 0 0 1.55 1.55l5.6 2.05-5.6 2.05a3 3 0 0 0-1.55 1.55L12 21.2l-2.05-5.6a3 3 0 0 0-1.55-1.55L2.8 12l5.6-2.05A3 3 0 0 0 9.95 8.4z"/><path d="M19 3.2v2.4"/><path d="M20.2 4.4h-2.4"/><path d="M5 18.4v2"/><path d="M6 19.4H4"/></svg>
						</button>
						<textarea rows="1" placeholder="Message&hellip;" maxlength="2000"></textarea>
						<button type="button" class="schat-icon-btn schat-send-btn" disabled aria-label="Send">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
						</button>
					</div>
				</div>
			</div>
		</div>
	<?php if ( ! $skip_own_root ) : ?>
	</div>
	<?php endif; ?>
	<?php
}

/* ============================================================
   SHORTCODE: [shatania_chat_button]
   Floating chat launcher for the profile/feed page. Works for
   BOTH regular members and for Shatania herself when she's
   viewing her own feed logged in as admin — in the admin case
   it opens straight into whichever thread she last had open,
   or the inbox if none, via a small picker.
   ============================================================ */
add_shortcode( 'shatania_chat_button', 'schat_sc_button' );
function schat_sc_button() {
	if ( ! is_user_logged_in() ) return '';

	$uid = get_current_user_id();

	if ( schat_is_site_admin( $uid ) ) {
		return schat_render_admin_front_widget();
	}

	if ( ! schat_can_chat( $uid ) ) return '';

	$unread = schat_unread_count_for_member( $uid );

	ob_start();
	echo schat_inline_assets();
	?>
	<div class="schat-root">
		<button type="button" class="schat-fab schat-fab-pill schat-fab-icon" id="schat-fab-btn" data-member-id="<?php echo esc_attr( $uid ); ?>" title="Message Shatania" aria-label="Message Shatania">
			<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
			<?php if ( $unread > 0 ) : ?>
				<span class="schat-fab-badge" id="schat-fab-badge"><?php echo $unread > 9 ? '9+' : $unread; ?></span>
			<?php endif; ?>
		</button>
		<?php schat_render_panel_shell( $uid, false, true ); ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Admin front-end widget: a floating button that opens a lightweight
 * inbox list (all threads with unread badges); tapping a thread opens
 * that member's panel. This is what lets Shatania chat from the
 * front-end profile page while logged in as admin, without needing
 * wp-admin.
 */
function schat_render_admin_front_widget() {
	$threads = schat_get_all_threads();
	$total_unread = 0;
	foreach ( $threads as $t ) { $total_unread += $t['unread']; }

	ob_start();
	echo schat_inline_assets();
	?>
	<div class="schat-root">
		<button class="schat-fab schat-admin-fab schat-fab-icon" id="schat-admin-fab-btn" title="Messages" aria-label="Open messages">
			<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
			<?php if ( $total_unread > 0 ) : ?>
				<span class="schat-fab-badge"><?php echo $total_unread > 9 ? '9+' : $total_unread; ?></span>
			<?php endif; ?>
		</button>

		<div class="schat-inbox-overlay" id="schat-inbox-overlay">
			<div class="schat-inbox-panel">
				<div class="schat-inbox-head">
					<h3>Messages</h3>
					<button type="button" id="schat-inbox-close"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
				</div>
				<div class="schat-inbox-list" id="schat-inbox-list">
					<?php if ( empty( $threads ) ) : ?>
						<div class="schat-inbox-empty">No conversations yet. Members can message you any time — this list fills in once someone writes.</div>
					<?php else : foreach ( $threads as $t ) :
						$preview = '';
						if ( $t['last_msg'] ) {
							$preview = $t['last_msg']->message !== '' ? wp_trim_words( $t['last_msg']->message, 8, '…' ) : '📷 Photo';
							if ( intval( $t['last_msg']->sender_is_admin ) === 1 ) $preview = 'You: ' . $preview;
						}
					?>
						<div class="schat-inbox-row" data-member-id="<?php echo esc_attr( $t['member_id'] ); ?>">
							<div style="flex:1;min-width:0;">
								<div class="schat-inbox-name"><?php echo esc_html( $t['name'] ); ?></div>
								<div class="schat-inbox-preview"><?php echo esc_html( $preview ); ?></div>
							</div>
							<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
								<span class="schat-inbox-time"><?php echo esc_html( schat_time_label( $t['last_at'] ) ); ?></span>
								<?php if ( $t['unread'] > 0 ) : ?><span class="schat-inbox-badge"><?php echo intval( $t['unread'] ); ?></span><?php endif; ?>
							</div>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</div>
		</div>
	</div>

	<?php foreach ( $threads as $t ) : schat_render_panel_shell( $t['member_id'], false ); endforeach; ?>
	<?php
	return ob_get_clean();
}

/* ============================================================
   AJAX: send message
   ============================================================ */
add_action( 'wp_ajax_schat_send_message', 'schat_ajax_send_message' );
function schat_ajax_send_message() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	global $wpdb;
	$current_uid = get_current_user_id();
	$is_admin    = schat_is_site_admin();
	$member_id   = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;

	if ( ! $member_id ) wp_send_json_error( 'Missing conversation.' );

	if ( ! $is_admin ) {
		if ( ! schat_can_chat( $current_uid ) ) wp_send_json_error( 'Not authorized.', 403 );
		$member_id = $current_uid; // members can never target anyone else
	} else {
		$member = get_user_by( 'id', $member_id );
		if ( ! $member ) wp_send_json_error( 'Member not found.' );
	}

	$message = isset( $_POST['message'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ) : '';

	$image_url = '';
	if ( ! empty( $_FILES['photo'] ) ) {
		$result = schat_handle_photo_upload( 'photo' );
		if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
		$image_url = $result;
	}

	if ( $message === '' && $image_url === '' ) wp_send_json_error( 'Message is empty.' );

	// Validate the reply target actually belongs to this thread.
	$reply_to = isset( $_POST['reply_to'] ) ? intval( $_POST['reply_to'] ) : 0;
	if ( $reply_to ) {
		$ok = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}schat_messages WHERE id = %d AND member_id = %d AND is_deleted = 0",
			$reply_to, $member_id
		) );
		if ( ! $ok ) $reply_to = 0;
	}

	$wpdb->insert( "{$wpdb->prefix}schat_messages", [
		'member_id'       => $member_id,
		'sender_is_admin' => $is_admin ? 1 : 0,
		'message'         => $message,
		'image_url'       => $image_url,
		'reply_to'        => $reply_to ?: null,
		// Stored as true UTC (gmdate), NOT current_time('mysql') which is
		// the site's local timezone. The client appends 'Z' and converts
		// to each viewer's own zone, so IST and PDT users each see their
		// own local time for the same message.
		'sent_at'         => gmdate( 'Y-m-d H:i:s' ),
	] );
	$new_id = $wpdb->insert_id;

	// Sending implies you've stopped typing — but you're very much here.
	schat_clear_typing( $member_id, $is_admin );
	schat_set_activity( $member_id, $is_admin, 'viewing' );

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}schat_messages WHERE id = %d", $new_id ) );

	if ( $row && ! empty( $row->reply_to ) ) {
		$p = $wpdb->get_row( $wpdb->prepare(
			"SELECT message, image_url, sender_is_admin FROM {$wpdb->prefix}schat_messages WHERE id = %d",
			intval( $row->reply_to )
		) );
		if ( $p ) {
			$row->reply_snippet = [
				'text'      => $p->message !== '' ? wp_trim_words( $p->message, 12, '…' ) : ( $p->image_url ? 'Photo' : '' ),
				'was_admin' => intval( $p->sender_is_admin ),
			];
		}
	}

	/* Member → admin messages used to always auto-email Shatania. As of
	   2.5.5 that is off by default: new messages show up in the admin Chat
	   page (and as a push, if Push Setup is configured), so the inbox stays
	   quiet. Re-enable it on Shatania -> App Download if you ever want the
	   emails back.
	   Admin → member messages still never auto-email; she uses "Notify". */
	if ( ! $is_admin && schat_admin_email_enabled() ) {
		$member = get_user_by( 'id', $member_id );
		schat_notify_admin_new_message( $member, $message, $image_url !== '' );
	}

	// Push notification — goes to whichever side did NOT just send this
	// message. schat_fcm_send_to_user() itself skips silently if that
	// person is currently looking at the thread, or has no device token.
	$push_preview = $image_url !== ''
		? ( $message !== '' ? wp_trim_words( $message, 12, '…' ) . ' 📷' : '📷 Sent a photo' )
		: wp_trim_words( $message, 12, '…' );

	if ( $is_admin ) {
		// Shatania sent it -> push to the member.
		schat_fcm_send_to_user( $member_id, 'Shatania', $push_preview, [
			'type'      => 'chat',
			'click_url' => schat_chat_deeplink(),
			'member_id' => $member_id,
		] );
	} else {
		// A member sent it -> push to Shatania's own device(s), if she
		// ever logs into the app with her admin account. If she only
		// ever uses the WP dashboard, no token exists and this is a no-op.
		$admin_id = 0;
		$admins   = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ] );
		if ( ! empty( $admins ) ) $admin_id = intval( $admins[0] );
		if ( $admin_id ) {
			$sender      = get_user_by( 'id', $current_uid );
			$sender_name = $sender ? $sender->display_name : 'A member';
			/* Front-end deep link rather than wp-admin: the push lands on
			   her phone, where the app's WebView shows the member area, not
			   the dashboard. The member id opens that person's thread. */
			schat_fcm_send_to_user( $admin_id, $sender_name, $push_preview, [
				'type'      => 'chat',
				'click_url' => schat_chat_deeplink( $member_id ),
				'member_id' => $member_id,
			] );
		}
	}

	wp_send_json_success( [ 'message' => $row ] );
}

/* ============================================================
   AJAX: fetch messages (initial load + polling)
   ============================================================ */
add_action( 'wp_ajax_schat_fetch_messages', 'schat_ajax_fetch_messages' );
function schat_ajax_fetch_messages() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	global $wpdb;
	$current_uid = get_current_user_id();
	$is_admin    = schat_is_site_admin();
	$member_id   = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
	$after_id    = isset( $_POST['after_id'] ) ? intval( $_POST['after_id'] ) : 0;
	/* Set when the client scrolls up and wants the batch older than the
	   oldest row it already has. Mutually exclusive with after_id. */
	$before_id   = isset( $_POST['before_id'] ) ? intval( $_POST['before_id'] ) : 0;

	if ( ! $is_admin ) $member_id = $current_uid;
	if ( ! $member_id ) wp_send_json_error( 'Missing conversation.' );

	$rows = schat_get_thread( $member_id, $after_id, 0, $before_id );

	/* Whether anything older still exists, so the client knows when to
	   stop asking. Based on the oldest row in THIS batch; if the batch is
	   empty we've already hit the top. */
	$oldest_id  = ! empty( $rows ) ? intval( $rows[0]->id ) : 0;
	$has_older  = $oldest_id ? schat_has_older( $member_id, $oldest_id ) : false;

	// Hydrate reply previews so the client can render quoted bubbles.
	foreach ( $rows as $r ) {
		$r->reply_snippet = null;
		if ( ! empty( $r->reply_to ) ) {
			$p = $wpdb->get_row( $wpdb->prepare(
				"SELECT message, image_url, sender_is_admin FROM {$wpdb->prefix}schat_messages WHERE id = %d",
				intval( $r->reply_to )
			) );
			if ( $p ) {
				$r->reply_snippet = [
					'text'      => $p->message !== '' ? wp_trim_words( $p->message, 12, '…' ) : ( $p->image_url ? 'Photo' : '' ),
					'was_admin' => intval( $p->sender_is_admin ),
				];
			}
		}
	}

	/* Reaction sync. The live poll only asks for messages NEWER than
	   after_id, so a heart added to an older message would never reach the
	   other side. Ship the loved set for the recent slice of the thread on
	   every poll; the client diffs it and animates any new arrivals. */
	$loved_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}schat_messages
		  WHERE member_id = %d AND loved = 1 AND is_deleted = 0
		  ORDER BY id DESC LIMIT 200",
		$member_id
	) );

	$schat_act = schat_other_activity( $member_id, $is_admin );

	/* Deletion sync. Same problem the reaction sync above solves: the live
	   poll only asks for messages NEWER than after_id, so a message deleted
	   after the other side rendered it would sit there until they reloaded.
	   Ship the recently-deleted ids and let the client drop those bubbles —
	   silently, with nothing left in their place. */
	$deleted_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}schat_messages
		  WHERE member_id = %d AND is_deleted = 1
		  ORDER BY id DESC LIMIT 200",
		$member_id
	) );

	wp_send_json_success( [
		'messages'     => $rows,
		'loved_ids'    => array_map( 'intval', (array) $loved_ids ),
		'deleted_ids'  => array_map( 'intval', (array) $deleted_ids ),
		'has_older'    => $has_older,
		'is_backfill'  => $before_id > 0,
		'typing'       => schat_is_other_typing( $member_id, $is_admin ),
		// Live presence for the header line: typing / scrolling / viewing / away.
		'activity'     => $schat_act['state'],
		'activity_ago' => $schat_act['ago'],
		// Ephemeral confetti relay — null when no party is pending.
		'celebrate'    => schat_get_celebration( $member_id ),
		'last_seen_id' => schat_last_seen_id( $member_id, $is_admin ),
		// Computed here, before the client calls mark_read. Only meaningful
		// on the initial load (after_id = 0); during live polling new
		// messages arrive while the thread is already open, so they don't
		// need a divider. A back-page (before_id) is also not an initial
		// load — recomputing there would redraw the divider mid-history.
		'first_unread_id' => ( $after_id === 0 && $before_id === 0 ) ? schat_first_unread_id( $member_id, $is_admin ) : 0,
		'unread_total' => $is_admin ? schat_unread_count_for_admin() : schat_unread_count_for_member( $member_id ),
	] );
}

/* ============================================================
   AJAX: lightweight presence/badge poll — used when no panel is
   open, so the launcher badge updates live without a reload.
   ============================================================ */
add_action( 'wp_ajax_schat_poll_state', 'schat_ajax_poll_state' );
function schat_ajax_poll_state() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	$current_uid = get_current_user_id();
	$is_admin    = schat_is_site_admin();

	if ( $is_admin ) {
		$threads = schat_get_all_threads();
		$out = [];
		foreach ( $threads as $t ) {
			$out[] = [
				'member_id' => intval( $t['member_id'] ),
				'unread'    => intval( $t['unread'] ),
			];
		}
		wp_send_json_success( [
			'unread_total' => schat_unread_count_for_admin(),
			'threads'      => $out,
		] );
	}

	wp_send_json_success( [
		'unread_total' => schat_unread_count_for_member( $current_uid ),
		'threads'      => [],
	] );
}

/* ============================================================
   AJAX: typing ping
   ============================================================ */
add_action( 'wp_ajax_schat_typing', 'schat_ajax_typing' );
function schat_ajax_typing() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	$current_uid = get_current_user_id();
	$is_admin    = schat_is_site_admin();
	$member_id   = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
	$stop        = ! empty( $_POST['stop'] );

	if ( ! $is_admin ) $member_id = $current_uid;
	if ( ! $member_id ) wp_send_json_error( 'Missing conversation.' );

	if ( $stop ) {
		schat_clear_typing( $member_id, $is_admin );
		// Stopped typing but still here — keep the header line accurate.
		schat_set_activity( $member_id, $is_admin, 'viewing' );
	} else {
		schat_set_typing( $member_id, $is_admin );
		schat_set_activity( $member_id, $is_admin, 'typing' );
	}

	wp_send_json_success();
}

/* ============================================================
   AJAX: live activity ping — typing / scrolling / viewing / away
   ============================================================ */
add_action( 'wp_ajax_schat_activity', 'schat_ajax_activity' );
function schat_ajax_activity() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	$current_uid = get_current_user_id();
	$is_admin    = schat_is_site_admin();
	$member_id   = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
	$state       = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : 'viewing';

	// A member can only ever report activity in their own thread.
	if ( ! $is_admin ) $member_id = $current_uid;
	if ( ! $member_id ) wp_send_json_error( 'Missing conversation.' );

	schat_set_activity( $member_id, $is_admin, $state );
	wp_send_json_success();
}

/* ============================================================
   AJAX: send a celebration (the confetti button)
   ============================================================ */
add_action( 'wp_ajax_schat_celebrate', 'schat_ajax_celebrate' );
function schat_ajax_celebrate() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	$current_uid = get_current_user_id();
	$is_admin    = schat_is_site_admin();
	$member_id   = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;

	if ( ! $is_admin ) {
		$member_id = $current_uid;
		if ( ! schat_can_chat( $current_uid ) ) wp_send_json_error( 'Not allowed.', 403 );
	}
	if ( ! $member_id ) wp_send_json_error( 'Missing conversation.' );

	/* Server-side cooldown. The button disables itself for 2.5s client
	   side, but that's cosmetic — this is what actually stops someone
	   holding the line open with a script. */
	$rl_key = 'schat_party_rl_' . $current_uid;
	if ( get_transient( $rl_key ) ) {
		wp_send_json_error( 'Slow down a moment.', 429 );
	}
	set_transient( $rl_key, 1, 2 );

	$seq = schat_push_celebration( $member_id, $is_admin );

	// Firing confetti proves you're in the thread.
	schat_set_activity( $member_id, $is_admin, 'viewing' );

	wp_send_json_success( [ 'seq' => $seq ] );
}

/* ============================================================
   AJAX: admin hard-deletes an entire conversation
   ============================================================ */
add_action( 'wp_ajax_schat_delete_thread', 'schat_ajax_delete_thread' );
function schat_ajax_delete_thread() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! schat_is_site_admin() ) wp_send_json_error( 'Not authorized.', 403 );

	$member_id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
	if ( ! $member_id ) wp_send_json_error( 'Missing conversation.' );

	schat_delete_thread( $member_id );
	wp_send_json_success( [ 'member_id' => $member_id ] );
}

/* ============================================================================
   LINK PREVIEWS

   A URL in a message becomes a clickable link in the chat, and the server
   fetches that page once to pull its title / description / image so the
   message can show a small preview card.

   The fetch is the sensitive part: a member can put any URL in a message
   and make this site request it. Three things keep that safe —

     • wp_safe_remote_get(), not wp_remote_get(). It runs the URL through
       WordPress's own SSRF guard, which refuses loopback and private-network
       addresses, so nobody can use the chat to probe 127.0.0.1, 10.x or the
       cloud metadata endpoint.
     • http/https only, a short timeout, few redirects, and a capped
       response size — a preview is never worth a long-running request.
     • Results are cached per URL, and each user has a fetch budget, so a
       flood of links cannot turn into a flood of outbound requests.

   Nothing here trusts the fetched page: every value is treated as text,
   truncated, and escaped again on the way out.
   ============================================================================ */

define( 'SCHAT_LP_TIMEOUT',    6 );                 // seconds per fetch
define( 'SCHAT_LP_MAX_BYTES',  262144 );            // 256KB of HTML is plenty for <head>
define( 'SCHAT_LP_TTL',        7 * DAY_IN_SECONDS ); // cache a good preview for a week
define( 'SCHAT_LP_TTL_FAIL',   6 * HOUR_IN_SECONDS ); // and a failure for a few hours
define( 'SCHAT_LP_PER_MINUTE', 20 );                // fetches per user per minute

/**
 * Normalise a user-supplied link and reject anything that isn't a plain
 * web address. Returns '' when the URL is not previewable.
 */
function schat_lp_clean_url( $raw ) {
	$raw = trim( (string) $raw );
	if ( $raw === '' || strlen( $raw ) > 2000 ) return '';

	// "www.example.com" is a link as far as a person is concerned.
	if ( stripos( $raw, 'http://' ) !== 0 && stripos( $raw, 'https://' ) !== 0 ) {
		if ( stripos( $raw, 'www.' ) === 0 ) $raw = 'https://' . $raw;
		else return '';
	}

	$url = esc_url_raw( $raw, [ 'http', 'https' ] );
	if ( ! $url ) return '';

	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) return '';
	if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) return '';

	return $url;
}

/** Pull one <meta> value out of raw HTML, in either attribute order. */
function schat_lp_meta( $html, $name, $attr = 'property' ) {
	$n = preg_quote( $name, '#' );
	$a = preg_quote( $attr, '#' );
	// content= after the identifying attribute...
	if ( preg_match( '#<meta[^>]+' . $a . '\s*=\s*["\']' . $n . '["\'][^>]*?content\s*=\s*["\']([^"\']*)["\']#i', $html, $m ) ) {
		return $m[1];
	}
	// ...or before it.
	if ( preg_match( '#<meta[^>]+content\s*=\s*["\']([^"\']*)["\'][^>]*?' . $a . '\s*=\s*["\']' . $n . '["\']#i', $html, $m ) ) {
		return $m[1];
	}
	return '';
}

/** Tidy a value scraped from someone else's page. */
function schat_lp_text( $s, $max ) {
	$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$s = preg_replace( '/\s+/u', ' ', $s );
	$s = trim( wp_strip_all_tags( $s ) );
	if ( $s === '' ) return '';
	if ( function_exists( 'mb_substr' ) && mb_strlen( $s, 'UTF-8' ) > $max ) {
		$s = rtrim( mb_substr( $s, 0, $max, 'UTF-8' ) ) . '…';
	} elseif ( ! function_exists( 'mb_substr' ) && strlen( $s ) > $max ) {
		$s = rtrim( substr( $s, 0, $max ) ) . '…';
	}
	return $s;
}

/** Turn a possibly-relative image URL into an absolute https one, or ''. */
function schat_lp_image_url( $src, $base ) {
	$src = trim( html_entity_decode( (string) $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( $src === '' ) return '';

	if ( strpos( $src, '//' ) === 0 ) {
		$src = 'https:' . $src;
	} elseif ( strpos( $src, '/' ) === 0 ) {
		$b = wp_parse_url( $base );
		if ( empty( $b['host'] ) ) return '';
		$src = 'https://' . $b['host'] . $src;
	} elseif ( ! preg_match( '#^https?://#i', $src ) ) {
		return '';
	}

	/* https only. The member's browser loads this directly, and a plain
	   http image would be blocked as mixed content on an https site
	   anyway — better to show no thumbnail than a broken one. */
	if ( stripos( $src, 'https://' ) !== 0 ) return '';

	$src = esc_url_raw( $src, [ 'https' ] );
	return $src ? $src : '';
}

/**
 * Fetch and parse a link's metadata. Returns an array that is always safe
 * to hand to the client, or null when there is nothing worth showing.
 */
function schat_lp_fetch( $url ) {
	$resp = wp_safe_remote_get( $url, [
		'timeout'             => SCHAT_LP_TIMEOUT,
		'redirection'         => 3,
		'limit_response_size' => SCHAT_LP_MAX_BYTES,
		'user-agent'          => 'Mozilla/5.0 (compatible; ShataniaChat link preview)',
		'headers'             => [ 'Accept' => 'text/html,application/xhtml+xml' ],
	] );

	if ( is_wp_error( $resp ) ) return null;
	if ( (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;

	$type = wp_remote_retrieve_header( $resp, 'content-type' );
	if ( $type && stripos( $type, 'html' ) === false ) return null;

	$html = wp_remote_retrieve_body( $resp );
	if ( ! is_string( $html ) || $html === '' ) return null;
	$html = substr( $html, 0, SCHAT_LP_MAX_BYTES );

	$title = schat_lp_meta( $html, 'og:title' );
	if ( $title === '' ) $title = schat_lp_meta( $html, 'twitter:title', 'name' );
	if ( $title === '' && preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) $title = $m[1];

	$desc = schat_lp_meta( $html, 'og:description' );
	if ( $desc === '' ) $desc = schat_lp_meta( $html, 'twitter:description', 'name' );
	if ( $desc === '' ) $desc = schat_lp_meta( $html, 'description', 'name' );

	$site = schat_lp_meta( $html, 'og:site_name' );

	$img = schat_lp_meta( $html, 'og:image' );
	if ( $img === '' ) $img = schat_lp_meta( $html, 'og:image:secure_url' );
	if ( $img === '' ) $img = schat_lp_meta( $html, 'twitter:image', 'name' );

	$host = wp_parse_url( $url, PHP_URL_HOST );
	$host = $host ? preg_replace( '/^www\./i', '', $host ) : '';

	$out = [
		'url'   => $url,
		'title' => schat_lp_text( $title, 120 ),
		'desc'  => schat_lp_text( $desc, 200 ),
		'site'  => schat_lp_text( $site, 60 ),
		'image' => schat_lp_image_url( $img, $url ),
		'host'  => $host,
	];

	// A card with nothing but a domain on it isn't worth the space.
	if ( $out['title'] === '' && $out['desc'] === '' && $out['image'] === '' ) return null;
	return $out;
}

/* ============================================================
   AJAX: link preview
   ============================================================ */
add_action( 'wp_ajax_schat_link_preview', 'schat_ajax_link_preview' );
function schat_ajax_link_preview() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	$uid = get_current_user_id();
	if ( ! schat_is_site_admin() && ! schat_can_chat( $uid ) ) {
		wp_send_json_error( 'Not allowed.', 403 );
	}

	$url = schat_lp_clean_url( isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '' );
	if ( ! $url ) wp_send_json_error( 'Not a previewable link.' );

	$key = 'schat_lp_' . md5( $url );
	$hit = get_transient( $key );
	if ( $hit !== false ) {
		// 'none' is a remembered failure — don't retry it on every render.
		if ( $hit === 'none' ) wp_send_json_error( 'No preview available.' );
		wp_send_json_success( $hit );
	}

	/* Fetch budget. Only uncached links reach this point, so ordinary
	   chatting never touches it — it exists to cap someone pasting a
	   hundred distinct URLs. */
	$rl_key = 'schat_lp_rl_' . $uid;
	$used   = (int) get_transient( $rl_key );
	if ( $used >= SCHAT_LP_PER_MINUTE ) {
		wp_send_json_error( 'Too many link previews just now.', 429 );
	}
	set_transient( $rl_key, $used + 1, MINUTE_IN_SECONDS );

	$data = schat_lp_fetch( $url );
	if ( ! $data ) {
		set_transient( $key, 'none', SCHAT_LP_TTL_FAIL );
		wp_send_json_error( 'No preview available.' );
	}

	set_transient( $key, $data, SCHAT_LP_TTL );
	wp_send_json_success( $data );
}

/* ============================================================
   AJAX: mark read
   ============================================================ */
add_action( 'wp_ajax_schat_mark_read', 'schat_ajax_mark_read' );
function schat_ajax_mark_read() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	$current_uid = get_current_user_id();
	$is_admin    = schat_is_site_admin();
	$member_id   = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;

	if ( ! $is_admin ) $member_id = $current_uid;
	if ( ! $member_id ) wp_send_json_error( 'Missing conversation.' );

	schat_mark_read( $member_id, $is_admin );
	wp_send_json_success();
}

/* ============================================================
   AJAX: toggle heart on a message (replaces read-receipt/ack ticks)
   ============================================================ */
add_action( 'wp_ajax_schat_toggle_love', 'schat_ajax_toggle_love' );
function schat_ajax_toggle_love() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	global $wpdb;
	$current_uid = get_current_user_id();
	$is_admin    = schat_is_site_admin();
	$message_id  = isset( $_POST['message_id'] ) ? intval( $_POST['message_id'] ) : 0;
	if ( ! $message_id ) wp_send_json_error( 'Missing message.' );

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}schat_messages WHERE id = %d", $message_id ) );
	if ( ! $row ) wp_send_json_error( 'Message not found.' );

	// Only the recipient of a message can heart it.
	$viewer_is_recipient = $is_admin
		? ( intval( $row->sender_is_admin ) === 0 )
		: ( intval( $row->sender_is_admin ) === 1 && intval( $row->member_id ) === $current_uid );
	if ( ! $viewer_is_recipient ) wp_send_json_error( 'Not authorized.', 403 );

	/* An explicit value makes the call idempotent: the same request
	   arriving twice sets the same state twice instead of undoing itself.
	   Falls back to a toggle when nothing is sent, so an older cached
	   copy of the script still behaves as it always did. */
	if ( isset( $_POST['love'] ) ) {
		$new_val = ( $_POST['love'] === '1' || $_POST['love'] === 1 ) ? 1 : 0;
	} else {
		$new_val = $row->loved ? 0 : 1;
	}
	$wpdb->update( "{$wpdb->prefix}schat_messages", [ 'loved' => $new_val ], [ 'id' => $message_id ] );

	wp_send_json_success( [ 'loved' => (bool) $new_val ] );
}

/* ============================================================
   AJAX: admin deletes a message (soft delete). Members never
   get a delete control in the UI, and this handler independently
   enforces admin-only regardless of what the client sends.
   ============================================================ */
/* Deleting hides, it never destroys.
   schat_delete_message() only flips is_deleted, and every chat query
   filters on it, so the message simply stops existing as far as the chat
   is concerned — there is deliberately no "message deleted" placeholder
   for the other person to see. The row itself stays in the table and the
   whole conversation, deletions included, remains readable under
   Shatania -> Chat.

   Who may delete what:
     • Shatania — any message in any thread.
     • A member — only a message they sent themselves, only in their own
       thread. They can never delete Shatania's messages. */
add_action( 'wp_ajax_schat_delete_message', 'schat_ajax_delete_message' );
function schat_ajax_delete_message() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	global $wpdb;
	$current_uid = get_current_user_id();
	$is_admin    = schat_is_site_admin();
	$message_id  = isset( $_POST['message_id'] ) ? intval( $_POST['message_id'] ) : 0;
	if ( ! $message_id ) wp_send_json_error( 'Missing message.' );

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, member_id, sender_is_admin FROM {$wpdb->prefix}schat_messages
		  WHERE id = %d AND is_deleted = 0",
		$message_id
	) );
	if ( ! $row ) wp_send_json_error( 'Message not found.' );

	if ( ! $is_admin ) {
		// Own thread only — mirrors the ownership check in the edit handler.
		if ( intval( $row->member_id ) !== $current_uid )  wp_send_json_error( 'Not authorized.', 403 );
		// Own messages only — a member cannot remove Shatania's words.
		if ( intval( $row->sender_is_admin ) === 1 )       wp_send_json_error( 'Not authorized.', 403 );
		if ( ! schat_can_chat( $current_uid ) )            wp_send_json_error( 'Not allowed.', 403 );
	}

	schat_delete_message( $message_id );
	wp_send_json_success( [ 'message_id' => intval( $row->id ) ] );
}

/* ============================================================
   AJAX: edit an already-sent message.
   A sender may edit the TEXT of their own message. Admin can edit
   any admin-sent message; a member can edit only their own
   (member-sent) messages in their own thread. Photos aren't edited
   here — only the text body. Editing to empty is rejected unless
   the message also carries a photo.
   ============================================================ */
add_action( 'wp_ajax_schat_edit_message', 'schat_ajax_edit_message' );
function schat_ajax_edit_message() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	global $wpdb;
	$current_uid = get_current_user_id();
	$is_admin    = schat_is_site_admin();
	$message_id  = isset( $_POST['message_id'] ) ? intval( $_POST['message_id'] ) : 0;
	$new_text    = isset( $_POST['message'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ) : '';

	if ( ! $message_id ) wp_send_json_error( 'Missing message.' );

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}schat_messages WHERE id = %d AND is_deleted = 0",
		$message_id
	) );
	if ( ! $row ) wp_send_json_error( 'Message not found.' );

	// Ownership check — you can only edit a message you sent.
	$viewer_is_sender = $is_admin
		? ( intval( $row->sender_is_admin ) === 1 )
		: ( intval( $row->sender_is_admin ) === 0 && intval( $row->member_id ) === $current_uid );
	if ( ! $viewer_is_sender ) wp_send_json_error( 'Not authorized.', 403 );

	// Don't let text be blanked out unless a photo remains.
	if ( $new_text === '' && $row->image_url === '' ) {
		wp_send_json_error( 'Message cannot be empty.' );
	}

	$wpdb->update(
		"{$wpdb->prefix}schat_messages",
		[ 'message' => $new_text, 'edited_at' => gmdate( 'Y-m-d H:i:s' ) ],
		[ 'id' => $message_id ]
	);

	$fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}schat_messages WHERE id = %d", $message_id ) );
	wp_send_json_success( [ 'message' => $fresh ] );
}

/* ============================================================
   AJAX: admin manually notifies a member by email about a
   specific message she sent them.
   ============================================================ */
add_action( 'wp_ajax_schat_notify_member', 'schat_ajax_notify_member' );
function schat_ajax_notify_member() {
	check_ajax_referer( 'schat_nonce', 'nonce' );
	if ( ! schat_is_site_admin() ) wp_send_json_error( 'Not authorized.', 403 );

	global $wpdb;
	$message_id = isset( $_POST['message_id'] ) ? intval( $_POST['message_id'] ) : 0;
	if ( ! $message_id ) wp_send_json_error( 'Missing message.' );

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}schat_messages WHERE id = %d", $message_id ) );
	if ( ! $row || intval( $row->sender_is_admin ) !== 1 ) wp_send_json_error( 'Message not found.' );

	$member = get_user_by( 'id', $row->member_id );
	if ( ! $member ) wp_send_json_error( 'Member not found.' );

	schat_notify_member_of_reply( $member, $row->message, $row->image_url !== '' );

	// Remember that this message was already emailed, so the UI can show
	// it as sent after a page reload instead of offering it again.
	$wpdb->update(
		"{$wpdb->prefix}schat_messages",
		[ 'notified_at' => gmdate( 'Y-m-d H:i:s' ) ],
		[ 'id' => $message_id ]
	);

	wp_send_json_success();
}

/* ============================================================
   ADMIN DASHBOARD PAGE (wp-admin) — management only.
   Reading/replying happens in the front-end widget; this page
   exists so an entire conversation can be permanently deleted.
   ============================================================ */
/**
 * Every conversation, for the admin screen.
 *
 * Unlike schat_get_all_threads() this does NOT filter out deleted
 * messages, so a thread survives here even after every message in it has
 * been deleted from the chat — which is the point: the admin record is
 * the one place nothing disappears from.
 */
function schat_admin_threads() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT member_id,
		        MAX(sent_at) AS last_sent_at,
		        COUNT(*) AS total,
		        SUM(CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END) AS deleted_count,
		        SUM(CASE WHEN sender_is_admin = 0 AND read_at IS NULL AND is_deleted = 0 THEN 1 ELSE 0 END) AS unread
		   FROM {$wpdb->prefix}schat_messages
		  GROUP BY member_id
		  ORDER BY last_sent_at DESC"
	);

	$out = [];
	foreach ( (array) $rows as $row ) {
		$user = get_user_by( 'id', $row->member_id );
		$last_msg = $wpdb->get_row( $wpdb->prepare(
			"SELECT message, image_url, sender_is_admin FROM {$wpdb->prefix}schat_messages
			  WHERE member_id = %d AND is_deleted = 0 ORDER BY id DESC LIMIT 1",
			$row->member_id
		) );
		$out[] = [
			'member_id'     => (int) $row->member_id,
			'name'          => $user ? $user->display_name : ( 'user #' . intval( $row->member_id ) ),
			'email'         => $user ? $user->user_email : '',
			'unread'        => (int) $row->unread,
			'total'         => (int) $row->total,
			'deleted_count' => (int) $row->deleted_count,
			'last_at'       => $row->last_sent_at,
			'last_msg'      => $last_msg,
		];
	}
	return $out;
}

/**
 * The complete transcript for one thread — deleted messages included.
 *
 * Only the admin screen calls this. It deliberately ignores is_deleted so
 * that a message someone removed from the chat is still readable here.
 */
function schat_full_thread( $member_id, $limit = 500 ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT id, sender_is_admin, message, image_url, sent_at, is_deleted, edited_at
		   FROM {$wpdb->prefix}schat_messages
		  WHERE member_id = %d
		  ORDER BY id ASC
		  LIMIT %d",
		intval( $member_id ), intval( $limit )
	) );
}

/**
 * Renders one conversation's full transcript as collapsed admin markup.
 * Deleted messages are shown, greyed and labelled, never omitted.
 */
function schat_admin_render_transcript( $member_id, $member_name, $total, $deleted_count ) {
	$msgs = schat_full_thread( $member_id );
	?>
	<details>
		<summary style="cursor:pointer;color:#2271b1;">
			View full transcript (<?php echo intval( $total ); ?> messages<?php
				if ( $deleted_count > 0 ) echo ', including ' . intval( $deleted_count ) . ' deleted';
			?>)
		</summary>
		<div style="max-height:440px;overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin:10px 0 4px;">
			<?php if ( empty( $msgs ) ) : ?>
				<p style="color:#888;margin:0;">No messages stored.</p>
			<?php endif; ?>
			<?php foreach ( $msgs as $m ) :
				$from_admin = intval( $m->sender_is_admin ) === 1;
				$gone       = intval( $m->is_deleted ) === 1;
				?>
				<div style="padding:8px 10px;margin-bottom:6px;border-radius:8px;
				            border-left:3px solid <?php echo $from_admin ? '#D9A9AC' : '#c3c4c7'; ?>;
				            background:<?php echo $gone ? '#fcf0f1' : '#f6f7f7'; ?>;">
					<div style="font-size:11.5px;color:#888;margin-bottom:3px;">
						<strong style="color:#333;"><?php echo $from_admin ? 'You' : esc_html( $member_name ); ?></strong>
						&middot; <?php echo esc_html( schat_time_label( $m->sent_at ) ); ?>
						<?php if ( $m->edited_at ) : ?>&middot; edited<?php endif; ?>
						<?php if ( $gone ) : ?>
							&middot; <span style="color:#b32d2e;font-weight:600;">deleted from chat</span>
						<?php endif; ?>
					</div>
					<?php if ( $m->message !== '' ) : ?>
						<div style="font-size:13.5px;color:<?php echo $gone ? '#6b6b6b' : '#1d2327'; ?>;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html( $m->message ); ?></div>
					<?php endif; ?>
					<?php if ( $m->image_url !== '' ) : ?>
						<div style="margin-top:6px;">
							<a href="<?php echo esc_url( $m->image_url ); ?>" target="_blank" rel="noopener">
								<img src="<?php echo esc_url( $m->image_url ); ?>" alt="" style="max-width:150px;max-height:150px;border-radius:6px;display:block;<?php echo $gone ? 'opacity:.6;' : ''; ?>">
							</a>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</details>
	<?php
}

function schat_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not authorized.' );

	// ---- Handle "Delete conversation" (hard delete, nonce-protected) ----
	$schat_notice = '';
	if ( isset( $_POST['schat_delete_thread'] ) ) {
		$del_id = intval( $_POST['schat_delete_thread'] );
		if ( ! isset( $_POST['schat_del_nonce'] ) || ! wp_verify_nonce( $_POST['schat_del_nonce'], 'schat_del_thread_' . $del_id ) ) {
			$schat_notice = '<div class="notice notice-error"><p>Security check failed. Nothing was deleted.</p></div>';
		} else {
			$u = get_user_by( 'id', $del_id );
			schat_delete_thread( $del_id );
			$schat_notice = '<div class="notice notice-success is-dismissible"><p>Conversation with <strong>'
				. esc_html( $u ? $u->display_name : ( 'user #' . $del_id ) )
				. '</strong> was permanently deleted.</p></div>';
		}
	}

	echo '<div class="wrap schat-admin-wrap">';
	echo '<h1 style="margin-bottom:18px;">Shatania Chat</h1>';
	echo $schat_notice;
	echo '<p style="color:#666;max-width:780px;">Conversations are read and replied to from the front-end chat widget. This page is the permanent record: a message either of you deletes disappears from the chat silently, but it stays here, marked, and can still be read in the transcript below.</p>';

	$threads = schat_admin_threads();
	?>
	<h2 style="font-size:15px;">Conversations</h2>
	<?php if ( empty( $threads ) ) : ?>
		<p>No conversations yet. Any approved member can message you first &mdash; this list fills in once someone writes.</p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:980px;">
			<thead><tr><th>Member</th><th>Last message</th><th>Last active</th><th style="width:110px;">Messages</th><th style="width:150px;"></th></tr></thead>
			<tbody>
			<?php foreach ( $threads as $t ) :
				$preview = '';
				if ( $t['last_msg'] ) {
					$preview = $t['last_msg']->message !== '' ? wp_trim_words( $t['last_msg']->message, 12, '…' ) : 'Photo';
					$prefix  = intval( $t['last_msg']->sender_is_admin ) === 1 ? 'You: ' : '';
					$preview = $prefix . $preview;
				} elseif ( $t['deleted_count'] > 0 ) {
					$preview = '—';
				}
			?>
				<tr>
					<td><strong><?php echo esc_html( $t['name'] ); ?></strong><br><span style="color:#888;font-size:12px;"><?php echo esc_html( $t['email'] ); ?></span></td>
					<td><?php echo esc_html( $preview ); ?></td>
					<td><?php echo esc_html( schat_time_label( $t['last_at'] ) ); ?></td>
					<td>
						<?php echo intval( $t['total'] ); ?> total
						<?php if ( $t['deleted_count'] > 0 ) : ?>
							<br><span style="color:#b32d2e;font-size:12px;"><?php echo intval( $t['deleted_count'] ); ?> deleted</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $t['unread'] > 0 ) : ?>
							<span style="background:#E24C4C;color:#fff;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:600;"><?php echo intval( $t['unread'] ); ?> new</span>
						<?php endif; ?>
						<form method="post" style="display:inline-block;margin-left:6px;"
							onsubmit="return confirm('Permanently delete the entire conversation with <?php echo esc_js( $t['name'] ); ?>?\n\nAll messages and any photos they sent will be erased, including anything already deleted from the chat. This cannot be undone.');">
							<?php wp_nonce_field( 'schat_del_thread_' . $t['member_id'], 'schat_del_nonce' ); ?>
							<input type="hidden" name="schat_delete_thread" value="<?php echo esc_attr( $t['member_id'] ); ?>">
							<button type="submit" class="button button-link-delete" style="color:#b32d2e;">Delete</button>
						</form>
					</td>
				</tr>
				<tr>
					<td colspan="5" style="padding-top:0;">
						<?php schat_admin_render_transcript( $t['member_id'], $t['name'], $t['total'], $t['deleted_count'] ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
	echo '</div>';
}

// ============================================================
// CLOUDFLARE TURNSTILE VERIFICATION HELPER
// ============================================================
function shat_verify_turnstile( $token ) {
	if ( empty( $token ) ) return false;
	$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
		'body' => [
			'secret'   => SHAT_CF_SECRET_KEY,
			'response' => sanitize_text_field( $token ),
			'remoteip' => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
		],
		'timeout' => 10,
	] );
	if ( is_wp_error( $response ) ) return false;
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return ! empty( $body['success'] );
}


// ============================================================
// LOCKDOWN — disable default wp-login.php / wp-admin login form
// All authentication MUST go through /shatania-login/
// ============================================================

/**
 * Return the canonical custom login URL.
 * (Uses hard-coded path so this works even before pages are resolved.)
 */
function shat_custom_login_url() {
	if ( function_exists( 'shat_page_url' ) ) {
		$u = shat_page_url( 'shatania-login' );
		if ( $u ) return $u;
	}
	return home_url( '/shatania-login/' );
}

/**
 * Block direct access to wp-login.php.
 * Only logout, postpass and email-confirmation links are allowed through.
 * Everything else (login form GET, login POST, lost password, register)
 * is redirected to the custom login page.
 */
add_action( 'login_init', 'shat_block_wp_login_screen', 0 );
function shat_block_wp_login_screen() {
	// Allow internal/system actions only.
	$action  = isset( $_REQUEST['action'] ) ? strtolower( (string) $_REQUEST['action'] ) : '';
	$allowed = [ 'logout', 'postpass', 'confirmaction', 'confirm_admin_email', 'resetpass', 'rp' ];
	if ( in_array( $action, $allowed, true ) ) return;

	// Block everything else (default login form, POST submit, lostpassword, register).
	nocache_headers();
	wp_safe_redirect( shat_custom_login_url(), 302 );
	exit;
}

/**
 * Reject any authentication attempt that did not originate from our
 * custom /shatania-login/ shortcode. The shortcode does NOT call
 * wp_authenticate(); it uses wp_check_password() + wp_set_auth_cookie()
 * directly, so it is unaffected by this filter.
 *
 * This blocks: wp-login.php POST, XML-RPC, app passwords over basic auth,
 * REST cookie auth attempts, and any 3rd-party plugin calling wp_signon().
 */
add_filter( 'authenticate', 'shat_block_default_authenticate', 1, 3 );
function shat_block_default_authenticate( $user, $username, $password ) {
	// Not a credential submission — let other filters run (e.g. cookie check).
	if ( empty( $username ) && empty( $password ) ) return $user;
	// Allow internal/system contexts.
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) return $user;
	if ( defined( 'WP_CLI' ) && WP_CLI ) return $user;
	if ( defined( 'SHAT_CUSTOM_LOGIN' ) && SHAT_CUSTOM_LOGIN ) return $user;

	return new WP_Error(
		'shat_login_disabled',
		'Direct sign-in is disabled. Please sign in at <a href="' . esc_url( shat_custom_login_url() ) . '">' . esc_html( shat_custom_login_url() ) . '</a>.'
	);
}

/**
 * Disable XML-RPC authentication entirely (closes another bypass).
 */
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', function( $methods ) {
	unset( $methods['system.multicall'] );
	return $methods;
} );

/**
 * Disable application passwords (would otherwise allow basic-auth sign-in).
 */
add_filter( 'wp_is_application_passwords_available', '__return_false' );

/**
 * Force the "login URL" used everywhere in WordPress (admin redirects,
 * "You must log in" notices, comment login links, etc.) to point at our
 * custom page instead of wp-login.php.
 */
add_filter( 'login_url', 'shat_filter_login_url', 99, 3 );
function shat_filter_login_url( $login_url, $redirect, $force_reauth ) {
	$url = shat_custom_login_url();
	if ( ! empty( $redirect ) ) {
		$url = add_query_arg( 'redirect_to', urlencode( $redirect ), $url );
	}
	return $url;
}

/**
 * Send wp-admin visitors who are not logged in to the custom login page
 * (skip AJAX and the login page itself).
 */
add_action( 'admin_init', 'shat_guard_wp_admin', 0 );
function shat_guard_wp_admin() {
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
	if ( is_user_logged_in() ) return;
	nocache_headers();
	wp_safe_redirect( shat_custom_login_url(), 302 );
	exit;
}




// ============================================================
// 2FA — Google Authenticator (TOTP, RFC 6238) + email login code
// ============================================================
// Per-user meta keys:
//   shat_2fa_secret        Base32 secret (admin only when enabled)
//   shat_2fa_enabled       '1' when admin has verified setup
//   shat_login_code_hash   sha256 of one-time email code
//   shat_login_code_exp    unix timestamp expiry of email code
//   shat_login_code_tries  remaining attempts (int)

function shat_base32_encode( $bin ) {
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$out = ''; $v = 0; $vbits = 0;
	for ( $i = 0; $i < strlen( $bin ); $i++ ) {
		$v = ( $v << 8 ) | ord( $bin[ $i ] );
		$vbits += 8;
		while ( $vbits >= 5 ) {
			$vbits -= 5;
			$out .= $alphabet[ ( $v >> $vbits ) & 31 ];
		}
	}
	if ( $vbits > 0 ) $out .= $alphabet[ ( $v << ( 5 - $vbits ) ) & 31 ];
	while ( strlen( $out ) % 8 ) $out .= '=';
	return $out;
}

function shat_base32_decode( $b32 ) {
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$b32 = strtoupper( preg_replace( '/[^A-Z2-7]/', '', $b32 ) );
	$out = ''; $v = 0; $vbits = 0;
	for ( $i = 0; $i < strlen( $b32 ); $i++ ) {
		$idx = strpos( $alphabet, $b32[ $i ] );
		if ( $idx === false ) continue;
		$v = ( $v << 5 ) | $idx;
		$vbits += 5;
		if ( $vbits >= 8 ) {
			$vbits -= 8;
			$out .= chr( ( $v >> $vbits ) & 0xFF );
		}
	}
	return $out;
}

function shat_generate_totp_secret() {
	// 20 random bytes -> 32 base32 chars
	return shat_base32_encode( random_bytes( 20 ) );
}

function shat_totp_code( $secret_b32, $timeslice = null ) {
	if ( $timeslice === null ) $timeslice = floor( time() / 30 );
	$key = shat_base32_decode( $secret_b32 );
	$bin = pack( 'N*', 0 ) . pack( 'N*', $timeslice ); // 8-byte big-endian counter
	$hash = hash_hmac( 'sha1', $bin, $key, true );
	$offset = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0F;
	$truncated = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
		| ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 )
		| ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 )
		| ( ord( $hash[ $offset + 3 ] ) & 0xFF );
	return str_pad( (string) ( $truncated % 1000000 ), 6, '0', STR_PAD_LEFT );
}

function shat_totp_verify( $secret_b32, $code, $window = 1 ) {
	$code = preg_replace( '/\s+/', '', (string) $code );
	if ( ! ctype_digit( $code ) || strlen( $code ) !== 6 ) return false;
	$now = floor( time() / 30 );
	for ( $i = -$window; $i <= $window; $i++ ) {
		if ( hash_equals( shat_totp_code( $secret_b32, $now + $i ), $code ) ) return true;
	}
	return false;
}

function shat_otpauth_uri( $secret_b32, $account, $issuer ) {
	$label = rawurlencode( $issuer . ':' . $account );
	$qs    = http_build_query( [
		'secret' => $secret_b32,
		'issuer' => $issuer,
		'digits' => 6,
		'period' => 30,
		'algorithm' => 'SHA1',
	] );
	return 'otpauth://totp/' . $label . '?' . $qs;
}

function shat_qr_image_url( $otpauth_uri ) {
	// Google Chart QR endpoint (no library required).
	return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode( $otpauth_uri );
}

function shat_admin_has_2fa( $user_id ) {
	return get_user_meta( $user_id, 'shat_2fa_enabled', true ) === '1'
		&& ! empty( get_user_meta( $user_id, 'shat_2fa_secret', true ) );
}

/* ---- Email login code (for non-admin users) ---- */
function shat_issue_email_code( $user ) {
	$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	update_user_meta( $user->ID, 'shat_login_code_hash',  hash( 'sha256', $code ) );
	update_user_meta( $user->ID, 'shat_login_code_exp',   time() + 10 * MINUTE_IN_SECONDS );
	update_user_meta( $user->ID, 'shat_login_code_tries', 5 );

	$body = '<p style="font-size:15px;color:#333;line-height:1.6;">Hi ' . esc_html( $user->display_name ?: $user->user_login ) . ',</p>'
		. '<p style="font-size:14px;color:#444;line-height:1.7;">Use this code to finish signing in to ' . esc_html( SHAT_SITE_NAME ) . ':</p>'
		. '<div style="font-size:34px;letter-spacing:8px;font-weight:600;color:#1f1f1f;background:#FBF0F1;border:1px solid #E8C4C6;border-radius:12px;padding:18px;text-align:center;margin:18px 0;">'
		. esc_html( $code ) . '</div>'
		. '<p style="font-size:13px;color:#888;">This code expires in 10 minutes. If you didn\'t try to sign in, you can ignore this email.</p>';
	/* The code is deliberately NOT in the subject line. Putting a one-time
	   code there is a well-known spam-filter trigger, and it leaves the code
	   readable in a lock-screen notification. */
	return shat_mail(
		$user->user_email,
		'Your ' . SHAT_SITE_NAME . ' sign-in code',
		shat_email_wrap( 'Your sign-in code', $body )
	);
}

function shat_verify_email_code( $user_id, $code ) {
	$code = preg_replace( '/\s+/', '', (string) $code );
	if ( ! ctype_digit( $code ) || strlen( $code ) !== 6 ) return 'Enter the 6-digit code from your email.';
	$hash  = get_user_meta( $user_id, 'shat_login_code_hash', true );
	$exp   = (int) get_user_meta( $user_id, 'shat_login_code_exp', true );
	$tries = (int) get_user_meta( $user_id, 'shat_login_code_tries', true );
	if ( ! $hash || ! $exp ) return 'No active code. Please start again.';
	if ( time() > $exp ) {
		delete_user_meta( $user_id, 'shat_login_code_hash' );
		return 'That code has expired. Please request a new one.';
	}
	if ( $tries <= 0 ) {
		delete_user_meta( $user_id, 'shat_login_code_hash' );
		return 'Too many attempts. Please request a new code.';
	}
	if ( ! hash_equals( $hash, hash( 'sha256', $code ) ) ) {
		update_user_meta( $user_id, 'shat_login_code_tries', $tries - 1 );
		return 'Incorrect code. ' . max( 0, $tries - 1 ) . ' tries remaining.';
	}
	// success — clear
	delete_user_meta( $user_id, 'shat_login_code_hash' );
	delete_user_meta( $user_id, 'shat_login_code_exp' );
	delete_user_meta( $user_id, 'shat_login_code_tries' );
	return '';
}

/* ---- Short-lived signed pending-login token (kept in transient) ---- */
function shat_pending_login_start( $user_id, $kind /* 'totp' | 'email' */, $remember ) {
	$token = bin2hex( random_bytes( 24 ) );
	set_transient( 'shat_pl_' . $token, [
		'uid'      => (int) $user_id,
		'kind'     => $kind,
		'remember' => (bool) $remember,
	], 15 * MINUTE_IN_SECONDS );
	return $token;
}
function shat_pending_login_get( $token ) {
	if ( ! $token || ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) return null;
	return get_transient( 'shat_pl_' . $token ) ?: null;
}
function shat_pending_login_clear( $token ) {
	if ( $token ) delete_transient( 'shat_pl_' . $token );
}

// ============================================================
// GALLERY HELPERS (1.4.0) — multi-image post support
// ============================================================
function shat_get_gallery_images( $pid, $size = 'large' ) {
	$ids  = get_post_meta( $pid, '_shat_gallery_ids', true );
	$urls = [];
	if ( is_array( $ids ) && ! empty( $ids ) ) {
		foreach ( $ids as $aid ) {
			$aid = intval( $aid );
			if ( ! $aid ) continue;
			$u = wp_get_attachment_image_url( $aid, $size );
			if ( $u ) $urls[] = $u;
		}
	}
	if ( empty( $urls ) ) {
		$u = get_the_post_thumbnail_url( $pid, $size )
			?: get_post_meta( $pid, '_shat_image_url', true );
		if ( $u ) $urls[] = $u;
	}
	return $urls;
}

/**
 * Generate (and cache) a heavily blurred JPEG preview of an attachment image.
 * Returns a public URL of the blurred preview, or '' on failure.
 * Used in subscriber notification emails as a teaser.
 */
function shat_generate_blurred_preview( $attachment_id ) {
	$attachment_id = intval( $attachment_id );
	if ( ! $attachment_id ) return '';

	/* Cache key includes the algorithm version, so previews made by the
	   older (blocky) version are ignored rather than reused. */
	$cached = get_post_meta( $attachment_id, '_shat_blur_preview_url_v3', true );
	$cached_path = get_post_meta( $attachment_id, '_shat_blur_preview_path_v3', true );
	if ( $cached && $cached_path && file_exists( $cached_path ) ) {
		return $cached;
	}

	$src_path = get_attached_file( $attachment_id );
	if ( ! $src_path || ! file_exists( $src_path ) ) return '';
	if ( ! function_exists( 'imagecreatetruecolor' ) ) return ''; // GD not available

	$info = @getimagesize( $src_path );
	if ( ! $info ) return '';
	$mime = $info['mime'] ?? '';

	switch ( $mime ) {
		case 'image/jpeg': $src = @imagecreatefromjpeg( $src_path ); break;
		case 'image/png':  $src = @imagecreatefrompng( $src_path );  break;
		case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp( $src_path ) : false; break;
		default: $src = false;
	}
	if ( ! $src ) return '';

	$w = imagesx( $src ); $h = imagesy( $src );

	$out_w = 600;
	$out_h = max( 1, intval( $h * ( $out_w / max( 1, $w ) ) ) );

	/* Why this is done in stages rather than "shrink hard, blur, enlarge":

	   GD's IMG_FILTER_GAUSSIAN_BLUR is a fixed 3x3 kernel. Its reach is
	   three PIXELS, not a fraction of the image — so on a 120px-wide
	   working copy it barely spreads colour at all, and a 5x upscale then
	   magnifies every surviving pixel edge into a ~5px hard-edged square.
	   That is the mosaic/pixelated look, and adding more passes at the
	   small size cannot fix it.

	   Instead: step down gradually (each step at most halving), which
	   makes GD's resampler average neighbouring pixels properly, then
	   step back UP gradually with blur passes between each enlargement.
	   Blurring after every upscale keeps the kernel working on edges
	   while they are still only a pixel or two wide, so they never get
	   a chance to harden into blocks. */

	/* ---- Step 1: progressive downscale to a smooth, detail-free base.
	   88px is small enough that no subject detail survives. Halving at
	   each step (rather than one big jump) is what removes detail
	   smoothly instead of aliasing it into hard specks. */
	$cur   = $src;
	$cur_w = $w;
	$cur_h = $h;
	/* 16px base — every email image goes through this, so it is tuned for
	   maximum concealment rather than a light frosting.

	   Measured structural correlation against the source, using GD's
	   actual 3x3 kernel: 88px base leaves ~82% (clearly readable),
	   32px ~47%, 16px ~31% with no visible blocking. Below 16px the
	   gains flatten out while the result starts looking like a flat
	   colour swatch rather than a teaser. */
	$target_small = 16;

	while ( $cur_w > $target_small * 2 ) {
		$next_w = max( $target_small, intval( $cur_w / 2 ) );
		$next_h = max( 1, intval( $cur_h * ( $next_w / $cur_w ) ) );
		$next   = imagecreatetruecolor( $next_w, $next_h );
		imagecopyresampled( $next, $cur, 0, 0, 0, 0, $next_w, $next_h, $cur_w, $cur_h );
		imagedestroy( $cur );
		$cur = $next; $cur_w = $next_w; $cur_h = $next_h;
	}

	/* Final hop to the working size. */
	$small_w = $target_small;
	$small_h = max( 1, intval( $cur_h * ( $small_w / max( 1, $cur_w ) ) ) );
	$small   = imagecreatetruecolor( $small_w, $small_h );
	imagecopyresampled( $small, $cur, 0, 0, 0, 0, $small_w, $small_h, $cur_w, $cur_h );
	imagedestroy( $cur );

	/* Soften the small base so the first enlargement has nothing sharp
	   to magnify. */
	for ( $i = 0; $i < 14; $i++ ) {
		@imagefilter( $small, IMG_FILTER_GAUSSIAN_BLUR );
	}

	/* ---- Step 2: progressive upscale, blurring between each step.
	   Doubling at most per step keeps every edge within reach of the
	   3x3 kernel, so nothing ever hardens into a visible block. */
	$cur   = $small;
	$cur_w = $small_w;
	$cur_h = $small_h;

	while ( $cur_w < $out_w ) {
		$next_w = min( $out_w, $cur_w * 2 );
		$next_h = max( 1, intval( $cur_h * ( $next_w / $cur_w ) ) );
		$next   = imagecreatetruecolor( $next_w, $next_h );
		imagecopyresampled( $next, $cur, 0, 0, 0, 0, $next_w, $next_h, $cur_w, $cur_h );
		imagedestroy( $cur );
		$cur = $next; $cur_w = $next_w; $cur_h = $next_h;

		/* Blur at THIS size before enlarging again — this is the step
		   the old version was missing. */
		for ( $i = 0; $i < 8; $i++ ) {
			@imagefilter( $cur, IMG_FILTER_GAUSSIAN_BLUR );
		}
	}

	$out   = $cur;
	$out_w = $cur_w;
	$out_h = $cur_h;

	/* ---- Step 3: final polish at full size. */
	for ( $i = 0; $i < 6; $i++ ) {
		@imagefilter( $out, IMG_FILTER_GAUSSIAN_BLUR );
	}

	/* Slight darkening so light photos do not read as a washed-out grey
	   rectangle in an email client, and so the caption below stays
	   readable against it. */
	@imagefilter( $out, IMG_FILTER_BRIGHTNESS, -12 );

	$upload = wp_upload_dir();
	$dir = trailingslashit( $upload['basedir'] ) . 'shat-blur';
	$url_base = trailingslashit( $upload['baseurl'] ) . 'shat-blur';
	if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );

	/* The 'v2' marks the progressive-blur algorithm. Bumping this string
	   invalidates every previously cached preview, so old blocky-looking
	   files are regenerated instead of served forever from cache. */
	$fname = 'blur-v3-' . $attachment_id . '-' . substr( md5( 'v3' . $src_path . filemtime( $src_path ) ), 0, 8 ) . '.jpg';
	$out_path = $dir . '/' . $fname;
	$out_url  = $url_base . '/' . $fname;

	$ok = @imagejpeg( $out, $out_path, 70 );
	imagedestroy( $out );
	if ( ! $ok ) return '';

	update_post_meta( $attachment_id, '_shat_blur_preview_path_v3', $out_path );
	update_post_meta( $attachment_id, '_shat_blur_preview_url_v3',  $out_url  );
	return $out_url;
}

/**
 * Get a blurred preview URL for a photo post's cover image.
 */
function shat_get_post_blur_preview_url( $pid ) {
	$aid = intval( get_post_thumbnail_id( $pid ) );
	if ( ! $aid ) {
		$ids = get_post_meta( $pid, '_shat_gallery_ids', true );
		if ( is_array( $ids ) && ! empty( $ids ) ) $aid = intval( $ids[0] );
	}
	if ( $aid ) return shat_generate_blurred_preview( $aid );
	return '';
}

function shat_allowed_image_mimes() {
	return [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	];
}
add_filter( 'upload_mimes', function( $mimes ) {
	if ( is_admin() && isset( $_GET['page'] ) && strpos( sanitize_key( $_GET['page'] ), 'shatania' ) === 0 ) {
		return shat_allowed_image_mimes();
	}
	return $mimes;
} );


// ============================================================
// DB SETUP — shared between activation & version-upgrade
// ============================================================
function shat_run_db_setup() {
	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shat_likes (
		id         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		photo_id   bigint(20) UNSIGNED NOT NULL,
		user_id    bigint(20) UNSIGNED NOT NULL DEFAULT 0,
		ip_address varchar(45)          NOT NULL DEFAULT '',
		created_at datetime             NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY unique_user_like (photo_id, user_id),
		KEY photo_idx (photo_id)
	) $charset;" );

	/* Views. Every impression is its own row — there is deliberately NO
	   unique key on (photo_id, viewer_hash) any more.

	   The old schema had UNIQUE KEY unique_view (photo_id, viewer_hash)
	   where the hash included date('Y-m-d'), which capped a photo at ONE
	   view per person per day. Opening the same post ten times counted
	   once, so the number never behaved like a view count. The hash is
	   still stored (for distinct-viewer maths and light bot filtering),
	   it just no longer blocks inserts. */
	dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shat_views (
		id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		photo_id    bigint(20) UNSIGNED NOT NULL,
		user_id     bigint(20) UNSIGNED NOT NULL DEFAULT 0,
		viewer_hash varchar(64)         NOT NULL DEFAULT '',
		viewed_at   datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY photo_idx (photo_id),
		KEY user_idx (user_id),
		KEY hash_idx (viewer_hash),
		KEY photo_time_idx (photo_id, viewed_at)
	) $charset;" );

	/* Drop the old unique key on existing installs. dbDelta will not
	   remove an index by itself, so without this the cap would persist
	   on every site that was created before this version. */
	$views_tbl = $wpdb->prefix . 'shat_views';
	$has_unique = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM information_schema.STATISTICS
		  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'unique_view'",
		$views_tbl
	) );
	if ( $has_unique ) {
		$wpdb->query( "ALTER TABLE {$views_tbl} DROP INDEX unique_view" );
	}

	/* NEW in 1.3.0 — per-user permanent unlocks for blurred photos */
	dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shat_unlocked (
		id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		photo_id    bigint(20) UNSIGNED NOT NULL,
		user_id     bigint(20) UNSIGNED NOT NULL,
		unlocked_at datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY unique_unlock (photo_id, user_id),
		KEY photo_idx (photo_id)
	) $charset;" );

	/* NEW — per-user Favorites/Saved photos (private per-member, Instagram-Saved style) */
	dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shat_favorites (
		id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		photo_id    bigint(20) UNSIGNED NOT NULL,
		user_id     bigint(20) UNSIGNED NOT NULL,
		saved_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY unique_fav (photo_id, user_id),
		KEY user_idx (user_id),
		KEY photo_idx (photo_id)
	) $charset;" );

	/* NEW — in-app access requests (replaces raw mailto: links).
	   type: 'access' (pending member requesting approval), 'renew' (feed access
	   expired), 'unlock' (single locked photo). photo_id is 0 for access/renew.
	   status: 'open' (new, unanswered), 'in_progress' (admin has replied at
	   least once, conversation ongoing), 'resolved' (admin closed it).
	   admin_reply/replied_at/reply_seen mirror the LATEST message for cheap
	   access from older queries — the full conversation lives in
	   shat_request_messages below. */
	dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shat_access_requests (
		id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id     bigint(20) UNSIGNED NOT NULL,
		type        varchar(20)          NOT NULL DEFAULT 'access',
		photo_id    bigint(20) UNSIGNED NOT NULL DEFAULT 0,
		message     text                 NULL,
		status      varchar(20)          NOT NULL DEFAULT 'open',
		admin_reply text                 NULL,
		replied_at  datetime             NULL,
		reply_seen  tinyint(1)           NOT NULL DEFAULT 0,
		created_at  datetime             NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY user_idx (user_id),
		KEY status_idx (status)
	) $charset;" );

	/* NEW — full conversation thread for each request/ticket above.
	   sender: 'user' or 'admin'. seen: whether the OTHER party has read it
	   (a user message's "seen" tracks whether admin has read it, and vice
	   versa — each side only ever marks the other side's messages). */
	dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shat_request_messages (
		id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		request_id  bigint(20) UNSIGNED NOT NULL,
		sender      varchar(10)          NOT NULL DEFAULT 'user',
		message     text                 NOT NULL,
		seen        tinyint(1)           NOT NULL DEFAULT 0,
		created_at  datetime             NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY request_idx (request_id)
	) $charset;" );

	/* NEW — NOWPayments crypto payment tracking. One row per invoice created.
	   status mirrors NOWPayments' own payment_status values (waiting,
	   confirming, confirmed, finished, failed, expired, refunded) plus our
	   own 'pending' as the initial state before NOWPayments has responded.
	   type mirrors shat_access_requests.type ('access'/'renew'/'unlock') so
	   we know exactly what to grant once payment is confirmed. */
	dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}shat_payments (
		id            bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id       bigint(20) UNSIGNED NOT NULL,
		type          varchar(20)          NOT NULL DEFAULT 'access',
		photo_id      bigint(20) UNSIGNED NOT NULL DEFAULT 0,
		request_id    bigint(20) UNSIGNED NOT NULL DEFAULT 0,
		nowpay_id     varchar(64)          NOT NULL DEFAULT '',
		order_id      varchar(64)          NOT NULL DEFAULT '',
		price_amount  decimal(18,8)        NOT NULL DEFAULT 0,
		price_currency varchar(10)         NOT NULL DEFAULT 'usd',
		pay_amount    decimal(18,8)        NOT NULL DEFAULT 0,
		pay_currency  varchar(20)          NOT NULL DEFAULT '',
		status        varchar(20)          NOT NULL DEFAULT 'pending',
		invoice_url   text                 NULL,
		created_at    datetime             NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at    datetime             NULL,
		PRIMARY KEY (id),
		UNIQUE KEY nowpay_idx (nowpay_id),
		KEY user_idx (user_id),
		KEY status_idx (status)
	) $charset;" );
}

/* Defensive safety net: guarantees both shat_access_requests (with its reply
   columns) and shat_request_messages exist, independent of whether the
   init-hook version-upgrade ever actually ran on this install (e.g. due to
   caching, or the file being uploaded without a following page load reaching
   that hook in time). All operations here are idempotent and safe to call
   as often as needed — they only add what's missing and never touch
   existing data. */
function shat_ensure_access_requests_table() {
	global $wpdb;
	static $checked = false;
	if ( $checked ) return; // only actually hit the DB once per request
	$checked = true;

	$tbl  = $wpdb->prefix . 'shat_access_requests';
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM $tbl" );
	if ( $wpdb->last_error || $cols === null || empty( $cols ) ) {
		// Table(s) don't exist at all yet — run full setup, which creates
		// every shat_* table (all calls are CREATE TABLE IF NOT EXISTS, so
		// this can never damage anything that already exists).
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		shat_run_db_setup();
		return;
	}
	if ( ! in_array( 'admin_reply', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE $tbl ADD COLUMN admin_reply text NULL" );
	}
	if ( ! in_array( 'replied_at', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE $tbl ADD COLUMN replied_at datetime NULL" );
	}
	if ( ! in_array( 'reply_seen', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE $tbl ADD COLUMN reply_seen tinyint(1) NOT NULL DEFAULT 0" );
	}

	$msg_tbl  = $wpdb->prefix . 'shat_request_messages';
	$msg_cols = $wpdb->get_col( "SHOW COLUMNS FROM $msg_tbl" );
	if ( $wpdb->last_error || $msg_cols === null || empty( $msg_cols ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE IF NOT EXISTS $msg_tbl (
			id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id  bigint(20) UNSIGNED NOT NULL,
			sender      varchar(10)          NOT NULL DEFAULT 'user',
			message     text                 NOT NULL,
			seen        tinyint(1)           NOT NULL DEFAULT 0,
			created_at  datetime             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY request_idx (request_id)
		) $charset;" );
	}

	$pay_tbl  = $wpdb->prefix . 'shat_payments';
	$pay_cols = $wpdb->get_col( "SHOW COLUMNS FROM $pay_tbl" );
	if ( $wpdb->last_error || $pay_cols === null || empty( $pay_cols ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE IF NOT EXISTS $pay_tbl (
			id             bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id        bigint(20) UNSIGNED NOT NULL,
			type           varchar(20)          NOT NULL DEFAULT 'access',
			photo_id       bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			request_id     bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			nowpay_id      varchar(64)          NOT NULL DEFAULT '',
			order_id       varchar(64)          NOT NULL DEFAULT '',
			price_amount   decimal(18,8)        NOT NULL DEFAULT 0,
			price_currency varchar(10)          NOT NULL DEFAULT 'usd',
			pay_amount     decimal(18,8)        NOT NULL DEFAULT 0,
			pay_currency   varchar(20)          NOT NULL DEFAULT '',
			status         varchar(20)          NOT NULL DEFAULT 'pending',
			invoice_url    text                 NULL,
			created_at     datetime             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at     datetime             NULL,
			PRIMARY KEY (id),
			UNIQUE KEY nowpay_idx (nowpay_id),
			KEY user_idx (user_id),
			KEY status_idx (status)
		) $charset;" );
	}
}


// ============================================================
// ACTIVATION — tables + pages
// ============================================================
register_activation_hook( __FILE__, 'shat_activate' );
function shat_activate() {
	shat_run_db_setup();

	$pages = [
		[ 'title' => 'Login',          'slug' => 'shatania-login',    'sc' => '[shatania_login]' ],
		[ 'title' => 'Join Us',        'slug' => 'shatania-register', 'sc' => '[shatania_register]' ],
		[ 'title' => 'Feed',           'slug' => 'shatania-feed',     'sc' => '[shatania_feed]' ],
		[ 'title' => 'Reset Password', 'slug' => 'shatania-reset',    'sc' => '[shatania_reset]' ],
		[ 'title' => 'Support Shatania', 'slug' => 'shatania-join',   'sc' => '[shatania_join]' ],
	];
	foreach ( $pages as $p ) {
		if ( ! get_page_by_path( $p['slug'] ) ) {
			wp_insert_post( [
				'post_title'   => $p['title'],
				'post_content' => $p['sc'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_name'    => $p['slug'],
			] );
		}
	}
	$legacy = get_page_by_path( 'shatania-profile' );
	if ( $legacy ) wp_delete_post( $legacy->ID, true );

	/* Stamp the moment OTP verification went live. Every account that
	   existed before this instant is grandfathered in as verified. */
	if ( ! get_option( 'shat_otp_cutover' ) ) add_option( 'shat_otp_cutover', time() );

	update_option( 'shat_db_ver', SHAT_VER );
	flush_rewrite_rules();
}

// ============================================================
// INIT — CPT + version-upgrade check + create reset page if missing
// ============================================================
add_action( 'init', 'shat_init' );
function shat_init() {
	register_post_type( 'shat_photo', [
		'labels'       => [ 'name' => 'Shatania Photos', 'singular_name' => 'Photo' ],
		'public'       => false,
		'show_ui'      => false,
		'show_in_menu' => false,
		'supports'     => [ 'title', 'thumbnail', 'custom-fields' ],
		'rewrite'      => false,
	] );

	/* Auto-upgrade DB on file-only plugin updates (no reactivation needed). */
	if ( get_option( 'shat_db_ver' ) !== SHAT_VER ) {
		shat_run_db_setup();
		update_option( 'shat_db_ver', SHAT_VER );
	}

	/* Create the reset page if it doesn't exist yet (for existing installs). */
	if ( ! get_page_by_path( 'shatania-reset' ) ) {
		wp_insert_post( [
			'post_title'   => 'Reset Password',
			'post_content' => '[shatania_reset]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_name'    => 'shatania-reset',
		] );
	}

	/* Create the Patreon conversion page if it doesn't exist yet (for existing installs). */
	if ( ! get_page_by_path( 'shatania-join' ) ) {
		wp_insert_post( [
			'post_title'   => 'Support Shatania',
			'post_content' => '[shatania_join]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_name'    => 'shatania-join',
		] );
	}
}

// ============================================================
// ENQUEUE
// ============================================================
add_action( 'wp_enqueue_scripts', 'shat_enqueue_front' );
function shat_enqueue_front() {
	wp_enqueue_script( 'jquery' );
	wp_enqueue_style( 'shat-fonts',
		'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Sen:wght@400;500;700;800&family=Zilla+Slab:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&display=swap',
		[], null );
	if ( current_user_can( 'manage_options' ) ) wp_enqueue_media();
	wp_localize_script( 'jquery', 'shatAjax', [
		'ajaxurl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'shat_nonce' ),
	] );
}

add_action( 'admin_enqueue_scripts', 'shat_enqueue_admin' );
function shat_enqueue_admin() {
	wp_enqueue_script( 'jquery' );
	wp_enqueue_media();
	wp_enqueue_style( 'shat-fonts-admin',
		'https://fonts.googleapis.com/css2?family=Zilla+Slab:ital,wght@0,300;0,400;0,500;0,600;1,300&display=swap',
		[], null );
}


/* ============================================================
   SHAPE-SAFE FOCUS / TAP FEEDBACK  (site-wide)
   ------------------------------------------------------------
   Fixes the square box that appeared around round & pill buttons
   on tap/click. Three separate causes, all handled here:

     1. -webkit-tap-highlight-color — mobile browsers (Samsung
        Internet, older Chrome/Android, some in-app webviews)
        paint this over the element's *border box* and ignore
        border-radius, so a circular button flashes a square.
     2. Pointer focus rings — a mouse click leaves :focus on the
        element; any theme rule hanging off :focus (Kadence, WP
        core, Gutenberg) then stays painted after the click.
     3. Square wrappers — the ring is drawn on a wrapper
        (.wp-block-button, .kb-btn-wrap, a nav <li>) that has no
        border-radius of its own, while the round shape lives on
        the child.

   Keyboard focus is deliberately NOT removed: :focus-visible
   still draws a ring, as an outline with an offset, which every
   current browser renders following the element's own
   border-radius. We never set border-radius on focus — doing so
   deforms square buttons the moment they are focused.

   Printed late (priority 999) so it lands after the theme and
   Kadence Blocks inline CSS.
   ============================================================ */
add_action( 'wp_head', 'shat_shape_safe_focus_css', 999 );
function shat_shape_safe_focus_css() {

	// Every clickable thing on the site, in one list.
	$c = 'a, button, summary, label, [role="button"], [role="link"], [role="tab"], '
	   . 'input[type="button"], input[type="submit"], input[type="reset"], '
	   . '.button, .wp-block-button__link, .wp-element-button, '
	   . '.kb-button, .kt-button, .kadence-button, .kb-buttons-wrap a, '
	   . '.menu-toggle-open, .menu-toggle-close, .search-toggle-open, '
	   . '.shat-btn, .cta-btn, .btn-reset, .shat-like-btn, .shat-save-btn, '
	   . '.schat-fab, .schat-icon-btn, .schat-send-btn';

	// Same list, but only the ones that are genuinely button-shaped —
	// used for the overlay rule, which we keep off plain text links.
	$b = 'button, [role="button"], input[type="button"], input[type="submit"], '
	   . 'input[type="reset"], .button, .wp-block-button__link, .wp-element-button, '
	   . '.kb-button, .kt-button, .kadence-button, .shat-btn, .cta-btn, '
	   . '.schat-fab, .schat-icon-btn, .schat-send-btn';

	$css = "
	/* 1. No rectangular tap-highlight on touch. */
	html { -webkit-tap-highlight-color: transparent; }
	{$c}, input, select, textarea, [tabindex] {
		-webkit-tap-highlight-color: transparent;
	}

	/* 2. Mouse / finger: no ring left behind after the click. */
	:is({$c}):focus:not(:focus-visible) {
		outline: 0 !important;
	}

	/* 3. Keyboard: ring follows the element's real shape.
	      (No border-radius here — on purpose.) */
	:is({$c}):focus-visible {
		outline: 2px solid var(--shat-focus-ring, #96505C) !important;
		outline-offset: 3px !important;
	}

	/* 4. Firefox's private inner focus border is always square. */
	:is({$c})::-moz-focus-inner {
		border: 0 !important;
		padding: 0 !important;
	}

	/* 5. Hover / press overlays drawn as pseudo-elements inherit the
	      button's curve instead of filling its square border box. */
	:is({$b})::before,
	:is({$b})::after {
		border-radius: inherit;
	}

	/* 6. Square wrappers never draw the ring — the child does. */
	.wp-block-button:focus-within,
	.wp-block-button__width-auto:focus-within,
	.kb-btn-wrap:focus-within,
	.kb-buttons-wrap:focus-within,
	.wp-block-buttons:focus-within,
	.cta-row:focus-within,
	.nav-button:focus-within,
	li.menu-item:focus-within {
		outline: 0 !important;
		box-shadow: none !important;
	}

	";

	// Collapse the indentation we used for readability.
	echo "<style id='shat-shape-safe-focus'>"
	   . preg_replace( '/\s*\n\s*/', "\n", trim( $css ) )
	   . "</style>\n";
}
// ============================================================
// HELPERS
// ============================================================
function shat_page_url( $slug ) {
	$p = get_page_by_path( $slug );
	return $p ? get_permalink( $p->ID ) : home_url( '/' . $slug );
}

function shat_is_approved( $uid = 0 ) {
	if ( ! $uid ) $uid = get_current_user_id();
	if ( ! $uid ) return false;
	$u = get_userdata( $uid );
	if ( $u && in_array( 'administrator', (array) $u->roles ) ) return true;
	return get_user_meta( $uid, 'shat_status', true ) === 'approved';
}

/* ============================================================
   MANUAL-APPROVAL TOGGLE (NEW)
   ------------------------------------------------------------
   Controls what happens the moment a new signup finishes OTP email
   verification:

     • ON  (default): the account is created as 'pending' and YOU approve
                      it from the Members page before they can enter. This
                      is the original, cautious behaviour.

     • OFF:          the OTP-verified account is auto-approved instantly.
                      They see the feed's "download the app" screen right
                      away, install the app, and get in — no waiting on you.

   Downstream is unchanged either way: approved + in-app = feed access +
   push. The only thing this flips is whether a human gate sits in between.
   Stored in the 'shat_require_approval' option ('1' = manual, '0' = auto).
   ============================================================ */
function shat_require_approval() {
	// Default to '1' (manual approval) so upgrading an existing install
	// never suddenly opens the doors without the admin opting in.
	$v = get_option( 'shat_require_approval', '1' );
	return $v === '' ? true : ( $v !== '0' );
}

/* ============================================================
   GENDER (NEW in 2.3.0)
   Optional self-reported field, collected at registration and shown
   as a breakdown on the admin Members page. Stored in the
   'shat_gender' user meta. Anything unknown normalises to
   'unspecified', so the admin counts always sum to the member total
   — including everyone who registered before this field existed.
   ============================================================ */

function shat_gender_options() {
	return [
		''            => 'Prefer not to say',
		'female'      => 'Female',
		'male'        => 'Male',
		'other'       => 'Other',
	];
}

function shat_sanitize_gender( $raw ) {
	$raw = sanitize_key( (string) $raw );
	return in_array( $raw, [ 'female', 'male', 'other' ], true ) ? $raw : 'unspecified';
}

/* Human label for a stored value. */
function shat_gender_label( $val ) {
	switch ( $val ) {
		case 'female': return 'Female';
		case 'male':   return 'Male';
		case 'other':  return 'Other';
		default:       return 'Not specified';
	}
}

function shat_get_gender( $uid ) {
	return shat_sanitize_gender( get_user_meta( $uid, 'shat_gender', true ) );
}

function shat_get_status( $uid = 0 ) {
	if ( ! $uid ) $uid = get_current_user_id();
	if ( ! $uid ) return 'guest';
	$u = get_userdata( $uid );
	if ( $u && in_array( 'administrator', (array) $u->roles ) ) return 'approved';
	return get_user_meta( $uid, 'shat_status', true ) ?: 'pending';
}

/**
 * Feed access is a SEPARATE, time-boxed gate on top of portal approval.
 * A user can be portal-approved (can log in) but have their feed access
 * expired (sees a locked/blurred feed instead of the pending page).
 *
 * shat_feed_expiry user meta holds a MySQL datetime string.
 * Empty/missing = unlimited access (no expiry) as long as portal-approved.
 */
/* ============================================================
   CANDY STREAK
   ------------------------------------------------------------
   Spend SHAT_STREAK_MINUTES of active time on the feed in a day
   and you earn that day's candy. Miss a day and the streak resets
   to zero — same contract as Snapchat.

   Everything lives in user meta, keyed by the *user's own* local
   date (sent from the browser) so someone in IST isn't judged
   against UTC midnight:
     shat_streak_count      int    consecutive days earned
     shat_streak_last_day   Y-m-d  last day a candy was earned
     shat_streak_best       int    longest run ever
     shat_streak_secs_day   Y-m-d  day the seconds counter refers to
     shat_streak_secs       int    seconds accumulated that day
   ============================================================ */
define( 'SHAT_STREAK_MINUTES', 5 );

/** True once the feed shortcode has rendered on this request. */
function shat_is_feed_page() {
	return ! empty( $GLOBALS['shat_feed_rendered'] );
}

/** Wrapped-sweet icon, shared by the pill, sheet and toast. */
function shat_candy_svg() {
	return '<svg viewBox="0 0 32 32" fill="none" aria-hidden="true">'
		. '<defs><linearGradient id="shatCandyG" x1="7" y1="8" x2="25" y2="24" gradientUnits="userSpaceOnUse">'
		. '<stop stop-color="#F2C3CE"/><stop offset="1" stop-color="#C96A78"/></linearGradient></defs>'
		. '<path d="M9.6 16 3.9 11.6a.6.6 0 0 0-1 .47v7.86a.6.6 0 0 0 1 .47L9.6 16z" fill="url(#shatCandyG)" opacity=".62"/>'
		. '<path d="M22.4 16l5.7-4.4a.6.6 0 0 1 1 .47v7.86a.6.6 0 0 1-1 .47L22.4 16z" fill="url(#shatCandyG)" opacity=".62"/>'
		. '<circle cx="16" cy="16" r="7" fill="url(#shatCandyG)"/>'
		. '<path d="M12.9 13.5c1.7-1.2 4.5-1.2 6.2 0" stroke="#fff" stroke-width="1.5" '
		. 'stroke-linecap="round" opacity=".55" fill="none"/>'
		. '</svg>';
}

/** Normalise a browser-supplied Y-m-d, falling back to site time. */
function shat_streak_day( $raw = '' ) {
	$raw = trim( (string) $raw );
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
		// Guard against a spoofed far-future date being used to bank days.
		$ts = strtotime( $raw . ' 12:00:00' );
		if ( $ts && abs( $ts - time() ) < 2 * DAY_IN_SECONDS ) return $raw;
	}
	return current_time( 'Y-m-d' );
}

/** Current streak state for a user, self-healing if a day was missed. */
function shat_streak_get( $uid, $today = '' ) {
	$today = shat_streak_day( $today );
	$count = (int) get_user_meta( $uid, 'shat_streak_count', true );
	$last  = (string) get_user_meta( $uid, 'shat_streak_last_day', true );
	$best  = (int) get_user_meta( $uid, 'shat_streak_best', true );

	// Broken? Anything older than yesterday means a day was skipped.
	if ( $count > 0 && $last ) {
		$yesterday = date( 'Y-m-d', strtotime( $today . ' -1 day' ) );
		if ( $last !== $today && $last !== $yesterday ) {
			$count = 0;
			update_user_meta( $uid, 'shat_streak_count', 0 );
		}
	}

	// Seconds accumulated today (reset when the day rolls over).
	$secs_day = (string) get_user_meta( $uid, 'shat_streak_secs_day', true );
	$secs     = ( $secs_day === $today ) ? (int) get_user_meta( $uid, 'shat_streak_secs', true ) : 0;

	$goal = SHAT_STREAK_MINUTES * 60;
	return [
		'count'        => $count,
		'best'         => max( $best, $count ),
		'earned_today' => ( $last === $today ),
		'seconds'      => min( $secs, $goal ),
		'goal_seconds' => $goal,
		'minutes'      => SHAT_STREAK_MINUTES,
		'last_day'     => $last,
	];
}

/**
 * Add watched seconds and award the candy once the goal is reached.
 * Returns the fresh state plus 'just_earned' when this call tipped it over.
 */
function shat_streak_add_seconds( $uid, $add, $today = '' ) {
	$today = shat_streak_day( $today );
	$add   = max( 0, min( 120, (int) $add ) ); // clamp: one ping can't bank more than 2 min

	$state = shat_streak_get( $uid, $today );
	if ( $state['earned_today'] ) {
		$state['just_earned'] = false;
		return $state;
	}

	$secs_day = (string) get_user_meta( $uid, 'shat_streak_secs_day', true );
	$secs     = ( $secs_day === $today ) ? (int) get_user_meta( $uid, 'shat_streak_secs', true ) : 0;
	$secs    += $add;

	update_user_meta( $uid, 'shat_streak_secs_day', $today );
	update_user_meta( $uid, 'shat_streak_secs', $secs );

	$goal        = SHAT_STREAK_MINUTES * 60;
	$just_earned = false;

	if ( $secs >= $goal ) {
		$last      = (string) get_user_meta( $uid, 'shat_streak_last_day', true );
		$yesterday = date( 'Y-m-d', strtotime( $today . ' -1 day' ) );
		// Continue the run only when yesterday was earned; otherwise start at 1.
		$count = ( $last === $yesterday ) ? ( (int) get_user_meta( $uid, 'shat_streak_count', true ) + 1 ) : 1;

		update_user_meta( $uid, 'shat_streak_count', $count );
		update_user_meta( $uid, 'shat_streak_last_day', $today );
		$best = (int) get_user_meta( $uid, 'shat_streak_best', true );
		if ( $count > $best ) update_user_meta( $uid, 'shat_streak_best', $count );

		$just_earned = true;
	}

	$state = shat_streak_get( $uid, $today );
	$state['just_earned'] = $just_earned;
	return $state;
}

/** AJAX: heartbeat from the feed — banks watched time, returns streak state. */
add_action( 'wp_ajax_shat_streak_ping', 'shat_ajax_streak_ping' );
function shat_ajax_streak_ping() {
	check_ajax_referer( 'shat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );

	$uid   = get_current_user_id();
	$add   = isset( $_POST['seconds'] ) ? intval( $_POST['seconds'] ) : 0;
	$today = isset( $_POST['day'] ) ? sanitize_text_field( wp_unslash( $_POST['day'] ) ) : '';

	wp_send_json_success( shat_streak_add_seconds( $uid, $add, $today ) );
}

function shat_has_feed_access( $uid = 0 ) {
	if ( ! $uid ) $uid = get_current_user_id();
	if ( ! $uid ) return false;
	$u = get_userdata( $uid );
	if ( $u && in_array( 'administrator', (array) $u->roles ) ) return true;

	$expiry = get_user_meta( $uid, 'shat_feed_expiry', true );
	if ( ! $expiry ) return true; // no expiry set = unlimited access
	return strtotime( $expiry ) > current_time( 'timestamp' );
}

/** Human-friendly feed access status for admin display: 'unlimited' | 'active' | 'expired' */
function shat_feed_access_state( $uid ) {
	$expiry = get_user_meta( $uid, 'shat_feed_expiry', true );
	if ( ! $expiry ) return 'unlimited';
	return ( strtotime( $expiry ) > current_time( 'timestamp' ) ) ? 'active' : 'expired';
}

function shat_photo_likes( $pid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}shat_likes WHERE photo_id=%d", $pid ) );
}

function shat_photo_views( $pid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}shat_views WHERE photo_id=%d", $pid ) );
}

/* How many DIFFERENT people saw this photo, as opposed to how many times
   it was seen. Both numbers are useful: the feed shows total views, the
   admin Activity page shows both so a high count from one obsessive
   viewer is distinguishable from broad reach. */
function shat_photo_unique_views( $pid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT viewer_hash) FROM {$wpdb->prefix}shat_views WHERE photo_id=%d", $pid ) );
}

function shat_user_liked( $pid, $uid = 0 ) {
	global $wpdb;
	if ( ! $uid ) $uid = get_current_user_id();
	if ( ! $uid ) return false;
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}shat_likes WHERE photo_id=%d AND user_id=%d", $pid, $uid ) );
}

/* Record one view. Repeat visits DO count — that is the point of a view
   counter — but a short cooldown stops a single page render, a quick
   back-and-forward, or a double-fired AJAX call from inflating it.

   The hash identifies a viewer without storing their IP: it is
   ip + user-agent, with no date component (the date used to be baked in,
   which is what limited each viewer to one view per photo per day). */
function shat_record_view( $pid ) {
	global $wpdb;

	$pid = intval( $pid );
	if ( ! $pid ) return;

	/* Do not count your own visits. Browsing your feed as admin would
	   otherwise be the largest source of views on a young site, making
	   the number meaningless. */
	if ( current_user_can( 'manage_options' ) ) return;

	$ip   = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
	$ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
	$uid  = get_current_user_id();
	$hash = md5( $ip . '|' . $ua );

	/* Cooldown: ignore a repeat view of the same photo by the same viewer
	   within this many seconds. 30 minutes is long enough to absorb
	   refreshes and tab-switching, short enough that genuinely coming
	   back later still counts. */
	$cooldown = (int) apply_filters( 'shat_view_cooldown_seconds', 30 * MINUTE_IN_SECONDS );

	$recent = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}shat_views
		  WHERE photo_id = %d AND viewer_hash = %s
		    AND viewed_at > DATE_SUB(NOW(), INTERVAL %d SECOND)
		  LIMIT 1",
		$pid, $hash, $cooldown
	) );
	if ( $recent ) return;

	$wpdb->insert(
		$wpdb->prefix . 'shat_views',
		[ 'photo_id' => $pid, 'user_id' => $uid, 'viewer_hash' => $hash ],
		[ '%d', '%d', '%s' ]
	);
}

function shat_fmt( $n ) {
	if ( $n >= 1000000 ) return round( $n / 1000000, 1 ) . 'M';
	if ( $n >= 1000 )    return round( $n / 1000,    1 ) . 'K';
	return (string) $n;
}

/**
 * Every outgoing email goes through here. Returns wp_mail()'s own boolean so
 * callers can react when sending fails — previously the result was thrown
 * away, so a site with no working mail transport looked identical to one
 * that had just sent a message successfully.
 */
function shat_mail( $to, $subject, $html_body ) {
	$headers = [
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . SHAT_FROM_NAME . ' <' . SHAT_FROM_EMAIL . '>',
		'Reply-To: ' . SHAT_FROM_NAME . ' <' . SHAT_FROM_EMAIL . '>',
	];
	$ok = wp_mail( $to, $subject, $html_body, $headers );
	if ( ! $ok ) {
		error_log( '[Shatania Mail] wp_mail() returned false for ' . $to . ' — subject: ' . $subject );
	}
	return $ok;
}

/**
 * Route WordPress mail through a real SMTP server.
 *
 * Without this, WordPress hands messages to PHP's mail(), which sends
 * unauthenticated mail straight from the web server. Gmail, Outlook and
 * Apple Mail discard that silently — not bounce it, discard it — which is
 * exactly what a "sign-in code never arrives" report looks like.
 *
 * Credentials come from wp-config.php constants when present (preferable,
 * since they stay out of the database), otherwise from the settings saved on
 * Shatania -> Email Check.
 */
function shat_smtp_config() {
	$opt = get_option( 'shat_smtp', [] );

	return [
		'enabled' => defined( 'SHAT_SMTP_HOST' ) ? true : ! empty( $opt['enabled'] ),
		'host'    => defined( 'SHAT_SMTP_HOST' ) ? SHAT_SMTP_HOST : ( $opt['host'] ?? '' ),
		'port'    => defined( 'SHAT_SMTP_PORT' ) ? intval( SHAT_SMTP_PORT ) : intval( $opt['port'] ?? 587 ),
		'secure'  => defined( 'SHAT_SMTP_SECURE' ) ? SHAT_SMTP_SECURE : ( $opt['secure'] ?? 'tls' ),
		'user'    => defined( 'SHAT_SMTP_USER' ) ? SHAT_SMTP_USER : ( $opt['user'] ?? '' ),
		'pass'    => defined( 'SHAT_SMTP_PASS' ) ? SHAT_SMTP_PASS : ( $opt['pass'] ?? '' ),
	];
}

add_action( 'phpmailer_init', 'shat_apply_smtp', 20 );
function shat_apply_smtp( $phpmailer ) {
	$c = shat_smtp_config();
	if ( empty( $c['enabled'] ) || empty( $c['host'] ) ) return;

	$phpmailer->isSMTP();
	$phpmailer->Host = $c['host'];
	$phpmailer->Port = $c['port'] ?: 587;

	if ( $c['secure'] === 'none' ) {
		$phpmailer->SMTPSecure  = '';
		$phpmailer->SMTPAutoTLS = false;
	} else {
		$phpmailer->SMTPSecure = $c['secure']; // 'tls' or 'ssl'
	}

	$phpmailer->SMTPAuth = ! empty( $c['user'] );
	if ( $phpmailer->SMTPAuth ) {
		$phpmailer->Username = $c['user'];
		$phpmailer->Password = $c['pass'];
	}

	/* Keep the envelope sender aligned with the From header. A mismatch is
	   what trips SPF at the receiving end even when the DNS records are
	   correct. */
	$phpmailer->From     = SHAT_FROM_EMAIL;
	$phpmailer->FromName = SHAT_FROM_NAME;
	$phpmailer->Sender   = SHAT_FROM_EMAIL;

	$phpmailer->Timeout = 15;
}

/**
 * PHPMailer's real reason for a failure, stashed so the admin screen can
 * show it. Without this the only symptom is an email that never arrives.
 */
add_action( 'wp_mail_failed', 'shat_capture_mail_error' );
function shat_capture_mail_error( $wp_error ) {
	$msg = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : 'Unknown mail error';
	update_option( 'shat_last_mail_error', [ 'time' => time(), 'msg' => $msg ], false );
	error_log( '[Shatania Mail] ' . $msg );
}

function shat_email_wrap( $heading, $body ) {
	return '<!DOCTYPE html><html><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{margin:0;padding:0;background:#F5F5F5;font-family:Georgia,serif;}
.wrap{max-width:540px;margin:0 auto;padding:40px 20px;}
.card{background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 4px 40px rgba(0,0,0,.07);}
.top{background:#1F1F1F;padding:28px 36px;text-align:center;}
.logo{color:#fff;font-size:22px;letter-spacing:-.3px;}.logo span{color:#D9A9AC;}
.body{padding:36px;}h2{font-size:20px;font-weight:500;color:#1F1F1F;margin:0 0 18px;letter-spacing:-.3px;}
p{font-size:14px;color:#444;line-height:1.7;margin:0 0 14px;}
.btn{display:inline-block;padding:13px 26px;background:#1F1F1F;color:#fff !important;text-decoration:none;border-radius:10px;font-size:14px;margin-top:6px;}
.foot{background:#F8F8F8;padding:20px 36px;text-align:center;border-top:1px solid #F0F0F0;}
.foot p{font-size:12px;color:#999;margin:0;}.foot a{color:#D9A9AC;text-decoration:none;}
</style></head><body>
<div class="wrap"><div class="card">
<div class="top"><div class="logo">Shatania<span>.</span></div></div>
<div class="body"><h2>' . $heading . '</h2>' . $body . '</div>
<div class="foot"><p>&copy; ' . date('Y') . ' Shatania &middot; <a href="https://shataniaveyra.com">shataniaveyra.com</a></p></div>
</div></div></body></html>';
}

function shat_get_approved_users() {
	return get_users( [
		'meta_key'     => 'shat_status',
		'meta_value'   => 'approved',
		'role__not_in' => [ 'administrator' ],
		'number'       => 500,
	] );
}

/* Audience-aware feed query (2.3.0).

   Posts restricted to specific members must not count toward another
   member's page size, otherwise page 2 would skip real posts. So we
   over-fetch in batches, filter each batch through the audience check,
   and only then apply the offset. $skip walks the *filtered* stream.

   Admins bypass filtering entirely (shat_user_can_view_post), so this
   costs them nothing but one extra pass over the array. */
function shat_get_photos( $page = 1, $per = 10 ) {
	$uid   = get_current_user_id();
	$out   = [];
	$skip  = max( 0, ( $page - 1 ) * $per );
	$seen  = 0;
	$batch = 1;

	while ( count( $out ) < $per && $batch <= 20 ) {
		$raw = get_posts( [
			'post_type'      => 'shat_photo',
			'posts_per_page' => $per * 3,
			'paged'          => $batch,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );
		if ( empty( $raw ) ) break;

		foreach ( shat_filter_visible_posts( $raw, $uid ) as $p ) {
			if ( $seen++ < $skip ) continue;
			$out[] = $p;
			if ( count( $out ) >= $per ) break 2;
		}
		$batch++;
	}
	return $out;
}

function shat_count_photos() {
	$c = wp_count_posts( 'shat_photo' );
	return (int) ( $c->publish ?? 0 );
}

/* --- Blur / unlock helpers (NEW in 1.3.0) --- */

function shat_is_blurred( $pid ) {
	return (bool) get_post_meta( $pid, '_shat_blurred', true );
}

function shat_user_unlocked( $pid, $uid = 0 ) {
	global $wpdb;
	if ( ! $uid ) $uid = get_current_user_id();
	if ( ! $uid ) return false;
	/* Admins always see full images. */
	$u = get_userdata( $uid );
	if ( $u && in_array( 'administrator', (array) $u->roles ) ) return true;
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}shat_unlocked WHERE photo_id=%d AND user_id=%d",
		$pid, $uid ) );
}

/* ============================================================
   AUDIENCE TARGETING + PATREON EXCLUSIVE  (NEW in 2.3.0)

   Per-photo meta:
     _shat_audience      'all' | 'selected'
     _shat_audience_ids  array of user IDs (only when 'selected')
     _shat_patreon       '1' when the post is Patreon-exclusive

   Audience controls VISIBILITY (post vanishes from other feeds).
   Patreon controls LOCKING (post stays visible but shows a lock
   card with a "Visit Patreon" button). They compose: a post can be
   both restricted to a few members AND Patreon-locked.

   Posts created before this version have no _shat_audience meta and
   fall back to 'all', so nothing needs migrating.
   ============================================================ */

function shat_is_patreon( $pid ) {
	return (bool) get_post_meta( $pid, '_shat_patreon', true );
}

function shat_get_audience( $pid ) {
	$a = get_post_meta( $pid, '_shat_audience', true );
	return in_array( $a, [ 'all', 'selected' ], true ) ? $a : 'all';
}

function shat_get_audience_ids( $pid ) {
	$ids = get_post_meta( $pid, '_shat_audience_ids', true );
	return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
}

/* Can this user SEE the post in their feed at all?
   Admins always yes. Patreon-exclusive posts stay visible to everyone
   (they render the Patreon lock card) — only 'selected' hides a post. */
function shat_user_can_view_post( $pid, $uid = 0 ) {
	if ( ! $uid ) $uid = get_current_user_id();
	$u = $uid ? get_userdata( $uid ) : null;
	if ( $u && in_array( 'administrator', (array) $u->roles, true ) ) return true;

	if ( shat_get_audience( $pid ) === 'selected' ) {
		if ( ! $uid ) return false;
		return in_array( (int) $uid, shat_get_audience_ids( $pid ), true );
	}
	return true;
}

/* Filter a list of WP_Post objects down to what this user may see. */
function shat_filter_visible_posts( $posts, $uid = 0 ) {
	if ( ! $uid ) $uid = get_current_user_id();
	return array_values( array_filter( $posts, function ( $p ) use ( $uid ) {
		return shat_user_can_view_post( $p->ID, $uid );
	} ) );
}

/* --- No-index ("Exclusive" photos hidden from Google) ---
   Sitewide noindex (everything except the home page) is now unconditional —
   see shat_wp_robots_home_only() etc. below — so it no longer depends on
   this flag. This per-photo flag now controls ONE thing: whether the photo
   is skipped from the /shatania-join/ marketing page's scrolling collage,
   which is the one place a real, un-gated image URL is embedded for
   logged-out visitors. shat_get_noindexed_attachment_ids() below is kept
   as a helper but is no longer read by any SEO filter. */

function shat_is_noindex( $pid ) {
	return (bool) get_post_meta( $pid, '_shat_noindex', true );
}

/* Every attachment ID (cover + full gallery) belonging to any shat_photo
   flagged no-index. Cached — cleared whenever a photo is published, edited,
   or deleted (see shat_clear_noindex_cache() calls at those points). */
function shat_get_noindexed_attachment_ids() {
	$cached = get_transient( 'shat_noindex_aids' );
	if ( is_array( $cached ) ) return $cached;

	$ids   = [];
	$pids  = get_posts( [
		'post_type'      => 'shat_photo',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'meta_key'       => '_shat_noindex',
		'meta_value'     => '1',
		'fields'         => 'ids',
	] );
	foreach ( $pids as $pid ) {
		$gallery = get_post_meta( $pid, '_shat_gallery_ids', true );
		if ( is_array( $gallery ) ) {
			foreach ( $gallery as $aid ) $ids[] = intval( $aid );
		}
		$thumb = get_post_thumbnail_id( $pid );
		if ( $thumb ) $ids[] = intval( $thumb );
	}
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	set_transient( 'shat_noindex_aids', $ids, HOUR_IN_SECONDS );
	return $ids;
}

function shat_clear_noindex_cache() {
	delete_transient( 'shat_noindex_aids' );
}

/* --- Favorites / Saved (private per-member, Instagram-Saved style) --- */

function shat_user_saved( $pid, $uid = 0 ) {
	global $wpdb;
	if ( ! $uid ) $uid = get_current_user_id();
	if ( ! $uid ) return false;
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}shat_favorites WHERE photo_id=%d AND user_id=%d", $pid, $uid ) );
}

function shat_get_saved_photos( $uid, $page = 1, $per = 30 ) {
	global $wpdb;
	$offset = max( 0, ( $page - 1 ) * $per );
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT photo_id FROM {$wpdb->prefix}shat_favorites WHERE user_id=%d ORDER BY saved_at DESC LIMIT %d OFFSET %d",
		$uid, $per, $offset ) );
	if ( empty( $ids ) ) return [];
	$ids = array_map( 'intval', $ids );
	$posts = get_posts( [
		'post_type'      => 'shat_photo',
		'post__in'       => $ids,
		'orderby'        => 'post__in', // preserve the saved_at DESC order from the favorites query
		'posts_per_page' => count( $ids ),
		'post_status'    => 'publish',
	] );
	return $posts;
}

function shat_count_saved( $uid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}shat_favorites WHERE user_id=%d", $uid ) );
}

/* --- Access Requests (in-app replacement for mailto: links) --- */

/* Returns the contact phrase as escaped HTML — the single place that
   formats it, so every screen (member sheet, feed ticket card, admin list)
   stays in sync. */
function shat_contact_line() {
	/* 2.3.0 — everything routes to the built-in chat now. Note this returns
	   escaped HTML for use mid-sentence ("Send a message to ..."), so it
	   deliberately has no trailing punctuation. Callers who need to know
	   whether the reader can actually *reach* the chat should check
	   schat_can_chat() first — pending members cannot (see below). */
	return 'me right here using the <strong>Message</strong> button';
}

function shat_request_type_label( $type ) {
	$labels = [
		'access' => 'Requesting account approval',
		'renew'  => 'Requesting feed renewal',
		'unlock' => 'Requesting photo unlock',
	];
	return $labels[ $type ] ?? 'Access request';
}

function shat_count_open_requests() {
	global $wpdb;
	return (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}shat_access_requests WHERE status='open'" );
}

function shat_count_in_progress_requests() {
	global $wpdb;
	return (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}shat_access_requests WHERE status='in_progress'" );
}

/* Requests belonging to the current user only — this is what makes the reply
   "private per member": every query here is scoped by user_id, so a person
   can never see another member's requests or replies, only their own. */
function shat_get_my_requests( $uid, $limit = 10 ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}shat_access_requests WHERE user_id=%d ORDER BY created_at DESC LIMIT %d",
		$uid, $limit ) );
}

function shat_count_unseen_replies( $uid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}shat_access_requests WHERE user_id=%d AND admin_reply IS NOT NULL AND reply_seen=0",
		$uid ) );
}

function shat_status_label( $status ) {
	$labels = [ 'open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved' ];
	return $labels[ $status ] ?? ucfirst( $status );
}

/* Mark a request resolved/open. Requests are a simple log: what was
   requested, by whom, when, and whether it's been handled. The live
   back-and-forth happens in the built-in chat (schat_*), not here. */
function shat_set_request_status( $request_id, $status ) {
	global $wpdb;
	$wpdb->update( $wpdb->prefix . 'shat_access_requests', [ 'status' => $status ], [ 'id' => $request_id ] );
}

/* Does this user already have an open/in_progress ticket of this exact type?
   Used to block duplicate requests — e.g. can't submit a second "renew"
   request while one is still being handled. For 'unlock' requests, pass the
   specific photo_id too — a member should be able to request unlock on a
   different photo even while one unlock request is already in progress; the
   duplicate-block only applies to the SAME photo. Returns the ticket row or
   null. */
function shat_get_active_request_of_type( $uid, $type, $photo_id = null ) {
	global $wpdb;
	if ( $photo_id !== null ) {
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}shat_access_requests
			 WHERE user_id=%d AND type=%s AND photo_id=%d AND status IN ('open','in_progress')
			 ORDER BY created_at DESC LIMIT 1",
			$uid, $type, $photo_id ) );
	}
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}shat_access_requests
		 WHERE user_id=%d AND type=%s AND status IN ('open','in_progress')
		 ORDER BY created_at DESC LIMIT 1",
		$uid, $type ) );
}

// ============================================================
// NOWPAYMENTS — crypto payment gateway (USDT/TRC20)
// ============================================================

/* Creates a NOWPayments invoice for a given request and returns the invoice
   URL the member should be sent to. Returns WP_Error on failure so the
   caller can show a sensible message rather than a blank/broken sheet. */
function shat_nowpay_create_invoice( $uid, $type, $photo_id = 0, $request_id = 0 ) {
	if ( ! SHAT_NOWPAY_API_KEY ) {
		return new WP_Error( 'nowpay_not_configured', 'Payments are not yet configured. Please try again later.' );
	}

	global $wpdb;
	$order_id = 'shat_' . $uid . '_' . $type . '_' . time();

	$body = [
		'price_amount'      => SHAT_NOWPAY_PRICE,
		'price_currency'    => 'usd',
		'pay_currency'      => SHAT_NOWPAY_CURRENCY,
		'order_id'          => $order_id,
		'order_description' => shat_request_type_label( $type ) . ' — ' . SHAT_SITE_NAME,
		'ipn_callback_url'  => admin_url( 'admin-ajax.php?action=shat_nowpay_ipn' ),
		'success_url'       => shat_page_url( 'shatania-feed' ),
		'cancel_url'        => shat_page_url( 'shatania-feed' ),
	];

	$response = wp_remote_post( 'https://api.nowpayments.io/v1/invoice', [
		'headers' => [
			'x-api-key'    => SHAT_NOWPAY_API_KEY,
			'Content-Type' => 'application/json',
		],
		'body'    => wp_json_encode( $body ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) return $response;

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code !== 200 && $code !== 201 ) {
		return new WP_Error( 'nowpay_api_error', $data['message'] ?? 'Payment provider error — please try again.' );
	}

	/* Record the pending payment so the IPN webhook has something to match
	   against and update once NOWPayments confirms it. */
	$wpdb->insert( $wpdb->prefix . 'shat_payments', [
		'user_id'        => $uid,
		'type'           => $type,
		'photo_id'       => $photo_id,
		'request_id'     => $request_id,
		'nowpay_id'      => (string) ( $data['id'] ?? '' ),
		'order_id'       => $order_id,
		'price_amount'   => SHAT_NOWPAY_PRICE,
		'price_currency' => 'usd',
		'pay_currency'   => SHAT_NOWPAY_CURRENCY,
		'status'         => 'pending',
		'invoice_url'    => $data['invoice_url'] ?? '',
	] );

	return $data['invoice_url'] ?? new WP_Error( 'nowpay_no_url', 'Could not create payment link.' );
}

/* Verifies that an incoming IPN request genuinely came from NOWPayments.
   NOWPayments signs the payload with HMAC-SHA512 using your IPN secret,
   sent in the x-nowpayments-sig header. The payload must be JSON-encoded
   with keys SORTED alphabetically before hashing — this exact ordering is
   required by NOWPayments' own spec, not optional. */
function shat_nowpay_verify_ipn( $raw_body, $signature ) {
	if ( ! SHAT_NOWPAY_IPN_SECRET || ! $signature ) return false;
	$data = json_decode( $raw_body, true );
	if ( ! is_array( $data ) ) return false;
	ksort( $data );
	$sorted_json = wp_json_encode( $data );
	$expected    = hash_hmac( 'sha512', $sorted_json, SHAT_NOWPAY_IPN_SECRET );
	return hash_equals( $expected, $signature );
}

/* Applies the actual access grant once a payment is confirmed — this is
   the single place that decides what "paid" means for each request type,
   so the IPN handler and any future manual "mark as paid" admin action
   both funnel through the same logic. */
function shat_grant_paid_access( $payment_row ) {
	$uid  = (int) $payment_row->user_id;
	$type = $payment_row->type;

	if ( $type === 'access' ) {
		update_user_meta( $uid, 'shat_status', 'approved' );
		update_user_meta( $uid, 'shat_approved_date', current_time( 'mysql' ) );
	} elseif ( $type === 'renew' ) {
		$new_expiry = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( SHAT_NOWPAY_ACCESS_DAYS * DAY_IN_SECONDS ) );
		update_user_meta( $uid, 'shat_feed_expiry', $new_expiry );
	} elseif ( $type === 'unlock' && $payment_row->photo_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}shat_unlocked (photo_id, user_id) VALUES (%d, %d)
			 ON DUPLICATE KEY UPDATE unlocked_at = unlocked_at",
			$payment_row->photo_id, $uid ) );
	}

	/* Auto-resolve the linked request ticket, if any — no thread message
	   needed since the chat system was removed; the payment record itself
	   is the audit trail. */
	if ( $payment_row->request_id ) {
		shat_ensure_access_requests_table();
		shat_set_request_status( $payment_row->request_id, 'resolved' );
	}

	$user = get_userdata( $uid );
	if ( $user ) {
		shat_mail( $user->user_email, 'Payment confirmed — access granted', shat_email_wrap( 'You\'re all set',
			'<p>Hi ' . esc_html( $user->first_name ?: $user->display_name ) . ',</p>
			<p>Your payment was confirmed and your access has been updated.</p>
			<p><a href="' . esc_url( shat_page_url( 'shatania-feed' ) ) . '" class="btn">Go to Shatania &rarr;</a></p>'
		) );
	}
}

// ============================================================
// BASE CSS — shared across all front-end pages
// ============================================================
function shat_base_css() { return '
<style>
@import url("https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Zilla+Slab:ital,wght@0,300;0,400;0,500;0,600;1,300&display=swap");
@import url("https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap");

:root{
  --bg:#141414; --card:#1C1C1C; --text:#F2F2F2; --sec:#9A9A9A;
  --gold:#D9A9AC; --gold-light:#E8C4C6; --gold-dark:#C08A8E; --btn:#F2F2F2; --btn-text:#141414;
  --line:rgba(255,255,255,.08);
  --font:"Zilla Slab",Georgia,serif;
  --display:"Cormorant Garamond",Georgia,serif;
  --display-card:"Sen","Zilla Slab",sans-serif;
  --r-card:20px; --r-input:13px; --r-btn:12px;
  --shadow-card:0 8px 40px rgba(0,0,0,.35);
  --shadow-dark:0 4px 40px rgba(0,0,0,.6);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
.shat-root{font-family:var(--font);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}

/* =========================================
   PROFILE PAGE FONT OVERRIDE — Inter typeface only.
   Color palette now lives in :root and is shared by every
   page (login/register/reset/pending/feed) for consistency.
   ========================================= */
.shat-feed-root{
  --font:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;
  --display:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;
  --display-card:"Inter",-apple-system,BlinkMacSystemFont,sans-serif;
  font-family:var(--font);
  -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
}
.shat-feed-root *{font-family:inherit;}

/* ===== AUTH PAGES ===== */
.shat-auth-page{
  min-height:100vh; background:var(--bg);
  display:flex; align-items:center; justify-content:center; padding:20px;
}
.shat-auth-box{
  background:var(--card); border-radius:20px; padding:36px 34px;
  width:100%; max-width:400px;
  border:1px solid var(--line);
  box-shadow:0 1px 3px rgba(0,0,0,.25);
}
.shat-auth-logo{text-align:center; margin-bottom:28px;}
.shat-auth-logo .mark{
  width:52px; height:52px; background:var(--btn); border-radius:50%;
  display:inline-flex; align-items:center; justify-content:center; margin-bottom:14px;
}
.shat-auth-logo .mark svg{width:23px;height:23px;fill:var(--btn-text);}
.shat-auth-logo h1{font-size:20px;font-weight:600;color:var(--text);letter-spacing:-.3px;margin-bottom:5px;}
.shat-auth-logo p{color:var(--sec);font-size:13.5px;font-weight:400;font-style:normal;}
.shat-field{margin-bottom:14px;}
.shat-field label{display:block;font-size:12px;font-weight:500;color:var(--sec);text-transform:none;letter-spacing:0;margin-bottom:6px;}
.shat-field input{
  width:100%; padding:12px 16px;
  border:1.5px solid rgba(255,255,255,.12); border-radius:12px;
  font-family:var(--font); font-size:15px;
  color:var(--text) !important;
  -webkit-text-fill-color:var(--text);
  caret-color:var(--gold);
  background:#101010; outline:none;
  transition:border-color .2s,box-shadow .2s,background .2s;
}
.shat-field input:focus{
  border-color:var(--gold);background:#181818;box-shadow:0 0 0 4px rgba(184,155,94,.14);
  color:var(--text) !important;
  -webkit-text-fill-color:var(--text);
}
.shat-field input::placeholder{color:#5C5C5C;opacity:1;}

/* Select — matches the inputs above, with a custom chevron because the
   native arrow cannot be recoloured on a dark background.
   NOTE: this whole block sits inside a single-quoted PHP string, so no
   apostrophes are allowed anywhere below, including in comments. */
.shat-select{
  width:100%; padding:12px 40px 12px 16px;
  border:1.5px solid rgba(255,255,255,.12); border-radius:12px;
  font-family:var(--font); font-size:15px;
  color:var(--text) !important;
  background:#101010; outline:none; cursor:pointer;
  -webkit-appearance:none; -moz-appearance:none; appearance:none;
  background-image:url("data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%20viewBox=%220%200%2024%2024%22%20fill=%22none%22%20stroke=%22%239A9A9A%22%20stroke-width=%222%22%20stroke-linecap=%22round%22%20stroke-linejoin=%22round%22%3E%3Cpolyline%20points=%226%209%2012%2015%2018%209%22/%3E%3C/svg%3E");
  background-repeat:no-repeat;
  background-position:right 14px center;
  background-size:16px 16px;
  transition:border-color .2s,box-shadow .2s,background-color .2s;
}
.shat-select:focus{
  border-color:var(--gold); background-color:#181818;
  box-shadow:0 0 0 4px rgba(184,155,94,.14);
}
/* The dropdown list itself is OS-rendered; force dark so it is not
   white-on-white in some Android browsers. */
.shat-select option{background:#101010;color:var(--text);}
/* Kill browser/mobile autofill white or black-on-white flash */
.shat-field input:-webkit-autofill,
.shat-field input:-webkit-autofill:hover,
.shat-field input:-webkit-autofill:focus,
.shat-field input:-webkit-autofill:active{
  -webkit-text-fill-color:var(--text) !important;
  -webkit-box-shadow:0 0 0 1000px #101010 inset !important;
  box-shadow:0 0 0 1000px #101010 inset !important;
  caret-color:var(--gold);
  transition:background-color 9999s ease-in-out 0s;
}
.shat-row2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.shat-btn-primary{
  width:100%; padding:13px; background:var(--btn); color:var(--btn-text);
  border:none; border-radius:12px;
  font-family:var(--font); font-size:15px; font-weight:500;
  cursor:pointer; letter-spacing:.1px;
  transition:opacity .2s,transform .15s;
}
.shat-btn-primary:hover{opacity:.85;transform:translateY(-1px);}
.shat-btn-primary:active{transform:translateY(0);}
.shat-auth-foot{text-align:center;margin-top:18px;font-size:13.5px;color:var(--sec);}
.shat-auth-foot a{color:var(--gold);text-decoration:none;font-weight:600;}
.shat-auth-foot a:hover{text-decoration:underline;}
.shat-msg{padding:11px 15px;border-radius:10px;margin-bottom:16px;font-size:13px;line-height:1.45;}
.shat-msg.err{background:rgba(229,57,53,.12);color:#FF8A80;border-left:3px solid #E53935;}
.shat-msg.ok {background:rgba(67,160,71,.12);color:#81C784;border-left:3px solid #43A047;}
.shat-msg.info{background:rgba(184,155,94,.10);color:var(--gold);border-left:3px solid var(--gold);}

/* ===== REMEMBER ME ROW ===== */
.shat-remember-row{
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:16px; margin-top:-2px;
}
.shat-remember-label{
  display:inline-flex; align-items:center; gap:9px;
  font-size:13px; color:var(--sec); cursor:pointer;
  font-family:var(--font); font-weight:400; user-select:none;
}
.shat-remember-label input[type=checkbox]{
  width:16px; height:16px; accent-color:var(--gold);
  cursor:pointer; flex-shrink:0;
}
.shat-forgot-link{
  font-size:13px; color:var(--gold); text-decoration:none;
  font-family:var(--font); font-weight:500;
}
.shat-forgot-link:hover{text-decoration:underline;}

/* ===== CLOUDFLARE TURNSTILE ===== */
.shat-turnstile-wrap{
  display:flex; justify-content:center;
  margin-bottom:16px; margin-top:2px;
  transform:scale(.94); transform-origin:center;
}
.shat-turnstile-wrap .cf-turnstile{
  /* Turnstile renders its own iframe; just center it */
  display:inline-block;
}

/* Mobile: compact, vertically centered auth pages */
@media (max-width:768px){
  .shat-auth-page,.shat-status-page{
    min-height:100vh !important;
    align-items:center !important;
    padding:16px !important;
  }
  .shat-auth-box,.shat-status-box{
    padding:26px 22px !important;
    border-radius:16px !important;
  }
  .shat-auth-logo{margin-bottom:18px !important;}
  .shat-auth-logo .mark{width:44px;height:44px;margin-bottom:10px;}
  .shat-auth-logo .mark svg{width:20px;height:20px;}
  .shat-auth-logo h1{font-size:18px;margin-bottom:4px;}
  .shat-auth-logo p{font-size:12.5px;}
  .shat-field{margin-bottom:11px;}
  .shat-field label{font-size:11px;margin-bottom:5px;}
  .shat-field input{padding:11px 14px;font-size:14.5px;}
  .shat-row2{gap:8px;}
  .shat-btn-primary{padding:12px;font-size:14px;}
  .shat-auth-foot{margin-top:14px;font-size:13px;}
  .shat-remember-row{margin-bottom:12px;}
  .shat-turnstile-wrap{margin-bottom:12px;transform:scale(.88);}
  .shat-msg{padding:10px 13px;margin-bottom:12px;font-size:12.5px;}
}
@media (max-width:400px){
  .shat-auth-box,.shat-status-box{padding:22px 18px !important;}
  .shat-turnstile-wrap{transform:scale(.8);}
}


/* ===== STATUS / PENDING PAGE ===== */
.shat-status-page{
  min-height:100vh; background:var(--bg);
  display:flex; align-items:center; justify-content:center; padding:20px;
}
.shat-status-box{
  background:var(--card); border-radius:20px; padding:44px 40px;
  max-width:400px; width:100%; text-align:center;
  border:1px solid var(--line);
  box-shadow:0 1px 3px rgba(0,0,0,.25);
}
.shat-status-box .icon{font-size:40px;margin-bottom:18px;display:block;}
.shat-status-box h2{font-size:21px;font-weight:600;color:var(--text);margin-bottom:10px;}
.shat-status-box p{font-size:14px;color:var(--sec);line-height:1.6;font-weight:400;}
.shat-logout-link{
  display:inline-block; margin-top:24px; padding:12px 26px;
  background:var(--btn); color:var(--btn-text); text-decoration:none;
  border-radius:12px; font-family:var(--font); font-size:14px; font-weight:500;
}
.shat-logout-link:hover{opacity:.85;}

/* ===================================================
   FEED PAGE — Instagram-style luxury profile + posts
   =================================================== */
.shat-feed-root{
  background:var(--bg);
  min-height:100vh;
  padding-bottom:90px;
  padding-top:24px;
  font-family:var(--font);
  -webkit-font-smoothing:antialiased;
  /* Prevent text / image selection globally on the feed */
  -webkit-user-select:none;
  -moz-user-select:none;
  user-select:none;
}

/* =========================================
   LUXURY PROFILE HEADER — feminine & refined, dark theme
   Palette: bg #141414, card #1C1C1C, text #F2F2F2,
   sec #9A9A9A, accent #D9A9AC, button #F2F2F2
   ========================================= */
.shat-ig-header{
  position:relative; max-width:600px; margin:0 auto;
  padding:28px 28px 24px; text-align:left;
  background:var(--card);
  border:1px solid var(--line);
  border-radius:20px;
  overflow:hidden;
  font-family:var(--font);
  animation:shatHeaderIn .55s cubic-bezier(.22,.9,.32,1) both;
}
@keyframes shatHeaderIn{from{opacity:0;transform:translateY(-10px) scale(.98);}to{opacity:1;transform:translateY(0) scale(1);}}
/* Decorative hearts removed — clean Instagram/Apple-style header, no busy motifs */
.shat-heart-deco{display:none;}

.shat-ig-top{
  position:relative; z-index:2;
  display:flex; align-items:center; gap:26px;
}

/* No native tap square on any header control (sign-out + chat fab). */
.shat-ig-header a,
.shat-ig-header button,
.shat-ig-logout,
.schat-fab-icon{
  -webkit-tap-highlight-color:rgba(0,0,0,0) !important;
  -webkit-touch-callout:none !important;
  outline:none !important;
}
.shat-ig-header a:focus,
.shat-ig-header button:focus,
.shat-ig-header a:active,
.shat-ig-header button:active{
  outline:none !important;
  box-shadow:none !important;
}

/* Refined sign-out — minimal pill */
.shat-ig-logout{
  position:absolute; top:18px; right:18px; z-index:5;
  width:36px; height:36px;
  display:inline-flex; align-items:center; justify-content:center;
  background:#141414; border:1px solid var(--line);
  border-radius:50%; color:var(--sec); text-decoration:none;
  transition:background .3s ease,color .3s ease,border-color .3s ease,transform .25s cubic-bezier(.34,1.56,.64,1);
  animation:shatHeaderIn .55s cubic-bezier(.22,.9,.32,1) both;
}
.shat-ig-logout:hover{
  background:var(--btn); color:var(--btn-text); border-color:var(--btn);
  transform:translateY(-1px);
}
.shat-ig-logout:active{transform:scale(.9);}
.shat-ig-logout svg{width:15px;height:15px;display:block;}

/* Avatar — thin gold ring, Instagram-story style, calm and minimal */
.shat-ig-avatar-ring{
  position:relative; width:88px; height:88px; border-radius:50%;
  padding:2.5px; flex-shrink:0;
  background:linear-gradient(135deg,#E8C4C6 0%,#D9A9AC 100%);
  transition:transform .3s cubic-bezier(.34,1.56,.64,1);
  animation:shatAvatarIn .5s cubic-bezier(.34,1.2,.4,1) .15s both;
}
.shat-ig-avatar-ring:hover{transform:scale(1.04);}
.shat-ig-shimmer{display:none;}
@keyframes shatAvatarIn{from{opacity:0;transform:scale(.82);}to{opacity:1;transform:scale(1);}}

.shat-ig-avatar-inner{
  position:relative; width:100%; height:100%; border-radius:50%;
  background:var(--card); overflow:hidden; display:block;
  border:3px solid var(--card);
}
.shat-ig-avatar-inner img{
  width:100%; height:100%; border-radius:50%; object-fit:cover; display:block;
  transition:transform .5s ease;
}
.shat-ig-avatar-ring:hover .shat-ig-avatar-inner img{transform:scale(1.05);}

/* Small verified-style badge on avatar (subtle, no glow) */
.shat-ig-avatar-badge{
  position:absolute; bottom:3px; right:3px;
  width:15px; height:15px; border-radius:50%;
  background:#3ED598; border:2.5px solid var(--card);
  z-index:3;
}
.shat-ig-avatar-badge svg{display:none;}

.shat-ig-info{flex:1; min-width:0; display:flex; flex-direction:column; gap:5px; animation:shatInfoIn .5s cubic-bezier(.22,.9,.32,1) .22s both;}
.shat-ig-name{
  font-family:var(--font);
  font-size:22px; font-weight:600; color:var(--text);
  letter-spacing:-.2px; margin:0; line-height:1.2;
  display:inline-flex; align-items:center; gap:6px;
}
.shat-ig-name .shat-verified{color:#D9A9AC; display:inline-flex;}
.shat-ig-name .shat-verified svg{width:16px;height:16px;}

.shat-ig-handle{
  font-family:var(--font);
  font-size:14px; color:var(--sec); font-weight:400;
  letter-spacing:.2px; text-transform:none;
  margin:0; line-height:1.3;
}
@keyframes shatInfoIn{from{opacity:0;transform:translateX(-8px);}to{opacity:1;transform:translateX(0);}}

/* Stats — clean Instagram-style row, no dividers, tighter numbers-over-labels */
.shat-ig-stats{
  position:relative; z-index:2;
  display:grid; grid-template-columns:repeat(3,1fr);
  margin-top:22px; padding:18px 0 0;
  border-top:1px solid var(--line);
  animation:shatStatsIn .5s cubic-bezier(.22,.9,.32,1) .28s both;
}
@keyframes shatStatsIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
.shat-ig-stat{
  text-align:center; padding:4px 8px;
  display:flex; flex-direction:column; gap:4px; align-items:center;
}
.shat-ig-stat .stat-n{
  font-family:var(--font);
  font-size:19px; font-weight:600; color:var(--text);
  line-height:1; letter-spacing:-.2px;
}
.shat-ig-stat .stat-l{
  font-family:var(--font);
  font-size:12px; color:var(--sec);
  text-transform:none; letter-spacing:.2px; font-weight:400;
}

/* Bio block — plain text under name, Apple/Instagram convention */
.shat-ig-bio-wrap{
  position:relative; z-index:2;
  margin-top:16px; padding:0;
  background:transparent; border-radius:0; border:none;
}
.shat-ig-bio{
  font-family:var(--font);
  font-size:14.5px; color:var(--text); line-height:1.55; font-weight:400;
  margin:0;
}
.shat-ig-bio .shat-bio-heart{display:none;}
.shat-ig-bio-sub{
  display:block; margin-top:4px; font-style:normal;
  font-size:13.5px; color:var(--sec); line-height:1.5;
}

/* ===== MEMBER PROFILE: header icons =====
   Two buttons stacked vertically and centred against the card height:
   the member initial on top, the chat bubble beneath.

   Both use top:50% with a translateY offset so they stay balanced as the
   card height changes across breakpoints. Stack is 36 + 10 + 36 = 82px,
   so the top button sits -41px from centre and the lower one +5px.

   NOTE: every offset below carries !important. The base .schat-fab-icon
   rule declares top/transform with !important, so anything softer here
   loses and both buttons collapse onto the same spot. */
.shat-me-btn{
  position:absolute !important;
  top:50% !important; right:20px !important;
  left:auto !important; bottom:auto !important;
  transform:translateY(-41px) !important;
  width:36px !important; height:36px !important;
  z-index:7 !important;
  padding:0 !important; border-radius:50% !important;
  display:inline-flex !important; align-items:center !important; justify-content:center !important;
  background:linear-gradient(150deg,#3A2E33,#241E21) !important;
  border:1px solid rgba(233,175,190,.24) !important;
  color:#EDBCC7 !important; cursor:pointer; overflow:visible !important;
  -webkit-tap-highlight-color:transparent; -webkit-appearance:none; appearance:none; outline:none;
  transition:background .3s ease, border-color .3s ease, transform .25s cubic-bezier(.34,1.56,.64,1);
}
.shat-me-btn:hover{ border-color:rgba(233,175,190,.45) !important; }
.shat-me-btn:active{ transform:translateY(-41px) scale(.9) !important; }
.shat-me-btn-initial{
  font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Inter","Segoe UI",Helvetica,Arial,sans-serif;
  font-size:16px; font-weight:600; line-height:1; letter-spacing:-.01em;
  color:currentColor; text-transform:uppercase;
}
.shat-me-dot{
  position:absolute; top:-2px; right:-2px;
  width:10px; height:10px; border-radius:50%;
  background:linear-gradient(150deg,#F2C3CE,#C96A78);
  border:2px solid var(--card);
}
.shat-me-btn.shat-me-win{ animation:shatMeWin .85s cubic-bezier(.34,1.4,.5,1); }
@keyframes shatMeWin{
  0%  { transform:translateY(-41px) scale(1); }
  28% { transform:translateY(-41px) scale(1.22); }
  52% { transform:translateY(-41px) scale(.96); }
  100%{ transform:translateY(-41px) scale(1); }
}

/* Chat button: 10px below the initial. */
.shat-ig-header .schat-fab-icon,
.shat-ig-header #schat-fab-btn.schat-fab-icon,
.shat-ig-header #schat-admin-fab-btn.schat-fab-icon{
  position:absolute !important;
  top:50% !important; right:20px !important;
  left:auto !important; bottom:auto !important;
  transform:translateY(5px) !important;
  width:36px !important; height:36px !important;
  z-index:6 !important;
}
/* The base sheet has .schat-fab-icon:active{transform:scale(.9)} which
   would wipe the translateY and snap the button to the card centre on
   tap. Re-apply the offset alongside the press scale. */
.shat-ig-header .schat-fab-icon:active,
.shat-ig-header #schat-fab-btn.schat-fab-icon:active{
  transform:translateY(5px) scale(.9) !important;
}
.shat-ig-header .schat-fab-icon:hover,
.shat-ig-header #schat-fab-btn.schat-fab-icon:hover{
  transform:translateY(5px) !important;
}

/* The glyph was 15px against a 16px letter, which read smaller. */
.shat-ig-header .schat-fab-icon svg,
.shat-ig-header #schat-fab-btn.schat-fab-icon svg{
  width:18px !important; height:18px !important; stroke-width:1.9 !important;
}

/* Reserve room so the name and verified badge never run under the icons. */
.shat-ig-header{ padding-right:72px; }

@media(max-width:640px){
  .shat-me-btn,
  .shat-ig-header .schat-fab-icon,
  .shat-ig-header #schat-fab-btn.schat-fab-icon{ right:16px !important; }
}
@media(max-width:480px){
  .shat-me-btn,
  .shat-ig-header .schat-fab-icon,
  .shat-ig-header #schat-fab-btn.schat-fab-icon{
    width:33px !important; height:33px !important;
  }
  /* Stack becomes 33 + 9 + 33 = 75px, so -37.5px and +4.5px. */
  .shat-me-btn{ transform:translateY(-37px) !important; }
  .shat-me-btn:active{ transform:translateY(-37px) scale(.9) !important; }
  .shat-ig-header .schat-fab-icon,
  .shat-ig-header #schat-fab-btn.schat-fab-icon{ transform:translateY(5px) !important; }
  .shat-ig-header .schat-fab-icon:active,
  .shat-ig-header #schat-fab-btn.schat-fab-icon:active{ transform:translateY(5px) scale(.9) !important; }
  .shat-me-btn-initial{ font-size:14.5px; }
  .shat-ig-header .schat-fab-icon svg{ width:16px !important; height:16px !important; }
}

/* ===== MEMBER PROFILE SHEET ===== */
.shat-me-overlay{
  position:fixed; inset:0; z-index:100002;
  background:rgba(4,4,6,0);
  display:none; align-items:flex-end; justify-content:center;
  -webkit-backdrop-filter:blur(0); backdrop-filter:blur(0);
  transition:background .3s ease, backdrop-filter .3s ease;
  -webkit-tap-highlight-color:transparent;
}
.shat-me-overlay.shat-me-open{display:flex;}
.shat-me-overlay.shat-me-visible{
  background:rgba(4,4,6,.6);
  -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px);
}
.shat-me-sheet{
  width:100%; max-width:430px;
  background:linear-gradient(172deg,#242023 0%,#171416 62%);
  border:.5px solid rgba(255,255,255,.10);
  border-radius:28px 28px 0 0;
  padding:12px 22px calc(24px + env(safe-area-inset-bottom, 0px));
  box-shadow:0 -16px 60px rgba(0,0,0,.62), inset 0 1px 0 rgba(255,255,255,.055);
  transform:translateY(100%);
  transition:transform .38s cubic-bezier(.32,.72,0,1);
  max-height:90vh; overflow-y:auto;
  scrollbar-width:none; -ms-overflow-style:none;
  font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Inter","Segoe UI",Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
}
.shat-me-sheet::-webkit-scrollbar{width:0;height:0;display:none;}
.shat-me-overlay.shat-me-visible .shat-me-sheet{transform:translateY(0);}
@media(min-width:641px){
  .shat-me-overlay{align-items:center; padding:24px;}
  .shat-me-sheet{
    border-radius:26px; max-width:400px;
    transform:translateY(16px) scale(.97); opacity:0;
    transition:transform .34s cubic-bezier(.22,.9,.32,1), opacity .3s ease;
  }
  .shat-me-overlay.shat-me-visible .shat-me-sheet{transform:translateY(0) scale(1); opacity:1;}
}
.shat-me-grab{
  width:36px; height:4px; border-radius:4px; margin:0 auto 18px;
  background:rgba(255,255,255,.14);
}
@media(min-width:641px){ .shat-me-grab{display:none;} }

/* Greeting header */
.shat-me-head{text-align:center; margin-bottom:20px;}
.shat-me-avatar{
  position:relative; width:74px; height:74px; margin:0 auto 13px;
  border-radius:50%;
  background:linear-gradient(150deg,#3A2E33,#241E21);
  border:.5px solid rgba(233,175,190,.24);
  box-shadow:0 6px 22px -12px rgba(0,0,0,.9);
  display:flex; align-items:center; justify-content:center;
}
.shat-me-initial{
  font-size:30px; font-weight:650; color:#EDBCC7;
  letter-spacing:-.02em; line-height:1; text-transform:uppercase;
}
.shat-me-greet{
  margin:0; font-size:13px; font-weight:500; color:var(--sec);
  letter-spacing:-.004em; line-height:1.3; opacity:.9;
}
.shat-me-name{
  margin:3px 0 0; font-size:27px; font-weight:700; letter-spacing:-.03em;
  color:var(--text); line-height:1.12; font-family:inherit; text-transform:none;
}
.shat-me-since{
  margin:7px 0 0; font-size:12px; color:var(--sec); letter-spacing:-.002em; opacity:.85;
}

/* Cards */
.shat-me-card{
  background:rgba(255,255,255,.038); border:.5px solid rgba(255,255,255,.065);
  border-radius:18px; padding:16px 16px 15px; margin-bottom:11px;
}
.shat-me-streak-top{display:flex; align-items:center; gap:12px; margin-bottom:14px;}
.shat-me-candy{
  flex-shrink:0; width:38px; height:38px; display:flex;
  align-items:center; justify-content:center; border-radius:12px;
  background:rgba(233,175,190,.08); border:.5px solid rgba(233,175,190,.14);
}
.shat-me-candy svg{width:24px; height:24px;}
.shat-me-candy:not(.lit) svg{filter:saturate(.15) opacity(.45);}
.shat-me-streak-num{flex:1; min-width:0; display:flex; align-items:baseline; gap:7px;}
.shat-me-streak-num b{
  font-size:25px; font-weight:700; color:var(--text);
  letter-spacing:-.03em; line-height:1; font-variant-numeric:tabular-nums;
}
.shat-me-streak-num span{font-size:13px; color:var(--sec); letter-spacing:-.006em;}
.shat-me-info{
  flex-shrink:0; width:29px; height:29px; border-radius:50%; padding:0; cursor:pointer;
  background:rgba(255,255,255,.05); border:.5px solid rgba(255,255,255,.08);
  color:var(--sec); display:inline-flex; align-items:center; justify-content:center;
  -webkit-tap-highlight-color:transparent; -webkit-appearance:none; appearance:none; outline:none;
  transition:background .22s ease, color .22s ease, transform .18s cubic-bezier(.34,1.5,.64,1);
}
.shat-me-info svg{width:15px; height:15px;}
.shat-me-info:hover{background:rgba(255,255,255,.09); color:var(--text);}
.shat-me-info:active{transform:scale(.9);}
.shat-me-info.on{background:rgba(233,175,190,.14); color:#EDBCC7; border-color:rgba(233,175,190,.25);}

.shat-me-bar{height:7px; border-radius:100px; background:rgba(255,255,255,.06); overflow:hidden;}
.shat-me-bar span{
  display:block; height:100%; width:0%; border-radius:100px;
  background:linear-gradient(90deg,#C96A78,#F2C3CE);
  transition:width .7s cubic-bezier(.22,.9,.32,1);
}
.shat-me-bar-label{
  display:flex; justify-content:space-between; align-items:baseline; gap:10px;
  margin-top:9px; font-size:11.5px; color:var(--sec); letter-spacing:-.004em;
}
.shat-me-bar-label strong{color:#EDBCC7; font-weight:650; font-variant-numeric:tabular-nums;}

.shat-me-rules{
  margin-top:14px; padding-top:14px; border-top:.5px solid rgba(255,255,255,.07);
  animation:shatMeRules .32s cubic-bezier(.22,.9,.32,1) both;
}
@keyframes shatMeRules{from{opacity:0;transform:translateY(-5px);}to{opacity:1;transform:translateY(0);}}
.shat-me-rules ul{list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:9px;}
.shat-me-rules li{
  position:relative; padding-left:16px;
  font-size:12.5px; line-height:1.5; color:#B4ADB0; letter-spacing:-.004em;
}
.shat-me-rules li::before{
  content:""; position:absolute; left:2px; top:7px;
  width:4px; height:4px; border-radius:50%; background:#C96A78;
}
.shat-me-rules strong{color:var(--text); font-weight:620;}

/* Stats */
.shat-me-stats{display:flex; gap:9px; margin-bottom:11px;}
.shat-me-stat{
  flex:1; text-align:center; padding:13px 6px; border-radius:16px;
  background:rgba(255,255,255,.038); border:.5px solid rgba(255,255,255,.065);
}
.shat-me-stat b{
  display:block; font-size:21px; font-weight:700; color:#EDBCC7;
  letter-spacing:-.028em; line-height:1.05; font-variant-numeric:tabular-nums;
}
.shat-me-stat span{
  display:block; margin-top:4px; font-size:9.5px; letter-spacing:.07em;
  text-transform:uppercase; color:var(--sec); font-weight:600;
}

/* Membership rows */
.shat-me-rows{padding:4px 16px;}
.shat-me-row{
  display:flex; align-items:center; justify-content:space-between; gap:14px;
  padding:12px 0; border-bottom:.5px solid rgba(255,255,255,.055);
}
.shat-me-row:last-child{border-bottom:none;}
.shat-me-row-k{font-size:13px; color:var(--sec); letter-spacing:-.004em; flex-shrink:0;}
.shat-me-row-v{
  font-size:13px; color:var(--text); letter-spacing:-.008em; font-weight:520;
  text-align:right; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.shat-me-tag{
  display:inline-block; padding:3px 10px; border-radius:100px;
  font-size:11.5px; font-weight:600; letter-spacing:-.004em;
}
.shat-me-tag.ok{background:rgba(233,175,190,.13); color:#EDBCC7; border:.5px solid rgba(233,175,190,.22);}
.shat-me-tag.off{background:rgba(226,76,76,.13); color:#E88; border:.5px solid rgba(226,76,76,.24);}

.shat-me-signout{
  display:flex; align-items:center; justify-content:center; gap:9px;
  width:100%; padding:14px; margin-bottom:9px;
  border-radius:16px; text-decoration:none; cursor:pointer;
  background:rgba(226,76,76,.09); border:.5px solid rgba(226,76,76,.20);
  color:#E98A8A; font-family:inherit; font-size:14.5px; font-weight:600;
  letter-spacing:-.012em;
  -webkit-tap-highlight-color:transparent;
  transition:background .22s ease, transform .18s cubic-bezier(.34,1.5,.64,1);
}
.shat-me-signout:hover{background:rgba(226,76,76,.14);}
.shat-me-signout:active{transform:scale(.985);}
.shat-me-signout svg{width:17px; height:17px; flex-shrink:0; stroke:currentColor; fill:none;}

.shat-me-close{
  width:100%; padding:15px; border-radius:16px; border:none; cursor:pointer;
  background:rgba(255,255,255,.065); color:var(--text);
  font-family:inherit; font-size:15.5px; font-weight:600; letter-spacing:-.014em;
  text-transform:none; margin-top:4px;
  -webkit-tap-highlight-color:transparent;
  transition:background .22s ease, transform .18s cubic-bezier(.34,1.5,.64,1);
}
.shat-me-close:hover{background:rgba(255,255,255,.1);}
.shat-me-close:active{transform:scale(.985);}

/* Earn toast */
.shat-candy-toast{
  position:fixed; left:50%; bottom:calc(26px + env(safe-area-inset-bottom, 0px));
  transform:translate(-50%,26px); z-index:100003;
  display:flex; align-items:center; gap:10px;
  padding:12px 20px; border-radius:100px;
  background:linear-gradient(150deg,#302428,#1F191C);
  border:.5px solid rgba(233,175,190,.26);
  box-shadow:0 14px 44px rgba(0,0,0,.58), inset 0 1px 0 rgba(255,255,255,.06);
  color:var(--text); font-size:14px; font-weight:600; letter-spacing:-.014em;
  font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Inter","Segoe UI",Helvetica,Arial,sans-serif;
  opacity:0; pointer-events:none;
  transition:opacity .32s ease, transform .45s cubic-bezier(.22,1.1,.36,1);
  max-width:calc(100vw - 36px);
}
.shat-candy-toast.show{opacity:1; transform:translate(-50%,0);}
.shat-candy-toast svg{width:26px; height:26px; flex-shrink:0;}

@media (prefers-reduced-motion:reduce){
  .shat-me-btn, .shat-me-sheet, .shat-me-overlay,
  .shat-candy-toast, .shat-me-bar span, .shat-me-rules{
    animation:none !important; transition:none !important;
  }
}

/* ===== FEED ACCESS COUNTDOWN PILL ===== */
.shat-access-pill-wrap{
  max-width:600px; margin:18px auto 0; padding:0 28px;
  display:flex; justify-content:center;
}
.shat-access-pill{
  display:inline-flex; align-items:center; gap:9px;
  padding:9px 18px 9px 14px;
  background:linear-gradient(135deg,rgba(217,169,172,.10),rgba(217,169,172,.04));
  border:1px solid rgba(217,169,172,.28);
  border-radius:100px;
  animation:pillFadeIn .5s cubic-bezier(.22,.9,.32,1) .34s both;
}
.shat-access-pill-icon{
  display:inline-flex; align-items:center; justify-content:center;
  width:20px; height:20px; flex-shrink:0; color:var(--gold);
}
.shat-access-pill-icon svg{width:16px;height:16px;}
.shat-access-pill-text{
  font-family:var(--font); font-size:13px; color:var(--text);
  font-weight:400; letter-spacing:.1px; white-space:nowrap;
}
.shat-access-pill-text strong{
  font-weight:700; color:var(--gold);
}
/* Urgent state — 7 days or fewer remaining: warmer glow, slightly more presence */
.shat-access-pill.urgent{
  background:linear-gradient(135deg,rgba(217,169,172,.18),rgba(217,169,172,.08));
  border-color:rgba(217,169,172,.5);
  box-shadow:0 2px 16px rgba(217,169,172,.15);
}
.shat-access-pill.urgent .shat-access-pill-icon{
  animation:pillPulse 2.2s ease-in-out infinite;
}
@keyframes pillFadeIn{from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);}}
@keyframes pillPulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.6;transform:scale(1.12);}}

@media(max-width:640px){
  .shat-access-pill-wrap{padding:0 14px;margin-top:14px;}
  .shat-access-pill{padding:8px 16px 8px 12px;gap:7px;}
  .shat-access-pill-text{font-size:12px;}
}

/* ===== "REQUEST SENT" STATUS PILL — replaces the old chat/reply widget.
   No conversation UI anymore; just a small confirmation that a request
   is on file, plus the contact handle for a faster response. ===== */
.shat-reply-wrap{
  max-width:600px; margin:18px auto 0; padding:0 28px;
  animation:pillFadeIn .5s cubic-bezier(.22,.9,.32,1) both;
}
.shat-reply-card{
  position:relative; padding:16px 18px; background:var(--card);
  border:1px solid rgba(217,169,172,.3);
  border-radius:16px;
  box-shadow:0 4px 20px rgba(217,169,172,.08);
  display:flex; align-items:center; gap:12px; flex-wrap:wrap;
}
.shat-reply-icon{
  flex-shrink:0; width:34px; height:34px; border-radius:50%;
  background:linear-gradient(135deg,rgba(217,169,172,.22),rgba(217,169,172,.08));
  border:1px solid rgba(217,169,172,.32);
  display:flex; align-items:center; justify-content:center; color:#D9A9AC;
}
.shat-reply-icon svg{width:15px;height:15px;}
.shat-reply-headtext{flex:1; min-width:0;}
.shat-reply-eyebrow{
  display:block; font-family:var(--font); font-size:11px; font-weight:600;
  color:var(--gold); text-transform:uppercase; letter-spacing:1px; margin-bottom:3px;
}
.shat-reply-note{
  font-family:var(--font); font-size:13px; color:var(--sec); line-height:1.5;
}
.shat-reply-note strong{color:var(--text);}
.shat-reply-status{
  font-family:var(--font); font-size:10.5px; font-weight:600;
  text-transform:uppercase; letter-spacing:.6px;
  padding:3px 10px; border-radius:20px; white-space:nowrap;
}
.shat-status-open{background:rgba(217,169,172,.14); color:#D9A9AC;}
.shat-status-in_progress{background:rgba(217,169,172,.22); color:#E8C4C6;}
.shat-status-resolved{background:rgba(120,200,150,.14); color:#8ED4A8;}

@media(max-width:640px){
  .shat-reply-wrap{padding:0 14px;margin-top:14px;}
  .shat-reply-card{padding:14px 15px;border-radius:14px;}
}

/* Divider before feed — simple, minimal label */
.shat-ig-feed-divider{
  max-width:600px; margin:28px auto 4px;
  display:flex; align-items:center; justify-content:center; gap:14px; padding:0 28px;
  animation:shatDividerIn .5s cubic-bezier(.22,.9,.32,1) .42s both;
}
@keyframes shatDividerIn{from{opacity:0;}to{opacity:1;}}
.shat-ig-feed-divider::before,.shat-ig-feed-divider::after{
  content:""; flex:1; height:1px;
  background:var(--line);
}
.shat-ig-feed-divider span{
  font-family:var(--font);
  font-size:11px; color:var(--sec); text-transform:uppercase;
  letter-spacing:2px; white-space:nowrap; font-weight:500;
  display:inline-flex; align-items:center; gap:6px;
}
.shat-ig-feed-divider span svg{display:none;}

/* ===== POSTS / SAVED TAB SWITCHER — replaces the old plain divider ===== */
.shat-feed-tabs{
  position:relative; max-width:600px; margin:28px auto 4px; padding:0 28px 14px;
  display:flex; gap:6px; border-bottom:1px solid var(--line);
  animation:shatDividerIn .5s cubic-bezier(.22,.9,.32,1) .42s both;
}
.shat-feed-tab{
  -webkit-appearance:none; appearance:none; background:transparent; border:none;
  display:inline-flex; align-items:center; gap:7px;
  padding:9px 16px; margin-bottom:-15px;
  font-family:var(--font); font-size:13px; font-weight:600; letter-spacing:.2px;
  color:var(--sec); cursor:pointer; -webkit-tap-highlight-color:transparent;
  transition:color .25s ease;
}
.shat-feed-tab svg{width:16px;height:16px;flex-shrink:0;transition:transform .25s cubic-bezier(.34,1.56,.64,1);}
.shat-feed-tab.active{color:var(--text);}
.shat-feed-tab:active svg{transform:scale(.88);}
.shat-feed-tab-indicator{
  position:absolute; bottom:0; left:28px; width:calc(50% - 31px); height:2px;
  background:var(--gold); border-radius:2px;
  transition:transform .32s cubic-bezier(.22,.9,.32,1);
}
.shat-feed-tab-indicator.right{transform:translateX(calc(100% + 6px));}
.shat-tab-panel.shat-panel-in{animation:shatPanelIn .32s cubic-bezier(.22,.9,.32,1) both;}
@keyframes shatPanelIn{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:translateY(0);}}

@media(max-width:640px){
  .shat-feed-tabs{padding:0 14px 12px;margin-top:24px;}
  .shat-feed-tab{padding:8px 13px;font-size:12.5px;}
  .shat-feed-tab-indicator{left:14px;width:calc(50% - 17px);}
}

/* The Saved tab reuses the same card layout as the feed
   (.shat-photo-grid / .shat-photo-card below) so both tabs look
   identical. The old square thumbnail grid and its styles were removed
   in 2.3.0.
   NOTE: no apostrophes anywhere in this block - it lives inside a
   single-quoted PHP string. */

/* =========================================
   PHOTO GRID & CARDS
   ========================================= */
.shat-photo-grid{padding:12px 0 0;max-width:600px;margin:0 auto;}
.shat-photo-card{
  background:var(--card); border:1px solid var(--line); border-radius:16px;
  overflow:hidden; margin-bottom:20px;
  animation:cardIn .6s cubic-bezier(.22,.9,.32,1) both;
  animation-delay:calc(80ms + var(--stagger, 0) * 60ms);
  box-shadow:0 1px 3px rgba(0,0,0,.25);
  transition:box-shadow .3s cubic-bezier(.22,.61,.36,1), transform .3s cubic-bezier(.22,.61,.36,1);
}
.shat-photo-card:hover{
  box-shadow:0 4px 20px rgba(0,0,0,.4);
}
@keyframes cardIn{from{opacity:0;transform:translateY(22px) scale(.985);}to{opacity:1;transform:translateY(0) scale(1);}}

/* ===== "NEW" POST TREATMENT — lasts 3 days from actual upload time ===== */
.shat-photo-card.shat-card-new{
  border:1px solid rgba(217,169,172,.55);
  box-shadow:
    0 0 0 1px rgba(217,169,172,.12),
    0 4px 24px rgba(217,169,172,.16),
    0 1px 3px rgba(0,0,0,.25);
  animation:cardIn .6s cubic-bezier(.22,.9,.32,1) both, newCardGlow 3.2s ease-in-out infinite;
  animation-delay:calc(80ms + var(--stagger, 0) * 60ms), 0s;
}
.shat-photo-card.shat-card-new:hover{
  box-shadow:
    0 0 0 1px rgba(217,169,172,.18),
    0 6px 30px rgba(217,169,172,.24),
    0 4px 20px rgba(0,0,0,.4);
}
@keyframes newCardGlow{
  0%,100%{ border-color:rgba(217,169,172,.42); }
  50%{ border-color:rgba(217,169,172,.72); }
}

.shat-new-tag{
  display:inline-flex; align-items:center; gap:3px;
  margin-left:7px; padding:2.5px 9px 2.5px 7px;
  background:linear-gradient(120deg,#D9A9AC,#E8C4C6,#D9A9AC);
  background-size:200% 100%;
  color:#141414; font-family:var(--font); font-size:10px; font-weight:700;
  letter-spacing:.6px; text-transform:uppercase;
  border-radius:20px; vertical-align:middle;
  animation:newTagShimmer 2.6s ease-in-out infinite;
}
.shat-new-tag svg{width:9px;height:9px;flex-shrink:0;}
@keyframes newTagShimmer{
  0%{background-position:0% 50%;}
  50%{background-position:100% 50%;}
  100%{background-position:0% 50%;}
}

/* Card header */
.shat-card-head{padding:13px 16px;display:flex;align-items:center;gap:11px;border-bottom:none;}
.shat-card-avatar{width:36px;height:36px;border-radius:50%;overflow:hidden;flex-shrink:0;padding:2px;background:linear-gradient(135deg,#E8C4C6,#D9A9AC);}
.shat-card-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;border:1.5px solid var(--card);}
.shat-card-name{font-family:var(--font);font-size:14px;font-weight:600;color:var(--text);letter-spacing:.1px;display:flex;align-items:center;flex-wrap:wrap;}
.shat-card-date{font-family:var(--font);font-size:12px;color:var(--sec);margin-top:1px;font-weight:400;letter-spacing:.1px;}

/* Image area */
.shat-img-wrap{position:relative;width:100%;padding-top:100%;background:#0E0E0E;overflow:hidden;}
.shat-img-wrap img{
  position:absolute; top:0; left:0; width:100%; height:100%;
  object-fit:cover; display:block;
  transition:transform .6s cubic-bezier(.22,.61,.36,1);
  /* NO-DOWNLOAD: disable pointer events & drag on the raw image element */
  pointer-events:none;
  -webkit-user-drag:none;
  -moz-user-drag:none;
  user-drag:none;
  -webkit-touch-callout:none;
}
.shat-photo-card:hover .shat-img-wrap img{transform:scale(1.035);}

/* -----------------------------------------------
   NO-DOWNLOAD SHIELD — transparent overlay that
   intercepts right-click / long-press on every image
   ----------------------------------------------- */
.shat-img-shield{
  position:absolute; inset:0; z-index:4;
  background:transparent; cursor:default;
}

/* -----------------------------------------------
   BLUR / LOCKED STATE  (NEW in 1.3.0)
   ----------------------------------------------- */
/* Blurred image: heavy blur + slight scale to hide edges */
.shat-img-locked > img.shat-locked-img{
  filter:blur(24px);
  transform:scale(1.12) !important;
  transition:filter 1.2s ease, transform 1s ease;
}
/* When JS unlocks in-place, smooth transition back to normal */
.shat-img-locked.shat-unlocking > img.shat-locked-img{
  filter:blur(0) !important;
  transform:scale(1) !important;
}

/* The lock overlay (sits above blurred image) */
.shat-blur-overlay{
  position:absolute; inset:0; z-index:8;
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:13px; padding:24px 18px;
  background:linear-gradient(180deg,rgba(8,5,2,.38) 0%,rgba(10,7,3,.58) 50%,rgba(8,5,2,.38) 100%);
  backdrop-filter:blur(2px); -webkit-backdrop-filter:blur(2px);
  transition:opacity .9s ease;
}
.shat-blur-overlay.shat-fade-out{opacity:0;pointer-events:none;}

/* Rosé lock icon ring */
.shat-lock-ring{
  width:58px; height:58px; border-radius:50%;
  background:linear-gradient(135deg,rgba(217,169,172,.14),rgba(192,138,142,.10));
  border:1.5px solid rgba(217,169,172,.46);
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 6px 24px rgba(0,0,0,.42),0 0 0 6px rgba(217,169,172,.07);
  flex-shrink:0;
}
.shat-lock-ring svg{width:24px;height:24px;stroke:#D9A9AC;color:#D9A9AC;}

/* "This photo is locked" label */
.shat-lock-label{
  font-family:var(--font); font-size:10.5px;
  color:rgba(255,255,255,.50); letter-spacing:2px;
  text-transform:uppercase; font-weight:500; margin-top:-4px;
}

/* Passcode row: input + unlock button */
.shat-passcode-row{display:flex;gap:8px;width:100%;max-width:272px;}
.shat-passcode-input{
  flex:1; min-width:0; padding:10px 13px;
  background:rgba(255,255,255,.08);
  border:1.5px solid rgba(217,169,172,.26);
  border-radius:10px; color:#F2F2F2;
  font-family:var(--font); font-size:14px;
  outline:none; transition:border-color .2s,background .2s;
}
.shat-passcode-input:focus{border-color:rgba(217,169,172,.72);background:rgba(255,255,255,.12);}
.shat-passcode-input::placeholder{color:rgba(255,255,255,.24);font-style:italic;font-size:12.5px;}
.shat-passcode-btn{
  flex-shrink:0; padding:10px 15px;
  background:linear-gradient(135deg,rgba(217,169,172,.20),rgba(217,169,172,.15));
  border:1px solid rgba(217,169,172,.40); border-radius:10px;
  color:#D9A9AC; font-family:var(--font); font-size:12px; font-weight:600; letter-spacing:.5px;
  cursor:pointer; white-space:nowrap;
  transition:background .2s,color .2s,border-color .2s;
}
.shat-passcode-btn:hover:not(:disabled){
  background:linear-gradient(135deg,rgba(217,169,172,.38),rgba(217,169,172,.30));
  color:#E8C4C6; border-color:#C08A8E;
}
.shat-passcode-btn:disabled{opacity:.45;cursor:default;}

/* Error message inside overlay */
.shat-unlock-err{
  font-family:var(--font); font-size:11.5px; color:#FF7878;
  text-align:center; line-height:1.4; display:none;
  margin:-4px 0; max-width:260px;
}

/* "Request to Unlock" link */
.shat-request-unlock{
  color:rgba(255,255,255,.30); font-family:var(--font);
  font-size:10px; letter-spacing:1.6px; text-transform:uppercase;
  font-weight:500; text-decoration:none;
  padding-bottom:1px; border-bottom:1px solid rgba(255,255,255,.14);
  transition:color .2s,border-color .2s; margin-top:2px;
}
.shat-request-unlock:hover{color:#D9A9AC;border-color:rgba(217,169,172,.45);}

/* ---- Patreon exclusive lock (NEW in 2.3.0) ---- */
.shat-patreon-overlay{
  position:absolute; inset:0; display:flex; flex-direction:column;
  align-items:center; justify-content:center; gap:11px; padding:20px;
  text-align:center; color:#fff; z-index:3;
  background:rgba(18,18,20,.62);
  -webkit-backdrop-filter:blur(3px); backdrop-filter:blur(3px);
}
.shat-patreon-badge{
  display:inline-flex; align-items:center; gap:6px;
  font-size:10.5px; letter-spacing:.14em; text-transform:uppercase;
  padding:5px 12px; border-radius:999px;
  background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.22);
}
.shat-patreon-badge svg{width:12px;height:12px;}
.shat-patreon-title{
  font-size:16px; font-weight:600; margin:0; line-height:1.35; max-width:260px;
}
.shat-patreon-sub{
  font-size:12.5px; opacity:.78; margin:0; max-width:260px; line-height:1.5;
}
.shat-patreon-btn{
  display:inline-flex; align-items:center; gap:8px;
  margin-top:2px; padding:11px 22px; border-radius:999px;
  background:#FF424D; color:#fff; font-size:14px; font-weight:600;
  text-decoration:none; border:none; cursor:pointer;
  transition:transform .15s ease, filter .15s ease;
  -webkit-tap-highlight-color:transparent;
}
.shat-patreon-btn:hover{filter:brightness(1.08); transform:translateY(-1px); color:#fff;}
.shat-patreon-btn:active{transform:scale(.97);}
.shat-patreon-btn svg{width:16px;height:16px;}

/* Shake animation for wrong passcode */
@keyframes shatShake{
  0%,100%{transform:translateX(0);}
  20%,60%{transform:translateX(-6px);}
  40%,80%{transform:translateX(6px);}
}

/* Actions bar */
.shat-card-actions{
  padding:12px 16px 4px; display:flex; align-items:center; gap:16px;
  border-top:none;
}
.shat-like-btn{
  background:transparent !important; border:none; outline:none; cursor:pointer;
  -webkit-appearance:none; appearance:none;
  -webkit-tap-highlight-color:transparent;
  display:inline-flex; align-items:center; gap:7px;
  color:var(--text); font-family:var(--font); font-size:14px; padding:0;
  border-radius:0; box-shadow:none;
  transition:color .2s ease;
}
.shat-like-btn:hover{color:#E53935; background:transparent !important;}
.shat-like-btn:active{background:transparent !important;}
.shat-like-btn:focus{outline:none; background:transparent !important;}
.shat-like-btn svg{width:23px;height:23px;transition:transform .35s cubic-bezier(.34,1.56,.64,1),fill .25s ease,stroke .25s ease;}
.shat-like-btn:hover svg{transform:scale(1.12);}
.shat-like-btn.liked{color:#E53935;}
.shat-like-btn.liked svg{fill:#E53935;stroke:#E53935;}
.shat-like-btn.pop svg{animation:likePop .5s cubic-bezier(.34,1.56,.64,1);}
@keyframes likePop{0%{transform:scale(1);}45%{transform:scale(1.32);}100%{transform:scale(1);}}

/* ===== SAVE / BOOKMARK BUTTON — inline with Like, pushed to the far right (Instagram convention) ===== */
.shat-save-btn{
  background:transparent !important; border:none; outline:none; cursor:pointer;
  -webkit-appearance:none; appearance:none;
  -webkit-tap-highlight-color:transparent;
  display:inline-flex; align-items:center;
  color:var(--text); padding:0;
  margin-left:auto;
  border-radius:0; box-shadow:none;
  transition:color .25s ease;
}
.shat-save-btn:hover{color:var(--gold); background:transparent !important;}
.shat-save-btn:active{background:transparent !important;}
.shat-save-btn:focus{outline:none; background:transparent !important;}
.shat-save-btn svg{width:22px;height:22px;transition:transform .35s cubic-bezier(.34,1.56,.64,1),fill .25s ease,stroke .25s ease;}
.shat-save-btn:hover svg{transform:scale(1.12);}
.shat-save-btn.saved{color:var(--gold);}
.shat-save-btn.saved svg{fill:var(--gold);stroke:var(--gold);}
.shat-save-btn.bounce svg{animation:shatSaveBounce .5s cubic-bezier(.34,1.56,.64,1);}
@keyframes shatSaveBounce{0%{transform:scale(1);}45%{transform:scale(1.28);}100%{transform:scale(1);}}

.shat-views{display:inline-flex;align-items:center;gap:6px;color:var(--sec);font-size:13px;margin-left:auto;font-family:var(--font);}
.shat-views svg{width:16px;height:16px;stroke:var(--sec);}

/* Caption / description — clear hierarchy: bold caption first, softer muted description below */
.shat-card-meta{padding:6px 16px 18px;}
.shat-card-caption{font-family:var(--font);font-size:15px;font-weight:600;color:var(--text);margin-bottom:6px;letter-spacing:0;line-height:1.4;}
.shat-card-desc{font-family:var(--font);font-size:13.5px;color:var(--sec);line-height:1.6;font-weight:400;}

/* Load more */
.shat-loadmore-wrap{text-align:center;padding:32px 20px;}
.shat-loadmore-btn{
  background:var(--btn); border:1px solid var(--btn); color:var(--btn-text);
  padding:13px 40px; border-radius:10px; font-family:var(--font); font-size:13px;
  cursor:pointer; letter-spacing:.5px; text-transform:none; font-weight:500;
  transition:opacity .25s cubic-bezier(.22,.61,.36,1),transform .2s cubic-bezier(.34,1.56,.64,1);
}
.shat-loadmore-btn:hover{opacity:.85;transform:translateY(-1px);}
.shat-loadmore-btn:active{transform:translateY(0) scale(.96);transition-duration:.1s;}
.shat-loadmore-btn:disabled{opacity:.45;cursor:default;transform:none;}
.shat-end-msg{color:var(--sec);font-family:var(--font);font-size:12px;letter-spacing:1px;text-transform:uppercase;padding:28px 0;text-align:center;font-weight:500;}
.shat-empty-feed{text-align:center;padding:80px 20px;color:var(--sec);font-family:var(--font);font-style:normal;font-size:15px;}
.shat-empty-feed svg{width:42px;height:42px;stroke:rgba(217,169,172,.55);margin-bottom:16px;display:block;margin-left:auto;margin-right:auto;}

/* ===== FEED ACCESS EXPIRED BANNER ===== */
.shat-feed-expired-banner{
  max-width:600px; margin:0 auto 20px; padding:32px 28px;
  background:var(--card); border:1px solid var(--line); border-radius:16px;
  text-align:center; display:flex; flex-direction:column; align-items:center; gap:12px;
  animation:shatStatsIn .5s cubic-bezier(.22,.9,.32,1) .34s both;
}
.shat-feed-expired-banner .shat-lock-ring{
  position:static; margin-bottom:2px;
}
.shat-feed-expired-banner h2{
  font-family:var(--font); font-size:19px; font-weight:600; color:var(--text); margin:0;
}
.shat-feed-expired-banner p{
  font-family:var(--font); font-size:14px; color:var(--sec); line-height:1.55; margin:0; max-width:380px;
}
.shat-renew-btn{
  display:inline-block; margin-top:6px; padding:12px 28px;
  background:var(--gold); color:#141414; text-decoration:none;
  border-radius:10px; font-family:var(--font); font-size:14px; font-weight:600;
  transition:opacity .25s ease,transform .2s cubic-bezier(.34,1.56,.64,1);
}
.shat-renew-btn:active{transform:scale(.96);}
.shat-renew-btn:hover{opacity:.85;}

/* Secondary "Message me directly" button (2.3.0) — sits under the
   primary renew action on the expired banner. */
.shat-msg-inline{
  display:flex; align-items:center; justify-content:center; gap:8px;
  margin:10px auto 0; padding:11px 24px;
  background:transparent; color:var(--sec);
  border:1px solid rgba(255,255,255,.18); border-radius:10px;
  font-family:var(--font); font-size:13.5px; font-weight:500; cursor:pointer;
  transition:color .2s ease,border-color .2s ease,transform .2s cubic-bezier(.34,1.56,.64,1);
  -webkit-tap-highlight-color:transparent;
}
.shat-msg-inline svg{width:16px;height:16px;}
.shat-msg-inline:hover{color:#D9A9AC;border-color:rgba(217,169,172,.45);}
.shat-msg-inline:active{transform:scale(.96);}

/* ===== RESPONSIVE ===== */
@media(max-width:640px){
  .shat-feed-root{padding-top:18px;}
  .shat-ig-header{margin:0 14px;padding:26px 66px 24px 22px;border-radius:22px;}
  .shat-ig-top{gap:18px;}
  .shat-ig-avatar-ring{width:82px;height:82px;}
  .shat-ig-name{font-size:22px;}
  .shat-ig-handle{font-size:12.5px;}
  .shat-ig-stats{margin-top:20px;padding-top:18px;}
  .shat-ig-stat .stat-n{font-size:20px;}
  .shat-ig-bio-wrap{padding:16px 18px;margin-top:18px;}
  .shat-ig-bio{font-size:14px;}
  .shat-ig-bio-sub{font-size:13px;}
  .shat-ig-feed-divider{margin:24px 14px 4px;padding:0 14px;}
  .shat-photo-grid{padding:10px 12px 0;}
  .shat-passcode-row{max-width:100%;}
}
@media(max-width:480px){
  .shat-row2{grid-template-columns:1fr;}
  .shat-ig-header{padding:22px 62px 22px 18px;}
  .shat-ig-top{gap:16px;}
  .shat-ig-avatar-ring{width:74px;height:74px;}
  .shat-ig-avatar-badge{width:13px;height:13px;}
  .shat-ig-name{font-size:20px;}
  .shat-ig-name .shat-verified svg{width:16px;height:16px;}
  .shat-ig-handle{font-size:12px;}
  .shat-ig-stat .stat-n{font-size:18px;}
  .shat-ig-stat .stat-l{font-size:9px;letter-spacing:1.4px;}
  .shat-ig-bio{font-size:13.5px;}
  .shat-ig-bio-sub{font-size:12.5px;}
  .shat-photo-card{border-radius:14px;margin-bottom:12px;}
  .shat-passcode-input{font-size:13px;padding:9px 10px;}
  .shat-passcode-btn{font-size:11px;padding:9px 12px;}
}
@media(max-width:360px){
  .shat-ig-header{padding:20px 62px 20px 16px;}
  .shat-ig-avatar-ring{width:68px;height:68px;}
  .shat-ig-name{font-size:18px;}
  .shat-ig-top{gap:14px;}
}
</style>
<!-- Cloudflare Turnstile Script (loaded once, used on all auth pages) -->
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
';
}

// ============================================================
// SHORTCODE: [shatania_login]
// ============================================================
add_shortcode( 'shatania_login', 'shat_sc_login' );
function shat_sc_login() {
	if ( is_user_logged_in() ) {
		if ( shat_is_approved() ) { wp_safe_redirect( shat_page_url( 'shatania-feed' ) ); exit; }
		return shat_sc_pending();
	}

	$err  = '';
	$info = '';
	$step = isset( $_REQUEST['shat_2fa_token'] ) ? 'verify' : 'credentials';
	$pending_token = sanitize_text_field( $_REQUEST['shat_2fa_token'] ?? '' );
	$pending       = $step === 'verify' ? shat_pending_login_get( $pending_token ) : null;
	if ( $step === 'verify' && ! $pending ) {
		$err  = 'Your sign-in session expired. Please start again.';
		$step = 'credentials';
	}

	/* ============= STEP 1 — credentials ============= */
	if ( $step === 'credentials' && isset( $_POST['shat_login_nonce'] ) && wp_verify_nonce( $_POST['shat_login_nonce'], 'shat_do_login' ) ) {

		$cf_token = sanitize_text_field( $_POST['cf-turnstile-response'] ?? '' );
		if ( ! shat_verify_turnstile( $cf_token ) ) {
			$err = 'Security check failed. Please try again.';
		} else {
			$email = sanitize_email( $_POST['shat_email'] ?? '' );
			$pass  = $_POST['shat_pass'] ?? '';
			$remember = isset( $_POST['shat_remember'] );

			if ( ! $email || ! $pass ) {
				$err = 'Please fill in all fields.';
			} else {
				$user = get_user_by( 'email', $email );
				if ( $user && wp_check_password( $pass, $user->data->user_pass, $user->ID ) ) {
					$st = shat_get_status( $user->ID );
					$is_admin = user_can( $user, 'manage_options' );

					if ( ! $is_admin && $st === 'pending' ) {
						$err = 'Your account is pending approval. We\'ll email you once you\'re in.';
					} elseif ( ! $is_admin && $st === 'rejected' ) {
						$err = 'Your account wasn\'t approved. Please contact us for more info.';
					} else {
						if ( $is_admin && shat_admin_has_2fa( $user->ID ) ) {
							// Admin with Google Authenticator → step 2 (TOTP)
							$tok = shat_pending_login_start( $user->ID, 'totp', $remember );
							wp_safe_redirect( add_query_arg( 'shat_2fa_token', $tok, shat_page_url( 'shatania-login' ) ) );
							exit;
						}
						if ( $is_admin && ! shat_admin_has_2fa( $user->ID ) ) {
							// Admin without 2FA configured → sign in normally (they can enable it in admin panel)
							wp_set_current_user( $user->ID );
							wp_set_auth_cookie( $user->ID, $remember );
							do_action( 'wp_login', $user->user_login, $user );
							wp_safe_redirect( admin_url( 'admin.php?page=shatania-2fa' ) );
							exit;
						}
						// Regular user → email code. If the mail cannot be
						// sent, say so here rather than sending them to a
						// code screen for a code that will never arrive.
						if ( ! shat_issue_email_code( $user ) ) {
							$err = 'We could not send your sign-in code right now. Please try again in a moment, or contact ' . esc_html( SHAT_FROM_EMAIL ) . ' if it keeps happening.';
						} else {
							$tok = shat_pending_login_start( $user->ID, 'email', $remember );
							wp_safe_redirect( add_query_arg( 'shat_2fa_token', $tok, shat_page_url( 'shatania-login' ) ) );
							exit;
						}
					}
				} else {
					$err = 'Incorrect email or password.';
				}
			}
		}
	}

	/* ============= STEP 2 — verify code ============= */
	if ( $step === 'verify' && isset( $_POST['shat_2fa_nonce'] ) && wp_verify_nonce( $_POST['shat_2fa_nonce'], 'shat_do_2fa' ) ) {
		$code = sanitize_text_field( $_POST['shat_2fa_code'] ?? '' );

		// resend (email kind only)
		if ( isset( $_POST['shat_resend'] ) && $pending['kind'] === 'email' ) {
			$u = get_userdata( $pending['uid'] );
			if ( $u ) {
				if ( shat_issue_email_code( $u ) ) $info = 'A new code has been sent to your email.';
				else $err = 'We could not send the code. Please try again in a moment.';
			}
		} else {
			$ok = false;
			if ( $pending['kind'] === 'totp' ) {
				$secret = get_user_meta( $pending['uid'], 'shat_2fa_secret', true );
				if ( $secret && shat_totp_verify( $secret, $code ) ) $ok = true;
				else $err = 'Invalid authenticator code. Please try again.';
			} else {
				$ve = shat_verify_email_code( $pending['uid'], $code );
				if ( $ve === '' ) $ok = true; else $err = $ve;
			}
			if ( $ok ) {
				$uid = (int) $pending['uid'];
				$rem = ! empty( $pending['remember'] );
				shat_pending_login_clear( $pending_token );
				wp_set_current_user( $uid );
				wp_set_auth_cookie( $uid, $rem );
				$u = get_userdata( $uid );
				do_action( 'wp_login', $u->user_login, $u );
				if ( user_can( $u, 'manage_options' ) ) {
					wp_safe_redirect( admin_url() );
				} else {
					wp_safe_redirect( shat_page_url( 'shatania-feed' ) );
				}
				exit;
			}
		}
	}

	ob_start();
	echo shat_base_css(); ?>
	<div class="shat-root"><div class="shat-auth-page"><div class="shat-auth-box">
	  <div class="shat-auth-logo">
	    <div class="mark">
	      <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
	        <path d="M12 21.593c-5.63-5.539-11-10.297-11-14.402 0-3.791 3.068-5.191 5.281-5.191 1.312 0 4.151.501 5.719 4.457 1.59-3.968 4.464-4.447 5.726-4.447 2.54 0 5.274 1.621 5.274 5.181 0 4.069-5.136 8.625-11 14.402z"/>
	      </svg>
	    </div>
	    <?php if ( $step === 'verify' && $pending ): ?>
	      <h1>Verify it's you</h1>
	      <p><?php echo $pending['kind'] === 'totp'
	        ? 'Enter the 6-digit code from your Google Authenticator app.'
	        : 'We sent a 6-digit code to your email. Enter it below to continue.'; ?></p>
	    <?php else: ?>
	      <h1>Welcome back</h1>
	      <p>Sign in to your <?php echo esc_html( SHAT_SITE_NAME ); ?> account</p>
	    <?php endif; ?>
	  </div>
	  <?php if ( $err ): ?><div class="shat-msg err"><?php echo esc_html( $err ); ?></div><?php endif; ?>
	  <?php if ( $info ): ?><div class="shat-msg ok"><?php echo esc_html( $info ); ?></div><?php endif; ?>

	  <?php if ( $step === 'verify' && $pending ): ?>
	    <form method="post">
	      <?php wp_nonce_field( 'shat_do_2fa', 'shat_2fa_nonce' ); ?>
	      <input type="hidden" name="shat_2fa_token" value="<?php echo esc_attr( $pending_token ); ?>">
	      <div class="shat-field">
	        <label><?php echo $pending['kind'] === 'totp' ? 'Authenticator Code' : 'Email Code'; ?></label>
	        <input type="text" name="shat_2fa_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
	          placeholder="123456" required autocomplete="one-time-code" autofocus
	          style="letter-spacing:8px;font-size:22px;text-align:center;">
	      </div>
	      <button type="submit" class="shat-btn-primary">Verify &amp; Sign In</button>
	      <?php if ( $pending['kind'] === 'email' ): ?>
	        <button type="submit" name="shat_resend" value="1"
	          style="margin-top:10px;background:none;border:none;color:var(--gold);cursor:pointer;font-size:13px;text-decoration:underline;width:100%;">
	          Resend code
	        </button>
	      <?php endif; ?>
	    </form>
	    <div class="shat-auth-foot">
	      <a href="<?php echo esc_url( shat_page_url( 'shatania-login' ) ); ?>">&larr; Use a different account</a>
	    </div>
	  <?php else: ?>
	    <form method="post">
	      <?php wp_nonce_field( 'shat_do_login', 'shat_login_nonce' ); ?>
	      <div class="shat-field">
	        <label>Email Address</label>
	        <input type="email" name="shat_email" placeholder="you@example.com" value="<?php echo esc_attr( $_POST['shat_email'] ?? '' ); ?>" required autocomplete="email">
	      </div>
	      <div class="shat-field">
	        <label>Password</label>
	        <input type="password" name="shat_pass" placeholder="Your password" required autocomplete="current-password">
	      </div>
	      <div class="shat-remember-row">
	        <label class="shat-remember-label">
	          <input type="checkbox" name="shat_remember" value="1" <?php checked( isset( $_POST['shat_remember'] ) ); ?>>
	          Remember me
	        </label>
	        <a href="<?php echo esc_url( shat_page_url( 'shatania-reset' ) ); ?>" class="shat-forgot-link">Forgot password?</a>
	      </div>
	      <div class="shat-turnstile-wrap">
	        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( SHAT_CF_SITE_KEY ); ?>" data-theme="dark"></div>
	      </div>
	      <button type="submit" class="shat-btn-primary">Continue</button>
	    </form>
	    <div class="shat-auth-foot">New here? <a href="<?php echo esc_url( shat_page_url( 'shatania-register' ) ); ?>">Request Access</a></div>
	  <?php endif; ?>
	</div></div></div>
	<?php
	return ob_get_clean();
}

// ============================================================
// SHORTCODE: [shatania_register]  — two-step, email-verified signup
// ============================================================
// Step 1  Details form  → creates NOTHING, stores a signed pending
//                          signup in a transient + emails a 6-digit OTP.
// Step 2  OTP form      → on success creates the WP user, marks the
//                          email verified, and only THEN fires the
//                          access request to the admin.
// No unverified email ever reaches the members table.
// ============================================================

/* ---- Email-verification gate ---------------------------------
   Anyone who signed up before this feature shipped is treated as
   verified, so nobody already inside gets locked out. Only accounts
   created from now on must pass the OTP. */
add_action( 'init', 'shat_otp_cutover_bootstrap', 1 );
function shat_otp_cutover_bootstrap() {
	if ( ! get_option( 'shat_otp_cutover' ) ) add_option( 'shat_otp_cutover', time() );
}

function shat_email_is_verified( $user_id ) {
	if ( get_user_meta( $user_id, 'shat_email_verified', true ) === '1' ) return true;
	if ( user_can( $user_id, 'manage_options' ) ) return true;
	$u = get_userdata( $user_id );
	if ( ! $u ) return false;
	return (bool) get_option( 'shat_otp_cutover' )
		&& strtotime( $u->user_registered ) < (int) get_option( 'shat_otp_cutover' );
}

/* ---- Instagram handle -----------------------------------------
   Accepts what people actually paste: "@name", "name",
   "instagram.com/name", or a full URL with query junk. Always stored
   bare (no @) so it can be linked and compared consistently.
   IG allows letters, numbers, underscore and period; max 30. */
function shat_sanitize_ig_handle( $raw ) {
	$h = trim( (string) $raw );
	if ( $h === '' ) return '';
	// Strip a pasted profile URL down to the username segment.
	if ( preg_match( '~(?:instagram\.com|instagr\.am)/+([^/?#\s]+)~i', $h, $m ) ) $h = $m[1];
	$h = ltrim( $h, '@' );
	$h = strtok( $h, '?' );          // drop ?igshid=... tails
	$h = rtrim( $h, '/' );
	$h = strtolower( $h );
	if ( ! preg_match( '/^[a-z0-9._]{1,30}$/', $h ) ) return '';
	return $h;
}

/* ---- Pending-signup store (transient, 15 min) ---------------- */
function shat_signup_start( $data ) {
	$token = bin2hex( random_bytes( 24 ) );
	set_transient( 'shat_su_' . $token, $data, 15 * MINUTE_IN_SECONDS );
	return $token;
}
function shat_signup_get( $token ) {
	if ( ! $token || ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) return null;
	$d = get_transient( 'shat_su_' . $token );
	return is_array( $d ) ? $d : null;
}
function shat_signup_put( $token, $data ) {
	if ( $token ) set_transient( 'shat_su_' . $token, $data, 15 * MINUTE_IN_SECONDS );
}
function shat_signup_clear( $token ) {
	if ( $token ) delete_transient( 'shat_su_' . $token );
}

/* ---- Issue / re-issue the verification code ------------------ */
function shat_signup_issue_code( $token, &$data ) {
	$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	$data['code_hash'] = hash( 'sha256', $code );
	$data['code_exp']  = time() + 10 * MINUTE_IN_SECONDS;
	$data['tries']     = 5;
	$data['last_sent'] = time();
	$data['resends']   = (int) ( $data['resends'] ?? 0 );
	shat_signup_put( $token, $data );

	$body = '<p style="font-size:15px;color:#333;line-height:1.6;">Hi ' . esc_html( $data['fname'] ) . ',</p>'
		. '<p style="font-size:14px;color:#444;line-height:1.7;">Please confirm this is your email address to continue your ' . esc_html( SHAT_SITE_NAME ) . ' access request. Enter this code on the verification screen:</p>'
		. '<div style="font-size:34px;letter-spacing:8px;font-weight:600;color:#1f1f1f;background:#FBF0F1;border:1px solid #E8C4C6;border-radius:12px;padding:18px;text-align:center;margin:18px 0;">'
		. esc_html( $code ) . '</div>'
		. '<p style="font-size:13px;color:#888;">This code expires in 10 minutes. Your request isn\'t submitted until the code is confirmed. If you didn\'t request this, simply ignore this email — no account has been created.</p>';
	shat_mail( $data['email'], 'Verify your email — code ' . $code,
		shat_email_wrap( 'Verify your email', $body ) );
}

add_shortcode( 'shatania_register', 'shat_sc_register' );
function shat_sc_register() {
	if ( is_user_logged_in() ) {
		if ( shat_is_approved() ) { wp_safe_redirect( shat_page_url( 'shatania-feed' ) ); exit; }
		return shat_sc_pending();
	}

	$err = $ok = $notice = '';
	$step  = 'details';
	$token = isset( $_REQUEST['shat_su_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['shat_su_token'] ) ) : '';
	$pend  = $token ? shat_signup_get( $token ) : null;
	if ( $pend ) $step = 'verify';

	/* ============ STEP 1 — details submitted ============ */
	if ( isset( $_POST['shat_reg_nonce'] ) && wp_verify_nonce( $_POST['shat_reg_nonce'], 'shat_do_register' ) ) {

		$cf_token = sanitize_text_field( $_POST['cf-turnstile-response'] ?? '' );
		if ( ! shat_verify_turnstile( $cf_token ) ) {
			$err = 'Security check failed. Please try again.';
		} else {
			$fname  = sanitize_text_field( $_POST['shat_fname'] ?? '' );
			$lname  = sanitize_text_field( $_POST['shat_lname'] ?? '' );
			$email  = sanitize_email( $_POST['shat_email'] ?? '' );
			$pass   = $_POST['shat_pass']  ?? '';
			$pass2  = $_POST['shat_pass2'] ?? '';
			$gender = shat_sanitize_gender( $_POST['shat_gender'] ?? '' );
			$ig     = shat_sanitize_ig_handle( $_POST['shat_ig'] ?? '' );

			if ( ! $fname || ! $email || ! $pass )   { $err = 'Please fill in all required fields.'; }
			elseif ( ! is_email( $email ) )           { $err = 'Please enter a valid email address.'; }
			elseif ( strlen( $pass ) < 8 )            { $err = 'Password must be at least 8 characters.'; }
			elseif ( $pass !== $pass2 )               { $err = 'Passwords do not match.'; }
			elseif ( email_exists( $email ) )         { $err = 'An account with this email already exists.'; }
			else {
				/* Nothing is written to the database yet. We hold the
				   details in a short-lived transient and hand the user a
				   token. The account is born only after the code checks out. */
				$pend = [
					'fname'  => $fname,
					'lname'  => $lname,
					'email'  => $email,
					'pass'   => $pass,
					'gender' => $gender,
					'ig'     => $ig,
				];
				$token = shat_signup_start( $pend );
				shat_signup_issue_code( $token, $pend );
				$step   = 'verify';
				/* No banner here — the hint under the heading already
				   states where the code went. Saying it twice is noise. */
			}
		}
	}

	/* ============ RESEND ============ */
	if ( isset( $_POST['shat_resend_nonce'] ) && wp_verify_nonce( $_POST['shat_resend_nonce'], 'shat_do_resend' ) && $pend ) {
		$since = time() - (int) ( $pend['last_sent'] ?? 0 );
		if ( (int) ( $pend['resends'] ?? 0 ) >= 3 ) {
			$err = 'Resend limit reached. Please start again.';
		} elseif ( $since < 60 ) {
			$err = 'Please wait ' . ( 60 - $since ) . ' seconds before requesting another code.';
		} else {
			$pend['resends'] = (int) ( $pend['resends'] ?? 0 ) + 1;
			shat_signup_issue_code( $token, $pend );
			$notice = 'A new verification code has been sent.';
		}
		$step = 'verify';
	}

	/* ============ STEP 2 — code submitted ============ */
	if ( isset( $_POST['shat_otp_nonce'] ) && wp_verify_nonce( $_POST['shat_otp_nonce'], 'shat_do_otp' ) ) {

		if ( ! $pend ) {
			$err  = 'Your verification session expired. Please enter your details again.';
			$step = 'details';
		} else {
			$code  = preg_replace( '/\s+/', '', (string) ( $_POST['shat_otp'] ?? '' ) );
			$exp   = (int) ( $pend['code_exp'] ?? 0 );
			$tries = (int) ( $pend['tries'] ?? 0 );

			if ( ! ctype_digit( $code ) || strlen( $code ) !== 6 ) {
				$err = 'Enter the 6-digit code from your email.';
			} elseif ( time() > $exp ) {
				$err = 'That code has expired. Send yourself a new one below.';
			} elseif ( $tries <= 0 ) {
				$err = 'Too many attempts. Request a new code below.';
			} elseif ( ! hash_equals( (string) $pend['code_hash'], hash( 'sha256', $code ) ) ) {
				$pend['tries'] = $tries - 1;
				shat_signup_put( $token, $pend );
				$err = 'Incorrect code. ' . max( 0, $tries - 1 ) . ' tries remaining.';
			} elseif ( email_exists( $pend['email'] ) ) {
				/* Race guard: someone claimed it while the code was in flight. */
				shat_signup_clear( $token );
				$err  = 'An account with this email already exists.';
				$step = 'details';
			} else {
				/* ---- Verified. Create the account, THEN request access ---- */
				$fname = $pend['fname']; $lname = $pend['lname']; $email = $pend['email'];

				$uname = sanitize_user( strtolower( $fname . ( $lname ? '.' . $lname : '' ) ) );
				if ( username_exists( $uname ) ) $uname .= wp_rand( 10, 999 );
				$uid = wp_create_user( $uname, $pend['pass'], $email );

				if ( is_wp_error( $uid ) ) {
					$err = $uid->get_error_message();
				} else {
					wp_update_user( [ 'ID' => $uid, 'first_name' => $fname, 'last_name' => $lname, 'display_name' => trim( "$fname $lname" ) ] );
					/* Manual-approval toggle decides the starting status:
					   ON  -> 'pending' (admin reviews first, original flow)
					   OFF -> 'approved' (instant access; app download next)  */
					$shat_auto_approve = ! shat_require_approval();
					update_user_meta( $uid, 'shat_status', $shat_auto_approve ? 'approved' : 'pending' );
					update_user_meta( $uid, 'shat_joined_date', current_time( 'mysql' ) );
					update_user_meta( $uid, 'shat_gender', $pend['gender'] );
					if ( ! empty( $pend['ig'] ) ) update_user_meta( $uid, 'shat_ig', $pend['ig'] );
					/* The proof that gates everything downstream. */
					update_user_meta( $uid, 'shat_email_verified', '1' );
					update_user_meta( $uid, 'shat_email_verified_at', current_time( 'mysql' ) );
					if ( $shat_auto_approve ) {
						update_user_meta( $uid, 'shat_approved_date', current_time( 'mysql' ) );
						update_user_meta( $uid, 'shat_auto_approved', '1' );
					}

					shat_signup_clear( $token );

					$ig_line = ! empty( $pend['ig'] )
						? '<p style="font-size:14px;color:#444;">Instagram: <a href="' . esc_url( 'https://instagram.com/' . $pend['ig'] ) . '">@' . esc_html( $pend['ig'] ) . '</a></p>'
						: '<p style="font-size:13px;color:#999;">No Instagram handle provided.</p>';

					if ( $shat_auto_approve ) {
						/* AUTO-APPROVE PATH ------------------------------------ */
						/* Admin gets an FYI (not an action item). */
						shat_mail( get_option( 'admin_email' ),
							'New Member Joined — Shatania',
							shat_email_wrap( 'New Member Joined',
								'<p>Hi there,</p><p><strong>' . esc_html( "$fname $lname" ) . '</strong> (' . esc_html( $email ) . ') just joined Shatania.</p>
								' . $ig_line . '
								<p style="font-size:13px;color:#2e7d32;">✓ Email verified. Auto-approved (manual approval is off).</p>
								<p><a href="' . esc_url( admin_url( 'admin.php?page=shatania-members' ) ) . '" class="btn">View Members &rarr;</a></p>' ) );

						/* Member gets a warm welcome: the feed first, the app second. */
						$shat_feed_url = shat_page_url( 'shatania-feed' );
						$shat_apk = function_exists( 'shat_apk_download_url' ) ? shat_apk_download_url() : '';
						$shat_cta = '<p><a href="' . esc_url( $shat_feed_url ) . '" class="btn">Open the Feed &rarr;</a></p>';
						if ( $shat_apk ) {
							$shat_cta .= '<p style="font-size:14px;color:#444;">Prefer the Android app? <a href="' . esc_url( $shat_apk ) . '">Download the APK here</a> for push alerts on new posts and messages.</p>';
						}
						shat_mail( $email,
							'You\'re in ✨ Welcome to Shatania',
							shat_email_wrap( 'Welcome to Shatania',
								'<p>Hi <strong>' . esc_html( $fname ) . '</strong>,</p>
								<p>Your email is verified and your account is ready — no waiting.</p>
								<p>Sign in from any browser to start browsing the feed right now.</p>
								' . $shat_cta . '
								<p style="color:#999;font-size:13px;margin-top:20px;">— Shatania</p>' ) );

						$ok   = 'You\'re in! Your account is ready — open the feed and start browsing. ✨';
						$step = 'done';
					} else {
						/* MANUAL-APPROVAL PATH (original behaviour) ------------ */
						shat_mail( get_option( 'admin_email' ),
							'New Member Request — Shatania',
							shat_email_wrap( 'New Member Request',
								'<p>Hi there,</p><p><strong>' . esc_html( "$fname $lname" ) . '</strong> (' . esc_html( $email ) . ') has requested access to Shatania.</p>
								' . $ig_line . '
								<p style="font-size:13px;color:#2e7d32;">✓ Email address verified by one-time code.</p>
								<p><a href="' . esc_url( admin_url( 'admin.php?page=shatania-members&filter=pending' ) ) . '" class="btn">Review Members &rarr;</a></p>' ) );

						shat_mail( $email,
							'You\'re on the Shatania waitlist ✨',
							shat_email_wrap( 'You\'re on the waitlist',
								'<p>Hi <strong>' . esc_html( $fname ) . '</strong>,</p>
								<p>Your email is confirmed and your access request has been received.</p>
								<p>You\'ll get an email the moment you\'re approved. Thank you for your patience.</p>
								<p style="color:#999;font-size:13px;margin-top:20px;">— Shatania</p>' ) );

						$ok   = 'Email verified! Your access request has been submitted. You\'ll be notified by email once it\'s approved. ✨';
						$step = 'done';
					}
				}
			}
			if ( $step !== 'done' && $step !== 'details' ) $step = 'verify';
		}
	}

	ob_start();
	echo shat_base_css(); ?>
	<style>
	.shat-otp-hint{font-size:13px;color:var(--sec);line-height:1.6;text-align:center;margin:-4px 0 18px;}
	.shat-otp-hint strong{color:var(--text);font-weight:500;}
	.shat-otp-input{letter-spacing:14px;font-size:24px;text-align:center;font-variant-numeric:tabular-nums;padding-left:14px!important;}
	.shat-otp-meta{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;font-size:13px;color:var(--sec);}
	.shat-otp-meta form{margin:0;}
	.shat-linkbtn{background:none;border:0;padding:0;font:inherit;color:var(--gold);cursor:pointer;text-decoration:none;border-bottom:1px solid rgba(217,169,172,.35);}
	.shat-linkbtn:hover{color:var(--gold-light);border-bottom-color:var(--gold-light);}
	.shat-steps{display:flex;align-items:center;justify-content:center;gap:10px;margin:0 0 22px;}
	.shat-steps span{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--sec);}
	.shat-steps span.on{color:var(--gold);}
	.shat-steps i{display:block;width:26px;height:1px;background:var(--line);}
	.shat-ig-wrap{position:relative;display:block;}
	.shat-ig-at{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--sec);font-size:15px;pointer-events:none;line-height:1;}
	.shat-ig-input{padding-left:30px!important;}
	</style>
	<div class="shat-root"><div class="shat-auth-page"><div class="shat-auth-box">
	  <div class="shat-auth-logo">
	    <div class="mark">
	      <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
	        <path d="M12 21.593c-5.63-5.539-11-10.297-11-14.402 0-3.791 3.068-5.191 5.281-5.191 1.312 0 4.151.501 5.719 4.457 1.59-3.968 4.464-4.447 5.726-4.447 2.54 0 5.274 1.621 5.274 5.181 0 4.069-5.136 8.625-11 14.402z"/>
	      </svg>
	    </div>
	    <h1><?php echo $step === 'verify' ? 'Verify Your Email' : 'Request Access'; ?></h1>
	    <p><?php echo $step === 'verify' ? 'One quick step before your request is sent' : 'Join the Shatania community'; ?></p>
	  </div>

	  <?php if ( $step !== 'done' ): ?>
	  <div class="shat-steps">
	    <span class="<?php echo $step === 'details' ? 'on' : ''; ?>">1 · Details</span>
	    <i></i>
	    <span class="<?php echo $step === 'verify'  ? 'on' : ''; ?>">2 · Verify</span>
	  </div>
	  <?php endif; ?>

	  <?php if ( $err    ): ?><div class="shat-msg err"><?php echo esc_html( $err );    ?></div><?php endif; ?>
	  <?php if ( $ok     ): ?><div class="shat-msg ok" ><?php echo esc_html( $ok  );    ?></div><?php endif; ?>
	  <?php if ( $notice && ! $ok ): ?><div class="shat-msg ok"><?php echo esc_html( $notice ); ?></div><?php endif; ?>

	  <?php if ( $step === 'verify' && $pend ): ?>
	  <!-- ============ STEP 2 : OTP ============ -->
	  <p class="shat-otp-hint">A 6-digit verification code has been sent to<br><strong><?php echo esc_html( $pend['email'] ); ?></strong></p>
	  <form method="post">
	    <?php wp_nonce_field( 'shat_do_otp', 'shat_otp_nonce' ); ?>
	    <input type="hidden" name="shat_su_token" value="<?php echo esc_attr( $token ); ?>">
	    <div class="shat-field">
	      <label>Verification Code <span style="color:#E53935;">*</span></label>
	      <input type="text" name="shat_otp" class="shat-otp-input" inputmode="numeric" pattern="[0-9]{6}"
	             maxlength="6" autocomplete="one-time-code" placeholder="000000" required autofocus>
	    </div>
	    <button type="submit" class="shat-btn-primary">Verify &amp; Send Request</button>
	  </form>
	  <div class="shat-otp-meta">
	    <form method="post">
	      <?php wp_nonce_field( 'shat_do_resend', 'shat_resend_nonce' ); ?>
	      <input type="hidden" name="shat_su_token" value="<?php echo esc_attr( $token ); ?>">
	      <button type="submit" class="shat-linkbtn">Resend code</button>
	    </form>
	    <a href="<?php echo esc_url( shat_page_url( 'shatania-register' ) ); ?>" class="shat-linkbtn">Change email</a>
	  </div>

	  <?php elseif ( $step === 'details' ): ?>
	  <!-- ============ STEP 1 : DETAILS ============ -->
	  <form method="post">
	    <?php wp_nonce_field( 'shat_do_register', 'shat_reg_nonce' ); ?>
	    <div class="shat-row2">
	      <div class="shat-field"><label>First Name <span style="color:#E53935;">*</span></label><input type="text" name="shat_fname" placeholder="Jane" value="<?php echo esc_attr( $_POST['shat_fname'] ?? '' ); ?>" required autocomplete="given-name"></div>
	      <div class="shat-field"><label>Last Name</label><input type="text" name="shat_lname" placeholder="Doe" value="<?php echo esc_attr( $_POST['shat_lname'] ?? '' ); ?>" autocomplete="family-name"></div>
	    </div>
	    <div class="shat-field"><label>Email Address <span style="color:#E53935;">*</span></label><input type="email" name="shat_email" placeholder="you@example.com" value="<?php echo esc_attr( $_POST['shat_email'] ?? '' ); ?>" required autocomplete="email"></div>
	    <div class="shat-row2">
	      <div class="shat-field">
	        <label>Gender <span style="color:var(--sec);font-weight:400;">(optional)</span></label>
	        <?php
	        $shat_g_sel = shat_sanitize_gender( $_POST['shat_gender'] ?? '' );
	        if ( $shat_g_sel === 'unspecified' ) $shat_g_sel = '';
	        ?>
	        <select name="shat_gender" class="shat-select">
	          <?php foreach ( shat_gender_options() as $g_key => $g_label ): ?>
	            <option value="<?php echo esc_attr( $g_key ); ?>"<?php selected( $shat_g_sel, $g_key ); ?>><?php echo esc_html( $g_label ); ?></option>
	          <?php endforeach; ?>
	        </select>
	      </div>
	      <div class="shat-field">
	        <label>Instagram <span style="color:var(--sec);font-weight:400;">(optional)</span></label>
	        <div class="shat-ig-wrap">
	          <span class="shat-ig-at">@</span>
	          <input type="text" name="shat_ig" class="shat-ig-input" placeholder="yourhandle"
	                 value="<?php echo esc_attr( shat_sanitize_ig_handle( $_POST['shat_ig'] ?? '' ) ); ?>"
	                 maxlength="40" autocapitalize="none" autocorrect="off" spellcheck="false">
	        </div>
	      </div>
	    </div>
	    <div class="shat-field"><label>Password <span style="color:#E53935;">*</span></label><input type="password" name="shat_pass" placeholder="Minimum 8 characters" required autocomplete="new-password"></div>
	    <div class="shat-field"><label>Confirm Password <span style="color:#E53935;">*</span></label><input type="password" name="shat_pass2" placeholder="Repeat your password" required autocomplete="new-password"></div>
	    <div class="shat-turnstile-wrap">
	      <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( SHAT_CF_SITE_KEY ); ?>" data-theme="dark"></div>
	    </div>
	    <button type="submit" class="shat-btn-primary">Continue</button>
	  </form>
	  <?php endif; ?>

	  <div class="shat-auth-foot">Already a member? <a href="<?php echo esc_url( shat_page_url( 'shatania-login' ) ); ?>">Sign In</a></div>
	</div></div></div>
	<?php
	return ob_get_clean();
}

// ============================================================
// PENDING PAGE (inline helper, not a shortcode)
// ============================================================
function shat_sc_pending() {
	$uid   = get_current_user_id();
	$udata = get_userdata( $uid );
	$name  = $udata ? ( $udata->first_name ?: $udata->display_name ) : 'there';
	$email = $udata ? $udata->user_email : '';
	shat_ensure_access_requests_table();
	$shat_active_access_req = shat_get_active_request_of_type( $uid, 'access' );

	ob_start();
	echo shat_base_css(); ?>
	<div class="shat-root"><div class="shat-status-page"><div class="shat-status-box">
	  <span class="icon">⏳</span>
	  <h2>Pending Approval</h2>
	  <p>Hi <strong><?php echo esc_html( $name ); ?></strong>,<br>
	  Your account is currently under review. We'll send a confirmation to
	  <strong><?php echo esc_html( $email ); ?></strong> once you're approved.</p>

	  <div style="margin-top:22px;padding-top:20px;border-top:1px solid var(--line);">
	    <?php if ( $shat_active_access_req ): ?>
	    <p style="font-size:13.5px;color:var(--sec);line-height:1.6;margin-bottom:14px;">
	      Your request is on file. I'll reply by email at <strong><?php echo esc_html( $email ); ?></strong> &mdash; once you're approved you'll be able to message me directly here.
	    </p>
	    <button type="button" class="shat-logout-link" disabled style="background:rgba(255,255,255,.06);color:var(--sec);border:none;width:100%;font-family:inherit;cursor:default;opacity:.7;">
	      Request In Progress
	    </button>
	    <?php else: ?>
	    <p style="font-size:13.5px;color:var(--sec);line-height:1.6;margin-bottom:14px;">
	      Want faster access? Send us a quick note.
	    </p>
	    <button type="button" onclick="shatOpenRequestSheet('access',0)" class="shat-logout-link shat-req-trigger" style="background:var(--gold);color:#141414;border:none;width:100%;font-family:inherit;">
	      Request Access
	    </button>
	    <?php endif; ?>
	  </div>

	  <div style="margin-top:14px;">
	    <a href="<?php echo esc_url( wp_logout_url( shat_page_url( 'shatania-login' ) ) ); ?>" class="shat-logout-link js-shat-logout">Sign Out</a>
	  </div>
	</div></div></div>
	<?php
	return ob_get_clean();
}

// ============================================================
// SHORTCODE: [shatania_reset]  — Password Reset (NEW in 1.4.1)
// ============================================================
add_shortcode( 'shatania_reset', 'shat_sc_reset' );
function shat_sc_reset() {
	/* If already logged in and approved, send to feed */
	if ( is_user_logged_in() && shat_is_approved() ) {
		wp_safe_redirect( shat_page_url( 'shatania-feed' ) );
		exit;
	}

	$err = $ok = '';

	/* -------------------------------------------------------
	   STEP 2 — User clicked the link in their email.
	   URL contains ?shat_reset_key=KEY&shat_reset_login=LOGIN
	   ------------------------------------------------------- */
	$reset_key   = sanitize_text_field( $_GET['shat_reset_key']   ?? '' );
	$reset_login = sanitize_text_field( $_GET['shat_reset_login'] ?? '' );
	$step        = ( $reset_key && $reset_login ) ? 2 : 1;

	/* ---- STEP 2 POST: set new password ---- */
	if ( $step === 2 && isset( $_POST['shat_newpass_nonce'] ) && wp_verify_nonce( $_POST['shat_newpass_nonce'], 'shat_do_newpass' ) ) {

		/* --- Turnstile verification --- */
		$cf_token = sanitize_text_field( $_POST['cf-turnstile-response'] ?? '' );
		if ( ! shat_verify_turnstile( $cf_token ) ) {
			$err = 'Security check failed. Please try again.';
		} else {
			$new_pass  = $_POST['shat_new_pass']  ?? '';
			$new_pass2 = $_POST['shat_new_pass2'] ?? '';
			$rk        = sanitize_text_field( $_POST['shat_rkey']   ?? '' );
			$rl        = sanitize_text_field( $_POST['shat_rlogin'] ?? '' );

			if ( strlen( $new_pass ) < 8 ) {
				$err = 'Password must be at least 8 characters.';
			} elseif ( $new_pass !== $new_pass2 ) {
				$err = 'Passwords do not match.';
			} else {
				$user = check_password_reset_key( $rk, $rl );
				if ( is_wp_error( $user ) ) {
					$err = 'This reset link has expired or is invalid. Please request a new one.';
					$step = 1; // fall back to request form
				} else {
					reset_password( $user, $new_pass );
					$ok   = 'Your password has been updated! You can now sign in.';
					$step = 'done';
				}
			}
		}
	}

	/* ---- STEP 1 POST: send reset email ---- */
	if ( $step === 1 && isset( $_POST['shat_reset_nonce'] ) && wp_verify_nonce( $_POST['shat_reset_nonce'], 'shat_do_reset' ) ) {

		/* --- Turnstile verification --- */
		$cf_token = sanitize_text_field( $_POST['cf-turnstile-response'] ?? '' );
		if ( ! shat_verify_turnstile( $cf_token ) ) {
			$err = 'Security check failed. Please try again.';
		} else {
			$email = sanitize_email( $_POST['shat_email'] ?? '' );
			if ( ! is_email( $email ) ) {
				$err = 'Please enter a valid email address.';
			} else {
				$user = get_user_by( 'email', $email );
				/* Always show success to prevent email enumeration */
				if ( $user ) {
					$key       = get_password_reset_key( $user );
					$reset_url = add_query_arg( [
						'shat_reset_key'   => $key,
						'shat_reset_login' => rawurlencode( $user->user_login ),
					], shat_page_url( 'shatania-reset' ) );

					shat_mail( $email,
						'Reset your Shatania password',
						shat_email_wrap( 'Reset your password',
							'<p>Hi <strong>' . esc_html( $user->first_name ?: $user->display_name ) . '</strong>,</p>
							<p>We received a request to reset the password for your Shatania account.</p>
							<p><a href="' . esc_url( $reset_url ) . '" class="btn">Reset Password &rarr;</a></p>
							<p style="color:#999;font-size:13px;">This link will expire in 24 hours. If you didn\'t request a reset, you can safely ignore this email.</p>
							<p style="color:#999;font-size:13px;margin-top:20px;">— Shatania</p>'
						)
					);
				}
				$ok = 'If that email is registered, we\'ve sent a reset link. Please check your inbox.';
			}
		}
	}

	ob_start();
	echo shat_base_css(); ?>
	<div class="shat-root"><div class="shat-auth-page"><div class="shat-auth-box">
	  <div class="shat-auth-logo">
	    <div class="mark">
	      <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="#141414" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
	        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
	        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
	      </svg>
	    </div>
	    <?php if ( $step === 'done' ): ?>
	      <h1>Password Updated</h1>
	      <p>Your new password is ready to use</p>
	    <?php elseif ( $step === 2 ): ?>
	      <h1>Set New Password</h1>
	      <p>Choose a new password for your account</p>
	    <?php else: ?>
	      <h1>Reset Password</h1>
	      <p>We'll send a reset link to your email</p>
	    <?php endif; ?>
	  </div>

	  <?php if ( $err ): ?><div class="shat-msg err"><?php echo esc_html( $err ); ?></div><?php endif; ?>
	  <?php if ( $ok  ): ?><div class="shat-msg ok" ><?php echo esc_html( $ok  ); ?></div><?php endif; ?>

	  <?php if ( $step === 'done' ): ?>
	    <!-- Done state — just show link to login -->
	    <div style="text-align:center;margin-top:8px;">
	      <a href="<?php echo esc_url( shat_page_url( 'shatania-login' ) ); ?>" class="shat-btn-primary" style="display:inline-block;text-decoration:none;padding:15px 32px;">Sign In Now &rarr;</a>
	    </div>

	  <?php elseif ( $step === 2 && ! $err ): ?>
	    <!-- STEP 2: New password form (only if key not expired) -->
	    <form method="post">
	      <?php wp_nonce_field( 'shat_do_newpass', 'shat_newpass_nonce' ); ?>
	      <input type="hidden" name="shat_rkey"   value="<?php echo esc_attr( $reset_key ); ?>">
	      <input type="hidden" name="shat_rlogin" value="<?php echo esc_attr( $reset_login ); ?>">
	      <div class="shat-field">
	        <label>New Password <span style="color:#E53935;">*</span></label>
	        <input type="password" name="shat_new_pass" placeholder="Minimum 8 characters" required autocomplete="new-password">
	      </div>
	      <div class="shat-field">
	        <label>Confirm New Password <span style="color:#E53935;">*</span></label>
	        <input type="password" name="shat_new_pass2" placeholder="Repeat your new password" required autocomplete="new-password">
	      </div>
	      <!-- Cloudflare Turnstile widget -->
	      <div class="shat-turnstile-wrap">
	        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( SHAT_CF_SITE_KEY ); ?>" data-theme="dark"></div>
	      </div>
	      <button type="submit" class="shat-btn-primary">Update Password</button>
	    </form>

	  <?php elseif ( $step === 1 && ! $ok ): ?>
	    <!-- STEP 1: Email request form -->
	    <form method="post">
	      <?php wp_nonce_field( 'shat_do_reset', 'shat_reset_nonce' ); ?>
	      <div class="shat-field">
	        <label>Email Address</label>
	        <input type="email" name="shat_email" placeholder="you@example.com" value="<?php echo esc_attr( $_POST['shat_email'] ?? '' ); ?>" required autocomplete="email">
	      </div>
	      <!-- Cloudflare Turnstile widget -->
	      <div class="shat-turnstile-wrap">
	        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( SHAT_CF_SITE_KEY ); ?>" data-theme="dark"></div>
	      </div>
	      <button type="submit" class="shat-btn-primary">Send Reset Link</button>
	    </form>

	  <?php endif; ?>

	  <div class="shat-auth-foot">
	    Remembered it? <a href="<?php echo esc_url( shat_page_url( 'shatania-login' ) ); ?>">Sign In</a>
	    &nbsp;&middot;&nbsp;
	    New here? <a href="<?php echo esc_url( shat_page_url( 'shatania-register' ) ); ?>">Request Access</a>
	  </div>
	</div></div></div>
	<?php
	return ob_get_clean();
}

// ============================================================
// SHORTCODE: [shatania_join] — standalone Patreon conversion page
// ============================================================
add_shortcode( 'shatania_join', 'shat_sc_join' );
function shat_sc_join() {
	/* Pull real photos for the scrolling background, blurred client-side via
	   CSS so nothing sensitive is ever legible — this is a marketing page,
	   visible to logged-out visitors, so content must never be readable here.
	   Photos flagged "Exclusive — hide from Google" are skipped entirely:
	   this page is the ONLY spot in the whole plugin that embeds a real,
	   crawlable image URL for a logged-out visitor, so it's the one place
	   that actually matters for keeping those photos out of Google. */
	$candidates = shat_get_photos( 1, 60 );
	$urls   = [];
	foreach ( $candidates as $p ) {
		if ( shat_is_noindex( $p->ID ) ) continue;
		$imgs = shat_get_gallery_images( $p->ID, 'large' );
		if ( ! empty( $imgs ) ) $urls[] = $imgs[0];
		if ( count( $urls ) >= 24 ) break;
	}
	// Fallback so the page still looks intentional even on a brand-new site with no photos yet.
	if ( empty( $urls ) ) $urls = [ SHAT_PROFILE_PIC ];

	// Split into two columns, each repeated to guarantee a smooth, seamless scroll loop.
	$col_a = []; $col_b = [];
	foreach ( $urls as $i => $u ) { if ( $i % 2 === 0 ) $col_a[] = $u; else $col_b[] = $u; }
	if ( empty( $col_a ) ) $col_a = $urls;
	if ( empty( $col_b ) ) $col_b = array_reverse( $urls );
	$col_a = array_merge( $col_a, $col_a, $col_a );
	$col_b = array_merge( $col_b, $col_b, $col_b );

	$lines = [
		'You\'ve seen the preview.',
		'This is what\'s behind it.',
		'New sets, every week — yours the moment they\'re posted.',
		'One place. No noise. Just Shatania.',
	];

	ob_start(); ?>
	<div class="shat-join-root">
	  <div class="shat-join-bg" aria-hidden="true">
	    <div class="shat-join-col shat-join-col-a">
	      <?php foreach ( $col_a as $u ): ?>
	        <div class="shat-join-tile"><img src="<?php echo esc_url( $u ); ?>" alt="" loading="lazy"></div>
	      <?php endforeach; ?>
	    </div>
	    <div class="shat-join-col shat-join-col-b">
	      <?php foreach ( $col_b as $u ): ?>
	        <div class="shat-join-tile"><img src="<?php echo esc_url( $u ); ?>" alt="" loading="lazy"></div>
	      <?php endforeach; ?>
	    </div>
	  </div>

	  <div class="shat-join-veil"></div>

	  <div class="shat-join-content">
	    <span class="shat-join-eyebrow">Shatania</span>
	    <div class="shat-join-cycle" id="shat-join-cycle">
	      <?php foreach ( $lines as $i => $line ): ?>
	        <p class="shat-join-line<?php echo $i === 0 ? ' active' : ''; ?>"><?php echo esc_html( $line ); ?></p>
	      <?php endforeach; ?>
	    </div>
	    <a href="<?php echo esc_url( SHAT_PATREON_URL ); ?>" class="shat-join-cta" target="_blank" rel="noopener">
	      Join on Patreon
	    </a>
	    <a href="<?php echo esc_url( shat_page_url( 'shatania-login' ) ); ?>" class="shat-join-signin">Already a member? Sign in</a>
	  </div>
	</div>

	<style>
	.shat-join-root{
	  position:relative; min-height:100vh; overflow:hidden;
	  background:#141414;
	  font-family:"Zilla Slab",Georgia,serif;
	}
	.shat-join-bg{
	  position:absolute; inset:0; display:flex; gap:3px;
	  filter:blur(9px) brightness(.55) saturate(.85);
	  transform:scale(1.08); /* hides blur edge softening at the frame */
	}
	.shat-join-col{
	  flex:1; display:flex; flex-direction:column; gap:3px; min-width:0;
	  will-change:transform;
	}
	.shat-join-col-a{ animation:shatJoinScrollUp 70s linear infinite; }
	.shat-join-col-b{ animation:shatJoinScrollDown 70s linear infinite; }
	@keyframes shatJoinScrollUp{ from{transform:translateY(0);} to{transform:translateY(-33.333%);} }
	@keyframes shatJoinScrollDown{ from{transform:translateY(-33.333%);} to{transform:translateY(0);} }
	.shat-join-tile{width:100%; aspect-ratio:1/1; overflow:hidden; flex-shrink:0;}
	.shat-join-tile img{width:100%; height:100%; object-fit:cover; display:block;}

	.shat-join-veil{
	  position:absolute; inset:0;
	  background:
	    radial-gradient(ellipse at center, rgba(10,10,10,.55) 0%, rgba(10,10,10,.86) 62%, rgba(8,8,8,.96) 100%);
	}

	.shat-join-content{
	  position:relative; z-index:2; min-height:100vh;
	  display:flex; flex-direction:column; align-items:center; justify-content:center;
	  text-align:center; padding:32px 24px;
	}
	.shat-join-eyebrow{
	  font-size:12px; font-weight:600; letter-spacing:3px; text-transform:uppercase;
	  color:#D9A9AC; margin-bottom:22px;
	  opacity:0; animation:shatJoinFadeUp .8s cubic-bezier(.22,.9,.32,1) .1s forwards;
	}
	.shat-join-cycle{
	  position:relative; width:100%; max-width:640px;
	  height:auto; min-height:120px;
	  display:flex; align-items:center; justify-content:center;
	  margin-bottom:34px;
	}
	.shat-join-line{
	  position:absolute; left:0; right:0; margin:0;
	  font-size:clamp(24px,4.4vw,42px); font-weight:500; color:#F5F5F5;
	  line-height:1.3; letter-spacing:-.3px;
	  opacity:0; transform:translateY(14px);
	  transition:opacity .7s cubic-bezier(.22,.9,.32,1), transform .7s cubic-bezier(.22,.9,.32,1);
	  pointer-events:none;
	}
	.shat-join-line.active{
	  position:relative; opacity:1; transform:translateY(0); pointer-events:auto;
	}
	.shat-join-cta{
	  display:inline-block; padding:16px 44px; margin-bottom:16px;
	  background:#D9A9AC; color:#141414; text-decoration:none;
	  border-radius:100px; font-size:16px; font-weight:600; letter-spacing:.2px;
	  box-shadow:0 8px 30px rgba(217,169,172,.28);
	  transition:transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .3s ease, opacity .2s ease;
	  opacity:0; animation:shatJoinFadeUp .8s cubic-bezier(.22,.9,.32,1) .3s forwards;
	}
	.shat-join-cta:hover{ transform:translateY(-2px) scale(1.02); box-shadow:0 12px 38px rgba(217,169,172,.4); }
	.shat-join-cta:active{ transform:translateY(0) scale(.97); }
	.shat-join-signin{
	  font-size:13px; color:#8A8A8A; text-decoration:underline; text-underline-offset:3px;
	  opacity:0; animation:shatJoinFadeUp .8s cubic-bezier(.22,.9,.32,1) .45s forwards;
	}
	.shat-join-signin:hover{ color:#F2F2F2; }
	@keyframes shatJoinFadeUp{ from{opacity:0; transform:translateY(10px);} to{opacity:1; transform:translateY(0);} }

	@media (prefers-reduced-motion: reduce){
	  .shat-join-col-a, .shat-join-col-b{ animation:none; }
	}
	@media (max-width:640px){
	  .shat-join-cycle{ min-height:150px; }
	  .shat-join-cta{ padding:14px 36px; font-size:15px; width:100%; max-width:320px; }
	}
	</style>

	<script>
	(function(){
	  var lines = document.querySelectorAll('#shat-join-cycle .shat-join-line');
	  if (!lines.length) return;
	  var idx = 0;
	  setInterval(function(){
	    lines[idx].classList.remove('active');
	    idx = (idx + 1) % lines.length;
	    lines[idx].classList.add('active');
	  }, 3200);
	})();
	</script>
	<?php
	return ob_get_clean();
}


// ============================================================
// SHORTCODE: [shatania_feed]
// ============================================================
/* ============================================================================
   APK STORAGE + APP DOWNLOAD SCREEN
   ============================================================================
   The APK is uploaded via the admin (Shatania -> App Download) and stored in
   a protected folder under wp-content/uploads/shatania-app/ that is NOT
   directory-listable and whose only public access is through a download
   endpoint. We keep the URL of the current APK in an option.
   ============================================================================ */

// Directory (server path) + URL base for the stored APK.
function shat_apk_dir() {
	$up = wp_upload_dir();
	return trailingslashit( $up['basedir'] ) . 'shatania-app';
}
function shat_apk_stored_filename() {
	return get_option( 'shat_apk_filename', '' );
}
/**
 * The Google Drive share link for the latest APK, pasted by the admin on
 * Shatania -> App Download. '' when nothing has been set.
 *
 * Hosting the build on Drive keeps a 60-100MB APK off the web host
 * entirely, so there's no upload_max_filesize wall to fight and updating
 * the app is just replacing the file in Drive.
 */
function shat_apk_drive_url() {
	$u = trim( (string) get_option( 'shat_apk_drive_url', '' ) );
	return $u !== '' ? $u : '';
}

/** True when the download link points off-site, so links should open in a new tab. */
function shat_apk_link_is_external() {
	return shat_apk_drive_url() !== '';
}

/**
 * Public download URL for the current APK, or '' if none is available.
 * A pasted Google Drive link always wins over a self-hosted upload.
 */
function shat_apk_download_url() {
	$drive = shat_apk_drive_url();
	if ( $drive ) return $drive;

	$fn = shat_apk_stored_filename();
	if ( ! $fn ) return '';
	// Served through a query endpoint so we can set proper download headers
	// and so the raw folder can stay locked down.
	return add_query_arg( [ 'shat_get_apk' => '1' ], home_url( '/' ) );
}

// Download endpoint: streams the APK with correct headers.
add_action( 'init', 'shat_apk_download_endpoint' );
function shat_apk_download_endpoint() {
	if ( empty( $_GET['shat_get_apk'] ) ) return;

	$fn = shat_apk_stored_filename();
	$path = shat_apk_dir() . '/' . $fn;
	if ( ! $fn || ! file_exists( $path ) ) {
		status_header( 404 );
		wp_die( 'App file not found. Please check back soon.' );
	}

	nocache_headers();
	header( 'Content-Type: application/vnd.android.package-archive' );
	header( 'Content-Disposition: attachment; filename="Shatania.apk"' );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $path );
	exit;
}

/**
 * The "download the app" screen shown to non-admins who hit the feed from a
 * regular browser (desktop or mobile). No feed content is rendered here.
 */
function shat_app_download_screen() {
	$apk = shat_apk_download_url();
	$ext = shat_apk_link_is_external() ? ' target="_blank" rel="noopener"' : '';
	$pic = get_option( 'shat_profile_pic', SHAT_PROFILE_PIC );

	ob_start();
	echo shat_base_css();
	?>
	<style>
	  .sdl-wrap{
	    min-height:100vh;
	    background:
	      radial-gradient(120% 80% at 50% -10%, rgba(217,169,172,.18) 0%, rgba(217,169,172,0) 55%),
	      var(--bg,#141414);
	    display:flex;align-items:flex-start;justify-content:center;
	    padding:56px 22px 48px;
	  }
	  .sdl-card{
	    width:100%;max-width:440px;text-align:center;
	    color:var(--text,#F2F2F2);
	    font-family:var(--font,"Zilla Slab",serif);
	  }
	  .sdl-ava-ring{
	    width:120px;height:120px;margin:0 auto 26px;border-radius:50%;
	    padding:4px;
	    background:conic-gradient(from 210deg, var(--gold,#D9A9AC), var(--gold-light,#E8C4C6), var(--gold-dark,#C08A8E), var(--gold,#D9A9AC));
	    box-shadow:0 0 0 1px rgba(255,255,255,.06), 0 12px 40px rgba(217,169,172,.28);
	  }
	  .sdl-ava-ring img{
	    width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;
	    border:3px solid var(--bg,#141414);
	  }
	  .sdl-kicker{
	    font-family:var(--font);letter-spacing:.22em;text-transform:uppercase;
	    font-size:11px;font-weight:600;color:var(--gold,#D9A9AC);
	    margin-bottom:12px;
	  }
	  .sdl-title{
	    font-family:var(--display,"Cormorant Garamond",serif);
	    font-weight:600;font-size:40px;line-height:1.08;
	    margin:0 0 14px;letter-spacing:.5px;
	    color:#FFFFFF;
	  }
	  .sdl-title em{ font-style:italic;font-weight:500;color:var(--gold,#D9A9AC); }
	  .sdl-lede{
	    font-size:16px;line-height:1.6;color:#C9C9C9;
	    max-width:360px;margin:0 auto 30px;
	  }
	  .sdl-feats{
	    text-align:left;
	    background:var(--card,#1C1C1C);
	    border:1px solid var(--line,rgba(255,255,255,.08));
	    border-radius:var(--r-card,20px);
	    padding:8px 6px;margin:0 0 28px;
	    box-shadow:var(--shadow-card,0 8px 40px rgba(0,0,0,.35));
	  }
	  .sdl-feat{
	    display:flex;align-items:center;gap:14px;
	    padding:15px 18px;
	  }
	  .sdl-feat + .sdl-feat{ border-top:1px solid var(--line,rgba(255,255,255,.06)); }
	  .sdl-ico{
	    flex:0 0 auto;width:40px;height:40px;border-radius:12px;
	    display:flex;align-items:center;justify-content:center;
	    background:rgba(217,169,172,.12);
	    color:var(--gold,#D9A9AC);
	  }
	  .sdl-ico svg{ width:20px;height:20px;display:block; }
	  .sdl-feat span{ font-size:15px;color:var(--text,#F2F2F2);line-height:1.35; }
	  .sdl-btn{
	    display:flex;align-items:center;justify-content:center;gap:10px;
	    width:100%;box-sizing:border-box;
	    padding:17px 24px;border-radius:var(--r-btn,12px);
	    background:linear-gradient(180deg, var(--gold-light,#E8C4C6), var(--gold,#D9A9AC));
	    color:#141414;text-decoration:none;font-weight:700;font-size:16px;
	    font-family:var(--font);letter-spacing:.01em;
	    box-shadow:0 10px 30px rgba(217,169,172,.32);
	    transition:transform .15s ease, box-shadow .15s ease, filter .15s ease;
	  }
	  .sdl-btn:hover{ transform:translateY(-2px); box-shadow:0 14px 38px rgba(217,169,172,.42); filter:brightness(1.03); }
	  .sdl-btn svg{ width:19px;height:19px; }
	  .sdl-soon{
	    padding:17px 24px;border-radius:var(--r-btn,12px);
	    background:var(--card,#1C1C1C);border:1px solid var(--line,rgba(255,255,255,.08));
	    color:var(--sec,#9A9A9A);font-weight:600;font-size:15px;
	  }
	  .sdl-meta{ font-size:13.5px;color:var(--sec,#9A9A9A);line-height:1.6;margin:20px 0 0; }
	  .sdl-meta strong{ color:var(--text,#F2F2F2);font-weight:600; }
	  .sdl-steps{
	    display:inline-flex;align-items:center;gap:9px;flex-wrap:wrap;justify-content:center;
	    margin:22px 0 0;font-size:12.5px;color:var(--sec,#9A9A9A);
	    font-family:var(--font);
	  }
	  .sdl-steps b{ color:var(--gold,#D9A9AC);font-weight:600; }
	  .sdl-dot{ opacity:.4; }
	</style>

	<div class="shat-root">
	  <div class="sdl-wrap">
	    <div class="sdl-card">

	      <div class="sdl-ava-ring">
	        <img src="<?php echo esc_url( $pic ); ?>" alt="Shatania Nails">
	      </div>

	      <div class="sdl-kicker">Shatania Nails</div>
	      <h1 class="sdl-title">Get the <em>Shatania</em> app</h1>
	      <p class="sdl-lede">
	        The full feed lives in the app. Download it to unlock every post,
	        real-time chat, and instant alerts, and stay close to Shatania
	        Nails, always.
	      </p>

	      <div class="sdl-feats">
	        <div class="sdl-feat">
	          <span class="sdl-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span>
	          <span>Real-time chat with instant notifications</span>
	        </div>
	        <div class="sdl-feat">
	          <span class="sdl-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg></span>
	          <span>Every post &amp; photo, the moment it drops</span>
	        </div>
	        <div class="sdl-feat">
	          <span class="sdl-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg></span>
	          <span>New-post alerts so you never miss a thing</span>
	        </div>
	      </div>

	      <?php if ( $apk ) : ?>
	        <a class="sdl-btn" href="<?php echo esc_url( $apk ); ?>"<?php echo $ext; ?>>
	          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M5 21h14"/></svg>
	          Download the Android app
	        </a>
	      <?php else : ?>
	        <div class="sdl-soon">Download coming here very soon</div>
	      <?php endif; ?>

	      <p class="sdl-meta">
	        Currently available for <strong>Android phones</strong> only.<br>
	        Install, then sign in with your Shatania credentials to enjoy all content.
	      </p>

	      <div class="sdl-steps">
	        <span>Open the app</span><span class="sdl-dot">→</span>
	        <b>log in</b><span class="sdl-dot">→</span>
	        <span>the feed opens automatically</span>
	      </div>

	    </div>
	  </div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * The "get the app" banner that sits at the top of the feed for people
 * browsing on the web. It's the counterpart to shat_app_download_screen():
 * that one is a wall, this one is an invitation — the feed is right there
 * underneath it.
 *
 * Returns '' when there's nothing to offer (no link set) or nothing to
 * gain (the reader is already inside the app).
 */
function shat_app_banner() {
	$apk = shat_apk_download_url();
	if ( ! $apk ) return '';
	if ( shat_request_is_from_app() ) return ''; // already has it

	$ext = shat_apk_link_is_external() ? ' target="_blank" rel="noopener"' : '';

	ob_start();
	?>
	<style>
	  .shat-appbar{
	    display:flex;align-items:center;gap:13px;
	    margin:0 0 16px;padding:13px 15px;
	    border-radius:var(--r-card,16px);
	    background:var(--card,#1C1C1C);
	    border:1px solid var(--line,rgba(255,255,255,.08));
	    box-shadow:var(--shadow-card,0 8px 30px rgba(0,0,0,.3));
	    font-family:var(--font,"Zilla Slab",serif);
	  }
	  .shat-appbar[hidden]{ display:none; }
	  .shat-appbar-ico{
	    flex:0 0 auto;width:38px;height:38px;border-radius:11px;
	    display:flex;align-items:center;justify-content:center;
	    background:rgba(217,169,172,.13);color:var(--gold,#D9A9AC);
	  }
	  .shat-appbar-ico svg{ width:19px;height:19px;display:block; }
	  .shat-appbar-txt{ flex:1 1 auto;min-width:0;line-height:1.35; }
	  .shat-appbar-txt b{
	    display:block;font-size:14.5px;font-weight:600;
	    color:var(--text,#F2F2F2);
	  }
	  .shat-appbar-txt span{
	    display:block;font-size:12.5px;color:var(--sec,#9A9A9A);margin-top:2px;
	  }
	  .shat-appbar-btn{
	    flex:0 0 auto;text-decoration:none;white-space:nowrap;
	    padding:9px 16px;border-radius:var(--r-btn,10px);
	    background:linear-gradient(180deg, var(--gold-light,#E8C4C6), var(--gold,#D9A9AC));
	    color:#141414;font-weight:700;font-size:13.5px;
	  }
	  .shat-appbar-x{
	    flex:0 0 auto;background:none;border:0;cursor:pointer;padding:4px;
	    color:var(--sec,#9A9A9A);font-size:18px;line-height:1;
	  }
	  @media (max-width:420px){
	    .shat-appbar{ flex-wrap:wrap; }
	    .shat-appbar-btn{ width:100%;text-align:center;order:3; }
	  }
	</style>
	<div class="shat-appbar" id="shat-appbar" hidden>
	  <span class="shat-appbar-ico" aria-hidden="true">
	    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/></svg>
	  </span>
	  <span class="shat-appbar-txt">
	    <b>Get the Android app</b>
	    <span>Push alerts the moment a new post or message lands.</span>
	  </span>
	  <a class="shat-appbar-btn" href="<?php echo esc_url( $apk ); ?>"<?php echo $ext; ?>>Download APK</a>
	  <button type="button" class="shat-appbar-x" id="shat-appbar-x" aria-label="Dismiss">&times;</button>
	</div>
	<script>
	(function(){
	  /* Render server-side but reveal client-side, so a reader who already
	     dismissed it never sees a flash of the bar on every page load. */
	  var bar = document.getElementById('shat-appbar');
	  if (!bar) return;
	  var KEY = 'shat_appbar_dismissed';
	  var hidden = false;
	  try { hidden = localStorage.getItem(KEY) === '1'; } catch (e) {}
	  if (!hidden) bar.hidden = false;
	  document.getElementById('shat-appbar-x').addEventListener('click', function(){
	    bar.hidden = true;
	    try { localStorage.setItem(KEY, '1'); } catch (e) {}
	  });
	})();
	</script>
	<?php
	return ob_get_clean();
}

add_shortcode( 'shatania_feed', 'shat_sc_feed' );
function shat_sc_feed() {
	if ( ! is_user_logged_in() ) { wp_safe_redirect( shat_page_url( 'shatania-login' ) ); exit; }
	// Lets the streak footer hook know the timer belongs on this page.
	$GLOBALS['shat_feed_rendered'] = true;
	if ( ! shat_is_approved()  ) return shat_sc_pending();

	// App-only gate: non-admins must be viewing inside the Android app.
	// Admins bypass and can view the feed from any browser.
	if ( ! shat_feed_allowed_here() ) {
		return shat_app_download_screen();
	}

	$uid            = get_current_user_id();
	$feed_locked    = ! shat_has_feed_access( $uid );
	$feed_state     = shat_feed_access_state( $uid );
	$feed_days_left = 0;
	if ( $feed_state === 'active' ) {
		$feed_expiry_raw = get_user_meta( $uid, 'shat_feed_expiry', true );
		$feed_days_left  = max( 0, ceil( ( strtotime( $feed_expiry_raw ) - current_time('timestamp') ) / DAY_IN_SECONDS ) );
	}
	$photos      = shat_get_photos( 1, 10 );
	$total       = shat_count_photos();
	global $wpdb;
	$total_lk    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}shat_likes" );
	$total_vw    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}shat_views" );
	$profile_pic = get_option( 'shat_profile_pic', SHAT_PROFILE_PIC );

	ob_start();
	echo shat_base_css();
	?>
	<div class="shat-root">
	<div class="shat-feed-root">

	  <?php echo shat_app_banner(); ?>

	  <?php
	  $shat_me     = wp_get_current_user();
	  $shat_first  = $shat_me->first_name ? $shat_me->first_name : $shat_me->display_name;
	  $shat_first  = trim( preg_split( '/\s+/', trim( $shat_first ) )[0] );
	  $shat_streak = shat_streak_get( $uid );
	  ?>

	  <!-- IG-STYLE PROFILE HEADER (compact) -->
	  <section class="shat-ig-header" aria-label="Profile">
	    <!-- Decorative floating hearts -->
	    <span class="shat-heart-deco h1" aria-hidden="true">
	      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7-4.5-9.5-9A5.5 5.5 0 0112 6a5.5 5.5 0 019.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg>
	    </span>
	    <span class="shat-heart-deco h2" aria-hidden="true">
	      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7-4.5-9.5-9A5.5 5.5 0 0112 6a5.5 5.5 0 019.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg>
	    </span>
	    <span class="shat-heart-deco h3" aria-hidden="true">
	      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7-4.5-9.5-9A5.5 5.5 0 0112 6a5.5 5.5 0 019.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg>
	    </span>

	    <button type="button" class="shat-me-btn<?php echo $shat_streak['count'] > 0 ? ' has-streak' : ''; ?>"
	            id="shat-me-btn" onclick="shatOpenMeSheet()" title="Your profile" aria-label="Your profile">
	      <span class="shat-me-btn-initial" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $shat_first, 0, 1 ) ) ); ?></span>
	      <?php if ( $shat_streak['count'] > 0 ) : ?>
	        <span class="shat-me-dot" aria-hidden="true"></span>
	      <?php endif; ?>
	    </button>
	    <div class="shat-ig-top">
	      <div class="shat-ig-avatar-ring">
	        <div class="shat-ig-avatar-inner">
	          <img src="<?php echo esc_url( $profile_pic ); ?>" alt="Shatania Nails" loading="eager"
	            onerror="this.style.display='none';this.parentNode.style.background='linear-gradient(135deg,#D9A9AC,#C08A8E)';">
	        </div>
	        <span class="shat-ig-avatar-badge" aria-hidden="true"></span>
	      </div>
	      <div class="shat-ig-info">
	        <h1 class="shat-ig-name">
	          Shatania Nails
	          <span class="shat-verified" aria-label="Verified" title="Verified">
	            <svg viewBox="0 0 24 24" fill="currentColor">
	              <path d="M12,2 L15.29,4.05 L19.07,4.93 L19.95,8.71 L22,12 L19.95,15.29 L19.07,19.07 L15.29,19.95 L12,22 L8.71,19.95 L4.93,19.07 L4.05,15.29 L2,12 L4.05,8.71 L4.93,4.93 L8.71,4.05 Z"/>
	              <path d="M8.5,12.2 L10.7,14.4 L15,10" stroke="#141414" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
	            </svg>
	          </span>
	        </h1>
	        <p class="shat-ig-handle">@shatania_veyra</p>
	      </div>
	    </div>


	  <?php echo schat_sc_button(); ?>


	  </section>


	  <?php
	  shat_ensure_access_requests_table();
	  $shat_my_ticket = null;
	  foreach ( shat_get_my_requests( $uid, 5 ) as $shat_req_row ) {
	    if ( in_array( $shat_req_row->status, [ 'open', 'in_progress' ], true ) ) { $shat_my_ticket = $shat_req_row; break; }
	  }
	  if ( $shat_my_ticket ):
	  ?>
	  <div class="shat-reply-wrap">
	    <div class="shat-reply-card">
	      <div class="shat-reply-icon" aria-hidden="true">
	        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v12H7l-3 4V4z"/></svg>
	      </div>
	      <div class="shat-reply-headtext">
	        <span class="shat-reply-eyebrow"><?php echo esc_html( shat_request_type_label( $shat_my_ticket->type ) ); ?></span>
	        <p class="shat-reply-note">Request received. Send a message to <?php echo shat_contact_line(); ?> at the top of this page for the quickest response.</p>
	      </div>
	      <span class="shat-reply-status shat-status-<?php echo esc_attr( $shat_my_ticket->status ); ?>"><?php echo esc_html( shat_status_label( $shat_my_ticket->status ) ); ?></span>
	    </div>
	  </div>
	  <?php endif; ?>

  <!-- ======= PATREON INTRO NOTE (top of feed) ======= -->
  <div class="shat-patreon-note">
    <div class="shat-pnote-glow" aria-hidden="true"></div>

    <div class="shat-pnote-head">
      <span class="shat-pnote-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="currentColor">
          <circle cx="15.3" cy="9" r="6"/><rect x="2.8" y="2.6" width="3.4" height="18.8" rx="1"/>
        </svg>
      </span>
      <span class="shat-pnote-eyebrow">For my patrons</span>
    </div>

    <p class="shat-pnote-body">
      My normal edits live here. The most exclusive and curated ones go to my
      patrons first. If you like this page, you will love it over there.
    </p>

    <a class="shat-pnote-cta"
       href="<?php echo esc_url( SHAT_PATREON_GO_URL ); ?>"
       target="_blank" rel="noopener nofollow">
      <span>Become a patron</span>
      <svg class="shat-pnote-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M5 12h13M13 6l6 6-6 6"/>
      </svg>
    </a>
    <p class="shat-pnote-micro">New set every week. Cancel any time.</p>
  </div>

  <style>
  .shat-patreon-note{
    position:relative;overflow:hidden;box-sizing:border-box;
    max-width:600px;margin:32px auto 20px;padding:18px 20px 16px;
    border-radius:16px;font-family:var(--font);
    background:rgba(255,255,255,.045);
    border:1px solid rgba(255,255,255,.09);
    backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
  }
  .shat-pnote-glow{
    position:absolute;top:-80px;right:-60px;width:200px;height:200px;
    border-radius:50%;pointer-events:none;
    background:radial-gradient(circle,rgba(255,66,77,.15) 0%,rgba(255,66,77,0) 70%);
  }
  .shat-pnote-head{
    position:relative;z-index:1;
    display:flex;align-items:center;gap:8px;margin-bottom:10px;
  }
  .shat-pnote-mark{
    display:flex;align-items:center;justify-content:center;
    width:21px;height:21px;flex:0 0 21px;border-radius:6px;
    background:rgba(255,66,77,.14);color:#ff5a6e;
  }
  .shat-pnote-mark svg{width:12px;height:12px;}
  .shat-pnote-eyebrow{
    font-size:10.5px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;
    color:rgba(255,255,255,.42);
  }
  .shat-pnote-body{
    position:relative;z-index:1;
    margin:0 0 14px;font-size:14px;line-height:1.55;
    color:rgba(255,255,255,.72);letter-spacing:-.005em;max-width:52ch;
  }
  .shat-pnote-cta{
    position:relative;z-index:1;
    display:inline-flex;align-items:center;gap:7px;
    padding:9px 17px;border-radius:9px;
    background:#ff424d;color:#fff;
    font-size:13.5px;font-weight:600;letter-spacing:-.01em;text-decoration:none;
    transition:background .2s ease,transform .2s cubic-bezier(.34,1.56,.64,1);
    -webkit-tap-highlight-color:transparent;
  }
  .shat-pnote-cta:hover,.shat-pnote-cta:focus{
    background:#ff5a64;color:#fff;transform:translateY(-1px);
  }
  .shat-pnote-cta:active{transform:translateY(0);}
  .shat-pnote-cta svg{width:14px;height:14px;flex:0 0 14px;}
  .shat-pnote-arrow{opacity:.8;transition:transform .2s ease;}
  .shat-pnote-cta:hover .shat-pnote-arrow{transform:translateX(3px);}
  .shat-pnote-micro{
    position:relative;z-index:1;
    margin:9px 0 0;font-size:11.5px;line-height:1.4;
    color:rgba(255,255,255,.34);letter-spacing:.005em;
  }
  @media(max-width:640px){
    .shat-patreon-note{margin:24px 12px 18px;padding:16px 16px 14px;}
    .shat-pnote-cta{width:100%;justify-content:center;}
    .shat-pnote-micro{text-align:center;}
  }
  </style>





	  <div class="shat-feed-tabs" role="tablist">
	    <button type="button" class="shat-feed-tab active" id="shat-tab-posts" role="tab" aria-selected="true" onclick="shatSwitchTab('posts')">
	      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
	      Posts
	    </button>
	    <button type="button" class="shat-feed-tab" id="shat-tab-saved" role="tab" aria-selected="false" onclick="shatSwitchTab('saved')">
	      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
	      Saved
	    </button>
	    <span class="shat-feed-tab-indicator" id="shat-tab-indicator"></span>
	  </div>

	  <?php if ( $feed_locked ): ?>
	  <div class="shat-feed-expired-banner">
	    <div class="shat-lock-ring" aria-hidden="true">
	      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
	        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
	        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
	      </svg>
	    </div>
	    <h2>Your Access Has Ended</h2>
	    <p>Your time-limited access to the feed has expired. Renew to keep seeing new and existing posts.</p>
	    <?php $shat_active_renew = shat_get_active_request_of_type( $uid, 'renew' ); ?>
	    <?php if ( $shat_active_renew ): ?>
	    <button type="button" class="shat-renew-btn" disabled style="border:none;font-family:inherit;opacity:.6;cursor:default;">Request In Progress</button>
	    <?php else: ?>
	    <button type="button" onclick="shatOpenRequestSheet('renew',0)" class="shat-renew-btn shat-req-trigger" style="border:none;font-family:inherit;">Request Renewal</button>
	    <?php endif; ?>
	    <?php /* 2.3.0 — expired members keep 'approved' status, so the chat
	       still works for them. Give them a one-tap way into it rather than
	       only the request form. */ ?>
	    <button type="button" class="shat-msg-inline" onclick="shatOpenChatPanel()">
	      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
	      Message me directly
	    </button>
	  </div>
	  <?php endif; ?>

	  <!-- PANEL: POSTS (default view) -->
	  <div class="shat-tab-panel" id="shat-panel-posts">
	    <div class="shat-photo-grid" id="shat-grid">
	      <?php if ( empty( $photos ) ): ?>
	        <div class="shat-empty-feed">
	          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
	          Nothing here yet — check back soon.
	        </div>
	      <?php else: ?>
	        <?php foreach ( $photos as $ph ) echo shat_render_card( $ph, $uid, $feed_locked ); ?>
	      <?php endif; ?>
	    </div>

	    <div class="shat-loadmore-wrap" id="shat-loadmore-wrap">
	      <?php if ( $total > 10 ): ?>
	        <button class="shat-loadmore-btn" id="shat-loadmore-btn" onclick="shatLoadMore(this)">Load More</button>
	      <?php elseif ( ! empty( $photos ) ): ?>
	        <div class="shat-end-msg">&mdash;&nbsp;&nbsp;You&rsquo;ve seen everything&nbsp;&nbsp;&mdash;</div>
	      <?php endif; ?>
	    </div>
	  </div>

	  <!-- PANEL: SAVED — identical card layout to the feed, filtered to
	       the posts this member bookmarked. Page size matches the feed
	       (10) since these are full cards now, not thumbnails. -->
	  <div class="shat-tab-panel" id="shat-panel-saved" style="display:none;">
	    <?php
	    $saved_photos = shat_get_saved_photos( $uid, 1, 10 );
	    $saved_total  = shat_count_saved( $uid );
	    ?>
	    <div class="shat-photo-grid" id="shat-saved-grid">
	      <?php foreach ( $saved_photos as $sp ) echo shat_render_card( $sp, $uid, $feed_locked ); ?>
	    </div>
	    <div class="shat-empty-feed" id="shat-saved-empty" style="<?php echo empty( $saved_photos ) ? '' : 'display:none;'; ?>">
	      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
	      Nothing saved yet — tap the bookmark on any photo to save it here.
	    </div>
	    <div class="shat-loadmore-wrap" id="shat-saved-loadmore-wrap">
	      <?php if ( $saved_total > 10 ): ?>
	        <button class="shat-loadmore-btn" id="shat-saved-loadmore-btn" onclick="shatLoadMoreSaved(this)">Load More</button>
	      <?php endif; ?>
	    </div>
	  </div>

	</div>
	</div>

	<script>
	/* ============================================================
	   FEED JAVASCRIPT
	   ============================================================ */
	var _shatPage    = 1;
	var _shatLoading = false;
	var _shatPer     = 10;

	/* ---- Load more ---- */
	function shatLoadMore(btn) {
	  if (_shatLoading) return;
	  _shatLoading = true; btn.disabled = true; btn.textContent = 'Loading\u2026';
	  _shatPage++;
	  jQuery.post(shatAjax.ajaxurl, { action:'shat_load_more', nonce:shatAjax.nonce, page:_shatPage, per_page:_shatPer }, function(r){
	    if (r.success) {
	      jQuery('#shat-grid').append(r.data.html);
	      shatStaggerCards();
	      shatRegisterNewCards();
	      if (!r.data.has_more) {
	        jQuery('#shat-loadmore-wrap').html('<div class="shat-end-msg">&mdash;&nbsp;&nbsp;You\u2019ve seen everything&nbsp;&nbsp;&mdash;</div>');
	      } else { btn.textContent = 'Load More'; btn.disabled = false; }
	    }
	    _shatLoading = false;
	  }).fail(function(){ btn.textContent='Load More'; btn.disabled=false; _shatLoading=false; });
	}

	/* ---- Posts / Saved tab switcher ---- */
	/* 2.3.0 — open the chat panel from anywhere in the feed (e.g. the
	   "Your Access Has Ended" banner). Rather than reach into the chat
	   module's internals, just click its existing button, so the two
	   stay in sync if that code changes. */
	function shatOpenChatPanel() {
	  var fab = document.getElementById('schat-fab-btn');
	  if (fab) { fab.click(); return; }
	  /* Chat button isn't on the page (shouldn't happen for an approved
	     member) — fall back to the renewal request form. */
	  if (typeof shatOpenRequestSheet === 'function') shatOpenRequestSheet('renew', 0);
	}

	function shatSwitchTab(tab) {
	  var postsPanel = document.getElementById('shat-panel-posts');
	  var savedPanel = document.getElementById('shat-panel-saved');
	  var postsTab   = document.getElementById('shat-tab-posts');
	  var savedTab   = document.getElementById('shat-tab-saved');
	  var indicator  = document.getElementById('shat-tab-indicator');
	  var showSaved  = (tab === 'saved');

	  postsTab.classList.toggle('active', !showSaved);
	  savedTab.classList.toggle('active', showSaved);
	  postsTab.setAttribute('aria-selected', showSaved ? 'false' : 'true');
	  savedTab.setAttribute('aria-selected', showSaved ? 'true' : 'false');
	  if (indicator) indicator.classList.toggle('right', showSaved);

	  if (showSaved) {
	    postsPanel.style.display = 'none';
	    savedPanel.style.display = '';
	    savedPanel.classList.add('shat-panel-in');
	    setTimeout(function(){ savedPanel.classList.remove('shat-panel-in'); }, 320);
	  } else {
	    savedPanel.style.display = 'none';
	    postsPanel.style.display = '';
	    postsPanel.classList.add('shat-panel-in');
	    setTimeout(function(){ postsPanel.classList.remove('shat-panel-in'); }, 320);
	  }
	}

	/* ---- Load more (Saved tab) ---- */
	var _shatSavedPage = 1;
	var _shatSavedLoading = false;
	function shatLoadMoreSaved(btn) {
	  if (_shatSavedLoading) return;
	  _shatSavedLoading = true; btn.disabled = true; btn.textContent = 'Loading\u2026';
	  _shatSavedPage++;
	  jQuery.post(shatAjax.ajaxurl, { action:'shat_load_saved', nonce:shatAjax.nonce, page:_shatSavedPage, per_page:10 }, function(r){
	    if (r.success) {
	      jQuery('#shat-saved-grid').append(r.data.html);
	      if (!r.data.has_more) {
	        jQuery('#shat-saved-loadmore-wrap').html(r.data.total > 0 ? '<div class="shat-end-msg">&mdash;&nbsp;&nbsp;You\u2019ve seen everything&nbsp;&nbsp;&mdash;</div>' : '');
	      } else { btn.textContent = 'Load More'; btn.disabled = false; }
	    }
	    _shatSavedLoading = false;
	  }).fail(function(){ btn.textContent='Load More'; btn.disabled=false; _shatSavedLoading=false; });
	}

	/* ---- Toggle Like ---- */
	function shatToggleLike(btn, pid) {
	  if (btn.dataset.loading) return;
	  btn.dataset.loading = 1;
	  jQuery.post(shatAjax.ajaxurl, { action:'shat_toggle_like', nonce:shatAjax.nonce, photo_id:pid }, function(r){
	    delete btn.dataset.loading;
	    if (r.success) {
	      var svg = btn.querySelector('svg');
	      if (r.data.liked) {
	        btn.classList.add('liked','pop');
	        svg.setAttribute('fill','#E53935'); svg.setAttribute('stroke','#E53935');
	        setTimeout(function(){ btn.classList.remove('pop'); }, 400);
	      } else {
	        btn.classList.remove('liked');
	        svg.setAttribute('fill','none'); svg.setAttribute('stroke','#8A7A5A');
	      }
	    }
	  }).fail(function(){ delete btn.dataset.loading; });
	}

/* ---- Show the empty-state message once the last saved card is removed ---- */
	function shatCheckSavedEmpty() {
	  var grid = document.getElementById('shat-saved-grid');
	  /* Saved now renders full feed cards, so look for those. */
	  if (grid && !grid.querySelector('.shat-photo-card')) {
	    var empty = document.getElementById('shat-saved-empty');
	    if (empty) empty.style.display = '';
	  }
	}

	/* ---- Toggle Save / Favorite (private per-user, powers the Saved tab) ---- */
	function shatToggleFavorite(btn, pid) {
	  if (btn.dataset.loading) return;
	  btn.dataset.loading = 1;
	  var wasSaved = btn.classList.contains('saved');
	  jQuery.post(shatAjax.ajaxurl, { action:'shat_toggle_favorite', nonce:shatAjax.nonce, photo_id:pid }, function(r){
	    delete btn.dataset.loading;
	    if (r.success) {
	      btn.classList.toggle('saved', r.data.saved);
	      btn.setAttribute('aria-pressed', r.data.saved ? 'true' : 'false');
	      btn.setAttribute('aria-label', r.data.saved ? 'Remove from Saved' : 'Add to Saved');
	      if (r.data.saved) {
	        btn.classList.add('bounce');
	        setTimeout(function(){ btn.classList.remove('bounce'); }, 460);
	      }
	      shatSyncSavedGrid(r.data.saved, r.data.photo_id, r.data.tile_html);
	    }
	  }).fail(function(){
	    delete btn.dataset.loading;
	    btn.classList.toggle('saved', wasSaved); // revert optimistic state on failure
	  });
	}

	/* ---- Keep the Saved tab's grid in sync with saves/unsaves made from
	   the Posts feed, so switching to Saved always reflects the latest
	   state without needing a page refresh. ---- */
	function shatSyncSavedGrid(saved, pid, tileHtml) {
	  var grid = document.getElementById('shat-saved-grid');
	  if (!grid) return;
	  var existing = grid.querySelector('.shat-photo-card[data-pid="' + pid + '"]');
	  if (saved) {
	    if (existing) return; // already present, nothing to do
	    if (tileHtml) {
	      grid.insertAdjacentHTML('afterbegin', tileHtml);
	      var empty = document.getElementById('shat-saved-empty');
	      if (empty) empty.style.display = 'none';
	    }
	  } else if (existing) {
	    existing.style.transition = 'opacity .3s cubic-bezier(.22,.61,.36,1), transform .3s cubic-bezier(.22,.61,.36,1)';
	    existing.style.opacity = '0';
	    existing.style.transform = 'scale(.85)';
	    setTimeout(function(){
	      existing.remove();
	      shatCheckSavedEmpty();
	    }, 300);
	  }
	}


	/* ---- Record view on newly loaded cards ---- */
	function shatRegisterNewCards() {
	  jQuery('.shat-photo-card[data-pid]:not([data-viewed])').each(function(){
	    var c = jQuery(this); c.attr('data-viewed','1');
	    jQuery.post(shatAjax.ajaxurl, { action:'shat_record_view', nonce:shatAjax.nonce, photo_id:c.data('pid') });
	  });
	}

	/* ---- Stagger the entrance of newly-inserted cards ----
	   Assigns an incrementing --stagger index (capped at 6) so cards cascade in
	   like Apple Photos/App Store rows, rather than all fading in at once. Only
	   touches cards that haven't been staggered yet, so it's safe to call after
	   both the initial page render and every Load More batch. */
	function shatStaggerCards() {
	  var MAX_STAGGER = 6;
	  var i = 0;
	  jQuery('.shat-photo-card:not([data-staggered])').each(function(){
	    this.style.setProperty('--stagger', Math.min(i, MAX_STAGGER));
	    this.setAttribute('data-staggered','1');
	    i++;
	  });
	}

	/* ---- Unlock blurred photo ---- */
	function shatUnlock(btn, pid) {
	  var overlay  = btn.closest('.shat-blur-overlay');
	  var imgWrap  = overlay.closest('.shat-img-wrap');
	  var img      = imgWrap.querySelector('img');
	  var errEl    = overlay.querySelector('.shat-unlock-err');
	  var inputEl  = overlay.querySelector('.shat-passcode-input');
	  var code     = inputEl.value.trim();

	  if (!code) {
	    errEl.textContent = 'Please enter the passcode.';
	    errEl.style.display = 'block';
	    inputEl.style.animation = 'shatShake .4s ease';
	    setTimeout(function(){ inputEl.style.animation = ''; }, 420);
	    return;
	  }

	  btn.disabled = true; btn.textContent = '\u2026';
	  errEl.style.display = 'none';

	  jQuery.post(shatAjax.ajaxurl, {
	    action: 'shat_unlock_photo', nonce: shatAjax.nonce,
	    photo_id: pid, passcode: code
	  }, function(r) {
	    if (r.success) {
	      /* Smooth unlock animation */
	      imgWrap.classList.add('shat-unlocking');
	      overlay.classList.add('shat-fade-out');
	      img.style.transition = 'filter 1.2s ease, transform 1s ease';
	      img.style.filter     = 'blur(0)';
	      img.style.transform  = 'scale(1)';
	      setTimeout(function(){
	        overlay.remove();
	        imgWrap.classList.remove('shat-img-locked','shat-unlocking');
	        img.removeAttribute('style');
	        img.classList.remove('shat-locked-img');
	        /* Add shield to now-unlocked image */
	        var shield = document.createElement('div');
	        shield.className = 'shat-img-shield'; shield.setAttribute('aria-hidden','true');
	        imgWrap.appendChild(shield);
	      }, 1000);
	    } else {
	      errEl.textContent = r.data || 'Incorrect passcode. Please try again.';
	      errEl.style.display = 'block';
	      inputEl.style.animation = 'shatShake .4s ease';
	      setTimeout(function(){ inputEl.style.animation=''; }, 420);
	      btn.disabled = false; btn.textContent = 'Unlock';
	    }
	  }).fail(function(){ btn.disabled=false; btn.textContent='Unlock'; });
	}

	/* ---- Enter key fires Unlock button ---- */
	document.addEventListener('keydown', function(e){
	  if (e.key === 'Enter' && e.target.classList.contains('shat-passcode-input')) {
	    e.preventDefault();
	    var btn = e.target.closest('.shat-passcode-row') && e.target.closest('.shat-passcode-row').querySelector('.shat-passcode-btn');
	    if (btn) btn.click();
	  }
	});

	/* ===========================================================
	   NO-DOWNLOAD PROTECTION
	   — right-click, drag, and common save keyboard shortcuts
	     are all disabled on the feed.
	   =========================================================== */
	document.addEventListener('contextmenu', function(e){
	  if (e.target.closest && e.target.closest('.shat-feed-root')) {
	    e.preventDefault(); return false;
	  }
	}, true);

	document.addEventListener('dragstart', function(e){
	  if (e.target.closest && e.target.closest('.shat-feed-root')) {
	    e.preventDefault(); return false;
	  }
	}, true);

	document.addEventListener('keydown', function(e){
	  if (!document.querySelector('.shat-feed-root')) return;
	  /* Block Ctrl/Cmd + S (Save), U (View Source), Shift+I (DevTools) */
	  if ((e.ctrlKey || e.metaKey) && ['s','S','u','U'].includes(e.key)) {
	    e.preventDefault();
	  }
	});

	jQuery(function(){ shatStaggerCards(); shatRegisterNewCards(); });
	</script>
	<?php
	return ob_get_clean();
}

// ============================================================
// RENDER PHOTO CARD
// ============================================================
function shat_render_card( $photo, $uid = 0, $force_lock = false ) {
	$pid          = $photo->ID;
	$caption      = get_post_meta( $pid, '_shat_caption',     true );
	$desc         = get_post_meta( $pid, '_shat_description', true );
	$dated        = get_post_meta( $pid, '_shat_date',        true ) ?: get_the_date( 'F j, Y', $pid );
	$images       = shat_get_gallery_images( $pid, 'large' );
	$img          = $images[0] ?? '';
	$count        = count( $images );
	$likes        = shat_photo_likes( $pid );
	$views        = shat_photo_views( $pid );
	$liked        = $uid ? shat_user_liked( $pid, $uid ) : false;
	$saved        = $uid ? shat_user_saved( $pid, $uid ) : false;
	$fill         = $liked ? '#E53935' : 'none';
	$stroke       = $liked ? '#E53935' : '#5A5A5A';
	$lcls         = $liked ? ' liked'  : '';
	$avatar_url   = get_option( 'shat_author_avatar', get_option( 'shat_profile_pic', SHAT_PROFILE_PIC ) );

	/* ---- "NEW" badge — based on actual upload time, not the custom display date,
	   since _shat_date can be backdated/reordered by the admin. Lasts 3 days. ---- */
	$posted_ts = get_post_time( 'U', true, $pid ); // GMT unix timestamp of actual creation
	$is_new    = ( $posted_ts && ( time() - $posted_ts ) < ( 3 * DAY_IN_SECONDS ) );

	/* ---- Blur / unlock logic ---- */
	/* $force_lock (whole-feed access expired) always wins over per-photo unlock state —
	   there's no passcode to bypass a feed-expiry lock, only renewing access. */
	/* 2.3.0 — a Patreon exclusive locks like a blurred photo but has no
	   passcode escape: $has_unlocked is skipped entirely when it applies,
	   so an old or leaked passcode can never open one. Admins see through. */
	$is_admin_v   = $uid && user_can( $uid, 'manage_options' );
	$patreon_lock = shat_is_patreon( $pid ) && ! $is_admin_v;

	$is_blurred   = $force_lock || $patreon_lock || shat_is_blurred( $pid );
	$has_unlocked = ( ! $force_lock && ! $patreon_lock && $uid ) ? shat_user_unlocked( $pid, $uid ) : false;
	$is_locked    = $is_blurred && ! $has_unlocked;


	ob_start(); ?>
	<div class="shat-photo-card<?php echo $is_new ? ' shat-card-new' : ''; ?>" data-pid="<?php echo esc_attr( $pid ); ?>">

	  <!-- Card header -->
	  <div class="shat-card-head">
	    <div class="shat-card-avatar">
	      <img src="<?php echo esc_url( $avatar_url ?: SHAT_PROFILE_PIC ); ?>" alt="Shatania" onerror="this.style.display='none'">
	    </div>
	    <div>
	      <div class="shat-card-name">
	        Shatania
	        <?php if ( $is_new ): ?>
	        <span class="shat-new-tag" aria-label="New post">
	          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l1.9 5.8L20 8l-4.8 3.9L16.5 18 12 14.8 7.5 18l1.3-6.1L4 8l6.1-.2z"/></svg>
	          New
	        </span>
	        <?php endif; ?>
	      </div>
	      <div class="shat-card-date"><?php echo esc_html( $dated ); ?></div>
	    </div>
	  </div>

	  <?php if ( $img ):
	    $is_gallery = ( $count > 1 && ! $is_locked );
	    $wrap_cls   = 'shat-img-wrap';
	    if ( $is_locked )  $wrap_cls .= ' shat-img-locked';
	    if ( $is_gallery ) $wrap_cls .= ' shat-has-gallery';
	  ?>
	  <div class="<?php echo esc_attr( $wrap_cls ); ?>"
	       data-gallery='<?php echo esc_attr( wp_json_encode( $is_locked ? [] : $images ) ); ?>'>

	    <?php if ( $is_gallery ):
	      $tiles = array_slice( $images, 0, min( 4, $count ) );
	      $cls   = 'shat-collage shat-collage-' . count( $tiles ); ?>
	      <div class="<?php echo esc_attr( $cls ); ?>">
	        <?php foreach ( $tiles as $i => $u ): ?>
	          <div class="shat-tile" onclick="shatLightbox(this.closest('.shat-img-wrap'),<?php echo $i; ?>,this)">
	            <img src="<?php echo esc_url( $u ); ?>" alt="" loading="lazy">
	            <?php if ( $i === 3 && $count > 4 ): ?>
	              <div class="shat-more">+<?php echo ( $count - 4 ); ?></div>
	            <?php endif; ?>
	          </div>
	        <?php endforeach; ?>
	      </div>
	      <div class="shat-photo-badge"><?php echo intval( $count ); ?> Photos</div>
	    <?php else: ?>
	      <img
	        src="<?php echo esc_url( $img ); ?>"
	        alt="<?php echo esc_attr( $caption ?: 'Photo' ); ?>"
	        loading="lazy"
	        <?php echo $is_locked ? 'class="shat-locked-img"' : ''; ?>
	      >
	    <?php endif; ?>

	    <?php if ( $is_locked ): ?>

	    <?php if ( $patreon_lock ): ?>
	    <!-- ======= PATREON EXCLUSIVE OVERLAY ======= -->
	    <div class="shat-patreon-overlay">
	      <span class="shat-patreon-badge">
	        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
	          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
	          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
	        </svg>
	        Exclusive
	      </span>
	      <p class="shat-patreon-title">This one&rsquo;s for my Patreon</p>
	      <p class="shat-patreon-sub">Join to unlock this post and everything else in the exclusive collection.</p>
	      <a class="shat-patreon-btn"
	         href="<?php echo esc_url( SHAT_PATREON_GO_URL ); ?>"
	         target="_blank" rel="noopener nofollow">
	        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
	          <circle cx="15" cy="9" r="7"/><rect x="2" y="2" width="4" height="20" rx="1"/>
	        </svg>
	        Visit Patreon
	      </a>
	    </div>

	    <?php else: ?>
	    <!-- ======= BLUR / LOCK OVERLAY ======= -->
	    <div class="shat-blur-overlay">

	      <!-- Padlock icon -->
	      <div class="shat-lock-ring" aria-hidden="true">
	        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
	          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
	          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
	        </svg>
	      </div>

	      <p class="shat-lock-label"><?php echo $force_lock ? 'Access Expired' : 'Locked'; ?></p>

	      <?php if ( $force_lock ): ?>
	      <!-- Feed access expired — no passcode possible, only renewal -->
	      <?php $shat_active_renew_card = $uid ? shat_get_active_request_of_type( $uid, 'renew' ) : null; ?>
	      <?php if ( $shat_active_renew_card ): ?>
	      <button type="button" class="shat-passcode-btn" disabled style="font-family:inherit;opacity:.6;cursor:default;">Request In Progress</button>
	      <?php else: ?>
	      <button type="button" onclick="shatOpenRequestSheet('renew',0)" class="shat-passcode-btn shat-req-trigger" style="font-family:inherit;">Request Renewal</button>
	      <?php endif; ?>
	      <?php else: ?>
	      <!-- Passcode input row -->
	      <div class="shat-passcode-row">
	        <input
	          type="password"
	          class="shat-passcode-input"
	          placeholder="Enter passcode&hellip;"
	          autocomplete="off"
	          autocorrect="off"
	          autocapitalize="off"
	          spellcheck="false"
	          maxlength="80"
	        >
	        <button
	          class="shat-passcode-btn"
	          onclick="shatUnlock(this,<?php echo intval( $pid ); ?>)"
	        >Unlock</button>
	      </div>

	      <!-- Error message (shown by JS) -->
	      <p class="shat-unlock-err" aria-live="polite"></p>

	      <!-- Request link -->
	      <?php $shat_active_unlock_card = $uid ? shat_get_active_request_of_type( $uid, 'unlock', $pid ) : null; ?>
	      <?php if ( $shat_active_unlock_card ): ?>
	      <span class="shat-request-unlock" style="cursor:default;opacity:.6;background:none;border:none;">Request In Progress</span>
	      <?php else: ?>
	      <button type="button" onclick="shatOpenRequestSheet('unlock',<?php echo intval( $pid ); ?>)" class="shat-request-unlock shat-req-trigger" style="background:none;border:none;font-family:inherit;">&#8599; Request to Unlock</button>
	      <?php endif; ?>
	      <?php endif; ?>

	    </div>
	    <?php endif; /* $patreon_lock */ ?>

	    <?php else: ?>
	    <!-- Transparent shield — blocks right-click / drag on unlocked/normal images -->
	    <?php if ( ! $is_gallery ): ?>
	      <div class="shat-img-shield" aria-hidden="true"></div>
	    <?php endif; ?>
	    <?php endif; ?>

	  </div>
	  <?php endif; ?>

	  <!-- Like / view bar -->
	  <div class="shat-card-actions">
	    <button class="shat-like-btn<?php echo $lcls; ?>" onclick="shatToggleLike(this,<?php echo intval( $pid ); ?>)" aria-label="Like">
	      <svg viewBox="0 0 24 24" fill="<?php echo $fill; ?>" stroke="<?php echo $stroke; ?>" stroke-width="2">
	        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
	      </svg>
	    </button>

	    <?php if ( $uid && ! $is_locked ): ?>
	    <button
	      type="button"
	      class="shat-save-btn<?php echo $saved ? ' saved' : ''; ?>"
	      onclick="shatToggleFavorite(this,<?php echo intval( $pid ); ?>)"
	      aria-label="<?php echo $saved ? 'Remove from Saved' : 'Add to Saved'; ?>" aria-pressed="<?php echo $saved ? 'true' : 'false'; ?>"
	    >
	      <svg viewBox="0 0 24 24" fill="<?php echo $saved ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
	        <path d="M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>
	      </svg>
	    </button>
	    <?php endif; ?>
	  </div>

	  <?php if ( $caption || $desc ): ?>
	  <div class="shat-card-meta">
	    <?php if ( $caption ): ?><div class="shat-card-caption"><?php echo esc_html( $caption ); ?></div><?php endif; ?>
	    <?php if ( $desc    ): ?><div class="shat-card-desc"><?php echo esc_html( $desc ); ?></div><?php endif; ?>
	  </div>
	  <?php endif; ?>

	</div>
	<?php
	return ob_get_clean();
}


// ============================================================
// AJAX: LOAD MORE PHOTOS
// ============================================================
add_action( 'wp_ajax_shat_load_more', 'shat_ajax_load_more' );
function shat_ajax_load_more() {
	check_ajax_referer( 'shat_nonce', 'nonce' );
	if ( ! is_user_logged_in() || ! shat_is_approved() ) wp_die( '', '', 403 );
	$page   = max( 1, intval( $_POST['page']     ?? 1 ) );
	$per    = max( 1, intval( $_POST['per_page'] ?? 10 ) );
	$uid    = get_current_user_id();
	$feed_locked = ! shat_has_feed_access( $uid );
	$photos = shat_get_photos( $page, $per );
	$total  = shat_count_photos();
	$html   = '';
	foreach ( $photos as $ph ) $html .= shat_render_card( $ph, $uid, $feed_locked );
	wp_send_json_success( [ 'html' => $html, 'has_more' => ( $page * $per ) < $total ] );
}

// ============================================================
// AJAX: TOGGLE LIKE
// ============================================================
add_action( 'wp_ajax_shat_toggle_like', 'shat_ajax_toggle_like' );
function shat_ajax_toggle_like() {
	check_ajax_referer( 'shat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );
	global $wpdb;
	$pid  = intval( $_POST['photo_id'] ?? 0 );
	$uid  = get_current_user_id();
	$tbl  = $wpdb->prefix . 'shat_likes';
	$rows = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tbl WHERE photo_id=%d AND user_id=%d", $pid, $uid ) );
	if ( $rows ) {
		$wpdb->delete( $tbl, [ 'photo_id' => $pid, 'user_id' => $uid ] );
		$liked = false;
	} else {
		$wpdb->insert( $tbl, [ 'photo_id' => $pid, 'user_id' => $uid, 'ip_address' => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) ] );
		$liked = true;
	}
	wp_send_json_success( [ 'liked' => $liked, 'count' => shat_fmt( shat_photo_likes( $pid ) ) ] );
}

// ============================================================
// AJAX: TOGGLE FAVORITE (Save / Unsave — private per-user)
// ============================================================
add_action( 'wp_ajax_shat_toggle_favorite', 'shat_ajax_toggle_favorite' );
function shat_ajax_toggle_favorite() {
	check_ajax_referer( 'shat_nonce', 'nonce' );
	if ( ! is_user_logged_in() || ! shat_is_approved() ) wp_send_json_error( 'Not logged in', 401 );
	global $wpdb;
	$pid  = intval( $_POST['photo_id'] ?? 0 );
	$uid  = get_current_user_id();
	if ( ! $pid || get_post_type( $pid ) !== 'shat_photo' ) wp_send_json_error( 'Invalid photo', 400 );
	/* 2.3.0 — don't let a member save a post that isn't shared with them. */
	if ( ! shat_user_can_view_post( $pid, $uid ) ) wp_send_json_error( 'Not available', 403 );
	$tbl  = $wpdb->prefix . 'shat_favorites';
	$row  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tbl WHERE photo_id=%d AND user_id=%d", $pid, $uid ) );
	$tile_html = '';
	if ( $row ) {
		$wpdb->delete( $tbl, [ 'photo_id' => $pid, 'user_id' => $uid ] );
		$saved = false;
	} else {
		$wpdb->insert( $tbl, [ 'photo_id' => $pid, 'user_id' => $uid ] );
		$saved = true;
		/* Render the card server-side now, so the Saved tab can insert it
		   immediately without needing a page refresh to pick it up. Pass the
		   feed-lock state too — the Save button is already hidden client-side
		   once feed access expires, but this keeps the card correct even if
		   this endpoint is ever hit from a stale page.

		   Same renderer as the feed, so Saved is visually identical. */
		$photo_post = get_post( $pid );
		if ( $photo_post ) $tile_html = shat_render_card( $photo_post, $uid, ! shat_has_feed_access( $uid ) );
	}
	wp_send_json_success( [ 'saved' => $saved, 'count' => shat_count_saved( $uid ), 'tile_html' => $tile_html, 'photo_id' => $pid ] );
}

// ============================================================
// AJAX: LOAD SAVED PHOTOS (same card layout as the feed)
// ============================================================
add_action( 'wp_ajax_shat_load_saved', 'shat_ajax_load_saved' );
function shat_ajax_load_saved() {
	check_ajax_referer( 'shat_nonce', 'nonce' );
	if ( ! is_user_logged_in() || ! shat_is_approved() ) wp_die( '', '', 403 );
	$uid    = get_current_user_id();
	$page   = max( 1, intval( $_POST['page']     ?? 1 ) );
	$per    = max( 1, intval( $_POST['per_page'] ?? 10 ) );
	$feed_locked = ! shat_has_feed_access( $uid );
	$photos = shat_get_saved_photos( $uid, $page, $per );
	$total  = shat_count_saved( $uid );
	$html   = '';
	foreach ( $photos as $ph ) $html .= shat_render_card( $ph, $uid, $feed_locked );
	wp_send_json_success( [ 'html' => $html, 'has_more' => ( $page * $per ) < $total, 'total' => $total ] );
}


// ============================================================
// AJAX: SUBMIT ACCESS REQUEST (replaces mailto: links)
// ============================================================
add_action( 'wp_ajax_shat_submit_access_request', 'shat_ajax_submit_access_request' );
function shat_ajax_submit_access_request() {
	check_ajax_referer( 'shat_nonce', 'nonce' );
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in', 401 );
	shat_ensure_access_requests_table();

	$uid  = get_current_user_id();
	$user = get_userdata( $uid );
	if ( ! $user ) wp_send_json_error( 'Invalid user', 400 );

	/* Hard gate: only a confirmed email address may raise a request.
	   Accounts created before OTP verification existed are grandfathered
	   in by shat_email_is_verified(). */
	if ( ! shat_email_is_verified( $uid ) ) {
		wp_send_json_error( [ 'code' => 'email_unverified', 'message' => 'Please verify your email address first.' ], 403 );
	}

	$type = sanitize_key( $_POST['type'] ?? 'access' );
	if ( ! in_array( $type, [ 'access', 'renew', 'unlock' ], true ) ) $type = 'access';

	/* 'access' requests come from pending (not-yet-approved) members, so this
	   endpoint intentionally does NOT require shat_is_approved() — only login. */
	$pid = intval( $_POST['photo_id'] ?? 0 );
	if ( $pid && get_post_type( $pid ) !== 'shat_photo' ) $pid = 0;

	/* Block a duplicate request of the same type (and, for 'unlock', the same
	   specific photo) while one is still open or in progress — the member
	   sees "Request in progress" in the UI instead of being able to fire off
	   a second one for the exact same thing. */
	$existing = shat_get_active_request_of_type( $uid, $type, $type === 'unlock' ? $pid : null );
	if ( $existing ) {
		wp_send_json_error( [ 'code' => 'already_active', 'message' => 'You already have a request in progress.' ], 409 );
	}

	$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
	if ( mb_strlen( $message ) > 600 ) $message = mb_substr( $message, 0, 600 );

	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'shat_access_requests', [
		'user_id'  => $uid,
		'type'     => $type,
		'photo_id' => $pid,
		'message'  => $message,
		'status'   => 'open',
	] );
	$request_id = (int) $wpdb->insert_id;


	/* Create the NOWPayments invoice right away and return its URL so the
	   sheet can show a "Pay Now" button immediately — the member doesn't
	   have to wait for an admin reply to get a payment link. */
	$invoice_url = '';
	$invoice     = shat_nowpay_create_invoice( $uid, $type, $pid, $request_id );
	if ( ! is_wp_error( $invoice ) ) {
		$invoice_url = $invoice;
	}

	/* Email notification — same destination/style as the old mailto: links,
	   but the member never has to leave the site or open a mail app. */
	$label = shat_request_type_label( $type );
	$name  = $user->first_name ?: $user->display_name;
	$body  = '<p><strong>' . esc_html( $name ) . '</strong> (' . esc_html( $user->user_email ) . ') submitted a request from the Shatania feed.</p>';
	$body .= '<p style="color:#C08A8E;font-weight:600;">' . esc_html( $label ) . '</p>';
	if ( $pid ) {
		$cap = get_post_meta( $pid, '_shat_caption', true ) ?: get_the_title( $pid );
		$body .= '<p>Photo: <strong>' . esc_html( $cap ) . '</strong> (#' . $pid . ')</p>';
	}
	if ( $message !== '' ) {
		$body .= '<p style="background:#F8F8F8;border-radius:10px;padding:14px 16px;color:#333;">' . nl2br( esc_html( $message ) ) . '</p>';
	}
	$body .= '<p><a href="' . esc_url( admin_url( 'admin.php?page=shatania-requests' ) ) . '" class="btn">View in Dashboard &rarr;</a></p>';

	shat_mail( SHAT_UNLOCK_EMAIL, 'New Request: ' . $label . ' — ' . $name, shat_email_wrap( 'New Access Request', $body ) );

	wp_send_json_success( [ 'invoice_url' => $invoice_url ] );
}


// ============================================================
// NOWPAYMENTS: IPN WEBHOOK (public endpoint — NOWPayments' own servers
// call this, not a logged-in user, hence nopriv registration too)
// ============================================================
add_action( 'wp_ajax_shat_nowpay_ipn',        'shat_ajax_nowpay_ipn' );
add_action( 'wp_ajax_nopriv_shat_nowpay_ipn', 'shat_ajax_nowpay_ipn' );
function shat_ajax_nowpay_ipn() {
	shat_ensure_access_requests_table();
	global $wpdb;

	$raw_body  = file_get_contents( 'php://input' );
	$signature = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? '';

	/* Reject anything that isn't genuinely signed by NOWPayments. This is
	   the ONLY thing standing between "real payment happened" and "anyone
	   on the internet POSTs a fake success payload to this public URL and
	   gets free access" — never relax or bypass this check. */
	if ( ! shat_nowpay_verify_ipn( $raw_body, $signature ) ) {
		status_header( 401 );
		echo 'Invalid signature';
		exit;
	}

	$data = json_decode( $raw_body, true );
	if ( ! is_array( $data ) || empty( $data['payment_id'] ) ) {
		status_header( 400 );
		echo 'Malformed payload';
		exit;
	}

	$nowpay_id = (string) $data['payment_id'];
	$status    = sanitize_key( $data['payment_status'] ?? '' );

	$payment = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}shat_payments WHERE nowpay_id=%s", $nowpay_id ) );

	if ( ! $payment ) {
		// Unknown payment ID — nothing to update, but still 200 so NOWPayments
		// doesn't endlessly retry a webhook we'll never be able to match.
		status_header( 200 );
		echo 'OK (unrecognized payment)';
		exit;
	}

	$wpdb->update( $wpdb->prefix . 'shat_payments', [
		'status'     => $status,
		'pay_amount' => $data['pay_amount'] ?? $payment->pay_amount,
		'updated_at' => current_time( 'mysql' ),
	], [ 'id' => $payment->id ] );

	/* 'finished' is NOWPayments' terminal success state — only grant access
	   on this, never on 'confirming'/'waiting', and never twice: check the
	   payment row's own prior status so a duplicate/retried webhook call
	   can't double-grant (e.g. extend renewal twice for one payment). */
	if ( $status === 'finished' && $payment->status !== 'finished' ) {
		$payment->status = $status; // reflect the update for shat_grant_paid_access
		shat_grant_paid_access( $payment );
	}

	status_header( 200 );
	echo 'OK';
	exit;
}







// ============================================================
// AJAX: RECORD VIEW
// ============================================================
add_action( 'wp_ajax_shat_record_view',        'shat_ajax_record_view' );
add_action( 'wp_ajax_nopriv_shat_record_view', 'shat_ajax_record_view' );
function shat_ajax_record_view() {
	check_ajax_referer( 'shat_nonce', 'nonce' );
	$pid = intval( $_POST['photo_id'] ?? 0 );
	if ( $pid ) shat_record_view( $pid );
	wp_send_json_success();
}

// ============================================================
// AJAX: UPDATE AUTHOR AVATAR (Admin only)
// ============================================================
add_action( 'wp_ajax_shat_update_avatar', 'shat_ajax_update_avatar' );
function shat_ajax_update_avatar() {
	check_ajax_referer( 'shat_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_die( '', '', 403 );
	$url = esc_url_raw( $_POST['avatar_url'] ?? '' );
	update_option( 'shat_author_avatar', $url );
	wp_send_json_success( [ 'url' => $url ] );
}

// ============================================================
// AJAX: UNLOCK PHOTO  (NEW in 1.3.0)
// ============================================================
add_action( 'wp_ajax_shat_unlock_photo', 'shat_ajax_unlock_photo' );
function shat_ajax_unlock_photo() {
	check_ajax_referer( 'shat_nonce', 'nonce' );

	if ( ! is_user_logged_in() )  wp_send_json_error( 'Please log in to unlock photos.', 401 );
	if ( ! shat_is_approved()  )  wp_send_json_error( 'Your account is not yet approved.',  403 );

	global $wpdb;
	$pid     = intval( sanitize_text_field( $_POST['photo_id'] ?? 0 ) );
	$entered = sanitize_text_field( $_POST['passcode'] ?? '' );
	$uid     = get_current_user_id();

	if ( ! $pid || $entered === '' ) {
		wp_send_json_error( 'Invalid request.' );
	}

	/* Verify the photo exists and is actually blurred. */
	if ( get_post_type( $pid ) !== 'shat_photo' || ! shat_is_blurred( $pid ) ) {
		wp_send_json_error( 'This photo is not locked.' );
	}

	/* 2.3.0 — audience check: a post shared with specific members only
	   can't be unlocked by anyone else, even with a correct passcode. */
	if ( ! shat_user_can_view_post( $pid, $uid ) ) {
		wp_send_json_error( 'This photo is not available.' );
	}

	/* 2.3.0 — Patreon exclusives have no passcode path at all. The only
	   way in is Patreon, so a leaked/reused passcode can't open one. */
	if ( shat_is_patreon( $pid ) ) {
		wp_send_json_error( 'This post is a Patreon exclusive.' );
	}

	/* If user already unlocked it (e.g. race condition / double-click), just confirm. */
	if ( shat_user_unlocked( $pid, $uid ) ) {
		wp_send_json_success( [ 'unlocked' => true, 'already' => true ] );
	}

	/* Compare passcode — use hash_equals() to prevent timing attacks. */
	$stored = (string) get_post_meta( $pid, '_shat_passcode', true );
	if ( $stored === '' || ! hash_equals( $stored, $entered ) ) {
		wp_send_json_error( 'Incorrect passcode. Please try again.' );
	}

	/* Persist the unlock permanently for this user. */
	$wpdb->insert(
		$wpdb->prefix . 'shat_unlocked',
		[ 'photo_id' => $pid, 'user_id' => $uid ],
		[ '%d', '%d' ]
	);

	wp_send_json_success( [ 'unlocked' => true ] );
}

// ============================================================
// NO-INDEX — only the home page is allowed on Google.
// Everything else (every attachment, page, post, and archive —
// regardless of how the image/content was uploaded or created)
// gets noindex by default. This does NOT depend on any per-photo
// flag or post type, so Media Library uploads are covered too.
// ============================================================

add_filter( 'wp_robots', 'shat_wp_robots_home_only' );
function shat_wp_robots_home_only( $robots ) {
	if ( ! is_front_page() ) {
		$robots['noindex']      = true;
		$robots['nofollow']     = true;
		$robots['noarchive']    = true;
		$robots['noimageindex'] = true;
	}
	return $robots;
}

/* Belt-and-suspenders: also send the X-Robots-Tag HTTP header on every
   non-home request. Some crawlers weight the HTTP header over the <meta>
   tag, and it survives even if a caching layer strips <head> content. */
add_action( 'send_headers', 'shat_send_noindex_header' );
function shat_send_noindex_header() {
	if ( ! is_front_page() ) {
		header( 'X-Robots-Tag: noindex, nofollow, noarchive, noimageindex', true );
	}
}

/* Strip everything except the home URL out of WordPress's built-in XML
   sitemap (/wp-sitemap.xml), so Google never discovers any other page,
   post, or attachment through that route. */
add_filter( 'wp_sitemaps_posts_query_args', 'shat_exclude_all_but_home_from_sitemap', 10, 2 );
function shat_exclude_all_but_home_from_sitemap( $args, $post_type ) {
	// No post type belongs in the sitemap — the home URL isn't a queried
	// post, so excluding every post type here is sufficient and simplest.
	$args['post__in'] = [ 0 ]; // forces an empty result set
	return $args;
}

/* NOTE: intentionally no Disallow lines in robots.txt. Blocking crawl
   access prevents Google from ever reading the noindex tag/header above —
   a Disallow'd URL can stay indexed as a bare link with no preview instead
   of being removed. noindex + no crawl-block is the correct combination. */

// ============================================================
// FILTER: FORCE FROM ADDRESS
// ============================================================
add_filter( 'wp_mail_from',      fn() => SHAT_FROM_EMAIL );
add_filter( 'wp_mail_from_name', fn() => SHAT_FROM_NAME  );

// ============================================================
// SECURITY: BLOCK NON-ADMIN FROM WP-ADMIN
// ============================================================
add_action( 'after_setup_theme', function() {
	if ( ! current_user_can( 'manage_options' ) ) show_admin_bar( false );
} );
add_action( 'admin_init', function() {
	if ( is_admin() && ! current_user_can( 'manage_options' ) && ! wp_doing_ajax() ) {
		wp_safe_redirect( shat_page_url( 'shatania-feed' ) ); exit;
	}
} );

// ============================================================
// ADMIN MENU
// ============================================================
/* ============================================================
   ADMIN: SETTINGS PAGE (NEW)
   Currently houses the manual-approval toggle. Saves to the
   'shat_require_approval' option read by shat_require_approval().
   ============================================================ */
function shat_admin_settings() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$saved = false;
	if ( isset( $_POST['shat_settings_nonce'] ) && wp_verify_nonce( $_POST['shat_settings_nonce'], 'shat_save_settings' ) ) {
		// Unchecked checkbox = auto-approve OFF the manual gate => '0'.
		$manual = isset( $_POST['shat_require_approval'] ) ? '1' : '0';
		update_option( 'shat_require_approval', $manual, false );
		$saved = true;
	}

	$manual_on = shat_require_approval();
	?>
	<div class="wrap">
		<h1>Settings</h1>
		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
		<?php endif; ?>

		<form method="post" style="max-width:640px;margin-top:18px;">
			<?php wp_nonce_field( 'shat_save_settings', 'shat_settings_nonce' ); ?>

			<div style="background:#fff;border:1px solid #e2e2e2;border-radius:12px;padding:22px 24px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
				<h2 style="margin:0 0 6px;font-size:16px;">New member approval</h2>
				<p style="margin:0 0 18px;color:#666;font-size:13px;line-height:1.6;">
					Choose what happens right after a new person verifies their email with the one-time code.
				</p>

				<label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;">
					<input type="checkbox" name="shat_require_approval" value="1" <?php checked( $manual_on ); ?> style="margin-top:3px;transform:scale(1.25);">
					<span>
						<strong style="font-size:14px;">Review new members before they get access</strong>
						<span style="display:block;color:#666;font-size:13px;line-height:1.6;margin-top:4px;">
							<strong>On</strong> — New signups land as <em>pending</em>. They wait on the waitlist until you approve them from the Members page. (Original behaviour.)<br>
							<strong>Off</strong> — Verified signups are approved instantly. They go straight to the app-download screen, install the app, and get in — plus notifications for new posts and messages. No action needed from you.
						</span>
					</span>
				</label>
			</div>

			<p style="margin-top:20px;">
				<button type="submit" class="button button-primary">Save Settings</button>
			</p>

			<p style="color:#888;font-size:12px;line-height:1.6;max-width:560px;">
				Note: turning approval <em>off</em> only affects <strong>future</strong> signups. Anyone already pending stays pending until you approve them (or you can bulk-approve on the Members page). Either way, the feed still requires the app and login — this only controls the human review step.
			</p>
		</form>
	</div>
	<?php
}

add_action( 'admin_menu', 'shat_admin_menu' );
function shat_admin_menu() {
	add_menu_page( 'Shatania', 'Shatania', 'manage_options', 'shatania',
		'shat_admin_dashboard', 'dashicons-camera', 4 );
	add_submenu_page( 'shatania', 'Dashboard',     'Dashboard',     'manage_options', 'shatania',          'shat_admin_dashboard' );
	add_submenu_page( 'shatania', 'Upload Photo',  'Upload Photo',  'manage_options', 'shatania-upload',   'shat_admin_upload'    );
	add_submenu_page( 'shatania', 'Chat Style',    'Chat Style',    'manage_options', 'shatania-chatstyle','shat_admin_chatstyle' );
	add_submenu_page( 'shatania', 'Email Check',   'Email Check',   'manage_options', 'shatania-mail',     'shat_admin_mail'      );
	add_submenu_page( 'shatania', 'Manage Photos', 'Manage Photos', 'manage_options', 'shatania-photos',   'shat_admin_photos'    );
	add_submenu_page( 'shatania', 'Activity',      'Activity',      'manage_options', 'shatania-activity', 'shat_admin_activity'  );
	add_submenu_page( 'shatania', 'Members',       'Members',       'manage_options', 'shatania-members',  'shat_admin_members'   );
	add_submenu_page( 'shatania', 'Access Requests', 'Access Requests', 'manage_options', 'shatania-requests', 'shat_admin_requests' );
	add_submenu_page( 'shatania', 'Settings',      'Settings',      'manage_options', 'shatania-settings', 'shat_admin_settings'  );
	add_submenu_page( 'shatania', 'Email Members', 'Email Members', 'manage_options', 'shatania-email',    'shat_admin_email_pg'  );
	add_submenu_page( 'shatania', 'Chat',          'Chat',          'manage_options', 'shatania-chat',     'schat_admin_page'     );
	add_submenu_page( 'shatania', '2FA Security',  'Admin 2FA',     'manage_options', 'shatania-2fa',      'shat_admin_2fa'       );
	add_submenu_page( 'shatania', 'Push Setup',    'Push Setup',    'manage_options', 'shatania-push',     'shat_admin_push_setup' );
	add_submenu_page( 'shatania', 'App & Access',  'App & Access',  'manage_options', 'shatania-app',      'shat_admin_app_download' );
	/* Hidden page — Edit a published photo (linked from Manage Photos) */
	add_submenu_page( null, 'Edit Photo', 'Edit Photo', 'manage_options', 'shatania-edit', 'shat_admin_edit' );
}

// Note: the unread count is shown only as a badge on the chat button
// itself (see .schat-fab-badge in schat_sc_button() and
// schat_render_admin_front_widget()) — no separate admin-toolbar
// notification, so there's only ever one place the count appears.

// Note: chat CSS/JS for the wp-admin page are now embedded directly
// inside schat_admin_page() via schat_inline_assets() — see above —
// so no separate admin_enqueue_scripts hook is needed here anymore.

// ============================================================
// ADMIN: SHARED CSS
// ============================================================
function shat_admin_css() { echo '
<style>
@import url("https://fonts.googleapis.com/css2?family=Zilla+Slab:ital,wght@0,300;0,400;0,500;0,600;1,300&display=swap");
:root{--gold:#C08A8E;--text:#1F1F1F;--sec:#6B6B6B;--font:"Zilla Slab",Georgia,serif;}
#wpcontent,#wpfooter{background:#F7F7F7;}
.shat-a-wrap{font-family:var(--font);max-width:1080px;padding:28px 0 60px;-webkit-font-smoothing:antialiased;}
.shat-a-wrap h1{font-size:26px;font-weight:500;color:var(--text);letter-spacing:-.4px;margin-bottom:4px;}
.shat-a-wrap .sub{font-size:14px;color:var(--sec);font-weight:300;font-style:italic;margin-bottom:28px;}
.shat-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px;margin-bottom:28px;}
.shat-sc{background:#fff;border:1px solid #E8E8E8;border-radius:16px;padding:20px 22px;}
.shat-sc .n{font-size:36px;font-weight:500;color:var(--text);line-height:1;margin-bottom:4px;}
.shat-sc .l{font-size:11px;color:var(--sec);text-transform:uppercase;letter-spacing:1px;}
.shat-sc .gold{color:var(--gold);}
.shat-box{background:#fff;border:1px solid #E8E8E8;border-radius:16px;padding:24px 26px;margin-bottom:22px;}
.shat-box h2{font-size:16px;font-weight:500;color:var(--text);margin-bottom:18px;letter-spacing:-.2px;}
.shat-tbl{width:100%;border-collapse:collapse;font-size:14px;}
.shat-tbl th{background:#F8F8F8;padding:10px 14px;text-align:left;font-size:11px;color:var(--sec);text-transform:uppercase;letter-spacing:.9px;font-weight:500;border-bottom:1px solid #EEEEEE;}
.shat-tbl td{padding:12px 14px;border-bottom:1px solid #F4F4F4;color:var(--text);vertical-align:middle;}
.shat-tbl tr:last-child td{border-bottom:none;}
.shat-tbl tr:hover td{background:#FAFAFA;}
.ab{display:inline-block;padding:5px 12px;border-radius:8px;font-family:var(--font);font-size:12px;font-weight:500;cursor:pointer;text-decoration:none;border:none;line-height:1.5;}
.ab.ap{background:#E8F5E9;color:#2E7D32;}
.ab.rj{background:#FFEBEE;color:#C62828;margin-left:4px;}
.ab.dl{background:#FFEBEE;color:#C62828;}
.ab.pr{background:var(--text);color:#fff;padding:9px 20px;font-size:14px;}
.ab.unblur{background:#FBF0F1;color:#8B4F53;}
.shat-badge{display:inline-block;padding:3px 11px;border-radius:20px;font-size:11px;font-weight:500;}
.sb-pending{background:#FBF0F1;color:#8B4F53;}
.sb-approved{background:#E8F5E9;color:#2E7D32;}
.sb-rejected{background:#FFEBEE;color:#C62828;}
.sb-locked{background:#F5E6E7;color:#8B4F53;}
.shat-thumb{width:46px;height:58px;object-fit:cover;border-radius:6px;display:block;}
.shat-thumb.blurred{filter:blur(4px);}
.shat-fr{margin-bottom:16px;}
.shat-fr label{display:block;font-size:11px;color:var(--sec);text-transform:uppercase;letter-spacing:.9px;margin-bottom:7px;font-weight:500;}
.shat-fr input[type=text],.shat-fr input[type=date],.shat-fr textarea,.shat-fr input[type=email]{width:100%;padding:11px 14px;border:1.5px solid #E8E8E8;border-radius:10px;font-family:var(--font);font-size:14px;outline:none;transition:border-color .2s;background:#FAFAFA;color:var(--text);}
.shat-fr input:focus,.shat-fr textarea:focus{border-color:var(--gold);background:#fff;}
.shat-fr textarea{min-height:90px;resize:vertical;}
/* Blur toggle row */
.shat-blur-toggle-wrap{background:#FAFAFA;border:1.5px solid #E8E8E8;border-radius:12px;padding:14px 16px;transition:border-color .2s;}
.shat-blur-toggle-wrap.active{border-color:var(--gold);background:#FFFDF6;}
.shat-blur-toggle-label{display:inline-flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;color:var(--text);font-family:var(--font);font-weight:400;}
.shat-blur-toggle-label input[type=checkbox]{width:18px;height:18px;accent-color:var(--gold);cursor:pointer;}
.shat-passcode-hint{font-size:12px;color:var(--sec);margin-top:8px;font-style:italic;font-weight:300;}
.shat-sublist{max-height:240px;overflow-y:auto;border:1px solid #E8E8E8;border-radius:10px;padding:8px;margin-bottom:14px;background:#FAFAFA;}
.shat-si{display:flex;align-items:center;gap:10px;padding:9px 6px;border-bottom:1px solid #F0F0F0;font-size:13px;color:var(--text);}
.shat-si:last-child{border-bottom:none;}
.shat-notice{padding:13px 16px;border-radius:9px;margin-bottom:18px;font-size:14px;}
.shat-notice.ok{background:#E8F5E9;color:#1B5E20;}
.shat-notice.err{background:#FFEBEE;color:#B71C1C;}
.shat-alert-bar{background:#FBF0F1;border:1px solid #E8C4C6;border-radius:12px;padding:14px 18px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:12px;}
.shat-alert-bar span{font-size:13px;color:#8B4F53;}
.shat-alert-bar a{background:var(--gold);color:#fff;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:13px;white-space:nowrap;}
.filter-tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;}
.filter-tabs a{padding:7px 16px;border-radius:9px;font-family:var(--font);font-size:13px;text-decoration:none;border:1px solid #E8E8E8;color:#888;background:#fff;transition:all .2s;}
.filter-tabs a.active{background:var(--text);color:#fff;border-color:var(--text);}

/* ---- Member stats + search + pager (2.3.0) ---- */
.shat-stat-grid{
  display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
  gap:12px; margin-bottom:16px;
}
.shat-stat-card{
  background:#fff; border:1px solid #ECECEC; border-radius:12px;
  padding:16px 18px; display:flex; flex-direction:column; gap:4px;
  text-decoration:none; transition:border-color .2s, box-shadow .2s, transform .15s;
}
a.shat-stat-card:hover{border-color:#D9A9AC;box-shadow:0 4px 14px rgba(0,0,0,.06);transform:translateY(-1px);}
.shat-stat-n{font-size:26px;font-weight:700;line-height:1.1;letter-spacing:-.5px;}
.shat-stat-l{font-size:12px;color:#999;letter-spacing:.3px;}

.shat-gender-bar{
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  background:#fff; border:1px solid #ECECEC; border-radius:12px;
  padding:12px 16px; margin-bottom:16px;
}
.shat-gender-title{font-size:11px;text-transform:uppercase;letter-spacing:.9px;color:#AAA;font-weight:600;}
.shat-gender-chip{
  font-size:13px; color:#666;
  background:#F7F7F7; border:1px solid #EEE; border-radius:8px; padding:5px 11px;
}
.shat-gender-chip strong{color:#333;font-weight:600;}
.shat-gender-chip em{font-style:normal;color:#AAA;font-size:12px;}

.shat-search-row{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;}
.shat-search-input{
  flex:1; min-width:200px; max-width:360px;
  padding:8px 13px; border:1px solid #DDD; border-radius:9px;
  font-family:var(--font); font-size:13.5px; outline:none;
  transition:border-color .2s, box-shadow .2s;
}
.shat-search-input:focus{border-color:#D9A9AC;box-shadow:0 0 0 3px rgba(217,169,172,.15);}

.shat-result-line{font-size:13px;color:#888;margin:0 0 12px;}
.shat-result-line strong{color:#333;}

.shat-pager{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:18px;justify-content:center;}
.shat-pager .ab{text-decoration:none;}
.shat-pager-cur{background:var(--text) !important;color:#fff !important;border-color:var(--text) !important;}
.shat-pager-gap{color:#BBB;padding:0 2px;}

.shat-perpage{text-align:center;font-size:12.5px;color:#999;margin-top:10px;}
.shat-perpage a{color:#888;text-decoration:none;border-bottom:1px dotted #CCC;margin:0 2px;}
.shat-perpage a:hover{color:#D9A9AC;border-color:#D9A9AC;}
.shat-perpage strong{color:#333;margin:0 2px;}
.shat-admin-avatar{width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid #E8E8E8;}
.shat-admin-avatar-wrap{display:flex;align-items:center;gap:16px;margin-bottom:4px;}
.shat-admin-avatar-initial{width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#C08A8E,#8B4F53);display:inline-flex;align-items:center;justify-content:center;font-size:22px;color:#fff;font-weight:500;font-family:var(--font);}
code.shat-code{background:#F0F0F0;padding:2px 8px;border-radius:5px;font-size:12px;color:#444;font-family:monospace;border:1px solid #E4E4E4;user-select:all;cursor:text;}
@media(max-width:900px){.shat-stats{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px){.shat-a-wrap h1{font-size:20px;}.shat-sc .n{font-size:28px;}}
</style>'; }

// ============================================================
// ADMIN: DASHBOARD
// ============================================================
function shat_admin_dashboard() {
	global $wpdb;
	shat_admin_css();

	$total_ph  = shat_count_photos();
	$total_lk  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}shat_likes" );
	$total_vw  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}shat_views" );
	$total_mb  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='shat_status' AND meta_value='approved'" );
	$pending_n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key='shat_status' AND meta_value='pending'" );
	$total_bl  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_shat_blurred' AND meta_value='1'" );

	/* Feed access expiry counts — only among approved members (pending/rejected don't have feed access anyway) */
	$now          = current_time('timestamp');
	$soon_cutoff  = date( 'Y-m-d H:i:s', $now + ( 3 * DAY_IN_SECONDS ) );
	$now_mysql    = current_time('mysql');
	$expiring_n   = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->usermeta}
		 WHERE meta_key = 'shat_feed_expiry'
		 AND meta_value > %s AND meta_value <= %s",
		$now_mysql, $soon_cutoff
	) );
	$expired_n    = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->usermeta}
		 WHERE meta_key = 'shat_feed_expiry'
		 AND meta_value <= %s",
		$now_mysql
	) );

	/* Save avatar form */
	$avatar_msg = '';
	if ( isset( $_POST['shat_avatar_nonce'] ) && wp_verify_nonce( $_POST['shat_avatar_nonce'], 'shat_admin_avatar' ) ) {
		$new_av = esc_url_raw( $_POST['admin_avatar_url'] ?? '' );
		update_option( 'shat_author_avatar', $new_av );
		$avatar_msg = '<div class="shat-notice ok">&#10003; Author avatar updated.</div>';
	}

	$current_avatar = get_option( 'shat_author_avatar', '' );

	echo '<div class="shat-a-wrap">';
	echo '<h1>Shatania — Dashboard</h1>';
	echo '<p class="sub">Happy ' . date('l') . ' — Here\'s your community at a glance</p>';
	echo '<div class="shat-stats">';
	shat_admin_stat( 'Photos',         $total_ph,           '📷' );
	shat_admin_stat( 'Total Likes',    shat_fmt($total_lk), '♥', true );
	shat_admin_stat( 'Total Views',    shat_fmt($total_vw), '👁' );
	shat_admin_stat( 'Active Members', $total_mb,           '👥' );
	shat_admin_stat( 'Locked Photos',  $total_bl,           '🔒' );
	echo '</div>';

	if ( $pending_n > 0 ) {
		echo '<div class="shat-alert-bar">';
		echo '<span>⏳ <strong>' . $pending_n . '</strong> member(s) waiting for approval</span>';
		echo '<a href="' . admin_url('admin.php?page=shatania-members&filter=pending') . '">Review Now</a>';
		echo '</div>';
	}

	if ( $expiring_n > 0 || $expired_n > 0 ) {
		echo '<div class="shat-alert-bar">';
		$parts = [];
		if ( $expiring_n > 0 ) $parts[] = $expiring_n . ' member(s) expiring within 3 days';
		if ( $expired_n  > 0 ) $parts[] = $expired_n  . ' member(s) already expired';
		echo '<span>⏱️ <strong>' . implode( ', ', $parts ) . '</strong></span>';
		echo '<a href="' . admin_url('admin.php?page=shatania-members') . '">Review Feed Access</a>';
		echo '</div>';
	}

	$open_requests_n = shat_count_open_requests();
	if ( $open_requests_n > 0 ) {
		echo '<div class="shat-alert-bar">';
		echo '<span>💬 <strong>' . $open_requests_n . '</strong> open access request(s)</span>';
		echo '<a href="' . admin_url('admin.php?page=shatania-requests') . '">Review Requests</a>';
		echo '</div>';
	}

	/* Author avatar */
	echo '<div class="shat-box"><h2>👤 Author Avatar (Feed Cards)</h2>';
	echo $avatar_msg;
	echo '<p style="font-size:13px;color:#888;margin-bottom:16px;font-weight:300;">This photo appears in the header of each individual feed card.</p>';
	echo '<div class="shat-admin-avatar-wrap">';
	if ( $current_avatar ) echo '<img src="' . esc_url($current_avatar) . '" class="shat-admin-avatar" id="dash-av-img" alt="Author Avatar">';
	else                   echo '<div class="shat-admin-avatar-initial" id="dash-av-img">S</div>';
	echo '<div>';
	echo '<button type="button" class="ab pr" onclick="shatDashAvatarPick()" style="margin-right:8px;">&#128247; Change Photo</button>';
	if ( $current_avatar ) echo '<button type="button" class="ab dl" onclick="shatDashAvatarRemove()">Remove</button>';
	echo '</div></div>';
	echo '<form method="post" id="shat-dash-av-form" style="display:none;">';
	wp_nonce_field('shat_admin_avatar','shat_avatar_nonce');
	echo '<input type="hidden" name="admin_avatar_url" id="dash-av-url-field" value="' . esc_attr($current_avatar) . '">';
	echo '</form>';
	echo '<script>
	function shatDashAvatarPick(){var fr=wp.media({title:"Select Author Photo",multiple:false,library:{type:"image"},button:{text:"Use This Photo"}});fr.on("select",function(){var a=fr.state().get("selection").first().toJSON();document.getElementById("dash-av-url-field").value=a.url;document.getElementById("shat-dash-av-form").submit();});fr.open();}
	function shatDashAvatarRemove(){if(!confirm("Remove author avatar?"))return;document.getElementById("dash-av-url-field").value="";document.getElementById("shat-dash-av-form").submit();}
	</script>';
	echo '</div>';

	/* Shortcodes reference */
	echo '<div class="shat-box"><h2>📋 Shortcodes</h2>';
	$scs   = [
		'[shatania_login]'    => 'Login page',
		'[shatania_register]' => 'Registration form',
		'[shatania_feed]'     => 'Feed with profile header',
		'[shatania_reset]'    => 'Password reset page',
	];
	$slugs = [ 'shatania-login','shatania-register','shatania-feed','shatania-reset' ];
	echo '<table class="shat-tbl"><thead><tr><th>Shortcode</th><th>Description</th><th>Page</th></tr></thead><tbody>';
	$i = 0;
	foreach ( $scs as $sc => $desc ) {
		$pg  = get_page_by_path( $slugs[$i] );
		$url = $pg ? get_permalink($pg->ID) : '—';
		echo '<tr><td><code style="background:#F5F5F5;padding:3px 8px;border-radius:5px;font-size:12px;">' . esc_html($sc) . '</code></td>';
		echo '<td style="color:#888;">' . esc_html($desc) . '</td>';
		echo '<td>' . ($pg ? '<a href="' . esc_url($url) . '" target="_blank" style="color:var(--gold);text-decoration:none;font-size:12px;">' . esc_url($url) . ' ↗</a>' : '—') . '</td></tr>';
		$i++;
	}
	echo '</tbody></table></div>';

	/* Recent payments (NOWPayments) */
	$recent_payments = $wpdb->get_results(
		"SELECT * FROM {$wpdb->prefix}shat_payments ORDER BY created_at DESC LIMIT 8" );
	echo '<div class="shat-box"><h2>Recent Payments</h2>';
	if ( ! SHAT_NOWPAY_API_KEY ) {
		echo '<p style="color:#999;font-size:14px;">NOWPayments isn\'t configured yet — add your API key and IPN secret in the plugin file to start accepting payments.</p>';
	} elseif ( empty( $recent_payments ) ) {
		echo '<p style="color:#999;font-size:14px;">No payments yet.</p>';
	} else {
		echo '<table class="shat-tbl"><thead><tr><th>Member</th><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>';
		foreach ( $recent_payments as $p ) {
			$pu = get_userdata( $p->user_id );
			$pname = $pu ? $pu->display_name : 'Deleted user';
			$status_cls = $p->status === 'finished' ? 'sb-approved' : ( in_array( $p->status, ['failed','expired'], true ) ? 'sb-rejected' : 'sb-pending' );
			echo '<tr>';
			echo '<td><strong>' . esc_html( $pname ) . '</strong></td>';
			echo '<td>' . esc_html( shat_request_type_label( $p->type ) ) . '</td>';
			echo '<td>$' . esc_html( number_format( (float) $p->price_amount, 2 ) ) . '</td>';
			echo '<td><span class="shat-badge ' . $status_cls . '">' . esc_html( ucfirst( $p->status ) ) . '</span></td>';
			echo '<td style="color:#999;white-space:nowrap;">' . esc_html( date( 'M j, Y g:ia', strtotime( $p->created_at ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';

	/* Recent photos */
	$recent = shat_get_photos(1,5);
	echo '<div class="shat-box"><h2>Recent Photos</h2>';
	if ( empty($recent) ) {
		echo '<p style="color:#999;font-size:14px;">No photos yet. <a href="' . admin_url('admin.php?page=shatania-upload') . '" style="color:var(--gold);">Upload your first →</a></p>';
	} else {
		echo '<table class="shat-tbl"><thead><tr><th>Photo</th><th>Caption</th><th>Date</th><th>Lock</th><th>Likes</th><th>Views</th></tr></thead><tbody>';
		foreach ( $recent as $p ) {
			$img   = get_the_post_thumbnail_url($p->ID,'thumbnail') ?: get_post_meta($p->ID,'_shat_image_url',true);
			$cap   = esc_html( get_post_meta($p->ID,'_shat_caption',true) ?: $p->post_title );
			$blr   = shat_is_blurred($p->ID);
			$lk    = shat_photo_likes($p->ID);
			$vw    = shat_photo_views($p->ID);
			echo '<tr>';
			echo '<td>' . ($img ? '<img src="' . esc_url($img) . '" class="shat-thumb' . ($blr?' blurred':'') . '">' : '<span style="color:#CCC;">—</span>') . '</td>';
			echo '<td><strong>' . $cap . '</strong></td>';
			echo '<td style="color:#999;white-space:nowrap;">' . get_the_date('M j, Y',$p->ID) . '</td>';
			echo '<td>' . ($blr ? '<span class="shat-badge sb-locked">🔒 Locked</span>' : '<span style="color:#CCC;">—</span>') . '</td>';
			echo '<td><span style="color:#E53935;font-weight:500;">&#9829; ' . $lk . '</span></td>';
			echo '<td><span style="color:#888;">&#128065; ' . $vw . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
	echo '</div>';
}

function shat_admin_stat( $label, $num, $icon = '', $gold = false ) {
	echo '<div class="shat-sc">';
	echo '<div class="n ' . ($gold?'gold':'') . '">' . esc_html($num) . '</div>';
	echo '<div class="l">' . esc_html($icon.' '.$label) . '</div>';
	echo '</div>';
}

// ============================================================
// ADMIN: UPLOAD PHOTO
// ============================================================
function shat_admin_upload() {
	shat_admin_css();
	$msg = '';

	if ( isset($_POST['shat_upload_nonce']) && wp_verify_nonce($_POST['shat_upload_nonce'],'shat_do_upload') ) {
		$caption   = sanitize_text_field( wp_unslash( $_POST['caption']    ?? '' ) );
		$desc      = sanitize_textarea_field( wp_unslash( $_POST['desc']   ?? '' ) );
		$dt_raw    = sanitize_text_field( $_POST['photo_date'] ?? date('Y-m-d') );

		/* NEW 1.4.0 — multi-image (comma-separated attachment IDs) */
		$ids_raw   = sanitize_text_field( $_POST['image_ids']  ?? '' );
		$gallery   = array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) );

		/* Legacy single-image fallback */
		$img_id    = intval( $_POST['image_id']                ?? 0 );
		$img_url   = esc_url_raw( $_POST['image_url']          ?? '' );

		$send_em   = isset( $_POST['send_email'] );
		$recips    = array_map('sanitize_email', (array)($_POST['recipients'] ?? []));
		$is_blurred_post = isset($_POST['is_blurred']) && $_POST['is_blurred'] === '1';
		$passcode  = sanitize_text_field( $_POST['blur_passcode'] ?? '' );
		$is_noindex_post = isset($_POST['is_noindex']) && $_POST['is_noindex'] === '1';

		/* NEW 2.3.0 — audience targeting + Patreon exclusive */
		$audience = sanitize_key( $_POST['audience'] ?? 'all' );
		if ( ! in_array( $audience, [ 'all', 'selected' ], true ) ) $audience = 'all';
		$audience_ids = array_values( array_unique( array_filter(
			array_map( 'intval', (array) ( $_POST['audience_ids'] ?? [] ) )
		) ) );
		$is_patreon_post = isset( $_POST['is_patreon'] ) && $_POST['is_patreon'] === '1';

		/* Validate gallery attachments are allowed image MIME types */
		$allowed = array_values( shat_allowed_image_mimes() );
		foreach ( $gallery as $k => $aid ) {
			$mime = get_post_mime_type( $aid );
			if ( ! in_array( $mime, $allowed, true ) ) unset( $gallery[ $k ] );
		}
		$gallery = array_values( $gallery );

		/* Validation */
		if ( empty( $gallery ) && ! $img_id && ! $img_url ) {
			$msg = '<div class="shat-notice err">Please select at least one photo (JPG, PNG, or WEBP).</div>';
		} elseif ( $is_blurred_post && ! $is_patreon_post && $passcode === '' ) {
			$msg = '<div class="shat-notice err">A passcode is required for blurred photos.</div>';
		} elseif ( $audience === 'selected' && empty( $audience_ids ) ) {
			$msg = '<div class="shat-notice err">Pick at least one member, or choose &ldquo;Everyone&rdquo;.</div>';
		} else {
			$pid = wp_insert_post([
				'post_title'  => $caption ?: 'Photo ' . date('Y-m-d'),
				'post_type'   => 'shat_photo',
				'post_status' => 'publish',
				'post_date'   => date('Y-m-d H:i:s', strtotime($dt_raw)),
			]);
			if ( $pid && ! is_wp_error($pid) ) {
				update_post_meta( $pid, '_shat_caption',     $caption );
				update_post_meta( $pid, '_shat_description', $desc    );
				update_post_meta( $pid, '_shat_date',        date('F j, Y', strtotime($dt_raw)) );

				/* NEW 2.3.0 — audience */
				update_post_meta( $pid, '_shat_audience', $audience );
				if ( $audience === 'selected' ) {
					update_post_meta( $pid, '_shat_audience_ids', $audience_ids );
				} else {
					delete_post_meta( $pid, '_shat_audience_ids' );
				}

				if ( ! empty( $gallery ) ) {
					update_post_meta( $pid, '_shat_gallery_ids', $gallery );
					set_post_thumbnail( $pid, $gallery[0] ); // first image = cover
				} else {
					if ( $img_id  ) set_post_thumbnail( $pid, $img_id );
					if ( $img_url ) update_post_meta( $pid, '_shat_image_url', $img_url );
				}

				/* Blur meta */
				if ( $is_blurred_post ) {
					update_post_meta( $pid, '_shat_blurred',  '1' );
					update_post_meta( $pid, '_shat_passcode', $passcode );
				}
				/* NEW 2.3.0 — Patreon exclusive. Implies no-index: an
				   exclusive should never be crawlable. No passcode is
				   stored, because there's no passcode path in or out. */
				if ( $is_patreon_post ) {
					update_post_meta( $pid, '_shat_patreon', '1' );
					update_post_meta( $pid, '_shat_noindex', '1' );
					$is_noindex_post = true;
				}
				/* No-index meta — "Exclusive" photos kept out of Google. */
				if ( $is_noindex_post ) {
					update_post_meta( $pid, '_shat_noindex', '1' );
				}
				shat_clear_noindex_cache();
				/* 2.3.0 — never email a post to someone it isn't shared with. */
				if ( $send_em && ! empty( $recips ) && $audience === 'selected' ) {
					$allowed_emails = [];
					foreach ( $audience_ids as $aud_uid ) {
						$aud_user = get_userdata( $aud_uid );
						if ( $aud_user ) $allowed_emails[] = $aud_user->user_email;
					}
					$recips = array_values( array_intersect( $recips, $allowed_emails ) );
				}
				if ( $send_em && ! empty($recips) ) {
					/* EVERY notification email uses the blurred preview,
					   regardless of audience or lock state. The email is a
					   teaser only — the real photo is never sent by mail,
					   so a forwarded email can never leak an image.

					   If the blur cannot be generated (GD missing, odd
					   format), send NO image at all rather than falling
					   back to the real one. */
					$thumb = shat_get_post_blur_preview_url( $pid ) ?: '';
					shat_notify_subscribers( $pid, $caption, $thumb, $recips, $is_blurred_post || $is_patreon_post );
				}

				/* NEW — push notification for a new post. Only fires if the
				   admin ticked the box. Goes to all approved members with the
				   app installed. Kept deliberately vague in the body so it
				   works for blurred/exclusive posts too (no image leaked). */
				if ( isset( $_POST['send_push'] ) && function_exists( 'schat_fcm_broadcast_to_approved' ) ) {
					$push_title = 'Shatania posted something new';
					$push_body  = $caption !== '' ? wp_trim_words( $caption, 12, '…' ) : 'Open the app to see the latest post 💅';
					schat_fcm_broadcast_to_approved( $push_title, $push_body, [
						'type'      => 'post',
						'click_url' => shat_page_url( 'shatania-feed' ) ?: home_url( '/' ),
					] );
				}

				$msg = '<div class="shat-notice ok">&#10003; Photo published successfully! <a href="' . admin_url('admin.php?page=shatania-photos') . '" style="color:var(--gold);">View all photos &rarr;</a></div>';
			} else {
				$msg = '<div class="shat-notice err">Something went wrong. Please try again.</div>';
			}
		}
	}

	$subs = shat_get_approved_users();

	echo '<div class="shat-a-wrap">';
	echo '<h1>Upload New Photo</h1><p class="sub">Add a new image to the feed</p>';
	echo $msg;
	echo '<div class="shat-box" style="max-width:660px;">';
	echo '<form method="post" id="shat-upl-form">';
	wp_nonce_field( 'shat_do_upload', 'shat_upload_nonce' );

	echo '<div class="shat-fr"><label>Caption <span style="color:#E53935;">*</span></label><input type="text" name="caption" placeholder="Add a beautiful caption..." required></div>';
	echo '<div class="shat-fr"><label>Description</label><textarea name="desc" placeholder="Share more about this photo..."></textarea></div>';
	echo '<div class="shat-fr" style="max-width:220px;"><label>Date</label><input type="date" name="photo_date" value="' . date('Y-m-d') . '"></div>';

	/* Photo selector — multi-image */
	echo '<div class="shat-fr">';
	echo '<label>Photos <span style="color:#999;font-weight:400;">(select one or many)</span></label>';
	echo '<input type="hidden" name="image_ids" id="supl-img-ids">';
	echo '<button type="button" class="ab pr" onclick="shatMedia()" style="margin-bottom:10px;">&#128247; Select / Add Photos</button>';
	echo '<div id="supl-preview" style="display:flex;flex-wrap:wrap;gap:8px;"></div>';
	echo '</div>';

	/* ---- AUDIENCE (NEW 2.3.0) — who can see this post at all ---- */
	echo '<div class="shat-fr">';
	echo '<label>Share With</label>';
	echo '<div style="display:flex;flex-direction:column;gap:9px;">';

	echo '<label style="display:flex;align-items:center;gap:9px;font-size:14px;color:var(--text);font-weight:400;cursor:pointer;">';
	echo '<input type="radio" name="audience" value="all" checked onchange="shatAud()"> ';
	echo '<span><strong>Everyone</strong> <span style="color:#999;">&mdash; all approved members</span></span></label>';

	echo '<label style="display:flex;align-items:center;gap:9px;font-size:14px;color:var(--text);font-weight:400;cursor:pointer;">';
	echo '<input type="radio" name="audience" value="selected" onchange="shatAud()"> ';
	echo '<span><strong>Only selected members</strong> <span style="color:#999;">&mdash; hidden from everyone else</span></span></label>';
	echo '</div>';

	echo '<div id="shat-aud-list" style="display:none;margin-top:12px;padding:12px;border:1px solid #eee;border-radius:10px;max-height:240px;overflow-y:auto;background:#fafafa;">';
	if ( empty( $subs ) ) {
		echo '<p style="color:#999;margin:0;font-size:13px;">No approved members yet.</p>';
	} else {
		echo '<div style="margin-bottom:10px;"><label style="font-size:13px;display:inline-flex;align-items:center;gap:7px;cursor:pointer;">';
		echo '<input type="checkbox" onchange="shatAudAll(this)"> Select / Deselect All</label></div>';
		foreach ( $subs as $su ) {
			echo '<label style="display:flex;align-items:center;gap:9px;padding:6px 2px;font-size:13px;font-weight:400;cursor:pointer;">';
			echo '<input type="checkbox" class="shat-aud-cb" name="audience_ids[]" value="' . intval( $su->ID ) . '"> ';
			echo '<strong style="flex:1;">' . esc_html( $su->display_name ) . '</strong>';
			echo '<span style="color:#999;font-size:12px;">' . esc_html( $su->user_email ) . '</span>';
			echo '</label>';
		}
	}
	echo '</div>';
	echo '</div>';

	/* ---- PATREON EXCLUSIVE (NEW 2.3.0) ---- */
	echo '<div class="shat-fr">';
	echo '<label>Patreon</label>';
	echo '<div class="shat-blur-toggle-wrap" id="shat-patreon-wrap">';
	echo '<label class="shat-blur-toggle-label">';
	echo '<input type="checkbox" name="is_patreon" id="shat-patreon-chk" value="1" onchange="shatPatToggle(this)">';
	echo '⭐ Patreon exclusive &mdash; locked for everyone, with a &ldquo;Visit Patreon&rdquo; button';
	echo '</label>';
	echo '<p class="shat-passcode-hint" id="shat-patreon-hint" style="display:none;">Members see the blurred cover, an &ldquo;Exclusive&rdquo; badge, and a button linking to <code>' . esc_html( SHAT_PATREON_GO_URL ) . '</code>. Passcode unlock is disabled for exclusives, so an old passcode can never open one. This also forces no-index automatically.</p>';
	echo '</div>';
	echo '</div>';

	/* ---- BLUR TOGGLE (new) ---- */
	echo '<div class="shat-fr">';
	echo '<label>Privacy / Blur</label>';
	echo '<div class="shat-blur-toggle-wrap" id="shat-blur-wrap">';
	echo '<label class="shat-blur-toggle-label">';
	echo '<input type="checkbox" name="is_blurred" id="shat-blur-chk" value="1" onchange="shatBlurToggle(this)">';
	echo '🔒 Blur this photo (members need a passcode to unlock)';
	echo '</label>';
	echo '<div id="shat-passcode-field" style="display:none;margin-top:14px;">';
	echo '<label style="font-size:11px;color:var(--sec);text-transform:uppercase;letter-spacing:.9px;display:block;margin-bottom:7px;font-weight:500;">Passcode for this photo <span style="color:#E53935;">*</span></label>';
	echo '<input type="text" name="blur_passcode" id="shat-blur-passcode" placeholder="e.g. SunsetGold2024" style="max-width:280px;font-family:monospace;letter-spacing:1px;" autocomplete="off">';
	echo '<p class="shat-passcode-hint">Share this passcode privately with members you want to grant access to. Each member who enters it will permanently unlock this photo.</p>';
	echo '</div>';
	echo '</div>';
	echo '</div>';

	/* ---- NO-INDEX TOGGLE (Exclusive — hide from Google) ---- */
	echo '<div class="shat-fr">';
	echo '<label>Search Engine Visibility</label>';
	echo '<div class="shat-blur-toggle-wrap active" id="shat-noindex-wrap">';
	echo '<label class="shat-blur-toggle-label">';
	echo '<input type="checkbox" name="is_noindex" id="shat-noindex-chk" value="1" checked onchange="document.getElementById(\'shat-noindex-wrap\').classList.toggle(\'active\',this.checked)">';
	echo '🚫 Exclusive photo — hide from Google (noindex)';
	echo '</label>';
	echo '<p class="shat-passcode-hint">Keeps this photo out of Google Search and Google Images. Leave checked for exclusive content — uncheck only for photos you\'re fine with search engines finding.</p>';
	echo '</div>';
	echo '</div>';

	/* Email notifications */
	if ( ! empty($subs) ) {
		echo '<div class="shat-fr">';
		echo '<label>Email Notification</label>';
		echo '<label style="font-size:14px;color:var(--text);display:inline-flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:12px;">';
		echo '<input type="checkbox" name="send_email" id="shat-send-chk" onchange="document.getElementById(\'shat-subbox\').style.display=this.checked?\'block\':\'none\'">';
		echo 'Send new photo notification to subscribers</label>';
		echo '<div id="shat-subbox" style="display:none;">';
		echo '<div style="margin-bottom:8px;"><label style="font-size:13px;display:inline-flex;align-items:center;gap:7px;cursor:pointer;"><input type="checkbox" onchange="shatAllRecip(this)"> Select / Deselect All</label></div>';
		echo '<div class="shat-sublist">';
		foreach ( $subs as $su ) {
			echo '<div class="shat-si"><input type="checkbox" name="recipients[]" value="' . esc_attr($su->user_email) . '" checked>';
			echo '<strong style="flex:1;">' . esc_html($su->display_name) . '</strong>';
			echo '<span style="color:#999;font-size:12px;">' . esc_html($su->user_email) . '</span></div>';
		}
		echo '</div></div></div>';
	}

	/* Push notification (new-post alert to the app) */
	echo '<div class="shat-fr">';
	echo '<label>Push Notification</label>';
	echo '<label style="font-size:14px;color:var(--text);display:inline-flex;align-items:center;gap:8px;cursor:pointer;">';
	echo '<input type="checkbox" name="send_push" value="1" checked>';
	echo 'Send a new-post alert to everyone with the app</label>';
	echo '<p style="font-size:12.5px;color:#999;margin:6px 0 0;">Notifies all approved members who have the app installed. Works for blurred/exclusive posts too — no image is included in the alert.</p>';
	echo '</div>';

	echo '<button type="submit" class="ab pr" style="width:100%;text-align:center;padding:14px;font-size:15px;margin-top:6px;">Publish Photo</button>';
	echo '</form></div></div>';

	echo '<script>
	var shatPicked = [];
	function shatRenderPicked(){
	  var box = document.getElementById("supl-preview"); box.innerHTML = "";
	  shatPicked.forEach(function(it, i){
	    var w = document.createElement("div");
	    w.style.cssText = "position:relative;width:90px;height:90px;border-radius:10px;overflow:hidden;border:1px solid rgba(184,155,94,.3);";
	    w.innerHTML = "<img src=\""+it.url+"\" style=\"width:100%;height:100%;object-fit:cover;display:block;\">" +
	      "<button type=\"button\" onclick=\"shatPicked.splice("+i+",1);shatRenderPicked();\" style=\"position:absolute;top:3px;right:3px;background:rgba(0,0,0,.75);color:#fff;border:0;border-radius:50%;width:22px;height:22px;line-height:20px;cursor:pointer;font-size:14px;\">&times;</button>";
	    box.appendChild(w);
	  });
	  document.getElementById("supl-img-ids").value = shatPicked.map(function(x){return x.id;}).join(",");
	}
	function shatMedia(){
	  var fr = wp.media({title:"Select Photos",multiple:"add",library:{type:["image/jpeg","image/png","image/webp"]},button:{text:"Use These Photos"}});
	  fr.on("select",function(){
	    fr.state().get("selection").toJSON().forEach(function(a){
	      if (!shatPicked.some(function(p){return p.id===a.id;})) shatPicked.push({id:a.id,url:a.url});
	    });
	    shatRenderPicked();
	  });
	  fr.open();
	}
	function shatBlurToggle(cb){
	  var pf=document.getElementById("shat-passcode-field");
	  var wrap=document.getElementById("shat-blur-wrap");
	  pf.style.display=cb.checked?"block":"none";
	  wrap.classList.toggle("active",cb.checked);
	  var pat=document.getElementById("shat-patreon-chk");
	  if(cb.checked && pat && pat.checked){ pat.checked=false; shatPatToggle(pat); }
	  if(cb.checked) document.getElementById("shat-blur-passcode").focus();
	}
	function shatAllRecip(cb){document.querySelectorAll("input[name=\'recipients[]\']").forEach(function(c){c.checked=cb.checked;});}

	/* --- Audience + Patreon (2.3.0) --- */
	function shatAud(){
	  var sel = document.querySelector("input[name=\'audience\']:checked").value;
	  document.getElementById("shat-aud-list").style.display = (sel === "selected") ? "block" : "none";
	  shatSyncRecipients();
	}
	function shatAudAll(cb){
	  document.querySelectorAll(".shat-aud-cb").forEach(function(c){ c.checked = cb.checked; });
	  shatSyncRecipients();
	}
	/* When the audience is restricted, grey out email recipients who
	   are not in it so you cannot accidentally notify the wrong person.
	   The server enforces this too. */
	function shatSyncRecipients(){
	  var radio = document.querySelector("input[name=\'audience\']:checked");
	  if(!radio) return;
	  var restricted = radio.value === "selected";
	  var allowed = [];
	  document.querySelectorAll(".shat-aud-cb").forEach(function(c){
	    if(c.checked){ var lb=c.closest("label"); var em=lb?lb.querySelector("span:last-child"):null; if(em) allowed.push(em.textContent.trim()); }
	  });
	  document.querySelectorAll("input[name=\'recipients[]\']").forEach(function(c){
	    var ok = !restricted || allowed.indexOf(c.value) !== -1;
	    c.disabled = !ok;
	    if(!ok) c.checked = false;
	    var row = c.closest(".shat-si");
	    if(row) row.style.opacity = ok ? "1" : ".4";
	  });
	}
	document.addEventListener("change", function(e){
	  if(e.target && e.target.classList && e.target.classList.contains("shat-aud-cb")) shatSyncRecipients();
	});
	function shatPatToggle(cb){
	  document.getElementById("shat-patreon-hint").style.display = cb.checked ? "block" : "none";
	  document.getElementById("shat-patreon-wrap").classList.toggle("active", cb.checked);
	  /* Patreon and passcode-blur are mutually exclusive — an exclusive
	     has no passcode path, so leaving both on would be misleading. */
	  var blur = document.getElementById("shat-blur-chk");
	  if(cb.checked && blur && blur.checked){ blur.checked = false; shatBlurToggle(blur); }
	  if(cb.checked){
	    var ni = document.getElementById("shat-noindex-chk");
	    if(ni && !ni.checked){ ni.checked = true; ni.dispatchEvent(new Event("change")); }
	  }
	}
	</script>';
}

/* Subscriber notification — note blur in email if applicable */
/* $img_url is ALWAYS a server-side blurred preview — see the caller.
   No email ever carries the real photo, whoever it is addressed to, so a
   forwarded or leaked email cannot expose an image.

   $is_blurred here means only that the post is ALSO passcode-locked or
   Patreon-exclusive in the feed; it controls the padlock note, not
   whether the image is hidden.

   Note on technique: CSS filters (filter:blur(...)) are stripped by
   Gmail, Outlook and most other clients, so a sharp JPEG sent with a
   blur style would arrive fully visible. The pixels themselves must
   already be blurred — see shat_generate_blurred_preview(). No CSS blur
   is used here at all. */
function shat_notify_subscribers( $pid, $caption, $img_url, $emails, $is_blurred = false ) {
	$feed = shat_page_url('shatania-feed');

	$blur_note = $is_blurred ? '<p style="background:#FBF0F1;border-radius:8px;padding:10px 14px;font-size:13px;color:#8B4F53;margin-bottom:16px;">🔒 This photo is locked — you\'ll need a passcode to unlock it in the feed.</p>' : '';

	if ( $img_url ) {
		/* Always shown: the image in the mail is always a blurred teaser. */
		$caption_line = '<p style="text-align:center;font-size:12px;color:#999;margin-top:10px;">Preview blurred &mdash; view on ShataniaVeyra.com to see the full photo</p>';
		$img_block = '<div style="border-radius:12px;overflow:hidden;">'
			. '<img src="' . esc_url( $img_url ) . '" alt="New photo preview" '
			. 'style="width:100%;max-width:560px;height:auto;display:block;border-radius:12px;">'
			. '</div>' . $caption_line;
	} else {
		$img_block = '';
	}

	foreach ( $emails as $em ) {
		if ( ! is_email($em) ) continue;
		shat_mail( $em, 'New photo on Shatania ✨',
			shat_email_wrap( 'A new photo just dropped ✨',
				'<p>A beautiful new photo has been added to the collection.</p>'
				. $blur_note
				. $img_block
				. ( $caption ? '<p style="font-size:17px;color:#1F1F1F;font-style:italic;">&ldquo;' . esc_html($caption) . '&rdquo;</p>' : '' )
				. '<p><a href="' . esc_url($feed) . '" class="btn">View on ShataniaVeyra.com &rarr;</a></p>'
				. '<p style="color:#999;font-size:12px;margin-top:16px;">You\'re receiving this because you\'re an approved member of Shatania.</p>'
			)
		);
	}
}

// ============================================================
// ADMIN: CHAT STYLE (wallpaper behind the message thread)
// ============================================================
function shat_admin_chatstyle() {
	shat_admin_css();
	wp_enqueue_media();
	$msg = '';

	if ( isset( $_POST['shat_cw_nonce'] ) && wp_verify_nonce( $_POST['shat_cw_nonce'], 'shat_do_chatstyle' ) ) {
		$data = [
			'enabled'    => isset( $_POST['cw_enabled'] ) ? 1 : 0,
			'id'         => intval( $_POST['cw_img_id'] ?? 0 ),
			'url'        => esc_url_raw( wp_unslash( $_POST['cw_img_url'] ?? '' ) ),
			'visibility' => max( 0, min( 100, intval( $_POST['cw_visibility'] ?? 35 ) ) ),
		];
		update_option( 'shat_chat_wallpaper', $data );
		update_option( 'shat_chat_polish', isset( $_POST['cw_polish'] ) ? '1' : '0' );
		$msg = '<div class="notice notice-success"><p>Chat wallpaper saved.</p></div>';
	}

	$cw  = get_option( 'shat_chat_wallpaper', [] );
	$on  = ! empty( $cw['enabled'] );
	$url = $cw['url'] ?? '';
	$id  = intval( $cw['id'] ?? 0 );
	$vis = isset( $cw['visibility'] ) ? intval( $cw['visibility'] ) : 35;
	$pol = schat_polish_enabled();

	echo '<div class="wrap"><h1>Chat Style</h1>' . $msg;

	echo '<form method="post" style="max-width:640px;margin-top:18px;">';
	wp_nonce_field( 'shat_do_chatstyle', 'shat_cw_nonce' );

	echo '<table class="form-table"><tbody>';

	echo '<tr><th scope="row">Wallpaper</th><td>';
	echo '<label><input type="checkbox" name="cw_enabled" value="1" ' . checked( $on, true, false ) . '> Show a wallpaper behind chat messages</label>';
	echo '</td></tr>';

	echo '<tr><th scope="row">Polished look</th><td>';
	echo '<label><input type="checkbox" name="cw_polish" value="1" ' . checked( $pol, true, false ) . '> Use the refined chat styling</label>';
	echo '<p class="description" style="margin-top:8px;">'
		. 'Softer shadows instead of the pink glow around your own messages, a readable quote block when you reply, '
		. 'and gentler tap and arrival animations.<br>'
		. '<strong>Colours, fonts and spacing are exactly the same either way.</strong> '
		. 'Untick this to go straight back to the previous look.'
		. '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">Image</th><td>';
	echo '<input type="hidden" id="cw-img-id"  name="cw_img_id"  value="' . esc_attr( $id ) . '">';
	echo '<input type="hidden" id="cw-img-url" name="cw_img_url" value="' . esc_attr( $url ) . '">';
	echo '<div id="cw-img-preview" style="' . ( $url ? '' : 'display:none;' ) . 'margin-bottom:10px;">';
	echo '<img src="' . esc_url( $url ) . '" style="width:120px;height:213px;object-fit:cover;border-radius:10px;border:1px solid #ddd;display:block;">';
	echo '</div>';
	echo '<button type="button" class="button" id="cw-img-btn">Choose image</button> ';
	echo '<button type="button" class="button" id="cw-img-remove" style="' . ( $url ? '' : 'display:none;' ) . '">Remove</button>';
	echo '<p class="description" style="margin-top:10px;">'
		. '<strong>Recommended: 1080 &times; 1920 px (9:16 portrait).</strong><br>'
		. 'The chat panel is a tall phone-shaped column, so a 9:16 image fills it with no cropping. '
		. 'Anything landscape will be centre-cropped hard and lose its sides.<br>'
		. 'Keep the file under 500 KB (JPG is best) so the thread still opens instantly on mobile data. '
		. 'Soft, low-contrast images work far better than busy ones, since message bubbles sit on top.'
		. '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">Visibility</th><td>';
	echo '<input type="range" id="cw-vis" name="cw_visibility" min="0" max="100" step="5" value="' . esc_attr( $vis ) . '" style="width:280px;vertical-align:middle;"> ';
	echo '<output id="cw-vis-out" style="font-weight:600;">' . esc_html( $vis ) . '%</output>';
	echo '<p class="description">How strongly the image shows through. Lower means a darker veil over it and crisper text. '
		. '30&ndash;45% is the sweet spot for most photos.</p>';
	echo '</td></tr>';

	echo '</tbody></table>';
	submit_button( 'Save wallpaper' );
	echo '</form>';

	echo '<script>
	(function(){
		var frame;
		var btn = document.getElementById("cw-img-btn");
		var rm  = document.getElementById("cw-img-remove");
		var vis = document.getElementById("cw-vis");
		var out = document.getElementById("cw-vis-out");

		if (vis && out) vis.addEventListener("input", function(){ out.textContent = vis.value + "%"; });

		if (btn) btn.addEventListener("click", function(e){
			e.preventDefault();
			if (frame) { frame.open(); return; }
			frame = wp.media({ title:"Choose a chat wallpaper", library:{ type:"image" }, button:{ text:"Use this image" }, multiple:false });
			frame.on("select", function(){
				var a = frame.state().get("selection").first().toJSON();
				document.getElementById("cw-img-id").value  = a.id;
				document.getElementById("cw-img-url").value = a.url;
				var prev = document.getElementById("cw-img-preview");
				prev.querySelector("img").src = a.url;
				prev.style.display = "";
				document.getElementById("cw-img-remove").style.display = "";
			});
			frame.open();
		});

		if (rm) rm.addEventListener("click", function(e){
			e.preventDefault();
			document.getElementById("cw-img-id").value  = "";
			document.getElementById("cw-img-url").value = "";
			document.getElementById("cw-img-preview").style.display = "none";
			this.style.display = "none";
		});
	})();
	</script>';

	echo '</div>';
}


// ============================================================
// ADMIN: EMAIL CHECK (is outgoing mail actually working?)
// ============================================================
function shat_admin_mail() {
	shat_admin_css();
	$msg = '';

	if ( isset( $_POST['shat_mailtest_nonce'] ) && wp_verify_nonce( $_POST['shat_mailtest_nonce'], 'shat_do_mailtest' ) ) {
		$to = sanitize_email( wp_unslash( $_POST['test_to'] ?? '' ) );
		if ( ! is_email( $to ) ) {
			$msg = '<div class="notice notice-error"><p>That does not look like a valid email address.</p></div>';
		} else {
			delete_option( 'shat_last_mail_error' );
			$body = '<p style="font-size:15px;color:#333;line-height:1.6;">This is a test from your Shatania site.</p>'
				. '<p style="font-size:14px;color:#444;line-height:1.7;">If you are reading this, outgoing email is working and sign-in codes will reach members.</p>'
				. '<div style="font-size:26px;letter-spacing:8px;font-weight:600;color:#1f1f1f;background:#FBF0F1;border:1px solid #E8C4C6;border-radius:12px;padding:16px;text-align:center;margin:18px 0;">123456</div>';
			$sent = shat_mail( $to, 'Shatania email test', shat_email_wrap( 'Email test', $body ) );

			if ( $sent ) {
				$msg = '<div class="notice notice-success"><p><strong>WordPress accepted the message.</strong> '
					. 'Check the inbox for ' . esc_html( $to ) . ', including the spam folder. '
					. 'If it never arrives, the mail left WordPress but was rejected or filtered further down the line — see the notes below.</p></div>';
			} else {
				$msg = '<div class="notice notice-error"><p><strong>Sending failed immediately.</strong> '
					. 'WordPress could not hand the message off at all. The reason is shown below.</p></div>';
			}
		}
	}

	$last = get_option( 'shat_last_mail_error', [] );
	$admin_email = get_option( 'admin_email' );
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$from_host = substr( strrchr( SHAT_FROM_EMAIL, '@' ), 1 );

	echo '<div class="wrap"><h1>Email Check</h1>' . $msg;

	echo '<p style="max-width:720px;">Sign-in codes, approval notices and chat alerts all go out through WordPress. '
		. 'If members are not receiving their code, this page tells you whether the problem is WordPress, or what happens to the mail afterwards.</p>';

	echo '<form method="post" style="margin:18px 0 26px;">';
	wp_nonce_field( 'shat_do_mailtest', 'shat_mailtest_nonce' );
	echo '<input type="email" name="test_to" required placeholder="you@example.com" value="' . esc_attr( $admin_email ) . '" style="min-width:280px;padding:6px 10px;"> ';
	submit_button( 'Send test email', 'primary', 'submit', false );
	echo '</form>';

	echo '<h2>Current configuration</h2>';
	echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
	echo '<tr><td style="width:200px;"><strong>Sends as</strong></td><td>' . esc_html( SHAT_FROM_NAME . ' <' . SHAT_FROM_EMAIL . '>' ) . '</td></tr>';
	echo '<tr><td><strong>Site domain</strong></td><td>' . esc_html( $site_host ) . '</td></tr>';
	echo '<tr><td><strong>From domain</strong></td><td>' . esc_html( $from_host );
	if ( $from_host && $site_host && stripos( $site_host, $from_host ) === false && stripos( $from_host, preg_replace( '/^www\./', '', $site_host ) ) === false ) {
		echo ' <span style="color:#b32d2e;">&larr; does not match the site domain, which will fail SPF at most providers</span>';
	}
	echo '</td></tr>';
	echo '<tr><td><strong>Delivery method</strong></td><td>';
	if ( ! empty( $smtp_live['enabled'] ) && ! empty( $smtp_live['host'] ) ) {
		echo '<span style="color:#1a7f37;">SMTP &mdash; ' . esc_html( $smtp_live['host'] . ':' . $smtp_live['port'] )
			. ' (' . esc_html( strtoupper( $smtp_live['secure'] ) ) . ')</span>';
		if ( $by_const ) echo ' <em style="color:#666;">set in wp-config.php</em>';
	} else {
		echo '<span style="color:#b32d2e;">PHP mail() &mdash; unauthenticated, and routinely discarded by Gmail, Outlook and Apple Mail. Configure SMTP below.</span>';
	}
	echo '</td></tr>';
	echo '</tbody></table>';

	// ---- SMTP settings ----
	echo '<h2 style="margin-top:26px;">SMTP settings</h2>';

	if ( $by_const ) {
		echo '<p style="max-width:720px;">SMTP is configured with constants in <code>wp-config.php</code>, which take priority over anything saved here.</p>';
	}

	echo '<form method="post" style="max-width:720px;">';
	wp_nonce_field( 'shat_do_smtp', 'shat_smtp_nonce' );
	echo '<table class="form-table"><tbody>';

	echo '<tr><th scope="row">Use SMTP</th><td><label><input type="checkbox" name="smtp_enabled" value="1" '
		. checked( ! empty( $smtp['enabled'] ), true, false ) . '> Send through an SMTP server instead of PHP mail()</label></td></tr>';

	echo '<tr><th scope="row">Host</th><td><input type="text" name="smtp_host" value="'
		. esc_attr( $smtp['host'] ?? '' ) . '" placeholder="smtp.example.com" style="min-width:320px;"></td></tr>';

	echo '<tr><th scope="row">Port</th><td><input type="number" name="smtp_port" value="'
		. esc_attr( $smtp['port'] ?? 587 ) . '" style="width:110px;"> <span class="description">587 for TLS, 465 for SSL</span></td></tr>';

	$sec = $smtp['secure'] ?? 'tls';
	echo '<tr><th scope="row">Encryption</th><td><select name="smtp_secure">';
	foreach ( [ 'tls' => 'TLS (STARTTLS, port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None' ] as $k => $lab ) {
		echo '<option value="' . esc_attr( $k ) . '" ' . selected( $sec, $k, false ) . '>' . esc_html( $lab ) . '</option>';
	}
	echo '</select></td></tr>';

	echo '<tr><th scope="row">Username</th><td><input type="text" name="smtp_user" value="'
		. esc_attr( $smtp['user'] ?? '' ) . '" placeholder="hi@shataniaveyra.com" style="min-width:320px;" autocomplete="off"></td></tr>';

	echo '<tr><th scope="row">Password</th><td><input type="password" name="smtp_pass" value="" placeholder="'
		. ( ! empty( $smtp['pass'] ) ? 'saved &mdash; leave blank to keep it' : 'SMTP password or API key' )
		. '" style="min-width:320px;" autocomplete="new-password">'
		. '<p class="description">Stored in the database. To keep it out of the database entirely, define '
		. '<code>SHAT_SMTP_HOST</code>, <code>SHAT_SMTP_PORT</code>, <code>SHAT_SMTP_SECURE</code>, '
		. '<code>SHAT_SMTP_USER</code> and <code>SHAT_SMTP_PASS</code> in <code>wp-config.php</code> instead.</p></td></tr>';

	echo '</tbody></table>';
	submit_button( 'Save SMTP settings' );
	echo '</form>';

	echo '<h2 style="margin-top:26px;">Last recorded error</h2>';
	if ( ! empty( $last['msg'] ) ) {
		echo '<div style="max-width:720px;padding:14px 16px;background:#fff;border-left:4px solid #b32d2e;">'
			. '<p style="margin:0 0 6px;"><code>' . esc_html( $last['msg'] ) . '</code></p>'
			. '<p style="margin:0;color:#666;font-size:12px;">'
			. esc_html( human_time_diff( intval( $last['time'] ), time() ) ) . ' ago</p></div>';
	} else {
		echo '<p>No send has failed outright since this was last cleared.</p>';
	}

	echo '<h2 style="margin-top:26px;">If codes still are not arriving</h2>';
	echo '<div style="max-width:720px;">';
	echo '<p>Almost always this is deliverability, not the site. In order of likelihood:</p>';
	echo '<ol>';
	echo '<li><strong>SMTP not switched on.</strong> Fill in the settings above. This resolves the large majority of cases.</li>';
	echo '<li><strong>The From address is not authenticated for your domain.</strong> Mail claiming to be from <code>' . esc_html( SHAT_FROM_EMAIL ) . '</code> needs SPF and DKIM records on <code>' . esc_html( $from_host ) . '</code>, or receiving servers will treat it as forged.</li>';
	echo '<li><strong>It is arriving in spam.</strong> Check the spam folder of the account you are testing before assuming nothing was sent.</li>';
	echo '<li><strong>The host blocks outbound mail.</strong> Some shared hosts disable <code>mail()</code> entirely. The test above returning "sending failed immediately" points at this.</li>';
	echo '</ol>';
	echo '</div>';

	echo '</div>';
}


// ============================================================
// ADMIN: MANAGE PHOTOS
// ============================================================
function shat_admin_photos() {
	global $wpdb;
	shat_admin_css();

	/* DELETE */
	if ( isset($_GET['delete']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'shat_delete_' . intval($_GET['delete'])) ) {
		$pid = intval($_GET['delete']);
		if ( get_post_type($pid) === 'shat_photo' ) {
			wp_delete_post( $pid, true );
			$wpdb->delete( $wpdb->prefix . 'shat_likes',     [ 'photo_id' => $pid ] );
			$wpdb->delete( $wpdb->prefix . 'shat_views',     [ 'photo_id' => $pid ] );
			$wpdb->delete( $wpdb->prefix . 'shat_unlocked',  [ 'photo_id' => $pid ] );
			$wpdb->delete( $wpdb->prefix . 'shat_favorites', [ 'photo_id' => $pid ] );
			shat_clear_noindex_cache();
			echo '<div class="notice notice-success is-dismissible"><p>Photo deleted.</p></div>';
		}
	}

	/* UNBLUR */
	if ( isset($_GET['unblur']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'shat_unblur_' . intval($_GET['unblur'])) ) {
		$pid = intval($_GET['unblur']);
		if ( get_post_type($pid) === 'shat_photo' ) {
			delete_post_meta( $pid, '_shat_blurred' );
			delete_post_meta( $pid, '_shat_passcode' );
			/* Clear per-user unlocks — no longer needed. */
			$wpdb->delete( $wpdb->prefix . 'shat_unlocked', [ 'photo_id' => $pid ] );
			echo '<div class="notice notice-success is-dismissible"><p>Photo unblurred. It is now visible to all approved members.</p></div>';
		}
	}

	$all = get_posts([
		'post_type' => 'shat_photo', 'posts_per_page' => -1,
		'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC',
	]);

	echo '<div class="shat-a-wrap">';
	echo '<h1>Manage Photos</h1>';
	echo '<p class="sub">' . count($all) . ' photo(s) &middot; <a href="' . admin_url('admin.php?page=shatania-upload') . '" style="color:var(--gold);">Upload new &rarr;</a></p>';
	echo '<div class="shat-box">';
	if ( empty($all) ) {
		echo '<p style="color:#999;font-size:14px;text-align:center;padding:50px 0;">No photos yet. <a href="' . admin_url('admin.php?page=shatania-upload') . '" style="color:var(--gold);">Upload your first →</a></p>';
	} else {
		echo '<table class="shat-tbl"><thead><tr><th>Photo</th><th>Caption</th><th>Date</th><th>Lock / Passcode</th><th>Unlocks</th><th>Likes</th><th>Views</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $all as $p ) {
			$img      = get_the_post_thumbnail_url($p->ID,'thumbnail') ?: get_post_meta($p->ID,'_shat_image_url',true);
			$cap      = esc_html( get_post_meta($p->ID,'_shat_caption',true) ?: $p->post_title );
			$dt       = get_the_date('M j, Y',$p->ID);
			$blr      = shat_is_blurred($p->ID);
			$pc       = $blr ? (string) get_post_meta($p->ID,'_shat_passcode',true) : '';
			$unlocks  = $blr ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}shat_unlocked WHERE photo_id=%d",$p->ID)) : null;
			$lk       = shat_photo_likes($p->ID);
			$vw       = shat_photo_views($p->ID);
			$noidx    = shat_is_noindex($p->ID);

			/* Nonce URLs */
			$del_url    = wp_nonce_url( admin_url('admin.php?page=shatania-photos&delete=' . $p->ID), 'shat_delete_' . $p->ID );
			$unblur_url = wp_nonce_url( admin_url('admin.php?page=shatania-photos&unblur=' . $p->ID), 'shat_unblur_' . $p->ID );

			echo '<tr>';
			echo '<td>' . ($img ? '<img src="' . esc_url($img) . '" class="shat-thumb' . ($blr?' blurred':'') . '">' : '<span style="color:#CCC;">—</span>') . '</td>';
			/* 2.3.0 — audience + Patreon badges */
			$row_aud    = shat_get_audience( $p->ID );
			$row_audn   = count( shat_get_audience_ids( $p->ID ) );
			$row_patreon= shat_is_patreon( $p->ID );
			$badges     = '';
			if ( $row_patreon ) {
				$badges .= '<span class="shat-badge" style="background:#FF424D;color:#fff;margin-top:4px;margin-right:4px;display:inline-block;">⭐ Patreon</span>';
			}
			if ( $row_aud === 'selected' ) {
				$badges .= '<span class="shat-badge" style="background:#EDE9FF;color:#4B34C4;margin-top:4px;margin-right:4px;display:inline-block;">👤 ' . intval( $row_audn ) . ' member' . ( $row_audn === 1 ? '' : 's' ) . '</span>';
			}
			if ( $noidx ) {
				$badges .= '<span class="shat-badge" style="background:#F5E6E7;color:#8B4F53;margin-top:4px;display:inline-block;">🚫 No-index</span>';
			}
			echo '<td><strong>' . $cap . '</strong>' . ( $badges ? '<br>' . $badges : '' ) . '</td>';
			echo '<td style="color:#999;white-space:nowrap;">' . $dt . '</td>';

			/* Lock column */
			if ($blr) {
				echo '<td><span class="shat-badge sb-locked" style="display:inline-block;margin-bottom:5px;">🔒 Locked</span><br>';
				echo '<span style="font-size:11px;color:#888;">Code: <code class="shat-code">' . esc_html($pc) . '</code></span></td>';
			} else {
				echo '<td><span style="color:#CCC;font-size:13px;">—</span></td>';
			}

			/* Unlocks column */
			echo '<td>' . ($blr ? '<span style="color:#C08A8E;font-size:13px;">👥 ' . $unlocks . '</span>' : '<span style="color:#CCC;">—</span>') . '</td>';

			echo '<td><span style="color:#E53935;font-weight:500;">&#9829; ' . $lk . '</span></td>';
			echo '<td><span style="color:#888;">&#128065; ' . $vw . '</span></td>';
			echo '<td>';
			$edit_url = admin_url('admin.php?page=shatania-edit&photo=' . $p->ID);
			echo '<a href="' . esc_url($edit_url) . '" class="ab ap" style="margin-bottom:4px;display:inline-block;">✎ Edit</a><br>';
			if ($blr) {
				echo '<a href="' . esc_url($unblur_url) . '" class="ab unblur" style="margin-bottom:4px;display:inline-block;" onclick="return confirm(\'Remove blur and make visible to all?\')">🔓 Unblur</a><br>';
			}
			echo '<a href="' . esc_url($del_url) . '" class="ab dl" onclick="return confirm(\'Delete this photo permanently?\')">Delete</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div></div>';
}

// ============================================================
// ADMIN: EDIT A PUBLISHED PHOTO
// ============================================================
function shat_admin_edit() {
	global $wpdb;
	shat_admin_css();

	$pid = intval( $_GET['photo'] ?? 0 );
	if ( ! $pid || get_post_type( $pid ) !== 'shat_photo' ) {
		echo '<div class="shat-a-wrap"><h1>Edit Photo</h1><p class="sub">Photo not found. <a href="' . admin_url('admin.php?page=shatania-photos') . '" style="color:var(--gold);">Back to Manage Photos &rarr;</a></p></div>';
		return;
	}

	$msg = '';

	/* SAVE */
	if ( isset($_POST['shat_edit_nonce']) && wp_verify_nonce($_POST['shat_edit_nonce'], 'shat_do_edit_' . $pid) ) {
		$caption = sanitize_text_field( wp_unslash( $_POST['caption'] ?? '' ) );
		$desc    = sanitize_textarea_field( wp_unslash( $_POST['desc'] ?? '' ) );
		$dt_raw  = sanitize_text_field( $_POST['photo_date'] ?? date('Y-m-d') );

		$ids_raw = sanitize_text_field( $_POST['image_ids'] ?? '' );
		$gallery = array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) );

		/* Validate gallery attachments are allowed image MIME types */
		$allowed = array_values( shat_allowed_image_mimes() );
		foreach ( $gallery as $k => $aid ) {
			$mime = get_post_mime_type( $aid );
			if ( ! in_array( $mime, $allowed, true ) ) unset( $gallery[ $k ] );
		}
		$gallery = array_values( $gallery );

		$is_blurred_post = isset($_POST['is_blurred']) && $_POST['is_blurred'] === '1';
		$passcode = sanitize_text_field( $_POST['blur_passcode'] ?? '' );
		$is_noindex_post = isset($_POST['is_noindex']) && $_POST['is_noindex'] === '1';

		/* NEW 2.3.0 — audience targeting + Patreon exclusive */
		$audience = sanitize_key( $_POST['audience'] ?? 'all' );
		if ( ! in_array( $audience, [ 'all', 'selected' ], true ) ) $audience = 'all';
		$audience_ids = array_values( array_unique( array_filter(
			array_map( 'intval', (array) ( $_POST['audience_ids'] ?? [] ) )
		) ) );
		$is_patreon_post = isset( $_POST['is_patreon'] ) && $_POST['is_patreon'] === '1';

		if ( empty( $gallery ) ) {
			$msg = '<div class="shat-notice err">Please keep at least one photo.</div>';
		} elseif ( $is_blurred_post && ! $is_patreon_post && $passcode === '' ) {
			$msg = '<div class="shat-notice err">A passcode is required for blurred photos.</div>';
		} elseif ( $audience === 'selected' && empty( $audience_ids ) ) {
			$msg = '<div class="shat-notice err">Pick at least one member, or choose &ldquo;Everyone&rdquo;.</div>';
		} else {
			wp_update_post([
				'ID'         => $pid,
				'post_title' => $caption ?: 'Photo ' . date('Y-m-d'),
				'post_date'  => date('Y-m-d H:i:s', strtotime($dt_raw)),
				'post_date_gmt' => get_gmt_from_date( date('Y-m-d H:i:s', strtotime($dt_raw)) ),
			]);
			update_post_meta( $pid, '_shat_caption',     $caption );
			update_post_meta( $pid, '_shat_description', $desc    );
			update_post_meta( $pid, '_shat_date',        date('F j, Y', strtotime($dt_raw)) );

			update_post_meta( $pid, '_shat_gallery_ids', $gallery );
			set_post_thumbnail( $pid, $gallery[0] );
			delete_post_meta( $pid, '_shat_image_url' );

			if ( $is_blurred_post ) {
				update_post_meta( $pid, '_shat_blurred',  '1' );
				update_post_meta( $pid, '_shat_passcode', $passcode );
			} else {
				delete_post_meta( $pid, '_shat_blurred' );
				delete_post_meta( $pid, '_shat_passcode' );
				$wpdb->delete( $wpdb->prefix . 'shat_unlocked', [ 'photo_id' => $pid ] );
			}

			/* NEW 2.3.0 — audience */
			update_post_meta( $pid, '_shat_audience', $audience );
			if ( $audience === 'selected' ) {
				update_post_meta( $pid, '_shat_audience_ids', $audience_ids );
			} else {
				delete_post_meta( $pid, '_shat_audience_ids' );
			}

			/* NEW 2.3.0 — Patreon exclusive. Turning it ON forces no-index
			   and clears any stored passcode/unlocks, since an exclusive has
			   no passcode path. Turning it OFF just removes the flag. */
			if ( $is_patreon_post ) {
				update_post_meta( $pid, '_shat_patreon', '1' );
				$is_noindex_post = true;
				delete_post_meta( $pid, '_shat_passcode' );
				$wpdb->delete( $wpdb->prefix . 'shat_unlocked', [ 'photo_id' => $pid ] );
			} else {
				delete_post_meta( $pid, '_shat_patreon' );
			}

			if ( $is_noindex_post ) {
				update_post_meta( $pid, '_shat_noindex', '1' );
			} else {
				delete_post_meta( $pid, '_shat_noindex' );
			}
			shat_clear_noindex_cache();

			$msg = '<div class="shat-notice ok">&#10003; Changes saved. <a href="' . admin_url('admin.php?page=shatania-photos') . '" style="color:var(--gold);">Back to all photos &rarr;</a></div>';
		}
	}

	/* Current values */
	$caption = get_post_meta( $pid, '_shat_caption', true );
	$desc    = get_post_meta( $pid, '_shat_description', true );
	$pdate   = get_the_date( 'Y-m-d', $pid );
	$blr     = shat_is_blurred( $pid );
	$pc      = $blr ? (string) get_post_meta( $pid, '_shat_passcode', true ) : '';
	$noidx   = shat_is_noindex( $pid );
	$cur_aud     = shat_get_audience( $pid );
	$cur_aud_ids = shat_get_audience_ids( $pid );
	$cur_patreon = shat_is_patreon( $pid );
	$edit_subs   = shat_get_approved_users();

	/* Build current gallery list (id + url) */
	$ids = get_post_meta( $pid, '_shat_gallery_ids', true );
	if ( ! is_array( $ids ) || empty( $ids ) ) {
		$ids = [];
		$thumb_id = get_post_thumbnail_id( $pid );
		if ( $thumb_id ) $ids[] = (int) $thumb_id;
	}
	$preset = [];
	foreach ( $ids as $aid ) {
		$aid = (int) $aid;
		$u   = wp_get_attachment_image_url( $aid, 'medium' ) ?: wp_get_attachment_url( $aid );
		if ( $u ) $preset[] = [ 'id' => $aid, 'url' => $u ];
	}

	echo '<div class="shat-a-wrap">';
	echo '<h1>Edit Photo</h1>';
	echo '<p class="sub"><a href="' . admin_url('admin.php?page=shatania-photos') . '" style="color:var(--gold);">&larr; Back to Manage Photos</a></p>';
	echo $msg;
	echo '<div class="shat-box" style="max-width:660px;">';
	echo '<form method="post" id="shat-edit-form">';
	wp_nonce_field( 'shat_do_edit_' . $pid, 'shat_edit_nonce' );

	echo '<div class="shat-fr"><label>Caption <span style="color:#E53935;">*</span></label><input type="text" name="caption" value="' . esc_attr($caption) . '" required></div>';
	echo '<div class="shat-fr"><label>Description</label><textarea name="desc">' . esc_textarea($desc) . '</textarea></div>';
	echo '<div class="shat-fr" style="max-width:220px;"><label>Date</label><input type="date" name="photo_date" value="' . esc_attr($pdate) . '"></div>';

	echo '<div class="shat-fr">';
	echo '<label>Photos <span style="color:#999;font-weight:400;">(add, remove or replace)</span></label>';
	echo '<input type="hidden" name="image_ids" id="sed-img-ids" value="' . esc_attr( implode(',', wp_list_pluck($preset,'id')) ) . '">';
	echo '<button type="button" class="ab pr" onclick="shatEdMedia()" style="margin-bottom:10px;">&#128247; Add / Replace Photos</button>';
	echo '<div id="sed-preview" style="display:flex;flex-wrap:wrap;gap:8px;"></div>';
	echo '<p style="font-size:12px;color:#999;margin-top:8px;font-style:italic;">The first photo is used as the cover. Click &times; to remove a photo.</p>';
	echo '</div>';

	/* ---- AUDIENCE (NEW 2.3.0) ---- */
	echo '<div class="shat-fr">';
	echo '<label>Share With</label>';
	echo '<div style="display:flex;flex-direction:column;gap:9px;">';
	echo '<label style="display:flex;align-items:center;gap:9px;font-size:14px;color:var(--text);font-weight:400;cursor:pointer;">';
	echo '<input type="radio" name="audience" value="all"' . ( $cur_aud === 'all' ? ' checked' : '' ) . ' onchange="shatEdAud()"> ';
	echo '<span><strong>Everyone</strong> <span style="color:#999;">&mdash; all approved members</span></span></label>';
	echo '<label style="display:flex;align-items:center;gap:9px;font-size:14px;color:var(--text);font-weight:400;cursor:pointer;">';
	echo '<input type="radio" name="audience" value="selected"' . ( $cur_aud === 'selected' ? ' checked' : '' ) . ' onchange="shatEdAud()"> ';
	echo '<span><strong>Only selected members</strong> <span style="color:#999;">&mdash; hidden from everyone else</span></span></label>';
	echo '</div>';
	echo '<div id="sed-aud-list" style="display:' . ( $cur_aud === 'selected' ? 'block' : 'none' ) . ';margin-top:12px;padding:12px;border:1px solid #eee;border-radius:10px;max-height:240px;overflow-y:auto;background:#fafafa;">';
	if ( empty( $edit_subs ) ) {
		echo '<p style="color:#999;margin:0;font-size:13px;">No approved members yet.</p>';
	} else {
		echo '<div style="margin-bottom:10px;"><label style="font-size:13px;display:inline-flex;align-items:center;gap:7px;cursor:pointer;">';
		echo '<input type="checkbox" onchange="shatEdAudAll(this)"> Select / Deselect All</label></div>';
		foreach ( $edit_subs as $su ) {
			$chk = in_array( (int) $su->ID, $cur_aud_ids, true ) ? ' checked' : '';
			echo '<label style="display:flex;align-items:center;gap:9px;padding:6px 2px;font-size:13px;font-weight:400;cursor:pointer;">';
			echo '<input type="checkbox" class="sed-aud-cb" name="audience_ids[]" value="' . intval( $su->ID ) . '"' . $chk . '> ';
			echo '<strong style="flex:1;">' . esc_html( $su->display_name ) . '</strong>';
			echo '<span style="color:#999;font-size:12px;">' . esc_html( $su->user_email ) . '</span>';
			echo '</label>';
		}
	}
	echo '</div></div>';

	/* ---- PATREON EXCLUSIVE (NEW 2.3.0) ---- */
	echo '<div class="shat-fr">';
	echo '<label>Patreon</label>';
	echo '<div class="shat-blur-toggle-wrap' . ( $cur_patreon ? ' active' : '' ) . '" id="sed-patreon-wrap">';
	echo '<label class="shat-blur-toggle-label">';
	echo '<input type="checkbox" name="is_patreon" id="sed-patreon-chk" value="1"' . ( $cur_patreon ? ' checked' : '' ) . ' onchange="shatEdPat(this)">';
	echo '&#11088; Patreon exclusive &mdash; locked for everyone, with a &ldquo;Visit Patreon&rdquo; button';
	echo '</label>';
	echo '<p class="shat-passcode-hint" id="sed-patreon-hint" style="display:' . ( $cur_patreon ? 'block' : 'none' ) . ';">Members see the blurred cover, an &ldquo;Exclusive&rdquo; badge, and a button linking to <code>' . esc_html( SHAT_PATREON_GO_URL ) . '</code>. Saving with this on clears any stored passcode and existing unlocks for this photo.</p>';
	echo '</div></div>';

	echo '<div class="shat-fr">';
	echo '<label>Privacy / Blur</label>';
	echo '<div class="shat-blur-toggle-wrap' . ( $blr ? ' active' : '' ) . '" id="sed-blur-wrap">';
	echo '<label class="shat-blur-toggle-label">';
	echo '<input type="checkbox" name="is_blurred" id="sed-blur-chk" value="1"' . ( $blr ? ' checked' : '' ) . ' onchange="shatEdBlur(this)">';
	echo '🔒 Blur this photo (members need a passcode to unlock)';
	echo '</label>';
	echo '<div id="sed-passcode-field" style="display:' . ( $blr ? 'block' : 'none' ) . ';margin-top:14px;">';
	echo '<label style="font-size:11px;color:var(--sec);text-transform:uppercase;letter-spacing:.9px;display:block;margin-bottom:7px;font-weight:500;">Passcode for this photo <span style="color:#E53935;">*</span></label>';
	echo '<input type="text" name="blur_passcode" id="sed-blur-passcode" value="' . esc_attr($pc) . '" placeholder="e.g. SunsetGold2024" style="max-width:280px;font-family:monospace;letter-spacing:1px;" autocomplete="off">';
	echo '<p class="shat-passcode-hint">Turning blur off will make the photo visible to all members and reset existing unlocks.</p>';
	echo '</div></div></div>';

	echo '<div class="shat-fr">';
	echo '<label>Search Engine Visibility</label>';
	echo '<div class="shat-blur-toggle-wrap' . ( $noidx ? ' active' : '' ) . '" id="sed-noindex-wrap">';
	echo '<label class="shat-blur-toggle-label">';
	echo '<input type="checkbox" name="is_noindex" id="sed-noindex-chk" value="1"' . ( $noidx ? ' checked' : '' ) . ' onchange="document.getElementById(\'sed-noindex-wrap\').classList.toggle(\'active\',this.checked)">';
	echo '🚫 Exclusive photo — hide from Google (noindex)';
	echo '</label>';
	echo '<p class="shat-passcode-hint">Keeps this photo out of Google Search and Google Images.</p>';
	echo '</div></div>';

	echo '<button type="submit" class="ab pr" style="width:100%;text-align:center;padding:14px;font-size:15px;margin-top:6px;">Save Changes</button>';
	echo '</form></div></div>';

	echo '<script>
	var shatEdPicked = ' . wp_json_encode( $preset ) . ';
	function shatEdRender(){
	  var box=document.getElementById("sed-preview"); box.innerHTML="";
	  shatEdPicked.forEach(function(it,i){
	    var w=document.createElement("div");
	    w.style.cssText="position:relative;width:90px;height:90px;border-radius:10px;overflow:hidden;border:1px solid rgba(184,155,94,.3);";
	    var cover = i===0 ? "<span style=\"position:absolute;bottom:0;left:0;right:0;background:rgba(184,155,94,.92);color:#fff;font-size:9px;text-align:center;padding:2px 0;letter-spacing:.5px;\">COVER</span>" : "";
	    w.innerHTML="<img src=\""+it.url+"\" style=\"width:100%;height:100%;object-fit:cover;display:block;\">"+
	      "<button type=\"button\" onclick=\"shatEdPicked.splice("+i+",1);shatEdRender();\" style=\"position:absolute;top:3px;right:3px;background:rgba(0,0,0,.75);color:#fff;border:0;border-radius:50%;width:22px;height:22px;line-height:20px;cursor:pointer;font-size:14px;\">&times;</button>"+cover;
	    box.appendChild(w);
	  });
	  document.getElementById("sed-img-ids").value=shatEdPicked.map(function(x){return x.id;}).join(",");
	}
	function shatEdMedia(){
	  var fr=wp.media({title:"Select Photos",multiple:"add",library:{type:["image/jpeg","image/png","image/webp"]},button:{text:"Use These Photos"}});
	  fr.on("select",function(){
	    fr.state().get("selection").toJSON().forEach(function(a){
	      if(!shatEdPicked.some(function(p){return p.id===a.id;})) shatEdPicked.push({id:a.id,url:a.url});
	    });
	    shatEdRender();
	  });
	  fr.open();
	}
	function shatEdBlur(cb){
	  document.getElementById("sed-passcode-field").style.display=cb.checked?"block":"none";
	  document.getElementById("sed-blur-wrap").classList.toggle("active",cb.checked);
	  var pat=document.getElementById("sed-patreon-chk");
	  if(cb.checked && pat && pat.checked){ pat.checked=false; shatEdPat(pat); }
	  if(cb.checked) document.getElementById("sed-blur-passcode").focus();
	}
	/* --- Audience + Patreon (2.3.0) --- */
	function shatEdAud(){
	  var sel=document.querySelector("input[name=\'audience\']:checked").value;
	  document.getElementById("sed-aud-list").style.display=(sel==="selected")?"block":"none";
	}
	function shatEdAudAll(cb){
	  document.querySelectorAll(".sed-aud-cb").forEach(function(c){ c.checked=cb.checked; });
	}
	function shatEdPat(cb){
	  document.getElementById("sed-patreon-hint").style.display=cb.checked?"block":"none";
	  document.getElementById("sed-patreon-wrap").classList.toggle("active",cb.checked);
	  var blur=document.getElementById("sed-blur-chk");
	  if(cb.checked && blur && blur.checked){ blur.checked=false; shatEdBlur(blur); }
	  if(cb.checked){
	    var ni=document.getElementById("sed-noindex-chk");
	    if(ni && !ni.checked){ ni.checked=true; ni.dispatchEvent(new Event("change")); }
	  }
	}
	shatEdRender();
	</script>';
}

// ============================================================
// ADMIN: ACTIVITY — likes, views & notifications
// ============================================================
function shat_admin_activity() {
	global $wpdb;
	shat_admin_css();

	$lt = $wpdb->prefix . 'shat_likes';
	$vt = $wpdb->prefix . 'shat_views';

	/* Totals.
	   total_vw  — every impression, repeat visits included.
	   unique_vw — distinct viewers (by ip+ua hash), logged-in or not.
	   today_vw  — impressions in the last 24h, so the number is not just
	               a lifetime figure that never appears to move. */
	$total_lk  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $lt" );
	$total_vw  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vt" );
	$unique_vw = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT viewer_hash) FROM $vt" );
	$today_vw  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $vt WHERE viewed_at > DATE_SUB(NOW(), INTERVAL 1 DAY)" );

	echo '<div class="shat-a-wrap">';
	echo '<h1>Activity</h1>';
	echo '<p class="sub">See who likes &amp; views your photos, with dates and times</p>';

	echo '<div class="shat-stats">';
	shat_admin_stat( 'Total Likes',     shat_fmt($total_lk),  '♥', true );
	shat_admin_stat( 'Total Views',     shat_fmt($total_vw),  '👁' );
	shat_admin_stat( 'Unique Viewers',  shat_fmt($unique_vw), '👤' );
	shat_admin_stat( 'Views (24h)',     shat_fmt($today_vw),  '📈' );
	echo '</div>';

	/* ---- Like notification feed ---- */
	$likes = $wpdb->get_results( "SELECT photo_id, user_id, created_at FROM $lt ORDER BY created_at DESC LIMIT 100" );
	echo '<div class="shat-box"><h2>🔔 Recent Like Activity</h2>';
	if ( empty( $likes ) ) {
		echo '<p style="color:#999;font-size:14px;">No likes yet.</p>';
	} else {
		echo '<table class="shat-tbl"><thead><tr><th>Member</th><th>Liked Photo</th><th>Date</th><th>Time</th></tr></thead><tbody>';
		foreach ( $likes as $l ) {
			$u    = $l->user_id ? get_userdata( $l->user_id ) : null;
			$name = $u ? $u->display_name : 'Guest';
			$mail = $u ? $u->user_email : '';
			$cap  = esc_html( get_post_meta($l->photo_id,'_shat_caption',true) ?: ('Photo #' . $l->photo_id) );
			$ts   = strtotime( $l->created_at );
			echo '<tr>';
			echo '<td><strong>' . esc_html($name) . '</strong>' . ( $mail ? '<br><span style="color:#999;font-size:12px;">' . esc_html($mail) . '</span>' : '' ) . '</td>';
			echo '<td>' . $cap . '</td>';
			echo '<td style="color:#666;white-space:nowrap;">' . esc_html( date('M j, Y', $ts) ) . '</td>';
			echo '<td style="color:#999;white-space:nowrap;">' . esc_html( date('g:i A', $ts) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';

	/* ---- Per-photo breakdown ---- */
	$all = get_posts([
		'post_type' => 'shat_photo', 'posts_per_page' => -1,
		'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC',
	]);
	echo '<div class="shat-box"><h2>📊 Per-Photo Breakdown</h2>';
	if ( empty( $all ) ) {
		echo '<p style="color:#999;font-size:14px;">No photos yet.</p>';
	} else {
		echo '<table class="shat-tbl"><thead><tr><th>Photo</th><th>Caption</th><th>Views</th><th>Unique</th><th>Likes</th><th>Liked / Viewed By</th></tr></thead><tbody>';
		foreach ( $all as $p ) {
			$img = get_the_post_thumbnail_url($p->ID,'thumbnail') ?: get_post_meta($p->ID,'_shat_image_url',true);
			$cap = esc_html( get_post_meta($p->ID,'_shat_caption',true) ?: $p->post_title );
			$lk  = shat_photo_likes($p->ID);
			$vw  = shat_photo_views($p->ID);
			$uvw = shat_photo_unique_views($p->ID);

			/* Likers */
			$likers = $wpdb->get_results( $wpdb->prepare(
				"SELECT user_id, created_at FROM $lt WHERE photo_id=%d ORDER BY created_at DESC", $p->ID ) );
			$liker_names = [];
			foreach ( $likers as $lr ) {
				$u = $lr->user_id ? get_userdata( $lr->user_id ) : null;
				$liker_names[] = ( $u ? $u->display_name : 'Guest' ) . ' · ' . date('M j, g:i A', strtotime($lr->created_at));
			}

			/* Known viewers */
			$viewers = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT user_id FROM $vt WHERE photo_id=%d AND user_id > 0", $p->ID ) );
			$viewer_names = [];
			foreach ( $viewers as $vid ) {
				$u = get_userdata( $vid );
				if ( $u ) $viewer_names[] = $u->display_name;
			}

			echo '<tr>';
			echo '<td>' . ($img ? '<img src="' . esc_url($img) . '" class="shat-thumb">' : '<span style="color:#CCC;">—</span>') . '</td>';
			echo '<td><strong>' . $cap . '</strong></td>';
			echo '<td><span style="color:#888;">&#128065; ' . $vw . '</span></td>';
			echo '<td><span style="color:#999;">' . $uvw . ' <span style="font-size:11px;color:#BBB;">people</span></span></td>';
			echo '<td><span style="color:#E53935;font-weight:500;">&#9829; ' . $lk . '</span></td>';
			echo '<td style="font-size:12px;color:#666;max-width:320px;">';
			if ( $liker_names ) echo '<div style="margin-bottom:6px;"><strong style="color:#E53935;">♥ Likes:</strong> ' . esc_html( implode(', ', $liker_names) ) . '</div>';
			if ( $viewer_names ) echo '<div><strong style="color:#888;">👁 Viewed by:</strong> ' . esc_html( implode(', ', $viewer_names) ) . '</div>';
			if ( ! $liker_names && ! $viewer_names ) echo '<span style="color:#CCC;">—</span>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p style="font-size:12px;color:#999;margin-top:14px;font-style:italic;">Note: views from logged-out visitors are counted in totals but shown as anonymous.</p>';
	}
	echo '</div>';
	echo '</div>';
}


// ============================================================
/* ============================================================
   MEMBER STATS (NEW in 2.3.0)
   Totals for the admin Members page: overall count, status split,
   gender split, and recent signups. Computed with grouped COUNT
   queries rather than by loading every user into memory, so this
   stays cheap as the member list grows.

   Administrators are excluded throughout, matching the members list.
   ============================================================ */
function shat_member_stats() {
	global $wpdb;

	/* Admin user IDs to exclude. Small set — fine to inline. */
	$admin_ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
	$admin_ids = array_map( 'intval', (array) $admin_ids );
	$not_in    = ! empty( $admin_ids )
		? ' AND u.ID NOT IN (' . implode( ',', $admin_ids ) . ')'
		: '';

	$um = $wpdb->usermeta;
	$us = $wpdb->users;

	/* Status split — one grouped query. */
	$status_rows = $wpdb->get_results(
		"SELECT m.meta_value AS k, COUNT(*) AS c
		   FROM $um m
		   INNER JOIN $us u ON u.ID = m.user_id
		  WHERE m.meta_key = 'shat_status' $not_in
		  GROUP BY m.meta_value"
	);
	$by_status = [ 'pending' => 0, 'approved' => 0, 'rejected' => 0 ];
	$total     = 0;
	foreach ( $status_rows as $r ) {
		$by_status[ $r->k ] = (int) $r->c;
		$total += (int) $r->c;
	}

	/* Gender split. Users registered before this field existed have no
	   row at all, so they're counted by subtraction into 'unspecified'
	   — that keeps the parts summing to the member total. */
	$gender_rows = $wpdb->get_results(
		"SELECT m.meta_value AS k, COUNT(*) AS c
		   FROM $um m
		   INNER JOIN $us u ON u.ID = m.user_id
		   INNER JOIN $um s ON s.user_id = u.ID AND s.meta_key = 'shat_status'
		  WHERE m.meta_key = 'shat_gender' $not_in
		  GROUP BY m.meta_value"
	);
	$by_gender = [ 'female' => 0, 'male' => 0, 'other' => 0, 'unspecified' => 0 ];
	$gender_known = 0;
	foreach ( $gender_rows as $r ) {
		$key = shat_sanitize_gender( $r->k );
		$by_gender[ $key ] += (int) $r->c;
		$gender_known += (int) $r->c;
	}
	$by_gender['unspecified'] += max( 0, $total - $gender_known );

	/* Signups by period. shat_joined_date is this plugin's own stamp;
	   fall back to the WP registration date for older accounts. */
	$today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
	$week_start  = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - 6 * DAY_IN_SECONDS );
	$month_start = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - 29 * DAY_IN_SECONDS );

	$since = function( $stamp ) use ( $wpdb, $um, $us, $not_in ) {
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			   FROM $us u
			   INNER JOIN $um s ON s.user_id = u.ID AND s.meta_key = 'shat_status'
			   LEFT JOIN $um j ON j.user_id = u.ID AND j.meta_key = 'shat_joined_date'
			  WHERE COALESCE(NULLIF(j.meta_value,''), u.user_registered) >= %s $not_in",
			$stamp
		) );
	};

	return [
		'total'      => $total,
		'status'     => $by_status,
		'gender'     => $by_gender,
		'today'      => $since( $today_start ),
		'week'       => $since( $week_start ),
		'month'      => $since( $month_start ),
	];
}

function shat_admin_members() {
	shat_admin_css();

	/* ---- Portal approve/reject (existing) ---- */
	if ( isset($_GET['shat_action'], $_GET['uid']) && current_user_can('manage_options') ) {
		$action = sanitize_key( $_GET['shat_action'] );
		$uid    = intval( $_GET['uid'] );
		$udata  = get_userdata( $uid );
		if ( $udata && in_array($action, ['approve','reject']) ) {
			if ( $action === 'approve' ) {
				update_user_meta( $uid, 'shat_status', 'approved' );
				update_user_meta( $uid, 'shat_approved_date', current_time('mysql') );
				$fn = $udata->first_name ?: $udata->display_name;
				shat_mail( $udata->user_email,
					'You\'re approved — Welcome to Shatania ✨',
					shat_email_wrap( 'You\'re in! ✨',
						'<p>Hi <strong>' . esc_html($fn) . '</strong>,</p>
						<p>Your Shatania account has been approved! You can now sign in and explore the collection.</p>
						<p><a href="' . esc_url(shat_page_url('shatania-login')) . '" class="btn">Sign In Now &rarr;</a></p>
						<p style="color:#999;font-size:13px;margin-top:20px;">— Shatania</p>'
					)
				);
				echo '<div class="notice notice-success is-dismissible"><p>Member approved and notified via email.</p></div>';
			} else {
				update_user_meta( $uid, 'shat_status', 'rejected' );
				shat_mail( $udata->user_email,
					'Update on your Shatania application',
					shat_email_wrap( 'Application Update',
						'<p>Hi ' . esc_html($udata->first_name ?: $udata->display_name) . ',</p>
						<p>After reviewing your application, we\'re unable to approve your account at this time.</p>
						<p style="color:#999;font-size:13px;margin-top:20px;">— Shatania</p>'
					)
				);
				echo '<div class="notice notice-warning is-dismissible"><p>Member rejected and notified via email.</p></div>';
			}
		}
	}

	/* ---- Feed access: set days / revoke now / clear expiry ---- */
	if ( isset($_POST['shat_feed_nonce']) && wp_verify_nonce($_POST['shat_feed_nonce'],'shat_set_feed_access') && current_user_can('manage_options') ) {
		$uid       = intval( $_POST['uid'] ?? 0 );
		$feed_act  = sanitize_key( $_POST['shat_feed_action'] ?? '' );
		$udata     = get_userdata( $uid );

		if ( $udata && $feed_act === 'set_days' ) {
			$days = max( 1, intval( $_POST['shat_feed_days'] ?? 0 ) );
			$new_expiry = date( 'Y-m-d H:i:s', current_time('timestamp') + ( $days * DAY_IN_SECONDS ) );
			update_user_meta( $uid, 'shat_feed_expiry', $new_expiry );
			echo '<div class="notice notice-success is-dismissible"><p>Feed access set to expire in ' . $days . ' day(s) — ' . esc_html( date('M j, Y', strtotime($new_expiry)) ) . '.</p></div>';
		} elseif ( $udata && $feed_act === 'revoke' ) {
			update_user_meta( $uid, 'shat_feed_expiry', current_time('mysql') );
			echo '<div class="notice notice-warning is-dismissible"><p>Feed access revoked immediately for ' . esc_html($udata->display_name) . '.</p></div>';
		} elseif ( $udata && $feed_act === 'clear' ) {
			delete_user_meta( $uid, 'shat_feed_expiry' );
			echo '<div class="notice notice-success is-dismissible"><p>Feed access set to unlimited for ' . esc_html($udata->display_name) . '.</p></div>';
		}
	}

	$filter   = sanitize_key( $_GET['filter'] ?? 'all' );
	$search   = trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) );
	$per_page = max( 10, min( 200, intval( $_GET['per_page'] ?? 20 ) ) );
	$paged    = max( 1, intval( $_GET['paged'] ?? 1 ) );

	$args = [
		'role__not_in' => ['administrator'],
		'number'       => $per_page,
		'offset'       => ( $paged - 1 ) * $per_page,
		'count_total'  => true,   // needed for the pager
		'orderby'      => 'registered',
		'order'        => 'DESC',
	];
	if ( $search !== '' ) {
		/* Match name or email. 'search_columns' keeps this to indexed
		   user-table columns; display_name covers "First Last". */
		$args['search']         = '*' . $search . '*';
		$args['search_columns'] = [ 'user_login', 'user_email', 'user_nicename', 'display_name' ];
	}

	/* Status tab. 'all' still requires the meta to exist so WP users who
	   never went through this plugin's registration aren't listed. */
	if ( $filter !== 'all' ) {
		$args['meta_key']   = 'shat_status';
		$args['meta_value'] = $filter;
	} else {
		$args['meta_key']     = 'shat_status';
		$args['meta_compare'] = 'EXISTS';
	}

	$user_query  = new WP_User_Query( $args );
	$users       = $user_query->get_results();
	$total_found = (int) $user_query->get_total();
	$total_pages = $per_page > 0 ? (int) ceil( $total_found / $per_page ) : 1;

	$stats = shat_member_stats();

	echo '<div class="shat-a-wrap"><h1>Members</h1><p class="sub">Manage member access &amp; approvals</p>';

	/* ---- Stat cards ---- */
	$cards = [
		[ 'Total Members', $stats['total'],               '#D9A9AC', '' ],
		[ 'Joined Today',  $stats['today'],               '#5BBD7B', '' ],
		[ 'Last 7 Days',   $stats['week'],                '#5BBD7B', '' ],
		[ 'Last 30 Days',  $stats['month'],               '#5BBD7B', '' ],
		[ 'Pending',       $stats['status']['pending'],   '#E0A34A', 'pending' ],
		[ 'Approved',      $stats['status']['approved'],  '#5BBD7B', 'approved' ],
	];
	echo '<div class="shat-stat-grid">';
	foreach ( $cards as $c ) {
		list( $lbl, $val, $col, $tab ) = $c;
		$open = $tab ? '<a href="' . esc_url( admin_url( 'admin.php?page=shatania-members&filter=' . $tab ) ) . '" class="shat-stat-card">' : '<div class="shat-stat-card">';
		$shut = $tab ? '</a>' : '</div>';
		echo $open;
		echo '<span class="shat-stat-n" style="color:' . esc_attr( $col ) . ';">' . intval( $val ) . '</span>';
		echo '<span class="shat-stat-l">' . esc_html( $lbl ) . '</span>';
		echo $shut;
	}
	echo '</div>';

	/* ---- Gender breakdown ---- */
	$g = $stats['gender'];
	echo '<div class="shat-gender-bar">';
	echo '<span class="shat-gender-title">Gender</span>';
	foreach ( [ 'female', 'male', 'other', 'unspecified' ] as $gk ) {
		$gv  = intval( $g[ $gk ] );
		$pct = $stats['total'] > 0 ? round( $gv * 100 / $stats['total'] ) : 0;
		echo '<span class="shat-gender-chip">';
		echo '<strong>' . esc_html( shat_gender_label( $gk ) ) . '</strong> ';
		echo $gv . ' <em>(' . $pct . '%)</em>';
		echo '</span>';
	}
	echo '</div>';

	/* ---- Status tabs (preserve the current search) ---- */
	$tabs = ['all'=>'All','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'];
	echo '<div class="filter-tabs">';
	foreach ($tabs as $k=>$lbl) {
		$act  = ($filter===$k) ? ' active' : '';
		$href = add_query_arg( array_filter([
			'page'     => 'shatania-members',
			'filter'   => $k,
			's'        => $search !== '' ? $search : null,
			'per_page' => $per_page !== 20 ? $per_page : null,
		]), admin_url('admin.php') );
		echo '<a href="' . esc_url( $href ) . '" class="' . $act . '">' . $lbl . '</a>';
	}
	echo '</div>';

	/* ---- Search ---- */
	echo '<form method="get" class="shat-search-row">';
	echo '<input type="hidden" name="page" value="shatania-members">';
	echo '<input type="hidden" name="filter" value="' . esc_attr( $filter ) . '">';
	echo '<input type="hidden" name="per_page" value="' . intval( $per_page ) . '">';
	echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search by name or email&hellip;" class="shat-search-input">';
	echo '<button type="submit" class="ab ap" style="border:none;cursor:pointer;">Search</button>';
	if ( $search !== '' ) {
		echo '<a href="' . esc_url( admin_url('admin.php?page=shatania-members&filter=' . $filter) ) . '" class="ab" style="border:1px solid #ddd;background:#fff;">Clear</a>';
	}
	echo '</form>';

	/* ---- Result summary ---- */
	$range_from = $total_found ? ( ( $paged - 1 ) * $per_page ) + 1 : 0;
	$range_to   = min( $paged * $per_page, $total_found );
	echo '<p class="shat-result-line">';
	if ( $search !== '' ) {
		echo 'Found <strong>' . intval( $total_found ) . '</strong> member' . ( $total_found === 1 ? '' : 's' ) . ' matching &ldquo;' . esc_html( $search ) . '&rdquo;';
	} else {
		echo 'Showing <strong>' . intval( $range_from ) . '&ndash;' . intval( $range_to ) . '</strong> of <strong>' . intval( $total_found ) . '</strong>';
	}
	echo '</p>';

	echo '<div class="shat-box">';
	if ( empty($users) ) {
		echo '<p style="color:#999;font-size:14px;text-align:center;padding:40px 0;">No members found.</p>';
	} else {
		echo '<table class="shat-tbl"><thead><tr><th>Name</th><th>Email</th><th>Gender</th><th>Joined</th><th>Status</th><th>Feed Access</th><th>Portal Actions</th><th>Feed Access Controls</th></tr></thead><tbody>';
		foreach ($users as $u) {
			$st         = get_user_meta($u->ID,'shat_status',true) ?: 'pending';
			$jd         = get_user_meta($u->ID,'shat_joined_date',true) ?: $u->user_registered;
			$jdf        = date('M j, Y', strtotime($jd));
			$feed_state = shat_feed_access_state( $u->ID );
			$feed_exp   = get_user_meta( $u->ID, 'shat_feed_expiry', true );

			echo '<tr>';
			echo '<td><strong>' . esc_html($u->display_name) . '</strong></td>';
			echo '<td style="color:#888;">' . esc_html($u->user_email);
			$ig_h = get_user_meta( $u->ID, 'shat_ig', true );
			if ( $ig_h ) {
				echo '<div style="margin-top:3px;font-size:11px;">'
					. '<a href="' . esc_url( 'https://instagram.com/' . $ig_h ) . '" target="_blank" rel="noopener noreferrer nofollow" style="color:#C13584;text-decoration:none;">@'
					. esc_html( $ig_h ) . '</a></div>';
			}
			echo '</td>';
			$gval = shat_get_gender( $u->ID );
			echo '<td style="color:#999;white-space:nowrap;">' . esc_html( shat_gender_label( $gval ) ) . '</td>';
			echo '<td style="color:#999;white-space:nowrap;">' . $jdf . '</td>';
			echo '<td><span class="shat-badge sb-' . esc_attr($st) . '">' . ucfirst($st) . '</span></td>';

			/* Feed access column — with live countdown */
			echo '<td style="white-space:nowrap;">';
			if ( $feed_state === 'unlimited' ) {
				echo '<span class="shat-badge sb-approved">Unlimited</span>';
			} elseif ( $feed_state === 'active' ) {
				$days_left = max( 0, ceil( ( strtotime($feed_exp) - current_time('timestamp') ) / DAY_IN_SECONDS ) );
				$urgent    = $days_left <= 3;
				echo '<span class="shat-badge ' . ( $urgent ? 'sb-locked' : 'sb-approved' ) . '">';
				echo $days_left . ' day' . ( $days_left == 1 ? '' : 's' ) . ' left';
				echo '</span>';
				echo '<div style="color:#999;font-size:11px;margin-top:3px;">until ' . esc_html( date('M j, Y', strtotime($feed_exp)) ) . '</div>';
			} else {
				$days_ago = max( 0, floor( ( current_time('timestamp') - strtotime($feed_exp) ) / DAY_IN_SECONDS ) );
				echo '<span class="shat-badge sb-rejected">Expired</span>';
				echo '<div style="color:#999;font-size:11px;margin-top:3px;">' . $days_ago . ' day' . ( $days_ago == 1 ? '' : 's' ) . ' ago (' . esc_html( date('M j, Y', strtotime($feed_exp)) ) . ')</div>';
			}
			echo '</td>';

			/* Portal actions */
			echo '<td style="white-space:nowrap;">';
			if ($st!=='approved') echo '<a href="' . admin_url('admin.php?page=shatania-members&shat_action=approve&uid='.$u->ID) . '" class="ab ap">Approve</a>';
			if ($st!=='rejected') echo '<a href="' . admin_url('admin.php?page=shatania-members&shat_action=reject&uid='.$u->ID)  . '" class="ab rj" onclick="return confirm(\'Reject this member?\')">Reject</a>';
			echo '</td>';

			/* Feed access controls */
			echo '<td>';
			echo '<form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">';
			wp_nonce_field('shat_set_feed_access','shat_feed_nonce');
			echo '<input type="hidden" name="uid" value="' . intval($u->ID) . '">';
			echo '<input type="number" name="shat_feed_days" min="1" value="30" style="width:60px;padding:4px 6px;border:1px solid #ddd;border-radius:6px;font-size:12px;">';
			echo '<button type="submit" name="shat_feed_action" value="set_days" class="ab ap" style="border:none;cursor:pointer;">Set Days</button>';
			echo '<button type="submit" name="shat_feed_action" value="clear" class="ab unblur" style="border:none;cursor:pointer;" onclick="return confirm(\'Give unlimited feed access?\')">Unlimited</button>';
			echo '<button type="submit" name="shat_feed_action" value="revoke" class="ab rj" style="border:none;cursor:pointer;" onclick="return confirm(\'Revoke feed access immediately? They will still be able to log in, but the feed will show locked.\')">Revoke Now</button>';
			echo '</form>';
			echo '</td>';

			echo '</tr>';
		}
		echo '</tbody></table>';

		/* ---- Pager ---- */
		if ( $total_pages > 1 ) {
			$base = array_filter([
				'page'     => 'shatania-members',
				'filter'   => $filter !== 'all' ? $filter : null,
				's'        => $search !== '' ? $search : null,
				'per_page' => $per_page !== 20 ? $per_page : null,
			]);
			$link = function( $p ) use ( $base ) {
				return esc_url( add_query_arg( array_merge( $base, [ 'paged' => $p ] ), admin_url('admin.php') ) );
			};

			echo '<div class="shat-pager">';
			if ( $paged > 1 ) {
				echo '<a href="' . $link( $paged - 1 ) . '" class="ab">&larr; Previous</a>';
			}

			/* Window of page numbers around the current one, so a long
			   member list doesn't print hundreds of links. */
			$from = max( 1, $paged - 2 );
			$to   = min( $total_pages, $paged + 2 );
			if ( $from > 1 ) {
				echo '<a href="' . $link( 1 ) . '" class="ab">1</a>';
				if ( $from > 2 ) echo '<span class="shat-pager-gap">&hellip;</span>';
			}
			for ( $i = $from; $i <= $to; $i++ ) {
				if ( $i === $paged ) {
					echo '<span class="ab shat-pager-cur">' . $i . '</span>';
				} else {
					echo '<a href="' . $link( $i ) . '" class="ab">' . $i . '</a>';
				}
			}
			if ( $to < $total_pages ) {
				if ( $to < $total_pages - 1 ) echo '<span class="shat-pager-gap">&hellip;</span>';
				echo '<a href="' . $link( $total_pages ) . '" class="ab">' . $total_pages . '</a>';
			}

			if ( $paged < $total_pages ) {
				echo '<a href="' . $link( $paged + 1 ) . '" class="ab">Next &rarr;</a>';
			}
			echo '</div>';
		}

		/* ---- Per-page switcher ("expand if needed") ---- */
		echo '<div class="shat-perpage">Show ';
		foreach ( [ 20, 50, 100 ] as $n ) {
			$href = esc_url( add_query_arg( array_filter([
				'page'     => 'shatania-members',
				'filter'   => $filter !== 'all' ? $filter : null,
				's'        => $search !== '' ? $search : null,
				'per_page' => $n,
			]), admin_url('admin.php') ) );
			echo $per_page === $n
				? '<strong>' . $n . '</strong>'
				: '<a href="' . $href . '">' . $n . '</a>';
			echo ' ';
		}
		echo 'per page</div>';
	}
	echo '</div></div>';
}

// ============================================================
// ADMIN: ACCESS REQUESTS (in-app requests, replaces mailto: links)
// ============================================================
function shat_admin_requests() {
	global $wpdb;
	shat_admin_css();
	shat_ensure_access_requests_table();
	$tbl = $wpdb->prefix . 'shat_access_requests';

	/* Mark resolved / re-open / delete — no reply thread here; members are
	   pointed to the built-in chat for a live response, so this page is
	   just a log of who asked for what, with a way to keep it tidy. */
	if ( isset($_GET['shat_req_action'], $_GET['rid']) && current_user_can('manage_options') && isset($_GET['_wpnonce']) ) {
		$rid    = intval( $_GET['rid'] );
		$action = sanitize_key( $_GET['shat_req_action'] );
		if ( wp_verify_nonce( sanitize_key($_GET['_wpnonce']), 'shat_req_' . $rid ) ) {
			if ( in_array( $action, ['resolve','reopen'], true ) ) {
				shat_set_request_status( $rid, $action === 'resolve' ? 'resolved' : 'open' );
				echo '<div class="notice notice-success is-dismissible"><p>Request marked as ' . ( $action === 'resolve' ? 'resolved' : 'open' ) . '.</p></div>';
			} elseif ( $action === 'delete' ) {
				$wpdb->delete( $tbl, [ 'id' => $rid ] );
				echo '<div class="notice notice-success is-dismissible"><p>Request deleted.</p></div>';
			}
		}
	}

	/* Bulk "Delete All Resolved" — one click to clear out everything already
	   handled, so the log doesn't pile up over time. */
	if ( isset($_GET['shat_req_bulk']) && $_GET['shat_req_bulk'] === 'delete_resolved' && current_user_can('manage_options') && isset($_GET['_wpnonce']) ) {
		if ( wp_verify_nonce( sanitize_key($_GET['_wpnonce']), 'shat_req_bulk_delete' ) ) {
			$deleted = $wpdb->query( "DELETE FROM $tbl WHERE status='resolved'" );
			echo '<div class="notice notice-success is-dismissible"><p>' . intval( $deleted ) . ' resolved request(s) deleted.</p></div>';
		}
	}

	/* ---- Request log (default and only view) ---- */
	$filter = sanitize_key( $_GET['filter'] ?? 'all' );
	$where  = $filter === 'all' ? '1=1' : $wpdb->prepare( 'status=%s', $filter );
	$rows   = $wpdb->get_results( "SELECT * FROM $tbl WHERE $where ORDER BY created_at DESC LIMIT 200" );

	$open_count      = shat_count_open_requests();
	$resolved_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tbl WHERE status='resolved'" );

	echo '<div class="shat-a-wrap"><h1>Access Requests</h1><p class="sub">Requests sent from inside the member feed. Members are told to message you in the built-in chat for the quickest response &mdash; check <a href="' . esc_url( admin_url('admin.php?page=shatania-chat') ) . '" style="color:var(--gold);">Chat</a>, then mark the request resolved here once handled. Pending members can\'t use chat yet, so they\'re told to expect an email reply instead.</p>';

	$tabs = [
		'all'      => 'All',
		'open'     => 'Open (' . $open_count . ')',
		'resolved' => 'Resolved',
	];
	echo '<div class="filter-tabs" style="justify-content:space-between;align-items:center;display:flex;flex-wrap:wrap;gap:10px;">';
	echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
	foreach ( $tabs as $k => $lbl ) {
		$act = ( $filter === $k ) ? ' active' : '';
		echo '<a href="' . admin_url('admin.php?page=shatania-requests&filter='.$k) . '" class="' . $act . '">' . $lbl . '</a>';
	}
	echo '</div>';
	if ( $resolved_count > 0 ) {
		$bulk_url = wp_nonce_url( admin_url( 'admin.php?page=shatania-requests&filter=' . $filter . '&shat_req_bulk=delete_resolved' ), 'shat_req_bulk_delete' );
		echo '<a href="' . esc_url( $bulk_url ) . '" class="ab dl" onclick="return confirm(\'Delete all ' . intval( $resolved_count ) . ' resolved request(s)? This can\\\'t be undone.\')">Delete All Resolved (' . intval( $resolved_count ) . ')</a>';
	}
	echo '</div>';

	echo '<div class="shat-box">';
	if ( empty( $rows ) ) {
		echo '<p style="color:#999;font-size:14px;text-align:center;padding:40px 0;">No requests here.</p>';
	} else {
		echo '<table class="shat-tbl"><thead><tr><th>Member</th><th>Email</th><th>Request</th><th>Note</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$u = get_userdata( $r->user_id );
			$name  = $u ? $u->display_name : 'Deleted user';
			$email = $u ? $u->user_email   : '—';
			$label = shat_request_type_label( $r->type );
			$badge_cls = $r->status === 'resolved' ? 'sb-approved' : 'sb-pending';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $name ) . '</strong></td>';
			echo '<td style="color:#888;">' . ( $u ? '<a href="mailto:' . esc_attr($email) . '" style="color:#888;">' . esc_html($email) . '</a>' : '—' ) . '</td>';
			echo '<td>' . esc_html( $label );
			if ( $r->photo_id ) {
				$cap = get_post_meta( $r->photo_id, '_shat_caption', true ) ?: get_the_title( $r->photo_id );
				echo '<br><span style="color:#999;font-size:12px;">' . esc_html( $cap ?: ('Photo #' . $r->photo_id) ) . '</span>';
			}
			echo '</td>';
			echo '<td style="max-width:220px;color:#666;font-size:13px;">' . ( $r->message ? nl2br( esc_html( mb_strimwidth( $r->message, 0, 100, '…' ) ) ) : '<span style="color:#CCC;">—</span>' ) . '</td>';
			echo '<td style="color:#999;white-space:nowrap;">' . esc_html( date( 'M j, Y g:ia', strtotime( $r->created_at ) ) ) . '</td>';
			echo '<td><span class="shat-badge ' . $badge_cls . '">' . esc_html( $r->status === 'resolved' ? 'Resolved' : 'Open' ) . '</span></td>';
			echo '<td style="white-space:nowrap;">';
			if ( $r->status !== 'resolved' ) {
				$url = wp_nonce_url( admin_url( 'admin.php?page=shatania-requests&filter=' . $filter . '&shat_req_action=resolve&rid=' . $r->id ), 'shat_req_' . $r->id );
				echo '<a href="' . esc_url( $url ) . '" class="ab ap" style="margin-bottom:4px;display:inline-block;">Mark Resolved</a><br>';
			} else {
				$url = wp_nonce_url( admin_url( 'admin.php?page=shatania-requests&filter=' . $filter . '&shat_req_action=reopen&rid=' . $r->id ), 'shat_req_' . $r->id );
				echo '<a href="' . esc_url( $url ) . '" class="ab" style="background:#F0F0F0;color:#666;margin-bottom:4px;display:inline-block;">Re-open</a><br>';
			}
			$del_url = wp_nonce_url( admin_url( 'admin.php?page=shatania-requests&filter=' . $filter . '&shat_req_action=delete&rid=' . $r->id ), 'shat_req_' . $r->id );
			echo '<a href="' . esc_url( $del_url ) . '" class="ab dl" onclick="return confirm(\'Delete this request?\')">Delete</a>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div></div>';
}


function shat_admin_email_pg() {
	shat_admin_css();
	$msg = '';

	if ( isset($_POST['shat_em_nonce']) && wp_verify_nonce($_POST['shat_em_nonce'],'shat_send_em') ) {
		/* WordPress core (wp_magic_quotes) adds slashes to every $_POST value,
		   so an apostrophe arrives as \' and would send literally in the email.
		   wp_unslash() removes those slashes BEFORE sanitizing — the standard
		   WP fix. This is why messages were showing It\'s / you\'ll etc. */
		$subj   = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$body   = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
		$recips = array_map('sanitize_email', (array) wp_unslash( $_POST['recipients'] ?? [] ));
		if ( ! $subj || ! $body || empty($recips) ) {
			$msg = '<div class="shat-notice err">Please complete all fields and select at least one recipient.</div>';
		} else {
			$sent = 0;
			foreach ( $recips as $em ) {
				if ( ! is_email($em) ) continue;
				shat_mail( $em, $subj,
					shat_email_wrap( $subj,
						'<div style="font-size:14px;color:#333;line-height:1.7;">' . nl2br(esc_html($body)) . '</div>
						<p style="color:#999;font-size:12px;margin-top:20px;">You\'re receiving this because you\'re an approved member of Shatania.</p>'
					)
				);
				$sent++;
			}
			$msg = '<div class="shat-notice ok">&#10003; Email sent to ' . $sent . ' subscriber(s).</div>';
		}
	}

	$subs = shat_get_approved_users();

	echo '<div class="shat-a-wrap"><h1>Email Members</h1><p class="sub">Send a direct message to your approved community</p>';
	echo $msg;
	echo '<div class="shat-box" style="max-width:640px;">';
	echo '<form method="post">';
	wp_nonce_field('shat_send_em','shat_em_nonce');
	echo '<div class="shat-fr"><label>Subject</label><input type="text" name="subject" placeholder="Your email subject..." required></div>';
	echo '<div class="shat-fr"><label>Message</label><textarea name="body" placeholder="Write your message here..." style="min-height:130px;" required></textarea></div>';
	echo '<div class="shat-fr">';
	echo '<label>Recipients <span style="color:var(--gold);">(' . count($subs) . ' approved members)</span></label>';
	if ( empty($subs) ) {
		echo '<p style="color:#999;font-size:13px;">No approved members yet.</p>';
	} else {
		echo '<div style="margin-bottom:10px;"><label style="font-size:13px;display:inline-flex;align-items:center;gap:7px;cursor:pointer;"><input type="checkbox" checked onchange="document.querySelectorAll(\'input[name=\\\'recipients[]\\\']\').forEach(function(c){c.checked=this.checked}.bind(this))"> Select / Deselect All</label></div>';
		echo '<div class="shat-sublist">';
		foreach ($subs as $su) {
			echo '<div class="shat-si"><input type="checkbox" name="recipients[]" value="' . esc_attr($su->user_email) . '" checked>';
			echo '<strong style="flex:1;">' . esc_html($su->display_name) . '</strong>';
			echo '<span style="color:#999;font-size:12px;">' . esc_html($su->user_email) . '</span></div>';
		}
		echo '</div>';
	}
	echo '</div>';
	echo '<button type="submit" class="ab pr" style="width:100%;text-align:center;padding:13px;font-size:15px;">Send Email</button>';
	echo '</form></div></div>';
}



// ============================================================
// ADMIN: 2FA (Google Authenticator) — admins only
// ============================================================
function shat_admin_2fa() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
	shat_admin_css();

	$uid     = get_current_user_id();
	$enabled = shat_admin_has_2fa( $uid );
	$msg     = '';

	/* ---- DISABLE ---- */
	if ( isset( $_POST['shat_2fa_disable_nonce'] )
		&& wp_verify_nonce( $_POST['shat_2fa_disable_nonce'], 'shat_2fa_disable' ) ) {
		delete_user_meta( $uid, 'shat_2fa_secret' );
		delete_user_meta( $uid, 'shat_2fa_enabled' );
		delete_user_meta( $uid, 'shat_2fa_pending_secret' );
		$enabled = false;
		$msg = '<div class="shat-notice ok">&#10003; Two-factor authentication has been disabled.</div>';
	}

	/* ---- GENERATE a new pending secret ---- */
	if ( isset( $_POST['shat_2fa_gen_nonce'] )
		&& wp_verify_nonce( $_POST['shat_2fa_gen_nonce'], 'shat_2fa_gen' ) ) {
		update_user_meta( $uid, 'shat_2fa_pending_secret', shat_generate_totp_secret() );
		$msg = '<div class="shat-notice ok">Scan the QR code below with Google Authenticator, then enter a 6-digit code to confirm.</div>';
	}

	/* ---- VERIFY and ENABLE ---- */
	if ( isset( $_POST['shat_2fa_verify_nonce'] )
		&& wp_verify_nonce( $_POST['shat_2fa_verify_nonce'], 'shat_2fa_verify' ) ) {
		$pending = get_user_meta( $uid, 'shat_2fa_pending_secret', true );
		$code    = sanitize_text_field( $_POST['shat_2fa_code'] ?? '' );
		if ( $pending && shat_totp_verify( $pending, $code ) ) {
			update_user_meta( $uid, 'shat_2fa_secret', $pending );
			update_user_meta( $uid, 'shat_2fa_enabled', '1' );
			delete_user_meta( $uid, 'shat_2fa_pending_secret' );
			$enabled = true;
			$msg = '<div class="shat-notice ok">&#10003; Two-factor authentication is now enabled for your admin account.</div>';
		} else {
			$msg = '<div class="shat-notice err">That code didn\'t match. Make sure your phone\'s time is correct and try again.</div>';
		}
	}

	$pending_secret = get_user_meta( $uid, 'shat_2fa_pending_secret', true );
	$user           = wp_get_current_user();

	echo '<div class="shat-a-wrap"><h1>Admin 2FA Security</h1>';
	echo '<p class="sub">Protect your admin account with Google Authenticator (TOTP). Regular members sign in with an email code.</p>';
	echo $msg;

	echo '<div class="shat-box" style="max-width:640px;">';

	if ( $enabled ) {
		echo '<h2>Status: <span class="shat-badge sb-approved">Enabled</span></h2>';
		echo '<p style="color:#444;font-size:14px;line-height:1.7;margin:6px 0 18px;">Your admin sign-in now requires a 6-digit code from Google Authenticator after your password.</p>';
		echo '<form method="post" onsubmit="return confirm(\'Disable 2FA for your admin account? You will sign in with only password.\');">';
		wp_nonce_field( 'shat_2fa_disable', 'shat_2fa_disable_nonce' );
		echo '<button type="submit" class="ab dl">Disable 2FA</button>';
		echo '</form>';
	} elseif ( $pending_secret ) {
		$issuer  = SHAT_SITE_NAME;
		$account = $user->user_email ?: $user->user_login;
		$uri     = shat_otpauth_uri( $pending_secret, $account, $issuer );
		$qr      = shat_qr_image_url( $uri );

		echo '<h2>Set up Google Authenticator</h2>';
		echo '<ol style="font-size:14px;color:#333;line-height:1.8;padding-left:18px;">';
		echo '<li>Install <strong>Google Authenticator</strong> (or any TOTP app: Authy, 1Password, Microsoft Authenticator).</li>';
		echo '<li>Open the app and tap &ldquo;+&rdquo; &rarr; <em>Scan a QR code</em>.</li>';
		echo '<li>Scan the QR code below, then enter the 6-digit code it shows.</li>';
		echo '</ol>';

		echo '<div style="text-align:center;margin:18px 0;">';
		echo '<img src="' . esc_url( $qr ) . '" alt="QR code" style="border:1px solid #E8E8E8;border-radius:12px;padding:10px;background:#fff;">';
		echo '</div>';

		echo '<p style="font-size:13px;color:#666;text-align:center;margin-bottom:6px;">Can\'t scan? Enter this key manually:</p>';
		echo '<p style="text-align:center;margin-bottom:18px;"><code class="shat-code" style="font-size:14px;letter-spacing:2px;">' . esc_html( chunk_split( $pending_secret, 4, ' ' ) ) . '</code></p>';

		echo '<form method="post">';
		wp_nonce_field( 'shat_2fa_verify', 'shat_2fa_verify_nonce' );
		echo '<div class="shat-fr"><label>6-digit code from your app</label>';
		echo '<input type="text" name="shat_2fa_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus style="text-align:center;letter-spacing:8px;font-size:20px;"></div>';
		echo '<button type="submit" class="ab pr" style="width:100%;text-align:center;padding:13px;font-size:15px;">Verify &amp; Enable 2FA</button>';
		echo '</form>';

		echo '<form method="post" style="margin-top:10px;">';
		wp_nonce_field( 'shat_2fa_gen', 'shat_2fa_gen_nonce' );
		echo '<button type="submit" style="background:none;border:none;color:#999;font-size:12px;text-decoration:underline;cursor:pointer;">Generate a new secret</button>';
		echo '</form>';
	} else {
		echo '<h2>Status: <span class="shat-badge sb-pending">Not enabled</span></h2>';
		echo '<p style="color:#444;font-size:14px;line-height:1.7;margin:6px 0 18px;">Add an extra layer of security to your admin account. After enabling, you\'ll be asked for a 6-digit code from Google Authenticator every time you sign in.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'shat_2fa_gen', 'shat_2fa_gen_nonce' );
		echo '<button type="submit" class="ab pr">Set up Google Authenticator</button>';
		echo '</form>';
	}

	echo '</div>';

	/* Info card about member sign-in */
	echo '<div class="shat-box" style="max-width:640px;">';
	echo '<h2>Member sign-in (non-admins)</h2>';
	echo '<p style="color:#444;font-size:14px;line-height:1.7;margin:0;">Approved members sign in with their email + password. After entering the correct password, a 6-digit code is emailed to them and required to finish signing in. This happens automatically &mdash; no setup needed.</p>';
	echo '</div>';

	echo '</div>';
}
// ============================================================
// GALLERY LIGHTBOX (1.4.0) — collage CSS + fullscreen viewer
// ============================================================
add_action( 'wp_footer', function() {
	if ( ! shat_is_approved() ) return; // only members see/use it
	?>
	<style>
	/* Collage */
	.shat-img-wrap.shat-has-gallery{padding-top:0 !important;aspect-ratio:1/1;background:#0A0A0A;}
	.shat-img-wrap.shat-has-gallery .shat-collage{position:absolute;inset:0;width:100%;height:100%;}
	.shat-img-wrap.shat-has-gallery .shat-tile{z-index:5;}
	.shat-img-wrap.shat-has-gallery .shat-tile img{position:absolute !important;inset:0;width:100% !important;height:100% !important;pointer-events:none;}
	.shat-collage{display:grid;gap:3px;width:100%;aspect-ratio:1/1;background:#000;}
	.shat-collage-1{grid-template-columns:1fr;}
	.shat-collage-2{grid-template-columns:1fr 1fr;}
	.shat-collage-3{grid-template-columns:2fr 1fr;grid-template-rows:1fr 1fr;}
	.shat-collage-3 .shat-tile:nth-child(1){grid-row:span 2;}
	.shat-collage-4{grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr;}
	.shat-tile{position:relative;overflow:hidden;cursor:zoom-in;background:#111;}
	.shat-tile img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease;}
	.shat-tile:hover img{transform:scale(1.04);}
	.shat-more{position:absolute;inset:0;background:rgba(0,0,0,.55);color:#fff;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:600;letter-spacing:.5px;}
	.shat-photo-badge{position:absolute;top:12px;right:12px;background:rgba(0,0,0,.72);backdrop-filter:blur(6px);color:#EFEFEF;font-size:11px;font-weight:500;letter-spacing:.6px;padding:5px 10px;border-radius:20px;pointer-events:none;text-transform:uppercase;z-index:5;}
	/* ── Lightbox overlay ─────────────────────────────────────── */
	.shat-lb{
	  position:fixed;inset:0;
	  background:rgba(4,4,6,0);
	  z-index:99999;display:none;
	  align-items:center;justify-content:center;
	  touch-action:pan-y;
	  overflow:hidden;
	  transition:background-color .32s cubic-bezier(.32,.72,.35,1);
	}
	.shat-lb.open{display:flex;}
	.shat-lb.shat-lb-visible{background:rgba(4,4,6,.97);}

	/* ── Track: just a full-size clip region, no transform ── */
	.shat-lb-track{
	  position:absolute;
	  inset:0;
	  overflow:hidden;
	  z-index:1;
	}

	/* ── Each image centres itself; JS only changes translateX ── */
	.shat-lb-img{
	  position:absolute;
	  top:50%; left:50%;
	  transform:translate(-50%,-50%);
	  max-width:90vw; max-height:82vh;
	  object-fit:contain;
	  user-select:none; -webkit-user-drag:none;
	  border-radius:10px;
	  box-shadow:0 32px 80px rgba(0,0,0,.8);
	  will-change:transform,opacity,border-radius;
	}
	/* Apple Photos-style open/close: scales & fades from the tapped thumbnail's
	   position into the centred fullscreen frame, and reverses on close. */
	.shat-lb-img.shat-lb-opening{
	  transition:
	    transform .34s cubic-bezier(.22,.9,.32,1),
	    opacity .22s ease,
	    border-radius .34s cubic-bezier(.22,.9,.32,1);
	}
	.shat-lb-img.shat-lb-closing{
	  transition:
	    transform .26s cubic-bezier(.5,0,.75,0),
	    opacity .22s ease .04s,
	    border-radius .26s cubic-bezier(.5,0,.75,0);
	}


	/* ── Shared button reset (kills browser oval) ── */
	.shat-lb-nav{
	  -webkit-appearance:none;
	  appearance:none;
	  margin:0;padding:0;
	  line-height:1;
	  font-size:0;
	  text-indent:0;
	  display:flex;
	  align-items:center;
	  justify-content:center;
	  flex-shrink:0;
	  border-radius:50%;
	  background:rgba(255,255,255,.09);
	  border:1px solid rgba(255,255,255,.18);
	  box-shadow:
	    0 2px 8px rgba(0,0,0,.5),
	    inset 0 1px 0 rgba(255,255,255,.12);
	  cursor:pointer;
	  outline:none;
	  position:absolute;
	  opacity:0;
	  transition:
	    background .2s ease,
	    border-color .2s ease,
	    box-shadow .2s ease,
	    transform .15s ease,
	    opacity .28s ease .1s;
	}
	.shat-lb.shat-lb-visible .shat-lb-nav{opacity:1;}

	.shat-lb-nav{
	  top:50%;
	  width:48px;height:48px;
	  transform:translateY(-50%);
	}
	.shat-lb-nav:hover{
	  background:rgba(255,255,255,.18);
	  border-color:rgba(255,255,255,.32);
	  box-shadow:
	    0 4px 20px rgba(0,0,0,.6),
	    inset 0 1px 0 rgba(255,255,255,.18);
	  transform:translateY(-50%) scale(1.1);
	}
	.shat-lb-nav:active{
	  background:rgba(255,255,255,.10);
	  transform:translateY(-50%) scale(.93);
	  transition-duration:.08s;
	}
	.shat-lb-prev{left:24px;z-index:2;}
	.shat-lb-next{right:24px;z-index:2;}

	.shat-lb-nav svg{
	  display:block;flex-shrink:0;
	  width:16px;height:16px;
	  fill:none;
	  stroke:rgba(255,255,255,.9);
	  stroke-width:2;
	  stroke-linecap:round;
	  stroke-linejoin:round;
	}

	.shat-lb-count{
	  position:absolute;
	  top:20px;
	  left:50%;transform:translateX(-50%);
	  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,sans-serif;
	  font-size:11px;font-weight:500;letter-spacing:.9px;
	  color:rgba(255,255,255,.75);
	  background:rgba(255,255,255,.09);
	  border:1px solid rgba(255,255,255,.15);
	  box-shadow:0 2px 12px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.1);
	  padding:5px 18px;border-radius:100px;
	  white-space:nowrap;pointer-events:none;
	  opacity:0;
	  transition:opacity .28s ease .1s;
	}
	.shat-lb.shat-lb-visible .shat-lb-count{opacity:1;}

	/* Close button — always present, top-right, mirrors the nav styling.
	   Sits above the image and outside the swipe track so a tap can never
	   be read as a drag. */
	.shat-lb-close{
	  position:absolute;
	  top:16px; right:16px;
	  width:38px;height:38px;border-radius:50%;
	  display:flex;align-items:center;justify-content:center;
	  background:rgba(255,255,255,.09);
	  border:1px solid rgba(255,255,255,.15);
	  box-shadow:0 2px 12px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.1);
	  -webkit-backdrop-filter:blur(12px) saturate(160%);
	  backdrop-filter:blur(12px) saturate(160%);
	  cursor:pointer;padding:0;z-index:3;
	  opacity:0;
	  transition:opacity .28s ease .1s, background .2s ease, transform .2s cubic-bezier(.34,1.56,.64,1);
	  -webkit-tap-highlight-color:transparent;
	}
	.shat-lb.shat-lb-visible .shat-lb-close{opacity:1;}
	.shat-lb-close:hover{background:rgba(255,255,255,.16);transform:scale(1.08);}
	.shat-lb-close:active{background:rgba(255,255,255,.10);transform:scale(.93);transition-duration:.08s;}
	.shat-lb-close svg{
	  display:block;flex-shrink:0;
	  width:16px;height:16px;fill:none;
	  stroke:rgba(255,255,255,.92);
	  stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;
	}

	@media(max-width:640px){
	  .shat-lb-nav{width:42px;height:42px;}
	  .shat-lb-prev{left:10px;}
	  .shat-lb-next{right:10px;}
	  .shat-lb-close{top:12px;right:12px;width:40px;height:40px;}
	}
	</style>
	<script>
	(function(){
	  var lb, imgA, imgB, countEl, gallery=[], idx=0, animating=false;
	  var SVG_PREV='<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><polyline points="15 18 9 12 15 6"></polyline></svg>';
	  var SVG_NEXT='<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><polyline points="9 18 15 12 9 6"></polyline></svg>';
	  var SVG_CLOSE='<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
	  var EASE='cubic-bezier(0.25,0.46,0.45,0.94)';
	  var THRESH=40, RESIST=0.18, DUR=280;
	  var OPEN_DUR=340, CLOSE_DUR=260;

	  function setPos(el, x){
	    el.style.transition='none';
	    el.style.transform='translate(calc(-50% + '+x+'px),-50%)';
	  }
	  function animTo(el, x, ms){
	    el.style.transition='transform '+(ms||DUR)+'ms '+EASE;
	    el.style.transform='translate(calc(-50% + '+x+'px),-50%)';
	  }

	  /* ---- Scroll lock that compensates for scrollbar width, so removing
	     overflow:hidden on close never shifts/shakes the page layout ---- */
	  function lockScroll(){
	    var scrollbarW = window.innerWidth - document.documentElement.clientWidth;
	    if (scrollbarW > 0) {
	      var existingPad = parseFloat(getComputedStyle(document.body).paddingRight) || 0;
	      document.body.dataset.shatOrigPad = existingPad;
	      document.body.style.paddingRight = (existingPad + scrollbarW) + 'px';
	    }
	    document.body.style.overflow = 'hidden';
	  }
	  function unlockScroll(){
	    document.body.style.overflow = '';
	    var orig = document.body.dataset.shatOrigPad;
	    document.body.style.paddingRight = orig ? (orig + 'px') : '';
	    delete document.body.dataset.shatOrigPad;
	  }

	  /* ---- Apple Photos-style "grow from thumbnail" open/close ---- */
	  function getOriginRect(triggerEl){
	    if (!triggerEl) return null;
	    var imgEl = triggerEl.querySelector ? (triggerEl.querySelector('img') || triggerEl) : triggerEl;
	    var r = imgEl.getBoundingClientRect();
	    if (!r || (r.width===0 && r.height===0)) return null;
	    return r;
	  }

	  function playOpenAnim(triggerEl){
	    var rect = getOriginRect(triggerEl);
	    // Fallback: if we can't find a thumbnail rect, just fade/scale from center — still feels intentional.
	    var vw = window.innerWidth, vh = window.innerHeight;
	    var originX = rect ? (rect.left + rect.width/2) : vw/2;
	    var originY = rect ? (rect.top  + rect.height/2) : vh/2;
	    var originScale = rect ? Math.max(0.12, Math.min(rect.width, rect.height) / Math.max(vw, vh) * 1.4) : 0.4;

	    var dx = originX - vw/2;
	    var dy = originY - vh/2;

	    animating = true;
	    imgA.classList.remove('shat-lb-closing');
	    imgA.classList.add('shat-lb-opening');
	    imgA.style.opacity = '0';
	    imgA.style.borderRadius = '20px';
	    imgA.style.transition = 'none';
	    imgA.style.transform = 'translate(calc(-50% + '+dx+'px), calc(-50% + '+dy+'px)) scale('+originScale+')';
	    // Force reflow so the browser registers the start position before we animate to the end state.
	    void imgA.offsetWidth;

	    lb.classList.add('open');
	    lockScroll();

	    requestAnimationFrame(function(){
	      requestAnimationFrame(function(){
	        lb.classList.add('shat-lb-visible');
	        imgA.style.transition = 'transform '+OPEN_DUR+'ms cubic-bezier(.22,.9,.32,1), opacity 220ms ease, border-radius '+OPEN_DUR+'ms cubic-bezier(.22,.9,.32,1)';
	        imgA.style.opacity = '1';
	        imgA.style.borderRadius = '10px';
	        imgA.style.transform = 'translate(-50%,-50%) scale(1)';
	      });
	    });

	    setTimeout(function(){
	      imgA.classList.remove('shat-lb-opening');
	      imgA.style.transition = '';
	      animating = false;
	    }, OPEN_DUR + 30);
	  }

	  function playCloseAnim(triggerEl){
	    if (animating) return; // don't allow close to interrupt an in-progress open/navigate
	    var rect = getOriginRect(triggerEl);
	    var vw = window.innerWidth, vh = window.innerHeight;
	    var originX = rect ? (rect.left + rect.width/2) : vw/2;
	    var originY = rect ? (rect.top  + rect.height/2) : vh/2;
	    var originScale = rect ? Math.max(0.12, Math.min(rect.width, rect.height) / Math.max(vw, vh) * 1.4) : 0.4;
	    var dx = originX - vw/2;
	    var dy = originY - vh/2;

	    animating = true;
	    lb.classList.remove('shat-lb-visible');
	    imgA.classList.add('shat-lb-closing');
	    imgA.style.transition = 'transform '+CLOSE_DUR+'ms cubic-bezier(.5,0,.75,0), opacity 220ms ease, border-radius '+CLOSE_DUR+'ms cubic-bezier(.5,0,.75,0)';
	    imgA.style.opacity = '0';
	    imgA.style.borderRadius = '20px';
	    imgA.style.transform = 'translate(calc(-50% + '+dx+'px), calc(-50% + '+dy+'px)) scale('+originScale+')';

	    setTimeout(function(){
	      lb.classList.remove('open');
	      unlockScroll();
	      animating=false;
	      imgA.classList.remove('shat-lb-closing');
	      imgA.style.transition=''; imgA.style.opacity=''; imgA.style.borderRadius='';
	      setPos(imgA,0); setPos(imgB,0);
	      imgB.style.opacity='0';
	    }, CLOSE_DUR + 20);
	  }

	  var _lbTriggerEl = null; // remembers the tapped thumbnail for the close animation

	  var sw={on:false,x0:0,y0:0,dx:0,axis:'',dir:0,lx:0,lt:0,vx:0};

	  function build(){
	    lb=document.createElement('div'); lb.className='shat-lb';
	    lb.innerHTML=
	      '<div class="shat-lb-count"></div>'+
	      '<button class="shat-lb-nav shat-lb-prev" aria-label="Previous">'+SVG_PREV+'</button>'+
	      '<div class="shat-lb-track">'+
	        '<img class="shat-lb-img shat-img-a" draggable="false" alt="">'+
	        '<img class="shat-lb-img shat-img-b" draggable="false" alt="">'+
	      '</div>'+
	      '<button class="shat-lb-nav shat-lb-next" aria-label="Next">'+SVG_NEXT+'</button>'+
	      '<button class="shat-lb-close" aria-label="Close">'+SVG_CLOSE+'</button>';
	    document.body.appendChild(lb);
	    imgA=lb.querySelector('.shat-img-a');
	    imgB=lb.querySelector('.shat-img-b');
	    countEl=lb.querySelector('.shat-lb-count');

	    lb.addEventListener('click',function(e){
	      if(e.target===lb||e.target===lb.querySelector('.shat-lb-track')) close_();
	    });
	    lb.querySelector('.shat-lb-prev').addEventListener('click',function(e){
	      e.stopPropagation(); navigate(-1);
	    });
	    lb.querySelector('.shat-lb-next').addEventListener('click',function(e){
	      e.stopPropagation(); navigate(1);
	    });
	    lb.querySelector('.shat-lb-close').addEventListener('click',function(e){
	      e.stopPropagation(); close_();
	    });

	    lb.addEventListener('touchstart',function(e){
	      if(animating||e.touches.length!==1) return;
	      var t=e.touches[0];
	      sw.on=true; sw.axis=''; sw.dir=0; sw.dx=0; sw.vx=0;
	      sw.x0=sw.lx=t.clientX; sw.y0=t.clientY; sw.lt=Date.now();
	      setPos(imgA,0);
	      imgB.style.opacity='0';
	      setPos(imgB,0);
	    },{passive:true});

	    lb.addEventListener('touchmove',function(e){
	      if(!sw.on||e.touches.length!==1) return;
	      var t=e.touches[0];
	      var dx=t.clientX-sw.x0, dy=t.clientY-sw.y0;
	      if(!sw.axis){
	        if(Math.abs(dx)<6&&Math.abs(dy)<6) return;
	        sw.axis=Math.abs(dx)>=Math.abs(dy)?'h':'v';
	      }
	      if(sw.axis!=='h') return;
	      e.preventDefault();
	      var W=window.innerWidth;
	      var newDir=dx<0?1:-1;
	      if(newDir!==sw.dir){
	        sw.dir=newDir;
	        var ni=idx+newDir;
	        if(ni>=0&&ni<gallery.length){
	          imgB.src=gallery[ni];
	          setPos(imgB, newDir*W);
	          imgB.style.opacity='1';
	        } else {
	          imgB.style.opacity='0';
	        }
	      }
	      var atEdge=(dx>0&&idx===0)||(dx<0&&idx===gallery.length-1);
	      sw.dx=atEdge?dx*RESIST:dx;
	      setPos(imgA, sw.dx);
	      if(imgB.style.opacity==='1') setPos(imgB, sw.dir*W+sw.dx);
	      var now=Date.now(), dt=now-sw.lt;
	      if(dt>0) sw.vx=(t.clientX-sw.lx)/dt;
	      sw.lx=t.clientX; sw.lt=now;
	    },{passive:false});

	    lb.addEventListener('touchend',function(){
	      if(!sw.on) return; sw.on=false;
	      var dx=sw.dx, vx=sw.vx, dir=dx<0?1:-1;
	      var commit=sw.axis==='h'&&(Math.abs(dx)>=THRESH||Math.abs(vx)>0.3);
	      var ni=idx+dir;
	      if(commit&&ni>=0&&ni<gallery.length){
	        var spd=Math.min(Math.abs(vx),2.5), dur=Math.round(DUR-spd*50);
	        var W=window.innerWidth;
	        animating=true;
	        animTo(imgA, -dir*W, dur);
	        animTo(imgB, 0, dur);
	        setTimeout(function(){
	          idx=ni;
	          imgA.src=gallery[idx];
	          setPos(imgA,0);
	          imgB.style.opacity='0';
	          setPos(imgB,0);
	          countEl.textContent=(idx+1)+' / '+gallery.length;
	          animating=false;
	        }, dur+20);
	      } else {
	        animTo(imgA, 0, 260);
	        if(imgB.style.opacity==='1') animTo(imgB, sw.dir*window.innerWidth, 260);
	      }
	      sw.dx=0; sw.axis=''; sw.dir=0; sw.vx=0;
	    },{passive:true});
	  }

	  function navigate(dir){
	    if(animating) return;
	    var ni=idx+dir;
	    if(ni<0||ni>=gallery.length) return;
	    var W=window.innerWidth;
	    animating=true;
	    imgA.classList.remove('shat-lb-opening','shat-lb-closing');
	    imgA.style.opacity=''; imgA.style.borderRadius='';
	    imgB.src=gallery[ni];
	    imgB.style.opacity='1';
	    setPos(imgA, 0);
	    setPos(imgB, dir*W);
	    void lb.querySelector('.shat-lb-track').offsetWidth;
	    animTo(imgA, -dir*W, DUR);
	    animTo(imgB, 0, DUR);
	    setTimeout(function(){
	      idx=ni;
	      imgA.src=gallery[idx];
	      setPos(imgA,0);
	      imgB.style.opacity='0';
	      setPos(imgB,0);
	      countEl.textContent=(idx+1)+' / '+gallery.length;
	      animating=false;
	    }, DUR+20);
	  }

	  var hiResGen = 0; // guards against a stale full-res load finishing after the user has already navigated away

	  function render(thumbSrc){
	    animating=false;
	    var myGen = ++hiResGen;
	    var target = gallery[idx];

	    // 1) Paint instantly using the tapped thumbnail's own (already-decoded) src,
	    //    if we have one and it differs from the full-res target. This guarantees
	    //    the open animation has real pixels to animate on the very next frame,
	    //    regardless of how slow the network is.
	    if (thumbSrc && thumbSrc !== target) {
	      imgA.src = thumbSrc;
	    } else {
	      imgA.src = target;
	    }
	    setPos(imgA,0);
	    imgB.style.opacity='0';
	    setPos(imgB,0);
	    countEl.textContent=(idx+1)+' / '+gallery.length;
	    countEl.style.display=gallery.length<2?'none':'';
	    var isTouch=window.matchMedia('(pointer:coarse)').matches;
	    lb.querySelector('.shat-lb-prev').style.display=(isTouch||gallery.length<2)?'none':'';
	    lb.querySelector('.shat-lb-next').style.display=(isTouch||gallery.length<2)?'none':'';

	    // 2) Quietly preload the full-resolution image in the background. The moment
	    //    it's decoded, swap imgA.src to it — the browser keeps showing the last
	    //    painted frame (the thumbnail) until the new bitmap is ready, so this
	    //    swap never causes a flash or blank frame. Feels like the photo
	    //    "sharpens" rather than "loads".
	    if (imgA.src !== target) {
	      var hi = new Image();
	      hi.onload = function(){
	        if (myGen !== hiResGen) return; // user moved on already — discard
	        var apply = function(){
	          if (myGen !== hiResGen) return;
	          imgA.src = target;
	        };
	        if (hi.decode) { hi.decode().then(apply).catch(apply); }
	        else { apply(); }
	      };
	      hi.src = target;
	    }
	  }

	  function close_(){
	    playCloseAnim(_lbTriggerEl);
	  }

	  document.addEventListener('keydown',function(e){
	    if(!lb||!lb.classList.contains('open')) return;
	    if(e.key==='Escape')     close_();
	    if(e.key==='ArrowRight') navigate(1);
	    if(e.key==='ArrowLeft')  navigate(-1);
	  });

	  window.shatLightbox=function(wrap,startIdx,triggerEl){
	    if(!lb) build();
	    try{ gallery=JSON.parse(wrap.getAttribute('data-gallery')||'[]'); }catch(e){ gallery=[]; }
	    if(!gallery.length) return;
	    idx=Math.max(0,Math.min(startIdx|0,gallery.length-1));
	    // Grab the tapped thumbnail's own <img src> (already decoded and on screen)
	    // so render() can paint instantly instead of waiting on the full-res load.
	    var thumbEl = (triggerEl && triggerEl.querySelector) ? triggerEl.querySelector('img') : null;
	    var thumbSrc = thumbEl ? thumbEl.currentSrc || thumbEl.src : null;
	    render(thumbSrc);
	    _lbTriggerEl = triggerEl || wrap;
	    playOpenAnim(_lbTriggerEl);
	  };
	})();
	</script>
	<?php
}, 99 );

// ============================================================
// IN-APP ACCESS REQUEST SHEET (replaces mailto: links)
// ============================================================
add_action( 'wp_footer', function() {
	if ( ! is_user_logged_in() ) return; // works for pending members too, not just approved
	?>
	<style>
	.shat-req-overlay{
	  position:fixed; inset:0; z-index:100000;
	  background:rgba(4,4,6,0);
	  display:none; align-items:flex-end; justify-content:center;
	  transition:background-color .32s cubic-bezier(.32,.72,.35,1);
	}
	.shat-req-overlay.open{display:flex;}
	.shat-req-overlay.shat-req-visible{background:rgba(4,4,6,.72);}
	@media(min-width:641px){.shat-req-overlay{align-items:center;}}

	.shat-req-sheet{
	  width:100%; max-width:440px;
	  background:var(--card,#1C1C1C); border:1px solid var(--line,rgba(255,255,255,.08));
	  border-radius:24px 24px 0 0;
	  padding:28px 24px 28px;
	  transform:translateY(100%);
	  transition:transform .38s cubic-bezier(.22,.9,.32,1);
	  font-family:var(--font,"Zilla Slab",Georgia,serif);
	  max-height:86vh; overflow-y:auto;
	}
	.shat-req-overlay.shat-req-visible .shat-req-sheet{transform:translateY(0);}
	@media(min-width:641px){
	  .shat-req-sheet{border-radius:20px; transform:translateY(12px) scale(.97); opacity:0; transition:transform .32s cubic-bezier(.22,.9,.32,1), opacity .28s ease;}
	  .shat-req-overlay.shat-req-visible .shat-req-sheet{transform:translateY(0) scale(1); opacity:1;}
	}

	.shat-req-handle{width:36px;height:4px;background:rgba(255,255,255,.18);border-radius:3px;margin:0 auto 20px;}
	@media(min-width:641px){.shat-req-handle{display:none;}}

	.shat-req-icon{
	  width:44px;height:44px;border-radius:50%;
	  background:linear-gradient(135deg,rgba(217,169,172,.20),rgba(217,169,172,.08));
	  border:1px solid rgba(217,169,172,.3);
	  display:flex;align-items:center;justify-content:center;color:#D9A9AC;
	  margin-bottom:16px;
	}
	.shat-req-icon svg{width:19px;height:19px;}

	.shat-req-title{font-size:19px;font-weight:600;color:var(--text,#F2F2F2);margin:0 0 6px;letter-spacing:-.2px;}
	.shat-req-sub{font-size:13.5px;color:var(--sec,#9A9A9A);line-height:1.55;margin:0 0 20px;}

	.shat-req-textarea{
	  width:100%; min-height:84px; padding:12px 14px; resize:vertical;
	  background:rgba(255,255,255,.06); border:1.5px solid rgba(255,255,255,.1);
	  border-radius:12px; font-family:inherit; font-size:14px; line-height:1.5;
	  color:#F2F2F2 !important;
	  -webkit-text-fill-color:#F2F2F2;
	  caret-color:#D9A9AC;
	  outline:none; transition:border-color .2s,background .2s; margin-bottom:16px;
	}
	.shat-req-textarea:focus{
	  border-color:rgba(217,169,172,.55);background:rgba(255,255,255,.09);
	  color:#F2F2F2 !important;
	  -webkit-text-fill-color:#F2F2F2;
	}
	.shat-req-textarea::placeholder{color:rgba(255,255,255,.28);opacity:1;}

	.shat-req-actions{display:flex; gap:10px;}
	.shat-req-cancel{
	  flex:1; padding:13px; background:transparent; border:1px solid var(--line,rgba(255,255,255,.12));
	  border-radius:12px; color:var(--sec,#9A9A9A); font-family:inherit; font-size:14px; font-weight:500;
	  cursor:pointer; transition:background .2s,color .2s;
	}
	.shat-req-cancel:hover{background:rgba(255,255,255,.05); color:#F2F2F2;}
	.shat-req-send{
	  flex:2; padding:13px; background:var(--gold,#D9A9AC); border:none;
	  border-radius:12px; color:#141414; font-family:inherit; font-size:14px; font-weight:600;
	  cursor:pointer; transition:opacity .2s,transform .2s cubic-bezier(.34,1.56,.64,1);
	}
	.shat-req-send:hover:not(:disabled){opacity:.9;}
	.shat-req-send:active:not(:disabled){transform:scale(.97);}
	.shat-req-send:disabled{opacity:.5;cursor:default;}

	.shat-req-success{display:none; text-align:center; padding:12px 0 4px;}
	.shat-req-success.show{display:block;}
	.shat-req-success .chk{
	  width:52px;height:52px;border-radius:50%;margin:0 auto 16px;
	  background:linear-gradient(135deg,rgba(217,169,172,.25),rgba(217,169,172,.1));
	  border:1px solid rgba(217,169,172,.4);
	  display:flex;align-items:center;justify-content:center;color:#D9A9AC;
	  animation:shatReqCheckIn .4s cubic-bezier(.34,1.56,.64,1) both;
	}
	@keyframes shatReqCheckIn{from{opacity:0;transform:scale(.7);}to{opacity:1;transform:scale(1);}}
	.shat-req-success .chk svg{width:22px;height:22px;}
	.shat-req-success h3{font-size:17px;font-weight:600;color:var(--text,#F2F2F2);margin:0 0 6px;}
	.shat-req-success p{font-size:13.5px;color:var(--sec,#9A9A9A);line-height:1.5;margin:0 0 20px;}
	.shat-req-contact-note{
	  font-size:13px; line-height:1.6; color:#E8C4C6;
	  background:linear-gradient(135deg,rgba(217,169,172,.14),rgba(217,169,172,.06));
	  border:1px solid rgba(217,169,172,.30);
	  border-radius:12px; padding:12px 16px; margin:0 0 18px; text-align:left;
	}
	.shat-req-contact-note strong{color:#F5F5F5;}
	.shat-req-success button{
	  padding:12px 28px; background:transparent; border:1px solid var(--line,rgba(255,255,255,.14));
	  border-radius:12px; color:var(--text,#F2F2F2); font-family:inherit; font-size:14px; font-weight:500; cursor:pointer;
	}
	.shat-req-pay-btn{
	  display:inline-block; width:100%; box-sizing:border-box; text-align:center;
	  padding:13px 28px; margin-bottom:10px;
	  background:var(--gold,#D9A9AC); color:#141414 !important; text-decoration:none;
	  border-radius:12px; font-family:inherit; font-size:14px; font-weight:600;
	  transition:opacity .2s ease, transform .2s cubic-bezier(.34,1.56,.64,1);
	}
	.shat-req-pay-btn:hover{opacity:.9;}
	.shat-req-pay-btn:active{transform:scale(.97);}

	/* Triggers: turn the old mailto: links into buttons that look identical */
	.shat-req-trigger{cursor:pointer;}
	</style>

	<div class="shat-req-overlay" id="shat-req-overlay">
	  <div class="shat-req-sheet" id="shat-req-sheet">
	    <div class="shat-req-handle"></div>

	    <div id="shat-req-form-state">
	      <div class="shat-req-icon" aria-hidden="true">
	        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v12H7l-3 4V4z"/></svg>
	      </div>
	      <h3 class="shat-req-title" id="shat-req-title">Send a request</h3>
	      <p class="shat-req-sub" id="shat-req-sub">We'll get back to you by email shortly.</p>
	      <textarea class="shat-req-textarea" id="shat-req-message" placeholder="Add a note (optional)&hellip;" maxlength="600"></textarea>
	      <div class="shat-req-actions">
	        <button type="button" class="shat-req-cancel" onclick="shatCloseRequestSheet()">Cancel</button>
	        <button type="button" class="shat-req-send" id="shat-req-send-btn" onclick="shatSubmitRequestSheet()">Send</button>
	      </div>
	    </div>

	    <div class="shat-req-success" id="shat-req-success-state">
	      <div class="chk">
	        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
	      </div>
	      <h3>Request sent</h3>
	      <p id="shat-req-success-text">We've received it and will follow up soon.</p>
	      <div class="shat-req-contact-note" id="shat-req-contact-note">
	        Send a message to <?php echo shat_contact_line(); ?> at the top of this page for the quickest response &mdash; I'll restore your access ASAP.
	      </div>
	      <a href="#" target="_blank" rel="noopener" id="shat-req-pay-btn" class="shat-req-pay-btn" style="display:none;">Pay with Crypto (USDT) &rarr;</a>
	      <button type="button" onclick="shatCloseRequestSheet()">Done</button>
	    </div>
	  </div>
	</div>

	<script>
	(function(){
	  var reqOverlay, reqSheet, reqTitleEl, reqSubEl, reqMsgEl, reqSendBtn, reqFormState, reqSuccessState;
	  var reqType = 'access', reqPid = 0;

	  /* 2.3.0 — copy points at the built-in chat instead of Telegram/Snapchat. */
	  var REQ_COPY = {
	    access: { title: 'Want access?', sub: 'Send me a message here for the quickest response.' },
	    renew:  { title: 'Want to renew access?', sub: "Your feed access has ended. Message me here and I'll restore it ASAP." },
	    unlock: { title: 'Want to unlock this photo?', sub: 'This photo is locked. Message me here for the passcode.' }
	  };

	  function ready(){
	    reqOverlay      = document.getElementById('shat-req-overlay');
	    reqSheet        = document.getElementById('shat-req-sheet');
	    reqTitleEl      = document.getElementById('shat-req-title');
	    reqSubEl        = document.getElementById('shat-req-sub');
	    reqMsgEl        = document.getElementById('shat-req-message');
	    reqSendBtn      = document.getElementById('shat-req-send-btn');
	    reqFormState    = document.getElementById('shat-req-form-state');
	    reqSuccessState = document.getElementById('shat-req-success-state');

	    reqOverlay.addEventListener('click', function(e){
	      if (e.target === reqOverlay) shatCloseRequestSheet();
	    });
	  }
	  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();

	  window.shatOpenRequestSheet = function(type, pid){
	    reqType = type || 'access';
	    reqPid  = pid || 0;
	    var copy = REQ_COPY[reqType] || REQ_COPY.access;
	    reqTitleEl.textContent = copy.title;
	    reqSubEl.textContent   = copy.sub;
	    reqMsgEl.value = '';
	    reqFormState.style.display = '';
	    reqSuccessState.classList.remove('show');
	    reqSendBtn.disabled = false;
	    reqSendBtn.textContent = 'Send';

	    reqOverlay.classList.add('open');
	    document.body.style.overflow = 'hidden';
	    requestAnimationFrame(function(){
	      requestAnimationFrame(function(){ reqOverlay.classList.add('shat-req-visible'); });
	    });
	  };

	  window.shatCloseRequestSheet = function(){
	    reqOverlay.classList.remove('shat-req-visible');
	    document.body.style.overflow = '';
	    setTimeout(function(){ reqOverlay.classList.remove('open'); }, 320);
	  };

	  window.shatSubmitRequestSheet = function(){
	    if (reqSendBtn.disabled) return;
	    reqSendBtn.disabled = true;
	    reqSendBtn.textContent = 'Sending\u2026';
	    jQuery.post(shatAjax.ajaxurl, {
	      action: 'shat_submit_access_request',
	      nonce: shatAjax.nonce,
	      type: reqType,
	      photo_id: reqPid,
	      message: reqMsgEl.value
	    }, function(r){
	      if (r && r.success) {
	        reqFormState.style.display = 'none';
	        reqSuccessState.classList.add('show');
	        var payBtn = document.getElementById('shat-req-pay-btn');
	        var successText = document.getElementById('shat-req-success-text');
	        if (r.data && r.data.invoice_url) {
	          payBtn.href = r.data.invoice_url;
	          payBtn.style.display = '';
	          successText.textContent = 'Complete payment below to get access right away.';
	        } else {
	          payBtn.style.display = 'none';
	          successText.textContent = 'We\'ve received your request.';
	        }
	      } else {
	        reqSendBtn.disabled = false;
	        reqSendBtn.textContent = 'Send';
	      }
	    }).fail(function(){
	      reqSendBtn.disabled = false;
	      reqSendBtn.textContent = 'Send';
	    });
	  };

	  document.addEventListener('keydown', function(e){
	    if (e.key === 'Escape' && reqOverlay && reqOverlay.classList.contains('open')) shatCloseRequestSheet();
	  });
	})();
	</script>
	<?php
}, 99 );
// ============================================================
// SIGN-OUT CONFIRMATION DIALOG
// Intercepts any link tagged .js-shat-logout and asks first.
// Progressive enhancement: with JS off the links still work.
// ============================================================
add_action( 'wp_footer', function() {
	if ( ! is_user_logged_in() ) return;
	?>
	<style>
	.shat-lo-overlay{
	  position:fixed; inset:0; z-index:100001;
	  background:rgba(4,4,6,0);
	  display:none; align-items:center; justify-content:center;
	  padding:24px;
	  -webkit-backdrop-filter:blur(0px); backdrop-filter:blur(0px);
	  transition:background .26s ease, backdrop-filter .26s ease;
	  -webkit-tap-highlight-color:transparent;
	}
	.shat-lo-overlay.shat-lo-open{ display:flex; }
	.shat-lo-overlay.shat-lo-visible{
	  background:rgba(4,4,6,.62);
	  -webkit-backdrop-filter:blur(8px); backdrop-filter:blur(8px);
	}
	.shat-lo-card{
	  width:100%; max-width:320px;
	  background:linear-gradient(168deg,#201B1E 0%,#171416 100%);
	  border:.5px solid rgba(255,255,255,.10);
	  border-radius:22px;
	  box-shadow:0 24px 70px rgba(0,0,0,.62), inset 0 1px 0 rgba(255,255,255,.06);
	  padding:26px 22px 18px;
	  text-align:center;
	  font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Inter","Segoe UI",Helvetica,Arial,sans-serif;
	  -webkit-font-smoothing:antialiased;
	  transform:translateY(14px) scale(.96); opacity:0;
	  transition:transform .3s cubic-bezier(.22,1.1,.36,1), opacity .24s ease;
	}
	.shat-lo-overlay.shat-lo-visible .shat-lo-card{ transform:translateY(0) scale(1); opacity:1; }

	.shat-lo-icon{
	  width:52px; height:52px; margin:0 auto 14px;
	  border-radius:50%;
	  background:linear-gradient(168deg,#3A2E33,#2A2226);
	  border:.5px solid rgba(233,175,190,.16);
	  display:flex; align-items:center; justify-content:center;
	}
	.shat-lo-icon svg{ width:22px; height:22px; stroke:#E9AFBE; fill:none; stroke-width:1.9; }

	.shat-lo-title{
	  font-family:inherit !important;
	  font-size:17.5px !important; font-weight:650 !important; letter-spacing:-.02em !important;
	  color:#F5F5F6 !important; margin:0 0 6px !important; line-height:1.3 !important;
	  text-transform:none !important;
	}
	.shat-lo-sub{
	  font-family:inherit !important;
	  font-size:13.5px !important; line-height:1.45 !important; letter-spacing:-.006em !important;
	  color:#9A9A9C !important; margin:0 0 20px !important;
	}

	.shat-lo-actions{ display:flex; flex-direction:column; gap:8px; }
	.shat-lo-btn{
	  width:100%; padding:13px 16px; border-radius:14px;
	  font-family:inherit; font-size:15px; font-weight:600; letter-spacing:-.01em;
	  border:none; cursor:pointer; outline:none;
	  -webkit-tap-highlight-color:transparent;
	  transition:transform .18s cubic-bezier(.34,1.5,.64,1), background .2s ease, opacity .2s ease;
	}
	.shat-lo-btn:active{ transform:scale(.97); }
	.shat-lo-confirm{
	  background:linear-gradient(168deg,#C96A78 0%,#B45765 100%);
	  color:#FFF4F6;
	  box-shadow:0 2px 10px -4px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.16);
	}
	.shat-lo-confirm:hover{ background:linear-gradient(168deg,#D27482 0%,#BD606E 100%); }
	.shat-lo-cancel{
	  background:#262427 !important;
	  color:#E8E8EA !important;
	  border:.5px solid rgba(255,255,255,.11) !important;
	}
	.shat-lo-cancel:hover{ background:#302D31 !important; }

	@media (prefers-reduced-motion:reduce){
	  .shat-lo-overlay, .shat-lo-card, .shat-lo-btn{ transition:none !important; }
	}
	</style>

	<div class="shat-lo-overlay" id="shat-lo-overlay" role="dialog" aria-modal="true"
	     aria-labelledby="shat-lo-title" aria-describedby="shat-lo-sub">
	  <div class="shat-lo-card" id="shat-lo-card">
	    <div class="shat-lo-icon" aria-hidden="true">
	      <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
	        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
	        <polyline points="16 17 21 12 16 7"/>
	        <line x1="21" y1="12" x2="9" y2="12"/>
	      </svg>
	    </div>
	    <h3 class="shat-lo-title" id="shat-lo-title">Sign out?</h3>
	    <p class="shat-lo-sub" id="shat-lo-sub">You'll need to sign in again to view Shatania's feed and messages.</p>
	    <div class="shat-lo-actions">
	      <button type="button" class="shat-lo-btn shat-lo-confirm" id="shat-lo-confirm">Sign Out</button>
	      <button type="button" class="shat-lo-btn shat-lo-cancel" id="shat-lo-cancel">Cancel</button>
	    </div>
	  </div>
	</div>

	<script>
	(function () {
	  var overlay = document.getElementById('shat-lo-overlay');
	  var confirmBtn = document.getElementById('shat-lo-confirm');
	  var cancelBtn = document.getElementById('shat-lo-cancel');
	  var card = document.getElementById('shat-lo-card');
	  if (!overlay || !confirmBtn || !cancelBtn) return;

	  var pendingUrl = null;
	  var lastFocus = null;

	  function openDialog(url) {
	    pendingUrl = url;
	    lastFocus = document.activeElement;
	    overlay.classList.add('shat-lo-open');
	    // Force a reflow so the transition runs from the closed state.
	    void overlay.offsetWidth;
	    overlay.classList.add('shat-lo-visible');
	    document.body.style.overflow = 'hidden';
	    setTimeout(function () { cancelBtn.focus(); }, 60);
	  }

	  function closeDialog() {
	    pendingUrl = null;
	    overlay.classList.remove('shat-lo-visible');
	    document.body.style.overflow = '';
	    setTimeout(function () {
	      overlay.classList.remove('shat-lo-open');
	    }, 260);
	    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
	  }

	  // Intercept every tagged sign-out link.
	  document.addEventListener('click', function (e) {
	    var t = e.target;
	    if (t && t.nodeType === 3) t = t.parentNode; // text node -> element
	    var link = (t && t.closest) ? t.closest('.js-shat-logout') : null;
	    if (!link) return;
	    var href = link.getAttribute('href');
	    if (!href) return;
	    e.preventDefault();
	    e.stopPropagation();
	    // If the tap came from inside the profile sheet, dismiss it first so
	    // the confirm dialog is not stacked on top of another overlay.
	    if (typeof window.shatCloseMeSheet === 'function' &&
	        link.closest('.shat-me-sheet')) {
	      window.shatCloseMeSheet();
	    }
	    openDialog(href);
	  }, true);

	  confirmBtn.addEventListener('click', function () {
	    if (!pendingUrl) return closeDialog();
	    confirmBtn.disabled = true;
	    confirmBtn.textContent = 'Signing out…';
	    window.location.href = pendingUrl;
	  });

	  cancelBtn.addEventListener('click', closeDialog);

	  // Tap the scrim (but not the card) to dismiss.
	  overlay.addEventListener('click', function (e) {
	    if (card && card.contains(e.target)) return;
	    closeDialog();
	  });

	  // Esc to dismiss; trap Tab inside the dialog while it's open.
	  document.addEventListener('keydown', function (e) {
	    if (!overlay.classList.contains('shat-lo-open')) return;
	    if (e.key === 'Escape') { e.preventDefault(); closeDialog(); return; }
	    if (e.key === 'Tab') {
	      var f = [cancelBtn, confirmBtn];
	      var i = f.indexOf(document.activeElement);
	      e.preventDefault();
	      f[(i + (e.shiftKey ? f.length - 1 : 1)) % f.length].focus();
	    }
	  });
	})();
	</script>
	<?php
}, 100 );

// ============================================================
// MEMBER PROFILE SHEET (greeting, candy streak, membership)
// ============================================================
add_action( 'wp_footer', function() {
	if ( ! is_user_logged_in() ) return;
	if ( ! shat_is_feed_page() ) return;

	$uid   = get_current_user_id();
	$me    = wp_get_current_user();
	$state = shat_streak_get( $uid );

	$first = $me->first_name ? $me->first_name : $me->display_name;
	$first = trim( preg_split( '/\s+/', trim( $first ) )[0] );

	// Membership dates
	$joined_ts   = strtotime( $me->user_registered );
	$joined_disp = $joined_ts ? date_i18n( 'j F Y', $joined_ts ) : '';
	$days_member = $joined_ts ? max( 1, (int) floor( ( current_time( 'timestamp' ) - $joined_ts ) / DAY_IN_SECONDS ) ) : 0;

	// Access window
	$expiry_raw = get_user_meta( $uid, 'shat_feed_expiry', true );
	$access_state = shat_feed_access_state( $uid );
	$days_left  = 0;
	if ( $access_state === 'active' && $expiry_raw ) {
		$days_left = max( 0, ceil( ( strtotime( $expiry_raw ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) );
	}

	?>
	<div class="shat-me-overlay shat-root" id="shat-me-overlay" role="dialog"
	     aria-modal="true" aria-labelledby="shat-me-title">
	  <div class="shat-me-sheet" id="shat-me-sheet">
	    <div class="shat-me-grab" aria-hidden="true"></div>

	    <!-- Greeting -->
	    <header class="shat-me-head">
	      <div class="shat-me-avatar" aria-hidden="true">
	        <span class="shat-me-initial"><?php echo esc_html( mb_strtoupper( mb_substr( $first, 0, 1 ) ) ); ?></span>
	      </div>
	      <p class="shat-me-greet" id="shat-me-greet">Hello</p>
	      <h3 class="shat-me-name" id="shat-me-title"><?php echo esc_html( $first ); ?></h3>
	      <?php if ( $joined_disp ) : ?>
	        <p class="shat-me-since">Member since <?php echo esc_html( $joined_disp ); ?></p>
	      <?php endif; ?>
	    </header>

	    <!-- Candy streak card -->
	    <section class="shat-me-card shat-me-streak" aria-label="Candy streak">
	      <div class="shat-me-streak-top">
	        <span class="shat-me-candy<?php echo $state['count'] > 0 ? ' lit' : ''; ?>" aria-hidden="true"><?php echo shat_candy_svg(); ?></span>
	        <div class="shat-me-streak-num">
	          <b id="shat-me-streak-count"><?php echo intval( $state['count'] ); ?></b>
	          <span id="shat-me-streak-word"><?php echo $state['count'] === 1 ? 'day streak' : 'day streak'; ?></span>
	        </div>
	        <button type="button" class="shat-me-info" id="shat-me-info" aria-label="How candy works">
	          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
	               stroke-linecap="round" stroke-linejoin="round">
	            <circle cx="12" cy="12" r="9"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
	          </svg>
	        </button>
	      </div>

	      <div class="shat-me-bar"><span id="shat-me-bar-fill"></span></div>
	      <div class="shat-me-bar-label">
	        <span id="shat-me-bar-left">Today's progress</span>
	        <span id="shat-me-bar-right"></span>
	      </div>

	      <div class="shat-me-rules" id="shat-me-rules" hidden>
	        <ul>
	          <li>Spend <strong><?php echo intval( $state['minutes'] ); ?> minutes</strong> on the feed in a day to earn that day's candy.</li>
	          <li>Return each day to keep the run alive. Your streak counts consecutive days.</li>
	          <li>Skip a day and your streak resets to zero.</li>
	          <li>Only active time counts. The timer pauses when you switch away.</li>
	        </ul>
	      </div>
	    </section>

	    <!-- Stats -->
	    <div class="shat-me-stats">
	      <div class="shat-me-stat">
	        <b id="shat-me-best"><?php echo intval( $state['best'] ); ?></b>
	        <span>Best streak</span>
	      </div>
	      <div class="shat-me-stat">
	        <b><?php echo intval( $days_member ); ?></b>
	        <span>Days here</span>
	      </div>
	      <?php if ( $access_state === 'active' ) : ?>
	      <div class="shat-me-stat">
	        <b><?php echo intval( $days_left ); ?></b>
	        <span>Days left</span>
	      </div>
	      <?php endif; ?>
	    </div>

	    <!-- Membership -->
	    <section class="shat-me-card shat-me-rows" aria-label="Membership">
	      <div class="shat-me-row">
	        <span class="shat-me-row-k">Name</span>
	        <span class="shat-me-row-v"><?php echo esc_html( $me->display_name ); ?></span>
	      </div>
	      <div class="shat-me-row">
	        <span class="shat-me-row-k">Email</span>
	        <span class="shat-me-row-v"><?php echo esc_html( $me->user_email ); ?></span>
	      </div>
	      <div class="shat-me-row">
	        <span class="shat-me-row-k">Access</span>
	        <span class="shat-me-row-v">
	          <?php if ( $access_state === 'unlimited' ) : ?>
	            <span class="shat-me-tag ok">Unlimited</span>
	          <?php elseif ( $access_state === 'active' ) : ?>
	            <span class="shat-me-tag ok"><?php echo intval( $days_left ); ?> day<?php echo $days_left == 1 ? '' : 's'; ?> left</span>
	          <?php else : ?>
	            <span class="shat-me-tag off">Expired</span>
	          <?php endif; ?>
	        </span>
	      </div>
	    </section>

	    <a href="<?php echo esc_url( wp_logout_url( shat_page_url( 'shatania-login' ) ) ); ?>"
	       class="shat-me-signout js-shat-logout">
	      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
	           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
	        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
	      </svg>
	      <span>Sign Out</span>
	    </a>

	    <button type="button" class="shat-me-close" id="shat-me-close">Done</button>
	  </div>
	</div>

	<div class="shat-candy-toast shat-root" id="shat-candy-toast" role="status" aria-live="polite">
	  <?php echo shat_candy_svg(); ?>
	  <span id="shat-candy-toast-text">You earned a candy today</span>
	</div>

	<script>
	(function () {
	  var GOAL    = <?php echo intval( $state['goal_seconds'] ); ?>;
	  var MINUTES = <?php echo intval( $state['minutes'] ); ?>;
	  var watched = <?php echo intval( $state['seconds'] ); ?>;
	  var earned  = <?php echo $state['earned_today'] ? 'true' : 'false'; ?>;
	  var count   = <?php echo intval( $state['count'] ); ?>;
	  var best    = <?php echo intval( $state['best'] ); ?>;

	  var overlay  = document.getElementById('shat-me-overlay');
	  var sheet    = document.getElementById('shat-me-sheet');
	  var closeBtn = document.getElementById('shat-me-close');
	  var infoBtn  = document.getElementById('shat-me-info');
	  var rules    = document.getElementById('shat-me-rules');
	  var meBtn    = document.getElementById('shat-me-btn');
	  var toast    = document.getElementById('shat-candy-toast');
	  var toastTxt = document.getElementById('shat-candy-toast-text');

	  /* Greeting from the visitor's own clock. */
	  var greetEl = document.getElementById('shat-me-greet');
	  function paintGreeting() {
	    if (!greetEl) return;
	    var h = new Date().getHours();
	    greetEl.textContent =
	      h < 5  ? 'Up late,' :
	      h < 12 ? 'Good morning,' :
	      h < 17 ? 'Good afternoon,' :
	      h < 22 ? 'Good evening,' : 'Good night,';
	  }

	  function localDay() {
	    var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
	    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
	  }

	  function paint() {
	    var pct  = Math.min(100, Math.round((watched / GOAL) * 100));
	    var fill = document.getElementById('shat-me-bar-fill');
	    var l    = document.getElementById('shat-me-bar-left');
	    var r    = document.getElementById('shat-me-bar-right');
	    if (fill) fill.style.width = (earned ? 100 : pct) + '%';
	    if (l) l.textContent = earned ? "Today's candy earned" : "Today's progress";
	    if (r) {
	      if (earned) {
	        r.innerHTML = '<strong>Done</strong>';
	      } else {
	        var m = Math.floor(watched / 60), sec = watched % 60;
	        r.innerHTML = '<strong>' + m + 'm ' + (sec < 10 ? '0' : '') + sec + 's</strong> / ' + MINUTES + 'm';
	      }
	    }
	    var c = document.getElementById('shat-me-streak-count');
	    var w = document.getElementById('shat-me-streak-word');
	    var b = document.getElementById('shat-me-best');
	    if (c) c.textContent = count;
	    if (w) w.textContent = count === 1 ? 'day streak' : 'day streak';
	    if (b) b.textContent = Math.max(best, count);
	  }

	  function openSheet() {
	    if (!overlay) return;
	    paintGreeting();
	    paint();
	    overlay.classList.add('shat-me-open');
	    void overlay.offsetWidth;
	    overlay.classList.add('shat-me-visible');
	    document.body.style.overflow = 'hidden';
	  }
	  function closeSheet() {
	    if (!overlay) return;
	    overlay.classList.remove('shat-me-visible');
	    document.body.style.overflow = '';
	    setTimeout(function () { overlay.classList.remove('shat-me-open'); }, 320);
	  }
	  window.shatOpenMeSheet = openSheet;

	  if (closeBtn) closeBtn.addEventListener('click', closeSheet);

	  /* Sign Out lives in this sheet now. The shared .js-shat-logout handler
	     runs in the CAPTURE phase and calls stopPropagation, so a listener
	     on the link itself would never fire. Expose the closer instead and
	     let the confirm dialog call it as it opens. */
	  window.shatCloseMeSheet = closeSheet;
	  if (overlay) overlay.addEventListener('click', function (e) {
	    if (sheet && sheet.contains(e.target)) return;
	    closeSheet();
	  });
	  document.addEventListener('keydown', function (e) {
	    if (e.key === 'Escape' && overlay && overlay.classList.contains('shat-me-open')) closeSheet();
	  });

	  if (infoBtn && rules) {
	    infoBtn.addEventListener('click', function () {
	      var open = !rules.hasAttribute('hidden');
	      if (open) { rules.setAttribute('hidden', ''); infoBtn.classList.remove('on'); }
	      else      { rules.removeAttribute('hidden');  infoBtn.classList.add('on'); }
	    });
	  }

	  function showToast(msg) {
	    if (!toast) return;
	    if (toastTxt) toastTxt.textContent = msg;
	    toast.classList.add('show');
	    setTimeout(function () { toast.classList.remove('show'); }, 4200);
	  }

	  function applyState(s, announce) {
	    if (!s) return;
	    watched = s.seconds;
	    count   = s.count;
	    best    = s.best;
	    var was = earned;
	    earned  = !!s.earned_today;
	    if (meBtn && count > 0) meBtn.classList.add('has-streak');
	    if (announce && s.just_earned && !was) {
	      if (meBtn) {
	        meBtn.classList.remove('shat-me-win');
	        void meBtn.offsetWidth;
	        meBtn.classList.add('shat-me-win');
	      }
	      showToast(count === 1
	        ? 'Sweet! Your streak starts today'
	        : 'Candy earned. ' + count + ' days in a row');
	    }
	    if (overlay && overlay.classList.contains('shat-me-open')) paint();
	  }

	  /* Watch timer: counts only active, visible seconds. */
	  var pending = 0, lastActive = Date.now(), sending = false;
	  ['scroll','pointerdown','keydown','touchstart','mousemove'].forEach(function (evt) {
	    window.addEventListener(evt, function () { lastActive = Date.now(); }, { passive: true });
	  });

	  function flush(useBeacon) {
	    if (pending < 1 || sending || earned) return;
	    var send = Math.round(pending);
	    pending -= send;
	    var body = 'action=shat_streak_ping&nonce=' + encodeURIComponent(shatAjax.nonce) +
	               '&seconds=' + send + '&day=' + encodeURIComponent(localDay());
	    if (useBeacon && navigator.sendBeacon) {
	      try {
	        navigator.sendBeacon(shatAjax.ajaxurl,
	          new Blob([body], { type: 'application/x-www-form-urlencoded' }));
	      } catch (e) {}
	      return;
	    }
	    sending = true;
	    jQuery.post(shatAjax.ajaxurl, body, function (r) {
	      sending = false;
	      if (r && r.success) applyState(r.data, true);
	    }).fail(function () { sending = false; });
	  }

	  setInterval(function () {
	    if (earned || document.hidden) return;
	    if (Date.now() - lastActive > 60000) return;
	    pending += 1;
	    watched = Math.min(GOAL, watched + 1);
	    if (overlay && overlay.classList.contains('shat-me-open')) paint();
	    if (pending >= 30) flush(false);
	  }, 1000);

	  document.addEventListener('visibilitychange', function () {
	    if (document.hidden) flush(true);
	  });
	  window.addEventListener('pagehide', function () { flush(true); });

	  paintGreeting();
	  paint();
	})();
	</script>
	<?php
}, 101 );
// ============================================================
// OVERLAY CLEARANCE — keep foreign fixed bottom bars off the chat
// ============================================================
/**
 * On mobile a theme-level bottom navigation bar (or any other plugin's
 * fixed bottom toolbar) is painted on the layout viewport with its own
 * z-index. When the chat panel opens, that bar can end up sitting on top
 * of the composer, so the text field and Send button cannot be tapped.
 *
 * Raising the chat's z-index is not a reliable fix — a theme is free to
 * use a higher one — so instead we *hide* those bars for as long as one
 * of our own full-screen overlays is open, then put them back exactly as
 * they were.
 *
 * Nothing here hardcodes a theme selector. A small depth-limited walk of
 * the document finds elements that are (a) position:fixed or sticky,
 * (b) anchored to the bottom of the viewport, (c) wide and short like a
 * bar, and (d) not part of our own UI. Those are the only things hidden.
 *
 * The trigger is the `schat-locked` class the chat already puts on <body>
 * (every open/close path sets it, including the inbox and deep links), so
 * this needs no changes to the chat's own JavaScript. The profile sheet
 * is wired up through the same helper.
 */
add_action( 'wp_footer', function() {
	if ( ! is_user_logged_in() ) return;
	?>
	<style id="shat-overlay-clearance-css">
	/* Belt-and-braces: the JS below is what actually finds the bar, but
	   these cover the conventional markup without waiting for a frame. */
	body.schat-locked > nav[class*="bottom"],
	body.schat-locked > div[class*="bottom-nav"],
	body.schat-locked > div[class*="bottom-bar"],
	body.schat-locked .shat-nav-suppressed{
		display:none !important;
	}
	/* Applied by JS to anything it identifies. Kept separate from the
	   element's own inline style so nothing of theirs is overwritten. */
	.shat-nav-suppressed{
		display:none !important;
		visibility:hidden !important;
		pointer-events:none !important;
	}
	</style>
	<script id="shat-overlay-clearance-js">
	(function () {
		/* Our own chrome — never hide any of it. */
		var OURS = [
			'.schat-overlay', '.schat-inbox-overlay', '.schat-root',
			'.shat-me-overlay', '.shat-candy-toast', '.shat-root',
			'.schat-fab', '#schat-fab-btn', '#schat-admin-fab-btn',
			'#shat-overlay-clearance-css', '#shat-overlay-clearance-js',
			'#wpadminbar'
		].join(',');

		var suppressed = [];   // elements we hid, so we can restore them
		var depth      = 8;    // how far down from <body> to look

		function isOurs(el) {
			return !!(el.closest && el.closest(OURS));
		}

		/* A bar worth hiding: fixed/sticky, sitting at (or below) the
		   bottom edge, at least half the screen wide, and no taller than
		   a third of the screen. That shape is a navigation bar; a
		   full-screen modal or a page wrapper is not. */
		function looksLikeBottomBar(el) {
			var cs = window.getComputedStyle(el);
			if (cs.position !== 'fixed' && cs.position !== 'sticky') return false;
			if (cs.display === 'none' || cs.visibility === 'hidden') return false;

			var r = el.getBoundingClientRect();
			if (r.width <= 0 || r.height <= 0) return false;

			var vh = window.innerHeight  || document.documentElement.clientHeight;
			var vw = window.innerWidth   || document.documentElement.clientWidth;

			if (r.height > vh / 3) return false;          // too tall for a bar
			if (r.width  < vw * 0.5) return false;        // too narrow for a bar
			if (r.bottom < vh - 24) return false;         // not at the bottom

			return true;
		}

		/* Depth-limited walk. Fixed bottom bars live near the top of the
		   tree, so this stays cheap even on a heavy page — and it runs
		   only when an overlay opens. */
		function collect(node, level, out) {
			if (level > depth) return;
			for (var i = 0; i < node.children.length; i++) {
				var el = node.children[i];
				var tag = el.tagName;
				if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'LINK' ||
				    tag === 'TEMPLATE' || tag === 'NOSCRIPT') continue;
				if (isOurs(el)) continue;
				if (looksLikeBottomBar(el)) { out.push(el); continue; }
				collect(el, level + 1, out);
			}
		}

		function suppress() {
			if (suppressed.length) return;   // already hidden
			var found = [];
			try { collect(document.body, 0, found); } catch (e) { return; }
			found.forEach(function (el) {
				el.classList.add('shat-nav-suppressed');
				el.setAttribute('aria-hidden', 'true');
				suppressed.push(el);
			});
		}

		function restore() {
			suppressed.forEach(function (el) {
				el.classList.remove('shat-nav-suppressed');
				el.removeAttribute('aria-hidden');
			});
			suppressed = [];
		}

		/* Public, so anything else in the plugin can borrow it. */
		window.shatSuppressFixedBars = suppress;
		window.shatRestoreFixedBars  = restore;

		function anyOverlayOpen() {
			return document.body.classList.contains('schat-locked') ||
			       !!document.querySelector('.shat-me-overlay.shat-me-open') ||
			       !!document.querySelector('.schat-overlay.open') ||
			       !!document.querySelector('.schat-inbox-overlay.open');
		}

		var lastOpen = null;
		function sync() {
			var open = anyOverlayOpen();
			if (open === lastOpen) return;   // nothing changed
			lastOpen = open;
			if (open) suppress(); else restore();
		}

		/* One observer covers every open and close path: the chat toggles
		   `schat-locked` on <body>, and the profile sheet toggles
		   `shat-me-open` on its own overlay. The chat also retags message
		   rows constantly while you scroll, so the check is coalesced into
		   one animation frame and then short-circuits on `lastOpen`. */
		if (window.MutationObserver) {
			var queued = false;
			new MutationObserver(function () {
				if (queued) return;
				queued = true;
				requestAnimationFrame(function () { queued = false; sync(); });
			}).observe(document.documentElement, {
				attributes: true,
				attributeFilter: ['class'],
				subtree: true
			});
		}

		/* A bar can also be revealed mid-session (lazy-loaded theme JS) or
		   only become bottom-anchored once the keyboard resizes the visual
		   viewport, so re-check on those moments too. */
		if (window.visualViewport) {
			window.visualViewport.addEventListener('resize', function () {
				if (anyOverlayOpen()) { restore(); suppress(); }
			});
		}
		window.addEventListener('orientationchange', function () {
			if (anyOverlayOpen()) { restore(); suppress(); }
		});

		/* The standalone chat page has no overlay to toggle — the thread
		   IS the page — so hide the bars for the whole visit. */
		document.addEventListener('DOMContentLoaded', function () {
			if (document.querySelector('.schat-overlay.schat-standalone-mode')) {
				lastOpen = true; suppress();
			} else { sync(); }
		});
		if (document.readyState !== 'loading') {
			if (document.querySelector('.schat-overlay.schat-standalone-mode')) {
				lastOpen = true; suppress();
			} else { sync(); }
		}
	})();
	</script>
	<?php
}, 102 );
