<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fő plugin osztály – Smart-QR-SaaS v1.0.0
 *
 * Tartalmazza:
 * - Admin menü: linkek, beállítások, hírlevél, elemzés, MI asszisztens, adatvédelem
 * - QR / EAN-13 / Code128 képgenerálás (BaconQrCode + picqer)
 * - Frontend átirányítás: jelszóvédelem, lejárat, kattintásnapló, lojalitás
 * - WooCommerce hozzáférés ellenőrzés
 * - Shortcode-ok: [smart_qr_loyalty], [smart_qr_profile], [smart_qr_businesses], [smart_qr_dashboard]
 * - CSV export
 * - REST API végpontok
 * - Admin CSS/JS enqueue
 */
class Smart_QR_SaaS_Plugin {

    protected static ?self $instance = null;
    protected string $table_name;
    protected string $clicks_table;
    protected string $subs_table;
    protected string $points_table;
    protected string $log_table;
    protected string $tags_table;

    // ── Singleton ─────────────────────────────────────────────────────────────

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name   = $wpdb->prefix . 'smart_qr_links';
        $this->clicks_table = $wpdb->prefix . 'smart_qr_link_clicks';
        $this->subs_table   = $wpdb->prefix . 'smart_qr_newsletter_subscribers';
        $this->points_table = $wpdb->prefix . 'smart_qr_loyalty_points';
        $this->log_table    = $wpdb->prefix . 'smart_qr_loyalty_log';
        $this->tags_table   = $wpdb->prefix . 'smart_qr_link_tags';

        $this->register_hooks();
    }

    // ── Hook regisztráció ──────────────────────────────────────────────────────

    private function register_hooks(): void {
        // Admin
        add_action( 'admin_menu',              [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_admin_assets' ] );

        // Admin POST feldolgozók
        add_action( 'admin_post_smart_qr_saas_save_link',        [ $this, 'handle_save_link' ] );
        add_action( 'admin_post_smart_qr_saas_delete_link',      [ $this, 'handle_delete_link' ] );
        add_action( 'admin_post_smart_qr_saas_toggle_link',      [ $this, 'handle_toggle_link' ] );
        add_action( 'admin_post_smart_qr_saas_save_privacy',     [ $this, 'handle_save_privacy_notice' ] );
        add_action( 'admin_post_smart_qr_saas_save_settings',    [ $this, 'handle_save_settings' ] );
        add_action( 'admin_post_smart_qr_saas_newsletter_send',  [ $this, 'handle_newsletter_send' ] );
        add_action( 'admin_post_smart_qr_saas_add_subscriber',   [ $this, 'handle_add_subscriber' ] );
        add_action( 'admin_post_smart_qr_saas_export_links',     [ $this, 'handle_export_links_csv' ] );

        // AJAX
        add_action( 'wp_ajax_smart_qr_saas_image',        [ $this, 'handle_image_output' ] );
        add_action( 'wp_ajax_nopriv_smart_qr_saas_image', [ $this, 'handle_image_output' ] );
        add_action( 'wp_ajax_smart_qr_saas_chat',         [ $this, 'ajax_ai_chat' ] );

        // Frontend
        add_action( 'template_redirect', [ $this, 'maybe_handle_frontend_redirect' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

        // Shortcode-ok
        add_action( 'init', [ $this, 'register_shortcodes' ] );

        // REST API
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    // ── Admin menü ─────────────────────────────────────────────────────────────

    public function register_admin_menu(): void {
        add_menu_page(
            __( 'Smart QR SaaS', 'smart-qr-saas' ),
            __( 'Smart QR SaaS', 'smart-qr-saas' ),
            'read',
            'smart-qr-saas',
            [ $this, 'render_admin_page' ],
            'dashicons-qrcode',
            56
        );

        $subpages = [
            [ 'smart-qr-saas-settings',  __( 'Beállítások', 'smart-qr-saas' ),   'manage_options', [ $this, 'render_settings_page' ] ],
            [ 'smart-qr-saas-newsletter', __( 'Hírlevél', 'smart-qr-saas' ),     'edit_posts',     [ $this, 'render_newsletter_page' ] ],
            [ 'smart-qr-saas-analytics',  __( 'Elemzés', 'smart-qr-saas' ),      'read',           [ $this, 'render_analytics_page' ] ],
            [ 'smart-qr-saas-ai',         __( 'MI Asszisztens', 'smart-qr-saas' ),'read',           [ $this, 'render_ai_page' ] ],
            [ 'smart-qr-saas-privacy',    __( 'Adatvédelem', 'smart-qr-saas' ),  'manage_options', [ $this, 'render_privacy_page' ] ],
        ];

        foreach ( $subpages as [ $slug, $title, $cap, $cb ] ) {
            add_submenu_page( 'smart-qr-saas', $title, $title, $cap, $slug, $cb );
        }
    }

    // ── Admin eszközök (CSS/JS) ────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'smart-qr-saas' ) === false ) {
            return;
        }
        wp_enqueue_style(
            'smart-qr-saas-admin',
            SMART_QR_SAAS_URL . 'assets/css/admin.css',
            [],
            SMART_QR_SAAS_VERSION
        );
        wp_enqueue_script(
            'smart-qr-saas-admin',
            SMART_QR_SAAS_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            SMART_QR_SAAS_VERSION,
            true
        );
    }

    // ── Frontend eszközök ──────────────────────────────────────────────────────

    public function enqueue_frontend_assets(): void {
        if ( file_exists( SMART_QR_SAAS_PATH . 'assets/css/frontend.css' ) ) {
            wp_enqueue_style(
                'smart-qr-saas-frontend',
                SMART_QR_SAAS_URL . 'assets/css/frontend.css',
                [],
                SMART_QR_SAAS_VERSION
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ADMIN FŐOLDAL
    // ─────────────────────────────────────────────────────────────────────────

    public function render_admin_page(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Be kell jelentkezned.', 'smart-qr-saas' ) );
        }

        $current_user_id = get_current_user_id();

        $status_messages = [
            'ok'           => __( 'Link sikeresen elmentve.', 'smart-qr-saas' ),
            'deleted'      => __( 'Link sikeresen törölve.', 'smart-qr-saas' ),
            'toggled'      => __( 'Link állapota megváltozott.', 'smart-qr-saas' ),
            'no_wc_access' => __( 'Csak aktív WooCommerce vásárlással vagy előfizetéssel hozhatsz létre linket.', 'smart-qr-saas' ),
            'limit'        => __( 'A link elérte a kattintási limitet – ezért inaktiválva lett.', 'smart-qr-saas' ),
            'error'        => __( 'Hiba történt a művelet közben.', 'smart-qr-saas' ),
        ];

        $status_key = isset( $_GET['smart_qr_status'] ) ? sanitize_key( $_GET['smart_qr_status'] ) : '';
        $status_msg = $status_messages[ $status_key ] ?? '';

        global $wpdb;
        $links = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE user_id = %d ORDER BY created_at DESC",
                $current_user_id
            )
        );

        $base_url = admin_url( 'admin.php?page=smart-qr-saas' );
        ?>
        <div class="wrap sqr-wrap">
            <h1 class="sqr-title">
                <span class="dashicons dashicons-qrcode"></span>
                <?php esc_html_e( 'Smart QR SaaS', 'smart-qr-saas' ); ?>
                <span class="sqr-version">v<?php echo esc_html( SMART_QR_SAAS_VERSION ); ?></span>
            </h1>

            <?php if ( $status_msg ) : ?>
                <div class="notice notice-info is-dismissible"><p><?php echo esc_html( $status_msg ); ?></p></div>
            <?php endif; ?>

            <div class="sqr-grid">

                <?php /* ── Új link űrlap ── */ ?>
                <div class="sqr-card">
                    <h2><?php esc_html_e( 'Új link felvétele', 'smart-qr-saas' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Link létrehozásához teljesített WooCommerce rendelés vagy aktív előfizetés szükséges.', 'smart-qr-saas' ); ?>
                    </p>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sqr-link-form">
                        <?php wp_nonce_field( 'smart_qr_saas_save_link', 'smart_qr_saas_nonce' ); ?>
                        <input type="hidden" name="action" value="smart_qr_saas_save_link">

                        <div class="sqr-row">
                            <label for="sqr_title"><?php esc_html_e( 'Cím / Megjegyzés (opcionális)', 'smart-qr-saas' ); ?></label>
                            <input type="text" id="sqr_title" name="title" placeholder="<?php esc_attr_e( 'pl. Nyári akció QR', 'smart-qr-saas' ); ?>">
                        </div>

                        <div class="sqr-row">
                            <label for="sqr_original_url"><?php esc_html_e( 'Eredeti URL', 'smart-qr-saas' ); ?></label>
                            <input type="url" id="sqr_original_url" name="original_url" placeholder="https://pelda.hu/valami">
                            <p class="description"><?php esc_html_e( 'Üresen hagyható, ha bejegyzés ID-t adsz meg.', 'smart-qr-saas' ); ?></p>
                        </div>

                        <div class="sqr-row">
                            <label for="sqr_post_id"><?php esc_html_e( 'Bejegyzés / oldal ID (opcionális)', 'smart-qr-saas' ); ?></label>
                            <input type="number" id="sqr_post_id" name="post_id" min="0" placeholder="123">
                        </div>

                        <div class="sqr-row sqr-row-half">
                            <div>
                                <label for="sqr_code_type"><?php esc_html_e( 'Kód típusa', 'smart-qr-saas' ); ?></label>
                                <select id="sqr_code_type" name="code_type">
                                    <option value="qr"><?php esc_html_e( 'QR-kód', 'smart-qr-saas' ); ?></option>
                                    <option value="ean13"><?php esc_html_e( 'EAN-13', 'smart-qr-saas' ); ?></option>
                                    <option value="code128"><?php esc_html_e( 'Code 128', 'smart-qr-saas' ); ?></option>
                                </select>
                            </div>
                            <div>
                                <label for="sqr_shortlink_path"><?php esc_html_e( 'Útvonal', 'smart-qr-saas' ); ?></label>
                                <select id="sqr_shortlink_path" name="shortlink_path">
                                    <option value="q">/q/slug</option>
                                    <option value="go">/go/slug</option>
                                </select>
                            </div>
                        </div>

                        <div class="sqr-row sqr-row-half">
                            <div>
                                <label for="sqr_redirect_type"><?php esc_html_e( 'Átirányítás', 'smart-qr-saas' ); ?></label>
                                <select id="sqr_redirect_type" name="redirect_type">
                                    <option value="301">301 – állandó</option>
                                    <option value="302" selected>302 – ideiglenes</option>
                                    <option value="307">307 – ideiglenes (metódus)</option>
                                </select>
                            </div>
                            <div>
                                <label for="sqr_click_limit"><?php esc_html_e( 'Kattintási limit', 'smart-qr-saas' ); ?></label>
                                <input type="number" id="sqr_click_limit" name="click_limit" min="0" placeholder="korlátlan">
                            </div>
                        </div>

                        <div class="sqr-row">
                            <label for="sqr_expires_at"><?php esc_html_e( 'Lejárati dátum (opcionális)', 'smart-qr-saas' ); ?></label>
                            <input type="datetime-local" id="sqr_expires_at" name="expires_at">
                        </div>

                        <hr>
                        <p class="sqr-section-title"><?php esc_html_e( 'UTM paraméterek (opcionális)', 'smart-qr-saas' ); ?></p>

                        <div class="sqr-row sqr-row-third">
                            <div>
                                <label for="sqr_utm_source">utm_source</label>
                                <input type="text" id="sqr_utm_source" name="utm_source" placeholder="newsletter">
                            </div>
                            <div>
                                <label for="sqr_utm_medium">utm_medium</label>
                                <input type="text" id="sqr_utm_medium" name="utm_medium" placeholder="email">
                            </div>
                            <div>
                                <label for="sqr_utm_campaign">utm_campaign</label>
                                <input type="text" id="sqr_utm_campaign" name="utm_campaign" placeholder="nyari_akció">
                            </div>
                        </div>

                        <hr>

                        <div class="sqr-row">
                            <label>
                                <input type="checkbox" name="password_protect" value="1" id="sqr_pw_check">
                                <?php esc_html_e( 'Jelszóval védett link', 'smart-qr-saas' ); ?>
                            </label>
                        </div>
                        <div class="sqr-row" id="sqr_pw_row" style="display:none;">
                            <label for="sqr_password"><?php esc_html_e( 'Jelszó', 'smart-qr-saas' ); ?></label>
                            <input type="password" id="sqr_password" name="link_password" autocomplete="new-password">
                        </div>

                        <?php submit_button( __( 'Link mentése', 'smart-qr-saas' ), 'primary', 'submit', false ); ?>
                    </form>
                </div>

                <?php /* ── Linkek táblázat ── */ ?>
                <div class="sqr-card sqr-table-wrapper">
                    <div class="sqr-table-header">
                        <h2><?php esc_html_e( 'Linkjeid', 'smart-qr-saas' ); ?> (<?php echo count( $links ); ?>)</h2>
                        <?php if ( ! empty( $links ) ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'smart_qr_saas_export_links', 'sqr_export_nonce' ); ?>
                                <input type="hidden" name="action" value="smart_qr_saas_export_links">
                                <button type="submit" class="button">
                                    ⬇ <?php esc_html_e( 'CSV export', 'smart-qr-saas' ); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ( ! empty( $links ) ) : ?>
                        <table class="widefat fixed striped sqr-links-table">
                            <thead>
                                <tr>
                                    <th style="width:70px"><?php esc_html_e( 'Kép', 'smart-qr-saas' ); ?></th>
                                    <th><?php esc_html_e( 'Cím / URL', 'smart-qr-saas' ); ?></th>
                                    <th style="width:140px"><?php esc_html_e( 'Rövid link', 'smart-qr-saas' ); ?></th>
                                    <th style="width:60px"><?php esc_html_e( 'Típus', 'smart-qr-saas' ); ?></th>
                                    <th style="width:50px"><?php esc_html_e( 'Scans', 'smart-qr-saas' ); ?></th>
                                    <th style="width:60px"><?php esc_html_e( 'Aktív', 'smart-qr-saas' ); ?></th>
                                    <th style="width:110px"><?php esc_html_e( 'Műveletek', 'smart-qr-saas' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $links as $row ) :
                                    $path      = ( isset( $row->shortlink_path ) && $row->shortlink_path === 'go' ) ? 'go' : 'q';
                                    $short_url = trailingslashit( home_url( '/' . $path . '/' . $row->short_slug ) );
                                    $is_active = (int) $row->is_active;
                                    $expired   = ! empty( $row->expires_at ) && strtotime( $row->expires_at ) < time();
                                    $img_src   = add_query_arg(
                                        [ 'action' => 'smart_qr_saas_image', 'link_id' => (int) $row->id ],
                                        admin_url( 'admin-ajax.php' )
                                    );
                                    ?>
                                    <tr class="<?php echo ( ! $is_active || $expired ) ? 'sqr-inactive' : ''; ?>">
                                        <td>
                                            <a href="<?php echo esc_url( $img_src ); ?>" target="_blank" rel="noopener">
                                                <img src="<?php echo esc_url( $img_src ); ?>" alt="" style="max-width:64px;height:auto;border-radius:4px;">
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ( ! empty( $row->title ) ) : ?>
                                                <strong><?php echo esc_html( $row->title ); ?></strong><br>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url( $row->original_url ); ?>" target="_blank" rel="noopener noreferrer" class="sqr-url-truncate">
                                                <?php echo esc_html( $row->original_url ); ?>
                                            </a>
                                            <?php if ( ! empty( $row->password_hash ) ) : ?>
                                                <span class="sqr-badge sqr-badge-pw">🔒</span>
                                            <?php endif; ?>
                                            <?php if ( $expired ) : ?>
                                                <span class="sqr-badge sqr-badge-expired"><?php esc_html_e( 'lejárt', 'smart-qr-saas' ); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo esc_url( $short_url ); ?>" target="_blank" rel="noopener" class="sqr-url-truncate">
                                                <?php echo esc_html( '/' . $path . '/' . $row->short_slug ); ?>
                                            </a>
                                        </td>
                                        <td><?php echo esc_html( strtoupper( $row->code_type ) ); ?></td>
                                        <td><?php echo esc_html( (int) $row->scan_count ); ?></td>
                                        <td>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                                <?php wp_nonce_field( 'smart_qr_saas_toggle_link', 'sqr_toggle_nonce' ); ?>
                                                <input type="hidden" name="action" value="smart_qr_saas_toggle_link">
                                                <input type="hidden" name="link_id" value="<?php echo esc_attr( $row->id ); ?>">
                                                <button type="submit" class="button button-small">
                                                    <?php echo $is_active ? '✅' : '⛔'; ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                                <?php wp_nonce_field( 'smart_qr_saas_delete_link', 'smart_qr_saas_delete_nonce' ); ?>
                                                <input type="hidden" name="action" value="smart_qr_saas_delete_link">
                                                <input type="hidden" name="link_id" value="<?php echo esc_attr( $row->id ); ?>">
                                                <button type="submit" class="button button-link-delete sqr-btn-del"
                                                    onclick="return confirm('<?php echo esc_js( __( 'Biztosan törlöd?', 'smart-qr-saas' ) ); ?>')">
                                                    🗑
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p class="sqr-empty"><?php esc_html_e( 'Még nincs elmentett linked.', 'smart-qr-saas' ); ?></p>
                    <?php endif; ?>
                </div>

            </div><?php // .sqr-grid ?>
        </div>

        <script>
        (function(){
            // Jelszó mező megjelenítése
            var pwCheck = document.getElementById('sqr_pw_check');
            var pwRow   = document.getElementById('sqr_pw_row');
            if (pwCheck && pwRow) {
                pwCheck.addEventListener('change', function(){
                    pwRow.style.display = this.checked ? '' : 'none';
                });
            }
            // Validáció
            var form = document.getElementById('sqr-link-form');
            if (form) {
                form.addEventListener('submit', function(e){
                    var url = document.getElementById('sqr_original_url').value.trim();
                    var pid = document.getElementById('sqr_post_id').value.trim();
                    if (!url && !pid) {
                        e.preventDefault();
                        alert('<?php echo esc_js( __( 'Add meg az eredeti URL-t vagy a bejegyzés/oldal ID-t.', 'smart-qr-saas' ) ); ?>');
                    }
                });
            }
        })();
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  LINK MENTÉSE
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_save_link(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Be kell jelentkezned.', 'smart-qr-saas' ) );
        }
        if ( ! isset( $_POST['smart_qr_saas_nonce'] ) || ! wp_verify_nonce( $_POST['smart_qr_saas_nonce'], 'smart_qr_saas_save_link' ) ) {
            wp_die( esc_html__( 'Érvénytelen biztonsági token.', 'smart-qr-saas' ) );
        }

        $user_id = get_current_user_id();
        $redirect_base = admin_url( 'admin.php?page=smart-qr-saas' );

        if ( ! $this->user_has_wc_access( $user_id ) ) {
            wp_redirect( add_query_arg( 'smart_qr_status', 'no_wc_access', $redirect_base ) );
            exit;
        }

        // Sanitize inputs
        $title          = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : null;
        $original_url   = isset( $_POST['original_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['original_url'] ) ) ) : '';
        $post_id        = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $code_type      = strtolower( sanitize_text_field( wp_unslash( $_POST['code_type'] ?? 'qr' ) ) );
        $shortlink_path = ( isset( $_POST['shortlink_path'] ) && $_POST['shortlink_path'] === 'go' ) ? 'go' : 'q';
        $redirect_type  = sanitize_text_field( wp_unslash( $_POST['redirect_type'] ?? '302' ) );
        $expires_at     = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );
        $click_limit    = isset( $_POST['click_limit'] ) && $_POST['click_limit'] !== '' ? (int) $_POST['click_limit'] : null;
        $utm_source     = sanitize_text_field( wp_unslash( $_POST['utm_source'] ?? '' ) );
        $utm_medium     = sanitize_text_field( wp_unslash( $_POST['utm_medium'] ?? '' ) );
        $utm_campaign   = sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ?? '' ) );
        $password_prot  = ! empty( $_POST['password_protect'] );
        $link_password  = isset( $_POST['link_password'] ) ? wp_unslash( $_POST['link_password'] ) : '';

        // Post ID → permalink
        if ( $post_id > 0 ) {
            $post = get_post( $post_id );
            if ( $post ) {
                $original_url = get_permalink( $post ) ?: $original_url;
            }
        }

        if ( empty( $original_url ) ) {
            wp_redirect( add_query_arg( 'smart_qr_status', 'error', $redirect_base ) );
            exit;
        }

        // UTM paraméterek hozzáfűzése az URL-hez (ha megadta)
        $utm_params = array_filter( [
            'utm_source'   => $utm_source,
            'utm_medium'   => $utm_medium,
            'utm_campaign' => $utm_campaign,
        ] );
        if ( ! empty( $utm_params ) ) {
            $original_url = add_query_arg( $utm_params, $original_url );
        }

        // Validációk
        if ( ! in_array( $code_type,    [ 'qr', 'ean13', 'code128' ], true ) ) { $code_type    = 'qr'; }
        if ( ! in_array( $redirect_type,[ '301', '302', '307' ], true ) )       { $redirect_type = '302'; }

        $password_hash = ( $password_prot && $link_password !== '' )
            ? wp_hash_password( $link_password )
            : null;

        $expires_mysql = ( $expires_at !== '' )
            ? gmdate( 'Y-m-d H:i:s', strtotime( $expires_at ) )
            : null;

        global $wpdb;
        $now  = current_time( 'mysql' );
        $slug = $this->generate_unique_slug();

        $inserted = $wpdb->insert(
            $this->table_name,
            [
                'title'          => $title ?: null,
                'user_id'        => $user_id,
                'original_url'   => $original_url,
                'short_slug'     => $slug,
                'scan_count'     => 0,
                'code_type'      => $code_type,
                'password_hash'  => $password_hash,
                'expires_at'     => $expires_mysql,
                'redirect_type'  => $redirect_type,
                'post_id'        => $post_id > 0 ? $post_id : null,
                'shortlink_path' => $shortlink_path,
                'is_active'      => 1,
                'click_limit'    => $click_limit,
                'utm_source'     => $utm_source ?: null,
                'utm_medium'     => $utm_medium ?: null,
                'utm_campaign'   => $utm_campaign ?: null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%s','%d','%s','%s','%d','%s','%s','%s','%s','%d','%s','%d','%d','%s','%s','%s','%s','%s' ]
        );

        $status = ( false !== $inserted ) ? 'ok' : 'error';
        wp_redirect( add_query_arg( 'smart_qr_status', $status, $redirect_base ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  LINK TÖRLÉSE
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_delete_link(): void {
        if ( ! is_user_logged_in() || ! isset( $_POST['smart_qr_saas_delete_nonce'] )
            || ! wp_verify_nonce( $_POST['smart_qr_saas_delete_nonce'], 'smart_qr_saas_delete_link' )
        ) {
            wp_die( esc_html__( 'Érvénytelen kérés.', 'smart-qr-saas' ) );
        }

        $user_id = get_current_user_id();
        $link_id = isset( $_POST['link_id'] ) ? (int) $_POST['link_id'] : 0;
        $redirect_base = admin_url( 'admin.php?page=smart-qr-saas' );

        if ( ! $link_id ) {
            wp_redirect( add_query_arg( 'smart_qr_status', 'error', $redirect_base ) ); exit;
        }

        global $wpdb;

        // Admin bármit törölhet, mások csak a sajátjukat
        $where = current_user_can( 'manage_options' )
            ? [ 'id' => $link_id ]
            : [ 'id' => $link_id, 'user_id' => $user_id ];

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE id = %d" . ( current_user_can( 'manage_options' ) ? '' : ' AND user_id = %d' ) . ' LIMIT 1',
            ...( current_user_can( 'manage_options' ) ? [ $link_id ] : [ $link_id, $user_id ] )
        ) );

        if ( ! $row ) {
            wp_redirect( add_query_arg( 'smart_qr_status', 'error', $redirect_base ) ); exit;
        }

        $wpdb->delete( $this->table_name, $where, array_fill( 0, count( $where ), '%d' ) );

        // Kapcsolódó kattintások törlése
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$this->clicks_table}'" ) === $this->clicks_table ) { // phpcs:ignore
            $wpdb->delete( $this->clicks_table, [ 'link_id' => $link_id ], [ '%d' ] );
        }

        wp_redirect( add_query_arg( 'smart_qr_status', 'deleted', $redirect_base ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  LINK BE/KI KAPCSOLÁSA
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_toggle_link(): void {
        if ( ! is_user_logged_in() || ! isset( $_POST['sqr_toggle_nonce'] )
            || ! wp_verify_nonce( $_POST['sqr_toggle_nonce'], 'smart_qr_saas_toggle_link' )
        ) {
            wp_die( esc_html__( 'Érvénytelen kérés.', 'smart-qr-saas' ) );
        }

        $user_id = get_current_user_id();
        $link_id = (int) ( $_POST['link_id'] ?? 0 );
        $redirect_base = admin_url( 'admin.php?page=smart-qr-saas' );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, is_active, user_id FROM {$this->table_name} WHERE id = %d LIMIT 1",
            $link_id
        ) );

        if ( ! $row || ( (int) $row->user_id !== $user_id && ! current_user_can( 'manage_options' ) ) ) {
            wp_redirect( add_query_arg( 'smart_qr_status', 'error', $redirect_base ) ); exit;
        }

        $wpdb->update(
            $this->table_name,
            [ 'is_active' => ( (int) $row->is_active === 1 ) ? 0 : 1, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $link_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        wp_redirect( add_query_arg( 'smart_qr_status', 'toggled', $redirect_base ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  KÉP GENERÁLÁS (QR / EAN-13 / Code 128)
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_image_output(): void {
        if ( ! is_user_logged_in() ) {
            status_header( 403 );
            exit;
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'smart_qr_saas_image' ) ) {
            status_header( 403 );
            exit;
        }

        $link_id = isset( $_GET['link_id'] ) ? (int) $_GET['link_id'] : 0;
        if ( ! $link_id ) { status_header( 400 ); exit; }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d LIMIT 1",
            $link_id
        ) );
        if ( ! $row ) { status_header( 404 ); exit; }

        $user_id = get_current_user_id();
        if ( (int) $row->user_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
            status_header( 403 );
            exit;
        }

        $path      = ( isset( $row->shortlink_path ) && $row->shortlink_path === 'go' ) ? 'go' : 'q';
        $data      = home_url( '/' . $path . '/' . $row->short_slug . '/' );
        $code_type = strtolower( $row->code_type );
        $image     = $this->generate_code_image( $data, $code_type );

        if ( empty( $image['base64'] ) || empty( $image['mime'] ) ) {
            status_header( 500 );
            exit;
        }

        nocache_headers();
        header( 'Content-Type: ' . $image['mime'] );

        echo base64_decode( $image['base64'] );
        exit;
    }

    /**
     * Kód kép (QR / EAN13 / CODE128) generálása.
     *
     * @return array{mime:string,base64:string,data_uri:string}
     */
    protected function generate_code_image( string $string, string $type ): array {
        $type = strtolower( $type );

        if ( $type === 'qr' ) {
            if ( class_exists( '\BaconQrCode\Renderer\ImageRenderer' ) && class_exists( '\BaconQrCode\Renderer\Image\SvgImageBackEnd' ) ) {
                $renderer = new \BaconQrCode\Renderer\ImageRenderer(
                    new \BaconQrCode\Renderer\RendererStyle\RendererStyle( 300 ),
                    new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
                );
                $svg = ( new \BaconQrCode\Writer( $renderer ) )->writeString( $string );

                return [
                    'mime'     => 'image/svg+xml',
                    'base64'   => base64_encode( (string) $svg ),
                    'data_uri' => 'data:image/svg+xml;base64,' . base64_encode( (string) $svg ),
                ];
            }

            if ( class_exists( '\BaconQrCode\Renderer\ImageRenderer' ) && class_exists( '\BaconQrCode\Renderer\Image\ImagickImageBackEnd' ) ) {
                $renderer = new \BaconQrCode\Renderer\ImageRenderer(
                    new \BaconQrCode\Renderer\RendererStyle\RendererStyle( 300 ),
                    new \BaconQrCode\Renderer\Image\ImagickImageBackEnd()
                );
                $png = ( new \BaconQrCode\Writer( $renderer ) )->writeString( $string );

                return [
                    'mime'     => 'image/png',
                    'base64'   => base64_encode( $png ),
                    'data_uri' => 'data:image/png;base64,' . base64_encode( $png ),
                ];
            }
        }

        if ( in_array( $type, [ 'ean13', 'code128' ], true ) && class_exists( '\Picqer\Barcode\BarcodeGeneratorPNG' ) ) {
            $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();

            if ( $type === 'ean13' ) {
                $numeric = preg_replace( '/\D+/', '', $string );
                $value   = substr( str_pad( $numeric, 12, '0' ), 0, 12 );
                $png     = $generator->getBarcode( $value, $generator::TYPE_EAN_13, 2, 80 );
            } else {
                $value = substr( $string, 0, 64 );
                $png   = $generator->getBarcode( $value, $generator::TYPE_CODE_128, 2, 80 );
            }

            return [
                'mime'     => 'image/png',
                'base64'   => base64_encode( $png ),
                'data_uri' => 'data:image/png;base64,' . base64_encode( $png ),
            ];
        }

        return [
            'mime'     => '',
            'base64'   => '',
            'data_uri' => '',
        ];
    }

    private function output_placeholder_png( string $msg, int $w = 300, int $h = 80 ): void {
        $im = imagecreatetruecolor( $w, $h );
        $bg = imagecolorallocate( $im, 240, 240, 240 );
        $fg = imagecolorallocate( $im, 80, 80, 80 );
        imagefilledrectangle( $im, 0, 0, $w, $h, $bg );
        imagestring( $im, 3, 10, (int) ( $h / 2 ) - 7, $msg, $fg );
        imagepng( $im );
        imagedestroy( $im );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CSV EXPORT
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_export_links_csv(): void {
        if ( ! is_user_logged_in() || ! isset( $_POST['sqr_export_nonce'] )
            || ! wp_verify_nonce( $_POST['sqr_export_nonce'], 'smart_qr_saas_export_links' )
        ) {
            wp_die( esc_html__( 'Érvénytelen kérés.', 'smart-qr-saas' ) );
        }

        global $wpdb;
        $user_id = get_current_user_id();

        $where  = current_user_can( 'manage_options' ) ? '' : $wpdb->prepare( ' WHERE user_id = %d', $user_id );
        $links  = $wpdb->get_results( "SELECT * FROM {$this->table_name}{$where} ORDER BY created_at DESC" ); // phpcs:ignore

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="smart-qr-links-' . gmdate( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // UTF-8 BOM

        fputcsv( $out, [ 'ID','Cím','Eredeti URL','Rövid slug','Kód típus','Scan','Aktív','Lejárat','Létrehozva' ], ';' );

        foreach ( $links as $row ) {
            fputcsv( $out, [
                $row->id,
                $row->title ?? '',
                $row->original_url,
                $row->short_slug,
                strtoupper( $row->code_type ),
                $row->scan_count,
                $row->is_active ? 'igen' : 'nem',
                $row->expires_at ?? '',
                $row->created_at,
            ], ';' );
        }

        fclose( $out );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  FRONTEND ÁTIRÁNYÍTÁS
    // ─────────────────────────────────────────────────────────────────────────

    public function maybe_handle_frontend_redirect(): void {
        $slug = get_query_var( 'smart_qr_slug' );
        if ( empty( $slug ) ) {
            return;
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE short_slug = %s LIMIT 1",
            $slug
        ) );

        // Nem létező link → 404
        if ( ! $row ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return;
        }

        // Inaktív link
        if ( ! (int) $row->is_active ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 410 ); // Gone
            return;
        }

        // Lejárat
        if ( ! empty( $row->expires_at ) && strtotime( $row->expires_at ) < time() ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 410 );
            return;
        }

        // Kattintási limit elérése
        $link_id = (int) $row->id;
        if ( ! empty( $row->click_limit ) && (int) $row->scan_count >= (int) $row->click_limit ) {
            // Inaktiváljuk automatikusan
            $wpdb->update(
                $this->table_name,
                [ 'is_active' => 0, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $link_id ],
                [ '%d', '%s' ], [ '%d' ]
            );
            global $wp_query;
            $wp_query->set_404();
            status_header( 410 );
            return;
        }

        // Jelszóvédelem
        $cookie_name = 'smart_qr_pw_' . $link_id;
        if ( ! empty( $row->password_hash ) ) {
            $passed = false;
            if ( isset( $_COOKIE[ $cookie_name ] ) && wp_check_password( $_COOKIE[ $cookie_name ], $row->password_hash ) ) {
                $passed = true;
            } elseif ( isset( $_POST['smart_qr_pw'] ) && wp_check_password( wp_unslash( $_POST['smart_qr_pw'] ), $row->password_hash ) ) {
                $passed = true;
                $raw_pw = sanitize_text_field( wp_unslash( $_POST['smart_qr_pw'] ) );
                setcookie( $cookie_name, $raw_pw, time() + 86400 * 7, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
            }
            if ( ! $passed ) {
                $path = ( isset( $row->shortlink_path ) && $row->shortlink_path === 'go' ) ? 'go' : 'q';
                $this->render_password_form( $slug, $path );
                return;
            }
        }

        // Cél URL meghatározása
        $target_url = $row->original_url;
        if ( ! empty( $row->post_id ) ) {
            $post = get_post( (int) $row->post_id );
            if ( $post && ( $post->post_status === 'publish' || current_user_can( 'edit_post', (int) $row->post_id ) ) ) {
                $target_url = get_permalink( $post ) ?: $target_url;
            }
        }

        // ── scan_count növelése ────────────────────────────────────────────────
        $wpdb->update(
            $this->table_name,
            [ 'scan_count' => (int) $row->scan_count + 1, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $link_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        // ── Kattintás napló ───────────────────────────────────────────────────
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$this->clicks_table}'" ) === $this->clicks_table ) { // phpcs:ignore
            $ua          = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
            $device_type = $this->detect_device_type( $ua );
            $wpdb->insert(
                $this->clicks_table,
                [
                    'link_id'     => $link_id,
                    'ip'          => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
                    'user_agent'  => substr( $ua, 0, 500 ),
                    'referer'     => isset( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 500 ) : null,
                    'device_type' => $device_type,
                    'created_at'  => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s' ]
            );
        }

        // ── Lojalitás pontok ──────────────────────────────────────────────────
        if ( is_user_logged_in() ) {
            $this->maybe_award_loyalty_points( get_current_user_id(), $row );
        }

        $status = in_array( $row->redirect_type, [ '301', '302', '307' ], true )
            ? (int) $row->redirect_type
            : 302;

        wp_redirect( esc_url_raw( $target_url ), $status );
        exit;
    }

    /** Egyszerű eszköztípus felismerés UA string alapján. */
    private function detect_device_type( string $ua ): string {
        $ua = strtolower( $ua );
        if ( preg_match( '/tablet|ipad/', $ua ) ) { return 'tablet'; }
        if ( preg_match( '/mobile|android|iphone/', $ua ) ) { return 'mobile'; }
        return 'desktop';
    }

    /** Jelszóvédett link felugró formja. */
    protected function render_password_form( string $slug, string $path = 'q' ): void {
        $action = home_url( '/' . $path . '/' . $slug . '/' );
        status_header( 200 );
        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!DOCTYPE html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>' . esc_html__( 'Jelszó szükséges', 'smart-qr-saas' ) . '</title>
        <style>*{box-sizing:border-box}body{font-family:system-ui,sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.box{background:#fff;border-radius:12px;padding:32px;max-width:360px;width:100%;box-shadow:0 8px 24px rgba(0,0,0,.1)}h2{margin:0 0 8px;font-size:20px}p{color:#666;margin:0 0 20px}input[type=password]{width:100%;padding:10px 14px;border:1px solid #ccc;border-radius:6px;font-size:15px;margin-bottom:12px}button{width:100%;background:#2271b1;color:#fff;border:none;padding:11px;border-radius:6px;font-size:15px;cursor:pointer}button:hover{background:#1a5a8f}</style>
        </head><body><div class="box">
        <h2>' . esc_html__( 'Jelszó szükséges', 'smart-qr-saas' ) . '</h2>
        <p>' . esc_html__( 'Ez a link jelszóval védett.', 'smart-qr-saas' ) . '</p>
        <form method="post" action="' . esc_url( $action ) . '">
            <input type="password" name="smart_qr_pw" required placeholder="' . esc_attr__( 'Jelszó', 'smart-qr-saas' ) . '" autofocus>
            <button type="submit">' . esc_html__( 'Belépés', 'smart-qr-saas' ) . '</button>
        </form>
        </div></body></html>';
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  WOOCOMMERCE HOZZÁFÉRÉS ELLENŐRZÉS
    // ─────────────────────────────────────────────────────────────────────────

    protected function user_has_wc_access( int $user_id ): bool {
        if ( $user_id <= 0 ) {
            return false;
        }

        // A plugin tulajdonosa mindig hozzáfér.
        $owner = smart_qr_saas_get_owner();
        if ( $owner && (int) $owner->ID === $user_id ) {
            return true;
        }

        // WooCommerce függvény nélkül ne adjunk hozzáférést.
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return false;
        }

        // Completed rendelés keresése.
        $orders = wc_get_orders( [
            'customer_id' => $user_id,
            'status'      => [ 'completed' ],
            'limit'       => 1,
            'return'      => 'ids',
        ] );
        if ( ! empty( $orders ) ) {
            return true;
        }

        // WooCommerce Subscriptions: wcs_get_subscriptions (v3+).
        if ( function_exists( 'wcs_get_subscriptions' ) ) {
            $subs = wcs_get_subscriptions( [
                'customer_id' => $user_id,
                'status'      => [ 'active' ],
                'limit'       => 1,
            ] );
            if ( ! empty( $subs ) ) {
                return true;
            }
        }

        // Régebbi WCS: WC_Subscriptions_Manager.
        if ( class_exists( 'WC_Subscriptions_Manager' ) ) {
            $user_subs = WC_Subscriptions_Manager::get_users_subscriptions( $user_id );
            foreach ( (array) $user_subs as $sub ) {
                if ( isset( $sub['status'] ) && $sub['status'] === 'active' ) {
                    return true;
                }
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  LOJALITÁS PONTOK
    // ─────────────────────────────────────────────────────────────────────────

    protected function maybe_award_loyalty_points( int $customer_id, object $link_row ): void {
        global $wpdb;
        $business_id = (int) $link_row->user_id;
        if ( ! $business_id || $business_id === $customer_id ) {
            return; // Saját linkjét ne pontozza
        }

        $today_start = gmdate( 'Y-m-d 00:00:00' );
        $today_end   = gmdate( 'Y-m-d 23:59:59' );

        $already = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->log_table}
             WHERE user_id = %d AND business_user_id = %d AND link_id = %d
             AND created_at BETWEEN %s AND %s LIMIT 1",
            $customer_id, $business_id, (int) $link_row->id, $today_start, $today_end
        ) );
        if ( $already ) { return; }

        $now = current_time( 'mysql' );

        $wpdb->insert( $this->log_table, [
            'user_id'          => $customer_id,
            'business_user_id' => $business_id,
            'link_id'          => (int) $link_row->id,
            'points'           => 1,
            'reason'           => 'scan',
            'created_at'       => $now,
        ], [ '%d','%d','%d','%d','%s','%s' ] );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->points_table} WHERE user_id = %d AND business_user_id = %d LIMIT 1",
            $customer_id, $business_id
        ) );

        if ( $existing ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$this->points_table} SET points = points + 1, updated_at = %s WHERE id = %d",
                $now, $existing
            ) );
        } else {
            $wpdb->insert( $this->points_table, [
                'user_id'          => $customer_id,
                'business_user_id' => $business_id,
                'points'           => 1,
                'updated_at'       => $now,
            ], [ '%d','%d','%d','%s' ] );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  SHORTCODE-OK
    // ─────────────────────────────────────────────────────────────────────────

    public function register_shortcodes(): void {
        add_shortcode( 'smart_qr_loyalty',    [ $this, 'shortcode_loyalty' ] );
        add_shortcode( 'smart_qr_profile',    [ $this, 'shortcode_profile' ] );
        add_shortcode( 'smart_qr_businesses', [ $this, 'shortcode_businesses' ] );
        add_shortcode( 'smart_qr_dashboard',  [ $this, 'shortcode_dashboard' ] );
    }

    /** [smart_qr_loyalty] – bejelentkezett felhasználó pontjai. */
    public function shortcode_loyalty(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'A pontjaid megtekintéséhez be kell jelentkezned.', 'smart-qr-saas' ) . '</p>';
        }
        global $wpdb;
        $user_id = get_current_user_id();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->points_table} WHERE user_id = %d ORDER BY points DESC",
            $user_id
        ) );
        if ( empty( $rows ) ) {
            return '<p>' . esc_html__( 'Még nincs gyűjtött pontod.', 'smart-qr-saas' ) . '</p>';
        }
        $total = array_sum( array_column( $rows, 'points' ) );
        ob_start();
        ?>
        <div class="sqr-loyalty">
            <h2><?php esc_html_e( 'Pontjaid', 'smart-qr-saas' ); ?></h2>
            <p><?php printf( esc_html__( 'Összesen %d pontod van.', 'smart-qr-saas' ), (int) $total ); ?></p>
            <table class="sqr-table">
                <thead><tr><th><?php esc_html_e( 'Vállalkozás', 'smart-qr-saas' ); ?></th><th><?php esc_html_e( 'Pontok', 'smart-qr-saas' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $biz = get_user_by( 'id', $row->business_user_id ); ?>
                    <tr>
                        <td><?php echo esc_html( $biz ? $biz->display_name : '#' . $row->business_user_id ); ?></td>
                        <td><?php echo esc_html( (int) $row->points ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /** [smart_qr_profile] – felhasználói profil + pontok. */
    public function shortcode_profile(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'A profilod megtekintéséhez be kell jelentkezned.', 'smart-qr-saas' ) . '</p>';
        }
        $user = wp_get_current_user();
        ob_start();
        ?>
        <div class="sqr-profile">
            <h2><?php echo esc_html( $user->display_name ); ?></h2>
            <p><?php echo esc_html( $user->user_email ); ?></p>
            <?php echo $this->shortcode_loyalty(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** [smart_qr_businesses] – vállalkozások listája. */
    public function shortcode_businesses(): string {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT DISTINCT user_id FROM {$this->table_name} WHERE user_id > 0" ); // phpcs:ignore
        if ( empty( $rows ) ) {
            return '<p>' . esc_html__( 'Még nincs elérhető vállalkozás.', 'smart-qr-saas' ) . '</p>';
        }
        ob_start();
        ?>
        <div class="sqr-businesses">
            <h2><?php esc_html_e( 'Vállalkozások', 'smart-qr-saas' ); ?></h2>
            <ul>
            <?php foreach ( $rows as $row ) :
                $biz = get_user_by( 'id', $row->user_id );
                if ( ! $biz ) { continue; } ?>
                <li><?php echo esc_html( $biz->display_name ); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }

    /** [smart_qr_dashboard] – bejelentkezett felhasználó linkjei és statisztikái. */
    public function shortcode_dashboard(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'A dashboard megtekintéséhez be kell jelentkezned.', 'smart-qr-saas' ) . '</p>';
        }

        global $wpdb;
        $user_id    = get_current_user_id();
        $has_clicks = $wpdb->get_var( "SHOW TABLES LIKE '{$this->clicks_table}'" ) === $this->clicks_table; // phpcs:ignore

        $links = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ) );

        if ( empty( $links ) ) {
            return '<p>' . esc_html__( 'Még nincs egyetlen rövid linked sem.', 'smart-qr-saas' ) . '</p>';
        }

        $total_scans = (int) array_sum( array_column( $links, 'scan_count' ) );
        $active_count = count( array_filter( $links, fn( $l ) => (int) $l->is_active === 1 ) );

        ob_start();
        ?>
        <div class="sqr-dashboard">
            <div class="sqr-dash-stats">
                <div class="sqr-stat-box">
                    <span class="sqr-stat-num"><?php echo count( $links ); ?></span>
                    <span class="sqr-stat-label"><?php esc_html_e( 'összes link', 'smart-qr-saas' ); ?></span>
                </div>
                <div class="sqr-stat-box sqr-stat-green">
                    <span class="sqr-stat-num"><?php echo $active_count; ?></span>
                    <span class="sqr-stat-label"><?php esc_html_e( 'aktív', 'smart-qr-saas' ); ?></span>
                </div>
                <div class="sqr-stat-box sqr-stat-blue">
                    <span class="sqr-stat-num"><?php echo $total_scans; ?></span>
                    <span class="sqr-stat-label"><?php esc_html_e( 'összes scan', 'smart-qr-saas' ); ?></span>
                </div>
            </div>

            <div style="overflow-x:auto;">
            <table class="sqr-table sqr-dash-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Kép', 'smart-qr-saas' ); ?></th>
                        <th><?php esc_html_e( 'Cím / URL', 'smart-qr-saas' ); ?></th>
                        <th><?php esc_html_e( 'Rövid link', 'smart-qr-saas' ); ?></th>
                        <th><?php esc_html_e( 'Típus', 'smart-qr-saas' ); ?></th>
                        <th><?php esc_html_e( 'Scans', 'smart-qr-saas' ); ?></th>
                        <th><?php esc_html_e( 'Egyedi IP', 'smart-qr-saas' ); ?></th>
                        <th><?php esc_html_e( 'Lejárat', 'smart-qr-saas' ); ?></th>
                        <th><?php esc_html_e( 'Állapot', 'smart-qr-saas' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $links as $row ) :
                    $path      = ( isset( $row->shortlink_path ) && $row->shortlink_path === 'go' ) ? 'go' : 'q';
                    $short_url = home_url( '/' . $path . '/' . $row->short_slug . '/' );
                    $is_active = (int) $row->is_active;
                    $expired   = ! empty( $row->expires_at ) && strtotime( $row->expires_at ) < time();
                    $image_data = $this->generate_code_image( $short_url, (string) $row->code_type );
                    $img_nonce  = wp_create_nonce( 'smart_qr_saas_image' );
                    $img_src    = add_query_arg(
                        [ 'action' => 'smart_qr_saas_image', 'link_id' => (int) $row->id, '_wpnonce' => $img_nonce ],
                        admin_url( 'admin-ajax.php' )
                    );
                    $unique_ips = $has_clicks ? (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(DISTINCT ip) FROM {$this->clicks_table} WHERE link_id = %d",
                        $row->id
                    ) ) : '—';
                    ?>
                    <tr class="<?php echo ( ! $is_active || $expired ) ? 'sqr-row-inactive' : ''; ?>">
                        <td>
                            <?php if ( ! empty( $image_data['data_uri'] ) ) : ?>
                                <a href="<?php echo esc_url( $image_data['data_uri'] ); ?>" download="<?php echo esc_attr( sanitize_file_name( $row->short_slug . '-' . strtolower( $row->code_type ) . '.png' ) ); ?>">
                                    <img src="<?php echo esc_url( $image_data['data_uri'] ); ?>" alt="<?php echo esc_attr( strtoupper( $row->code_type ) ); ?>" style="width:64px;height:auto;border-radius:4px;">
                                </a>
                                <br>
                                <a href="<?php echo esc_url( $img_src ); ?>" target="_blank" rel="noopener" style="font-size:11px;">
                                    <?php esc_html_e( 'Kép megnyitása', 'smart-qr-saas' ); ?>
                                </a>
                            <?php else : ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $row->title ) ) : ?>
                                <strong><?php echo esc_html( $row->title ); ?></strong><br>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( $row->original_url ); ?>" target="_blank" rel="noopener" class="sqr-url-truncate">
                                <?php echo esc_html( $row->original_url ); ?>
                            </a>
                            <?php if ( ! empty( $row->password_hash ) ) : ?>
                                <span class="sqr-badge">🔒</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $short_url ); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html( '/' . $path . '/' . $row->short_slug ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( strtoupper( $row->code_type ) ); ?></td>
                        <td style="text-align:center;font-weight:700;"><?php echo esc_html( (int) $row->scan_count ); ?></td>
                        <td style="text-align:center;"><?php echo esc_html( $unique_ips ); ?></td>
                        <td>
                            <?php if ( ! empty( $row->expires_at ) ) : ?>
                                <?php echo esc_html( $row->expires_at ); ?>
                                <?php if ( $expired ) : ?>
                                    <br><span style="color:#c00;font-size:11px;"><?php esc_html_e( 'lejárt', 'smart-qr-saas' ); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $is_active && ! $expired ) : ?>
                                <span class="sqr-status-dot sqr-active">●</span> <?php esc_html_e( 'Aktív', 'smart-qr-saas' ); ?>
                            <?php else : ?>
                                <span class="sqr-status-dot sqr-inactive-dot">●</span> <?php esc_html_e( 'Inaktív', 'smart-qr-saas' ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ELEMZÉS OLDAL
    // ─────────────────────────────────────────────────────────────────────────

    public function render_analytics_page(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Be kell jelentkezned.', 'smart-qr-saas' ) );
        }
        global $wpdb;
        $user_id = get_current_user_id();
        $links   = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE user_id = %d ORDER BY scan_count DESC",
            $user_id
        ) );
        $has_clicks = $wpdb->get_var( "SHOW TABLES LIKE '{$this->clicks_table}'" ) === $this->clicks_table; // phpcs:ignore
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Kattintás elemzés', 'smart-qr-saas' ); ?></h1>
            <?php if ( empty( $links ) ) : ?>
                <p><?php esc_html_e( 'Még nincs rövid linked.', 'smart-qr-saas' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Rövid link', 'smart-qr-saas' ); ?></th>
                            <th><?php esc_html_e( 'Összes scan', 'smart-qr-saas' ); ?></th>
                            <th><?php esc_html_e( 'Egyedi IP', 'smart-qr-saas' ); ?></th>
                            <th><?php esc_html_e( 'Mobil', 'smart-qr-saas' ); ?></th>
                            <th><?php esc_html_e( 'Desktop', 'smart-qr-saas' ); ?></th>
                            <th><?php esc_html_e( 'Utolsó 5 kattintás', 'smart-qr-saas' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $links as $row ) :
                        $path      = ( isset( $row->shortlink_path ) && $row->shortlink_path === 'go' ) ? 'go' : 'q';
                        $short_url = home_url( '/' . $path . '/' . $row->short_slug . '/' );
                        $unique_ips = $mobile = $desktop = 0;
                        $last_clicks = [];
                        if ( $has_clicks ) {
                            $unique_ips   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ip) FROM {$this->clicks_table} WHERE link_id = %d", $row->id ) );
                            $mobile       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->clicks_table} WHERE link_id = %d AND device_type = 'mobile'", $row->id ) );
                            $desktop      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->clicks_table} WHERE link_id = %d AND device_type = 'desktop'", $row->id ) );
                            $last_clicks  = $wpdb->get_results( $wpdb->prepare( "SELECT ip, device_type, created_at FROM {$this->clicks_table} WHERE link_id = %d ORDER BY created_at DESC LIMIT 5", $row->id ) );
                        }
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $short_url ); ?>" target="_blank"><?php echo esc_html( '/' . $path . '/' . $row->short_slug ); ?></a></td>
                            <td><?php echo esc_html( (int) $row->scan_count ); ?></td>
                            <td><?php echo esc_html( $unique_ips ); ?></td>
                            <td><?php echo esc_html( $mobile ); ?></td>
                            <td><?php echo esc_html( $desktop ); ?></td>
                            <td>
                                <?php if ( ! empty( $last_clicks ) ) : ?>
                                    <ul style="margin:0;padding-left:16px;font-size:12px;">
                                    <?php foreach ( $last_clicks as $c ) : ?>
                                        <li><?php echo esc_html( $c->created_at . ' — ' . $c->ip . ' (' . $c->device_type . ')' ); ?></li>
                                    <?php endforeach; ?>
                                    </ul>
                                <?php else : ?>
                                    <em>—</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  BEÁLLÍTÁSOK OLDAL
    // ─────────────────────────────────────────────────────────────────────────

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Nincs jogosultságod.', 'smart-qr-saas' ) );
        }
        $allow_editor_newsletter = (int) get_option( 'smart_qr_saas_allow_editor_newsletter', 0 );
        $shortlink_path          = get_option( 'smart_qr_saas_shortlink_path', 'q' );
        $ai_api_key              = get_option( 'smart_qr_saas_ai_api_key', '' );
        $wc_required             = (int) get_option( 'smart_qr_saas_wc_required', 1 );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart QR SaaS – Beállítások', 'smart-qr-saas' ); ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'smart_qr_saas_save_settings', 'smart_qr_saas_settings_nonce' ); ?>
                <input type="hidden" name="action" value="smart_qr_saas_save_settings">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'WooCommerce hozzáférés kötelező', 'smart-qr-saas' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wc_required" value="1" <?php checked( $wc_required, 1 ); ?>>
                                <?php esc_html_e( 'Link csak Completed rendeléssel/aktív előfizetéssel hozható létre', 'smart-qr-saas' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Hírlevél szerkesztőknek', 'smart-qr-saas' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="allow_editor_newsletter" value="1" <?php checked( $allow_editor_newsletter, 1 ); ?>>
                                <?php esc_html_e( 'Szerkesztők (editor) is küldhetnek hírlevelet', 'smart-qr-saas' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Alapértelmezett rövid link útvonal', 'smart-qr-saas' ); ?></th>
                        <td>
                            <select name="shortlink_path">
                                <option value="q" <?php selected( $shortlink_path, 'q' ); ?>>/q/slug</option>
                                <option value="go" <?php selected( $shortlink_path, 'go' ); ?>>/go/slug</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'MI Asszisztens API kulcs (OpenAI)', 'smart-qr-saas' ); ?></th>
                        <td>
                            <input type="password" name="ai_api_key" value="<?php echo esc_attr( $ai_api_key ); ?>" class="regular-text" autocomplete="off">
                            <p class="description"><?php esc_html_e( 'OpenAI-kompatibilis API kulcs. Üresen hagyva offline segítség üzeneteket használ.', 'smart-qr-saas' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Plugin információ', 'smart-qr-saas' ); ?></h2>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Verzió', 'smart-qr-saas' ); ?></th><td><?php echo esc_html( SMART_QR_SAAS_VERSION ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Tulajdonos', 'smart-qr-saas' ); ?></th><td><?php echo esc_html( SMART_QR_SAAS_OWNER_EMAIL ); ?></td></tr>
                <tr><th>DB verzió</th><td><?php echo esc_html( get_option( 'smart_qr_saas_db_version', '–' ) ); ?></td></tr>
                <tr><th>BaconQrCode</th><td><?php echo class_exists( '\BaconQrCode\Writer' ) ? '✅ betöltve' : '⚠️ hiányzik (composer install)'; ?></td></tr>
                <tr><th>Picqer Barcode</th><td><?php echo class_exists( '\Picqer\Barcode\BarcodeGeneratorPNG' ) ? '✅ betöltve' : '⚠️ hiányzik (composer install)'; ?></td></tr>
            </table>
        </div>
        <?php
    }

    public function handle_save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Nincs jogosultságod.', 'smart-qr-saas' ) );
        }
        if ( ! isset( $_POST['smart_qr_saas_settings_nonce'] ) || ! wp_verify_nonce( $_POST['smart_qr_saas_settings_nonce'], 'smart_qr_saas_save_settings' ) ) {
            wp_die( esc_html__( 'Érvénytelen token.', 'smart-qr-saas' ) );
        }
        update_option( 'smart_qr_saas_wc_required',               ! empty( $_POST['wc_required'] ) ? 1 : 0 );
        update_option( 'smart_qr_saas_allow_editor_newsletter',    ! empty( $_POST['allow_editor_newsletter'] ) ? 1 : 0 );
        update_option( 'smart_qr_saas_shortlink_path',             ( isset( $_POST['shortlink_path'] ) && $_POST['shortlink_path'] === 'go' ) ? 'go' : 'q' );
        if ( isset( $_POST['ai_api_key'] ) ) {
            update_option( 'smart_qr_saas_ai_api_key', sanitize_text_field( wp_unslash( $_POST['ai_api_key'] ) ) );
        }
        wp_redirect( add_query_arg( 'settings-updated', '1', admin_url( 'admin.php?page=smart-qr-saas-settings' ) ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HÍRLEVÉL OLDAL
    // ─────────────────────────────────────────────────────────────────────────

    public function render_newsletter_page(): void {
        $can = current_user_can( 'manage_options' )
            || ( current_user_can( 'edit_posts' ) && (int) get_option( 'smart_qr_saas_allow_editor_newsletter', 0 ) === 1 );
        if ( ! $can ) {
            wp_die( esc_html__( 'Nincs jogosultságod. Az admin engedélyezheti a Beállításokban.', 'smart-qr-saas' ) );
        }
        global $wpdb;
        $has_table   = $wpdb->get_var( "SHOW TABLES LIKE '{$this->subs_table}'" ) === $this->subs_table; // phpcs:ignore
        $subscribers = $has_table
            ? $wpdb->get_results( "SELECT * FROM {$this->subs_table} WHERE status = 'active' ORDER BY created_at DESC LIMIT 500" ) // phpcs:ignore
            : [];
        $msg = isset( $_GET['newsletter_status'] ) ? sanitize_key( $_GET['newsletter_status'] ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Hírlevél', 'smart-qr-saas' ); ?></h1>
            <?php if ( $msg === 'sent' ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Hírlevél elküldve.', 'smart-qr-saas' ); ?></p></div>
            <?php elseif ( $msg === 'subscribed' ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Előfizető hozzáadva.', 'smart-qr-saas' ); ?></p></div>
            <?php elseif ( $msg === 'error' ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Hiba történt.', 'smart-qr-saas' ); ?></p></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                <div>
                    <h2><?php esc_html_e( 'Hírlevél kiküldése', 'smart-qr-saas' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'smart_qr_saas_newsletter_send', 'smart_qr_saas_newsletter_nonce' ); ?>
                        <input type="hidden" name="action" value="smart_qr_saas_newsletter_send">
                        <p><label><?php esc_html_e( 'Tárgy', 'smart-qr-saas' ); ?><br>
                            <input type="text" name="subject" class="large-text" required></label></p>
                        <p><label><?php esc_html_e( 'Szöveg (HTML)', 'smart-qr-saas' ); ?><br>
                            <textarea name="body" rows="10" class="large-text" required></textarea></label></p>
                        <p class="description"><?php printf( esc_html__( 'Küldés %d aktív előfizetőnek.', 'smart-qr-saas' ), count( $subscribers ) ); ?></p>
                        <?php submit_button( __( 'Küldés', 'smart-qr-saas' ) ); ?>
                    </form>
                </div>
                <div>
                    <h2><?php esc_html_e( 'Új előfizető', 'smart-qr-saas' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'smart_qr_saas_add_subscriber', 'smart_qr_saas_sub_nonce' ); ?>
                        <input type="hidden" name="action" value="smart_qr_saas_add_subscriber">
                        <p><input type="email" name="email" required class="large-text" placeholder="<?php esc_attr_e( 'E-mail cím', 'smart-qr-saas' ); ?>"></p>
                        <p><input type="text" name="name" class="large-text" placeholder="<?php esc_attr_e( 'Név (opcionális)', 'smart-qr-saas' ); ?>"></p>
                        <?php submit_button( __( 'Hozzáadás', 'smart-qr-saas' ), 'secondary' ); ?>
                    </form>
                </div>
            </div>

            <h2><?php printf( esc_html__( 'Előfizetők (%d)', 'smart-qr-saas' ), count( $subscribers ) ); ?></h2>
            <?php if ( ! empty( $subscribers ) ) : ?>
                <table class="widefat striped">
                    <thead><tr><th>E-mail</th><th>Név</th><th>Dátum</th></tr></thead>
                    <tbody>
                    <?php foreach ( $subscribers as $s ) : ?>
                        <tr>
                            <td><?php echo esc_html( $s->email ); ?></td>
                            <td><?php echo esc_html( $s->name ); ?></td>
                            <td><?php echo esc_html( $s->created_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php esc_html_e( 'Még nincs előfizető.', 'smart-qr-saas' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_add_subscriber(): void {
        $can = current_user_can( 'manage_options' )
            || ( current_user_can( 'edit_posts' ) && (int) get_option( 'smart_qr_saas_allow_editor_newsletter', 0 ) === 1 );
        if ( ! $can || ! isset( $_POST['smart_qr_saas_sub_nonce'] )
            || ! wp_verify_nonce( $_POST['smart_qr_saas_sub_nonce'], 'smart_qr_saas_add_subscriber' )
        ) {
            wp_die( esc_html__( 'Nincs jogosultságod.', 'smart-qr-saas' ) );
        }
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $redir = admin_url( 'admin.php?page=smart-qr-saas-newsletter' );
        if ( ! is_email( $email ) ) {
            wp_redirect( add_query_arg( 'newsletter_status', 'error', $redir ) ); exit;
        }
        global $wpdb;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$this->subs_table}'" ) !== $this->subs_table ) { // phpcs:ignore
            wp_redirect( $redir ); exit;
        }
        $wpdb->replace( $this->subs_table, [
            'email'      => $email,
            'name'       => $name,
            'status'     => 'active',
            'token'      => wp_generate_password( 32, false ),
            'created_at' => current_time( 'mysql' ),
        ], [ '%s','%s','%s','%s','%s' ] );
        wp_redirect( add_query_arg( 'newsletter_status', 'subscribed', $redir ) );
        exit;
    }

    public function handle_newsletter_send(): void {
        $can = current_user_can( 'manage_options' )
            || ( current_user_can( 'edit_posts' ) && (int) get_option( 'smart_qr_saas_allow_editor_newsletter', 0 ) === 1 );
        if ( ! $can || ! isset( $_POST['smart_qr_saas_newsletter_nonce'] )
            || ! wp_verify_nonce( $_POST['smart_qr_saas_newsletter_nonce'], 'smart_qr_saas_newsletter_send' )
        ) {
            wp_die( esc_html__( 'Nincs jogosultságod.', 'smart-qr-saas' ) );
        }
        $subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
        $body    = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );
        $redir   = admin_url( 'admin.php?page=smart-qr-saas-newsletter' );
        if ( ! $subject || ! $body ) {
            wp_redirect( add_query_arg( 'newsletter_status', 'error', $redir ) ); exit;
        }
        global $wpdb;
        $list = $wpdb->get_results( "SELECT email, name, token FROM {$this->subs_table} WHERE status = 'active'" ); // phpcs:ignore
        foreach ( $list as $row ) {
            $unsubscribe_url = add_query_arg( [
                'sqr_unsub' => 1,
                'token'     => urlencode( $row->token ),
            ], home_url( '/' ) );
            $footer = '<br><hr><p style="font-size:11px;color:#999;">
                <a href="' . esc_url( $unsubscribe_url ) . '">' . esc_html__( 'Leiratkozás', 'smart-qr-saas' ) . '</a>
            </p>';
            wp_mail(
                $row->email,
                $subject,
                $body . $footer,
                [ 'Content-Type: text/html; charset=UTF-8' ]
            );
        }
        wp_redirect( add_query_arg( 'newsletter_status', 'sent', $redir ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  MI ASSZISZTENS OLDAL + AJAX
    // ─────────────────────────────────────────────────────────────────────────

    public function render_ai_page(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Be kell jelentkezned.', 'smart-qr-saas' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart QR – MI Asszisztens', 'smart-qr-saas' ); ?></h1>
            <p><?php esc_html_e( 'Kérdezz bármit a rövid linkekről, DomPress shortlinksről, hírlevélről és beállításokról.', 'smart-qr-saas' ); ?></p>
            <div id="sqr-ai-wrap" style="max-width:720px;">
                <div id="sqr-ai-msgs" style="border:1px solid #ddd;border-radius:8px;padding:16px;min-height:200px;max-height:420px;overflow-y:auto;background:#fafafa;margin-bottom:12px;"></div>
                <div style="display:flex;gap:8px;">
                    <input type="text" id="sqr-ai-input" style="flex:1;padding:9px 12px;border:1px solid #ccc;border-radius:6px;" placeholder="<?php esc_attr_e( 'Kérdésed...', 'smart-qr-saas' ); ?>">
                    <button id="sqr-ai-send" class="button button-primary"><?php esc_html_e( 'Küldés', 'smart-qr-saas' ); ?></button>
                    <button id="sqr-ai-clear" class="button"><?php esc_html_e( 'Törlés', 'smart-qr-saas' ); ?></button>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var msgs  = document.getElementById('sqr-ai-msgs');
            var input = document.getElementById('sqr-ai-input');
            var btn   = document.getElementById('sqr-ai-send');
            var clear = document.getElementById('sqr-ai-clear');

            function addMsg(role, text) {
                var wrap = document.createElement('div');
                wrap.style.cssText = 'margin:8px 0;display:flex;gap:8px;align-items:flex-start;';
                var avatar = document.createElement('span');
                avatar.textContent = role === 'user' ? '👤' : '🤖';
                avatar.style.cssText = 'font-size:18px;flex-shrink:0;';
                var p = document.createElement('p');
                p.style.cssText = 'margin:0;background:' + (role === 'user' ? '#e8f4fd' : '#f0fff4') + ';padding:8px 12px;border-radius:8px;max-width:90%;';
                p.textContent = text;
                wrap.appendChild(avatar);
                wrap.appendChild(p);
                msgs.appendChild(wrap);
                msgs.scrollTop = msgs.scrollHeight;
            }

            function send() {
                var msg = (input.value || '').trim();
                if (!msg) return;
                addMsg('user', msg);
                input.value = '';
                btn.disabled = true;
                var fd = new FormData();
                fd.append('action', 'smart_qr_saas_chat');
                fd.append('message', msg);
                fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'smart_qr_saas_chat' ) ); ?>');
                fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST', body:fd})
                    .then(r => r.json())
                    .then(d => { btn.disabled = false; addMsg('assistant', d.data?.reply || d.data?.error || 'Hiba.'); })
                    .catch(() => { btn.disabled = false; addMsg('assistant', 'Kapcsolati hiba.'); });
            }

            btn.addEventListener('click', send);
            input.addEventListener('keypress', function(e){ if (e.key === 'Enter') send(); });
            clear.addEventListener('click', function(){ msgs.innerHTML = ''; });
        })();
        </script>
        <?php
    }

    public function ajax_ai_chat(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'reply' => __( 'Be kell jelentkezned.', 'smart-qr-saas' ) ] );
        }
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'smart_qr_saas_chat' ) ) {
            wp_send_json_error( [ 'reply' => __( 'Érvénytelen kérés.', 'smart-qr-saas' ) ] );
        }
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        if ( $message === '' ) {
            wp_send_json_error( [ 'reply' => __( 'Üres üzenet.', 'smart-qr-saas' ) ] );
        }

        $api_key = get_option( 'smart_qr_saas_ai_api_key', '' );
        $reply   = '';

        if ( $api_key !== '' ) {
            $uid     = get_current_user_id();
            $history = (array) get_user_meta( $uid, 'smart_qr_ai_history', true );
            $history[] = [ 'role' => 'user', 'content' => $message ];

            $system = 'Te a Smart QR SaaS WordPress bővítmény asszisztense vagy. '
                . 'Segíts a felhasználónak a rövid linkek (/q/ és /go/ prefix), DomPress shortlinks, '
                . 'QR-kód, EAN-13, Code128, jelszóvédelem, lejárat, kattintási limit, UTM paraméterek, '
                . 'hírlevél, lojalitás pontok, CSV export, REST API, MI Asszisztens és beállítások '
                . 'témakörében. Válaszolj röviden, pontosan, magyarul.';

            $res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( [
                    'model'    => 'gpt-4o-mini',
                    'messages' => array_merge(
                        [ [ 'role' => 'system', 'content' => $system ] ],
                        array_slice(
                            array_map( fn( $h ) => [ 'role' => $h['role'], 'content' => $h['content'] ], $history ),
                            -12
                        )
                    ),
                    'max_tokens' => 500,
                ] ),
                'timeout' => 25,
            ] );

            if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
                $json = json_decode( wp_remote_retrieve_body( $res ), true );
                $reply = trim( $json['choices'][0]['message']['content'] ?? '' );
                if ( $reply ) {
                    $history[] = [ 'role' => 'assistant', 'content' => $reply ];
                    update_user_meta( $uid, 'smart_qr_ai_history', array_slice( $history, -24 ) );
                }
            }
        }

        if ( $reply === '' ) {
            $reply = __( 'A Smart QR SaaS-ban linkeket a főmenüben hozhatsz létre (URL vagy bejegyzés ID). '
                . 'Választhatsz /q/ vagy /go/ útvonalat, állíthatsz be jelszóvédelmet, lejáratot és kattintási limitet. '
                . 'UTM paramétereket is megadhatsz a link létrehozásakor. '
                . 'Az Elemzés menüben látod a device-típus és egyedi IP statisztikákat. '
                . 'A [smart_qr_dashboard] shortcode-dal frontend táblázatot jeleníthetsz meg. '
                . 'CSV exporthoz kattints az Exportálás gombra a link listában. '
                . 'OpenAI API kulcsot a Beállítások menüben adhatsz meg a teljes MI segítséghez.', 'smart-qr-saas' );
        }

        wp_send_json_success( [ 'reply' => $reply ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ADATVÉDELMI OLDAL
    // ─────────────────────────────────────────────────────────────────────────

    public function render_privacy_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Nincs jogosultságod.', 'smart-qr-saas' ) );
        }
        $current = get_option( 'smart_qr_saas_privacy_notice', '' );
        if ( ! $current ) {
            $current = __(
                "A Smart-QR-SaaS bővítmény URL-rövidítés funkciója során a felhasználók által lerövidíteni "
                . "kívánt URL-címek a DomPress/THECARDS szervereire kerülhetnek továbbításra. A felhasználóknak "
                . "tisztában kell lenniük azzal, hogy adataik részben harmadik fél szolgáltatásán keresztül "
                . "kerülnek feldolgozásra. Kérjük, ellenőrizze a mindenkori adatvédelmi szabályzatot, és "
                . "tájékoztassa saját felhasználóit az ÁSZF-ben és Adatkezelési tájékoztatójában.\n\n"
                . "A jelen nyilatkozat naponta automatikusan felülvizsgálatra kerül.",
                'smart-qr-saas'
            );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Adatvédelmi nyilatkozat', 'smart-qr-saas' ); ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'smart_qr_saas_save_privacy', 'smart_qr_saas_privacy_nonce' ); ?>
                <input type="hidden" name="action" value="smart_qr_saas_save_privacy">
                <textarea name="smart_qr_saas_privacy_notice" rows="14" class="large-text code"><?php echo esc_textarea( $current ); ?></textarea>
                <?php submit_button( __( 'Mentés', 'smart-qr-saas' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save_privacy_notice(): void {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['smart_qr_saas_privacy_nonce'] )
            || ! wp_verify_nonce( $_POST['smart_qr_saas_privacy_nonce'], 'smart_qr_saas_save_privacy' )
        ) {
            wp_die( esc_html__( 'Érvénytelen kérés.', 'smart-qr-saas' ) );
        }
        $text = wp_kses_post( wp_unslash( $_POST['smart_qr_saas_privacy_notice'] ?? '' ) );
        update_option( 'smart_qr_saas_privacy_notice', $text );
        wp_redirect( admin_url( 'admin.php?page=smart-qr-saas-privacy' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  REST API
    // ─────────────────────────────────────────────────────────────────────────

    public function register_rest_routes(): void {
        // GET /wp-json/smart-qr/v1/links – saját linkek listája
        register_rest_route( 'smart-qr/v1', '/links', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_links' ],
            'permission_callback' => fn() => is_user_logged_in(),
        ] );

        // POST /wp-json/smart-qr/v1/links – új link létrehozása
        register_rest_route( 'smart-qr/v1', '/links', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'rest_create_link' ],
            'permission_callback' => fn() => is_user_logged_in(),
            'args'                => [
                'original_url' => [ 'required' => true, 'type' => 'string', 'format' => 'uri' ],
                'code_type'    => [ 'required' => false, 'type' => 'string', 'default' => 'qr', 'enum' => [ 'qr', 'ean13', 'code128' ] ],
            ],
        ] );

        // GET /wp-json/smart-qr/v1/links/{id}/stats
        register_rest_route( 'smart-qr/v1', '/links/(?P<id>\d+)/stats', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_link_stats' ],
            'permission_callback' => fn() => is_user_logged_in(),
        ] );
    }

    public function rest_get_links( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        $links   = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, original_url, short_slug, code_type, scan_count, is_active, expires_at, created_at FROM {$this->table_name} WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ) );
        return rest_ensure_response( $links );
    }

    public function rest_create_link( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $user_id = get_current_user_id();
        if ( ! $this->user_has_wc_access( $user_id ) ) {
            return new \WP_Error( 'no_access', __( 'Nincs WooCommerce hozzáférésed.', 'smart-qr-saas' ), [ 'status' => 403 ] );
        }
        global $wpdb;
        $now  = current_time( 'mysql' );
        $slug = $this->generate_unique_slug();
        $wpdb->insert( $this->table_name, [
            'user_id'        => $user_id,
            'original_url'   => esc_url_raw( $request->get_param( 'original_url' ) ),
            'short_slug'     => $slug,
            'scan_count'     => 0,
            'code_type'      => $request->get_param( 'code_type' ),
            'shortlink_path' => 'q',
            'redirect_type'  => '302',
            'is_active'      => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], [ '%d','%s','%s','%d','%s','%s','%s','%d','%s','%s' ] );
        $id       = $wpdb->insert_id;
        $short_url = home_url( '/q/' . $slug . '/' );
        return rest_ensure_response( [ 'id' => $id, 'short_url' => $short_url, 'slug' => $slug ] );
    }

    public function rest_get_link_stats( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        global $wpdb;
        $link_id = (int) $request->get_param( 'id' );
        $user_id = get_current_user_id();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, user_id, scan_count FROM {$this->table_name} WHERE id = %d LIMIT 1",
            $link_id
        ) );
        if ( ! $row || ( (int) $row->user_id !== $user_id && ! current_user_can( 'manage_options' ) ) ) {
            return new \WP_Error( 'not_found', __( 'A link nem található.', 'smart-qr-saas' ), [ 'status' => 404 ] );
        }
        $has_clicks = $wpdb->get_var( "SHOW TABLES LIKE '{$this->clicks_table}'" ) === $this->clicks_table; // phpcs:ignore
        $stats = [ 'link_id' => $link_id, 'total_scans' => (int) $row->scan_count ];
        if ( $has_clicks ) {
            $stats['unique_ips'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ip) FROM {$this->clicks_table} WHERE link_id = %d", $link_id ) );
            $stats['mobile']     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->clicks_table} WHERE link_id = %d AND device_type = 'mobile'", $link_id ) );
            $stats['desktop']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->clicks_table} WHERE link_id = %d AND device_type = 'desktop'", $link_id ) );
            $stats['tablet']     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->clicks_table} WHERE link_id = %d AND device_type = 'tablet'", $link_id ) );
        }
        return rest_ensure_response( $stats );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  SEGÉDFÜGGVÉNYEK
    // ─────────────────────────────────────────────────────────────────────────

    protected function generate_unique_slug(): string {
        global $wpdb;
        $attempts = 0;
        do {
            $slug   = strtolower( wp_generate_password( 6, false, false ) );
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE short_slug = %s",
                $slug
            ) );
            $attempts++;
        } while ( $exists && $attempts < 20 );
        return $slug;
    }
}
