<?php
/**
 * Translation editor.
 *
 * Lists everything in the translation memory and lets a human correct it. A
 * corrected string is marked as hand-edited and the machine never overwrites it
 * again — retranslating the site will skip it.
 *
 * @package LeadstartTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor screen.
 */
class LEADST_Editor {

	const CAP     = 'manage_options';
	const PER_PAGE = 25;

	/**
	 * Singleton instance.
	 *
	 * @var LEADST_Editor|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return LEADST_Editor
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_post_leadst_save_translations', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the editor submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'leadst-settings',
			__( 'Edit translations', 'leadstart-translate' ),
			__( 'Edit translations', 'leadstart-translate' ),
			self::CAP,
			'leadst-editor',
			array( $this, 'render' )
		);
	}

	/**
	 * Save edited strings.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leadstart-translate' ) );
		}

		check_admin_referer( 'leadst_save_translations' );

		global $wpdb;

		$table   = LEADST_DB::strings_table();
		$touched = array();
		$rows    = isset( $_POST['translation'] ) ? (array) wp_unslash( $_POST['translation'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$saved   = 0;

		foreach ( $rows as $id => $value ) {
			$id    = (int) $id;
			$value = wp_kses_post( (string) $value );

			if ( $id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
			$existing = $wpdb->get_row(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( 'SELECT translated_text, lang FROM %i WHERE id = %d', $table, $id ),
				ARRAY_A
			);

			if ( ! $existing || $existing['translated_text'] === $value ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
			$wpdb->update(
				$table,
				array(
					'translated_text' => $value,
					'status'          => LEADST_DB::STATUS_HUMAN,
					'engine'          => 'human',
					'updated_at'      => current_time( 'mysql' ),
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);

			$touched[ $existing['lang'] ] = true;
			$saved++;
		}

		foreach ( array_keys( $touched ) as $lang ) {
			LEADST_Processor::flush_cache( $lang );
		}

		$redirect = wp_get_referer();
		$redirect = $redirect ? $redirect : admin_url( 'admin.php?page=leadst-editor' );

		wp_safe_redirect( add_query_arg( 'leadst_saved_rows', $saved, $redirect ) );
		exit;
	}

	/**
	 * Editor screen.
	 *
	 * @return void
	 */
	public function render() {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$lang   = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$saved  = isset( $_GET['leadst_saved_rows'] ) ? (int) $_GET['leadst_saved_rows'] : 0;
		// phpcs:enable

		$active = LEADST_Languages::active();

		if ( ! $lang || ! isset( $active[ $lang ] ) ) {
			$lang = (string) key( $active );
		}

		$table  = LEADST_DB::strings_table();
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		// One literal query rather than a WHERE clause glued together from
		// fragments. Each filter is switched off by a sentinel the caller
		// passes -- an empty string, or -1 for the status -- so nothing is
		// ever interpolated into the SQL and every value is bound. MySQL
		// folds the sentinel comparison to a constant, so `lang = %s` still
		// uses its index whenever a language is actually being filtered on.
		$status_code = -1;

		if ( 'pending' === $status ) {
			$status_code = LEADST_DB::STATUS_PENDING;
		} elseif ( 'machine' === $status ) {
			$status_code = LEADST_DB::STATUS_MACHINE;
		} elseif ( 'human' === $status ) {
			$status_code = LEADST_DB::STATUS_HUMAN;
		} elseif ( 'failed' === $status ) {
			$status_code = LEADST_DB::STATUS_FAILED;
		}

		$like = '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE ( %s = '' OR lang = %s )
				   AND ( %d < 0 OR status = %d )
				   AND ( %s = '' OR source_text LIKE %s OR translated_text LIKE %s )",
				$table, $lang, $lang, $status_code, $status_code, $search, $like, $like
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no core API covers it.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_text, translated_text, status FROM %i
				 WHERE ( %s = '' OR lang = %s )
				   AND ( %d < 0 OR status = %d )
				   AND ( %s = '' OR source_text LIKE %s OR translated_text LIKE %s )
				 ORDER BY id ASC
				 LIMIT %d OFFSET %d",
				$table, $lang, $lang, $status_code, $status_code, $search, $like, $like, self::PER_PAGE, $offset
			),
			ARRAY_A
		);

		$pages = (int) ceil( $total / self::PER_PAGE );
		?>
		<div class="wrap leadst-wrap">
			<h1><?php esc_html_e( 'Edit translations', 'leadstart-translate' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of strings saved. */
							esc_html( _n( '%d string saved and locked against machine overwrites.', '%d strings saved and locked against machine overwrites.', $saved, 'leadstart-translate' ) ),
							(int) $saved
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $active ) ) : ?>
				<p><?php esc_html_e( 'Switch a language on first.', 'leadstart-translate' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<form method="get" class="leadst-filters">
				<input type="hidden" name="page" value="leadst-editor" />
				<select name="lang">
					<?php foreach ( $active as $slug => $language ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $lang, $slug ); ?>><?php echo esc_html( $language['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<option value="all" <?php selected( $status, 'all' ); ?>><?php esc_html_e( 'All', 'leadstart-translate' ); ?></option>
					<option value="machine" <?php selected( $status, 'machine' ); ?>><?php esc_html_e( 'Machine', 'leadstart-translate' ); ?></option>
					<option value="human" <?php selected( $status, 'human' ); ?>><?php esc_html_e( 'Hand-edited', 'leadstart-translate' ); ?></option>
					<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Waiting', 'leadstart-translate' ); ?></option>
					<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'leadstart-translate' ); ?></option>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search strings', 'leadstart-translate' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'leadstart-translate' ); ?></button>
				<span class="leadst-muted">
					<?php
					printf(
						/* translators: %s: number of strings. */
						esc_html__( '%s strings', 'leadstart-translate' ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="leadst_save_translations" />
				<?php wp_nonce_field( 'leadst_save_translations' ); ?>

				<table class="widefat striped leadst-editor-table">
					<thead>
						<tr>
							<th style="width:45%"><?php esc_html_e( 'Original', 'leadstart-translate' ); ?></th>
							<th style="width:45%"><?php esc_html_e( 'Translation', 'leadstart-translate' ); ?></th>
							<th style="width:10%"><?php esc_html_e( 'Status', 'leadstart-translate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'Nothing here yet. Run the pre-fill on the Status screen, or visit a translated page.', 'leadstart-translate' ); ?></td></tr>
						<?php endif; ?>

						<?php foreach ( (array) $rows as $row ) : ?>
							<tr>
								<td class="leadst-source"><?php echo esc_html( $row['source_text'] ); ?></td>
								<td>
									<textarea
										name="translation[<?php echo esc_attr( $row['id'] ); ?>]"
										rows="2"
										class="large-text"
										dir="<?php echo esc_attr( ! empty( $active[ $lang ]['rtl'] ) ? 'rtl' : 'ltr' ); ?>"
									><?php echo esc_textarea( (string) $row['translated_text'] ); ?></textarea>
								</td>
								<td>
									<?php
									$labels = array(
										LEADST_DB::STATUS_PENDING => __( 'Waiting', 'leadstart-translate' ),
										LEADST_DB::STATUS_MACHINE => __( 'Machine', 'leadstart-translate' ),
										LEADST_DB::STATUS_HUMAN   => __( 'Edited', 'leadstart-translate' ),
										LEADST_DB::STATUS_FAILED  => __( 'Failed', 'leadstart-translate' ),
									);
									$key = (int) $row['status'];
									printf(
										'<span class="leadst-status leadst-status--%d">%s</span>',
										(int) $key,
										esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : '' )
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save changes', 'leadstart-translate' ) ); ?>
			</form>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
