# pi-steward 作業ルール

リポジトリ共通の[AGENTS.md](../AGENTS.md)と併せて適用します。

## 役割と実行環境

- Raspberry Pi上でRealtime Databaseの`pi`参照先の変更を監視し、家庭内機器の操作と通知を実行します。翌日通知はroot cronの毎日23:10に実行します。
- Node.js 22、Raspberry Pi OS Lite 64-bit / Debian 13、Bash、systemd、cron、sudo、外部Pythonスクリプトを使用します。
- root実行、`/usr/local/bin/node`、再起動条件、既存イベント契約と同期操作を維持します。他サービスの実行基盤・設計・デプロイ方式を適用しません。
- 移行を理由にdeploy CIを追加しません。Piへの切り替え・更新はREADMEの手順で行います。

## 変更範囲

- Issueで指定された局所的な最小差分のみ実装し、機能追加、不要なリファクタリング、既存不具合の同時修正を行いません。
- `pc_switch`、`curtain`、`morning`、`notifier`、`sesame`と`閉め`は公開してよい入力プロトコルです。変更しません。
- `PC_SWITCH_SCRIPT_PATH`と`PC_SWITCH_DEVICE_ID`は起動時必須設定です。欠落・空値ではDatabase監視を開始しません。イベント時だけの遅延検証へ変更しません。
- 配置先は管理する通常の絶対パスを前提とします。特殊文字対応のエスケープ・クォートや、未対応パスを拒否する文字種検査を追加しません。
- `setup.sh`はサービス配置・有効化・cron登録を行い、サービス開始や旧配置の削除は行いません。旧サービス停止・旧cron除去・state引き継ぎはREADMEの順序を維持します。

## Public運用

- `.env`と`firebase-adminsdk.json`は`pi-steward/`直下のGit追跡対象外ローカルファイルです。権限600で扱い、実値を公開しません。
- `.env.example`は設定名と空値だけを保持し、説明はREADMEへ集約します。通知文面、認証情報、家庭固有endpoint・機器識別子はローカル設定で管理します。
- ログ、`monitor_state.json`、外部Pythonスクリプト、バックアップを公開リポジトリへ追加しません。runtime logには本番値が含まれ得るため、そのままIssue、PR、共有ログへ掲載しません。
- private移行元のGit履歴を取り込みません。旧リポジトリはarchive後もprivateを維持します。
- テスト・fixtureは実データから生成していない架空の値を使用します。

## 検証

- READMEの`npm ci`、既存5テスト、Node.js/Bash構文検査、ShellCheck、差分検査を実施します。
- 機器・Database・通知を操作する確認は、通常のテストと区別して記録します。
- `pi-steward CI`はNode.js 22で既存テスト・構文検査・ShellCheckを実行します。機器操作やデプロイは行いません。
- 移行元実機の確認、模擬環境の確認、改名後のPi切り替え確認を混同しません。
