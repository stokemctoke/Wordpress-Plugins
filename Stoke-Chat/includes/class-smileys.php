<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in Unicode smileys plus optional image smileys from a custom folder.
 *
 * Message shortcodes like :smile: or :) are replaced on the client when rendering.
 * Custom folder files (png/gif/jpg/svg/webp) become :filename: image smileys.
 */
class Smileys {

	/** Allowed image extensions in a custom smiley folder. */
	const IMAGE_EXTS = array( 'png', 'gif', 'jpg', 'jpeg', 'svg', 'webp' );

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Built-in shortcode → Unicode emoji map (always available).
	 *
	 * @return array<string,string>
	 */
	public function defaults() {
		return array(
			':)'         => '😊',
			':-)'        => '😊',
			':smile:'    => '😊',
			':('         => '😢',
			':-('        => '😢',
			':sad:'      => '😢',
			':D'         => '😄',
			':-D'        => '😄',
			':grin:'     => '😄',
			';)'         => '😉',
			';-)'        => '😉',
			':wink:'     => '😉',
			':P'         => '😛',
			':-P'        => '😛',
			':p'         => '😛',
			':tongue:'   => '😛',
			':o'         => '😮',
			':-o'        => '😮',
			':O'         => '😮',
			':surprised:' => '😮',
			':|'         => '😐',
			':-|'        => '😐',
			':neutral:'  => '😐',
			'xD'         => '😆',
			':laugh:'    => '😆',
			':/'         => '😕',
			':-/'        => '😕',
			':confused:' => '😕',
			'<3'         => '❤️',
			':heart:'    => '❤️',
			':thumbsup:' => '👍',
			':thumbsdown:' => '👎',
			':fire:'     => '🔥',
			':star:'     => '⭐',
			':ok:'       => '👌',
			':clap:'     => '👏',
			':wave:'     => '👋',
			':think:'    => '🤔',
			':cool:'     => '😎',
			':cry:'      => '😭',
			':angry:'    => '😠',
			':party:'    => '🥳',
			':eyes:'     => '👀',
			':100:'      => '💯',
			':rocket:'   => '🚀',
			':check:'    => '✅',
			':cross:'    => '❌',
		);
	}

	/**
	 * Smileys for the picker UI (unique codes, prefer :name: over text emoticons).
	 *
	 * @return array<int,array{code:string,type:string,value:string,label:string}>
	 */
	public function for_picker() {
		$seen   = array();
		$out    = array();
		$custom = $this->custom_smileys();

		foreach ( $custom as $code => $url ) {
			$key = $code;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'code'  => $code,
				'type'  => 'image',
				'value' => $url,
				'label' => $code,
			);
		}

		foreach ( $this->defaults() as $code => $emoji ) {
			// Prefer :name: entries in the picker; skip text-only emoticons like :) .
			if ( ':' !== $code[0] || ':' !== substr( $code, -1 ) || strlen( $code ) < 3 ) {
				continue;
			}
			if ( isset( $seen[ $emoji ] ) ) {
				continue;
			}
			$seen[ $emoji ] = true;
			$out[]          = array(
				'code'  => $code,
				'type'  => 'emoji',
				'value' => $emoji,
				'label' => $code,
			);
		}

		return $out;
	}

	/**
	 * Full replacement map for message rendering (custom images override defaults).
	 *
	 * @return array<string,array{type:string,value:string}>
	 */
	public function replacement_map() {
		$map = array();

		foreach ( $this->defaults() as $code => $emoji ) {
			$map[ $code ] = array(
				'type'  => 'emoji',
				'value' => $emoji,
			);
		}

		foreach ( $this->custom_smileys() as $code => $url ) {
			$map[ $code ] = array(
				'type'  => 'image',
				'value' => $url,
			);
		}

		return $map;
	}

	/**
	 * Scan the configured custom folder for image smileys.
	 *
	 * @return array<string,string> code => absolute URL
	 */
	public function custom_smileys() {
		$rel = (string) $this->settings->get( 'smiley_folder' );
		$rel = trim( str_replace( '\\', '/', $rel ), '/' );
		if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
			return array();
		}

		$dir = trailingslashit( WP_CONTENT_DIR ) . $rel;
		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return array();
		}

		$base_url = trailingslashit( content_url( $rel ) );
		$out      = array();

		$files = scandir( $dir );
		if ( ! is_array( $files ) ) {
			return array();
		}

		foreach ( $files as $file ) {
			if ( '.' === $file[0] ) {
				continue;
			}
			$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::IMAGE_EXTS, true ) ) {
				continue;
			}
			$base = pathinfo( $file, PATHINFO_FILENAME );
			$code = $this->sanitize_code( $base );
			if ( '' === $code ) {
				continue;
			}
			$out[ ':' . $code . ':' ] = $base_url . rawurlencode( $file );
		}

		ksort( $out );
		return $out;
	}

	/**
	 * Filename stem → smiley code body (without surrounding colons).
	 */
	private function sanitize_code( $name ) {
		$name = strtolower( (string) $name );
		$name = preg_replace( '/[^a-z0-9_\-]+/', '', $name );
		if ( ! is_string( $name ) || strlen( $name ) < 1 || strlen( $name ) > 40 ) {
			return '';
		}
		return $name;
	}
}
