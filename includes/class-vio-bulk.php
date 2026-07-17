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

	const META = '_vig_imgopt_done';

	public static function register(): void {
		add_action( 'wp_ajax_vig_imgopt_bulk', array( __CLASS__, 'ajax' ) );
	}

	/* ------------------------------------------------ query */

	private static function query_args( int $limit ): array {
		return array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => array(
				array( 'key' => self::META, 'compare' => 'NOT EXISTS' ),
			),
		);
	}

	public static function count_pending(): int {
		$q = new WP_Query( self::query_args( 1 ) );
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

	public static function get_batch( int $limit ): array {
		$q = new WP_Query( self::query_args( $limit ) );
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
		update_post_meta( $id, self::META, array( 'at' => time(), 'saved' => $saved ) );
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

	/* ------------------------------------------------ AJAX */

	public static function ajax(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'vig_imgopt_bulk', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Không đủ quyền.' ), 403 );
		}
		@set_time_limit( 0 );
		$resize = ! empty( $_POST['resize'] );
		$backup = ! empty( $_POST['backup'] );
		$batch  = max( 1, min( 20, (int) ( $_POST['batch'] ?? 5 ) ) );

		$ids       = self::get_batch( $batch );
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
			'remaining'   => self::count_pending(),
			'done'        => self::count_done(),
			'total_saved' => self::total_saved(),
		) );
	}
}

/* ------------------------------------------------ WP-CLI */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	class VIO_Bulk_CLI {
		/**
		 * Tối ưu ảnh cũ trong thư viện (nén giữ đuôi).
		 *
		 * [--resize]  : resize bản gốc quá khổ về max-width + regenerate thumbnail
		 * [--backup]  : giữ backup bản gốc (uploads/vig-imgopt-backup)
		 * [--batch=<n>] : số ảnh mỗi lô (mặc định 10)
		 */
		public function bulk( $args, $assoc ) {
			$resize = isset( $assoc['resize'] );
			$backup = isset( $assoc['backup'] );
			$batch  = max( 1, (int) ( $assoc['batch'] ?? 10 ) );
			$total  = VIO_Bulk::count_pending();
			if ( 0 === $total ) {
				\WP_CLI::success( 'Không có ảnh nào cần tối ưu.' );
				return;
			}
			\WP_CLI::log( "Cần tối ưu: {$total} ảnh." );
			$bar   = \WP_CLI\Utils\make_progress_bar( 'Đang tối ưu', $total );
			$saved = 0;
			while ( $ids = VIO_Bulk::get_batch( $batch ) ) {
				foreach ( $ids as $id ) {
					$r      = VIO_Bulk::optimize_attachment( $id, $resize, $backup );
					$saved += (int) ( $r['saved'] ?? 0 );
					$bar->tick();
				}
			}
			$bar->finish();
			\WP_CLI::success( 'Xong. Tiết kiệm phiên này: ' . size_format( $saved ) . '. Tổng đã tối ưu: ' . VIO_Bulk::count_done() . ' ảnh.' );
		}
	}
}
