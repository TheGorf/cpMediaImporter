<?php
/**
 * Plugin Name: ClassicPress Media Importer
 * Description: Scan a specified directory for media files and import them into the ClassicPress/WordPress Media Library.
 * Version:     1.5.0
 * Author:      Geoff Sweet (github.com/thegorf)
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CPMediaImporter {
	const OPTION_DIR           = 'cpmi_source_dir';
	const OPTION_LAST          = 'cpmi_last_run';
	const OPTION_CLEAR_MEDIA   = 'cpmi_clear_media';
	const TRANSIENT_KEY_PREFIX = 'cpmi_log_'; // per-user transient key prefix

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_cpmi_run', array( $this, 'handle_run' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
	}

	public function register_settings() {
		register_setting( 'cpmi_settings', self::OPTION_DIR, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_path' ),
		) );
		register_setting( 'cpmi_settings', self::OPTION_CLEAR_MEDIA, array(
			'type'              => 'boolean',
			'sanitize_callback' => function( $v ) { return $v ? 1 : 0; },
			'default'           => 0,
		) );
	}

	public function sanitize_path( $path ) {
		$path = trim( (string) $path );
		return rtrim( wp_normalize_path( $path ), '/' );
	}

	public function register_admin_page() {
		add_media_page(
			__( 'Media Importer', 'cpmi' ),
			__( 'Media Importer', 'cpmi' ),
			'manage_options',
			'cpmi',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'You do not have permission to access this page.' ) ); }

		$dir        = get_option( self::OPTION_DIR, '' );
		$last_run   = get_option( self::OPTION_LAST, '' );
		$clear_media= (int) get_option( self::OPTION_CLEAR_MEDIA, 0 );
		$log_key    = self::TRANSIENT_KEY_PREFIX . get_current_user_id();
		$log_data   = get_transient( $log_key );
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
							<p class="description"><?php esc_html_e( 'Absolute server path to the folder to scan. Files will be copied into the site\'s uploads directory, preserving the source folder structure.', 'cpmi' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Clear Media', 'cpmi' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_CLEAR_MEDIA ); ?>" value="1" <?php checked( 1, $clear_media ); ?> />
								<?php esc_html_e( 'Before importing, delete ALL items in the Media Library (permanent).', 'cpmi' ); ?>
							</label>
							<p class="description" style="color:#b32d2e;"><strong><?php esc_html_e( 'Warning:', 'cpmi' ); ?></strong> <?php esc_html_e( 'This permanently deletes every attachment and its files from the server. There is no undo.', 'cpmi' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'cpmi' ) ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Run Import', 'cpmi' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Run the importer now? If "Clear Media" is ON, ALL existing media will be deleted first.', 'cpmi' ) ); ?>');">
				<?php wp_nonce_field( 'cpmi_run' ); ?>
				<input type="hidden" name="action" value="cpmi_run" />
				<?php submit_button( __( 'Scan & Import Now', 'cpmi' ), 'primary', 'submit', false ); ?>
				<?php if ( $last_run ) : ?>
					<p class="description"><?php printf( esc_html__( 'Last run: %s', 'cpmi' ), esc_html( $last_run ) ); ?></p>
				<?php endif; ?>
			</form>

			<?php if ( is_array( $log_data ) && ! empty( $log_data['lines'] ) ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Import Log', 'cpmi' ); ?></h2>
				<div class="notice notice-info" style="max-height:320px; overflow:auto;">
					<p><strong><?php echo esc_html( $log_data['summary'] ); ?></strong></p>
					<ul>
						<?php foreach ( $log_data['lines'] as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php delete_transient( $log_key ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Insufficient permissions.' ) ); }
		check_admin_referer( 'cpmi_run' );

		$dir      = get_option( self::OPTION_DIR, '' );
		$clear_on = (int) get_option( self::OPTION_CLEAR_MEDIA, 0 );
		$log_key  = self::TRANSIENT_KEY_PREFIX . get_current_user_id();

		if ( empty( $dir ) || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			set_transient( $log_key, array(
				'summary' => __( 'Invalid or unreadable source directory.', 'cpmi' ),
				'lines'   => array( sprintf( __( 'Provided: %s', 'cpmi' ), $dir ) ),
			), 15 * MINUTE_IN_SECONDS );
			wp_redirect( add_query_arg( array( 'page' => 'cpmi' ), admin_url( 'upload.php' ) ) );
			exit;
		}

		@set_time_limit( 0 );

		$prelog = array();
		if ( $clear_on ) {
			$clear = $this->clear_media_library();
			$prelog[] = sprintf( __( 'Clear Media: deleted %1$d items, errors %2$d.', 'cpmi' ), $clear['deleted'], $clear['errors'] );
			if ( ! empty( $clear['lines'] ) ) {
				$prelog   = array_merge( $prelog, $clear['lines'] );
			}
		}

		$results = $this->import_from_directory( $dir );

		update_option( self::OPTION_LAST, current_time( 'mysql' ) );

		$summary = sprintf( __( 'Scanned %1$d files. Imported %2$d. Skipped %3$d. Errors %4$d.', 'cpmi' ), $results['scanned'], $results['imported'], $results['skipped'], $results['errors'] );
		$lines   = array_merge( $prelog, $results['log'] );
		set_transient( $log_key, array( 'summary' => $summary, 'lines' => $lines ), 15 * MINUTE_IN_SECONDS );

		wp_redirect( add_query_arg( array( 'page' => 'cpmi', 'cpmi_done' => 1 ), admin_url( 'upload.php' ) ) );
		exit;
	}

	/**
	 * Permanently delete ALL media library items.
	 * @return array{deleted:int,errors:int,lines:array}
	 */
	private function clear_media_library() {
		$deleted = 0; $errors = 0; $lines = array();

		$batch = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'suppress_filters'=> true,
		) );

		if ( empty( $batch ) ) {
			$lines[] = __( 'Clear Media: library already empty.', 'cpmi' );
			return compact( 'deleted', 'errors', 'lines' );
		}

		foreach ( $batch as $att_id ) {
			$title = get_the_title( $att_id );
			$result = wp_delete_attachment( $att_id, true ); // force delete
			if ( $result ) {
				$deleted++;
				$lines[] = sprintf( __( 'Deleted: %s (ID %d)', 'cpmi' ), $title, $att_id );
			} else {
				$errors++;
				$lines[] = sprintf( __( 'Failed to delete attachment ID %d', 'cpmi' ), $att_id );
			}
		}

		return compact( 'deleted', 'errors', 'lines' );
	}

	private function import_from_directory( $source_dir ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$counts = array( 'scanned' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => 0 );
		$log    = array();

		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $splFile ) {
			if ( ! $splFile instanceof SplFileInfo || ! $splFile->isFile() ) { continue; }

			$counts['scanned']++;
			$src_path = wp_normalize_path( $splFile->getPathname() );
			$basename = $splFile->getBasename();

			// Core MIME detection (grouped extensions like jpg|jpeg|jpe)
			$pretype = wp_check_filetype( $basename, null );
			if ( empty( $pretype['type'] ) ) {
				$ext = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
				$counts['skipped']++;
				$log[] = "Skipped $basename: unsupported extension .$ext (no registered MIME type)";
				continue;
			}

			$uploads = wp_upload_dir();
			if ( ! empty( $uploads['error'] ) ) {
				$counts['errors']++;
				$log[] = 'Uploads error: ' . $uploads['error'];
				continue;
			}
			$basedir = wp_normalize_path( $uploads['basedir'] );
			$baseurl = $uploads['baseurl'];

			// Preserve source folder structure relative to $source_dir
			$src_dir_rel = ltrim( str_replace( wp_normalize_path( $source_dir ), '', wp_normalize_path( dirname( $src_path ) ) ), '/' );
			$src_dir_rel = preg_replace( '#/{2,}#', '/', $src_dir_rel );
			$src_dir_rel = trim( $src_dir_rel, '/' );

			$dest_dir = $basedir . ( $src_dir_rel ? '/' . $src_dir_rel : '' );
			if ( ! wp_mkdir_p( $dest_dir ) ) {
				$counts['errors']++;
				$log[] = "Error creating uploads directory: $dest_dir";
				continue;
			}

			$unique_name = wp_unique_filename( $dest_dir, $basename );
			$dest_path   = trailingslashit( $dest_dir ) . $unique_name;
			$relative    = ltrim( ( $src_dir_rel ? $src_dir_rel . '/' : '' ) . $unique_name, '/' );
			$url         = trailingslashit( $baseurl ) . $relative;

			$existing = get_posts( array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => '_wp_attached_file', 'value' => $relative ) ),
			) );
			if ( ! empty( $existing ) ) {
				$counts['skipped']++;
				$log[] = "Skipped $basename: already exists in media library.";
				continue;
			}

			if ( ! @copy( $src_path, $dest_path ) ) {
				$counts['errors']++;
				$log[] = "Error copying $basename to $dest_path.";
				continue;
			}

			$stat  = @stat( dirname( $dest_path ) );
			if ( $stat && isset( $stat['mode'] ) ) { @chmod( $dest_path, $stat['mode'] & 0000666 ); }

			// Confirm MIME for the final stored name as well
			$filetype = wp_check_filetype( $unique_name, null );
			if ( empty( $filetype['type'] ) ) {
				$counts['skipped']++;
				$log[] = "Skipped $basename after copy: unrecognized MIME for $unique_name.";
				@unlink( $dest_path );
				continue;
			}

			$attachment = array(
				'guid'           => $url,
				'post_mime_type' => $filetype['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $unique_name ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attach_id = wp_insert_attachment( $attachment, $dest_path, 0 );
			if ( is_wp_error( $attach_id ) || ! $attach_id ) {
				@unlink( $dest_path );
				$counts['errors']++;
				$log[] = "Error inserting attachment for $basename: " . ( is_wp_error( $attach_id ) ? $attach_id->get_error_message() : 'Unknown' );
				continue;
			}

			update_post_meta( $attach_id, '_wp_attached_file', $relative );
			$metadata = wp_generate_attachment_metadata( $attach_id, $dest_path );
			if ( $metadata ) { wp_update_attachment_metadata( $attach_id, $metadata ); }

			$counts['imported']++;
			$log[] = "Imported $basename successfully.";
		}

		$counts['log'] = $log;
		return $counts;
	}

	public function show_admin_notices() {
		if ( ! is_admin() ) { return; }
		if ( isset( $_GET['cpmi_done'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Import completed. See details below.', 'cpmi' ) . '</p></div>';
		}
	}
}

new CPMediaImporter();
