<?php
/**
 * VIO_Bulk — tối ưu ảnh CŨ (đã có trong thư viện) theo lô.
 * Nén tại chỗ, GIỮ NGUYÊN đuôi + tên file → không phá tham chiếu trong bài viết.
 * Xử lý bản gốc + tất cả thumbnail. Đánh dấu _vig_imgopt_done để chạy lại bỏ qua.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VIO_Bulk {

	const META     = '_vig_imgopt_done';
	const CRON     = 'vig_imgopt_cron';
	const LOG      = 'vig_imgopt_cron_log';

	public static function register(): void {
		add_action( 'wp_ajax_vig_imgopt_bulk', array( __CLASS__, 'ajax' ) );
		add_action( self::CRON, array( __CLASS__, 'cron_run' ) );
		add_action( 'init', array( __CLASS__, 'reconcile_cron' ) );
	}

	/* ------------------------------------------------ query (CŨ → MỚI theo ngày = theo thư mục YYYY/MM) */

	private static function query_args( int $limit, string $folder = '' ): array {
		$meta = array( array( 'key' => self::META, 'compare' => 'NOT EXISTS' ) );

		// Lọc theo thư mục media (vd "2025/03"): _wp_attached_file bắt đầu bằng "2025/03/".
		$folder = self::clean_folder( $folder );
		if ( '' !== $folder ) {
			$meta[] = array(
				'key'     => '_wp_attached_file',
				'value'   => '^' . preg_quote( $folder, '/' ) . '/',
				'compare' => 'REGEXP',
			);
		}

		return array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'ASC',   // cũ nhất trước → xử lý dần theo thư mục năm/tháng
			'no_found_rows'  => false,
			'meta_query'     => $meta,
		);
	}

	/** Chỉ nhận dạng YYYY/MM — chặn ký tự lạ / path traversal. */
	public static function clean_folder( string $f ): string {
		$f = trim( str_replace( '\\', '/', $f ), '/ ' );
		return preg_match( '#^[0-9]{4}/[0-9]{2}$#', $f ) ? $f : '';
	}

	/**
	 * Danh sách thư mục năm/tháng trong Media + số ảnh tổng / chưa tối ưu.
	 * @return array [ '2025/03' => ['total'=>int,'pending'=>int], ... ] (cũ → mới)
	 */
	public static function folders(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT SUBSTRING_INDEX(pm.meta_value,'/',2) AS folder,
			        COUNT(*) AS total,
			        SUM(CASE WHEN dm.post_id IS NULL THEN 1 ELSE 0 END) AS pending
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p
			           ON p.ID = pm.post_id
			          AND p.post_type = 'attachment'
			          AND p.post_mime_type IN ('image/jpeg','image/png','image/webp')
			   LEFT JOIN {$wpdb->postmeta} dm
			          ON dm.post_id = pm.post_id AND dm.meta_key = %s
			  WHERE pm.meta_key = '_wp_attached_file'
			    AND pm.meta_value REGEXP '^[0-9]{4}/[0-9]{2}/'
			  GROUP BY folder
			  ORDER BY folder ASC",
			self::META
		) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->folder ] = array( 'total' => (int) $r->total, 'pending' => (int) $r->pending );
		}
		return $out;
	}

	/** Tối ưu đúng 1 ảnh theo ID (để chạy thử). $force = bỏ dấu đã-tối-ưu để chạy lại. */
	public static function optimize_one( int $id, bool $resize = false, bool $backup = false, bool $force = false ): array {
		if ( 'attachment' !== get_post_type( $id ) ) {
			return array( 'error' => 'ID không phải file media.' );
		}
		if ( $force ) {
			delete_post_meta( $id, self::META );
		}
		$file   = get_attached_file( $id );
		$before = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
		$r      = self::optimize_attachment( $id, $resize, $backup );
		$r['file']   = $file ? basename( $file ) : '';
		$r['before'] = $before;
		$r['after']  = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
		return $r;
	}

	/** Thư mục YYYY/MM của ảnh cũ nhất chưa tối ưu (đang tới lượt). */
	public static function current_folder(): string {
		$ids = self::get_batch( 1 );
		if ( empty( $ids ) ) {
			return '';
		}
		$rel = (string) get_post_meta( $ids[0], '_wp_attached_file', true );
		$dir = dirname( $rel );
		return ( $dir && '.' !== $dir ) ? $dir : '(thư mục gốc)';
	}

	public static function count_pending( string $folder = '' ): int {
		$q = new WP_Query( self::query_args( 1, $folder ) );
		return (int) $q->found_posts;
	}

	public static function count_done(): int {
		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::META, 'compare' => 'EXISTS' ) ),
		) );
		return (int) $q->found_posts;
	}

	public static function total_saved(): int {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s", self::META ) );
		$sum  = 0;
		foreach ( (array) $rows as $r ) {
			$d = maybe_unserialize( $r );
			$sum += is_array( $d ) ? (int) ( $d['saved'] ?? 0 ) : 0;
		}
		return $sum;
	}

	public static function get_batch( int $limit, string $folder = '' ): array {
		$q = new WP_Query( self::query_args( $limit, $folder ) );
		return array_map( 'intval', $q->posts );
	}

	/* ------------------------------------------------ core */

	/**
	 * Tối ưu 1 attachment (bản gốc + thumbnail), giữ đuôi.
	 * @return array{ok?:bool,skipped?:bool,error?:string,saved?:int,files?:int}
	 */
	public static function optimize_attachment( int $id, bool $resize = false, bool $backup = false ): array {
		if ( get_post_meta( $id, self::META, true ) ) {
			return array( 'skipped' => true );
		}
		$file = get_attached_file( $id );
		$type = get_post_mime_type( $id );
		if ( ! $file || ! file_exists( $file ) || ! in_array( $type, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			update_post_meta( $id, self::META, array( 'at' => time(), 'saved' => 0, 'skip' => 'not-image-or-missing' ) );
			return array( 'skipped' => true );
		}

		$dir   = dirname( $file );
		$meta  = wp_get_attachment_metadata( $id );
		$files = self::collect_files( $file, $type, $dir, $meta );

		if ( $backup ) {
			self::backup( array_keys( $files ) );
		}

		$before = self::sum_size( array_keys( $files ) );

		// Tầng 2 (opt-in): resize bản gốc quá khổ → regenerate thumbnail.
		if ( $resize ) {
			$editor = wp_get_image_editor( $file );
			if ( ! is_wp_error( $editor ) ) {
				$sz  = $editor->get_size();
				$max = (int) VIG_Image_Optimizer::opts()['max_width'];
				if ( ! empty( $sz['width'] ) && $sz['width'] > $max ) {
					$editor->resize( $max, PHP_INT_MAX, false );
					$editor->set_quality( (int) VIG_Image_Optimizer::opts()['jpeg_quality'] );
					$editor->save( $file, $type ); // ghi đè bản gốc, GIỮ tên
					require_once ABSPATH . 'wp-admin/includes/image.php';
					$newmeta = wp_generate_attachment_metadata( $id, $file );
					if ( is_array( $newmeta ) ) {
						wp_update_attachment_metadata( $id, $newmeta );
						$meta  = $newmeta;
						$files = self::collect_files( $file, $type, $dir, $meta );
					}
				}
			}
		}

		// Nén từng file tại chỗ, GIỮ đuôi + GIỮ kích thước (resize đã xử lý riêng ở tầng 2 → metadata luôn khớp).
		foreach ( $files as $p => $t ) {
			VIG_Image_Optimizer::optimize( $p, $t ?: ( wp_check_filetype( $p )['type'] ?: 'image/jpeg' ), true, false );
		}

		$after = self::sum_size( array_keys( $files ) );
		$saved = max( 0, $before - $after );
		// 'size' = dung lượng file gốc sau khi tối ưu → dùng để phát hiện ảnh bị thay/restore về sau.
		update_post_meta( $id, self::META, array(
			'at'    => time(),
			'saved' => $saved,
			'size'  => (int) @filesize( $file ),
		) );
		return array( 'ok' => true, 'saved' => $saved, 'files' => count( $files ) );
	}

	private static function collect_files( string $file, string $type, string $dir, $meta ): array {
		$files = array( $file => $type );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $s ) {
				if ( empty( $s['file'] ) ) {
					continue;
				}
				$p = $dir . '/' . $s['file'];
				if ( file_exists( $p ) && ! isset( $files[ $p ] ) ) {
					$files[ $p ] = $s['mime-type'] ?? '';
				}
			}
		}
		return $files;
	}

	private static function sum_size( array $paths ): int {
		$n = 0;
		foreach ( $paths as $p ) {
			$n += (int) @filesize( $p );
		}
		return $n;
	}

	private static function backup( array $paths ): void {
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['basedir'] );
		$bdir = $base . 'vig-imgopt-backup/';
		foreach ( $paths as $p ) {
			if ( 0 !== strpos( $p, $base ) ) {
				continue;
			}
			$rel = substr( $p, strlen( $base ) );
			$dst = $bdir . $rel;
			if ( file_exists( $dst ) ) {
				continue; // đã backup lần trước
			}
			wp_mkdir_p( dirname( $dst ) );
			@copy( $p, $dst );
		}
		if ( ! file_exists( $bdir . '.htaccess' ) ) {
			@file_put_contents( $bdir . '.htaccess', "Require all denied\nDeny from all\n" );
		}
	}

	/* ------------------------------------------------ CRON (tối ưu nền theo lịch) */

	/** Đặt/gỡ lịch theo cài đặt. Gọi ở init (idempotent) + khi lưu settings. */
	public static function reconcile_cron(): void {
		$o        = VIG_Image_Optimizer::opts();
		$enabled  = ! empty( $o['bulk_cron'] );
		$interval = in_array( $o['bulk_cron_interval'] ?? 'hourly', array( 'hourly', 'twicedaily', 'daily' ), true ) ? $o['bulk_cron_interval'] : 'hourly';
		$ts       = wp_next_scheduled( self::CRON );
		$cur      = get_option( 'vig_imgopt_cron_interval' );

		if ( $enabled ) {
			if ( ! $ts || $cur !== $interval ) {
				if ( $ts ) {
					wp_unschedule_event( $ts, self::CRON );
				}
				wp_schedule_event( time() + 300, $interval, self::CRON );
				update_option( 'vig_imgopt_cron_interval', $interval, false );
			}
		} elseif ( $ts ) {
			wp_unschedule_event( $ts, self::CRON );
			delete_option( 'vig_imgopt_cron_interval' );
		}
	}

	/** Chạy 1 lô mỗi lần cron fire (cũ → mới). */
	public static function cron_run(): void {
		$o = VIG_Image_Optimizer::opts();
		if ( empty( $o['bulk_cron'] ) ) {
			return;
		}
		@set_time_limit( 0 );
		$batch  = max( 1, (int) ( $o['bulk_cron_batch'] ?? 20 ) );
		$resize = ! empty( $o['bulk_cron_resize'] );
		$backup = ! empty( $o['bulk_cron_backup'] );
		$folder = self::current_folder();

		$ids   = self::get_batch( $batch );
		$saved = 0;
		$n     = 0;
		foreach ( $ids as $id ) {
			$r      = self::optimize_attachment( $id, $resize, $backup );
			$saved += (int) ( $r['saved'] ?? 0 );
			++$n;
		}
		update_option( self::LOG, array(
			'at'        => time(),
			'folder'    => $folder,
			'processed' => $n,
			'saved'     => $saved,
			'remaining' => self::count_pending(),
		), false );
	}

	public static function cron_status(): array {
		return array(
			'enabled'   => (bool) ( VIG_Image_Optimizer::opts()['bulk_cron'] ?? false ),
			'next'      => wp_next_scheduled( self::CRON ),
			'folder'    => self::current_folder(),
			'remaining' => self::count_pending(),
			'log'       => get_option( self::LOG, array() ),
		);
	}

	/* ------------------------------------------------ RESET / QUÉT LẠI (sau khi restore media) */

	/** Attachment ảnh đã được đánh dấu tối ưu (tuỳ chọn lọc theo thư mục). */
	private static function marked_ids( string $folder = '' ): array {
		$meta = array( array( 'key' => self::META, 'compare' => 'EXISTS' ) );
		$folder = self::clean_folder( $folder );
		if ( '' !== $folder ) {
			$meta[] = array(
				'key'     => '_wp_attached_file',
				'value'   => '^' . preg_quote( $folder, '/' ) . '/',
				'compare' => 'REGEXP',
			);
		}
		return array_map( 'intval', get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => $meta,
		) ) );
	}

	/**
	 * XOÁ dấu "đã tối ưu" → ảnh sẽ được xử lý lại từ đầu.
	 * Dùng sau khi RESTORE media: file đã về bản gốc nhưng dấu vẫn còn trong postmeta.
	 * @return int số ảnh được đặt lại
	 */
	public static function reset_marks( string $folder = '' ): int {
		$ids = self::marked_ids( $folder );
		foreach ( $ids as $id ) {
			delete_post_meta( $id, self::META );
		}
		return count( $ids );
	}

	/**
	 * Quét lại: so dung lượng file hiện tại với dung lượng đã ghi lúc tối ưu.
	 * Khác nhau ⇒ file đã bị thay (restore / upload đè / regenerate) ⇒ bỏ dấu để tối ưu lại.
	 * @return array{cleared:int,unchanged:int,unknown:int,missing:int}
	 */
	public static function rescan( string $folder = '' ): array {
		$r = array( 'cleared' => 0, 'unchanged' => 0, 'unknown' => 0, 'missing' => 0 );
		foreach ( self::marked_ids( $folder ) as $id ) {
			$mark = get_post_meta( $id, self::META, true );
			$file = get_attached_file( $id );

			if ( ! $file || ! file_exists( $file ) ) {
				$r['missing']++;
				continue;
			}
			// Dấu cũ (trước v1.9.0) không lưu 'size' → không so được, phải dùng "Đặt lại" thủ công.
			if ( ! is_array( $mark ) || ! isset( $mark['size'] ) ) {
				$r['unknown']++;
				continue;
			}
			if ( (int) $mark['size'] !== (int) filesize( $file ) ) {
				delete_post_meta( $id, self::META );
				$r['cleared']++;
			} else {
				$r['unchanged']++;
			}
		}
		return $r;
	}

	/* ------------------------------------------------ TỰ KIỂM TRA (an toàn: chỉ chạy trên BẢN SAO) */

	/**
	 * Chạy thử tối ưu trên bản sao của vài ảnh thật rồi đo mức lệch màu.
	 * KHÔNG bao giờ đụng vào ảnh gốc. Dùng để kiểm tra TRƯỚC khi chạy hàng loạt.
	 * @return array danh sách ['file','before','after','saved_pct','max_diff','ok']
	 */
	public static function selftest( int $n = 5 ): array {
		$ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'posts_per_page' => max( 1, $n ),
			'orderby'        => 'rand',
			'fields'         => 'ids',
		) );

		$rows = array();
		foreach ( $ids as $id ) {
			$src = get_attached_file( $id );
			if ( ! $src || ! file_exists( $src ) ) {
				continue;
			}
			$type = (string) get_post_mime_type( $id );
			$tmp  = $src . '-viotest.' . ( 'image/png' === $type ? 'png' : 'jpg' );
			if ( ! @copy( $src, $tmp ) ) {
				continue;
			}

			$before_px = self::sample_pixels( $tmp );
			$before_sz = (int) @filesize( $tmp );

			VIG_Image_Optimizer::optimize( $tmp, $type, true, false );   // giữ đuôi, không resize

			$after_px = self::sample_pixels( $tmp );
			$after_sz = (int) @filesize( $tmp );
			@unlink( $tmp );

			$max = 0;
			foreach ( $before_px as $i => $b ) {
				if ( ! isset( $after_px[ $i ] ) ) {
					continue;
				}
				for ( $c = 0; $c < 3; $c++ ) {
					$max = max( $max, abs( $b[ $c ] - $after_px[ $i ][ $c ] ) );
				}
			}
			$rows[] = array(
				'file'      => basename( $src ),
				'before'    => $before_sz,
				'after'     => $after_sz,
				'saved_pct' => $before_sz ? round( ( 1 - $after_sz / $before_sz ) * 100 ) : 0,
				'max_diff'  => $max,
				'ok'        => $max <= 12,   // ~5% — mắt thường không phân biệt được
			);
		}
		return $rows;
	}

	/** Lấy mẫu pixel theo lưới. Ép truecolor để đọc đúng cả ảnh palette. */
	private static function sample_pixels( string $path ): array {
		$im = @imagecreatefromstring( (string) @file_get_contents( $path ) );
		if ( ! $im ) {
			return array();
		}
		if ( function_exists( 'imagepalettetotruecolor' ) ) {
			@imagepalettetotruecolor( $im );   // ảnh palette: imagecolorat trả CHỈ SỐ, không phải RGB
		}
		$w   = imagesx( $im );
		$h   = imagesy( $im );
		$out = array();
		for ( $x = 1; $x < 8; $x++ ) {
			for ( $y = 1; $y < 8; $y++ ) {
				$c     = imagecolorat( $im, (int) ( $w * $x / 8 ), (int) ( $h * $y / 8 ) );
				$out[] = array( ( $c >> 16 ) & 255, ( $c >> 8 ) & 255, $c & 255 );
			}
		}
		imagedestroy( $im );
		return $out;
	}

	/* ------------------------------------------------ AJAX */

	public static function ajax(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'vig_imgopt_bulk', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Không đủ quyền.' ), 403 );
		}
		@set_time_limit( 0 );

		// Đặt lại / quét lại sau khi restore media
		$op = sanitize_key( (string) ( $_POST['op'] ?? '' ) );
		if ( 'reset' === $op || 'rescan' === $op ) {
			$fo_raw = (string) ( $_POST['folder'] ?? '' );
			$fo     = self::clean_folder( $fo_raw );
			if ( '' !== $fo_raw && '' === $fo ) {
				wp_send_json_error( array( 'message' => 'Thư mục không hợp lệ (phải dạng YYYY/MM).' ), 400 );
			}
			if ( 'reset' === $op ) {
				$n = self::reset_marks( $fo );
				wp_send_json_success( array(
					'op'      => 'reset',
					'message' => sprintf( 'Đã đặt lại %d ảnh%s — sẽ được tối ưu lại từ đầu.', $n, $fo ? " trong {$fo}" : '' ),
					'pending' => self::count_pending(),
				) );
			}
			$r = self::rescan( $fo );
			wp_send_json_success( array(
				'op'      => 'rescan',
				'message' => sprintf(
					'Quét xong: %d ảnh đã bị thay file → đặt lại để tối ưu lại · %d còn nguyên · %d không rõ (dấu cũ) · %d mất file.',
					$r['cleared'], $r['unchanged'], $r['unknown'], $r['missing']
				),
				'cleared' => $r['cleared'],
				'unknown' => $r['unknown'],
				'pending' => self::count_pending(),
			) );
		}

		$resize = ! empty( $_POST['resize'] );
		$backup = ! empty( $_POST['backup'] );
		$batch  = max( 1, min( 20, (int) ( $_POST['batch'] ?? 5 ) ) );
		$folder_raw = (string) ( $_POST['folder'] ?? '' );
		$folder     = self::clean_folder( $folder_raw );
		// Không hợp lệ mà lại im lặng chạy TOÀN BỘ thư viện thì rất nguy hiểm → báo lỗi.
		if ( '' !== $folder_raw && '' === $folder ) {
			wp_send_json_error( array( 'message' => 'Thư mục không hợp lệ (phải dạng YYYY/MM).' ), 400 );
		}

		// Chạy thử đúng 1 ảnh theo ID
		$one = (int) ( $_POST['id'] ?? 0 );
		if ( $one > 0 ) {
			$r = self::optimize_one( $one, $resize, $backup, ! empty( $_POST['force'] ) );
			if ( ! empty( $r['error'] ) ) {
				wp_send_json_error( array( 'message' => $r['error'] ), 400 );
			}
			wp_send_json_success( array(
				'single'      => true,
				'file'        => $r['file'],
				'before'      => (int) ( $r['before'] ?? 0 ),
				'after'       => (int) ( $r['after'] ?? 0 ),
				'skipped'     => ! empty( $r['skipped'] ),
				'processed'   => 1,
				'remaining'   => self::count_pending( $folder ),
				'done'        => self::count_done(),
				'total_saved' => self::total_saved(),
			) );
		}

		$ids       = self::get_batch( $batch, $folder );
		$processed = 0;
		$saved     = 0;
		foreach ( $ids as $id ) {
			$r = self::optimize_attachment( $id, $resize, $backup );
			++$processed;
			$saved += (int) ( $r['saved'] ?? 0 );
		}
		wp_send_json_success( array(
			'processed'   => $processed,
			'batch_saved' => $saved,
			'remaining'   => self::count_pending( $folder ),
			'done'        => self::count_done(),
			'total_saved' => self::total_saved(),
		) );
	}
}

/* ------------------------------------------------ WP-CLI */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	class VIO_Bulk_CLI {
		/**
		 * Chạy thử trên BẢN SAO của vài ảnh thật + đo lệch màu. Không đụng ảnh gốc.
		 * Nên chạy TRƯỚC khi tối ưu hàng loạt.
		 *
		 * [--n=<số>] : số ảnh lấy mẫu (mặc định 5)
		 */
		public function selftest( $args, $assoc ) {
			$rows = VIO_Bulk::selftest( max( 1, (int) ( $assoc['n'] ?? 5 ) ) );
			if ( ! $rows ) {
				\WP_CLI::warning( 'Không có ảnh nào để kiểm tra.' );
				return;
			}
			$bad = 0;
			foreach ( $rows as $r ) {
				$r['ok'] || $bad++;
				\WP_CLI::log( sprintf(
					'%s  %s → %s (-%d%%)  lệch màu tối đa %d/255  %s',
					str_pad( substr( $r['file'], 0, 34 ), 35 ),
					size_format( $r['before'] ), size_format( $r['after'] ),
					$r['saved_pct'], $r['max_diff'], $r['ok'] ? '✅' : '⚠️  BẤT THƯỜNG'
				) );
			}
			if ( $bad ) {
				\WP_CLI::error( "$bad ảnh lệch màu bất thường — ĐỪNG chạy hàng loạt, báo lại VIG." );
			}
			\WP_CLI::success( 'An toàn: màu sắc được giữ nguyên. Có thể chạy `wp vig-imgopt bulk`.' );
		}

		/**
		 * Tối ưu ảnh cũ trong thư viện (nén giữ đuôi).
		 *
		 * [--resize]  : resize bản gốc quá khổ về max-width + regenerate thumbnail
		 * [--backup]  : giữ backup bản gốc (uploads/vig-imgopt-backup)
		 * [--batch=<n>] : số ảnh mỗi lô (mặc định 10)
		 * [--folder=<YYYY/MM>] : CHỈ tối ưu 1 thư mục media (vd --folder=2025/03)
		 * [--id=<n>]  : CHỈ tối ưu đúng 1 ảnh theo ID (để chạy thử)
		 * [--force]   : chạy lại cả ảnh đã tối ưu (chỉ dùng với --id)
		 */
		public function bulk( $args, $assoc ) {
			$resize = isset( $assoc['resize'] );
			$backup = isset( $assoc['backup'] );
			$batch  = max( 1, (int) ( $assoc['batch'] ?? 10 ) );

			// --- chạy thử đúng 1 ảnh ---
			$one = (int) ( $assoc['id'] ?? 0 );
			if ( $one > 0 ) {
				$r = VIO_Bulk::optimize_one( $one, $resize, $backup, isset( $assoc['force'] ) );
				if ( ! empty( $r['error'] ) ) {
					\WP_CLI::error( $r['error'] );
				}
				if ( ! empty( $r['skipped'] ) ) {
					\WP_CLI::warning( 'Ảnh này đã tối ưu rồi (dùng --force để chạy lại).' );
					return;
				}
				\WP_CLI::success( sprintf(
					'%s: %s → %s (-%d%%), %d file (gốc + thumbnail).',
					$r['file'], size_format( $r['before'] ), size_format( $r['after'] ),
					$r['before'] ? round( ( 1 - $r['after'] / $r['before'] ) * 100 ) : 0,
					(int) ( $r['files'] ?? 1 )
				) );
				return;
			}

			// --- lọc theo thư mục ---
			$folder = VIO_Bulk::clean_folder( (string) ( $assoc['folder'] ?? '' ) );
			if ( ! empty( $assoc['folder'] ) && '' === $folder ) {
				\WP_CLI::error( 'Thư mục phải có dạng YYYY/MM, ví dụ --folder=2025/03' );
			}
			$scope = $folder ? "thư mục {$folder}" : 'toàn bộ thư viện';

			$total = VIO_Bulk::count_pending( $folder );
			if ( 0 === $total ) {
				\WP_CLI::success( "Không có ảnh nào cần tối ưu trong {$scope}." );
				return;
			}
			\WP_CLI::log( "Cần tối ưu ({$scope}): {$total} ảnh." );
			$bar   = \WP_CLI\Utils\make_progress_bar( 'Đang tối ưu', $total );
			$saved = 0;
			while ( $ids = VIO_Bulk::get_batch( $batch, $folder ) ) {
				foreach ( $ids as $id ) {
					$r      = VIO_Bulk::optimize_attachment( $id, $resize, $backup );
					$saved += (int) ( $r['saved'] ?? 0 );
					$bar->tick();
				}
			}
			$bar->finish();
			\WP_CLI::success( 'Xong. Tiết kiệm phiên này: ' . size_format( $saved ) . '. Tổng đã tối ưu: ' . VIO_Bulk::count_done() . ' ảnh.' );
		}

		/**
		 * XOÁ dấu "đã tối ưu" để plugin xử lý lại từ đầu.
		 * Dùng SAU KHI RESTORE media — file đã về bản gốc nhưng dấu vẫn nằm trong postmeta.
		 *
		 * [--folder=<YYYY/MM>] : chỉ đặt lại 1 thư mục
		 * [--yes] : không hỏi xác nhận
		 */
		public function reset( $args, $assoc ) {
			$folder = VIO_Bulk::clean_folder( (string) ( $assoc['folder'] ?? '' ) );
			if ( ! empty( $assoc['folder'] ) && '' === $folder ) {
				\WP_CLI::error( 'Thư mục phải có dạng YYYY/MM.' );
			}
			$scope = $folder ? "thư mục {$folder}" : 'TOÀN BỘ thư viện';
			\WP_CLI::confirm( "Đặt lại dấu 'đã tối ưu' cho {$scope}?", $assoc );
			$n = VIO_Bulk::reset_marks( $folder );
			\WP_CLI::success( "Đã đặt lại {$n} ảnh. Chạy `wp vig-imgopt bulk` để tối ưu lại." );
		}

		/**
		 * Quét lại: ảnh nào có file khác với lúc tối ưu (đã restore/thay) thì bỏ dấu.
		 * An toàn hơn `reset` vì chỉ đụng ảnh thực sự đã đổi.
		 *
		 * [--folder=<YYYY/MM>] : chỉ quét 1 thư mục
		 */
		public function rescan( $args, $assoc ) {
			$folder = VIO_Bulk::clean_folder( (string) ( $assoc['folder'] ?? '' ) );
			if ( ! empty( $assoc['folder'] ) && '' === $folder ) {
				\WP_CLI::error( 'Thư mục phải có dạng YYYY/MM.' );
			}
			$r = VIO_Bulk::rescan( $folder );
			\WP_CLI::log( "Đã bị thay file (đặt lại): {$r['cleared']}" );
			\WP_CLI::log( "Còn nguyên:               {$r['unchanged']}" );
			\WP_CLI::log( "Không rõ (dấu cũ):        {$r['unknown']}" );
			\WP_CLI::log( "Mất file:                 {$r['missing']}" );
			if ( $r['unknown'] ) {
				\WP_CLI::warning( "{$r['unknown']} ảnh mang dấu từ bản cũ (không lưu dung lượng) nên không so được — dùng `wp vig-imgopt reset` nếu vừa restore." );
			}
			\WP_CLI::success( 'Xong. Còn ' . VIO_Bulk::count_pending() . ' ảnh cần tối ưu.' );
		}

		/**
		 * Liệt kê thư mục năm/tháng trong Media + số ảnh chưa tối ưu.
		 */
		public function folders( $args, $assoc ) {
			$rows = VIO_Bulk::folders();
			if ( ! $rows ) {
				\WP_CLI::warning( 'Không tìm thấy thư mục dạng YYYY/MM nào.' );
				return;
			}
			$out = array();
			foreach ( $rows as $f => $d ) {
				$out[] = array( 'folder' => $f, 'tong_anh' => $d['total'], 'chua_toi_uu' => $d['pending'] );
			}
			\WP_CLI\Utils\format_items( 'table', $out, array( 'folder', 'tong_anh', 'chua_toi_uu' ) );
		}
	}
}
