<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * [stoke_chat] shortcode, conditional asset loading, and the block wrapper.
 */
class Shortcode {

	/** @var Settings */
	private $settings;

	/** @var Smileys */
	private $smileys;

	public function __construct( Settings $settings, Smileys $smileys ) {
		$this->settings = $settings;
		$this->smileys  = $smileys;
	}

	public function register() {
		add_shortcode( 'stoke_chat', array( $this, 'render' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		// Register on init, not wp_enqueue_scripts: block themes render template
		// content before wp_enqueue_scripts fires, and wp_localize_script silently
		// drops data for handles that are not yet registered at render time.
		add_action( 'init', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) assets; enqueued only when the shortcode renders.
	 */
	public function register_assets() {
		wp_register_script( 'stoke-chat', STOKECHAT_PLUGIN_URL . 'assets/js/chat.js', array(), STOKECHAT_VERSION, true );
		wp_register_style( 'stoke-chat', STOKECHAT_PLUGIN_URL . 'assets/css/chat.css', array(), STOKECHAT_VERSION );
	}

	public function render() {
		if ( ! is_user_logged_in() ) {
			return '<div class="stokechat stokechat-login-required"><p>'
				. esc_html__( 'You need to be logged in to use the chat.', 'stoke-chat' )
				. ' <a href="' . esc_url( wp_login_url( get_permalink() ? get_permalink() : home_url( '/' ) ) ) . '">'
				. esc_html__( 'Log in', 'stoke-chat' )
				. '</a></p></div>';
		}

		wp_enqueue_style( 'stoke-chat' );
		wp_enqueue_script( 'stoke-chat' );

		$user = wp_get_current_user();

		wp_localize_script(
			'stoke-chat',
			'StokeChatCfg',
			array(
				'restUrl'            => esc_url_raw( rest_url( 'stoke-chat/v1' ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'pollInterval'       => (int) $this->settings->get( 'poll_interval' ),
				'pollIntervalHidden' => (int) $this->settings->get( 'poll_interval_hidden' ),
				'maxLength'          => (int) $this->settings->get( 'message_max_length' ),
				'canCreateRooms'     => current_user_can( Capabilities::CREATE_ROOMS ),
				'isAdmin'            => current_user_can( 'manage_options' ),
				'smileys'            => $this->smileys->for_picker(),
				'smileyMap'          => $this->smileys->replacement_map(),
				'me'                 => array(
					'id'           => (int) $user->ID,
					'username'     => $user->user_login,
					'display_name' => $user->display_name,
					'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
				),
			)
		);

		return '<div id="stokechat-app" class="stokechat" data-loading="1"><p class="stokechat-boot">'
			. esc_html__( 'Loading chat…', 'stoke-chat' ) . '</p></div>';
	}

	public function register_block() {
		wp_register_script(
			'stoke-chat-block',
			STOKECHAT_PLUGIN_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element' ),
			STOKECHAT_VERSION,
			true
		);

		register_block_type(
			STOKECHAT_PLUGIN_DIR . 'block.json',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}
}
