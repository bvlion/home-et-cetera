# cloud-glance

スマートフォンから1問ずつ質問し、回答を受け取る家庭内向けサービスです。`bvlion/gas/QuickAsk` を [Issue #88](https://github.com/bvlion/home-et-cetera/issues/88) の確定要件に従って移行します。移行元は2026-10-03時点の最新 `master`、コミット [`d516a1e05e3e08e487c393d7c12c207c884a7d6c`](https://github.com/bvlion/gas/tree/d516a1e05e3e08e487c393d7c12c207c884a7d6c/QuickAsk) を確認しています。

## 構成と実行環境

| 項目 | 内容 |
| --- | --- |
| 専用Firebase / Google Cloudプロジェクト | `b-glance` |
| 本番URL | `https://b-glance.web.app` |
| フロントエンド | Firebase Hosting。`frontend/index.html`、`styles.css`、`app.js`を別ファイルで管理し、`dist/`へビルド |
| サーバー | Cloud Functions for Firebase第2世代、Node.js 22、HTTP関数 `cloudGlance` の1つ |
| 関数リージョン | `asia-east1`。Firebase Hostingの[推奨リージョン](https://firebase.google.com/docs/hosting/functions)からアジアのリージョンを使用 |
| 認証 | Firebase AuthenticationのGoogleログインとサーバー側allowlist |
| 本番設定 | Google Cloud Secret Manager |
| リリース | `cloud-glance-v*` タグpush、cloud-glance専用GitHub Actions |

`/api/session`（GET）、`/api/ask`（POST）、`/api/share`（POST）はすべて1つの関数内で処理します。Hostingが `/api/**` を同じ関数へ転送します。関数URLへ直接アクセスしても、同じGoogleトークン検証とallowlist照合を行います。HTTP関数・データベース・履歴保存機能は追加しません。

HTMLにはCSS・JavaScriptを埋め込みません。Firebase SDK、Marked、DOMPurifyは固定した依存関係をビルドへ含め、実行時のCDN読み込みを置き換えています。Google Apps Scriptの配信・認証・RPC・設定管理・デプロイへの依存はありません。

## 維持する利用体験

Googleでログインし、許可確認後に質問します。4,000文字までの質問に、日本語のMarkdownで回答します。Web検索の出典は回答本文内と回答下から開けます。生成HTMLはDOMPurifyで安全化し、危険なリンク・実行可能なHTMLを除去します。

「クリア」は質問・回答・出典・通知を消します。回答取得中とSlack共有中は二重送信・共有・クリアを制限します。次の質問の取得に失敗した場合も、前の回答を維持します。

Slackへは「Slackに共有」を押すまで投稿しません。質問欄を編集しても、共有する質問は回答取得時点の質問です。回答は移行元と同じSlackの `markdown` blockへ渡します。質問と回答の合計は「Q. 」の接頭辞を含め12,000文字までです。出典は安全なHTTP/HTTPS URLだけを重複排除し10件まで共有します。

OpenAI [Responses API](https://developers.openai.com/api/docs/guides/tools-web-search)へのモデルは `gpt-6-luna`、`reasoning: { effort: 'low' }`、`store: false`、Web検索は `search_context_size: low`、`tool_choice: auto` です。現在情報が必要な場合だけ検索するよう指示します。現在日時は `Asia/Tokyo` で生成し、タイムゾーンと `HOME_LOCATION` を指示文と検索コンテキストへ渡します。過去のresponse IDや継続会話を渡しません。

Hostingには60秒の要求上限があるため、関数は60秒、外部API通信は50秒のタイムアウトとし、失敗を画面へ戻します。遅延で処理順序を調整しません。

## 本番環境・アカウント側の初回設定

以下は利用者の管理アカウントで実施します。本番値・メールアドレス・認証ファイルをIssueやPRへ掲載しないでください。

1. 専用プロジェクト `b-glance` をFirebaseへ登録し、Cloud Functionsに必要なBlazeプランの課金を有効にします。Firebase Hosting、Authentication、Cloud Functions、Cloud Run、Cloud Build、Artifact Registry、Secret ManagerのAPI・サービスと、必要なサービスエージェントを設定します。初回のFirebase CLIデプロイでは、必要なAPI・サービスエージェントの準備権限も必要です。
2. FirebaseコンソールでWebアプリを登録します。ブラウザーはHostingの[予約URL](https://firebase.google.com/docs/hosting/reserved-urls) `/__/firebase/init.json` からFirebaseの公開設定を取得するため、実際の設定値をリポジトリへ転記しません。
3. Firebase AuthenticationのGoogleプロバイダーを有効化し、承認済みドメインへ `b-glance.web.app` を登録します。第三者ストレージ制限のあるスマートフォンでもログインできるよう、認証ドメインをHostingと同じホストへ設定しています。[Firebase公式手順](https://firebase.google.com/docs/auth/web/redirect-best-practices)に従い、Google OAuthクライアントの承認済みリダイレクトURIへ `https://b-glance.web.app/__/auth/handler` も登録します。
4. Secret Manager APIを有効化し、次のSecretをすべて作成します。`cloud-glance/` から `npx firebase functions:secrets:set SECRET名 --project b-glance` を実行し、値は安全な入力で登録してください。実値をコマンド引数やファイルへ残さないでください。

| Secret | 内容 |
| --- | --- |
| `HOME_GOOGLE_ACCOUNT` | 許可するGoogleアカウントのメールアドレス。半角カンマ区切り、比較時に空白除去・小文字化 |
| `OPENAI_API_KEY` | OpenAI APIキー |
| `INCOMING_WEBHOOK_URL` | SlackのHTTPS Incoming Webhook URL |
| `HOME_LOCATION` | 検索に渡すおおまかな地域名。指定しない場合も空のSecretを設定 |
| `SLACK_POST_SETTINGS` | Firebase標準の `defineJsonSecret` で取得する、移行元の投稿設定を引き継ぐJSONオブジェクト。キーは `channel`、`username`、`icon_url` のみ、各値は文字列。Webhook側の設定だけでよければ `{}` |

移行元のSlack投稿先・表示名・アイコンは本番値なので転記していません。必要な既存値を `SLACK_POST_SETTINGS` へ設定してください。[Slack App型のWebhook](https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/)では投稿先・名前・アイコンの上書きが反映されないため、Slack側でも既存の設定を確認します。

すべてのSecretは1つの関数にバインドします。実行サービスアカウントには、IDトークンの失効・無効利用者確認に必要な `roles/firebaseauth.viewer` と、各Secretの `roles/secretmanager.secretAccessor` を付与してください。Firebase CLIが付与する構成を含め、初回設定時に確認します。Secretの新バージョンを有効にした場合は関数の再デプロイが必要です。allowlist更新も同じです。失効トークン・無効利用者も拒否するようIDトークンを検証します。

## GitHub Actionsの設定とリリース

Google Cloud側でデプロイ用サービスアカウントと[Workload Identity Federation](https://github.com/google-github-actions/auth)を用意します。GitHub OIDCを使用し、長期のサービスアカウント鍵は作成しません。連携の条件をこのリポジトリと `refs/tags/cloud-glance-v*` のみに制限し、デプロイ用サービスアカウントへの `roles/iam.workloadIdentityUser` を設定します。

このリポジトリのGitHub Actions Secretsへ次の2つを登録します。値はPublicな文書へ記録しません。

| GitHub Actions Secret | 内容 |
| --- | --- |
| `CLOUD_GLANCE_WORKLOAD_IDENTITY_PROVIDER` | 作成したWorkload Identity Providerの完全名 |
| `CLOUD_GLANCE_DEPLOY_SERVICE_ACCOUNT` | デプロイ用サービスアカウント |

デプロイ用サービスアカウントにはHosting・Functionsのデプロイ権限（`roles/firebasehosting.admin`、`roles/cloudfunctions.developer`）、Cloud Runの公開呼び出し設定に必要な `roles/run.admin`、プロジェクトのサービス利用権限（`roles/serviceusage.serviceUsageConsumer`）、実行・ビルドサービスアカウントへの `roles/iam.serviceAccountUser` を設定します。Secretの参照・バインドに必要な権限と、ビルドサービスアカウントのCloud Build / Artifact Registry権限も必要です。[FunctionsのIAM要件](https://cloud.google.com/functions/docs/reference/iam/roles)に沿って初回準備と継続デプロイを確認してください。API有効化やIAM付与は管理アカウントで行います。

PRのレビュー・マージ後、リリース対象コミットへ `cloud-glance-v*` タグを付けてpushします。`.github/workflows/cloud-glance-deploy.yaml` が依存関係の復元・テスト・ビルド・エミュレーター検証を行い、`b-glance` のHostingと `functions:cloud-glance:cloudGlance` だけを反映します。本番への同時デプロイはcloud-glance専用のconcurrency groupで制限します。他サービスのデプロイは起動しません。

ローカルからの同等の反映は `cloud-glance/` で検証後に `npx firebase deploy --project b-glance --only hosting,functions:cloud-glance:cloudGlance --non-interactive` を実行します。GitHub Actionsと同じ、Firebase Hosting / Functionsのデプロイです。デプロイ後の自動疎通は公開画面の成功と未ログイン `/api/session` の401を確認します。実アカウントでの受け入れ検証は次節に従います。

## 開発・検証

ローカルはNode.js 22.22.2以上の22系を使用します。`cloud-glance/` で次の順に実行します。

```sh
npm ci
npm --prefix functions ci
npm run check
npm run test:emulators
```

`npm run check` はフロントエンドの画面状態・Markdown・出典・共有と、サーバーの認証・allowlist・Responses要求・Slack・失敗処理を架空値で検証し、配信用ファイルをビルドします。外部API通信は置き換え、本番投稿を行いません。

`npm run test:emulators` は `demo-cloud-glance` のHosting・Functions・Authenticationを起動し、実際のHTTP転送、Googleプロバイダーの検証用トークン、allowlist内外、関数URL直接アクセスを検証します。本番プロジェクトへ接続しません。架空値の `functions/.secret.local` を排他的に作成し終了時に削除します。既存ファイルがある場合は上書きせず失敗します。ポート15000・15001・19099が空いていることを確認してください。

手動のエミュレーター起動には、同名のSecretをすべて架空値で `functions/.secret.local` に設定し、`npm run build`、`npm run emulators` を実行します。Authenticationエミュレーターを使用する接続は、localhost / 127.0.0.1かつ `demo-cloud-glance` の場合だけです。質問・共有の手動操作は実際の外部通信になり得るため、テスト用設定のみ使用してください。

CIは `.github/workflows/cloud-glance-ci.yaml` の `cloud-glance-test` で実行します。mainへのpushとすべてのPull Requestでジョブを起動し、cloud-glanceまたは専用CI / デプロイworkflowの変更がある場合だけ依存関係の復元・検証・ビルド・エミュレーター検証を実行します。関係しない変更では重い検証をスキップし、変更判定が成功すればチェックも成功します。変更判定・検証が失敗した場合はジョブが失敗します。

このworkflowがmainへマージされた後、main rulesetの `Protect main` のRequired status checksへGitHub Actionsの `cloud-glance-test` を追加し、既存の `test` も維持します。マージ前は他のPRに新workflowが存在せずPendingになるため、必須チェックの追加はマージ後に行います。設定後、両チェックが必須になっていることを確認してください。

## 本番受け入れ検証と移行元の廃止判断

コードの検証だけでは本番動作完了・移行元廃止可能とは判断しません。設定・リリース後、次を確認し、結果をPR / Issueへ本番値なしで記録します。

- `https://b-glance.web.app` がスマートフォンで開き、GAS固有の警告バナーがない。
- 未ログインでは質問できず、許可するGoogle利用者がログインでき、allowlist外は画面・質問・共有を利用できない。
- 一般知識の質問でMarkdown回答を取得でき、現在情報が必要な質問でWeb検索の本文内・回答下の出典が開ける。
- 現在日時・日本時間・地域の扱いが正しく、回答・質問・出典・通知をクリアできる。
- 回答後の質問欄編集や取得失敗があっても、Slackには回答取得時点の質問と回答が共有される。共有操作前には投稿がなく、実際の投稿先・表示設定・Markdown・出典が正しい。
- `cloud-glance-v*` のworkflowでHostingと1つのHTTP関数だけが反映され、独自の質問・回答履歴を保存していない。

すべての本番検証が済み、許可利用者が新URLへ移行した後に、QuickAskを廃止可能と判断します。`bvlion/gas` 側の削除そのものはIssue #88のスコープ外です。初期時点でSecret Manager API・課金・認証・GitHub連携が未準備の場合は、それらの利用者側作業と本番検証を残作業として扱います。

## データの扱い

質問・回答・出典はページ内のメモリーだけに保持し、再読み込み・画面終了で消えます。独自のデータベース・履歴・ログへ保存しません。OpenAIへは `store: false` を指定し、Slackへは任意の共有操作時だけ送信します。Firebase Authenticationの認証状態はSDKが管理します。外部サービス側のデータ取り扱いは各アカウントの設定・ポリシーに従います。エラーには外部APIの生レスポンスや秘密情報を含めません。
