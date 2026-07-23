<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > Stoke Chat. Single option array, Settings API, capability sync on save.
 */
class Settings {

	const OPTION = 'stokechat_settings';

	public function defaults() {
		return array(
			'create_roles'         => array_keys( wp_roles()->roles ),
			'poll_interval'        => 5,
			'poll_interval_hidden' => 60,
			'message_max_length'   => 2000,
			'messages_per_page'    => 50,
			'emails_enabled'       => true,
			'away_threshold_min'   => 5,
			'email_throttle_min'   => 15,
			'chat_page_url'        => '',
			'smiley_folder'        => '',
			'palette'              => 'stoke-mctoke',
		);
	}

	/**
	 * Available UI color palettes (slug => label).
	 *
	 * @return array<string,string>
	 */
	public function palettes() {
		return array(
			'stoke-mctoke'    => __( 'Stoke McToke (cyan)', 'stoke-chat' ),
			'gallus-gadgets'  => __( 'Gallus Gadgets (orange)', 'stoke-chat' ),
		);
	}

	/**
	 * Sanitized current palette slug.
	 */
	public function palette() {
		$slug = (string) $this->get( 'palette' );
		if ( ! isset( $this->palettes()[ $slug ] ) ) {
			return 'stoke-mctoke';
		}
		return $slug;
	}

	public function get( $key ) {
		$saved    = get_option( self::OPTION, array() );
		$defaults = $this->defaults();
		$merged   = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
		return isset( $merged[ $key ] ) ? $merged[ $key ] : null;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'sync_capabilities' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION, array( $this, 'sync_capabilities_on_add' ), 10, 2 );
	}

	public function add_menu() {
		add_options_page(
			__( 'Stoke Chat', 'stoke-chat' ),
			__( 'Stoke Chat', 'stoke-chat' ),
			'manage_options',
			'stoke-chat',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'stoke_chat',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	public function sanitize( $input ) {
		$defaults = $this->defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = array();

		$roles               = array_keys( wp_roles()->roles );
		$out['create_roles'] = array_values( array_intersect( isset( $input['create_roles'] ) ? (array) $input['create_roles'] : array(), $roles ) );

		$out['poll_interval']        = max( 2, min( 300, (int) ( $input['poll_interval'] ?? $defaults['poll_interval'] ) ) );
		$out['poll_interval_hidden'] = max( $out['poll_interval'], min( 600, (int) ( $input['poll_interval_hidden'] ?? $defaults['poll_interval_hidden'] ) ) );
		$out['message_max_length']   = max( 1, min( 10000, (int) ( $input['message_max_length'] ?? $defaults['message_max_length'] ) ) );
		$out['messages_per_page']    = max( 10, min( 100, (int) ( $input['messages_per_page'] ?? $defaults['messages_per_page'] ) ) );
		$out['emails_enabled']       = ! empty( $input['emails_enabled'] );
		$out['away_threshold_min']   = max( 1, min( 1440, (int) ( $input['away_threshold_min'] ?? $defaults['away_threshold_min'] ) ) );
		$out['email_throttle_min']   = max( 1, min( 1440, (int) ( $input['email_throttle_min'] ?? $defaults['email_throttle_min'] ) ) );
		$out['chat_page_url']        = esc_url_raw( $input['chat_page_url'] ?? '' );

		$folder = isset( $input['smiley_folder'] ) ? (string) $input['smiley_folder'] : '';
		$folder = str_replace( '\\', '/', $folder );
		$folder = trim( $folder, '/' );
		if ( false !== strpos( $folder, '..' ) ) {
			$folder = '';
		}
		$folder = preg_replace( '#[^a-zA-Z0-9_\-./]#', '', $folder );
		$out['smiley_folder'] = is_string( $folder ) ? $folder : '';

		$palette = isset( $input['palette'] ) ? sanitize_key( (string) $input['palette'] ) : 'stoke-mctoke';
		$out['palette'] = isset( $this->palettes()[ $palette ] ) ? $palette : 'stoke-mctoke';

		return $out;
	}

	public function sync_capabilities( $old_value, $value ) {
		if ( is_array( $value ) && isset( $value['create_roles'] ) ) {
			Capabilities::sync( (array) $value['create_roles'] );
		}
	}

	public function sync_capabilities_on_add( $option, $value ) {
		$this->sync_capabilities( null, $value );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$roles_with_cap = Capabilities::roles_with_cap();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stoke Chat', 'stoke-chat' ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( 'stoke_chat' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Who can create rooms', 'stoke-chat' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( wp_roles()->roles as $slug => $role ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox"
											name="<?php echo esc_attr( self::OPTION ); ?>[create_roles][]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( in_array( $slug, $roles_with_cap, true ) ); ?> />
										<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'All logged-in users can always join public rooms and chat; this only controls room creation.', 'stoke-chat' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stokechat-poll"><?php esc_html_e( 'Poll interval (seconds)', 'stoke-chat' ); ?></label></th>
						<td>
							<input id="stokechat-poll" type="number" min="2" max="300"
								name="<?php echo esc_attr( self::OPTION ); ?>[poll_interval]"
								value="<?php echo esc_attr( $this->get( 'poll_interval' ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'How often the chat checks for new messages while the tab is visible.', 'stoke-chat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stokechat-poll-hidden"><?php esc_html_e( 'Poll interval when hidden (seconds)', 'stoke-chat' ); ?></label></th>
						<td>
							<input id="stokechat-poll-hidden" type="number" min="2" max="600"
								name="<?php echo esc_attr( self::OPTION ); ?>[poll_interval_hidden]"
								value="<?php echo esc_attr( $this->get( 'poll_interval_hidden' ) ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stokechat-maxlen"><?php esc_html_e( 'Max message length', 'stoke-chat' ); ?></label></th>
						<td>
							<input id="stokechat-maxlen" type="number" min="1" max="10000"
								name="<?php echo esc_attr( self::OPTION ); ?>[message_max_length]"
								value="<?php echo esc_attr( $this->get( 'message_max_length' ) ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stokechat-perpage"><?php esc_html_e( 'Messages per page', 'stoke-chat' ); ?></label></th>
						<td>
							<input id="stokechat-perpage" type="number" min="10" max="100"
								name="<?php echo esc_attr( self::OPTION ); ?>[messages_per_page]"
								value="<?php echo esc_attr( $this->get( 'messages_per_page' ) ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email alerts', 'stoke-chat' ); ?></th>
						<td>
							<label>
								<input type="checkbox"
									name="<?php echo esc_attr( self::OPTION ); ?>[emails_enabled]" value="1"
									<?php checked( (bool) $this->get( 'emails_enabled' ) ); ?> />
								<?php esc_html_e( 'Email users when they are @mentioned (or direct-messaged) while away', 'stoke-chat' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stokechat-away"><?php esc_html_e( 'Away threshold (minutes)', 'stoke-chat' ); ?></label></th>
						<td>
							<input id="stokechat-away" type="number" min="1" max="1440"
								name="<?php echo esc_attr( self::OPTION ); ?>[away_threshold_min]"
								value="<?php echo esc_attr( $this->get( 'away_threshold_min' ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'A user inactive for longer than this counts as away and may receive email alerts.', 'stoke-chat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stokechat-throttle"><?php esc_html_e( 'Email throttle (minutes)', 'stoke-chat' ); ?></label></th>
						<td>
							<input id="stokechat-throttle" type="number" min="1" max="1440"
								name="<?php echo esc_attr( self::OPTION ); ?>[email_throttle_min]"
								value="<?php echo esc_attr( $this->get( 'email_throttle_min' ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'At most one alert email per user per room within this window.', 'stoke-chat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stokechat-palette"><?php esc_html_e( 'Color palette', 'stoke-chat' ); ?></label></th>
						<td>
							<select id="stokechat-palette"
								name="<?php echo esc_attr( self::OPTION ); ?>[palette]">
								<?php foreach ( $this->palettes() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $this->palette(), $slug ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Match the chat UI to your site brand. Gallus Gadgets uses orange accents instead of cyan/blue.', 'stoke-chat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stokechat-url"><?php esc_html_e( 'Chat page URL', 'stoke-chat' ); ?></label></th>
						<td>
							<input id="stokechat-url" type="url" class="regular-text"
								name="<?php echo esc_attr( self::OPTION ); ?>[chat_page_url]"
								value="<?php echo esc_attr( $this->get( 'chat_page_url' ) ); ?>"
								placeholder="<?php echo esc_attr( home_url( '/chat/' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'The page containing the [stoke_chat] shortcode; used for links in alert emails.', 'stoke-chat' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stokechat-smileys"><?php esc_html_e( 'Custom smiley folder', 'stoke-chat' ); ?></label></th>
						<td>
							<input id="stokechat-smileys" type="text" class="regular-text"
								name="<?php echo esc_attr( self::OPTION ); ?>[smiley_folder]"
								value="<?php echo esc_attr( $this->get( 'smiley_folder' ) ); ?>"
								placeholder="uploads/stoke-chat-smileys" />
							<p class="description"><?php esc_html_e( 'Path relative to wp-content (e.g. uploads/stoke-chat-smileys). PNG, GIF, JPG, SVG, and WebP files become :filename: smileys. Built-in emoji smileys are always available.', 'stoke-chat' ); ?></p>
							<p class="description"><?php echo esc_html( 'Full path: ' . trailingslashit( WP_CONTENT_DIR ) . ( $this->get( 'smiley_folder' ) ?: '…' ) ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
