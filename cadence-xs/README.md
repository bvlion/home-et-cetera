# cadence-xs

この文書はcadence-xs専用の機能・開発・運用手順です。コマンドとファイルパスは、特記がない限り`cadence-xs/`を基準とします。リポジトリ全体の目的と構成は[ルートREADME](../README.md)を参照してください。

cadence-xsは、XServer上で動作する認証付きHTTP APIを提供するプロジェクトです。

旧サービス名はBvlionBatch5です。PHPの名前空間は`Bvlion\CadenceXs`です。

リポジトリルートから作業する場合は、最初に`cd cadence-xs`でサービスディレクトリへ移動してください。Git操作と`.github/`の設定はリポジトリ全体を対象とします。

## 提供する機能

- メールの取得・Slack通知・振り分け
- 記念日通知

## 技術方針

- WebアプリケーションフレームワークにはSlim 4を使用します。
- データベースアクセスにはPDOを使用します。
- APIはHTTPリクエスト内で処理を完了する同期処理とします。
- `/health`エンドポイントは実装しません。
- 不要な抽象化、DIコンテナ、基底Repository、過剰なClean Architectureは導入しません。

本番実行環境と配置構成は[本番実行環境](docs/production-environment.md)を参照してください。

## 環境設定

`.env.example`を`.env`としてコピーし、実行環境に合わせて値を設定します。

```shell
cp .env.example .env
```

| 環境変数 | 用途 |
| --- | --- |
| `APP_TIMEZONE` | アプリケーションのタイムゾーン |
| `DB_HOST` | データベースのホスト |
| `DB_PORT` | データベースのポート |
| `DB_NAME` | データベース名 |
| `DB_USER` | データベースのユーザー名 |
| `DB_PASSWORD` | データベースのパスワード |
| `SLACK_BOT_TOKEN` | Slack Bot Token |
| `SLACK_TEST_CHANNEL_ID` | Slack疎通確認時に使用するチャンネルID |
| `IMAP_HOST` | IMAPサーバーのホスト |
| `IMAP_PORT` | IMAPサーバーのポート |
| `IMAP_USERNAME` | IMAP接続のユーザー名 |
| `IMAP_PASSWORD` | IMAP接続のパスワード |
| `SCHEDULER_BEARER_TOKEN` | メール処理・記念日通知用Bearer Token |

`SLACK_TEST_CHANNEL_ID`を除く環境変数は、アプリ本体の動作に必須です。本番値は`.env`または実行環境の環境変数で注入し、コミットしません。設定が未指定または空の場合は、秘密値を含めず、該当する環境変数名を示してリクエスト処理を失敗させます。`SLACK_TEST_CHANNEL_ID`はアプリ本体では使用せず、後述の「実チャンネルへの疎通確認」でのみ使用します。

## データベースとマイグレーション

ローカル開発ではMySQL 5.7コンテナを使用します。データベースを起動し、未適用のマイグレーションを適用します。

```shell
docker compose up --detach database
docker compose run --rm app composer migrate
```

マイグレーションSQLは`database/migrations`へ`YYYYMMDDHHMMSS_description.sql`形式で配置します。ファイル名の昇順で適用し、適用済みファイル名を`schema_migrations`テーブルへ記録するため、同じコマンドを再実行できます。

本番環境では、環境変数と依存関係を設定した後、XServerのPHP 8.5.5を明示して適用します。

```shell
/opt/php-8.5.5/bin/php bin/migrate.php
```

本番データ、データベースダンプ、個人情報、秘密情報はリポジトリへ配置しません。`dating`、`mail_api`、設定、メール処理履歴の各テーブルは、対応する後続Issueでマイグレーションを追加します。

## Slack App設定

cadence-xs専用のSlack App・Botを1つだけ使用します。mail・datingの2機能は、同一の`SLACK_BOT_TOKEN`で投稿します。機能ごとに別のApp・Bot・Tokenは作成しません。

本番Slack Appは、次の手順で設定します。

1. Slack Appの「Basic Information」で、Botの表示名とアイコンを設定します。
2. 「OAuth & Permissions」のBot Token Scopesへ`chat:write`・`chat:write.customize`・`files:write`を追加します。`chat:write.public`は追加しません(投稿先チャンネルへは、後述のとおりBotを明示的に招待して参加させるため不要です)。`files:write`は、HTML本文を持つメールをPDF化してSlackへ添付するために使用します(詳細は[メール処理API](#メール処理api)を参照)。
3. Slack Appを対象のワークスペースへインストール、またはScope変更後は再インストールします。Bot Token ScopeはSlack Appの認可情報に紐づくため、`chat:write.customize`や`files:write`をSlack App設定へ追加しただけでは既存のBot Tokenへ反映されません。「OAuth & Permissions」画面で再インストール(reinstall)を実行してScopeを反映させてください。再インストールしても既存のBot Tokenが必ず新しい値に変わるとは限らないため、実行後に「OAuth & Permissions」画面で現在のBot Tokenを確認し、本番環境の`SLACK_BOT_TOKEN`と一致しているか確認してください(一致していない場合のみ差し替えます)。
4. 通知先とする各チャンネルへSlack Appを招待し、参加させます(`chat:write.public`を使わないため、Botが参加していないチャンネルへは投稿できません)。
5. Bot Tokenを本番環境の`SLACK_BOT_TOKEN`へ設定します。
6. 通知先はチャンネルIDで管理し、実際のチャンネル名、チャンネルID、Bot Tokenをリポジトリや共有ログへ記録しません。

通常投稿にはSlack Web APIの[`chat.postMessage`](https://docs.slack.dev/reference/methods/chat.postMessage)を、HTMLメールのPDF投稿には[`files.getUploadURLExternal`](https://docs.slack.dev/reference/methods/files.getUploadURLExternal)・[`files.completeUploadExternal`](https://docs.slack.dev/reference/methods/files.completeUploadExternal)を使用します。

- datingは、`channel`と`text`だけを指定する通常投稿です。表示名・アイコンはリクエストで上書きせず、Botそのものの表示名・アイコンで投稿されます。
- mailのプレーンテキスト投稿は、`channel`・`text`に加えて`username`・`icon_url`を指定するカスタム投稿です。旧BvlionBatch4のメール転送元表示(送信元ごとの表示名・アイコン)を復元するため、`chat:write.customize`スコープを使ってメールルールごとに投稿の表示名とアイコンを上書きします。このカスタム表示は、家族内で利用する閉じたワークスペースにおけるメール転送元の識別が目的であり、人間へのなりすましを意図したものではありません。
- mailのPDF投稿(HTML本文を持つメール)も、同じ`channel_id`・`username`・`icon_url`を`files.completeUploadExternal`へ指定し、プレーンテキスト投稿と同じ表示名・アイコンでファイルを共有します。詳細は[メール処理API](#メール処理api)を参照してください。

### Bot Token漏洩時のローテーション

Bot Tokenが漏洩した、または漏洩した疑いがある場合は、次の手順でcadence-xs専用Bot単位にローテーションします。実際のTokenやチャンネルIDはいかなる記録にも残しません。

1. Slack Appの管理画面で、cadence-xs用Bot Tokenを失効させます。
2. cadence-xs用Slack Appを再認可し、新しいBot Tokenを発行します。
3. 本番環境の`SLACK_BOT_TOKEN`を新しいTokenへ差し替えます(mail・datingの2機能は同じ環境変数を参照しているため、差し替えは1箇所で完了します)。
4. 通知先とする各チャンネルへ、cadence-xs用Slack App・Botが参加した状態のままであることを確認します(`chat:write.public`を使わない構成のため、参加していないチャンネルには投稿できません)。
5. `bin/check-slack.php`でSlackへの疎通を確認します(「実チャンネルへの疎通確認」節を参照)。

実チャンネルへの疎通確認には`SLACK_BOT_TOKEN`と`SLACK_TEST_CHANNEL_ID`だけを使用します。DB、IMAP、Bearer Tokenなど、アプリ本体の他の環境変数は不要です。

ローカルでは、Git管理対象外の`.env`へ2つの値だけを設定し、Docker経由で実行します。次の値は実データから生成していない架空値のため、実行時は実際のBot Tokenと確認先チャンネルIDへ置き換えます。

```dotenv
SLACK_BOT_TOKEN=xoxb-example-bot-token
SLACK_TEST_CHANNEL_ID=C0000000000
```

```shell
docker compose run --rm --no-deps app php bin/check-slack.php
```

本番環境では、`.env`または実行環境へ同じ2つの環境変数を設定し、XServerのPHP 8.5.5を明示して実行します。

```shell
/opt/php-8.5.5/bin/php bin/check-slack.php
```

このコマンドはテスト用文面`Slack API connectivity test.`を1件投稿します。成功時はToken、チャンネルID、メッセージの`ts`を出力せず、`Slack connectivity check succeeded.`だけを表示します。

## 記念日通知

`dating`テーブルで通知日、通知文面、投稿先チャンネルIDを管理します。マイグレーションには本番値を含めません。通知設定には次の列を使用します。

| 列 | 用途 |
| --- | --- |
| `target_date` | 毎年通知する`MMdd`、または100日単位で通知する`yyyyMMdd` |
| `message` | 通知文面。`yyyyMMdd`の場合は`%s`へ経過日数を挿入 |
| `channel_id` | 投稿先のSlackチャンネルID |

`POST /api/dating/notify`は、日本時間の当日を基準に該当データを判定します。8桁の`yyyyMMdd`が未来の日付の場合は通知対象外です。同じチャンネルに複数の該当データがある場合は、文面を改行でまとめて1件投稿します。該当データがない場合は投稿しません。処理はリクエスト内で同期実行し、正常終了時はHTTP 204を返します。

## API認証

認証対象ルートと使用するBearer Tokenは次のとおりです。

| ルート | 環境変数 |
| --- | --- |
| `POST /api/mail/process` | `SCHEDULER_BEARER_TOKEN` |
| `POST /api/dating/notify` | `SCHEDULER_BEARER_TOKEN` |

各ルートの実装時に`BearerTokenMiddleware`をルートミドルウェアとして登録し、`Authorization: Bearer <token>`ヘッダーを検証します。ヘッダーが未指定、形式不正、またはトークンが一致しない場合は、トークン値をレスポンスへ含めずHTTP 401を返します。

## メール検索

`mail_api`テーブルの`enable_flag = 1`であるルールを取得し、`target_from`をINBOX内の送信者または件名へ、大文字小文字を区別せず部分一致させます。検索結果としてメッセージのUIDとINBOXのUIDVALIDITYだけを返し、送信者、件名、本文はログや検索結果へ含めません。

ローカルのappコンテナにはPECL IMAP 1.0.3を導入します。IMAP over SSL/TLSのポート993を使用します。手動疎通確認ではINBOXを読み取り専用で参照し、メール処理APIではSlack投稿が成功するまで既読化しません。

ローカルから実IMAPへの疎通を確認する場合は、Git管理対象外の`.env`へIMAP用の4つの環境変数を設定し、Docker経由で次を実行します。

```shell
docker compose run --rm --no-deps app php bin/check-imap.php
```

このコマンドによる実IMAP接続は読み取り専用であり、メールの既読化、移動、削除を行いません。
接続に失敗した場合は、パスワード、ユーザー名、メールアドレス、ホスト名などの設定値を除去した診断情報を標準エラーへ表示します。診断情報を取得できない場合は、`IMAP connection failed.`だけを表示します。

本番接続を確認する場合は、Git管理対象外の`.env`または実行環境へIMAP用の4つの環境変数だけを設定し、XServerのPHP 8.5.5を明示して次を実行します。

```shell
/opt/php-8.5.5/bin/php bin/check-imap.php
```

成功時は`IMAP connectivity check succeeded.`だけを出力します。接続、検索、フォルダ参照に失敗した場合は、メールサーバー、アカウント、フォルダ、メールの実値を含めず、それぞれ異なるエラーで終了コード1を返します。この確認はメールの移動、削除、既読化を行いません。

メール件名はMIMEエンコードと文字コードを処理してUTF-8へ変換します。本文はmultipartを再帰的に探索し、quoted-printableまたはbase64をデコードした`text/plain`を優先して取得します。ファイル名または添付指定があるパートは本文として扱いません。`text/plain`本文はSlack投稿で推奨される長さに合わせて先頭4,000文字へ制限します。同様にmultipartを再帰的に探索し、添付ではない`text/html`本文もContent-Transfer-Encodingとcharsetを処理してUTF-8へ取得します(こちらは後述のPDF化に使うため文字数を制限しません)。`text/plain`のみのメールはHTML本文が空文字列になり、`text/html`のみのメールは本文(`text/plain`)が空文字列になります。

## メール処理履歴

メール処理履歴は、個人情報を含まないメールボックス識別子、UIDVALIDITY、UIDの組み合わせで一意に管理します。Slack投稿済みと処理完了を別に記録し、Slack投稿後にメール移動だけ失敗した場合は、保存済みの識別子を使って再投稿せず移動だけを再試行できる状態にします。この識別子は、プレーンテキストメールの通常投稿では`chat.postMessage`が返すメッセージtimestampですが、HTMLメールのPDF投稿では`files.completeUploadExternal`のレスポンスにメッセージtimestampが含まれないため、代わりに同レスポンスが返すSlackのfile ID(`files[0].id`)を保存します。どちらの場合も、値の意味に関わらず「このメールはSlackへ投稿済みである」ことを示す識別子としてのみ扱い、既存のカラム・再試行ロジックをそのまま利用します。件名、本文、送信者、メールアドレスは保存しません。

完了済み履歴は完了から90日を過ぎたものだけを削除対象とします。Slack投稿済み・移動未完了の履歴は、二重投稿防止のため完了するまで自動削除しません。

## メール処理API

`mail_api`テーブルでは、有効なメール処理ルールを次の列で管理します。

| 列 | 用途 |
| --- | --- |
| `target_from` | 送信者または件名へ部分一致させる文字列 |
| `to_folder` | INBOXからの移動先フォルダ |
| `channel_id` | 投稿先のSlackチャンネルID。`NULL`の場合はSlack投稿を行わない |
| `user_name` | Slack投稿の表示名(`chat:write.customize`の`username`) |
| `icon_url` | Slack投稿のアイコン画像URL(`chat:write.customize`の`icon_url`) |
| `prefix_format` | `user_name`の末尾へ付加する受信日時の書式。空文字列の場合は付加しない |
| `enable_flag` | ルールの有効状態 |

`POST /api/mail/process`は、有効なルールを登録順に処理します。対象メールの件名と本文を整形し、旧BvlionBatch4のメール転送元表示を復元した`username`・`icon_url`とともにSlackへ投稿します。投稿成功後に既読化して指定フォルダへ移動し、INBOXから削除します。Slack投稿に失敗したメールは移動しません。Slack投稿後の移動に失敗した場合は、処理履歴に基づいて次回の実行時にSlackへ再投稿せず、既読化と移動だけを再試行します。

メールがHTML本文を持つかどうかで投稿方法を分けます。

- HTML本文を持たないプレーンテキストのみのメールは、従来どおり件名と`text/plain`本文を整形したテキストを`chat.postMessage`で通常投稿します。
- HTML本文を持つメール(プレーンテキストと両方を持つ場合を含む)は、HTML本文を`Bvlion\CadenceXs\Mail\HtmlToPdfConverter`(Dompdf)でPDF化し、件名を紹介文とするファイルとしてSlackへ添付します。プレーンテキスト本文はこのケースでは投稿に使用しません。

PDF化・PDF投稿の詳細は次のとおりです。

- Dompdfはリモート画像・外部CSS・Webフォントの取得を無効化(`isRemoteEnabled = false`)し、JavaScriptも実行しません(`isJavascriptEnabled = false`)。HTML内の`cid:`画像は対応するinline MIMEパートから、`http` / `https`画像はcadence-xs側で取得してdata URIへ置換してからDompdfへ渡します。それ以外のスキームは取得しません。
- 外部画像の取得前には名前解決結果がすべて公開IPアドレスであることを確認し、検証済みIPアドレスへ接続先を固定します。localhost、プライベートIP、リンクローカル、予約済みIPへのアクセスは拒否し、最大3回のリダイレクト先にも同じ検証を行います。接続タイムアウトは3秒、リダイレクトを含む1画像全体のタイムアウトは10秒です。取得対象は実データも画像と判定できる`image/gif`、`image/jpeg`、`image/png`、`image/webp`に限定し、1画像2,000,000バイト、メール全体4,000,000バイトを上限とします。取得または検証に失敗した画像は置換せず、本文のPDF化を継続します。
- PDF内へ画像を描画するため、PHPのGD拡張を必須とします。
- 日本語本文を表示するため、`resources/fonts/IPAexGothic`に同梱したIPAexゴシック(TrueType、IPAフォントライセンスv1.0)をDompdfへ登録します。XServerにインストール済みのフォントには依存しません。CFFアウトラインを持つOpenType(`.otf`)フォントはDompdfでの埋め込みが不安定なため使用せず、TrueType(`.ttf`)フォントのみを同梱しています。
- Dompdfはブラウザと異なり、指定フォントにグリフがない場合の自動フォールバックを行わないため、メール本文のHTML/CSSがどのような`font-family`を指定していても(`!important`や高い詳細度を伴う場合を含む)、必ずIPAexゴシックが選択されるようにしています。CSSへ上書きルールを注入して詳細度・`!important`で競う方式ではなく、Dompdf自身が解決しうる全フォント名(`sans-serif`・`serif`・`helvetica`・`times`等、`vendor/dompdf/dompdf/lib/fonts/installed-fonts.dist.json`が持つ既定の全ファミリー名)をIPAexゴシックへ登録し直すことで、メール側がどの名前を指定してもDompdfの解決結果がIPAexゴシック以外になり得ないようにしています。それ以外の未知のフォント名は、Dompdfの既定フォールバック(`Options::setDefaultFont()`、これもIPAexゴシックに設定)へ渡ります。
- HTML本文・生成したPDFはメモリ上でのみ扱い、ディスクへの一時ファイル書き出し、ログへの出力、永続保存を行いません。
- HTMLメールのPDF変換時には、PHPのエラーログへメールUID、件名、受信日時、HTMLサイズ、各画像のURLまたはContent-ID、名前解決結果、HTTP応答・リダイレクト、MIME判定、取得サイズ、処理結果、例外情報をJSON形式で記録します。これは画像取得失敗を調査するための運用ログであり、ログファイルをリポジトリへ配置・共有しません。
- HTML本文の取得段階(`Bvlion\CadenceXs\Mail\MimeMessageDecoder`)とDompdfへ渡す直前(`Bvlion\CadenceXs\Mail\HtmlToPdfConverter::MAX_HTML_BYTES`、5,000,000バイト、約4.8MiB)の両方でサイズを制限します。Dompdfは入力HTMLサイズによって数倍〜十数倍のメモリを使用することがあり、実行環境のPHP `memory_limit`も未確認のため、5,000,000バイトという値はDompdfのレンダリングが安全であることを保証するものではなく、メモリ枯渇や実行時間超過のリスクを抑えるための運用上の上限です。
  - `MimeMessageDecoder`は、HTMLパートを`imap_fetchbody()`で取得する前にIMAPが宣言するパートサイズ(`part->bytes`、RFC 3501でtext系パートに必須の項目)を確認し、超過時は本文取得自体を行わずに失敗させます。取得後も、base64/quoted-printableのデコード前後、UTF-8への文字コード変換後の各段階でバイト数を確認します。転送エンコード状態(base64・quoted-printableでかさ増しされた状態)の上限は、base64の4/3倍やquoted-printableの再現しにくい増加を考慮し、最終的なUTF-8 HTMLの上限(5,000,000バイト)より大きい値(4倍)を用い、デコード後・変換後は最終上限(5,000,000バイト)で確認します。宣言サイズが取得できない場合は、無制限扱いにはせず失敗させます。
  - いずれの段階で上限を超えた場合も、HTMLを途中で切って不完全なPDFを生成することはせず、そのメール1件だけを本文・秘密情報を含まない`RuntimeException`で失敗させ、既読化・移動・完了記録を行わずに後続の対象メール処理を継続します。
- IPAexゴシックの登録は、`FontMetrics::registerFont()`(呼び出しごとにフォント本体をディスクへ書き出す)を1回だけ行い、上記の全フォント名(14ファミリー×normal/bold/italic/bold-italicの4書体=56通り)は、その1回で生成された同じキャッシュ済みフォント・メトリクスを参照する別名として`FontMetrics::setFontFamily()`(`installed-fonts.json`という小さなJSONの参照情報だけを保存し、フォント本体やメトリクスファイルは複製しない)へ設定します。`registerFont()`をフォント名の数だけ呼ぶと、内容が同一でも呼び出しごとに新しいコピーがディスクへ書き出されるため、この方式でIPAexゴシック本体(約6MB)の重複コピーを防いでいます。
- Slackへのアップロードは、廃止済みの`files.upload`ではなく、次の3工程で行います。
  1. `files.getUploadURLExternal`でアップロードURLとfile IDを取得します。
  2. 取得したURLへPDFバイナリを`POST`します(この工程はSlack Bot Tokenを使用しません)。
  3. `files.completeUploadExternal`で対象チャンネルへ共有します。`channel_id`・`initial_comment`(件名を紹介する投稿本文)に加え、プレーンテキスト投稿と同じ`username`・`icon_url`を指定し、`chat:write.customize`スコープで表示名・アイコンを上書きします。
- アップロードするファイル名は固定値(`mail.pdf`)とし、IMAP UID・メールアドレスなど個人情報につながる値を含めません。
- 3工程のいずれかが失敗した場合はメール全体をSlack投稿失敗として扱い、既読化・移動・処理履歴の更新を行いません(`failure_count`に加算)。

`channel_id`が`NULL`のルールに一致したメールは、Slack投稿(および表示名・アイコンの生成)を行わずに既読化・フォルダ移動・処理完了記録だけを行います。

Slack投稿時の表示名は、旧BvlionBatch4の`Mail#getSlackUserName()`と同じ規則で生成します。`prefix_format`が空文字列の場合は`user_name`のみ、空でない場合は`user_name`の末尾へメール受信日時を`Asia/Tokyo`で整形した文字列を付加します。`prefix_format`はJava/Apache Commons `FastDateFormat`(`java.text.SimpleDateFormat`相当)のパターンであり、PHPの`DateTimeInterface::format()`とは記号の意味が異なるため、`Bvlion\CadenceXs\Mail\LegacyDateFormatConverter`が旧パターンのトークンを直接解釈し、日時文字列を組み立てます(PHPの書式文字列へ変換して`format()`に渡す方式ではありません)。受信日時は`imap_fetch_overview()`が返す`udate`(IMAPサーバーのINTERNALDATE)から取得します。旧`receivedDate`と同じ値であり、メールヘッダーの`Date`(送信日時)とは意味が異なるため使用しません。`prefix_format`が空でないにもかかわらず受信日時を取得できない場合は、旧BvlionBatch4の`MailUtil#getSlackUserName()`と同じく`user_name`のみへフォールバックし、そのままSlack投稿・既読化・フォルダ移動を継続します(メール処理自体は失敗させません)。

処理はHTTPリクエスト内で同期実行し、Bearer Tokenには`SCHEDULER_BEARER_TOKEN`を使用します。応答はHTTP 200のJSONで、全メールの処理結果と失敗件数を返します。

```json
{
  "success": true,
  "failure_count": 0
}
```

## 旧環境データの移行

旧環境のデータを本番DBへ移行する手順は[旧環境データの移行](docs/legacy-data-migration.md)を参照してください。実データ・認証情報はリポジトリへ含めず、移行専用の作業ディレクトリで完結させます。

## ローカル実行

ローカルPCにはDockerのみ必要です。PHPとComposerをローカルPCへインストールする必要はありません。

appコンテナをビルドし、依存関係をインストールします。

```shell
docker compose build app
docker compose run --rm app composer install
```

Docker Composeでアプリケーションを起動します。

```shell
docker compose up app
```

ブラウザで`http://127.0.0.1:8080/undefined-route`へアクセスすると、JSON形式の404エラーが返ります。

アプリケーションを停止します。

```shell
docker compose down
```

構文確認、コーディング規約の確認、テストは次のコマンドで実行します。

```shell
docker compose run --rm app composer lint
docker compose run --rm app composer style
docker compose run --rm app composer test
```

Dockerはローカル開発でのみ使用します。本番環境はDocker化せず、XServerのPHP 8.5.5を使用します。詳細は[本番実行環境](docs/production-environment.md)を参照してください。

## 検証環境（`make check`）

構文確認・コーディング規約の確認・マイグレーション適用・テストを、開発環境から分離された検証専用のCompose環境で一括実行できます。

```shell
make check
```

- `make check`は`compose.check.yaml`を検証専用のCompose project（`cadence-xs-check`）で実行します。開発用`compose.yaml`が使うcontainer・network・volume・host port（8080番）とは別のprojectであり、開発用の`database` volumeを共有しません。検証用DBは検証専用の使い捨てvolumeを使用します。
- 検証用のappコンテナは実`.env`を読み込みません。`.env.example`の架空値だけを`/app/.env`へread-onlyで重ね、Composeの変数展開にも`--env-file .env.example`を使用します。
- 成功・失敗にかかわらず、`make check`終了時に検証専用project（`cadence-xs-check`）のcontainer・network・volumeだけをcleanupします。開発中の`compose.yaml`側のcontainer・DBには一切影響しません。
- GitHub Actions（リポジトリルートの`.github/workflows/ci.yaml`）も`cadence-xs/`で同じ`make check`を使用します。リポジトリルートからは`make -C cadence-xs check`を実行します。

`make check`が異常終了してcleanupが行われなかった場合は、検証専用projectだけを対象に手動でcleanupできます。

```shell
make check-clean
```

### 配置変更後の既存ローカル環境

開発用Compose project名は`cadence-xs`に固定し、`make db-wipe`も同じprojectだけを対象にします。旧配置のproject名は、明示指定がなければcheckoutディレクトリ名に依存していました。旧container・network・volumeは自動で移行・削除しません。

利用者は既存の`.env`を`cadence-xs/.env`へ安全に移し、権限を維持してください。旧開発環境を使用している場合は、既存projectと`database` volumeを確認し、必要なデータを非公開の場所へバックアップしてから、旧環境をvolumeを残して停止し、新しいprojectへ復元してください。旧環境が8080番を使用したままでは新環境を起動できません。project名を変更しただけでは既存DBデータは引き継がれず、新しい`cadence-xs_database` volumeが作られます。DB名などの設定は既存`.env`を引き継ぎます。

`vendor` volumeも別になるため、新配置で`composer install`を実行してください。旧volumeは復元・動作確認が済むまで保持し、削除を移行手順に含めません。既存projectの明示指定や`COMPOSE_PROJECT_NAME`を継続すると固定名を上書きするため、通常の開発と`db-wipe`の対象を`cadence-xs`へ合わせてください。

## 開発用データベースの永続化と初期化

`database` volumeにより、通常の`docker compose down`（`--volumes`を付けない停止）やコンテナの再作成では開発用DBデータは失われません。開発用DBのデータを完全に削除する場合だけ、明示的な破壊操作用コマンドを使用します。

```shell
make db-wipe CONFIRM=yes
```

`CONFIRM=yes`を指定しない場合はエラーで停止し、削除を実行しません。このコマンドは開発用の`database` volumeだけを対象とし、appコンテナや検証用Composeプロジェクト（`make check`）には触れません。削除後にDBを再作成する場合は、「データベースとマイグレーション」節の手順を再実行してください。

起動中の開発用Docker環境（container・network・volume）の再作成・削除、開発用`database` volumeの削除、実`.env`・実データベース・Slack/IMAPなど実外部サービスへの接続は、利用者の明示的な許可なしに行いません。事故・中断・確認事項が発生した場合は、日本語で具体的に報告します。

## 本番デプロイ

`cadence-xs-v*`形式のGitタグ(例: `cadence-xs-v1.0.0`)をpushすると、GitHub Actions(`.github/workflows/deploy.yaml`)がそのタグの指すcommitから`cadence-xs/`だけをXServer本番環境へ自動デプロイします。他サービス用のタグ、従来の`v*`タグ、`main`へのpushやPull Requestではデプロイされません。初回の環境構築(「初回デプロイ」節)は引き続き手動で行いますが、以降の更新デプロイは`cadence-xs-v*`タグのpushだけで完了します。詳細は「自動デプロイ(`cadence-xs-v*`タグpush)」節を参照してください。

### 配置の考え方

- ドメインのドキュメントルート(`public_html`)は固定であり、変更できません。ここには公開してよいファイルだけを置きます。
- Gitリポジトリは、アカウントホーム配下かつ`public_html`外へ配置し、作業ツリーには`cadence-xs/`だけを反映します。以下ではcheckoutのルートを`<checkout-directory>`、サービス本体の`<checkout-directory>/cadence-xs`を`<app-directory>`と表記します。アプリ本体(`bootstrap`、`src`、`vendor`、`composer.*`、`database`、`bin`、`.env`など)は`<app-directory>`内にあります。実際の絶対パスはリポジトリへ記載しません。
- `public_html`には次の2つだけを配置します。
  - `index.php`: `<app-directory>/public/index.php`へのシンボリックリンク
  - `.htaccess`: `<app-directory>/public/.htaccess`をコピーした通常ファイル
- 実機で確認できたのは、`public_html`内のシンボリックリンク経由で`public_html`外のPHPファイルを実行した場合に、PHPの`__DIR__`が実体側のディレクトリで解決されることだけです。`.htaccess`をシンボリックリンクにした場合にApacheがRewriteや`Authorization`ヘッダー転送を同様に処理するかは確認していないため、`.htaccess`はシンボリックリンクにせず、`public_html`直下へ通常ファイルとして配置します。この方式(方式A)により、`public/index.php`はコード変更なしでそのまま利用できます。

### 初回デプロイ

1. SSHでログインし、`<checkout-directory>`を作成してcheckoutせずにリポジトリを取得します。`<release-tag>`には、このデプロイ方式を含む`cadence-xs-v*`形式のリリースタグを指定し、`cadence-xs/`だけを反映します。

    ```shell
    mkdir -p <checkout-directory>
    cd <checkout-directory>
    git clone --no-checkout https://github.com/bvlion/home-et-cetera.git .
    git fetch origin tag <release-tag>
    git restore --source=<release-tag> --staged --worktree -- cadence-xs/
    git reset --mixed --quiet <release-tag>
    ```

2. 専用のComposerを、検証済みチェックサムでcadence-xs専用の非公開ツールディレクトリへ配置します。共有Composerは使用・更新しません。Composerの公式インストーラー検証手順に沿って、ダウンロード・SHA-384検証・インストール・後始末を1つのスクリプトで実行します。処理全体をサブシェル`( ... )`で囲んでいるため、途中で失敗しても現在のSSH接続(親シェル)は終了しません。ツールディレクトリの絶対パスは、貼り付け後の対話プロンプトで入力します(コマンド内に埋め込みません)。

    ```shell
    (
        set -euo pipefail

        read -rp "Composerを配置する専用ツールディレクトリの絶対パスを入力してEnter: " TOOLS_DIRECTORY
        mkdir -p "$TOOLS_DIRECTORY"
        cd "$TOOLS_DIRECTORY"

        COMPOSER_SETUP_FILE="composer-setup.php"
        trap 'rm -f "$COMPOSER_SETUP_FILE"' EXIT

        EXPECTED_SIGNATURE="$(/opt/php-8.5.5/bin/php -r "echo file_get_contents('https://composer.github.io/installer.sig');")"
        if [ -z "$EXPECTED_SIGNATURE" ]; then
            echo 'ERROR: Could not retrieve the expected Composer installer signature.' >&2
            exit 1
        fi

        if ! /opt/php-8.5.5/bin/php -r "exit(copy('https://getcomposer.org/installer', '$COMPOSER_SETUP_FILE') ? 0 : 1);"; then
            echo 'ERROR: Could not download the Composer installer.' >&2
            exit 1
        fi

        if [ ! -f "$COMPOSER_SETUP_FILE" ]; then
            echo 'ERROR: Composer installer file is missing after download.' >&2
            exit 1
        fi

        ACTUAL_SIGNATURE="$(/opt/php-8.5.5/bin/php -r "echo hash_file('sha384', '$COMPOSER_SETUP_FILE');")"
        if [ -z "$ACTUAL_SIGNATURE" ]; then
            echo 'ERROR: Could not compute the Composer installer signature.' >&2
            exit 1
        fi

        if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]; then
            echo 'ERROR: Composer installer signature mismatch.' >&2
            exit 1
        fi

        /opt/php-8.5.5/bin/php "$COMPOSER_SETUP_FILE" --install-dir="$TOOLS_DIRECTORY" --filename=composer.phar
    )
    COMPOSER_SETUP_STATUS=$?
    if [ "$COMPOSER_SETUP_STATUS" -ne 0 ]; then
        echo 'Composer setup failed. See the error above before continuing.' >&2
    fi
    ```

    `trap`により、成功・失敗のいずれでも`composer-setup.php`は削除されます。署名取得失敗、ダウンロード失敗、ファイル不在、署名計算失敗、署名不一致、Composerインストール失敗のいずれかが起きた場合はサブシェル内で非ゼロの終了コードとなり、インストールへは進みません。`COMPOSER_SETUP_STATUS`が`0`であることを確認してから次の手順へ進んでください。

3. 本番用の依存関係をインストールします。開発用パッケージを含めず、`composer.lock`の内容どおりに導入します。`<tools-directory>`には、手順2で入力したツールディレクトリと同じ絶対パスを指定してください。

    ```shell
    cd <app-directory>
    /opt/php-8.5.5/bin/php <tools-directory>/composer.phar install --no-dev --optimize-autoloader --classmap-authoritative
    ```

    `--optimize-autoloader --classmap-authoritative`は、デプロイのたびに`composer install`を実行する運用と整合するため採用します。コードの変更後に依存関係を更新し忘れると、追加したクラスが読み込めなくなる点に注意してください。

4. `.env`を配置します。値は`.env.example`をコピーし、この文書の「環境設定」節の一覧に従って本番値を設定します。実際の値はコミットしません。

    ```shell
    cp .env.example .env
    chmod 600 .env
    ```

5. `public_html`側に、公開してよい2ファイルだけを配置します。`index.php`はシンボリックリンク、`.htaccess`は通常ファイルとしてコピーします。`public_html`内に他のファイルは置きません。

    ```shell
    ln -s <app-directory>/public/index.php <public_html-directory>/index.php
    cp <app-directory>/public/.htaccess <public_html-directory>/.htaccess
    ```

6. 権限を設定します。一般的な作りに寄せるなら、ディレクトリは755、ファイルは644、`.env`は600を目安にします。この権限で本番のHTTP実行環境から読み取れるかは、後述の「疎通確認」で確認してください。想定と異なるエラーが出た場合は、実際の実行ユーザー・グループに合わせて調整が必要です。具体的な必要権限は`docs/production-environment.md`に記載がなく、本手順書だけでは確定できません。

7. マイグレーションを適用します(「データベースとマイグレーション」節を参照)。

    ```shell
    /opt/php-8.5.5/bin/php bin/migrate.php
    ```

8. 「/healthに依存しない疎通確認」を実施します。

### 自動デプロイ(`cadence-xs-v*`タグpush)

`cadence-xs-v*`形式のタグをpushすると、GitHub Actions(`.github/workflows/deploy.yaml`)が次を自動実行します。

1. 本番の`<checkout-directory>/cadence-xs`内にtracked変更がないことを確認します。ある場合は上書きせずデプロイを失敗させます。ルートファイルなど、サービス外の変更・削除は確認対象にしません。
2. pushされたタグをfetchし、そのタグが最終的に指すcommit(軽量タグ・annotated tagのいずれでも同じ結果になります)がrunnerで確定したcommitと一致することを確認します。`git restore --staged --worktree`でそのcommitの`cadence-xs/`だけを反映し、対象タグで削除されたサービス内のtrackedファイルも削除します。その後、`git reset --mixed --quiet`でHEADとindexをデプロイ済みcommitへ合わせます。このresetは作業ツリーのファイルを書き換えません。他のパスは更新・復元・削除せず、実行時点の`origin/main`も使用しません。
3. `<checkout-directory>/cadence-xs`へ移動し、`DEPLOY_COMPOSER_PATH`のcadence-xs専用Composerと`/opt/php-8.5.5/bin/php`を使い、`composer.lock`に基づいて`--no-dev --optimize-autoloader --classmap-authoritative`で本番依存をインストールします。
4. `/opt/php-8.5.5/bin/php bin/migrate.php`で未適用マイグレーションを適用します。
5. `<app-directory>/public/.htaccess`を`public_html`側へ上書きコピーします。`index.php`のシンボリックリンクは初回作成時のものを再利用します。
6. runner上の`cadence-xs/bin/check-deploy-connectivity.sh`で2つのAPIへ未認証POSTを送り、すべてHTTP 401であることを確認します。1件でも401以外の場合はworkflow全体を失敗させます(「/healthに依存しない疎通確認」節の「1. 未認証確認」を参照)。

本番の`.env`はworkflowから作成・コピー・上書き・削除しません。既存の`.env`をそのまま使用します。SSHの秘密鍵・接続先・絶対パス・本番URLなどの値は、いずれもGitHub Secretsから取得し、リポジトリへは記録しません。

#### 通常のリリース手順

リリースしたいcommitへタグを作成してpushするだけで、そのcommitの`cadence-xs/`が本番へ反映されます。

```shell
git tag cadence-xs-v1.0.0
git push origin cadence-xs-v1.0.0
```

#### 必要なGitHub Secrets

自動デプロイを使うには、事前に次のGitHub Secretsをリポジトリへ登録します。

| Secret | 用途 |
| --- | --- |
| `DEPLOY_SSH_HOST` | XServerのSSH接続先ホスト |
| `DEPLOY_SSH_PORT` | SSH接続ポート |
| `DEPLOY_SSH_USER` | SSH接続ユーザー名 |
| `DEPLOY_SSH_PRIVATE_KEY` | cadence-xsデプロイ専用のSSH秘密鍵 |
| `DEPLOY_SSH_KNOWN_HOSTS` | 接続先のhost keyを検証するためのknown_hostsエントリ |
| `DEPLOY_PATH` | 本番リポジトリcheckoutのルート(`<checkout-directory>`)の絶対パス。`cadence-xs`は付けない |
| `DEPLOY_COMPOSER_PATH` | 本番に配置済みのcadence-xs専用Composer(`composer.phar`)の絶対パス |
| `DEPLOY_PUBLIC_PATH` | 公開ディレクトリ(`public_html`)の絶対パス |
| `DEPLOY_BASE_URL` | デプロイ後の未認証疎通確認に使用する本番URL |

`DEPLOY_PUBLIC_PATH`はIssue #60に列挙されていた既存Secret一覧には含まれていない追加のSecretです。`DEPLOY_PATH`はドキュメントルート外にあるcheckoutのルートの絶対パスであり、「配置の考え方」節のとおり公開ディレクトリ(`public_html`)とは別の場所にあるため、`DEPLOY_PATH`から`public_html`の絶対パスを安全に推測して組み立てることはできません。`cadence-xs/public/.htaccess`を`public_html`側へ反映するために、公開ディレクトリの絶対パスを保持する専用のSecretとして追加しています。

#### 旧ルート配置からの初回切り替え（Issue #87・利用者側の作業）

この配置変更を含むタグを通常どおりpushする前に、利用者が本番の切り替えを行ってください。既存checkoutの絶対パス、専用Composerのパス、公開先、URL、GitHub Secrets、Slack App・Bot、DB、APIの認証値は継続利用します。サービス名変更だけを理由に再作成する必要はありません。

1. 既存checkoutと公開用`index.php`のリンク先を確認し、非公開の場所へ既存`.env`を権限600でバックアップします。旧配置の安定commitも記録します。切り替え中は旧`public/index.php`が消えるため、停止を伴う作業時間を確保します。
2. tracked変更がないことを確認し、配置変更を含むリリース対象commitをfetchしてcheckoutします。`git reset --hard`や`git clean`は使用しません。
3. 旧checkoutルートの`.env`を`<checkout-directory>/cadence-xs/.env`へ安全に移し、権限600と本番設定を維持します。移行先に既存`.env`があれば上書きせず確認します。環境変数注入で運用している場合は、その設定が新配置の実行でも有効であることを確認します。
4. `<app-directory>`で「初回デプロイ」の手順3・7に従い、既存専用Composerによる依存関係の導入とマイグレーションを実行します。旧ルートの`vendor`は新配置から参照されません。
5. 公開用`index.php`が既存シンボリックリンクであることを確認してから、リンク先を`<app-directory>/public/index.php`へ張り替えます。通常ファイルや想定外のリンクなら上書きせず停止します。`.htaccess`は新しい`<app-directory>/public/.htaccess`から通常ファイルとしてコピーします。
6. 「/healthに依存しない疎通確認」に従い、未認証の2 APIが401を返すことと、利用者が許可した機能確認を行います。以後の更新は`cadence-xs-v*`タグpushで行います。

自動デプロイは`.env`の移動と`index.php`のリンクの張り替えを行いません。旧リンクのままでは未認証疎通確認が失敗します。旧配置のcommitへ戻す場合は、旧ルートの`.env`・そのcommitの依存関係・公開リンク・`.htaccess`も旧配置へ戻してください。旧DBデータの再importは、この配置変更には不要です。

SSH host key verificationは`DEPLOY_SSH_KNOWN_HOSTS`を使って必ず有効な状態で行い、`StrictHostKeyChecking=no`等での無効化は行いません。

#### 不要ファイルの初回削除（Issue #92・利用者側の作業）

既存環境では、利用者が初回のみ不要なルートのモノレポ管理用ファイル（ルートの`README.md`、`AGENTS.md`、`.github/`など）や旧配置由来の残存物を確認して削除します。`<checkout-directory>/.git`はタグのfetchとデプロイ済みcommitの記録に必要なため残します。稼働中の`cadence-xs/`（`.env`、`vendor`を含む）、専用Composer、公開ディレクトリと`index.php`のリンクも維持します。自動デプロイは不要ファイルを削除せず、削除済みのサービス外ファイルを復元しません。

サービス外のtrackedファイルを削除すると、リポジトリ全体の`git status`には削除が表示されます。この状態でも自動デプロイは成立します。以後、本番でリポジトリ全体をcheckoutすると不要ファイルが再配置されるため、サービスだけを反映する手順を使用してください。

#### 初回設定(利用者側の作業)

1. cadence-xsデプロイ専用のSSH鍵ペアを作成します。
2. 作成した公開鍵を、XServer側の対象アカウントのSSH認証(`~/.ssh/authorized_keys`)へ登録します。
3. 上記の必要なGitHub Secretsをすべて登録します。
4. `cadence-xs-v*`形式のタグを作成・pushし、GitHub Actionsのデプロイが成功することを確認します。

### 失敗時にどこまで戻すか

- **アプリコード**: `git log`や過去のデプロイ実行結果で直前の安定commitを確認します。`<checkout-directory>`で`cadence-xs/`内にtracked変更がないことを確認し、`git restore --source=<直前のcommit> --staged --worktree -- cadence-xs/`と`git reset --mixed --quiet <直前のcommit>`でサービスだけを戻します。その後、そのcommit時点の`composer.lock`に合わせて`composer install`を再実行し、`.htaccess`もコピーして未認証疎通確認を行います。旧ルート配置へ戻す場合は、前述のIssue #87の注意事項に従います。
- **マイグレーション**: `bin/migrate.php`にロールバック機能はありません。マイグレーション適用後に問題が起きた場合は、データベースのバックアップからの復元、または追加のマイグレーションでの是正を検討し、適用済みのマイグレーションファイルは変更しません。
- **公開ファイル**: `public_html`側は`index.php`のシンボリックリンクと`.htaccess`の通常ファイルの2つだけのため、問題が起きた場合は`index.php`のリンクを削除する、または`.htaccess`を退避すれば公開を止められます。

### ログの扱い

- 現在のアプリケーションは、独自のログファイルを生成しません。`bootstrap/app.php`はSlimのエラーミドルウェアをログ出力無効(`logErrors: false`)で登録しており、Monolog等のロギングライブラリも導入していません(`composer.json`で確認済み)。
- CLIコマンド(`bin/migrate.php`、`bin/check-slack.php`、`bin/check-imap.php`)の実行結果・エラーは、標準出力または標準エラーへ出力されるだけで、ファイルへは書き込みません。
- HTTPアクセスの記録や、PHP/Apacheレベルの未捕捉エラーは、アプリケーションではなくXServer側のアクセスログ・エラーログで確認します。ただし、そのログの具体的な保存先(絶対パス)は、`docs/production-environment.md`を含め今回確認できた事実の範囲には含まれていません。**推測でパスを記載することはできないため、XServerのサーバーパネルまたは公式ドキュメントで確認してください。** 確認できた内容は、必要に応じて`docs/production-environment.md`へ追記することを想定しています。
- Bearer Token、Slack Bot Token、IMAPパスワードなどの秘密情報、メール本文・メールアドレスなどの個人情報を、独自ログへ保存する実装は存在しません。既存の疎通確認コマンド(`bin/check-slack.php`、`bin/check-imap.php`)も、失敗時の診断情報から設定値そのものを除去する実装になっています(「Slack App設定」「メール検索」節を参照)。

### HTMLメールPDF変換のローカル確認

保存したHTMLメールを既存の`HtmlToPdfConverter`へ直接渡して、PDFと画像処理ログを確認できます。

```shell
/opt/php-8.5.5/bin/php bin/convert-html-to-pdf.php <html-file> [pdf-file]
```

`pdf-file`を省略すると、入力ファイル名へ`.pdf`を付加したパスへ出力します。外部画像の取得、取得失敗時のfallback、画像処理ログはメール処理時と同じ変換処理を使用します。出力先は標準出力に表示され、画像処理ログはPHPのエラーログで確認します。

### /healthに依存しない疎通確認

`/health`エンドポイントは実装しません。疎通確認は次の手順で行います。

#### 0. 外部サービス疎通の補助確認(任意・推奨)

API疎通確認の前に、Slack・IMAPそれぞれの外部サービスへの到達性を単体で確認しておくと、問題が起きた際の切り分けに役立ちます。

```shell
/opt/php-8.5.5/bin/php bin/check-slack.php
/opt/php-8.5.5/bin/php bin/check-imap.php
```

それぞれ専用の環境変数だけを使用し、既存のAPIやデータベースには触れません(詳細は「Slack App設定」「メール検索」節を参照)。

#### 1. 未認証確認

2つのAPIすべてが、Authorizationヘッダーなしで401を返すことを確認します。これで確認できるのは、ルーティングとBearer Token認証による拒否が機能していることです。`Authorization`ヘッダーが`.htaccess`のRewriteによってPHPまで到達しているかどうかは、この時点では確認できません(ヘッダーがまったく転送されていなくても、未指定の場合と同じ401になるためです)。ヘッダー転送の確認は、後述の「認証済み確認」で正しいBearer Tokenを使ったリクエストが成功することによって行います。

`cadence-xs-v*`タグによる自動デプロイでは、デプロイ完了後にこの確認を`bin/check-deploy-connectivity.sh`が自動実行し、2件のいずれかが401以外の場合はGitHub Actions全体を失敗させます。このスクリプトはBearer Tokenを一切使用せず、Slack投稿・IMAP処理・DB更新などの副作用も発生させません。GitHub Actions専用ではなく、ローカルやSSH先から手動実行する場合にも同じスクリプトを再利用できます。

```shell
bin/check-deploy-connectivity.sh https://<domain>
# または
DEPLOY_BASE_URL=https://<domain> bin/check-deploy-connectivity.sh
```

内部では次と同等のcurlリクエストを送り、2件とも`HTTP 401`であることを確認します(いずれか1件でも401以外なら非ゼロ終了します)。

```shell
curl -i -X POST https://<domain>/api/mail/process
curl -i -X POST https://<domain>/api/dating/notify
```

#### 2. 認証済み確認

この確認は`cadence-xs-v*`タグによる自動デプロイには含まれません。正規のBearer Tokenを使った本番APIの実行、`bin/check-slack.php`による実際のSlack投稿、IMAPでのメール既読化・移動などの副作用を伴う確認は、デプロイのたびに自動実行せず、必要な場合だけ利用者が手動で行います。

副作用(実際のSlack投稿・メールの既読化や移動)を発生させない状態を用意したうえで、正規のBearer Tokenを付与して確認します。正しいBearer Tokenを付与したリクエストが期待どおりの応答を返すことにより、`Authorization`ヘッダーが`.htaccess`のRewriteを経由してPHPまで到達していることも合わせて確認できます。

- **記念日通知**: `dating`テーブルに該当データがない状態(初回デプロイ直後など)で実行し、投稿が発生せずHTTP 204が返ることを確認します。

    ```shell
    curl -i -X POST https://<domain>/api/dating/notify \
      -H "Authorization: Bearer <SCHEDULER_BEARER_TOKEN>"
    ```

- **メール処理**: `mail_api`テーブルに`enable_flag = 1`の行がない状態で実行します。有効なルールが1件もない場合、IMAPへ接続せずに即座に応答するため、IMAP接続やSlack投稿を発生させずに確認できます。

    ```shell
    curl -i -X POST https://<domain>/api/mail/process \
      -H "Authorization: Bearer <SCHEDULER_BEARER_TOKEN>"
    ```

    `{"success":true,"failure_count":0}`とHTTP 200を確認します。

## 開発運用

- 1つのIssueにつき、1つのブランチと1つのPRを作成します。
- PRはユーザーの明示的な承認を得るまでマージしません。
- Issueに記載された範囲を最小差分で実装し、明示されていない横展開は行いません。

## Publicリポジトリの運用

- 本番値、個人情報、秘密情報をコミットしません。
- Issue、PR、テスト、fixture、ログ、SQLにも実データを含めません。
- 認証情報、Webhook、チャンネルID、メール内容、通知文面、データベースの本番値は、リポジトリ外の安全な場所で管理します。
- サンプルやテストには、実データから生成していない架空の値を使用します。
