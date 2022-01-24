<?php
/**
 * Webhook
 *
 * GithubのWebhookを使った自動デプロイ
 * git clone / git pull を両方
 * webhook.logファイル生成
 * デプロイするディレクトリもどこからか取る
 */
class GithubWebhook {

	/**
	 * デプロイ先パス
	 *
	 * @var String
	 */
	private $path;

	/**
	 * Githubから送信されるデータ
	 *
	 * @var Object
	 */
	private $payload;

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		ini_set( 'date.timezone', 'Asia/Tokyo' );
		ini_set( 'error_log', dirname( __DIR__ ) . '/webhook.log' );
		$this->auth();
		$this->set_deploy_path();
		$this->set_payload();
		$this->deploy();
	}


	/**
	 * シークレット認証
	 */
	private function auth() {

		// $SECRET
		extract( parse_ini_file( 'setting.ini' ) );

		// 認証用 hash
		$signature = $_SERVER['HTTP_X_HUB_SIGNATURE'];// sha1=xxx...
		$hash_hmac = 'sha1=' . hash_hmac( 'sha1', file_get_contents( 'php://input' ), $SECRET );// sha1=xxx...を生成

		// ＄SECRETで認証出来なかったら終了
		if ( ! @hash_equals( $hash_hmac, $signature ) ) {
			error_log( '不正アクセス' );
			exit;
		}
	}

	/**
	 * GETパラメーターからデプロイパスを設定
	 */
	private function set_deploy_path() {

		// 指定なしは、このディレクトリ
		$this->path = isset( $_GET['path'] ) ? $_GET['path'] : '';

		// 絶対パス以外は、フルパスに変換
		$this->path = ( ! preg_match( '/^\//', $this->path ) )
			? dirname( __DIR__ ) . "/{$this->path}"
			: $this->path;

		// 末尾のスラッシュ除去
		$this->path = rtrim( $this->path, '/' );
	}

	/**
	 * payload設定
	 */
	private function set_payload() {

		// Githubの2タイプのPayloadに対応（json または x-www-form-urlencoded）
		$this->payload = preg_match( '/json/', $_SERVER['CONTENT_TYPE'] )
			? file_get_contents( 'php://input' )
			: $_POST['payload'];
		$this->payload = json_decode( $this->payload, true );
	}

	/**
	 * デプロイ
	 */
	private function deploy() {
		$path       = $this->path;
		$repository = $this->payload['repository']['name'];// リポジトリ名
		$clone_url  = $this->payload['repository']['clone_url'];// クローンURL
		$ref        = $this->payload['ref'];// ブランチ判定用

		// クローンされて無い場合
		if ( ! is_dir( "{$path}/{$repository}" ) ) {
			$command = "cd {$path} && git clone {$clone_url}";
			exec( $command );
			error_log( $command );
			exit;
		}

		switch ( $ref ) {

			// マスターブランチ
			case 'refs/heads/main':
			case 'refs/heads/master':
				$branch  = end( explode( '/', $ref ) );
				$command = "cd {$path}/{$repository} && git pull";
				exec( $command, $output, $retval );
				error_log( $command . json_encode( $output ) . $retval );
				break;

			// それ以外のブランチ
			default:
				break;
		}

	}

	/**
	 * package.jsonの変更を監視してnpm i
	 * !! 要らん疑惑
	 */
	private function npm_i() {
		// package.jsonの存在を確認し、そのディレクトリを特定

		$flg = false;
		foreach ( $this->payload['commits'] as $commit ) {
			// package.jsonの変更を監視
			$flg = array_search( 'package.json', $commit['added'] + $commit['modified'] );
			if ( is_int( $flg ) ) {
				error_log( 'search' );
				$repository = $this->payload['repository']['name'];
				$command    = 'export PATH=$HOME/.nodebrew/current/bin:$PATH &&';
				$command   .= "cd {$this->path}/{$repository} && npm i";
				exec( $command );
				error_log( $command );
				break;
			}
		}
	}

	/**
	 * FTPでディレクトリごと削除
	 *
	 * @param String $conn_id
	 * @param String $remote_dir
	 */
	private function ftp_deletedir( $conn_id, $remote_dir ) {
		$remote_dir = str_replace( '//', '/', "/{$remote_dir}/" );
		if ( $files = ftp_nlist( $conn_id, $remote_dir ) ) {
			;// ディレクトリのリスト取得
			foreach ( (array) $files as $file ) {
				if ( ! preg_match( '/(\.|\.\.)$/', $file ) ) {// .と..は除外
					// ファイル削除を試みる
					if ( ! @ftp_delete( $conn_id, $file ) ) {
						// ディレクトリ削除失敗したら再帰する
						if ( ! @ftp_rmdir( $conn_id, $file ) ) {
							$this->ftp_deletedir( $conn_id, $file );
						}
					}
				}
			}
			@ftp_rmdir( $conn_id, $remote_dir );
		}
	}

	/**
	 * FTPでディレクトリごとアップロード
	 *
	 * @param String $conn_id
	 * @param String $local_dir
	 * @param String $remote_dir
	 */
	private function ftp_uploaddir( $conn_id, $local_dir, $remote_dir ) {
		$local_dir  = str_replace( '//', '/', "/{$local_dir}/" );
		$remote_dir = str_replace( '//', '/', "/{$remote_dir}/" );
		@ftp_mkdir( $conn_id, $remote_dir );
		$handle = @opendir( $local_dir );
		while ( ( $file = @readdir( $handle ) ) !== false ) {
			if ( ! preg_match( '/(\.|\.\.|.git)$/', $file ) ) {
				if ( is_dir( "{$local_dir}{$file}" ) ) {
					$this->ftp_uploaddir( $conn_id, "{$local_dir}{$file}/", "{$remote_dir}{$file}/" );
				} else {
					$f[] = $file;
				}
			}
		}
		closedir( $handle );
		if ( @count( $f ) ) {
			sort( $f );
			@ftp_chdir( $conn_id, $remote_dir );
			foreach ( $f as $files ) {
				$from = @fopen( "{$local_dir}{$files}", 'r' );
				@ftp_fput( $conn_id, $files, $from, FTP_BINARY );
			}
		}
	}

}
