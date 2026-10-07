# pi-steward

Raspberry Pi上でFirebase Realtime Databaseの変更を受け、床暖房のPCスイッチ操作、モニター切替、カーテン、朝の機器操作、音声通知、2台のSesameの操作を実行します。休日・平日の切替に応じた翌日の通知はroot cronで実行します。

private `bvlion/BvlionBatch3` のPR #19マージ後のmaster、`13a96287b18c86c9f60ed5c452aec18d29299e16` から、公開可能なtracked snapshotだけを取り込みました。Git履歴、`.env`、Firebase認証JSON、ログ、runtime stateは取り込んでいません。移行の判断・検証範囲は[移行記録](docs/issue-85-migration.md)、作業ルールは[AGENTS.md](AGENTS.md)を参照してください。

## 実行環境

- Raspberry Pi 3 Model B Rev 1.2
- Raspberry Pi OS Lite 64-bit / Debian 13 (Trixie)
- Node.js 22（`/usr/local/bin/node`）
- Bash 4以上、`/usr/bin/python3`、systemd、cron、sudo

Node.jsと外部Pythonスクリプトは環境側で用意します。実行基盤、root実行、依存ライブラリ、Realtime Databaseのイベント契約は移行元を維持します。deploy CIは新設せず、Pi上で手動更新します。

モノレポ配置は管理する通常の絶対パスを前提とします。シェル特殊文字を含む配置先では運用せず、そのためのエスケープ・クォート対応や事前拒否の文字種チェックも実装しません。

## ローカル設定

モノレポルートではなく、`pi-steward/`の直下に`.env`と`firebase-adminsdk.json`を置きます。両方ともgitignore対象で、Gitへ追加しません。`.env.example`は空の設定値だけを記載しています。

`pi-steward/`で`.env.example`を`.env`へコピーし、設定管理元の運用値を記入します。FirebaseサービスアカウントJSONは、バックアップまたは認証情報の管理元から同じディレクトリへ配置します。配置後、両ファイルの権限を600にしてください。

```bash
cp .env.example .env
chmod 600 .env firebase-adminsdk.json
```

実値をIssue、PR、テスト、fixture、共有ログへの作業報告に記載しないでください。Piのruntime logにはAPI応答や通知内容が含まれ得るため、そのまま共有しません。

| 設定 | 内容 |
| --- | --- |
| `FIREBASE_DATABASE_URL` | Realtime DatabaseのURL |
| `HOLIDAY_API_URL` | 現在の日付文字列を後ろに付ける休日APIのURL。末尾の区切りも維持 |
| `HOLIDAY_API_AUTHORIZATION` | 休日APIのAuthorizationヘッダー全体。現在のBearer接頭辞も含む |
| `NOTIFIER_URL` | 現在の通知送信先URL |
| `NATURE_REMO_AUTHORIZATION` | Nature RemoのAuthorizationヘッダー全体。現在のBearer接頭辞も含む |
| `NATURE_REMO_MONITOR_1_SIGNAL_ID` / `NATURE_REMO_MONITOR_2_SIGNAL_ID` / `NATURE_REMO_MORNING_SIGNAL_ID` | 現在の各操作の信号ID |
| `SWITCHBOT_TOKEN` / `SWITCHBOT_SECRET` / `SWITCHBOT_CURTAIN_DEVICE_ID` | SwitchBotの認証情報とカーテンのデバイスID |
| `SESAME_1_DEVICE_ID` / `SESAME_1_SECRET` / `SESAME_2_DEVICE_ID` / `SESAME_2_SECRET` | 2台のSesameのIDとデバイス固有secret（現在の16進文字列） |
| `SESAME_API_KEY` / `SESAME_HISTORY_LABEL` | SesameのAPIキーと現在の履歴ラベル |
| `HOLIDAY_NOTIFICATION_VOLUME` / `HOLIDAY_NOTIFICATION_TYPE` | 翌日通知の現在の音量と通知種別 |
| `HOLIDAY_NOTIFICATION_HOLIDAY_TEXT` / `HOLIDAY_NOTIFICATION_WORKDAY_TEXT` | 翌日通知の現在の文面 |
| `PC_SWITCH_SCRIPT_PATH` / `PC_SWITCH_DEVICE_ID` | 外部Pythonスクリプトの絶対パスと床暖房のデバイスID |

`.env`はNode.js 22の`process.loadEnvFile`で読み込みます。Node.jsの書式に従い、各値を引用符で囲んでください。通知文面などは現在の文字列をそのまま維持します。プロセスに既に設定されている環境変数は`.env`より優先されます。

`index.js`と`holiday_notification.js`は作業ディレクトリによらず、自身のあるpi-stewardディレクトリの`.env`を読み込みます。必要な値が空の場合は設定名を示して終了します。`PC_SWITCH_SCRIPT_PATH`と`PC_SWITCH_DEVICE_ID`も`index.js`の起動時必須設定として検証し、未設定・空の場合はRealtime Database監視を開始しません。検証済みの値を`pc_switch`イベントで`shells/pc_switch.sh`へ引数で渡します。

Realtime Databaseの参照先`pi`とイベントキー`pc_switch`、`curtain`、`morning`、`notifier`、`sesame`は、公開してよい構造情報としてコード内に保持します。`閉め`も入力プロトコルのコマンド値として維持します。本番Database URL、機器識別子、実際に送信する通知文面は`.env`で管理します。従来の`FIREBASE_DATABASE_REFERENCE`と各`FIREBASE_*_KEY`の設定は不要です。

## 開発・検証

モノレポルートから実行します。依存関係のoptional除外は`.npmrc`で移行元を維持しています。

```bash
npm --prefix pi-steward ci
npm --prefix pi-steward test
node --check pi-steward/index.js
node --check pi-steward/holiday_notification.js
node --check pi-steward/logger.js
node --check pi-steward/test/dependencies.test.js
bash -n pi-steward/setup.sh
bash -n pi-steward/shells/pc_switch.sh
shellcheck pi-steward/setup.sh pi-steward/shells/pc_switch.sh
git diff --check
```

`npm test`は依存関係と署名の5テストです。認証情報や実機通信は必要ありません。`index.js`や`holiday_notification.js`の通常実行は機器操作・通知を伴うため、構文検査と区別してください。

## 再構築

通常のモノレポ配置例を`/opt/home-et-cetera`とします。以下は配置例であり、本番の固定パスではありません。モノレポをcloneし、`pi-steward/`へ移動してローカル設定と認証JSONを復元します。

```bash
cd /opt/home-et-cetera/pi-steward
npm ci
npm test
sudo ./setup.sh
sudo systemctl start pi-steward.service
sudo systemctl status pi-steward.service
```

`setup.sh`は自身のディレクトリを`pi-steward.service`の`WorkingDirectory`へ展開し、`/etc/systemd/system/pi-steward.service`へ配置します。サービスファイルはテンプレートのため、直接コピーせず`setup.sh`を使用します。`SCRIPT_DIR`はモノレポルートではなく`pi-steward/`の絶対パスです。

root実行、`ExecStart=/usr/local/bin/node index.js`、`Restart=on-failure`、`RestartSec=10`、`KillMode=process`は維持します。root crontabへ毎日23:10の`holiday_notification.js`実行を登録します。同じ配置先で再実行しても重複登録せず、他のcronジョブを維持します。`.env`または認証JSONが欠ける場合はsystemd/cronの変更前に停止します。

`setup.sh`はサービスの開始、Node.jsや依存関係のインストール、apt操作、旧サービスや旧cronの削除を行いません。配置を変える場合は旧cron行を手動で取り除きます。root cronの作業ディレクトリは従来どおりです。設定はスクリプトの配置先から読み込まれ、ログは実行時の作業ディレクトリを基準に出力される既存動作を維持します。

## BvlionBatch3からの切り替え

PR #19マージ後のPiでは既に秘密情報がローカル設定へ分離されています。モノレポ統合PRのマージ後、Piを更新する際は次の順序で切り替えます。23:10のcron実行と重なる時刻は避けます。

1. 旧配置の`.env`、`firebase-adminsdk.json`、`monitor_state.json`、systemd設定、root crontabをリポジトリ外へバックアップします。外部Pythonスクリプトとその依存環境も維持できることを確認します。
2. モノレポを通常の配置先へcloneし、`pi-steward/`へ`.env`と認証JSONをコピーします。権限600を確認し、`npm ci`と`npm test`を実行します。設定名と値はPR #19のものをそのまま使います。外部Pythonスクリプトを移す場合だけ`PC_SWITCH_SCRIPT_PATH`を更新します。
3. `sudo systemctl stop BvlionBatch3.service`、`sudo systemctl disable BvlionBatch3.service`で旧サービスを停止・無効化します。`sudo crontab -e`で旧配置の`holiday_notification.js`を呼ぶ23:10の行だけを削除し、他ジョブを維持します。
4. 旧サービス停止後の最新の`monitor_state.json`を`pi-steward/`へコピーします。存在しない場合は新規環境と同じ初期化動作になります。既存状態がある場合は引き継いでください。
5. 新配置の`pi-steward/`で`sudo ./setup.sh`、`sudo systemctl start pi-steward.service`を実行します。
6. 新サービスのactive・enabled、root実行、実配置のWorkingDirectory、ExecStart、再起動条件と、root cronの新配置23:10ジョブが1件であることを確認します。旧サービスはinactive・disabled、旧cron行は0件であることを確認します。
7. 通常運用で機器操作・通知・状態保存を確認し、旧配置とバックアップは切り替え確認が完了するまで保持します。

旧サービスと新サービスを同時起動すると、同じRealtime Databaseイベントに二重反応します。旧サービスの停止と旧cron行の削除を新サービス起動前に行ってください。

切り戻す場合は、新サービスを停止・無効化し、新配置のcron行だけを削除します。停止後の最新のモニター状態を旧配置へ戻し、バックアップした旧cron行を復元し、旧サービスを有効化・起動します。二重起動を避ける順序は切り替え時と同じです。

移行後の更新はモノレポを更新し、`pi-steward/`で`npm ci`、`npm test`を実行してから`sudo systemctl restart pi-steward.service`で行います。配置・サービス設定を変更した場合は再起動前に`sudo ./setup.sh`を再実行します。

## 外部ローカル依存

床暖房のPCスイッチ操作にはリポジトリ外の`switchbot_py3.py`が必要です。別途配置し、`.env`の`PC_SWITCH_SCRIPT_PATH`へ絶対パスを設定します。デバイスへの`press`を実行し、出力に`Connected`が含まれるまで再試行する現行動作を維持します。

`shells/pc_switch.sh`は外部Pythonスクリプトパスを第1引数、デバイスIDを第2引数として受け取ります。呼び出し側が`.env`を読み、引数配列で渡します。シェル自身は`.env`を読みません。引数が欠けるか空の場合はPython実行前に終了します。

`.env`、認証JSON、外部Pythonスクリプトと依存環境はGitだけでは再構築できません。安全なバックアップを別途保持してください。`monitor_state.json`はモニター切替のruntime state、`logs/`はローカルログです。いずれも公開対象にしません。

## 実機確認とarchive判断

[Issue #85の確定コメント](https://github.com/bvlion/home-et-cetera/issues/85#issuecomment-6030557385)では、PR #19マージ後の旧配置でsystemd / cron、service active、PCスイッチとモニターの一連動作、通知、Sesameを確認済みとしています。カーテン、morning処理、翌日通知cronは通常運用で確認し、追加の強制実行は行いません。

この確認は移行元の実機確認です。改名後のモノレポ配置でのPi切り替えは別の運用作業であり、実施・確認結果が記録されるまで「切り替え済み」とは扱いません。切り替え時は上記のサービス・cron・状態引き継ぎを確認し、機能確認は確定済みの通常運用方針を維持します。

旧privateリポジトリは、モノレポPRのマージ、Piの配置・サービス・cronの切り替え確認、ローカル設定・認証JSON・外部Python依存・必要なstateのバックアップ、旧配置への運用依存の解消を記録した後にarchive可能です。切り替え前の現時点ではarchiveを保留します。archive後も旧秘密情報がGit履歴に残るためprivateを維持します。archive操作自体はIssue #85のスコープ外です。
