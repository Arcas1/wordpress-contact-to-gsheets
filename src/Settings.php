<?php

namespace C2GS;

/**
 * Settings page (Settings -> Contact to GSheets) plus the admin-post
 * handlers for the OAuth connect / disconnect flow and admin notices.
 */
final class Settings {

	public const SETTINGS_OPTION = 'c2gs_settings';
	public const PAGE_SLUG       = 'contact-to-gsheets';
	public const STATE_NONCE     = 'c2gs_oauth';

	public function __construct(
		private GoogleAuth $auth,
		private ErrorLog $log
	) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addPage' ] );
		add_action( 'admin_init', [ $this, 'registerSetting' ] );
		add_action( 'admin_post_c2gs_oauth_start', [ $this, 'handleOauthStart' ] );
		add_action( 'admin_post_' . GoogleAuth::CALLBACK_ACTION, [ $this, 'handleOauthCallback' ] );
		add_action( 'admin_post_c2gs_oauth_disconnect', [ $this, 'handleDisconnect' ] );
		add_action( 'admin_post_c2gs_dismiss_failures', [ $this, 'handleDismissFailures' ] );
		add_action( 'admin_notices', [ $this, 'renderNotices' ] );
	}

	public function redirectUri(): string {
		return admin_url( 'admin-post.php?action=' . GoogleAuth::CALLBACK_ACTION );
	}

	public function addPage(): void {
		add_options_page(
			__( 'Contact to GSheets', 'contact-to-gsheets' ),
			__( 'Contact to GSheets', 'contact-to-gsheets' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'renderPage' ]
		);
	}

	public function registerSetting(): void {
		register_setting(
			'c2gs_group',
			self::SETTINGS_OPTION,
			[ 'type' => 'array', 'sanitize_callback' => [ $this, 'sanitize' ] ]
		);
	}

	/**
	 * @param mixed $input
	 * @return array{client_id:string,client_secret:string,spreadsheet_id:string,tab_name:string}
	 */
	public function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : [];
		$current = get_option( self::SETTINGS_OPTION, [] );
		$current = is_array( $current ) ? $current : [];

		$clientId     = sanitize_text_field( $input['client_id'] ?? '' );
		$clientSecret = sanitize_text_field( $input['client_secret'] ?? '' );
		$tabName      = sanitize_text_field( $input['tab_name'] ?? '' );
		if ( '' === $tabName ) {
			$tabName = 'Submissions';
		}

		$spreadsheetId = sanitize_text_field( $input['spreadsheet_id'] ?? '' );
		if ( '' !== $spreadsheetId && ! preg_match( '/^[A-Za-z0-9_-]+$/', $spreadsheetId ) ) {
			add_settings_error(
				self::SETTINGS_OPTION,
				'bad_spreadsheet_id',
				__( 'Spreadsheet ID contains invalid characters; keeping the previous value.', 'contact-to-gsheets' )
			);
			$spreadsheetId = (string) ( $current['spreadsheet_id'] ?? '' );
		}

		return [
			'client_id'      => $clientId,
			'client_secret'  => $clientSecret,
			'spreadsheet_id' => $spreadsheetId,
			'tab_name'       => $tabName,
		];
	}

	public function handleOauthStart(): void {
		$this->assertCap();
		$state = wp_create_nonce( self::STATE_NONCE );
		wp_redirect( $this->auth->consentUrl( $state ) );
		exit;
	}

	public function handleOauthCallback(): void {
		$this->assertCap();
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		$status = 'error';
		if ( $code && wp_verify_nonce( $state, self::STATE_NONCE ) && $this->auth->exchangeCode( $code ) ) {
			$status = 'connected';
		}
		wp_safe_redirect( add_query_arg( 'c2gs_oauth', $status, $this->settingsUrl() ) );
		exit;
	}

	public function handleDisconnect(): void {
		$this->assertCap();
		check_admin_referer( 'c2gs_disconnect' );
		$this->auth->disconnect();
		wp_safe_redirect( add_query_arg( 'c2gs_oauth', 'disconnected', $this->settingsUrl() ) );
		exit;
	}

	public function handleDismissFailures(): void {
		$this->assertCap();
		check_admin_referer( 'c2gs_dismiss_failures' );
		delete_transient( 'c2gs_fail_count' );
		$this->log->clear();
		wp_safe_redirect( $this->settingsUrl() );
		exit;
	}

	public function renderNotices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->formVibesActive() ) {
			$this->notice( 'error', __( 'Contact to GSheets needs the Form Vibes plugin active to capture submissions.', 'contact-to-gsheets' ) );
		}

		if ( get_transient( 'c2gs_not_connected' ) ) {
			$this->notice(
				'warning',
				sprintf(
					/* translators: %s: settings page URL */
					__( 'Contact to GSheets is not connected to Google. <a href="%s">Open settings</a>.', 'contact-to-gsheets' ),
					esc_url( $this->settingsUrl() )
				)
			);
		}

		$failCount = (int) get_transient( 'c2gs_fail_count' );
		if ( $failCount > 0 ) {
			$this->notice(
				'error',
				sprintf(
					/* translators: 1: failure count, 2: settings page URL */
					_n(
						'%1$d Form Vibes submission failed to sync to Google Sheets. <a href="%2$s">Details</a>.',
						'%1$d Form Vibes submissions failed to sync to Google Sheets. <a href="%2$s">Details</a>.',
						$failCount,
						'contact-to-gsheets'
					),
					$failCount,
					esc_url( $this->settingsUrl() )
				)
			);
		}
	}

	public function renderPage(): void {
		$this->assertCap();
		$tab = ( isset( $_GET['tab'] ) && 'guide' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ? 'guide' : 'settings';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Contact to GSheets', 'contact-to-gsheets' ); ?></h1>
			<?php $this->renderTabs( $tab ); ?>
			<?php
			if ( 'guide' === $tab ) {
				( new SetupGuide() )->render(
					$this->redirectUri(),
					$this->tabUrl( 'settings' ),
					$this->tabUrl( 'guide' )
				);
			} else {
				$this->renderSettingsTab();
			}
			?>
		</div>
		<?php
	}

	private function renderTabs( string $active ): void {
		$tabs = [
			'settings' => __( 'Settings', 'contact-to-gsheets' ),
			'guide'    => __( 'Setup guide', 'contact-to-gsheets' ),
		];
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $this->tabUrl( $key ) ),
				$active === $key ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';
	}

	private function tabUrl( string $tab ): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=' . $tab );
	}

	private function renderSettingsTab(): void {
		$settings  = get_option( self::SETTINGS_OPTION, [] );
		$settings  = is_array( $settings ) ? $settings : [];
		$connected = $this->auth->isConnected();
		?>
			<h2><?php esc_html_e( 'Google connection', 'contact-to-gsheets' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: URL of the in-plugin setup guide tab */
					wp_kses(
						__( 'First time? Follow the <a href="%s">step-by-step setup guide</a> to create the OAuth client and get the Client ID and Client Secret.', 'contact-to-gsheets' ),
						[ 'a' => [ 'href' => [] ] ]
					),
					esc_url( $this->tabUrl( 'guide' ) )
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'Add this exact redirect URI to your Google Cloud OAuth client (type: Web application):', 'contact-to-gsheets' ); ?><br />
				<code><?php echo esc_html( $this->redirectUri() ); ?></code>
			</p>
			<p>
				<?php if ( $connected ) : ?>
					<strong style="color:#1a7f37;"><?php esc_html_e( 'Connected', 'contact-to-gsheets' ); ?></strong>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="c2gs_oauth_disconnect" />
						<?php wp_nonce_field( 'c2gs_disconnect' ); ?>
						<button class="button"><?php esc_html_e( 'Disconnect', 'contact-to-gsheets' ); ?></button>
					</form>
				<?php else : ?>
					<strong style="color:#b32d2e;"><?php esc_html_e( 'Not connected', 'contact-to-gsheets' ); ?></strong>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin-post.php?action=c2gs_oauth_start' ) ); ?>">
						<?php esc_html_e( 'Connect Google', 'contact-to-gsheets' ); ?>
					</a>
				<?php endif; ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'c2gs_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="c2gs_client_id"><?php esc_html_e( 'Google Client ID', 'contact-to-gsheets' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[client_id]" id="c2gs_client_id" type="text" class="regular-text" value="<?php echo esc_attr( $settings['client_id'] ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="c2gs_client_secret"><?php esc_html_e( 'Google Client Secret', 'contact-to-gsheets' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[client_secret]" id="c2gs_client_secret" type="password" class="regular-text" value="<?php echo esc_attr( $settings['client_secret'] ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="c2gs_spreadsheet_id"><?php esc_html_e( 'Spreadsheet ID', 'contact-to-gsheets' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[spreadsheet_id]" id="c2gs_spreadsheet_id" type="text" class="regular-text" value="<?php echo esc_attr( $settings['spreadsheet_id'] ?? '' ); ?>" />
							<p class="description"><?php esc_html_e( 'The long ID from the sheet URL: docs.google.com/spreadsheets/d/THIS_PART/edit', 'contact-to-gsheets' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="c2gs_tab_name"><?php esc_html_e( 'Tab name', 'contact-to-gsheets' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[tab_name]" id="c2gs_tab_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings['tab_name'] ?? 'Submissions' ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Recent sync failures', 'contact-to-gsheets' ); ?></h2>
			<?php $failures = $this->log->all(); ?>
			<?php if ( empty( $failures ) ) : ?>
				<p><?php esc_html_e( 'None recorded.', 'contact-to-gsheets' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="c2gs_dismiss_failures" />
					<?php wp_nonce_field( 'c2gs_dismiss_failures' ); ?>
					<button class="button"><?php esc_html_e( 'Clear failure log', 'contact-to-gsheets' ); ?></button>
				</form>
				<table class="widefat striped" style="margin-top:8px;">
					<thead><tr>
						<th><?php esc_html_e( 'Time', 'contact-to-gsheets' ); ?></th>
						<th><?php esc_html_e( 'Form', 'contact-to-gsheets' ); ?></th>
						<th><?php esc_html_e( 'HTTP', 'contact-to-gsheets' ); ?></th>
						<th><?php esc_html_e( 'Message', 'contact-to-gsheets' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $failures as $f ) : ?>
						<tr>
							<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $f['time'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( ( $f['plugin_name'] ?? '' ) . ' #' . ( $f['form_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $f['http_code'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $f['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php
	}

	private function settingsUrl(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	private function assertCap(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'contact-to-gsheets' ) );
		}
	}

	private function formVibesActive(): bool {
		return in_array(
			'form-vibes/form-vibes.php',
			(array) get_option( 'active_plugins', [] ),
			true
		);
	}

	private function notice( string $level, string $html ): void {
		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( $level ),
			wp_kses( $html, [ 'a' => [ 'href' => [] ], 'strong' => [] ] )
		);
	}
}
