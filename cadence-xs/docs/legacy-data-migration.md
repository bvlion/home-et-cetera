# 旧環境データの移行(Issue #15)

旧BvlionBatch4(`dating`・`mail_api`)のデータをcadence-xsの本番DBへ移行する手順です。実データ・認証情報・実際のチャンネル名やIDは、このリポジトリのいかなるファイルにも含めません。

以下の手順はすべて貴方(リポジトリ運用者)が実行してください。このリポジトリのツールは実行主体になりません。本番移行では、エクスポート・インポート・検証・本番マイグレーションのすべての工程をXServer上のPHP 8.5.5 CLI(`/opt/php-8.5.5/bin/php`)で実行しました。

- **エクスポート**(`bin/export-legacy-data.php`)：旧DBへの接続は、XServerから旧GCP MySQLへのSSHトンネルを経由しました。トンネルの接続先ホスト・ポートなど具体的な接続情報はこのドキュメントに記載しません。
- **インポート・検証・本番マイグレーション**(`bin/import-legacy-data.php`・`bin/verify-legacy-migration.php`・`bin/migrate.php`)：本番DBへ書き込む工程のため、同じくXServerのPHP 8.5.5で実行しました。

コマンドとファイルパスは、特記がない限り`cadence-xs/`を基準とします。

## 0. 前提

- 移行対象は `dating`・`mail_api` の2テーブルです。
- `mail_api.channel_id` は本Issueのマイグレーションで `NULL` を許容します。旧環境でSlack投稿がスキップされていた行は、`channel_id = NULL` として移行し、新環境でも同様にSlack投稿だけをスキップします。
- 旧`dating`・旧`mail_api`の主キー(`pk`)は、新環境の`id`へそのまま引き継ぎます。
- `mail_api`の`user_name`・`icon_url`・`prefix_format`(Slack投稿の表示名・アイコン・受信日時書式)は、Issue #44のマイグレーションで追加した列です。`bin/export-legacy-data.php`は元からこの3項目を`mail_api.json`へ含めているため、エクスポートをやり直す必要はありません。Issue #15で本番へ通常import済みの44件へこの3項目だけを安全に補完する手順は、9節を参照してください。
- 2節のスキーマ・集計確認は実施済みです。確認済みの件数は次のとおりです。

  | 項目 | 件数 |
  | --- | --- |
  | `dating` 総件数 | 4 |
  | `mail_api` 総件数 | 44 |
  | `mail_api.enable_flag = 1`(有効) | 43 |
  | `mail_api.enable_flag = 0`(無効) | 1 |
  | 移行後に`channel_id = NULL`となる`mail_api`行 | 31 |

  `bin/import-legacy-data.php`・`bin/verify-legacy-migration.php`はこれらの件数を既定値として検証し、入力ファイルの件数がこれと一致しない場合は`valid: false`としてDBへ書き込みません。

## 1. 作業ディレクトリの準備

リポジトリ外・`public_html`外・Webから公開されない場所に作業ディレクトリを用意します。以降、この場所を `<migration-work-directory>` と表記します。

```shell
mkdir -m 700 <migration-work-directory>
```

このディレクトリの直下に作成するファイルはすべて権限600とし、移行完了後に削除します。

各`bin/*.php`スクリプトへパスを渡す際は、`$HOME`または絶対パスを使用してください。シェルは`--env-file=~/...`のように`--`から始まるオプション引数内の`~`をチルダ展開の対象としないため、`~`を含むパスを指定しても期待どおり展開されません。

## 2. 旧DBのスキーマ・集計確認(参考)

旧DBで次のSQLを実行し、スキーマと集計値(件数のみ)を確認します。実データは出力しません。

```sql
SHOW CREATE TABLE dating;
SHOW CREATE TABLE mail_api;
```

```sql
SELECT
  COUNT(*) AS total_count,
  SUM(pk IS NULL) AS pk_null_count,
  SUM(pk <= 0) AS pk_non_positive_count,
  SUM(target_date IS NULL) AS target_date_null_count,
  SUM(target_date = '') AS target_date_empty_count,
  SUM(message IS NULL) AS message_null_count,
  SUM(message = '') AS message_empty_count
FROM dating;
```

```sql
SELECT
  COUNT(*) AS total_count,
  SUM(enable_flag = 1) AS enable_flag_1_count,
  SUM(enable_flag = 0) AS enable_flag_0_count,
  SUM(enable_flag IS NULL) AS enable_flag_null_count,
  SUM(enable_flag IS NOT NULL AND enable_flag NOT IN (0, 1)) AS enable_flag_other_count,
  SUM(pk IS NULL) AS pk_null_count,
  SUM(pk <= 0) AS pk_non_positive_count,
  SUM(target_from IS NULL) AS target_from_null_count,
  SUM(target_from = '') AS target_from_empty_count,
  SUM(to_folder IS NULL) AS to_folder_null_count,
  SUM(to_folder = '') AS to_folder_empty_count,
  SUM(channel IS NULL) AS channel_null_count,
  SUM(channel = '') AS channel_empty_count,
  SUM(user_name IS NULL) AS user_name_null_count,
  SUM(user_name = '') AS user_name_empty_count,
  SUM(icon_url IS NULL) AS icon_url_null_count,
  SUM(icon_url = '') AS icon_url_empty_count,
  SUM(prefix_format IS NULL) AS prefix_format_null_count,
  SUM(prefix_format = '') AS prefix_format_empty_count,
  SUM(
    channel IS NULL OR user_name IS NULL OR icon_url IS NULL OR prefix_format IS NULL
  ) AS slack_skip_condition_all_rows_count,
  SUM(
    enable_flag = 1
    AND (channel IS NULL OR user_name IS NULL OR icon_url IS NULL OR prefix_format IS NULL)
  ) AS slack_skip_condition_enabled_rows_count
FROM mail_api;
```

## 3. 旧DBからのエクスポート

`<migration-work-directory>/legacy-db.env` を作成します(`docs/legacy-db.env.example` を参考にしてください。値は本番の旧DB接続情報です)。

```shell
chmod 600 <migration-work-directory>/legacy-db.env
```

エクスポートはXServerのPHP 8.5.5(`/opt/php-8.5.5/bin/php`)で実行しました。旧DBはXServerから直接到達できないため、`legacy-db.env`の接続先(`LEGACY_DB_HOST`・`LEGACY_DB_PORT`)には、あらかじめ確立したSSHトンネルのローカル側エンドポイントを指定します。トンネルの確立方法・接続先ホストなど具体的な接続情報はこのドキュメントに記載しません。

```shell
/opt/php-8.5.5/bin/php bin/export-legacy-data.php \
  --env-file=<migration-work-directory>/legacy-db.env \
  --table=dating \
  --output=<migration-work-directory>/dating.json

/opt/php-8.5.5/bin/php bin/export-legacy-data.php \
  --env-file=<migration-work-directory>/legacy-db.env \
  --table=mail_api \
  --output=<migration-work-directory>/mail_api.json
```

DB接続情報は`--env-file`で指定したファイルからのみ読み込みます。プロセス環境変数に同名の変数が存在していても使用しません。指定ファイルが存在しない・読み取れない・必須項目が欠けている場合は、値を含まないエラーメッセージで終了します。

- 出力ファイルは一時ファイルへ書き込んだ後、成功時のみ完成ファイル名へ`rename`されます。権限は作成時から600です。
- 完成ファイル・一時ファイルのいずれかが既に存在する場合はエラー終了し、上書きしません。
- 標準出力には行数と出力先パスのみ表示し、実データは表示しません。

## 4. `migration-settings.json` と `channel_map.json` の作成

いずれも `<migration-work-directory>` に、貴方が手動で作成します(`chmod 600`)。

`migration-settings.json`:

```json
{
  "dating_channel": "<旧Slackチャンネル名>"
}
```

- `dating_channel` は旧`CheckDating`が固定で使用していたチャンネル名です。

`channel_map.json`:

```json
{
  "<旧Slackチャンネル名>": "<新SlackチャンネルID>"
}
```

`mail_api.json` 内の `channel` 値、および `migration-settings.json` の `dating_channel` を解決するために必要な、すべての旧チャンネル名を含めてください。ただし、`mail_api.json`の各行のうち `channel`・`user_name`・`icon_url`・`prefix_format` のいずれかが `null` の行は、`channel` の値が対応表になくてもエラーになりません(その行は`channel_id = NULL`として移行されるため、変換を行わないからです)。

## 5. dry-run

```shell
/opt/php-8.5.5/bin/php bin/import-legacy-data.php \
  --dating=<migration-work-directory>/dating.json \
  --mail-api=<migration-work-directory>/mail_api.json \
  --settings=<migration-work-directory>/migration-settings.json \
  --channel-map=<migration-work-directory>/channel_map.json \
  --dry-run
```

`--dry-run`でもDBへ接続し、`dating`・`mail_api`の既存件数を確認します(書き込みは行いません)。次を確認してください。

- `valid: true`
- `expected_counts.*`の`matches`がすべて`true`(0節の確認済み件数と入力件数が一致していることを表します)
- `all_tables_empty: true` / `can_execute: true`

`valid: false`の場合は `error:` 行を確認し、4節の方針に従って入力ファイルを修正してください。`all_tables_empty: false`の場合は、対象テーブルに既存データが残っています。原因を確認してから進めてください。

## 6. バックアップ

本実行の前に、`mysqldump`で本番DBのバックアップを取得します。本番移行では、`<migration-work-directory>`内にバックアップ取得用の一時スクリプト`backup-production-db.sh`を作成し、`mysqldump`の出力を同ディレクトリ内の`production-before-import.sql`(権限600)として保存しました。この時点では移行作業ディレクトリの外へは移動しません。バックアップの最終的な保管場所への移動、および一時スクリプトの削除は、11節のcleanupで行います。

## 7. 本実行

```shell
/opt/php-8.5.5/bin/php bin/import-legacy-data.php \
  --dating=<migration-work-directory>/dating.json \
  --mail-api=<migration-work-directory>/mail_api.json \
  --settings=<migration-work-directory>/migration-settings.json \
  --channel-map=<migration-work-directory>/channel_map.json
```

- `dating`・`mail_api` のいずれかに既存データがある場合、何も書き込まずに `abort_reason` を表示して終了します。
- 対象テーブルがすべて空の場合のみ、1トランザクション内で全件INSERTします。途中で失敗した場合は自動的にロールバックされ、`executed: false` になります。
- 成功後にこのコマンドを再実行すると、テーブルが空でなくなっているため、必ず中止されます(二重投入は起きません)。

## 8. 検証

```shell
/opt/php-8.5.5/bin/php bin/verify-legacy-migration.php \
  --dating=<migration-work-directory>/dating.json \
  --mail-api=<migration-work-directory>/mail_api.json \
  --settings=<migration-work-directory>/migration-settings.json \
  --channel-map=<migration-work-directory>/channel_map.json
```

次がすべて満たされていることを確認してください。実際の値は一切出力されません。

- `dating.mismatched_count` / `mail_api.mismatched_count` / `dating.order_mismatch_count` / `mail_api.order_mismatch_count` がいずれも `0`
- `dating.required_field_violation_count` / `mail_api.required_field_violation_count` がいずれも `0`
- `mail_api.expected_null_channel_id_count` と `mail_api.actual_null_channel_id_count` が一致(31)
- `mail_api.enabled_count_expected` と `enabled_count_actual` が一致(43)、`disabled_count_expected` と `disabled_count_actual` が一致(1)

## 9. 既存44件への表示用データ補完(Issue #44)

Issue #15により、本番DBの`mail_api`にはすでに44件が通常import済みです。Issue #44で追加した`user_name`・`icon_url`・`prefix_format`の3列は、この44件では`NULL`のままになっています。この節は、44件を削除・再importせず、この3列だけを安全に補完する手順です。まだ通常importを実施していない環境(初回importで3列も同時に入る)では、この節の作業は不要です。

前提として、8節までのマイグレーション適用・通常import・検証が完了していることを確認してください。使用するファイルは3節でエクスポートした既存の`mail_api.json`と、4節で作成した`channel_map.json`です。エクスポート・ファイルの作り直しは不要です。

### 9.1 マイグレーションの適用

Issue #44のマイグレーション(`user_name`・`icon_url`・`prefix_format`列の追加)がまだ適用されていなければ、通常のマイグレーション手順で適用します。

```shell
/opt/php-8.5.5/bin/php bin/migrate.php
```

### 9.2 dry-run

```shell
/opt/php-8.5.5/bin/php bin/backfill-mail-api-display.php \
  --mail-api=<migration-work-directory>/mail_api.json \
  --channel-map=<migration-work-directory>/channel_map.json \
  --dry-run
```

DBへ接続して`mail_api.json`の各行を`id`・`target_from`・`to_folder`・`channel_id`・`enable_flag`でDB側の行と照合しますが、書き込みは行いません。次を確認してください。

- `valid: true`
- `mismatched_count: 0`(基礎データが一致しない行がある場合は、別環境・別exportを誤って指定していないか確認してください)
- `conflict_count: 0`(3列のいずれかにすでに期待値と異なる値が入っている行がある場合は、無条件に上書きせずここで停止します)
- `input_count`と`db_count`が一致していること(`mail_api.json`にない行がDB側に存在する場合も、無条件に上書きせずここで停止します)
- `can_execute: true`
- `planned_update_count`が更新予定件数、`already_set_count`がすでに正しい値が入っている件数です。`user_name`・`icon_url`・`prefix_format`・チャンネルIDなどの実際の値は出力されません。

いずれかの条件を満たさない場合は、`error:`・`abort_reason:`を確認し、原因を解消してから再実行してください。

### 9.3 本実行

dry-runの結果が問題なければ、`--dry-run`を外して本実行します。

```shell
/opt/php-8.5.5/bin/php bin/backfill-mail-api-display.php \
  --mail-api=<migration-work-directory>/mail_api.json \
  --channel-map=<migration-work-directory>/channel_map.json
```

- 対象は3列(`user_name`・`icon_url`・`prefix_format`)の`UPDATE`のみです。行の削除・`TRUNCATE`・再`INSERT`は行いません。
- 更新は1トランザクション内で行い、事前照合に不一致(`mismatched_count`または`conflict_count`が0でない)がある場合は、一部だけ更新せず処理全体を中止します。
- `executed: true`と`updated_count`(実際に更新した件数)を確認してください。
- このコマンドを誤って再実行しても、すでに正しい値が入っている行は`already_set_count`として扱われ、同じ値を再設定するだけの安全な操作になります。3列に期待値と異なる既存値が入っている行がある場合は、9.2と同様に`conflict_count`で検知され、無条件上書きせず処理全体を中止します。

### 9.4 検証

8節と同じ`bin/verify-legacy-migration.php`で、3列を含めて再検証します。

```shell
/opt/php-8.5.5/bin/php bin/verify-legacy-migration.php \
  --dating=<migration-work-directory>/dating.json \
  --mail-api=<migration-work-directory>/mail_api.json \
  --settings=<migration-work-directory>/migration-settings.json \
  --channel-map=<migration-work-directory>/channel_map.json
```

`mail_api.mismatched_count: 0`を確認してください(`user_name`・`icon_url`・`prefix_format`も比較対象に含まれます)。実際の値は出力されません。

## 10. 2機能の本番相当確認

[cadence-xs専用README](../README.md)の各機能節(記念日通知・メール処理)に沿って、本番相当の確認を行ってください。`channel_id`が`NULL`のメール処理ルールに一致するメールは、Slack投稿なしで既読化・移動されることも確認してください。Slack投稿ありのメール処理ルールでは、9節で補完した表示名・アイコン・受信日時がSlackへ反映されることも確認してください。

本番移行では、記念日通知・メール処理の2機能について本番相当確認を完了しています。メール処理では、Slackのカスタム表示名・カスタムアイコン・受信日時の表示、件名・本文の投稿、既読化、日本語フォルダへの移動、および`POST /api/mail/process`の`success: true` / `failure_count: 0`を確認しました。日本語フォルダへの移動は、`imap_mail_move()`へ渡す移動先フォルダ名をUTF7-IMAPへ変換する対応(Issue #46 / PR #47)を適用した状態で確認しています。

## 11. 後片付け

Issue #15の完了条件を満たすためのcleanupです。本番移行・9節のbackfill・10節の本番相当確認は完了していますが、このcleanupは本ドキュメント作成時点ではまだ実施していません。次の順番で行います。

### 11.1 バックアップの退避

`<migration-work-directory>`内の`production-before-import.sql`は削除せず、Webから公開されない別の安全な場所へ移動し、権限600を維持してください。保持期間の判断はこのドキュメントの範囲外です。

```shell
mv <migration-work-directory>/production-before-import.sql <backup-storage-directory>/
chmod 600 <backup-storage-directory>/production-before-import.sql
```

### 11.2 移行用ファイルの削除

`<migration-work-directory>`内の次のファイルを削除します。

- `backup-production-db.sh`(バックアップ取得用の一時スクリプト。移行専用で以後は不要なため削除します)
- エクスポート結果の`dating.json`・`mail_api.json`
- `migration-settings.json`
- `channel_map.json`
- `legacy-db.env`

```shell
rm -f <migration-work-directory>/backup-production-db.sh
rm -f <migration-work-directory>/*.json <migration-work-directory>/legacy-db.env
```

### 11.3 作業ディレクトリの削除

11.1でバックアップを退避し、11.2で移行用ファイルを削除すると`<migration-work-directory>`は空になります。ディレクトリ自体も削除します。

```shell
rmdir <migration-work-directory>
```

### 11.4 SSHトンネルの終了

3節で旧DBへの接続に使用したSSHトンネルを終了します。終了方法は、トンネルを確立した手段に対応する方法(接続に使ったプロセスの停止など)に従ってください。

### このcleanupに含まないもの

- 旧API・旧GCP環境そのものの停止は、本Issueの範囲外です(Issue #18で扱います)。このcleanupを実施した後も、旧API・旧GCP環境は稼働したままで構いません。
- 既存スケジューラーの呼び出し先切り替えは、本Issueの範囲外です(Issue #16で扱います)。

## 失敗時の停止・復旧

- **本実行のトランザクション中の失敗**：自動的にロールバックされます。対象テーブルは実行前の状態(空)のまま残るため、入力ファイルを修正したうえで本実行をやり直せます。
- **本実行の成功後に問題が判明した場合**：`TRUNCATE`による即時のやり直しは案内しません。6節で`mysqldump`により取得したバックアップからの復元を基本とし、具体的な復元コマンドはXServerで実際に利用できる方法に従ってください。
- **2機能の本番相当確認で問題が判明した場合**：問題が解消してから本番相当確認をやり直してください。
