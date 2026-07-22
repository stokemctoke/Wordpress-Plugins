<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrator: instantiates services and wires hooks.
 */
class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	public $settings;

	/** @var Presence */
	public $presence;

	/** @var Rate_Limiter */
	public $rate_limiter;

	/** @var Members */
	public $members;

	/** @var Messages */
	public $messages;

	/** @var Rooms */
	public $rooms;

	/** @var Mentions */
	public $mentions;

	/** @var Mailer */
	public $mailer;

	/** @var Shortcode */
	public $shortcode;

	/** @var User_Hooks */
	public $user_hooks;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings     = new Settings();
		$this->presence     = new Presence( $this->settings );
		$this->rate_limiter = new Rate_Limiter();
		$this->members      = new Members();
		$this->messages     = new Messages();
		$this->rooms        = new Rooms( $this->members );
		$this->mentions     = new Mentions( $this->members );
		$this->mailer       = new Mailer( $this->settings, $this->presence, $this->members, $this->rooms, $this->mentions );
		$this->shortcode    = new Shortcode( $this->settings );
		$this->user_hooks   = new User_Hooks( $this->rooms, $this->members );

		Schema::maybe_upgrade();

		$this->settings->register();
		$this->mailer->register();
		$this->shortcode->register();
		$this->user_hooks->register();

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function register_rest_routes() {
		( new Rest\Rooms_Controller( $this ) )->register_routes();
		( new Rest\Messages_Controller( $this ) )->register_routes();
		( new Rest\Members_Controller( $this ) )->register_routes();
		( new Rest\Users_Controller( $this ) )->register_routes();
	}
}
