# Issue #85 移行記録

2026-10-07時点の[Issue #85](https://github.com/bvlion/home-et-cetera/issues/85)本文・コメントと、private `bvlion/BvlionBatch3` の現行masterを確認した結果です。実値は記載しません。

## 移行元と履歴

- 移行元はPR #19マージ後のmaster `13a96287b18c86c9f60ed5c452aec18d29299e16`。GitHubのmaster、PR #19のmerge commit、取得したsnapshotの参照先が一致しています。
- 認証JSONが旧履歴で追跡され、PR #19で追跡から外されたことを確認しました。旧Git履歴は公開不可のため取り込みません。
- private側で`git archive`によりtrackedファイルだけを抽出し、監査後のsnapshotを新規追加しました。private側のremote・commit・branchの履歴をPublic側へfetch・merge・cherry-pickしていません。
- ローカル`.env`、認証JSON、ログ、`monitor_state.json`、外部Pythonスクリプト、検証用fixtureは取り込んでいません。`logs/.gitkeep`は空ディレクトリ保持用です。

## 現役機能と変更範囲

| 入口 | 現役処理 |
| --- | --- |
| Realtime Database `pi/pc_switch` | 外部Pythonによる床暖房のPCスイッチ操作、Nature Remoのモニター交互切替、状態保存 |
| `pi/curtain` | SwitchBotカーテンの開閉。`閉め`の入力契約を維持 |
| `pi/morning` | 曜日・休日API・強制設定に基づくカーテンまたはNature Remo操作 |
| `pi/notifier` | 入力を通知先へ送信。区切りと通知パラメータを維持 |
| `pi/sesame` | 2台のSesameへ署名付き操作を送信 |
| root cron、毎日23:10 | 現在と翌日の休日・強制設定を確認し、切替時に翌日通知 |

実行コード、設定契約、既存テストの8ファイル（`index.js`、`holiday_notification.js`、`logger.js`、`shells/pc_switch.sh`、`test/dependencies.test.js`、`.env.example`、`.gitignore`、`logs/.gitkeep`）は移行元とbyte単位で一致しています。lockfileは名称2箇所のみ変更し、依存関係全体が一致しています。

サービステンプレートとsetupのサービス名を`pi-steward.service`へ変更し、配置先は従来どおりsetupのディレクトリから生成します。package metadata・`.npmrc`の旧名称・旧リポジトリ参照を更新しました。Dependabotは移行元の週次・日本時間09:00の設定を維持し、対象を`/pi-steward`へ変更しました。ユーザー判断により`update-types`を削除し、majorを含むversion updateを同じグループの対象としています。README・AGENTS.mdとルートのサービス一覧・適用範囲を整備しました。

root実行、Node.js 22、Realtime Database、systemd / cronの実行形態、23:10の時刻、同期呼び出し、状態保存、署名、通知の既存挙動を維持します。deploy CIや別基盤への移行は追加していません。

## 公開前監査

- 移行元tracked snapshotの15ファイルを確認しました。公開対象にはコード、依存関係、空の設定例、systemdテンプレート、setup、シェル、既存テスト、公開用文書だけを含めます。移行元READMEは公開モノレポ用に書き直しました。
- ローカル設定23項目のうち識別に有効な文字列、旧認証JSONの秘密鍵・識別情報、旧author情報を含む28値との照合で、移行元snapshot・公開候補に実値の残存はありません。短い数値等は実値との文字列一致だけでは判定せず、設定例が全て空であり、実行コードがローカル設定を参照することを確認しました。
- 文書と共通設定を含む公開候補19ファイルを最終監査し、照合した実値・秘密鍵パターンの残存、本番設定ファイル・認証JSON・runtime state・ログの追加がないことを確認しました。`.env`、認証JSON、state、ログ、node_modulesの5種類のパスがGitの除外対象であることを確認しました。
- Realtime Databaseの参照先・イベントキー、一般の外部APIのURL、入力コマンドはIssueの確定方針に従って公開しています。本番Database URL、家庭固有endpoint、認証情報、機器ID、通知文面はローカル設定へ保持します。
- Piのローカルruntime logの内容を変更する対応は最新コメントでスコープ外とされています。実ログを公開したり、実データからfixtureを生成したりしていません。

## 検証結果

ローカルNode.js v22.17.0・npm 11.4.2を使用しました。追加の模擬検証はリポジトリ外の架空のfixtureで行い、実機通信・機器操作・実際のsystemd / cron変更を行っていません。

| 検証 | 結果・範囲 |
| --- | --- |
| `npm ci`、`npm test` | インストール成功、既存5テスト成功 |
| Node.js構文検査 | 実行コード3ファイルと既存テスト成功 |
| Bash構文検査、ShellCheck | setupとPCスイッチシェルの両方で成功 |
| 差分検査 | `git diff --check`成功 |
| 移行元との動作比較150条件 | PCスイッチ・モニター状態の初期化/交互切替/連続操作、カーテン、morningの平日/休日/強制設定、通知、Sesame3操作・2台の署名、未知イベント、翌日通知の曜日/休日/強制設定、通信失敗・同期シェル失敗が一致 |
| 模擬起動失敗55条件 | 各必須設定の未設定・空、両PC設定欠落、`.env`欠落で、認証JSON読込・Firebase初期化・監視・機器操作前の停止を確認 |
| Node.js標準の`.env`読込 | 架空の`.env`を実際の`process.loadEnvFile`で読み、同じ起動失敗55条件と、正常設定2入口の外部作業ディレクトリからの起動を確認。通信・Firebaseは模擬化 |
| setup比較24条件 | 通常配置3例×cron初期状態4種×2回。サービス名・配置先を正規化して移行元と一致。cron重複防止・他ジョブ維持・旧配置ジョブが自動削除されないことを確認 |
| setup停止3条件 | `.env`欠落、認証JSON欠落、root権限不足でサービス配置前に停止。systemd保存先と権限判定は模擬環境に置換 |
| PCスイッチシェル | 架空のPythonでConnectedまで2回の再試行・引数保持を確認。引数欠落・空の4条件ではPython未実行 |

`npm audit`は移行元と同じ依存関係の`@fastify/busboy`にmoderate 1件（GHSA-gxm5-99cw-xjw9）を報告し、終了コード1でした。依存関係更新は行っていません。最新コメントでスコープ外とされたnotifier異常入力時の未定義変数参照も維持し、今回の正常動作比較にはその異常入力を含めていません。

## 実機・運用・archive

[確定コメント](https://github.com/bvlion/home-et-cetera/issues/85#issuecomment-6030557385)による移行元実機の確認済み範囲は、PR #19マージ後のsystemd / cron、service active、PCスイッチ・モニターの一連動作、通知、Sesameです。カーテン・morning・翌日通知cronは通常運用で確認し、未実行をsnapshot移行のブロッカーにはしません。

改名後のPi切り替えは今回未実施です。名称変更に伴うサービスファイル・systemctl対象・WorkingDirectory・root cronの配置先・モニター状態の引き継ぎと、旧サービス/cronとの二重実行の影響を確認し、[README](../README.md)へ切り替え・切り戻し・再構築手順を記載しました。Piでの切り替え確認を実施したとは報告しません。

| Issue完了条件 | 現時点の扱い |
| --- | --- |
| 現役機能の確認 | 対象コードと入口・処理を確認済み |
| 公開前監査 | snapshotと公開候補を監査済み |
| Git履歴の安全な扱い | private履歴を継承しないsnapshot移行に確定・実施 |
| pi-stewardとして管理 | 専用ブランチに実装・設定・文書を追加し、PRで取り込み対象を記録 |
| 現行基盤・既存挙動の維持 | 実行コード・依存関係の一致と模擬比較で確認 |
| README・AGENTS.md | 役割・設定・検証・再構築・更新・固有ルールを記載 |
| 名称変更の運用影響 | setup生成結果・二重実行・state・外部依存の影響を確認し、手順を記載 |
| 移行後の動作確認 | 移行snapshotのローカル・模擬検証済み。改名後のPi切り替え確認は未実施 |
| archive可否の判断 | 現時点では保留。PRマージ・Pi切り替え確認・バックアップ・旧配置への依存解消後に可能 |

PRのマージ・Pi切り替え・archive操作は行っていません。旧privateリポジトリはarchive後もprivateを維持します。
