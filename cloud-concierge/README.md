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

本番用の時刻通知文面だけは公開コードから外し、環境変数 `SPEAK_TIME_TEMPLATE` に移しました。`{time}` を日本時間の時分、`{timestamp}` をISO日時に置換します。既存の文面・制御用の内容を非公開で引き継ぐ必要があり、サンプル文面を本番設定に使いません。

## 配置

| 配置 | 内容 |
| --- | --- |
| `functions/index.js`、`functions/intents/` | HTTP受付、機器API、休日Webhook、Raspberry Pi向けRTDB書き込み |
| `functions/package.json`、`functions/package-lock.json` | 移行元の依存関係とNode.js 24指定 |
| `firebase.json`、`database.rules.json` | Firebase Emulator設定と既存RTDBルール |
| `scripts/verify-emulators.mjs` | 架空データによる17操作の動作確認 |
| `../.github/workflows/cloud-concierge-ci.yaml` | サービス専用の検証 |
| `../.github/workflows/cloud-concierge-deploy.yaml` | mainへのpushによる既存関数の更新 |

## 開発と検証

Node.js 24とJava 21以上を用意します。以下は `cloud-concierge/` から実行します。

```sh
npm --prefix functions ci
npm --prefix functions run lint
npm --prefix functions run test:emulators
```

検証は `demo-cloud-concierge` プロジェクトのFunctions（5001）とRTDB（5002）Emulatorを起動します。ポートを空けて実行してください。データと送信先はテスト内の架空値・ループバックHTTPサーバーだけで、個人の認証設定は不要です。依存関係とEmulatorの初回取得にはインターネット接続が必要です。

17操作、POST制約、正しいトークン・不正トークン・ヘッダーなし、未知の操作、RTDBルール、機器APIの順序・認証・button指定・待機間隔、RTDB書き込み、日本時間、既存の早期応答を確認します。実機、実Webhook、本番IAM・デプロイはこの検証では確認しません。

対話的にEmulatorだけを起動する場合:

```sh
npm --prefix functions run serve
```

`serve` もdemoプロジェクトと架空の通知テンプレートを使います。空のデータベースで起動するため、要求を送る場合は架空のトークン・ループバックURLをEmulatorへ設定してください。移行元の本番データexportの自動importは行いません。

[Firebase公式のdemoプロジェクトとRTDB Emulatorの説明](https://firebase.google.com/docs/emulator-suite/connect_rtdb)も参照してください。demoプロジェクトは本番リソースを持ちません。

## CI

`cloud-concierge CI` はmainへのpushとすべてのPull Requestで `cloud-concierge-test` を起動します。`cloud-concierge/**` または専用CI・デプロイworkflowの変更時に、Node.js 24で `npm ci`、lint、Emulator検証を実行します。他サービスだけの変更では検証をスキップし、変更判定の成功でチェックを成功させます。他サービスのCI・必須チェック設定は変更しません。

## デプロイと非公開設定

選択された方針Aに従い、GitHub Actionsの `gcloud functions deploy` を本番デプロイの正とします。移行元の別経路であった `npm run deploy`（Firebase CLI）は移しません。mainへのpushでサービス内のJavaScript・JSON、`.gcloudignore`、専用デプロイworkflowが変わると、既存関数を更新します。ドキュメントだけの変更では起動しません。

`home-et-cetera` に以下のRepository Secretsを非公開で設定します。

| Secret名 | 引き継ぐ内容 |
| --- | --- |
| `CLOUD_CONCIERGE_GCP_PROJECT_ID` | 移行元 `FIREBASE_PJ` と同じ既存プロジェクト |
| `CLOUD_CONCIERGE_SERVICE_ACCOUNT_KEY_BASE64` | 移行元 `GCLOUD_SERVICE_KEY` と同じ形式のbase64サービスアカウントJSON。既存のデプロイ権限を維持 |
| `CLOUD_CONCIERGE_FIREBASE_DATABASE_URL` | 移行元 `FIREBASE_DATABASE_URL` と同じRTDB URL |
| `CLOUD_CONCIERGE_SPEAK_TIME_TEMPLATE` | 移行元の時刻通知文面の動的な時分・ISO日時部分だけを `{time}`・`{timestamp}` に置き換えた値 |

値の不足やテンプレートのプレースホルダー不足はデプロイ前に失敗させます。認証JSONと環境設定ファイルはrunnerの一時領域に権限600で作成して終了時に削除し、リポジトリやartifactに保存しません。環境変数の値に区切り文字が含まれても保持するため、gcloudの [`--env-vars-file`](https://docs.cloud.google.com/sdk/gcloud/reference/functions/deploy) を使います。

デプロイ引数は `postRequestFunction --gen2 --runtime=nodejs24 --region=us-central1 --trigger-http --entry-point=postRequestFunction --source=. --allow-unauthenticated` を維持します。対象は `cloud-concierge/functions/` のみです。RTDBのデータ移行・ルール変更は実行しません。既存RTDBとRaspberry Piの接続・参照パスも維持してください。

サービス内の `.env*`、`.envrc`、`.firebaserc`、認証JSON、`functions/data/` の実データはコミットしません。デプロイ対象にも環境設定・データexportを含めません。秘密値や家庭固有の実値、実通知文面、実リクエスト・応答・ログをIssueやPRに貼らないでください。

## 本番切り替えと旧リポジトリのarchive判断

コードの移行・架空データでの検証だけでは、本番切り替え済みとは判断しません。初回マージの前にSecretsを準備し、切り替え中は旧リポジトリのmain更新による並行デプロイを止めます。設定が未準備なら本workflowは失敗し、既存本番関数を更新しません。

archive可能とするには、次をすべて確認します。

1. このPRをmerge commitで取り込み、新リポジトリのデプロイが既存プロジェクト・既存関数へ成功する。
2. 既存URL・RTDB・Raspberry Pi連携が維持され、時刻通知の非公開設定も引き継がれている。
3. 利用者が既存の呼び出し元から必要な家庭内操作を確認する。公開記録は結果の成否だけにする。
4. 旧リポジトリに未移行の変更や必要な未解決作業がなく、今後の変更・依存関係更新を本サービスで管理できる。

上記が未確認の間はarchive不可です。archive操作自体はIssue #84の対象外です。新しいSecretsの設定、本番デプロイ成功、実機での受け入れ確認は利用者側で行い、結果だけIssue #84に記録してください。
