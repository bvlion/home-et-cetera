# cadence-xsの作業ルール

この文書はcadence-xsの実装・設定・検証・運用と、その関連文書に適用します。対象パスは[ルートAGENTS.md](../AGENTS.md)に記載しています。他サービスには適用しません。共通ルールはルートAGENTS.mdを参照してください。

## 役割・実行環境・作業の入口

- 現在の役割はメール処理と記念日通知の認証付きHTTP APIです。機能・開発・検証・デプロイの詳細は[README](README.md)を参照してください。
- 本番はXServerのPHP 8.5.5（CGI/FastCGI）とMySQL 5.7系を使用し、Docker化しません。CLIでは`/opt/php-8.5.5/bin/php`と既存の専用Composerを明示します。
- 開発・検証のコマンドは`cadence-xs/`で実行します。ローカル開発はDocker Compose、通常の検証は`make check`（ルートからは`make -C cadence-xs check`）を使用します。開発用project名は`cadence-xs`です。
- GitHub Actionsの検証処理は`.github/workflows/ci.yaml`（`cadence-xs CI`）に配置します。Pull Requestとmainへのpushで必須チェック`test`を起動し、`cadence-xs/`またはCI定義の変更がある場合だけ`make check`を実行します。無関係な変更では重い検証をスキップし、変更判定が成功すればジョブを成功させます。変更判定や検証の失敗はジョブの失敗とします。
- デプロイは`cadence-xs-v*`タグpushによるGitHub Actions・SSH方式です。`DEPLOY_PATH`は既存Gitリポジトリのルートを維持し、対象タグから配下の`cadence-xs/`だけを更新します。他のパスのファイルは更新・復元・削除しません。変更確認は`cadence-xs/`内のtrackedファイルに限定し、デプロイ済みcommitをHEADとindexへ記録します。不要なルートファイル・旧配置の残存物の初回削除と、旧環境からの切り替えはREADMEの利用者側手順に従います。
- PHPの名前空間は`Bvlion\CadenceXs`を使用し、過去のマイグレーションは維持します。配置変更を理由に機能やデータを変更しません。
- 秘密情報・本番値は共通ルールに従い非公開で管理し、`.env`はサービスディレクトリに配置します。旧開発volumeや本番環境の切り替えを無断で実行しません。

## 旧環境からの移行範囲

- 現在維持する移行済み機能は、旧BvlionBatch4のメール処理と記念日通知です。旧HomeServerの残業通知は既存実装で廃止済みであり、この配置変更で復元しません。
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
- 通常の検証には検証専用の`make check`を使用します。`make check`は検証専用のCompose project（`cadence-xs-check`）だけを使い、開発用のcontainer・network・volume・host portには一切触れません。
