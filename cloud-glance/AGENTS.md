# cloud-glance の作業ルール

ルートの[AGENTS.md](../AGENTS.md)と併せて、このディレクトリと `.github/workflows/cloud-glance-ci.yaml`、`.github/workflows/cloud-glance-deploy.yaml` に適用します。

- 仕様の正は [Issue #88](https://github.com/bvlion/home-et-cetera/issues/88) の確定要件です。移行元の確認には `bvlion/gas/QuickAsk` の最新 `master` を使用し、確認コミットを記録します。
- Firebaseプロジェクトは専用の `b-glance`、本番URLは `https://b-glance.web.app` です。他サービスとプロジェクト・実行基盤・デプロイを共用しません。
- Node.js 22、Firebase Hosting、Cloud Functions for Firebase第2世代、Firebase AuthenticationのGoogleログインを使用します。
- デプロイするHTTP関数は `cloudGlance` の1つだけです。利用許可確認・質問・Slack共有はその内側で扱い、HTTP関数を追加しません。
- CSS・JavaScriptをHTMLへ埋め込みません。フロントエンドは `frontend/`、ビルド成果物は未追跡の `dist/` に置きます。
- 各サーバー処理でGoogleのIDトークンとSecret Managerのallowlistを検証します。ブラウザーの状態だけを信用しません。
- APIキー・Webhook・allowlist・地域情報・Slackの投稿設定はSecret Managerで管理します。本番値をソース・文書・Issue・PR・fixture・ログへ記録しません。
- 質問・回答・出典・IDトークンを独自の履歴、データベース、ブラウザーの永続ストレージ、ログへ保存しません。Firebase Authenticationの認証状態はSDKで管理します。
- Responses APIの `gpt-6-luna`、`reasoning.effort: low`、`store: false`、自動Web検索、`Asia/Tokyo` を維持します。継続会話を追加しません。
- Slackへは共有操作があった場合だけ投稿し、回答取得時点の質問と組み合わせます。移行元のMarkdown・出典・クリア・処理中の操作制限を維持します。
- 確認はこのディレクトリで `npm ci`、`npm --prefix functions ci`、`npm run check`、`npm run test:emulators` を実行します。エミュレーター検証は `demo-cloud-glance` と架空値だけを使用します。
- リリースは `cloud-glance-v*` タグpushで行います。cadence-xsのworkflowやデプロイ方式を変更しません。
- 利用者のアカウント側で必要な作業・未確定の設計判断をIssueまたはPRへ報告します。本番検証前に完了・移行元廃止可能と断定しません。
