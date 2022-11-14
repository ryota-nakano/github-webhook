<?php

$user = 'your name';
$token = $_GET['token'];
$SECRET = 'your secret';#Github Webhook Secret

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
	$clone_url = $payload['repository']['clone_url'];# クローンURL
	$clone_url = str_replace('https://', "https://{$user}:{$token}@", $clone_url);
	$ref = $payload['ref'];# ブランチ判定
	$log = "[{$date}][{$adder}][{$repos}]:";# logテキスト

	# 既にクローンされている場合
	if(is_dir($repos)){

		switch($ref){

			# マスターブランチ
			case 'refs/heads/main':
			case 'refs/heads/master':
				$branch = end(explode('/',$ref));
				exec("cd {$dir}/{$repos};git remote set-url origin $clone_url;git pull origin {$branch}");
				$log.= "{$ref}をプルしました。";
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
