# IPAex Gothic (IPAexゴシック)

`ipaexg.ttf`は、[IPAフォント](https://moji.or.jp/ipafont/)配布元(情報処理推進機構(IPA))が公開する`IPAexフォント Ver.004.01`から取得したTrueTypeフォントです。

XServer本番環境にインストール済みのフォントへ依存せず、HTMLメールをPDF化する際に日本語本文を確実に表示するため、`BvlionBatch5\Mail\HtmlToPdfConverter`がこのフォントをDompdfへ登録して使用します。

Dompdfが使用する`dompdf/php-font-lib`はTrueType(`glyf`アウトライン)フォントの埋め込みを前提としており、CFFアウトラインを持つOpenType(`.otf`)フォントを埋め込むと、PDFビューアーによってはグリフが正しく表示されないことを確認しています(本ディレクトリにはOTFフォントを配置しません)。

ライセンスは同梱の`IPA_Font_License_Agreement_v1.0.txt`([IPAフォントライセンスv1.0](https://moji.or.jp/ipafont/ipa-font-license/))に従います。同ライセンスは、フォントをそのままの状態で複製・再配布すること、およびデジタル・ドキュメント・ファイル(PDF等)へエンベッドして頒布することを認めています。
