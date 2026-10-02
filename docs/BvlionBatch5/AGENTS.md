# BvlionBatch5の作業ルール

この文書はBvlionBatch5の実装・設定・検証・運用と、その関連文書に適用します。対象パスは[ルートAGENTS.md](../../AGENTS.md)に記載しています。他サービスには適用しません。共通ルールはルートAGENTS.mdを参照してください。

## 移行範囲

- 移行対象は、旧BvlionBatch4のメール処理と記念日通知、および旧HomeServerの残業通知です。
- 旧BvlionBatch4の `/horoscope` と `/speak-time` は移行対象外です。
- Issueで明示されていない機能を移行対象へ追加しません。

## 技術方針

- WebアプリケーションフレームワークにはSlim 4を使用します。
- データベースアクセスにはPDOを使用します。
- APIはHTTPリクエスト内で処理を完了する同期処理とします。
- `/health`エンドポイントは実装しません。
- 不要な抽象化を導入しません。
- DIコンテナを導入しません。
- 基底Repositoryを導入しません。
- 過剰なClean Architectureを導入しません。

## Docker運用の安全ルール

- 起動中の既存ローカル開発Docker環境（container・network・volume）を、利用者の明示的な許可なしに再作成・削除しません。
- 開発用の`database` volumeを、利用者の明示的な許可なしに削除しません。削除するコマンドは`make db-wipe CONFIRM=yes`だけです。
- 実`.env`、実データベース、Slack・IMAPなど実外部サービスへ、利用者の明示的な許可なしに接続しません。
- 上記について判断できない場合は、実行せず作業を止めて確認します。
- 事故・中断・確認事項は日本語で具体的に報告します。
- 通常の検証には検証専用の`make check`を使用します。`make check`は検証専用のCompose project（`bvlionbatch5-check`）だけを使い、開発用のcontainer・network・volume・host portには一切触れません。
