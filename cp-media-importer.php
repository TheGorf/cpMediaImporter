<?php
/**
 * Plugin Name: ClassicPress Media Importer
 * Description: Scan a specified directory for media files and import them into the ClassicPress/WordPress Media Library.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPMediaImporter {
	const OPTION_DIR  = 'cpmi_source_dir';
	const OPTION_LAST = 'cpmi_last_run';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_cpmi_run', [ $this, 'handle_run' ] );
	}

	public function register_settings() {
		register_setting( 'cpmi_settings', self::OPTION_DIR, [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_path' ],
		] );
	}

	public function sanitize_path( $path ) {
		$path = trim( (string) $path );
		// Normalize directory separators and remove trailing slashes
		$path = rtrim( wp_normalize_path( $path ), '/' );
		return $path;
	}

	public function register_admin_page() {
		add_media_page(
			__( 'Media Importer', 'cpmi' ),
			__( 'Media Importer', 'cpmi' ),
			'manage_options',
			'cpmi',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to access this page.' ) );
		}

		$dir      = get_option( self::OPTION_DIR, '' );
		$last_run = get_option( self::OPTION_LAST, '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ClassicPress Media Importer', 'cpmi' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'cpmi_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cpmi_source_dir"><?php esc_html_e( 'Source Directory', 'cpmi' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_DIR ); ?>" id="cpmi_source_dir" type="text" class="regular-text code" value="<?php echo esc_attr( $dir ); ?>" placeholder="/path/to/media" />
							<p class="description"><?php esc_html_e( 'Absolute server path to the folder to scan. Files will be copied into the site\'s uploads directory.', 'cpmi' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'cpmi' ) ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Run Import', 'cpmi' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cpmi_run' ); ?>
				<input type="hidden" name="action" value="cpmi_run" />
				<?php submit_button( __( 'Scan & Import Now', 'cpmi' ), 'primary', 'submit', false ); ?>
				<?php if ( $last_run ) : ?>
					<p class="description"><?php printf( esc_html__( 'Last run: %s', 'cpmi' ), esc_html( $last_run ) ); ?></p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	public function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions.' ) );
		}
		check_admin_referer( 'cpmi_run' );

		$dir = get_option( self::OPTION_DIR, '' );
		if ( empty( $dir ) || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			wp_redirect( add_query_arg( [ 'page' => 'cpmi', 'cpmi_msg' => rawurlencode( __( 'Invalid or unreadable source directory.', 'cpmi' ) ) ], admin_url( 'upload.php' ) ) );
			exit;
		}

		@set_time_limit( 0 );
		$results = $this->import_from_directory( $dir );

		update_option( self::OPTION_LAST, current_time( 'mysql' ) );

		$msg = sprintf(
			/* translators: 1: total scanned, 2: imported, 3: skipped, 4: errors */
			__( 'Scanned %1$d files. Imported %2$d. Skipped %3$d. Errors %4$d.', 'cpmi' ),
			$results['scanned'],
			$results['imported'],
			$results['skipped'],
			$results['errors']
		);

		wp_redirect( add_query_arg( [ 'page' => 'cpmi', 'cpmi_msg' => rawurlencode( $msg ) ], admin_url( 'upload.php' ) ) );
		exit;
	}

	/**
	 * Recursively scan a directory and import supported media files.
	 *
	 * @param string $source_dir Absolute path to scan.
	 * @return array{scanned:int,imported:int,skipped:int,errors:int}
	 */
	private function import_from_directory( $source_dir ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$counts = [ 'scanned' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => 0 ];

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS )
		);

		$mime_map = wp_get_mime_types(); // extension => mime
		$allowed_exts = array_keys( $mime_map );

		$uploads = wp_upload_dir();
		$upload_basedir = wp_normalize_path( $uploads['basedir'] );
		$upload_baseurl = $uploads['baseurl'];

		foreach ( $it as $splFile ) {
			/** @var SplFileInfo $splFile */
			if ( ! $splFile->isFile() ) { continue; }

			$counts['scanned']++;
			$src_path = wp_normalize_path( $splFile->getPathname() );
			$basename = $splFile->getBasename();
			$ext = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );

			if ( ! in_array( $ext, $allowed_exts, true ) ) {
				$counts['skipped']++;
				continue;
			}

			// Determine the destination path inside current uploads subdir (e.g., /uploads/2025/10)
			$uploads = wp_upload_dir(); // refresh to respect date changes
			$dest_dir = wp_normalize_path( $uploads['path'] );
			wp_mkdir_p( $dest_dir );

			$unique_name = wp_unique_filename( $dest_dir, $basename );
			$dest_path  = trailingslashit( $dest_dir ) . $unique_name;
			$relative   = ltrim( trailingslashit( $uploads['subdir'] ) . $unique_name, '/' );
			$url        = trailingslashit( $uploads['url'] ) . $unique_name;

			// Avoid duplicate imports by checking existing attachment with same _wp_attached_file
			$existing = get_posts( [
				'post_type'  => 'attachment',
				'post_status'=> 'inherit',
				'numberposts'=> 1,
				'fields'     => 'ids',
				'meta_query' => [ [
					'key'   => '_wp_attached_file',
					'value' => $relative,
				] ],
			] );
			if ( ! empty( $existing ) ) {
				$counts['skipped']++;
				continue;
			}

			// Copy file into uploads
			if ( ! @copy( $src_path, $dest_path ) ) {
				$counts['errors']++;
				continue;
			}

			// Set proper permissions
			$stat  = stat( dirname( $dest_path ) );
			$perms = $stat['mode'] & 0000666;
			@chmod( $dest_path, $perms );

			$filetype = wp_check_filetype( $unique_name, null );
			$attachment = [
				'guid'           => $url,
				'post_mime_type' => $filetype['type'] ?: 'application/octet-stream',
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $unique_name ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			];

			$attach_id = wp_insert_attachment( $attachment, $dest_path, 0 );
			if ( is_wp_error( $attach_id ) || ! $attach_id ) {
				@unlink( $dest_path );
				$counts['errors']++;
				continue;
			}

			// Update required meta linking to relative path inside uploads
			update_post_meta( $attach_id, '_wp_attached_file', $relative );

			// Generate and save attachment metadata (thumbnails for images, etc.)
			$metadata = wp_generate_attachment_metadata( $attach_id, $dest_path );
			if ( $metadata ) {
				wp_update_attachment_metadata( $attach_id, $metadata );
			}

			$counts['imported']++;
		}

		return $counts;
	}
}

new CPMediaImporter();

// Admin notice for operation result
add_action( 'admin_notices', function () {
	if ( ! is_admin() ) { return; }
	if ( ! isset( $_GET['cpmi_msg'] ) ) { return; }
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$msg = wp_unslash( $_GET['cpmi_msg'] );
	?>
	<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
	<?php
} );
