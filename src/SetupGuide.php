<?php

namespace C2GS;

/**
 * Renders the in-admin "Setup guide" tab: a self-service walkthrough for a
 * site owner to create the Google OAuth client and connect this plugin.
 *
 * Bilingual (English / Spanish). The language follows the site locale and can
 * be overridden with ?guide_lang=en|es. Copy is inline so it works without
 * translation files.
 */
final class SetupGuide {

	private const CONSOLE_PROJECT = 'https://console.cloud.google.com/projectcreate';
	private const CONSOLE_LIBRARY = 'https://console.cloud.google.com/apis/library/sheets.googleapis.com';
	private const CONSOLE_CONSENT = 'https://console.cloud.google.com/auth/overview';
	private const CONSOLE_CREDS   = 'https://console.cloud.google.com/apis/credentials';
	private const ACCOUNT_CONNS   = 'https://myaccount.google.com/connections';

	/**
	 * @param string $redirectUri The plugin's OAuth redirect URI (must be added to the Google client).
	 * @param string $settingsUrl URL of the Settings tab where the fields and Connect button live.
	 * @param string $guideBase   Base URL of this guide tab, for the language switch links.
	 */
	public function render( string $redirectUri, string $settingsUrl, string $guideBase ): void {
		$lang = $this->resolveLang();
		$t    = $this->strings( $lang );

		$this->renderSwitch( $lang, $guideBase );
		?>
		<div class="c2gs-guide" style="max-width:820px;">

			<p style="font-size:14px;"><?php echo esc_html( $t['intro'] ); ?></p>

			<h2><?php echo esc_html( $t['s1_title'] ); ?></h2>
			<ol>
				<li><?php echo wp_kses( sprintf( $t['s1_1'], esc_url( self::CONSOLE_PROJECT ) ), $this->linkTags() ); ?></li>
				<li><?php echo esc_html( $t['s1_2'] ); ?></li>
				<li><?php echo esc_html( $t['s1_3'] ); ?></li>
			</ol>

			<h2><?php echo esc_html( $t['s2_title'] ); ?></h2>
			<ol>
				<li><?php echo wp_kses( sprintf( $t['s2_1'], esc_url( self::CONSOLE_LIBRARY ) ), $this->linkTags() ); ?></li>
				<li><?php echo esc_html( $t['s2_2'] ); ?></li>
			</ol>

			<h2><?php echo esc_html( $t['s3_title'] ); ?></h2>
			<ol>
				<li><?php echo wp_kses( sprintf( $t['s3_1'], esc_url( self::CONSOLE_CONSENT ) ), $this->linkTags() ); ?></li>
				<li><?php echo esc_html( $t['s3_2'] ); ?></li>
				<li><?php echo esc_html( $t['s3_3'] ); ?></li>
				<li><?php echo esc_html( $t['s3_4'] ); ?></li>
				<li><?php echo esc_html( $t['s3_5'] ); ?></li>
			</ol>

			<div class="notice notice-warning inline" style="margin:12px 0;">
				<p><strong><?php echo esc_html( $t['warn_title'] ); ?></strong> <?php echo esc_html( $t['warn_body'] ); ?></p>
			</div>

			<h2><?php echo esc_html( $t['s4_title'] ); ?></h2>
			<ol>
				<li><?php echo wp_kses( sprintf( $t['s4_1'], esc_url( self::CONSOLE_CREDS ) ), $this->linkTags() ); ?></li>
				<li><?php echo esc_html( $t['s4_2'] ); ?></li>
				<li>
					<?php echo esc_html( $t['s4_3'] ); ?><br />
					<code style="display:inline-block;margin-top:6px;padding:6px 8px;background:#f6f7f7;user-select:all;"><?php echo esc_html( $redirectUri ); ?></code><br />
					<span class="description"><?php echo esc_html( $t['s4_3_note'] ); ?></span>
				</li>
				<li><?php echo esc_html( $t['s4_4'] ); ?></li>
			</ol>

			<h2><?php echo esc_html( $t['s5_title'] ); ?></h2>
			<ol>
				<li><?php echo esc_html( $t['s5_1'] ); ?></li>
				<li>
					<?php echo esc_html( $t['s5_2'] ); ?><br />
					<code style="display:inline-block;margin-top:6px;padding:6px 8px;background:#f6f7f7;">docs.google.com/spreadsheets/d/<strong><?php echo esc_html( $t['s5_2_id'] ); ?></strong>/edit</code>
				</li>
				<li><?php echo esc_html( $t['s5_3'] ); ?></li>
			</ol>

			<h2><?php echo esc_html( $t['s6_title'] ); ?></h2>
			<ol>
				<li><?php echo wp_kses( sprintf( $t['s6_1'], esc_url( $settingsUrl ) ), $this->linkTags() ); ?></li>
				<li><?php echo esc_html( $t['s6_2'] ); ?></li>
				<li><?php echo esc_html( $t['s6_3'] ); ?></li>
				<li><?php echo esc_html( $t['s6_4'] ); ?></li>
				<li><?php echo esc_html( $t['s6_5'] ); ?></li>
			</ol>

			<h2><?php echo esc_html( $t['s7_title'] ); ?></h2>
			<ol>
				<li><?php echo esc_html( $t['s7_1'] ); ?></li>
				<li><?php echo esc_html( $t['s7_2'] ); ?></li>
				<li><?php echo esc_html( $t['s7_3'] ); ?></li>
			</ol>

			<h2><?php echo esc_html( $t['tr_title'] ); ?></h2>
			<table class="widefat striped" style="margin-top:8px;">
				<thead><tr>
					<th><?php echo esc_html( $t['tr_head_a'] ); ?></th>
					<th><?php echo esc_html( $t['tr_head_b'] ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $t['tr_rows'] as $row ) : ?>
						<tr>
							<td><?php echo wp_kses( $row[0], $this->linkTags() ); ?></td>
							<td><?php echo esc_html( $row[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html( $t['disc_title'] ); ?></h2>
			<p><?php echo wp_kses( sprintf( $t['disc_body'], esc_url( $settingsUrl ), esc_url( self::ACCOUNT_CONNS ) ), $this->linkTags() ); ?></p>
		</div>
		<?php
	}

	private function resolveLang(): string {
		if ( isset( $_GET['guide_lang'] ) ) {
			$override = sanitize_key( wp_unslash( $_GET['guide_lang'] ) );
			if ( in_array( $override, [ 'en', 'es' ], true ) ) {
				return $override;
			}
		}
		return str_starts_with( determine_locale(), 'es' ) ? 'es' : 'en';
	}

	private function renderSwitch( string $lang, string $guideBase ): void {
		$en = add_query_arg( 'guide_lang', 'en', $guideBase );
		$es = add_query_arg( 'guide_lang', 'es', $guideBase );
		printf(
			'<p style="margin:4px 0 16px;"><a href="%s"%s>English</a> &nbsp;|&nbsp; <a href="%s"%s>Espa&ntilde;ol</a></p>',
			esc_url( $en ),
			'en' === $lang ? ' style="font-weight:600;"' : '',
			esc_url( $es ),
			'es' === $lang ? ' style="font-weight:600;"' : ''
		);
	}

	/** @return array<string,array<string,array<int,string>>> */
	private function linkTags(): array {
		return [
			'a'      => [ 'href' => [], 'target' => [], 'rel' => [] ],
			'strong' => [],
			'code'   => [],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function strings( string $lang ): array {
		$en = [
			'intro'      => 'This plugin sends each form submission to a Google Sheet. To let it do that you create a free Google "OAuth client" once and connect it. It takes about 10 minutes. You need a Google account that can edit the target spreadsheet.',

			's1_title'   => 'Step 1. Create a Google Cloud project',
			's1_1'       => 'Open <a href="%s" target="_blank" rel="noopener">the Google Cloud project page</a> and sign in with the Google account you want to use.',
			's1_2'       => 'Give the project a name such as "wp-contact-to-gsheets" and click Create.',
			's1_3'       => 'Make sure that project is selected in the top bar before continuing.',

			's2_title'   => 'Step 2. Turn on the Google Sheets API',
			's2_1'       => 'Open <a href="%s" target="_blank" rel="noopener">the Google Sheets API page</a>.',
			's2_2'       => 'Click Enable. You do not need the Google Drive API.',

			's3_title'   => 'Step 3. Configure the consent screen',
			's3_1'       => 'Open <a href="%s" target="_blank" rel="noopener">the OAuth consent screen</a> (APIs and Services, then OAuth consent screen).',
			's3_2'       => 'User type: choose Internal if your Google account belongs to a Google Workspace organization and only that organization will connect. Otherwise choose External.',
			's3_3'       => 'Fill in App name, a support email, and a developer contact email. Logo and links are optional.',
			's3_4'       => 'Under Scopes, add "https://www.googleapis.com/auth/spreadsheets" and save. That is the only permission this plugin uses.',
			's3_5'       => 'External only: under Test users, add the exact Google account you will click Connect with, or you will get an "access denied" error.',

			'warn_title' => 'Important for @gmail.com accounts:',
			'warn_body'  => 'while the consent screen is in "Testing" status, Google expires the connection after 7 days and syncing stops silently. On the consent screen click "Publish app" (Publishing status: In production). For the single spreadsheets permission with one user this does not trigger a Google review; you only get a one-time "unverified app" warning that you click through. Google Workspace "Internal" apps are not affected.',

			's4_title'   => 'Step 4. Create the OAuth client',
			's4_1'       => 'Open <a href="%s" target="_blank" rel="noopener">the Credentials page</a> and click Create credentials, then OAuth client ID.',
			's4_2'       => 'Application type: Web application.',
			's4_3'       => 'Under Authorized redirect URIs, click Add URI and paste this exact address:',
			's4_3_note'  => 'It must match character for character (https, no trailing slash). If your site answers on both www and non-www, add both.',
			's4_4'       => 'Click Create. Google shows "Your Client ID" and "Your Client Secret". Either copy both for step 6, or click "Download JSON" and upload that file in step 6 to fill both fields automatically.',

			's5_title'   => 'Step 5. Get the Spreadsheet ID',
			's5_1'       => 'Open (or create) the Google Sheet that will receive submissions. The account you connect with must be able to edit it.',
			's5_2'       => 'Copy the ID from the sheet URL, the part between /d/ and /edit:',
			's5_2_id'    => 'THIS-IS-THE-ID',
			's5_3'       => 'You do not need to add a tab or header row. The plugin creates the "Submissions" tab and the header on the first form submission.',

			's6_title'   => 'Step 6. Enter the values and connect',
			's6_1'       => 'Go to the <a href="%s">Settings tab</a>.',
			's6_2'       => 'Either upload the JSON file from step 4 in "Upload client JSON", or paste Google Client ID and Google Client Secret by hand. Add the Spreadsheet ID. Leave Tab name as "Submissions" unless you want another. Click Save Changes.',
			's6_3'       => 'Click Connect Google and pick the account that can edit the sheet.',
			's6_4'       => 'If you see "Google hasn\'t verified this app", click Advanced, then "Go to (app name) (unsafe)". This is your own app.',
			's6_5'       => 'Approve the Google Sheets permission. You return to the Settings tab showing "Connected".',

			's7_title'   => 'Step 7. Test it',
			's7_1'       => 'Submit one of your forms on the public site.',
			's7_2'       => 'Within a few seconds a new row appears in the Submissions tab of your sheet.',
			's7_3'       => 'Check that "Recent sync failures" on the Settings tab is empty.',

			'tr_title'   => 'If something does not work',
			'tr_head_a'  => 'What you see',
			'tr_head_b'  => 'Cause and fix',
			'tr_rows'    => [
				[ '<code>redirect_uri_mismatch</code>', 'The redirect URI in the OAuth client does not exactly match the one shown in Step 4. Copy it again from this page. Check https vs http, www, and trailing slash.' ],
				[ '<code>access_denied</code> right after choosing the account', 'External app in Testing status and this account is not listed under Test users. Add it, or publish the app (Step 3).' ],
				[ 'Connected, but no rows; failures show 403 or PERMISSION_DENIED', 'The connected account cannot edit that spreadsheet, or the Spreadsheet ID is wrong. Give the account Editor access and re-check the ID.' ],
				[ 'Failures show 404', 'Wrong Spreadsheet ID, or the sheet was deleted.' ],
				[ 'Worked for a week then stopped; failures show <code>invalid_grant</code>', 'External app still in Testing: the connection expired after 7 days. Publish the app (Step 3), then click Connect Google again.' ],
				[ 'A single 401 that then recovers', 'Normal. The short-lived token expired and the plugin refreshed it. Only a repeating 401 is a problem.' ],
				[ '"not connected to Google" notice', 'Connect Google was not completed, or the Spreadsheet ID field is empty.' ],
			],

			'disc_title' => 'Disconnecting later',
			'disc_body'  => 'Use Disconnect on the <a href="%1$s">Settings tab</a> (this revokes the token with Google), and optionally remove the app from <a href="%2$s" target="_blank" rel="noopener">your Google account connections</a>.',
		];

		$es = [
			'intro'      => 'Este plugin envia cada envio de formulario a una hoja de Google Sheets. Para permitirlo, se crea una vez un "cliente OAuth" gratuito de Google y se conecta. Toma unos 10 minutos. Necesitas una cuenta de Google que pueda editar la hoja de destino.',

			's1_title'   => 'Paso 1. Crear un proyecto en Google Cloud',
			's1_1'       => 'Abre <a href="%s" target="_blank" rel="noopener">la pagina de creacion de proyecto de Google Cloud</a> e inicia sesion con la cuenta de Google que quieras usar.',
			's1_2'       => 'Ponle un nombre al proyecto, por ejemplo "wp-contact-to-gsheets", y haz clic en Crear.',
			's1_3'       => 'Asegurate de que ese proyecto este seleccionado en la barra superior antes de continuar.',

			's2_title'   => 'Paso 2. Activar la API de Google Sheets',
			's2_1'       => 'Abre <a href="%s" target="_blank" rel="noopener">la pagina de la API de Google Sheets</a>.',
			's2_2'       => 'Haz clic en Habilitar. No necesitas la API de Google Drive.',

			's3_title'   => 'Paso 3. Configurar la pantalla de consentimiento',
			's3_1'       => 'Abre <a href="%s" target="_blank" rel="noopener">la pantalla de consentimiento de OAuth</a> (APIs y servicios, luego Pantalla de consentimiento de OAuth).',
			's3_2'       => 'Tipo de usuario: elige Interno si tu cuenta de Google pertenece a una organizacion de Google Workspace y solo esa organizacion se conectara. Si no, elige Externo.',
			's3_3'       => 'Completa el nombre de la aplicacion, un correo de soporte y un correo de contacto del desarrollador. El logo y los enlaces son opcionales.',
			's3_4'       => 'En Ambitos (Scopes), agrega "https://www.googleapis.com/auth/spreadsheets" y guarda. Ese es el unico permiso que usa este plugin.',
			's3_5'       => 'Solo Externo: en Usuarios de prueba, agrega la cuenta de Google exacta con la que haras clic en Conectar, o veras un error de "acceso denegado".',

			'warn_title' => 'Importante para cuentas @gmail.com:',
			'warn_body'  => 'mientras la pantalla de consentimiento este en estado "Prueba" (Testing), Google caduca la conexion a los 7 dias y la sincronizacion se detiene sin aviso. En la pantalla de consentimiento haz clic en "Publicar aplicacion" (Estado de publicacion: En produccion). Para el unico permiso de spreadsheets con un solo usuario esto no dispara una revision de Google; solo veras una advertencia de "aplicacion no verificada" una vez, que aceptas. Las aplicaciones "Internas" de Google Workspace no se ven afectadas.',

			's4_title'   => 'Paso 4. Crear el cliente OAuth',
			's4_1'       => 'Abre <a href="%s" target="_blank" rel="noopener">la pagina de Credenciales</a> y haz clic en Crear credenciales, luego ID de cliente de OAuth.',
			's4_2'       => 'Tipo de aplicacion: Aplicacion web.',
			's4_3'       => 'En URIs de redireccionamiento autorizados, haz clic en Agregar URI y pega esta direccion exacta:',
			's4_3_note'  => 'Debe coincidir caracter por caracter (https, sin barra final). Si tu sitio responde en www y sin www, agrega ambas.',
			's4_4'       => 'Haz clic en Crear. Google muestra "Tu ID de cliente" y "Tu secreto de cliente". Copia ambos para el paso 6, o haz clic en "Descargar JSON" y sube ese archivo en el paso 6 para completar los dos campos automaticamente.',

			's5_title'   => 'Paso 5. Obtener el ID de la hoja de calculo',
			's5_1'       => 'Abre (o crea) la hoja de Google Sheets que recibira los envios. La cuenta con la que te conectes debe poder editarla.',
			's5_2'       => 'Copia el ID desde la URL de la hoja, la parte entre /d/ y /edit:',
			's5_2_id'    => 'ESTE-ES-EL-ID',
			's5_3'       => 'No necesitas agregar una pestana ni una fila de encabezado. El plugin crea la pestana "Submissions" y el encabezado en el primer envio.',

			's6_title'   => 'Paso 6. Ingresar los valores y conectar',
			's6_1'       => 'Ve a la <a href="%s">pestana Ajustes</a>.',
			's6_2'       => 'Sube el archivo JSON del paso 4 en "Upload client JSON", o pega Google Client ID y Google Client Secret a mano. Agrega el Spreadsheet ID. Deja Tab name como "Submissions" salvo que quieras otro. Haz clic en Guardar cambios.',
			's6_3'       => 'Haz clic en Connect Google y elige la cuenta que puede editar la hoja.',
			's6_4'       => 'Si ves "Google no ha verificado esta aplicacion", haz clic en Avanzada y luego en "Ir a (nombre de la app) (no seguro)". Es tu propia aplicacion.',
			's6_5'       => 'Aprueba el permiso de Google Sheets. Vuelves a la pestana Ajustes con el estado "Connected".',

			's7_title'   => 'Paso 7. Probarlo',
			's7_1'       => 'Envia uno de tus formularios en el sitio publico.',
			's7_2'       => 'En unos segundos aparece una fila nueva en la pestana Submissions de tu hoja.',
			's7_3'       => 'Verifica que "Recent sync failures" en la pestana Ajustes este vacio.',

			'tr_title'   => 'Si algo no funciona',
			'tr_head_a'  => 'Lo que ves',
			'tr_head_b'  => 'Causa y solucion',
			'tr_rows'    => [
				[ '<code>redirect_uri_mismatch</code>', 'El URI de redireccionamiento del cliente OAuth no coincide exactamente con el del Paso 4. Copialo de nuevo desde esta pagina. Revisa https vs http, www y la barra final.' ],
				[ '<code>access_denied</code> justo despues de elegir la cuenta', 'La app Externa esta en estado Prueba y esta cuenta no esta en Usuarios de prueba. Agregala, o publica la app (Paso 3).' ],
				[ 'Conectado, pero sin filas; los fallos muestran 403 o PERMISSION_DENIED', 'La cuenta conectada no puede editar esa hoja, o el Spreadsheet ID es incorrecto. Da acceso de Editor a la cuenta y verifica el ID.' ],
				[ 'Los fallos muestran 404', 'Spreadsheet ID incorrecto, o la hoja fue eliminada.' ],
				[ 'Funciono una semana y luego se detuvo; los fallos muestran <code>invalid_grant</code>', 'La app Externa sigue en Prueba: la conexion caduco a los 7 dias. Publica la app (Paso 3) y vuelve a hacer clic en Connect Google.' ],
				[ 'Un unico 401 que luego se recupera', 'Normal. El token de corta duracion caduco y el plugin lo renovo. Solo un 401 repetido es un problema.' ],
				[ 'Aviso "not connected to Google"', 'No se completo Connect Google, o el campo Spreadsheet ID esta vacio.' ],
			],

			'disc_title' => 'Desconectar mas adelante',
			'disc_body'  => 'Usa Disconnect en la <a href="%1$s">pestana Ajustes</a> (esto revoca el token con Google), y opcionalmente quita la aplicacion desde <a href="%2$s" target="_blank" rel="noopener">las conexiones de tu cuenta de Google</a>.',
		];

		return 'es' === $lang ? $es : $en;
	}
}
