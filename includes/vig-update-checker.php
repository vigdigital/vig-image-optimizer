<?php
/**
 * VIG self-update qua GitHub Releases (Plugin Update Checker).
 * Copy GIỐNG NHAU vào mọi VIG plugin. Guard: chưa vendor PUC -> no-op (không fatal).
 * Kích hoạt: (1) vendor PUC vào <plugin>/plugin-update-checker/,
 *            (2) tạo repo github.com/vigdigital/<slug>, (3) tag Release theo Version header.
 * Repo private: define('VIG_GH_TOKEN', '...') trong wp-config.php (KHÔNG hardcode vào plugin).
 * Xem: knowledge/wp-skills/VIG Plugin Distribution (GitHub + PUC).md
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'vig_setup_updates' ) ) {
    /**
     * @param bool $public Repo công khai? true → check không cần token.
     *                     false (private) → chỉ check khi có VIG_GH_TOKEN, tránh lỗi 404 của GitHub.
     */
    function vig_setup_updates( string $main_file, string $slug, string $org = 'vigdigital', bool $public = false ): void {
        $puc = plugin_dir_path( $main_file ) . 'plugin-update-checker/plugin-update-checker.php';
        if ( ! file_exists( $puc ) ) {
            return; // chưa vendor PUC -> bỏ qua
        }
        // Repo private + không token → GitHub API trả 404 → PUC hiện lỗi.
        // Chỉ chạy checker khi repo public HOẶC có token (hoặc constant VIG_UPDATE_PUBLIC ép bật).
        $has_token = defined( 'VIG_GH_TOKEN' ) && VIG_GH_TOKEN;
        $is_public = $public || ( defined( 'VIG_UPDATE_PUBLIC' ) && VIG_UPDATE_PUBLIC );
        if ( ! $has_token && ! $is_public ) {
            return;
        }
        require_once $puc;
        if ( ! class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
            return;
        }
        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            "https://github.com/{$org}/{$slug}/",
            $main_file,
            $slug
        );
        $checker->setBranch( 'master' ); // repo VIG dùng branch master
        $api = $checker->getVcsApi();
        if ( method_exists( $api, 'enableReleaseAssets' ) ) {
            $api->enableReleaseAssets(); // ưu tiên file .zip attach vào Release
        }
        if ( $has_token ) {
            $checker->setAuthentication( VIG_GH_TOKEN ); // repo private
        }
    }
}
