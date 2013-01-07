<?php

/*
Plugin Name: SES for WordPress
Version: 0.1
Description: A simple but flexible plugin for using Amazon Simple Email Service with WP. Supports image embedding, return-path, plain text emails and more.
Author: Duncan Brown
Author URI: http://duncanjbrown.com
*/

define( 'SES4WP_VERSION', 0.1 );
$ses4wp_path = plugin_basename( __FILE__ );

/**
 * Check PHP version
 */
if( version_compare( PHP_VERSION, '5.3.0', '<' ) ) {

	if( !function_exists( 'deactivate_plugins' ) ) {
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}
	deactivate_plugins( $ses4wp_path );
	wp_die( '<h1>Sorry!</h1><p>SES for Wordpress requires PHP 5.3. Please contact your host for assistance.</p><p>Press back to return to the options page.</p>' );
}

/**
 * Register default settings
 */
register_activation_hook( $ses4wp_path, function() {
	
	// automatically override wp_mail
	if( !get_option( 'ses4wp_override_wp_mail' ) )
		update_option( 'ses4wp_override_wp_mail', true );
} );

load_plugin_textdomain( 'ses4wp', false, basename( dirname( __FILE__ ) ) );

/**
 * Set up all the options
 */
define( 'SES_FOR_WORDPRESS_PATH', trailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'SES_FOR_WORDPRESS_URI', trailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'SES_FOR_WORDPRESS_KEY', get_option( 'ses4wp_key') );
define( 'SES_FOR_WORDPRESS_SECRET', get_option( 'ses4wp_secret') );
define( 'SES_FOR_WORDPRESS_SENDER', get_option( 'ses4wp_sender') );
define( 'SES_FOR_WORDPRESS_REPLY_TO', get_option( 'ses4wp_reply_to') );
define( 'SES_FOR_WORDPRESS_RETURN_PATH', get_option( 'ses4wp_return_path') );
define( 'SES_FOR_WORDPRESS_SENDER_NAME', get_option( 'ses4wp_sender_name') );
define( 'SES_FOR_WORDPRESS_TEST_EMAIL', apply_filters( 'ses4wp_test_email', get_option( 'admin_email' ) ) );

/**
 * Includes
 */

// AWS SDK v1.5
if( !defined( 'CFRUNTIME_NAME' ) ) {
	include 'lib/sdk/sdk.class.php';
}

// PEAR Mime Mail
if( !class_exists( 'Mail_mime' ) )
	include 'lib/Mail/mime.php';

// convert HTML to text for html emails
if( !function_exists( 'convert_html_to_text' ) )
	include 'lib/html2text.php';

// generic options-page building code
if( !defined( 'DJB_OPTIONS_VERSION' ) )
	include 'lib/djb-options.php';


/**
 * Business to connect to SES.
 */
class SES4WP_SESAdapter {

	/**
	 * The handle for the api
	 */
	var $ses;

	/**
	 * Set up the connection to SES
	 */
	function __construct() {
		$this->set_credentials( SES_FOR_WORDPRESS_KEY, SES_FOR_WORDPRESS_SECRET );
		$this->ses = new AmazonSES();
	}

	/**
	 * Set the credentials that the SDK will refer to in its transactions
	 *
	 * @param string  $key
	 * @param string  $secret
	 */
	private function set_credentials( $key, $secret ) {

		CFCredentials::set( array(
				'development' => array(
					'key' => $key,
					'secret' => $secret,
					'default_cache_config' => '',
					'certificate_authority' => false
				),
				'@default' => 'development'
			) );
	}

	/**
	 * Pass all the other method calls up to the SES object itself
	 */
	function __call( $method, $args ) {
		return call_user_func_array( array( $this->ses, $method), $args );
	}

	/**
	 * Wrap the SES send raw email
	 */
	function send_raw_email( $data, $destinations ) {
		$response = $this->ses->send_raw_email( $data, $destinations );
		return $this->parse_response( $response );
	}

	/**
	 * Parse the response from Amazon
	 * @return mixed true|WP_Error
	 */
	protected function parse_response( $response ) {
		
		$code = $response->status;
		
		if( $code == 200 || $code == 202 )
			return true;

		return new WP_Error( 'ses4wp_error', (string) $response->body->Error->Message );
	}

}

class SES4WP_Email {

	/**
	 * The MIME mail object we'll be sending
	 */
	private $mail;

	/**
	 * Its headers
	 */
	private $headers;

	/**
	 * Prepare a mime mail 
	 * Params identical to wp_mail except it won't take a string of headers, only an array
	 * if you want to treat it exactly the same as wp_mail, the pluggable function below will
	 * do necessary filtering and argument parsing
	 */
	function __construct( $to, $subject, $message, $headers = false, $attachments = false ) {

		if( !$headers )
			$headers = array();

		$charset = get_bloginfo( 'charset' );

		$this->mail = new Mail_mime( array( 
			'eol' => "\n",
			'text_charset' => $charset,
			'html_charset' => $charset ) 
		);

		$this->to = $to;

		$this->mail->encodeRecipients( $to );

		$default_headers = array(
			'From' => SES_FOR_WORDPRESS_SENDER_NAME . ' <' . SES_FOR_WORDPRESS_SENDER .'>', 
			'Reply-To' => SES_FOR_WORDPRESS_REPLY_TO,
			'To' => $this->mail->encodeRecipients( $to ),
			'Subject' => '=?UTF-8?Q?' . quoted_printable_encode($subject) . '?='
		);

		if( $message )
			$this->set_content( $message );

		if( $attachments )
			$this->add_attachments( $attachments );

		$this->headers = $this->mail->txtHeaders( array_merge( $default_headers, $headers ) );
	}

	/**
	 * Send the email
	 * @return mixed true|WP_Error if the response is no good
	 */
	public function send() {
		
		$body = $this->mail->get( array( 'text_charset' => get_bloginfo( 'charset' ) ) );

		$message = $this->headers . "\r\n" . $body;
		
		$transport = new SES4WP_SESAdapter();
		
		return $transport->send_raw_email( 
			array( 'Data' => base64_encode( $message ) ), 
			array( 'Destinations' => $this->to ) );
	}

	/**
	 * Stick attachments to the email
	 * @param array of file paths
	 */
	private function add_attachments( $attachments ) {

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		foreach( $attachments as $a ) {
			$type = finfo_file( $finfo, $a );
			$this->mail->addAttachment( $a, $type );
		}
	}

	/**
	 * Set the content of the email
	 * @param string $message
	 */
	private function set_content( $message ) {
		$plain = convert_html_to_text( $message );
		$this->mail->setTxtBody( $plain );
		$this->mail->setHTMLBody( $message );
	}

	/**
	 * Add some images to embed in the email
	 * @param array $images
	 */
	public function embed_image( $id, $image ) {

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$type = finfo_file( $finfo, $image );
		$this->mail->addHTMLImage( $image, $type, '', true, $id );
		return self::get_embedded_content_id( $id );
	}


	/**
	 * Get the embed ID of an embedded object
	 * PEAR Mail automatically appends the sender's domain to the content-id header of this object
	 * so we will do the same thing.
	 *
	 * eg if the content-id id was foo and the sender domain was bar.com
	 * you would have to reference it as eg <img src="cid:foo@bar.com@" />
	 *
	 * @param string the content_id to make compatible with a PEAR Mail-generated cid
	 * @return string a content id to use in an src attribute. 
	 */
	public static function get_embedded_content_id( $id ) {
		$sender = SES_FOR_WORDPRESS_SENDER;
		$bits = explode( '@', $sender );
		return $id . '@' . array_pop( $bits );
	}

}


/**
 * Override wp_mail
 */
if ( !function_exists( 'wp_mail' ) && get_option( 'ses4wp_override_wp_mail' ) ) :

	function wp_mail( $to, $subject, $message, $headers = '', $attachments = '' ) {

		// let usual filters do their business
		extract( apply_filters( 'wp_mail', compact( 'to', 'subject', 'message', 'headers', 'attachments' ) ) );

		// let string headers from the filter be treated as array
		// we do this because wp_mail accepts strings too
		if( $headers && !is_array( $headers ) )
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );

		$mail = new SES4WP_Email( $to, $subject, $message, $headers, $attachments );
		
		$images = apply_filters( 'ses4wp_images', false );

		if( $images ) {
			foreach( $images as $id => $image ) {
				$mail->embed_image( $id, $image );
			}
		}

		$mail->send();
	}

endif;

/**
 * Options page
 */
add_action( 'admin_menu', 'ses4wp_add_admin_pages' );

function ses4wp_add_admin_pages() {

	$main_options = new Djb_Options_Page( array( 
		'slug' 			=> 'db_ses_main_options_page',
		'menu_title' 	=> __( 'SES4WP Options', 'ses4wp' ),
		'page_title' 	=> __( 'SES4WP Options', 'ses4wp' ),
		'capability' 	=> 'manage_options',
		'icon_url'		=> false,
		'position'		=> false
	) );

	$keys = new Djb_Options_Page_Section( __( 'AWS Keys', 'ses4wp' ) );

	$keys->add_field( new Djb_Options_Page_Text_Field( array(
		'key' => 'ses4wp_secret',
		'name' => 'AWS Secret' 
	) ) );

	$keys->add_field( new Djb_Options_Page_Text_Field( array(
		'key' => 'ses4wp_key',
		'name' => 'AWS Key' 
	) ) );

	$main_options->add_section( $keys );

	$addresses = new Djb_Options_Page_Section( __( 'Sender addresses', 'ses4wp' ) );

	$addresses->add_field( new Djb_Options_Page_Text_Field( array(
		'key' => 'ses4wp_sender',
		'name' => 'From:' 
	) ) );

	$addresses->add_field( new Djb_Options_Page_Text_Field( array(
		'key' => 'ses4wp_reply_to',
		'name' => 'Reply-To:' 
	) ) );

	$addresses->add_field( new Djb_Options_Page_Text_Field( array(
		'key' => 'ses4wp_return_path',
		'name' => 'Return-Path:' 
	) ) );

	$addresses->add_field( new Djb_Options_Page_Text_Field( array(
		'key' => 'ses4wp_sender_name',
		'name' => 'Sender name' 
	) ) );

	$main_options->add_section( $addresses );

	$integration = new Djb_Options_Page_Section( __( 'Integration', 'ses4wp' ) );

	$integration->add_field( new Djb_Options_Page_Checkbox_Field( array(
		'key' => 'ses4wp_override_wp_mail',
		'name' => 'Override wp_mail?'
	) ) );

	$integration->add_field( new Djb_Options_Page_Checkbox_Field( array(
		'key' => 'ses4wp_send_test_email',
		'name' => __( 'Send a test email to ', 'ses4wp' ) . SES_FOR_WORDPRESS_TEST_EMAIL,
		'validation' => 'ses4wp_test'
	) ) );

	$main_options->add_section( $integration );

	// add extra fields, sections, go crazy
	do_action( 'ses4wp_main_options_page', $main_options );
}


/**
 * If vital options are missing, let the user know
 */
if( !SES_FOR_WORDPRESS_KEY || !SES_FOR_WORDPRESS_SECRET || !SES_FOR_WORDPRESS_SENDER ) {
	add_action( 'admin_init', function() {
		global $pagenow;
		if( $pagenow == 'plugins.php' )
			add_settings_error( 'ses4wp_generic_message', 'ses4wp_generic', __( 'SES4WP needs some configuration before it\'s ready - visit <em>Settings > SES4WP Options</em> to set it up', 'ses4wp' ), 'error' );
	} );
}

/**
 * Let us display generic errors
 */
add_action( 'admin_notices', function() {
	settings_errors( 'ses4wp_generic_message' );
} );

/**
 * Send a test email to the blog admin.
 *
 * This function is used as a callback to a form element so it will always
 * be called on admin page submit. Obviously we don't want to send a test every time,
 * only when the 'send_test_email_box' is ticked.
 * 
 * For this reason it takes a param, $bool, which is only set when the box is ticked.
 *
 * @param bool $bool
 */
function ses4wp_test( $bool ) {

	if( $bool ) {
		$mail = new SES4WP_Email( SES_FOR_WORDPRESS_TEST_EMAIL, __( 'Test email', 'ses4wp' ), __( 'This email was sent via Amazon SES by SES for Wordpress version ', 'ses4wp' ) . SES4WP_VERSION );
		$response = $mail->send();

		if( is_wp_error( $response ) )
			add_settings_error( 'ses4wp_send_test_email', 'ses4wp_email_error', 'SES error: ' . $response->get_error_message(), 'error' );
		else
			add_settings_error( 'ses4wp_send_test_email', 'ses4wp_email_sent', 'SES mail sent', 'updated' );
	}

	return null;
} 

/**
 * Embed an image in the email you are about to send
 * @param string $id the content-id of the image
 * @param string $image the path to the image
 *
 * @return the content-id to use in your image's src attribute
 */
function ses4wp_embed_image( $id, $image ) {
	
	$image = array( $id => $image );
	
	add_filter( 'ses4wp_images', function( $images ) use ( $image ) {
		
		if( !$images )
			$images = array();

		return array_merge( $images, $image );
	} );

	// PEAR Mail will mangle your content ID so we have to ask for it back to use in an src
	return SES4WP_Email::get_embedded_content_id( $id );
}