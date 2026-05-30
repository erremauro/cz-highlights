<?php
/**
 * Plugin Name: CZ Highlights
 * Description: Evidenziazioni e note personali per articoli e volumi. Solo per utenti registrati.
 * Version:     1.0.0
 * Author:      CZ
 * Text Domain: cz-highlights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CZH_VERSION',  '1.0.0' );
define( 'CZH_PATH',     plugin_dir_path( __FILE__ ) );
define( 'CZH_URL',      plugins_url( '', __FILE__ ) . '/' );

require_once CZH_PATH . 'inc/db.php';
require_once CZH_PATH . 'inc/rest-api.php';
require_once CZH_PATH . 'inc/post-watcher.php';

register_activation_hook( __FILE__, [ 'CZ_Highlights', 'activate' ] );

final class CZ_Highlights {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate() {
		CZH_DB::create_table();
		CZH_PostNotes_DB::create_table();
		self::ensure_notes_page();
	}

	private static function ensure_notes_page() {
		$page_id = (int) get_option( 'czh_notes_page_id', 0 );
		if ( $page_id ) {
			$page = get_post( $page_id );
			if ( $page && 'page' === $page->post_type && 'trash' !== $page->post_status ) {
				return;
			}
		}

		$page_id = wp_insert_post( [
			'post_title'   => 'Le Mie Note',
			'post_name'    => 'le-mie-note',
			'post_content' => '[czh_my_notes]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		] );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'czh_notes_page_id', $page_id );
		}
	}

	private function __construct() {
		add_action( 'init',                    [ $this, 'maybe_upgrade_db' ] );
		add_action( 'rest_api_init',           [ 'CZH_REST', 'register_routes' ] );
		add_action( 'wp_enqueue_scripts',      [ $this, 'enqueue_assets' ] );
		add_action( 'wp_footer',               [ $this, 'print_drawer' ] );
		add_action( 'czh_nav_user_menu_items', [ $this, 'render_nav_menu_item' ] );
		add_filter( 'body_class',              [ $this, 'add_body_classes' ] );
		add_filter( 'the_content',             [ $this, 'append_post_note_bar' ] );
		add_shortcode( 'czh_my_notes',         [ $this, 'render_notes_shortcode' ] );
	}

	public function add_body_classes( $classes ) {
		if ( ! is_user_logged_in() ) {
			return $classes;
		}
		$style = get_user_meta( get_current_user_id(), 'czup_highlight_style', true );
		// Empty meta means user has never saved a preference → default is underline.
		if ( '' === $style || 'underline' === $style ) {
			$classes[] = 'czh-style-underline';
		}
		return $classes;
	}

	private function highlights_enabled() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$enabled = get_user_meta( get_current_user_id(), 'czup_highlights_enabled', true );
		return '0' !== $enabled; // empty string (never saved) or '1' → enabled
	}

	/** ---- Assets ---- */

	public function enqueue_assets() {
		$is_article    = is_singular( 'post' );
		$is_volume     = is_singular( 'volume' );
		$is_notes_page = $this->is_notes_page();
		$logged_in     = is_user_logged_in();

		// Guest: load only on articles/volumes to show tooltip → login prompt
		if ( ! $logged_in && ! $is_article && ! $is_volume ) {
			return;
		}

		// Logged-in: apply normal restrictions
		if ( $logged_in ) {
			if ( ! $this->highlights_enabled() ) {
				return;
			}
			if ( ! $is_article && ! $is_volume && ! $is_notes_page ) {
				return;
			}
		}

		$get_asset = function ( $rel ) {
			$abs = CZH_PATH . ltrim( $rel, '/' );
			$min = preg_replace( '/(\.js|\.css)$/', '.min$1', $abs );
			$use_min = ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
			$chosen  = ( $use_min && file_exists( $min ) ) ? $min : $abs;
			$url     = CZH_URL . ltrim( str_replace( CZH_PATH, '', $chosen ), '/' );
			$ver     = file_exists( $chosen ) ? filemtime( $chosen ) : CZH_VERSION;
			return [ $url, $ver ];
		};

		list( $js_url, $js_ver ) = $get_asset( 'assets/js/czh.js' );
		wp_register_script( 'czh-js', $js_url, [], $js_ver, true );

		if ( $logged_in ) {
			$config = [
				'version' => CZH_VERSION,
				'rest'    => [
					'root'  => esc_url_raw( trailingslashit( rest_url( CZH_REST::NAMESPACE ) ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				],
				'user'    => [
					'loggedIn' => true,
					'id'       => get_current_user_id(),
				],
				'context' => $this->build_context(),
				'colors'  => [ 'yellow', 'green', 'blue', 'pink', 'orange' ],
				'selectors' => [
					'content' => '.post-content',
					'toolbar' => '.czcr-toolbar',
				],
				'i18n'    => [
					'highlight'       => __( 'Evidenzia', 'cz-highlights' ),
					'add_note_btn'    => __( 'Aggiungi Nota', 'cz-highlights' ),
					'save_note'       => __( 'Salva nota', 'cz-highlights' ),
					'delete'          => __( 'Elimina', 'cz-highlights' ),
					'add_note'        => __( 'Aggiungi una nota…', 'cz-highlights' ),
					'my_notes'        => __( 'Le mie note', 'cz-highlights' ),
					'no_highlights'   => __( 'Nessuna evidenziazione in questo articolo.', 'cz-highlights' ),
					'note_label'      => __( 'Nota', 'cz-highlights' ),
					'close'           => __( 'Chiudi', 'cz-highlights' ),
					'displaced_msg'   => __( 'Il testo di questa evidenziazione potrebbe essere cambiato.', 'cz-highlights' ),
					'orphaned_msg'    => __( 'Questa evidenziazione non è più trovabile nel testo.', 'cz-highlights' ),
					'loading'         => __( 'Caricamento…', 'cz-highlights' ),
					'open_volume'     => __( 'Apri', 'cz-highlights' ),
					'back_to_volumes' => __( 'Tutti i volumi', 'cz-highlights' ),
					'edit'            => __( 'Modifica', 'cz-highlights' ),
					'save'            => __( 'Salva', 'cz-highlights' ),
					'cancel'          => __( 'Annulla', 'cz-highlights' ),
					'confirm_delete'  => __( 'Eliminare questa nota?', 'cz-highlights' ),
					'no_notes_volume' => __( 'Nessuna nota in questo volume.', 'cz-highlights' ),
					'no_notes_any'    => __( 'Nessuna nota ancora.', 'cz-highlights' ),
					'goto'            => __( 'Vai', 'cz-highlights' ),
					'error_loading'    => __( 'Errore nel caricamento.', 'cz-highlights' ),
					'standalone_title'    => __( 'Note sparse', 'cz-highlights' ),
					'collapse_all'        => __( 'Comprimi', 'cz-highlights' ),
					'expand_all'          => __( 'Espandi', 'cz-highlights' ),
					'add_article_note'    => __( 'Aggiungi nota', 'cz-highlights' ),
					'edit_article_note'   => __( 'Modifica nota', 'cz-highlights' ),
					'article_note_title'  => __( "Nota sull'articolo", 'cz-highlights' ),
					'article_note_label'  => __( 'Considerazioni Personali', 'cz-highlights' ),
					'expand'              => __( 'Mostra tutto', 'cz-highlights' ),
					'collapse_text'       => __( 'Riduci', 'cz-highlights' ),
				],
			];
		} else {
			$config = [
				'version'   => CZH_VERSION,
				'user'      => [ 'loggedIn' => false, 'id' => 0 ],
				'loginUrl'  => wp_login_url( get_permalink() ),
				'context'   => $this->build_context(),
				'selectors' => [
					'content' => '.post-content',
					'toolbar' => '.czcr-toolbar',
				],
				'i18n'      => [
					'highlight' => __( 'Evidenzia', 'cz-highlights' ),
				],
			];
		}

		wp_enqueue_script( 'czh-js' );
		wp_add_inline_script( 'czh-js', 'window.CZH = ' . wp_json_encode( $config ) . ';', 'before' );

		list( $css_url, $css_ver ) = $get_asset( 'assets/css/czh.css' );
		wp_enqueue_style( 'czh', $css_url, [], $css_ver );
	}

	private function build_context() {
		global $post;

		if ( is_singular( 'post' ) && $post instanceof WP_Post ) {
			return [
				'type'      => 'post',
				'postId'    => (int) $post->ID,
				'pageNum'   => max( 1, (int) get_query_var( 'page' ) ?: 1 ),
				'volumeId'  => $this->get_volume_for_post( $post->ID ),
				'permalink' => get_permalink( $post ),
			];
		}

		if ( is_singular( 'volume' ) && $post instanceof WP_Post ) {
			return [
				'type'      => 'volume',
				'volumeId'  => (int) $post->ID,
				'postId'    => null,
				'permalink' => get_permalink( $post ),
			];
		}

		if ( $this->is_notes_page() ) {
			return [
				'type'     => 'notes-page',
				'postId'   => null,
				'volumeId' => null,
			];
		}

		return null;
	}

	private function is_notes_page() {
		global $post;
		return $post instanceof WP_Post && has_shortcode( $post->post_content, 'czh_my_notes' );
	}

	private function get_notes_page_url() {
		$page_id = (int) get_option( 'czh_notes_page_id', 0 );
		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) return $url;
		}
		return home_url( '/utente/note' );
	}

	private function get_volume_for_post( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'cz_volume_items';

		// Check if the table exists before querying
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return null;
		}

		$volume_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT volume_id FROM {$table} WHERE post_id = %d LIMIT 1", (int) $post_id )
		);

		return $volume_id ? (int) $volume_id : null;
	}

	/** ---- DB upgrade ---- */

	public function maybe_upgrade_db() {
		if ( get_option( 'czh_db_version' ) !== CZH_VERSION ) {
			CZH_PostNotes_DB::create_table();
			update_option( 'czh_db_version', CZH_VERSION );
		}
	}

	/** ---- Post note bar (appended after article content) ---- */

	public function append_post_note_bar( $content ) {
		if ( ! is_singular( 'post' ) || ! is_user_logged_in() || ! $this->highlights_enabled() ) {
			return $content;
		}
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$content .= '<div id="czh-post-note-bar" class="czh-post-note-bar" aria-live="polite"></div>';
		return $content;
	}

	/** ---- Drawer HTML ---- */

	public function print_drawer() {
		if ( ! $this->highlights_enabled() ) {
			return;
		}
		if ( ! is_singular( 'post' ) && ! is_singular( 'volume' ) ) {
			return;
		}
		?>
		<aside id="czh-drawer" class="czh-drawer" aria-hidden="true" aria-label="<?php esc_attr_e( 'Le mie note', 'cz-highlights' ); ?>">
			<div class="czh-drawer__header">
				<h2 class="czh-drawer__title"><?php esc_html_e( 'Le mie note', 'cz-highlights' ); ?></h2>
				<div class="czh-drawer__header-actions">
					<a href="<?php echo esc_url( $this->get_notes_page_url() ); ?>" class="czh-drawer__all-notes-btn" aria-label="<?php esc_attr_e( 'Tutte le note', 'cz-highlights' ); ?>">
						<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
						</svg>
					</a>
					<button type="button" class="czh-drawer__close" aria-label="<?php esc_attr_e( 'Chiudi', 'cz-highlights' ); ?>">
						<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor">
							<path d="M18 6 6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
						</svg>
					</button>
				</div>
			</div>
			<div class="czh-drawer__body">
				<p class="czh-drawer__empty"><?php esc_html_e( 'Nessuna evidenziazione in questo articolo.', 'cz-highlights' ); ?></p>
			</div>
		</aside>
		<div class="czh-drawer-backdrop" data-czh-drawer-close></div>
		<?php
	}

	/** ---- Notes page shortcode ---- */

	public function render_notes_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Accedi per vedere le tue note.', 'cz-highlights' ) . '</p>';
		}
		if ( ! $this->highlights_enabled() ) {
			return '';
		}
		return '<h1 class="czh-notes__page-title">' . esc_html__( 'Le Mie Note', 'cz-highlights' ) . '</h1>'
			. '<div id="czh-notes-app" class="czh-notes-app"><p class="czh-notes__loading">' . esc_html__( 'Caricamento…', 'cz-highlights' ) . '</p></div>';
	}

	/** ---- Nav user menu item ---- */

	public function render_nav_menu_item() {
		if ( ! $this->highlights_enabled() ) {
			return;
		}

		$svg = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>';

		if ( is_singular( 'post' ) || is_singular( 'volume' ) ) {
			// On article/volume pages the JS is loaded — use a button to open the drawer.
			?>
			<button type="button" role="menuitem" class="czh-nav-notes-btn" data-czh-open-drawer>
				<?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Le Mie Note', 'cz-highlights' ); ?></span>
			</button>
			<?php
		} else {
			// On all other pages, navigate to the dedicated notes page.
			?>
			<a role="menuitem" href="<?php echo esc_url( $this->get_notes_page_url() ); ?>">
				<?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Le Mie Note', 'cz-highlights' ); ?></span>
			</a>
			<?php
		}
	}
}

CZ_Highlights::instance();
