<?php

$USER = '';# Github ユーザー名
$PASS = '';# Github パスワード
$SECRET = '';#Github Webhook Secret

/*===================================================

	# GithubのWebhookを使った自動デプロイ（distの中のみ）
		* webhookのURLをこのファイルにする
		* git clone / git pull を両方
		* webhook.logファイル生成

===================================================*/

$sig = $_SERVER['HTTP_X_HUB_SIGNATURE'];#sha1=xxx...
$hmac = 'sha1='.hash_hmac('sha1',file_get_contents('php://input'),$SECRET);#sha1=xxx...を生成
$date = date("Y-m-d H:i:s");# 日付
$adder = $_SERVER['REMOTE_ADDR'];# 訪問者のIPアドレス
$type = $_SERVER['CONTENT_TYPE'];# json または x-www-form-urlencoded
$dir = __DIR__;
$gclone = dirname(__DIR__).'/.github-clone';# git clone保存用非公開ディレクトリ

# Githubからのアクセス
if(@hash_equals($hmac,$sig)){
	# 2タイプのPayloadに対応（json または x-www-form-urlencoded）
	$payload = (preg_match('/json/',$type))? file_get_contents('php://input'): $_POST['payload'];
	$payload = json_decode($payload,true);

	$repos = $payload['repository']['name'];# リポジトリ名
	$clone_url = str_replace('https://', "https://{$USER}:{$PASS}@", $payload['repository']['clone_url']);# クローンURL
	$ref = $payload['ref'];# ブランチ判定
	$log = "[{$date}][{$adder}][{$repos}]:";# logテキスト

	# .github-cloneが無いなら作成
	if(!is_dir("{$gclone}")) exec("mkdir {$gclone}");

	# 既にクローンされている場合
	if(is_dir("{$gclone}/{$repos}")){

		switch($ref){

			# マスターブランチ
			case 'refs/heads/master':
				exec("cd {$gclone}/{$repos};git pull origin master");
				$log.= "{$ref}をプルしました。";
				break;

			# それ以外のブランチ
			default: $log.= "{$ref}がプッシュされました。";
		}
	}
	# クローンされていない場合
	else{
		exec("cd {$gclone};git clone {$clone_url}");
		$log.= "{$repos}をクローンしました。";
	}
	# distのみpublic_htmlのリポジトリ名の場所に配置
	exec("cp -r {$gclone}/{$repos}/dist/* {$dir}/{$repos}/");

}
# Github以外からのアクセス
else{
	$log = "[{$date}][{$adder}]:不正アクセス。";
}
file_put_contents("{$gclone}/github-webhook.log", "{$log}\n", FILE_APPEND|LOCK_EX);# ログファイル生成