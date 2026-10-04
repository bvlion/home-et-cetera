# AGENTS.md

このファイルの共通ルールはリポジトリ全体に適用します。

## リポジトリの目的とサービスの分離

- このリポジトリは、複数の個人・家庭内向けサービスを管理するモノレポです。
- 各サービスの実行基盤・言語・アーキテクチャ・デプロイ方式はサービスごとに管理します。cadence-xsの技術・運用方針を他サービスへ適用しません。
- サービスの追加・統合は、それぞれのIssueで明示された範囲で行います。
- 追加サービスはサービスごとのディレクトリへ配置し、そのREADME.mdとAGENTS.mdに役割・実行環境・作業ルールを記載します。具体的な構成は対象Issueで決めます。

## cadence-xsのルールの適用範囲

cadence-xsに関する作業では、必ず[専用AGENTS.md](cadence-xs/AGENTS.md)を読み、共通ルールと併せて適用してください。

専用ルールは`cadence-xs/`配下の実装・設定・検証・関連文書と、次のリポジトリ共通配置にあるcadence-xs用の設定に適用します。

- `.github/workflows/cadence-xs-ci.yaml`、`.github/workflows/cadence-xs-deploy.yaml`
- `.github/dependabot.yml`のComposer設定

他サービスのディレクトリや、そのサービス専用の設定・検証・デプロイにはcadence-xs専用ルールを適用しません。サービスごとのAGENTS.mdを参照してください。

## cloud-glanceのルールの適用範囲

cloud-glanceに関する作業では、必ず[専用AGENTS.md](cloud-glance/AGENTS.md)を読み、共通ルールと併せて適用してください。

専用ルールは`cloud-glance/`配下の実装・設定・検証・関連文書と、次のリポジトリ共通配置にあるcloud-glance用の設定に適用します。

- `.github/workflows/cloud-glance-ci.yaml`、`.github/workflows/cloud-glance-deploy.yaml`
- `.github/dependabot.yml` の cloud-glance 用 npm 設定

他サービスのディレクトリや、そのサービス専用の設定・検証・デプロイにはcloud-glance専用ルールを適用しません。サービスごとのAGENTS.mdを参照してください。

## 判断と実装

- 既存リポジトリや具体的な実装が情報源として示された場合は、対象コードを確認してから判断します。
- 対象コードを確認できない場合は、その旨を明示します。
- 対象コードから確認した事実と一般論を区別し、混同しません。
- 実装を確認していない段階で一般論を述べる場合は、「一般的な作りに寄せるなら」と明示します。
- 将来の拡張を想定した先行実装は行いません。
- Issueの完了条件を満たす最小差分で実装します。
- 同一Issueの依頼範囲を、類似箇所へ勝手に横展開しません。

## ブランチとPR

- 1 Issue・1 branch・1 PRを原則とします。
- Issueごとに専用ブランチを作成します。
- 1つのPRへ複数Issueの変更を混在させません。
- PRは原則としてCreate a merge commitで取り込みます。
- ユーザーの明示的な承認を得るまでPRをマージしません。
- PRは、作業途中で共有する必要がある場合を除き、Draftにしません。

## Public運用

- 本番値、個人情報、秘密情報をリポジトリへ記録しません。
- Issue、PR、テスト、fixture、ログ、SQLにも実データを含めません。
- 認証情報、Webhook、実際のチャンネルID、実際のメール内容、本番で使用する通知文面、データベースの本番値を公開しません。
- サンプル、fixture、テストデータには、実データから生成していない架空の値を使用します。
- 秘密情報や個人情報をPublicなIssue、PR、コミット、共有ログへ掲載しません。
