# home-et-cetera

複数の個人・家庭内向けサービスを、役割ごとに分離して管理するリポジトリです。各サービスの実行基盤・言語・アーキテクチャ・デプロイ方式は個別に管理します。

## サービス一覧

| サービス | 管理状況 | 説明・関連Issue |
| --- | --- | --- |
| cadence-xs | 管理中。`cadence-xs/`に配置 | メール処理・記念日通知などを提供するXServer上のHTTP API。[専用README](cadence-xs/README.md) |
| cloud-concierge | 管理中。`cloud-concierge/`に配置。本番切り替え条件は専用READMEを参照 | Firebase Functions / RTDBで家庭内操作を取り次ぐサービス。[専用README](cloud-concierge/README.md)、[Issue #84](https://github.com/bvlion/home-et-cetera/issues/84) |
| pi-steward | 管理中。`pi-steward/`に配置。Raspberry Piの切り替え・archive条件は専用READMEを参照 | Realtime Databaseの変更を受けて家庭内機器の操作と通知を実行する常駐サービス。[専用README](pi-steward/README.md)、[Issue #85](https://github.com/bvlion/home-et-cetera/issues/85) |
| cloud-glance | 管理中。`cloud-glance/`に配置。本番移行確認は専用READMEを参照 | Googleログイン付きの単発質問・任意のSlack共有。[専用README](cloud-glance/README.md)、[Issue #88](https://github.com/bvlion/home-et-cetera/issues/88) |

[Issue #69](https://github.com/bvlion/home-et-cetera/issues/69)ではリポジトリ全体の位置づけと文書を整理します。cloud-concierge（Issue #84）、pi-steward（Issue #85）、cloud-glance（Issue #88）の統合・移行と、各サービスの実行基盤・アーキテクチャ変更は、それぞれのIssueで扱います。

## 現在の構成

| 配置 | 役割 |
| --- | --- |
| `README.md`、`AGENTS.md` | リポジトリ全体の説明・共通作業ルール |
| `cadence-xs/README.md`、`cadence-xs/AGENTS.md` | cadence-xs専用の説明・作業ルール |
| `cadence-xs/` | cadence-xsの実装・依存関係・データベース定義・ローカル開発・検証設定 |
| `cadence-xs/docs/` | cadence-xsの本番環境・旧環境データ移行資料 |
| `.github/workflows/cadence-xs-ci.yaml`、`.github/workflows/cadence-xs-deploy.yaml` | cadence-xsの検証・本番デプロイ |
| `cloud-glance/` | Firebase Hosting / Cloud Functions / Authenticationの単発質問サービス。説明・作業ルール・検証を個別管理 |
| `.github/workflows/cloud-glance-ci.yaml`、`.github/workflows/cloud-glance-deploy.yaml` | cloud-glance専用の検証と `cloud-glance-v*` タグによるデプロイ |
| `cloud-concierge/` | 家庭内操作を取り次ぐFirebase Functions / RTDBサービス。説明・作業ルール・検証を個別管理 |
| `.github/workflows/cloud-concierge-ci.yaml`、`.github/workflows/cloud-concierge-deploy.yaml` | cloud-concierge専用の検証とmainへのpushによるgcloudデプロイ |
| `pi-steward/` | Raspberry Pi / systemd / root cronで動く機器操作・通知サービス。説明・作業ルール・検証を個別管理 |
| `.github/dependabot.yml` | cadence-xsのComposer依存関係、cloud-glance・cloud-concierge・pi-stewardのnpm依存関係、GitHub Actionsの更新設定 |

開発・検証・デプロイ手順は[専用README](cadence-xs/README.md)を参照してください。検証はリポジトリルートから`make -C cadence-xs check`で実行します。`cadence-xs-v*`タグによる本番デプロイでは、対象タグの`cadence-xs/`だけを更新します。

## サービスを追加する方針

- 追加・統合はサービスごとのIssueで行い、サービスごとのディレクトリに配置します。具体的な配置は対象Issueで決めます。
- 各サービスのREADME.mdに役割・実行環境・開発・検証・デプロイ手順を記載し、AGENTS.mdに固有の作業ルールを記載します。
- サービスの依存関係・設定・検証・デプロイを個別に管理し、cadence-xsのSlim 4・PDO・Docker・XServerの方針を他サービスの要件にはしません。
- サービスを追加した際は、このREADMEのサービス一覧と構成を更新します。

## リポジトリ名

リポジトリ名は`home-et-cetera`です。Issue #69で、BvlionBatch5専用だった旧名称から家庭内サービス全体を表す名称へ変更しました。Issue #87でBvlionBatch5のサービス名を`cadence-xs`へ変更し、実装・設定を同名ディレクトリへ移しました。XServerの実行基盤は維持します。Issue #92で、デプロイ対象を`cadence-xs/`に限定し、リリースタグを`cadence-xs-v*`へ変更しました。

旧リポジトリ名を使用しているcloneでは、`origin`を`https://github.com/bvlion/home-et-cetera.git`へ更新してください。本番checkoutも同じ対象ですが、更新は利用者が既存の配置で行います。checkout先・公開先・GitHub Secretsは維持します。Issue #87の配置変更では、`.env`の配置、公開用`index.php`のリンク先、既存ローカルDBの引き継ぎが影響を受けます。[専用README](cadence-xs/README.md)の切り替え手順を参照してください。

## 開発運用

共通ルールは[AGENTS.md](AGENTS.md)、cadence-xs固有のルールは[専用AGENTS.md](cadence-xs/AGENTS.md)を参照してください。

- 1つのIssueにつき、1つのブランチと1つのPRを作成します。
- PRはユーザーの明示的な承認を得るまでマージしません。
- Issueに記載された範囲を最小差分で実装し、明示されていない横展開は行いません。

`cadence-xs CI`はcadence-xs専用のworkflowです。mainへのpushとすべてのPull Requestで`test`ジョブを起動し、`cadence-xs/`または`.github/workflows/cadence-xs-ci.yaml`に変更がある場合だけ既存の`make check`を実行します。ルートREADMEのみ・他サービスのみの変更では重い検証をスキップし、変更判定が成功すれば`test`も成功します。変更判定や検証の失敗はジョブの失敗となります。main rulesetの`Protect main`に設定済みのGitHub Actionsの必須チェック`test`と一致するため、管理設定の変更は不要です。他サービスのCIと必要な必須チェックは、そのサービスを統合するIssueで追加します。

`cloud-glance CI`もmainへのpushとすべてのPull Requestで `cloud-glance-test` を起動します。cloud-glanceまたは専用workflowの変更時だけ検証し、関係しない変更では重い検証をスキップしてチェックを成功させます。Issue #88に従い、workflowのmainへのマージ後に `Protect main` の必須チェックへ `cloud-glance-test` を追加します。設定手順は[専用README](cloud-glance/README.md)を参照してください。

`cloud-concierge CI`もmainへのpushとすべてのPull Requestで固定名 `cloud-concierge-test` を起動します。サービスまたは専用workflowの変更時だけ、移行元と同じNode.js 24・npm install・cache・lintを実行します。無関係な変更でも変更判定の成功でチェックを成功させます。Issue #84に従い、`Protect main` の必須チェックへ固定名 `cloud-concierge-test` を登録済みです。

## Publicリポジトリの運用

pi-stewardは移行元の公開可能な現行snapshotだけを取り込み、privateリポジトリのGit履歴を継承しません。検証は `npm --prefix pi-steward ci` と `npm --prefix pi-steward test` で実行します。既存のRaspberry Pi / systemd / cron運用を維持し、移行に伴うdeploy CIは追加しません。

- 本番値、個人情報、秘密情報をコミットしません。
- Issue、PR、テスト、fixture、ログ、SQLにも実データを含めません。
- 認証情報、Webhook、実際のチャンネルID、実際のメール内容、本番で使用する通知文面、データベースの本番値は、リポジトリ外の安全な場所で管理します。
- サンプルやテストには、実データから生成していない架空の値を使用します。
