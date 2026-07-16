<?php
/**
 * VIG Toolkit — shared parent admin menu for VIG plugins.
 * Copy this file IDENTICALLY into every VIG plugin. function_exists guards prevent fatals on duplicate load;
 * the $admin_page_hooks check ensures the parent menu is registered only once (first plugin to run admin_menu).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'vig_toolkit_register_parent' ) ) {
    function vig_toolkit_register_parent(): void {
        global $admin_page_hooks;
        if ( isset( $admin_page_hooks['vig-toolkit'] ) ) {
            return; // already registered by another VIG plugin
        }
        add_menu_page(
            'VIG Toolkit',                 // page title
            'VIG Toolkit',                 // sidebar label
            'manage_options',
            'vig-toolkit',                 // shared parent slug (fixed across VIG plugins)
            'vig_toolkit_dashboard',
            'dashicons-screenoptions',
            58
        );
        // Rename the auto-created duplicate submenu "VIG Toolkit" -> "Dashboard"
        add_submenu_page( 'vig-toolkit', 'VIG Toolkit', 'Dashboard', 'manage_options', 'vig-toolkit', 'vig_toolkit_dashboard' );
    }
}

if ( ! function_exists( 'vig_toolkit_dashboard' ) ) {
    function vig_toolkit_dashboard(): void {
        global $submenu;
        $items = isset( $submenu['vig-toolkit'] ) ? $submenu['vig-toolkit'] : array();
        ?>
        <div class="wrap">
            <h1>VIG Toolkit</h1>
            <p style="font-size:14px;max-width:780px;">
                Central hub for the VIG Digital tools installed on this site. Open a tool's settings from the cards
                below or the submenu on the left.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-top:20px;max-width:900px;">
                <?php foreach ( $items as $it ) :
                    $title = isset( $it[0] ) ? wp_strip_all_tags( $it[0] ) : '';
                    $slug  = isset( $it[2] ) ? $it[2] : '';
                    if ( $slug === '' || $slug === 'vig-toolkit' ) {
                        continue; // skip the Dashboard entry itself
                    }
                    $url = ( strpos( $slug, '.php' ) !== false ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . $slug );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>"
                       style="display:block;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;text-decoration:none;color:#1d2327;box-shadow:0 1px 2px rgba(0,0,0,.04);transition:box-shadow .15s;">
                        <span class="dashicons dashicons-admin-generic" style="color:#2271b1;font-size:22px;width:22px;height:22px;"></span>
                        <strong style="display:block;font-size:15px;margin:8px 0 4px;"><?php echo esc_html( $title ); ?></strong>
                        <span style="color:#646970;font-size:13px;">Open &rarr;</span>
                    </a>
                <?php endforeach; ?>
            </div>

            <p style="margin-top:26px;color:#646970;">
                Built &amp; maintained by <a href="https://vigdigital.com" target="_blank" rel="noopener">VIG Digital</a>.
            </p>
        </div>
        <?php
    }
}
