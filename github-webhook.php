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
$date = date("[Y-m-d H:i:s]");# 日付
$adder = $_SERVER['REMOTE_ADDR'];# 訪問者のIPアドレス

# Githubからのアクセス
if(@hash_equals($hmac,$sig)){
	$payload = json_decode($_POST['payload'],true);
	$dir = $payload['repository']['name'];
	$clone_url = str_replace('https://', "https://{$USER}:{$PASS}@", $payload['repository']['clone_url']);
	$ref = $payload['ref'];# ブランチ判定
	switch($ref){
		case 'refs/heads/master':# マスターブランチ
			# 既にクローンされている場合
			if(is_dir($dir)){
				exec("cd {$dir};git pull origin master");
				$log = "{$date} {$adder}: {$dir}にmasterをプルしました。";
			}
			# クローンされていない場合
			else{
				exec("git clone {$clone_url}");
				$log = "{$date} {$adder}: {$dir}をクローンしました。";
			}
			break;
		default:# それ以外のブランチ
			$log = "{$date} {$adder}: {$ref} がプッシュされました。";
	}
}
# Github以外からのアクセス
else{
	$log = "{$date} {$adder}: 不正アクセス。";
}
file_put_contents('github-webhook.log', "{$log}\n", FILE_APPEND|LOCK_EX);# ログファイル生成