<?php
/**
 * Plugin Name: VIG Image Optimizer
 * Plugin URI:  https://vigdigital.com
 * Description: Automatically optimizes images the moment they are uploaded to the Media Library — scales down to a maximum width (default 2000px, height preserved), compresses or converts to WebP, strips metadata, and can block oversized uploads. Existing images are never touched. Built by VIG Digital.
 * Version:     1.9.0
 * Author:      VIG Digital
 * Author URI:  https://vigdigital.com
 * License:     GPL-2.0-or-later
 * Text Domain: vig-image-optimizer
 * Update URI:  https://github.com/vigdigital/vig-image-optimizer
 *
 * Ghi chú kỹ thuật (xem knowledge/wp-skills/WP Image Optimization):
 * - CHỈ hạ CHIỀU NGANG (width) về max, KHÔNG cắt chiều cao (khác `sips -Z`/longest-side).
 * - Giữ nguyên tên + đuôi (trừ chế độ PNG→JPEG có đổi .png→.jpg trên file MỚI, an toàn).
 * - Chỉ xử lý ảnh MỚI upload; ảnh đã có trong thư viện không bị đụng.
 * - Chạy được cả trên host chỉ có GD (fallback) lẫn Imagick (PNG lossy tốt hơn).
 */

if (!defined('ABSPATH')) exit;

define('VIG_IMGOPT_PATH', plugin_dir_path(__FILE__));

require_once VIG_IMGOPT_PATH . 'includes/vig-admin-menu.php';

// Tự-update qua GitHub Releases (repo public → không cần token).
require_once VIG_IMGOPT_PATH . 'includes/vig-update-checker.php';
vig_setup_updates( __FILE__, 'vig-image-optimizer', 'vigdigital', true );

class VIG_Image_Optimizer {

    const OPT = 'vig_imgopt_settings';

    private static $defaults = [
        'max_width'     => 2000,       // px — chiều ngang tối đa
        'jpeg_quality'  => 82,         // 1-100
        'png_mode'      => 'quantize', // keep | quantize | to_jpeg
        'png_colors'    => 256,        // số màu khi quantize (lossy PNG, cần Imagick)
        'strip_meta'    => 1,
        'block_over_mb' => 10,         // chặn upload ảnh nặng hơn ngưỡng này (MB); 0 = tắt
        'output_format' => 'webp',     // original | webp (mặc định WebP; tự fallback 'original' nếu host không hỗ trợ WebP)
        // Tối ưu ảnh CŨ theo lịch nền (từ cũ → mới theo thư mục năm/tháng)
        'bulk_cron'          => 0,
        'bulk_cron_interval' => 'hourly',  // hourly | twicedaily | daily
        'bulk_cron_batch'    => 20,
        'bulk_cron_resize'   => 0,
        'bulk_cron_backup'   => 0,
    ];

    public static function init() {
        $o = self::opts();
        add_filter('jpeg_quality', fn() => (int) $o['jpeg_quality']);
        add_filter('wp_editor_set_quality', fn() => (int) $o['jpeg_quality']);
        // Tắt auto-scale của WP (cap theo cạnh dài) — ta tự cap theo CHIỀU NGANG
        add_filter('big_image_size_threshold', '__return_false');
        // Chặn ảnh quá nặng TRƯỚC khi WP nhận (trả lỗi cho người upload)
        add_filter('wp_handle_upload_prefilter', [__CLASS__, 'block_large']);
        // Tối ưu ảnh gốc ngay sau upload, trước khi WP sinh bản con
        add_filter('wp_handle_upload', [__CLASS__, 'on_upload']);

        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'settings']);
        add_action('admin_notices', [__CLASS__, 'saved_notice']);

        // Tối ưu ảnh CŨ (bulk).
        require_once VIG_IMGOPT_PATH . 'includes/class-vio-bulk.php';
        VIO_Bulk::register();
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('vig-imgopt', 'VIO_Bulk_CLI');
        }
    }

    public static function opts() {
        return wp_parse_args(get_option(self::OPT, []), self::$defaults);
    }

    /* ================= CHẶN ẢNH QUÁ NẶNG ================= */
    public static function block_large($file) {
        $mb = (int) self::opts()['block_over_mb'];
        if ($mb > 0
            && strpos((string) ($file['type'] ?? ''), 'image/') === 0
            && (int) ($file['size'] ?? 0) > $mb * 1048576) {
            $file['error'] = sprintf(
                'Ảnh "%s" (%s) vượt giới hạn %d MB. Vui lòng giảm dung lượng ảnh trước khi tải lên.',
                $file['name'] ?? 'image', size_format((int) ($file['size'] ?? 0)), $mb
            );
        }
        return $file;
    }

    /* ================= XỬ LÝ KHI UPLOAD ================= */
    public static function on_upload($upload) {
        if (empty($upload['file']) || empty($upload['type'])) return $upload;
        $type = $upload['type'];
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) return $upload;

        $file   = $upload['file'];
        $before = @filesize($file);

        $newfile = self::optimize($file, $type);   // trả path (cũ hoặc mới) hoặc false
        if (!$newfile) return $upload;

        // Nếu đổi đuôi (.png→.jpg / .webp) → cập nhật lại mảng upload cho WP dùng
        if ($newfile !== $file) {
            $upload['url']  = str_replace(wp_basename($file), wp_basename($newfile), $upload['url']);
            $upload['file'] = $newfile;
            $newtype        = wp_check_filetype(wp_basename($newfile));
            $upload['type'] = $newtype['type'] ?: $upload['type'];
            $file = $newfile;
        }

        if ($before) {
            $after = @filesize($file);
            if ($after && $after < $before) {
                $t = get_transient('vig_imgopt_last') ?: ['n' => 0, 'saved' => 0];
                $t['n']++; $t['saved'] += ($before - $after);
                set_transient('vig_imgopt_last', $t, 60);
            }
        }
        return $upload;
    }

    /**
     * Tối ưu 1 file tại chỗ. Trả về path kết quả (có thể đổi đuôi), hoặc false nếu bỏ qua/lỗi.
     * @param bool $keep_ext true = KHÔNG đổi đuôi (bỏ WebP + PNG→JPEG) — dùng cho ảnh CŨ để không phá tham chiếu.
     */
    public static function optimize($file, $type, $keep_ext = false, $resize_width = true) {
        $o    = self::opts();
        $max  = (int) $o['max_width'];
        $orig = (int) @filesize($file);

        // ==== Convert sang WebP (nén tốt nhất, GIỮ được transparency) ====
        if (!$keep_ext && ($o['output_format'] ?? 'original') === 'webp' && self::webp_ok()) {
            $editor = wp_get_image_editor($file);
            if (!is_wp_error($editor)) {
                $size = $editor->get_size();
                if (!empty($size['width']) && $size['width'] > $max) {
                    $editor->resize($max, PHP_INT_MAX, false);   // CHỈ cap chiều ngang
                }
                $editor->set_quality((int) $o['jpeg_quality']);
                // nguồn đã .webp → ghi ra tạm; ảnh khác → đổi đuôi .webp
                $target = ($type === 'image/webp')
                    ? $file . '-viotmp.webp'
                    : preg_replace('/\.[^.\/]+$/', '', $file) . '.webp';
                $saved = $editor->save($target, 'image/webp');
                if (!is_wp_error($saved) && !empty($saved['path'])) {
                    if ((int) @filesize($saved['path']) < $orig) {   // WebP nhỏ hơn → chốt
                        if ($type === 'image/webp') { @rename($saved['path'], $file); return $file; }
                        @unlink($file);                              // xoá bản gốc jpg/png
                        return $saved['path'];                       // .webp mới
                    }
                    @unlink($saved['path']);                         // WebP không nhỏ hơn → bỏ, xử lý thường
                }
            }
        }

        // PNG lossy — giữ .png. Ưu tiên pngquant (đúng thuật toán TinyPNG dùng: nén sâu
        // hơn nhiều mà pixel gần như y hệt); không có thì dùng Imagick quantize.
        if ($type === 'image/png' && $o['png_mode'] === 'quantize') {
            if ($resize_width) self::resize_png_width($file, $max);
            if (self::png_pngquant($file, $o)) return $file;
            if (extension_loaded('imagick') && self::png_quantize_imagick($file, $o, false)) return $file;
            // lỗi → rơi xuống đường chung
        }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) return false;

        $size = $editor->get_size();
        if ($resize_width && !empty($size['width']) && $size['width'] > $max) {
            $editor->resize($max, PHP_INT_MAX, false);   // CHỈ cap chiều ngang
        }
        $editor->set_quality((int) $o['jpeg_quality']);

        // PNG → JPEG (chỉ khi chắc chắn KHÔNG trong suốt) — CHỈ giữ nếu nhỏ hơn thật
        if (!$keep_ext && $type === 'image/png' && $o['png_mode'] === 'to_jpeg' && !self::png_has_alpha($file)) {
            $new   = preg_replace('/\.png$/i', '.jpg', $file);
            if ($new === $file) $new .= '.jpg';
            $saved = $editor->save($new, 'image/jpeg');
            if (!is_wp_error($saved) && !empty($saved['path'])) {
                if ((int) @filesize($saved['path']) < $orig) {   // JPEG nhỏ hơn → chốt
                    if ($saved['path'] !== $file) @unlink($file);
                    return $saved['path'];
                }
                @unlink($saved['path']);                          // JPEG to hơn → bỏ, giữ .png
            }
        }

        // Lưu đè cùng định dạng — qua file tạm để KHÔNG BAO GIỜ làm ảnh phình
        $ext = ($type === 'image/jpeg') ? 'jpg' : (($type === 'image/webp') ? 'webp' : 'png');
        $tmp = preg_replace('/\.[^.\/]+$/', '', $file) . '-viotmp.' . $ext;
        $saved = $editor->save($tmp, $type);
        if (is_wp_error($saved) || empty($saved['path'])) return false;

        if ((int) @filesize($saved['path']) < $orig) {           // CHỈ chốt khi nhỏ hơn thật → không bao giờ phình
            @rename($saved['path'], $file);
            if ($o['strip_meta'] && $type !== 'image/png') self::strip_meta_imagick($file);
        } else {
            @unlink($saved['path']);                              // không nhỏ hơn → giữ nguyên gốc
        }
        return $file;
    }

    /** Hạ chiều ngang PNG về max (dùng editor chuẩn của WP). */
    private static function resize_png_width($file, $max) {
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) return;
        $s = $editor->get_size();
        if (!empty($s['width']) && $s['width'] > (int) $max) {
            $editor->resize((int) $max, PHP_INT_MAX, false);
            $editor->save($file, 'image/png');
        }
    }

    /**
     * Nén PNG bằng pngquant — ĐÚNG thuật toán TinyPNG dùng (libimagequant).
     * Nén sâu hơn Imagick quantize rất nhiều mà màu/độ sáng gần như y hệt.
     * pngquant tự TỪ CHỐI (exit 99) khi không đạt ngưỡng chất lượng → giữ nguyên ảnh gốc.
     * KHÔNG dùng --strip để giữ lại ICC profile (tránh ảnh bị xỉn màu).
     */
    private static function png_pngquant($file, $o) {
        $bin = self::pngquant_bin();
        if (!$bin) return false;
        $colors = max(2, min(256, (int) $o['png_colors']));
        $tmp    = $file . '-viotmp.png';
        @unlink($tmp);
        $cmd = escapeshellarg($bin) . ' --quality=65-90 --speed 3 --force --output '
             . escapeshellarg($tmp) . ' ' . $colors . ' -- ' . escapeshellarg($file) . ' 2>&1';
        $out = array(); $code = 1;
        @exec($cmd, $out, $code);
        if (0 !== $code || !file_exists($tmp) || !@filesize($tmp)) { @unlink($tmp); return false; }
        if ((int) @filesize($tmp) < (int) @filesize($file)) { @rename($tmp, $file); return true; }
        @unlink($tmp);
        return false;   // không nhỏ hơn → giữ gốc
    }

    /** Tìm pngquant trên host (cache 1 ngày). '' nếu không có / exec bị chặn. */
    private static function pngquant_bin() {
        $c = get_transient('vig_imgopt_pngquant');
        if (false !== $c) return ('0' === $c) ? false : $c;

        $bin      = '';
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (function_exists('exec') && !in_array('exec', $disabled, true)) {
            foreach (array('/usr/bin/pngquant', '/usr/local/bin/pngquant', '/opt/homebrew/bin/pngquant', '/opt/local/bin/pngquant') as $p) {
                if (@is_executable($p)) { $bin = $p; break; }
            }
            if (!$bin) {
                $out = array();
                @exec('command -v pngquant 2>/dev/null', $out);
                if (!empty($out[0]) && @is_executable(trim($out[0]))) $bin = trim($out[0]);
            }
        }
        set_transient('vig_imgopt_pngquant', $bin ?: '0', DAY_IN_SECONDS);
        return $bin ?: false;
    }

    /** PNG lossy qua Imagick: resize width + giảm màu + nén tối đa. */
    private static function png_quantize_imagick($file, $o, $resize_width = true) {
        try {
            $im  = new Imagick($file);
            $w   = $im->getImageWidth();
            $max = (int) $o['max_width'];
            if ($resize_width && $w > $max) {
                $h = (int) round($im->getImageHeight() * ($max / $w));
                $im->resizeImage($max, $h, Imagick::FILTER_LANCZOS, 1);
            }
            // QUAN TRỌNG: phải là COLORSPACE_SRGB. Dùng COLORSPACE_RGB = RGB tuyến tính
            // → ImageMagick chuyển ảnh sang linear rồi ghi ra như sRGB ⇒ ẢNH BỊ TỐI ĐI rõ rệt.
            $before = self::mean_brightness($im);
            $im->quantizeImage(max(2, (int) $o['png_colors']), Imagick::COLORSPACE_SRGB, 0, false, false);
            $im->setImageDepth(8);
            $im->setOption('png:compression-level', '9');
            if (!empty($o['strip_meta'])) self::strip_keep_icc($im);

            // LƯỚI AN TOÀN: nếu độ sáng lệch bất thường (>8%) thì KHÔNG ghi đè — giữ ảnh gốc.
            // Chính lỗi colorspace ở trên từng làm ảnh tối đi ~36% mà không ai phát hiện.
            $after = self::mean_brightness($im);
            if (null !== $before && null !== $after && $before > 0 && abs($after - $before) / $before > 0.08) {
                $im->clear();
                return false;   // rơi xuống đường xử lý thường (an toàn)
            }

            $im->writeImage($file);
            $im->clear();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Độ sáng trung bình 0..1 — dùng để phát hiện ảnh bị lệch màu/tối đi. null = không đo được. */
    private static function mean_brightness(Imagick $im) {
        try {
            $c = clone $im;
            $c->setImageColorspace(Imagick::COLORSPACE_SRGB);
            // PHẢI bỏ alpha trước khi đo: quantize có thể thêm/bớt kênh alpha, mà CHANNEL_ALL
            // tính cả alpha ⇒ mean lệch ~11% một cách giả tạo ⇒ lưới an toàn báo động nhầm.
            $c->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $c->resizeImage(50, 0, Imagick::FILTER_BOX, 1);   // thu nhỏ cho nhanh
            $s = $c->getImageChannelMean(Imagick::CHANNEL_RED | Imagick::CHANNEL_GREEN | Imagick::CHANNEL_BLUE);
            $c->clear();
            if (empty($s['mean'])) return null;
            $q = (float) Imagick::getQuantumRange()['quantumRangeLong'];
            return $q > 0 ? ((float) $s['mean']) / $q : null;
        } catch (\Throwable $e) {
            return null;   // không đo được → bỏ qua lưới an toàn
        }
    }

    private static function strip_meta_imagick($file) {
        if (!extension_loaded('imagick')) return;
        try { $im = new Imagick($file); self::strip_keep_icc($im); $im->writeImage($file); $im->clear(); }
        catch (\Throwable $e) {}
    }

    /**
     * Xoá EXIF/GPS/thumbnail nhưng GIỮ LẠI ICC color profile.
     * stripImage() trần sẽ xoá luôn ICC → ảnh chụp bằng iPhone (Display P3) hay máy ảnh
     * (Adobe RGB) bị trình duyệt hiểu nhầm thành sRGB ⇒ MÀU XỈN / TỐI ĐI.
     * ICC chỉ vài trăm byte → giữ lại gần như không tốn dung lượng.
     */
    private static function strip_keep_icc(Imagick $im) {
        $icc = '';
        try { $icc = (string) $im->getImageProfile('icc'); } catch (\Throwable $e) {}
        $im->stripImage();
        if ($icc !== '') {
            try { $im->profileImage('icc', $icc); } catch (\Throwable $e) {}
        }
    }

    /** Engine có hỗ trợ ghi WebP không (GD imagewebp hoặc Imagick). */
    public static function webp_ok() {
        if (function_exists('imagewebp')) return true;
        if (extension_loaded('imagick')) {
            try { return !empty((new Imagick())->queryFormats('WEBP')); } catch (\Throwable $e) {}
        }
        return false;
    }

    /** Đọc IHDR color type: chỉ grayscale(0)/truecolor(2) mới chắc chắn KHÔNG alpha. */
    private static function png_has_alpha($file) {
        $fh = @fopen($file, 'rb'); if (!$fh) return true;
        $data = fread($fh, 26); fclose($fh);
        if (strlen($data) < 26) return true;
        $ct = ord($data[25]);
        return !($ct === 0 || $ct === 2);
    }

    /* ================= TỐI ƯU ẢNH CŨ (BULK) ================= */
    public static function render_bulk_box() {
        $pending = VIO_Bulk::count_pending();
        $done    = VIO_Bulk::count_done();
        $saved   = VIO_Bulk::total_saved();
        $nonce   = wp_create_nonce('vig_imgopt_bulk');
        ?>
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin-top:14px;max-width:820px">
            <h2 style="margin-top:0">Tối ưu ảnh CŨ (đã có trong thư viện)</h2>
            <p class="description" style="font-size:13px;max-width:720px">
                Nén lại các ảnh đã upload từ trước — <strong>giữ nguyên đuôi &amp; tên file</strong> nên không phá liên kết trong bài viết. Xử lý cả bản gốc lẫn thumbnail. Ảnh đã tối ưu sẽ được bỏ qua ở lần chạy sau.
            </p>
            <p><strong>Chưa tối ưu:</strong> <span id="vio-pending"><?php echo (int) $pending; ?></span> ảnh
               &nbsp;·&nbsp; <strong>Đã tối ưu:</strong> <span id="vio-done"><?php echo (int) $done; ?></span>
               &nbsp;·&nbsp; <strong>Đã tiết kiệm:</strong> <span id="vio-saved"><?php echo esc_html(size_format($saved)); ?></span>
               <?php $cf = VIO_Bulk::current_folder(); if ($cf): ?>&nbsp;·&nbsp; <strong>Đang tới thư mục:</strong> <code><?php echo esc_html($cf); ?></code><?php endif; ?></p>

            <?php $cs = VIO_Bulk::cron_status(); if ($cs['enabled']): ?>
                <p style="padding:8px 12px;border-left:4px solid #2271b1;background:#eef4fb">
                    ⏱ <strong>Lịch nền: BẬT</strong>
                    <?php echo $cs['next'] ? ' · lần tới ' . esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $cs['next']), 'H:i d/m')) : ''; ?>
                    <?php if (!empty($cs['log']['at'])): ?>
                        · lần trước: xử lý <?php echo (int) $cs['log']['processed']; ?> ảnh (thư mục <code><?php echo esc_html($cs['log']['folder'] ?: '—'); ?></code>), tiết kiệm <?php echo esc_html(size_format((int) $cs['log']['saved'])); ?>
                    <?php endif; ?>
                    <br><span class="description">Xử lý dần từ cũ đến mới, ~<?php echo (int) self::opts()['bulk_cron_batch']; ?> ảnh/lượt. Cấu hình ở phần "Lịch tối ưu ảnh cũ (nền)" bên dưới.</span>
                </p>
            <?php endif; ?>

            <p>
                <label style="margin-right:16px"><input type="checkbox" id="vio-resize"> Resize bản gốc quá khổ về max-width (<?php echo (int) self::opts()['max_width']; ?>px) + regenerate thumbnail</label><br>
                <label><input type="checkbox" id="vio-backup"> Giữ backup bản gốc (có thể hoàn tác — tốn thêm dung lượng)</label>
            </p>

            <?php $folders = VIO_Bulk::folders(); ?>
            <table class="form-table" style="margin-top:4px">
                <tr>
                    <th scope="row" style="width:170px;padding-left:0">Phạm vi</th>
                    <td style="padding-left:0">
                        <select id="vio-folder" style="min-width:290px">
                            <option value="" data-pending="<?php echo (int) $pending; ?>">Toàn bộ thư viện — <?php echo (int) $pending; ?> ảnh chưa tối ưu</option>
                            <?php foreach ($folders as $f => $d): ?>
                                <option value="<?php echo esc_attr($f); ?>" data-pending="<?php echo (int) $d['pending']; ?>" <?php disabled($d['pending'], 0); ?>>
                                    📁 <?php echo esc_html($f); ?> — <?php echo (int) $d['pending']; ?>/<?php echo (int) $d['total']; ?> chưa tối ưu
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Chọn 1 thư mục năm/tháng để tối ưu từng phần thay vì chạy cả thư viện.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row" style="padding-left:0">Chạy thử 1 ảnh</th>
                    <td style="padding-left:0">
                        <input type="number" id="vio-one-id" class="small-text" placeholder="ID ảnh" min="1">
                        <label style="margin-left:8px"><input type="checkbox" id="vio-one-force"> Chạy lại cả ảnh đã tối ưu</label>
                        <button type="button" class="button" id="vio-one-run" style="margin-left:8px">Tối ưu ảnh này</button>
                        <p class="description">
                            Nên làm <strong>trước khi chạy hàng loạt</strong>. Lấy ID ở <a href="<?php echo esc_url(admin_url('upload.php?mode=list')); ?>">Thư viện</a> — rê chuột vào ảnh, xem số trong link <code>post=<strong>123</strong></code>.
                        </p>
                        <p id="vio-one-result" style="margin:6px 0 0"></p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" class="button button-primary" id="vio-start" <?php disabled($pending, 0); ?>>Bắt đầu tối ưu</button>
                <button type="button" class="button" id="vio-stop" style="display:none">Dừng</button>
            </p>
            <div id="vio-progress-wrap" style="display:none;max-width:520px">
                <div style="background:#f0f0f1;border-radius:6px;overflow:hidden;height:22px"><div id="vio-bar" style="height:100%;width:0;background:#2271b1;transition:width .3s"></div></div>
                <p id="vio-status" style="font-size:13px;color:#50575e;margin:6px 0 0"></p>
            </div>

            <script>
            (function(){
                var start=document.getElementById('vio-start'),stop=document.getElementById('vio-stop'),
                    wrap=document.getElementById('vio-progress-wrap'),bar=document.getElementById('vio-bar'),
                    status=document.getElementById('vio-status'),
                    folderSel=document.getElementById('vio-folder'),
                    ep=<?php echo (int) $pending; ?>, total=ep, running=false;

                function scopePending(){
                    var op=folderSel.options[folderSel.selectedIndex];
                    return parseInt(op&&op.getAttribute('data-pending')||'0',10);
                }
                folderSel.addEventListener('change',function(){
                    total=scopePending();
                    start.disabled = total===0;
                    status.textContent = total===0 ? 'Thư mục này đã tối ưu xong.' : '';
                    bar.style.width='0';
                });

                function run(){
                    if(!running) return;
                    var fd=new FormData();
                    fd.append('action','vig_imgopt_bulk');
                    fd.append('nonce','<?php echo esc_js($nonce); ?>');
                    fd.append('batch','5');
                    fd.append('folder',folderSel.value);
                    fd.append('resize',document.getElementById('vio-resize').checked?'1':'');
                    fd.append('backup',document.getElementById('vio-backup').checked?'1':'');
                    fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        if(!j.success){ status.textContent='Lỗi: '+((j.data&&j.data.message)||'?'); running=false; reset(); return; }
                        var d=j.data, doneNow=total-d.remaining;
                        bar.style.width=(total?Math.round(doneNow/total*100):100)+'%';
                        status.textContent='Đã xử lý '+doneNow+' / '+total+' · còn '+d.remaining+' · tiết kiệm '+fmt(d.total_saved);
                        document.getElementById('vio-pending').textContent=d.remaining;
                        document.getElementById('vio-done').textContent=d.done;
                        document.getElementById('vio-saved').textContent=fmt(d.total_saved);
                        if(d.remaining>0 && running){ run(); }
                        else { running=false; status.textContent='Xong ✓ '+status.textContent; reset(); }
                    }).catch(function(e){ status.textContent='Lỗi mạng: '+e; running=false; reset(); });
                }
                function fmt(b){ if(b<1024)return b+' B'; var u=['KB','MB','GB'],i=-1; do{b/=1024;i++;}while(b>=1024&&i<2); return b.toFixed(1)+' '+u[i]; }
                function reset(){ start.style.display=''; stop.style.display='none'; }
                start.addEventListener('click',function(){ total=scopePending(); running=true; wrap.style.display='block'; start.style.display='none'; stop.style.display=''; run(); });
                stop.addEventListener('click',function(){ running=false; reset(); status.textContent+=' (đã dừng)'; });

                // --- chạy thử đúng 1 ảnh ---
                var oneBtn=document.getElementById('vio-one-run'), oneOut=document.getElementById('vio-one-result');
                oneBtn.addEventListener('click',function(){
                    var id=parseInt(document.getElementById('vio-one-id').value,10);
                    if(!id){ oneOut.innerHTML='<span style="color:#b32d2e">Nhập ID ảnh trước.</span>'; return; }
                    oneBtn.disabled=true; oneOut.textContent='Đang xử lý…';
                    var fd=new FormData();
                    fd.append('action','vig_imgopt_bulk');
                    fd.append('nonce','<?php echo esc_js($nonce); ?>');
                    fd.append('id',id);
                    fd.append('force',document.getElementById('vio-one-force').checked?'1':'');
                    fd.append('resize',document.getElementById('vio-resize').checked?'1':'');
                    fd.append('backup',document.getElementById('vio-backup').checked?'1':'');
                    fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        oneBtn.disabled=false;
                        if(!j.success){ oneOut.innerHTML='<span style="color:#b32d2e">Lỗi: '+((j.data&&j.data.message)||'?')+'</span>'; return; }
                        var d=j.data;
                        if(d.skipped){ oneOut.innerHTML='<span style="color:#996800">Ảnh này đã tối ưu rồi — tick "Chạy lại" nếu muốn ép chạy.</span>'; return; }
                        var pct=d.before?Math.round((1-d.after/d.before)*100):0;
                        oneOut.innerHTML='<span style="color:#007017">✓ '+d.file+': '+fmt(d.before)+' → '+fmt(d.after)+' (-'+pct+'%)</span> · <a href="<?php echo esc_url(admin_url('upload.php')); ?>?item='+id+'" target="_blank">xem ảnh</a>';
                        document.getElementById('vio-pending').textContent=d.remaining;
                        document.getElementById('vio-done').textContent=d.done;
                        document.getElementById('vio-saved').textContent=fmt(d.total_saved);
                    }).catch(function(e){ oneBtn.disabled=false; oneOut.innerHTML='<span style="color:#b32d2e">Lỗi mạng: '+e+'</span>'; });
                });
            })();
            </script>
        </div>
        <?php
    }

    /* ================= ADMIN ================= */
    public static function menu() {
        vig_toolkit_register_parent();              // đảm bảo menu cha "VIG Toolkit" tồn tại (idempotent)
        add_submenu_page(
            'vig-toolkit',                          // gắn vào menu cha chung
            'VIG Image Optimizer',                  // page title
            'Image Optimizer',                      // menu label
            'manage_options',
            'vig-image-optimizer',                  // slug settings của plugin
            [__CLASS__, 'page']
        );
    }

    public static function settings() {
        register_setting('vig_imgopt', self::OPT, [__CLASS__, 'sanitize']);
    }

    /**
     * Mỗi tab là 1 form riêng nhưng cùng ghi vào 1 option key. Vì vậy phải xuất phát từ
     * GIÁ TRỊ ĐANG LƯU và chỉ ghi đè những khoá thuộc tab vừa submit (_section) —
     * nếu dựng lại từ defaults thì lưu tab này sẽ xoá cài đặt của tab kia (nhất là checkbox).
     */
    public static function sanitize($in) {
        $d   = self::$defaults;
        $in  = is_array($in) ? $in : [];
        $cur = wp_parse_args(get_option(self::OPT, []), $d);

        $sec    = isset($in['_section']) ? (string) $in['_section'] : '';
        $upload = ('' === $sec || 'upload' === $sec);   // '' = form gộp (tương thích ngược)
        $old    = ('' === $sec || 'old' === $sec);

        if ($upload) {
            $cur['max_width']     = max(200, min(6000, (int) ($in['max_width'] ?? $d['max_width'])));
            $cur['jpeg_quality']  = max(1, min(100, (int) ($in['jpeg_quality'] ?? $d['jpeg_quality'])));
            $cur['png_mode']      = in_array(($in['png_mode'] ?? ''), ['keep','quantize','to_jpeg'], true) ? $in['png_mode'] : $d['png_mode'];
            $cur['png_colors']    = max(2, min(256, (int) ($in['png_colors'] ?? $d['png_colors'])));
            $cur['strip_meta']    = empty($in['strip_meta']) ? 0 : 1;
            $cur['block_over_mb'] = max(0, min(1000, (int) ($in['block_over_mb'] ?? $d['block_over_mb'])));
            $cur['output_format'] = in_array(($in['output_format'] ?? ''), ['original','webp'], true) ? $in['output_format'] : $d['output_format'];
        }

        if ($old) {
            $cur['bulk_cron']          = empty($in['bulk_cron']) ? 0 : 1;
            $cur['bulk_cron_interval'] = in_array(($in['bulk_cron_interval'] ?? ''), ['hourly','twicedaily','daily'], true) ? $in['bulk_cron_interval'] : $d['bulk_cron_interval'];
            $cur['bulk_cron_batch']    = max(1, min(200, (int) ($in['bulk_cron_batch'] ?? $d['bulk_cron_batch'])));
            $cur['bulk_cron_resize']   = empty($in['bulk_cron_resize']) ? 0 : 1;
            $cur['bulk_cron_backup']   = empty($in['bulk_cron_backup']) ? 0 : 1;
        }

        unset($cur['_section']);
        return $cur;
    }

    public static function page() {
        $o   = self::opts();
        $tab = (isset($_GET['tab']) && 'upload' === $_GET['tab']) ? 'upload' : 'old';
        $url = admin_url('admin.php?page=vig-image-optimizer');
        ?>
        <div class="wrap">
            <h1>VIG Image Optimizer</h1>

            <h2 class="nav-tab-wrapper" style="margin-bottom:16px">
                <a href="<?php echo esc_url($url . '&tab=old'); ?>" class="nav-tab <?php echo 'old' === $tab ? 'nav-tab-active' : ''; ?>">
                    Ảnh CŨ (đã có trong thư viện)
                </a>
                <a href="<?php echo esc_url($url . '&tab=upload'); ?>" class="nav-tab <?php echo 'upload' === $tab ? 'nav-tab-active' : ''; ?>">
                    Ảnh upload MỚI
                </a>
            </h2>

            <?php 'old' === $tab ? self::tab_old($o) : self::tab_upload($o); ?>
        </div>
        <?php
    }

    /* ================= TAB: ẢNH CŨ ================= */
    private static function tab_old($o) {
        ?>
        <p class="description" style="max-width:760px;font-size:14px">
            Nén lại ảnh <strong>đã có sẵn</strong> trong Thư viện. Giữ nguyên tên &amp; đuôi file nên không phá liên kết trong bài viết.
            Nên làm theo thứ tự: <strong>chạy thử 1 ảnh → 1 thư mục → toàn bộ</strong>.
        </p>

        <?php self::render_bulk_box(); ?>
        <?php self::render_reset_box(); ?>

        <form method="post" action="options.php" style="margin-top:18px;max-width:820px">
            <?php settings_fields('vig_imgopt'); ?>
            <input type="hidden" name="<?php echo self::OPT; ?>[_section]" value="old">
                        <h2 style="margin-top:24px">Lịch tối ưu ảnh cũ (nền)</h2>
                        <p class="description" style="max-width:640px">Tự tối ưu ảnh cũ theo lô ở chế độ nền, xử lý <strong>từ cũ đến mới</strong> (theo thư mục năm/tháng). Hợp với thư viện lớn — khỏi bấm tay.</p>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Bật lịch nền</th>
                                <td><label><input type="checkbox" name="<?php echo self::OPT; ?>[bulk_cron]" value="1" <?php checked($o['bulk_cron'],1); ?>> Tự tối ưu ảnh cũ theo lịch</label></td>
                            </tr>
                            <tr>
                                <th scope="row">Tần suất</th>
                                <td><select name="<?php echo self::OPT; ?>[bulk_cron_interval]">
                                    <?php foreach (['hourly'=>'Mỗi giờ','twicedaily'=>'Ngày 2 lần','daily'=>'Mỗi ngày'] as $k=>$lbl): ?>
                                        <option value="<?php echo $k; ?>" <?php selected($o['bulk_cron_interval'],$k); ?>><?php echo $lbl; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                &nbsp; Mỗi lần: <input type="number" name="<?php echo self::OPT; ?>[bulk_cron_batch]" value="<?php echo esc_attr($o['bulk_cron_batch']); ?>" min="1" max="200" style="width:80px"> ảnh</td>
                            </tr>
                            <tr>
                                <th scope="row">Tuỳ chọn</th>
                                <td>
                                    <label style="display:block;margin-bottom:4px"><input type="checkbox" name="<?php echo self::OPT; ?>[bulk_cron_resize]" value="1" <?php checked($o['bulk_cron_resize'],1); ?>> Resize bản gốc quá khổ về max-width</label>
                                    <label><input type="checkbox" name="<?php echo self::OPT; ?>[bulk_cron_backup]" value="1" <?php checked($o['bulk_cron_backup'],1); ?>> Giữ backup bản gốc</label>
                                    <p class="description">wp-cron chỉ chạy khi site có truy cập. Thư viện rất lớn nên đặt cron thật (system cron gọi wp-cron.php) hoặc dùng <code>wp vig-imgopt bulk</code>.</p>
                                </td>
                            </tr>
                        </table>
            <?php submit_button('Lưu cài đặt lịch nền'); ?>
        </form>
        <?php
    }

    /* ================= TAB: ẢNH UPLOAD MỚI ================= */
    private static function tab_upload($o) {
        $imagick = extension_loaded('imagick');
        ?>
        <p class="description" style="max-width:760px;font-size:14px">
            Áp dụng cho ảnh <strong>upload từ nay về sau</strong> — xử lý ngay lúc tải lên, <em>trước khi</em> WordPress sinh thumbnail.
            Không đụng tới ảnh đã có trong thư viện (ảnh cũ nằm ở tab bên cạnh).
        </p>
        <p style="padding:8px 12px;border-left:4px solid <?php echo $imagick ? '#46b450' : '#dba617'; ?>;background:<?php echo $imagick ? '#ecf7ed' : '#fcf3cd'; ?>;display:inline-block;margin-top:4px">
            Image engine: <strong><?php echo $imagick ? 'Imagick ✓ — supports lossy PNG (keeps .png)' : 'GD only — lossy PNG is unavailable; use “Convert PNG → JPEG” for photos'; ?></strong>
        </p>

        <div style="display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start;margin-top:12px">
            <div style="flex:1 1 480px;min-width:340px">
                <form method="post" action="options.php">
                    <?php settings_fields('vig_imgopt'); ?>
                    <input type="hidden" name="<?php echo self::OPT; ?>[_section]" value="upload">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label>Output format</label></th>
                                <td>
                                    <?php $webp_ok = self::webp_ok(); ?>
                                    <select name="<?php echo self::OPT; ?>[output_format]">
                                        <option value="original" <?php selected($o['output_format'],'original'); ?>>Keep original format (JPEG / PNG)</option>
                                        <option value="webp" <?php selected($o['output_format'],'webp'); ?> <?php disabled(!$webp_ok); ?>>Convert everything to WebP<?php echo $webp_ok ? ' — recommended' : ' (not supported on this server)'; ?></option>
                                    </select>
                                    <p class="description">WebP is ~25–35% smaller than JPEG <strong>and keeps transparency</strong> (so even logos convert safely). Supported by all modern browsers. When enabled, this overrides the “PNG handling” option below.<?php echo $webp_ok ? '' : ' <strong>This server has no WebP support</strong> — option disabled.'; ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Max width</label></th>
                                <td><input type="number" name="<?php echo self::OPT; ?>[max_width]" value="<?php echo esc_attr($o['max_width']); ?>" min="200" max="6000" step="10"> px
                                    <p class="description">Images wider than this are scaled down. Height is kept in proportion — <strong>never cropped</strong>. Recommended: 2000.</p></td>
                            </tr>
                            <tr>
                                <th scope="row"><label>JPEG quality</label></th>
                                <td><input type="number" name="<?php echo self::OPT; ?>[jpeg_quality]" value="<?php echo esc_attr($o['jpeg_quality']); ?>" min="1" max="100"> /100
                                    <p class="description">Applies to JPEGs and every generated thumbnail size. 80–85 is a good balance.</p></td>
                            </tr>
                            <tr>
                                <th scope="row"><label>PNG handling</label></th>
                                <td>
                                    <?php $modes = ['quantize'=>'Lossy color reduction — keeps .png (requires Imagick)','to_jpeg'=>'Convert PNG → JPEG (only images without transparency)','keep'=>'Resize + lossless compress only (light)']; ?>
                                    <select name="<?php echo self::OPT; ?>[png_mode]">
                                        <?php foreach ($modes as $k=>$label): ?>
                                            <option value="<?php echo $k; ?>" <?php selected($o['png_mode'],$k); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Photos saved as PNG are very heavy. With Imagick choose “Lossy”; on GD-only hosts choose “Convert PNG → JPEG”. Transparent PNGs are always kept as PNG regardless of this setting.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>PNG colors (lossy)</label></th>
                                <td><input type="number" name="<?php echo self::OPT; ?>[png_colors]" value="<?php echo esc_attr($o['png_colors']); ?>" min="2" max="256">
                                    <p class="description">Used only in “Lossy” mode. 256 = safe.</p></td>
                            </tr>
                            <tr>
                                <th scope="row">Strip metadata</th>
                                <td><label><input type="checkbox" name="<?php echo self::OPT; ?>[strip_meta]" value="1" <?php checked($o['strip_meta'],1); ?>> Remove EXIF/ICC data (reduces file size)</label></td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Block oversized images</label></th>
                                <td><input type="number" name="<?php echo self::OPT; ?>[block_over_mb]" value="<?php echo esc_attr($o['block_over_mb']); ?>" min="0" max="1000"> MB
                                    <p class="description">Reject any image upload larger than this and show an error to the uploader. <strong>0 = disabled</strong>. Default: 10 MB.</p></td>
                            </tr>
                        </table>
                    <?php submit_button('Save settings'); ?>
                </form>
            </div>

                <!-- English guide -->
                <aside style="flex:0 0 320px;max-width:340px;position:sticky;top:40px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:6px 20px 16px">
                    <h2 style="font-size:15px;border-bottom:1px solid #f0f0f1;padding-bottom:10px">How it works</h2>
                    <p style="font-size:13px;color:#50575e">Runs <strong>automatically</strong> the instant an image is uploaded to the Media Library — <em>before</em> WordPress generates thumbnails, so every size is created from the already-optimized original. No queue, no cron.</p>
                    <p style="font-size:13px;color:#50575e">Only <strong>new uploads</strong> are processed. Images already in your library are left untouched.</p>

                    <h2 style="font-size:15px;border-bottom:1px solid #f0f0f1;padding-bottom:10px">Two layers of protection</h2>
                    <ol style="font-size:13px;color:#50575e;padding-left:18px;margin:0">
                        <li style="margin-bottom:6px"><strong>Optimize</strong> — images within the limit are resized to the max width and compressed.</li>
                        <li><strong>Block</strong> — images over the “Block oversized” limit are refused up front, so huge files never enter the library.</li>
                    </ol>

                    <h2 style="font-size:15px;border-bottom:1px solid #f0f0f1;padding-bottom:10px">WebP output</h2>
                    <p style="font-size:13px;color:#50575e">Set <strong>Output format → WebP</strong> for the best compression (~25–35% smaller than JPEG). Unlike JPEG, WebP <strong>keeps transparency</strong>, so logos and cut-outs convert safely too. Every modern browser supports it.</p>

                    <h2 style="font-size:15px;border-bottom:1px solid #f0f0f1;padding-bottom:10px">Safe by design</h2>
                    <ul style="font-size:13px;color:#50575e;padding-left:18px;margin:0;list-style:disc">
                        <li style="margin-bottom:6px"><strong>Transparency preserved</strong> — WebP keeps it; in “Keep original” mode, transparent PNGs stay PNG (never flattened to JPEG).</li>
                        <li style="margin-bottom:6px"><strong>Never enlarges</strong> — the optimized version is kept only if it is genuinely smaller.</li>
                        <li><strong>Links stay valid</strong> — filename &amp; URL are unchanged (an opaque PNG may become <code>.jpg</code> only when that is smaller).</li>
                    </ul>

                    <h2 style="font-size:15px;border-bottom:1px solid #f0f0f1;padding-bottom:10px">Tips</h2>
                    <ul style="font-size:13px;color:#50575e;padding-left:18px;margin:0;list-style:disc">
                        <li style="margin-bottom:6px">Best results with the <strong>Imagick</strong> PHP extension. On GD-only hosts, set PNG handling to “Convert PNG → JPEG”.</li>
                        <li>To optimize images uploaded <em>before</em> installing this plugin, re-save or re-upload them.</li>
                    </ul>
                </aside>
        </div>
        <?php
    }

    /* ================= HỘP: ĐẶT LẠI SAU KHI RESTORE ================= */
    private static function render_reset_box() {
        $nonce = wp_create_nonce('vig_imgopt_bulk');
        ?>
        <div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #dba617;border-radius:6px;padding:14px 20px;margin-top:14px;max-width:820px">
            <h2 style="margin-top:0;font-size:15px">Vừa restore media? Bấm vào đây</h2>
            <p class="description" style="max-width:720px">
                Plugin đánh dấu ảnh đã tối ưu trong <strong>database</strong>. Khi bạn restore <strong>file ảnh</strong>, dấu này vẫn còn
                nên plugin tưởng đã xong và báo <em>“không còn ảnh nào cần tối ưu”</em>.
            </p>
            <p>
                <button type="button" class="button" id="vio-rescan">Quét lại (khuyên dùng)</button>
                <button type="button" class="button" id="vio-reset">Đặt lại toàn bộ</button>
                <span id="vio-reset-out" style="margin-left:10px"></span>
            </p>
            <p class="description" style="max-width:720px;margin:0">
                <strong>Quét lại</strong>: so dung lượng file hiện tại với lúc tối ưu — chỉ đặt lại ảnh <em>thực sự đã bị thay</em>. An toàn, nên dùng trước.<br>
                <strong>Đặt lại toàn bộ</strong>: xoá hết dấu, mọi ảnh sẽ được nén lại từ đầu (tốn thời gian hơn; ảnh chưa restore sẽ bị nén lần nữa).
            </p>

            <script>
            (function(){
                var out=document.getElementById('vio-reset-out');
                function call(op, btn, confirmMsg){
                    if(confirmMsg && !window.confirm(confirmMsg)) return;
                    btn.disabled=true; out.textContent='Đang xử lý…';
                    var fd=new FormData();
                    fd.append('action','vig_imgopt_bulk');
                    fd.append('nonce','<?php echo esc_js($nonce); ?>');
                    fd.append('op',op);
                    fd.append('folder',(document.getElementById('vio-folder')||{}).value||'');
                    fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(j){
                        btn.disabled=false;
                        if(!j.success){ out.innerHTML='<span style="color:#b32d2e">Lỗi: '+((j.data&&j.data.message)||'?')+'</span>'; return; }
                        out.innerHTML='<span style="color:#007017">'+j.data.message+'</span> — <a href="">tải lại trang</a>';
                    }).catch(function(e){ btn.disabled=false; out.innerHTML='<span style="color:#b32d2e">Lỗi mạng: '+e+'</span>'; });
                }
                document.getElementById('vio-rescan').addEventListener('click',function(){ call('rescan',this,''); });
                document.getElementById('vio-reset').addEventListener('click',function(){
                    call('reset',this,'Xoá toàn bộ dấu "đã tối ưu"? Mọi ảnh sẽ được nén lại từ đầu.');
                });
            })();
            </script>
        </div>
        <?php
    }

    public static function saved_notice() {
        $t = get_transient('vig_imgopt_last');
        if (!$t || empty($t['n'])) return;
        delete_transient('vig_imgopt_last');
        echo '<div class="notice notice-success is-dismissible"><p><strong>VIG Image Optimizer:</strong> optimized '
            . (int) $t['n'] . ' image(s), saved ~' . esc_html(size_format($t['saved'], 1)) . '.</p></div>';
    }
}

add_action('plugins_loaded', ['VIG_Image_Optimizer', 'init']);

// Gỡ lịch nền khi tắt plugin.
register_deactivation_hook(__FILE__, function () {
    $ts = wp_next_scheduled('vig_imgopt_cron');
    if ($ts) wp_unschedule_event($ts, 'vig_imgopt_cron');
});
