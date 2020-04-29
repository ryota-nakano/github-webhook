<?php
$USER = '';# Github ユーザー名
$PASS = '';# Github パスワード
$SECRET = '';#Github Webhook Secret

/*===================================================

	# GithubのWebhookを使った自動デプロイ
		* webhookのURLをこのファイルにする
		* git clone / git pull を両方
		* webhook.logファイル生成

===================================================*/

$sig = $_SERVER['HTTP_X_HUB_SIGNATURE'];#sha1=xxx...
$hmac = 'sha1='.hash_hmac('sha1',file_get_contents('php://input'),$SECRET);#sha1=xxx...を生成
$date = date("Y-m-d H:i:s");# 日付
$adder = $_SERVER['REMOTE_ADDR'];# 訪問者のIPアドレス
$type = $_SERVER['CONTENT_TYPE'];# json または x-www-form-urlencoded
$dir = dirname(__FILE__);

# Githubからのアクセス
if(@hash_equals($hmac,$sig)){
	# 2タイプのPayloadに対応（json または x-www-form-urlencoded）
	$payload = (preg_match('/json/',$type))? file_get_contents('php://input'): $_POST['payload'];
	$payload = json_decode($payload,true);

	$repos = $payload['repository']['name'];# リポジトリ名
	$clone_url = str_replace('https://', "https://{$USER}:{$PASS}@", $payload['repository']['clone_url']);# クローンURL
	$ref = $payload['ref'];# ブランチ判定
	$log = "[{$date}][{$adder}][{$repos}]:";# logテキスト

	# 既にクローンされている場合
	if(is_dir($repos)){

		switch($ref){

			# マスターブランチ
			case 'refs/heads/master':
				exec("cd {$dir}/{$repos};git pull origin master");
				$log.= "{$ref}をプルしました。";

				# リポジトリによって処理を変更
				switch($repos){

					# FTPログインを試みる
					case 'リポジトリ１':
						$conn_id = ftp_ssl_connect('ホスト');
						$login = ftp_login($conn_id, 'ユーザー', 'パスワード');
						break;

					# ZIP化を試みる
					case 'リポジトリ２':
						$zip = "{$repos}.zip";# Zipする場所
						$log.= "Zipファイル化を試みます。";
						$log.= exec("cd {$dir};zip -r {$zip} {$repos} -x \*/.git/\*");
				}

				# FTPログイン成功したら
				if($login){
					ftp_pasv($conn_id, true);
					ftp_deletedir($conn_id, $repos);
					ftp_uploaddir($conn_id, "{$dir}/{$repos}", $repos);
					ftp_close($conn_id);
					$log.= "{$repos}をFTPでアプデしました。";
				}

				break;

			# それ以外のブランチ
			default: $log.= "{$ref}がプッシュされました。";
		}
	}
	# クローンされていない場合
	else{
		exec("git clone {$clone_url}");
		$log.= "{$repos}をクローンしました。";
	}
}
# Github以外からのアクセス
else{
	$log = "[{$date}][{$adder}]:不正アクセス。";
}
file_put_contents('github-webhook.log', "{$log}\n", FILE_APPEND|LOCK_EX);# ログファイル生成

/* FTPでディレクトリごと削除する
-------------------------------------------*/
function ftp_deletedir($conn_id, $remote_dir){
	$remote_dir = str_replace('//','/',"/{$remote_dir}/");
	if($files = ftp_nlist($conn_id, $remote_dir)){;# ディレクトリのリスト取得
		foreach((array)$files as $file){
			if(!preg_match('/(\.|\.\.)$/', $file)){# .と..は除外
				# ファイル削除を試みる
				if(!@ftp_delete($conn_id,$file)){
					# ディレクトリ削除失敗したら再帰する
					if(!@ftp_rmdir($conn_id,$file)) ftp_deletedir($conn_id,$file);
				}
			}
		}
		@ftp_rmdir($conn_id,$remote_dir);
	}
}

/* FTPでディレクトリごとアップロード
-------------------------------------------*/
function ftp_uploaddir($conn_id, $local_dir, $remote_dir){
	$local_dir = str_replace('//','/',"/{$local_dir}/");
	$remote_dir = str_replace('//','/',"/{$remote_dir}/");
	@ftp_mkdir($conn_id, $remote_dir);
	$handle = @opendir($local_dir);
	while(($file = @readdir($handle)) !== false){
		if(!preg_match('/(\.|\.\.|.git)$/', $file)){
			if(is_dir("{$local_dir}{$file}")) ftp_uploaddir($conn_id, "{$local_dir}{$file}/", "{$remote_dir}{$file}/");
			else $f[] = $file;
		}
	}
	closedir($handle);
	if(@count($f)){
		sort($f);
		@ftp_chdir($conn_id, $remote_dir);
		foreach ($f as $files){
			$from = @fopen("{$local_dir}{$files}", 'r');
			@ftp_fput($conn_id, $files, $from, FTP_BINARY);
		}
	}
}
