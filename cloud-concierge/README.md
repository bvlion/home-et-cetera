# cloud-concierge

HTTPで家庭内操作の要求を受け、Firebase Functions / Realtime Database（RTDB）経由で機器操作とRaspberry Pi側の処理へ取り次ぐサービスです。[Issue #84](https://github.com/bvlion/home-et-cetera/issues/84)で、[dialogflow-functions](https://github.com/bvlion/dialogflow-functions)のmain、コミット `90c2493024819404205762105398d89ddaa0e556` から移行しました。現行コードにDialogflowとの連携はありません。

## 実行環境と既存動作

- Node.js 24、Firebase Functions第2世代、リージョン `us-central1`。
- HTTP関数名とentry pointは `postRequestFunction`。既存のプロジェクト・関数・URL・RTDBを引き継ぎ、新しい関数へ切り替えません。
- POSTの `x-auth-header` をRTDBの `/token` と照合します。POST以外は404、認証不一致は400、未知の `type` は200を返します。
- Google Cloud側では `--allow-unauthenticated` を維持し、アプリケーション側のトークンで確認します。RTDBのクライアント読み書きは禁止し、Admin SDKを利用します。

リクエストのJSONには `type` と、必要な操作で `text` または `command` を指定します。

| type | 現行の処理 |
| --- | --- |
| `living_on` / `living_off` | RTDBに登録された機器APIへ順番にPOST |
| `morning` | `/pi/curtain` へ書き込み、リビング操作、追加の機器操作 |
| `before_sleep` / `sleep` / `all_off` | 就寝前・就寝・全停止の既存送信順序を実行。`sleep` はカーテンも書き込み |
| `set_today_holiday` / `set_today_weekday` / `set_tomorrow_holiday` / `set_tomorrow_weekday` | 日本時間の当日・翌日を既存WebhookへPOST |
| `speak_text` / `speak_time` | `/pi/notifier` へ指定テキスト・日本時間の時刻を含む通知を書き込み |
| `curtain` | `command` を `/pi/curtain` へ書き込み |
| `pc_switch` | `/pi/pc_switch` へ日時を書き込み |
| `sesame_open` / `sesame_close` / `sesame_toggle` | 既存のコマンドを `/pi/sesame` へ書き込み |

RTDBの `/remo/token`、`/remo/url/*`、`/holidays-webhook/token`、`/holidays-webhook/holiday-update-url` は既存の構造を維持します。URL・トークン・実データはここへ掲載しません。機器API間の1500ミリ秒待機は移行元の送信間隔です。`morning` は追加の機器操作の完了前、休日設定はWebhookの完了前に応答する現行仕様を維持します。これらの応答は後続処理の完了を保証しません。

`speak_time` は、Issue #84の最新方針に従い、移行元Publicコードの固定文面を含めて維持します。新しい通知テンプレート設定はありません。

## 配置

| 配置 | 内容 |
| --- | --- |
| `functions/index.js`、`functions/intents/` | HTTP受付、機器API、休日Webhook、Raspberry Pi向けRTDB書き込み |
| `functions/package.json`、`functions/package-lock.json` | 移行元の依存関係とNode.js 24指定 |
| `firebase.json`、`database.rules.json` | Firebase Emulator設定と既存RTDBルール |
| `../.github/workflows/cloud-concierge-ci.yaml` | サービス専用の検証 |
| `../.github/workflows/cloud-concierge-deploy.yaml` | mainへのpushによる既存関数の更新 |

## 開発と検証

Node.js 24を用意します。以下は `cloud-concierge/` から実行します。

```sh
npm --prefix functions install
npm --prefix functions run lint
```

移行元の `serve` script（`firebase emulators:start --only functions,database --import data`）も維持します。利用する場合はJavaを含むFirebase Emulatorの実行環境、非公開のローカルプロジェクト・環境設定、`functions/data/` のEmulator exportが必要です。公開配置は `.gitkeep` だけなので、検証用には実データから生成していない架空のexportとループバック送信先を用意します。Functionsは5001、RTDBは5002を使用します。

```sh
npm --prefix functions run serve -- --project demo-cloud-concierge
```

上記はdemoプロジェクトの指定例です。`FIREBASE_DATABASE_URL` も架空のdemo用設定にし、本番RTDB・機器API・Webhookを検証目的で操作しません。[Firebase公式のRTDB Emulatorの説明](https://firebase.google.com/docs/emulator-suite/connect_rtdb)も参照してください。

今回の移行では恒久的なEmulator統合検証を追加しません。既に実施した17操作のEmulator確認結果は、[Issue #84の移行確認記録](https://github.com/bvlion/home-et-cetera/issues/84#issuecomment-6011837968)として残します。本番IAM・デプロイ・実機はその確認の対象外です。

## CI

`cloud-concierge CI` は、専用CI workflowまたは `cloud-concierge/` 内のJavaScript・JSONに変更のあるPull Requestで起動します。移行元と同じcheckout、Node.js 24、`npm install`、cache、lintの順序・Actionsの版を維持します。ジョブ名とcache key・lockfile判定をサービスごとに分離します。main push、paths-filter、Java setup、Emulator検証は追加しません。

他サービスだけの変更ではこのworkflowは起動しません。main rulesetに `cloud-concierge-test` は追加せず、他サービスのCI・必須チェック設定も変更しません。

## デプロイと非公開設定

選択された方針Aに従い、GitHub Actionsの `gcloud functions deploy` を本番デプロイの正とします。移行元の別経路であった `npm run deploy`（Firebase CLI）は移しません。mainへのpushでサービス内のJavaScript・JSONまたは専用デプロイworkflowが変わると、既存関数を更新します。ドキュメントだけの変更では起動しません。

`home-et-cetera` に以下のRepository Secretsを非公開で設定します。

| Secret名 | 引き継ぐ内容 |
| --- | --- |
| `CLOUD_CONCIERGE_GCP_PROJECT_ID` | 移行元 `FIREBASE_PJ` と同じ既存プロジェクト |
| `CLOUD_CONCIERGE_SERVICE_ACCOUNT_KEY_BASE64` | 移行元 `GCLOUD_SERVICE_KEY` と同じ形式のbase64サービスアカウントJSON。既存のデプロイ権限を維持 |
| `CLOUD_CONCIERGE_GOOGLE_APPLICATION_CREDENTIALS` | 移行元 `GOOGLE_APPLICATION_CREDENTIALS` と同じ役割の認証JSON配置先。runner上の書き込み可能な絶対パスで、デプロイ対象の `cloud-concierge/functions/` 外を指定 |
| `CLOUD_CONCIERGE_FIREBASE_DATABASE_URL` | 移行元 `FIREBASE_DATABASE_URL` と同じRTDB URL |

移行元と同じcheckout、gcloud setup、認証JSON準備、サービスアカウント認証、`gcloud functions deploy` の順序・Actionsの版を維持します。環境変数は既存の `FIREBASE_DATABASE_URL` だけを `--set-env-vars` で渡します。Node.js setup、設定検証スクリプト、環境変数ファイル生成、npm検証、cleanupは追加しません。

デプロイ引数は `postRequestFunction --gen2 --runtime=nodejs24 --region=us-central1 --trigger-http --entry-point=postRequestFunction --source=. --allow-unauthenticated` を維持します。対象は `cloud-concierge/functions/` のみです。RTDBのデータ移行・ルール変更は実行しません。既存RTDBとRaspberry Piの接続・参照パスも維持してください。

サービス内の `.env*`、`.envrc`、`.firebaserc`、認証JSON、`functions/data/` の実データはコミットしません。移行元の `.envrc` や本番exportは移していません。秘密値や家庭固有の実値、新たな実通知文面、実リクエスト・応答・ログをIssueやPRに貼らないでください。

## 本番切り替えと旧リポジトリのarchive判断

コードの移行・架空データでの検証だけでは、本番切り替え済みとは判断しません。初回マージの前にSecretsを準備し、切り替え中は旧リポジトリのmain更新による並行デプロイを止めます。

archive可能とするには、次をすべて確認します。

1. このPRをmerge commitで取り込み、新リポジトリのデプロイが既存プロジェクト・既存関数へ成功する。
2. 既存URL・RTDB・Raspberry Pi連携が維持されている。
3. 利用者が既存の呼び出し元から必要な家庭内操作を確認する。公開記録は結果の成否だけにする。
4. 旧リポジトリに未移行の変更や必要な未解決作業がなく、今後の変更・依存関係更新を本サービスで管理できる。

上記が未確認の間はarchive不可です。archive操作自体はIssue #84の対象外です。新しいSecretsの設定、本番デプロイ成功、実機での受け入れ確認は利用者側で行い、結果だけIssue #84に記録してください。
