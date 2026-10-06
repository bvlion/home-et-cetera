# cloud-conciergeの作業ルール

リポジトリルートの[AGENTS.md](../AGENTS.md)と併せて適用します。対象は `cloud-concierge/`、`.github/workflows/cloud-concierge-ci.yaml`、`.github/workflows/cloud-concierge-deploy.yaml`、`.github/dependabot.yml` の本サービス用npm設定です。他サービスに適用しません。

## 維持する仕様

- Firebase Functions第2世代 / RTDB、Node.js 24、`us-central1`、HTTP関数 `postRequestFunction` を維持します。
- GitHub Actionsでmainから `gcloud functions deploy` する方針Aを採用します。Firebase CLIによる別の本番デプロイ経路を追加しません。
- HTTPの `type`、ヘッダー認証、ステータスコード、RTDBパス、機器コマンド・送信順序・1500ミリ秒の間隔を、明示されたIssueなしに変更しません。
- `morning` と休日設定の後続処理が応答後も続く仕様を、移行に伴って変更しません。
- `speak_time` は、Issue #84の最新方針に従い、移行元Publicコードの固定文面を含めて維持します。移行に伴う外部設定化を行いません。
- 先行した機能追加・リファクタリング・他サービスへの仕様統一を行いません。

## 検証と公開情報

- `npm --prefix functions install`、`npm --prefix functions run lint` を本ディレクトリから実行します。Node.js 24を使用します。CIは移行元と同じPull Request時のcheckout、Node.js setup、npm install、cache、lintを維持します。
- 移行確認のための新しい検証基盤や本番デプロイ前の追加検証を、今回の移行へ混在させません。本番RTDB・機器API・Webhookを検証目的で操作しません。
- `.envrc`、`.env*`、認証JSON、RTDB exportをコピー・公開しません。既存Publicコード以外の通知文面や秘密値・家庭固有の実値を追加しません。実データからfixtureを生成しません。
- デプロイや実機確認の結果は、秘密値・URL・実リクエスト・実応答・実ログを除いた成否だけ記録します。
- 本番設定・切り替え・archive判断の手順は[README](README.md)に従います。コード検証の成功を本番切り替え成功と扱いません。旧リポジトリを勝手にarchiveしません。
