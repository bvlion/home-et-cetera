# home-et-cetera

複数の個人・家庭内向けサービスを、役割ごとに分離して管理するリポジトリです。各サービスの実行基盤・言語・アーキテクチャ・デプロイ方式は個別に管理します。

## サービス一覧

| サービス | 管理状況 | 説明・関連Issue |
| --- | --- | --- |
| BvlionBatch5 | 管理中。実装・設定はリポジトリルートに配置 | メール処理・記念日通知などを提供するXServer上のHTTP API。[専用README](docs/BvlionBatch5/README.md) |
| dialogflow-functions | 今後統合予定 | [Issue #84](https://github.com/bvlion/home-et-cetera/issues/84)で扱います |
| BvlionBatch3 | 今後統合予定 | [Issue #85](https://github.com/bvlion/home-et-cetera/issues/85)で扱います |
| Quick Ask | 今後移行予定 | 別Issueで扱います |

[Issue #69](https://github.com/bvlion/home-et-cetera/issues/69)ではリポジトリ全体の位置づけと文書を整理します。追加予定の3サービスの実コード統合と、各サービスの実行基盤・アーキテクチャ変更は、それぞれのIssueで扱います。

## 現在の構成

| 配置 | 役割 |
| --- | --- |
| `README.md`、`AGENTS.md` | リポジトリ全体の説明・共通作業ルール |
| `docs/BvlionBatch5/` | BvlionBatch5専用の説明・作業ルール |
| `bin/`、`bootstrap/`、`database/`、`public/`、`resources/`、`src/`、`tests/` | BvlionBatch5の実装・データベース定義・検証 |
| `composer.*`、`phpcs.xml`、`phpunit.xml` | BvlionBatch5の依存関係・検証設定 |
| `docker/`、`compose*.yaml`、`Makefile`、`.dockerignore`、`.env.example` | BvlionBatch5のローカル開発・検証設定 |
| `.github/workflows/ci.yaml`、`.github/workflows/deploy.yaml` | BvlionBatch5の検証・本番デプロイ |
| `.github/dependabot.yml` | BvlionBatch5のComposer依存関係とGitHub Actionsの更新設定 |
| `docs/production-environment.md`、`docs/legacy-data-migration.md`、`docs/legacy-db.env.example` | BvlionBatch5の本番環境・旧環境データ移行資料 |

BvlionBatch5の実装・設定は既存の配置を維持しています。開発・検証・デプロイ手順は[専用README](docs/BvlionBatch5/README.md)を参照してください。ルートの`make check`と`v*`タグによる本番デプロイはBvlionBatch5用です。

## サービスを追加する方針

- 追加・統合はサービスごとのIssueで行い、サービスごとのディレクトリに配置します。具体的な配置は対象Issueで決めます。
- 各サービスのREADME.mdに役割・実行環境・開発・検証・デプロイ手順を記載し、AGENTS.mdに固有の作業ルールを記載します。
- サービスの依存関係・設定・検証・デプロイを個別に管理し、BvlionBatch5のSlim 4・PDO・Docker・XServerの方針を他サービスの要件にはしません。
- サービスを追加した際は、このREADMEのサービス一覧と構成を更新します。

## リポジトリ名

リポジトリ名は`home-et-cetera`です。Issue #69で、BvlionBatch5専用だった旧名称から家庭内サービス全体を表す名称へ変更しました。BvlionBatch5のサービス名・実行設定は維持します。

既存のcloneでは、`origin`を`https://github.com/bvlion/home-et-cetera.git`へ更新してください。本番checkoutも同じ対象ですが、更新は利用者が既存の配置で行います。アプリの配置先・公開先・GitHub Secretsを名称に合わせて変更する必要はありません。

## 開発運用

共通ルールは[AGENTS.md](AGENTS.md)、BvlionBatch5固有のルールは[専用AGENTS.md](docs/BvlionBatch5/AGENTS.md)を参照してください。

- 1つのIssueにつき、1つのブランチと1つのPRを作成します。
- PRはユーザーの明示的な承認を得るまでマージしません。
- Issueに記載された範囲を最小差分で実装し、明示されていない横展開は行いません。

## Publicリポジトリの運用

- 本番値、個人情報、秘密情報をコミットしません。
- Issue、PR、テスト、fixture、ログ、SQLにも実データを含めません。
- 認証情報、Webhook、実際のチャンネルID、実際のメール内容、本番で使用する通知文面、データベースの本番値は、リポジトリ外の安全な場所で管理します。
- サンプルやテストには、実データから生成していない架空の値を使用します。
