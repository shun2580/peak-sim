# 実アプリ試運転(real-app-trial)

調査日: 2026-11-01(裁定 D36〜D40 と FIX2・schema 0.2 を入れた後の数字に更新)

指示書#03「公開されている OSS の Laravel アプリでの試運転」(T1〜T4)の結果をまとめる文書である。節の構成は、目的と読み方(§0)・選んだアプリ(§1)・方法(§2)・結果(§3)・判定の抜き取り確認(§4)・直した不具合(§5)・提案(§6)・食い違いと質問(§7)・試験ログ(§8)である。試験ログ(§8)だけは、この文書の commit の後に別の commit で追加する。

生の出力(JSON・表)と、対象アプリの clone、集計の道具は、このリポジトリの外に置いた。ここに残すのは集計と、ツールの不具合の修正・試験だけである。

## 0. 目的・範囲・読み方の注意

### 0.1 目的

フェーズ1の判定が、検証用アプリの 24 ルートの外、つまり一般の実アプリで、どれほど役に立つかを測る。測った結果は、フェーズ2へ進む前に次の 3 つを決める材料にする。

1. 例外表(`resources/known-calls.php`)の緩め方
2. Blade の読み方をどこまで広げるか
3. 定数コストの呼び出し(`Auth::user()`、ルートモデル束縛など)の数え方

### 0.2 範囲:測るだけ

- **今回は測るだけである。** 上の 3 つは実装していない。例外表に足していない。`gap` にしていたものを `gap` にしなくする変更もしていない。
- 判定の規則は緩めていない(D21「分からないを安全にしない」)。
- ツールの不具合は、試験を足したうえで直した(§5)。ただし直したのは、判定を軽くする方向でないものだけである。判定を軽くする方向の案は、不具合の修正であっても実装せず、提案(§6)に回す。ビュー名の `/` の読み違い(FIX2)は、裁定(D36)で「誤読の修正であり、判定を軽くする変更に当たらない」と決まったので直した。出力の `degree` の罠(D38)も、同じ日の裁定で直した。
- 対象は公開 OSS の Laravel アプリ 5 本である(§1)。勤務先のアプリは使っていない。

### 0.3 読み方の注意

この文書の数字を読むときは、次の 5 点を前提にすること。

1. **「見逃し 0」が言えるのは、確定したルートの範囲だけである。** ここでいう確定とは、判定が `unknown` でないルートを指す。フェーズ1の「見逃し 0」は、検証用アプリの 24 ルートと単体試験のケースに対してだけ保証されていた。実アプリで確かめたのは、判定が確定したルートに限る。確定しなかったルートについては、見逃しの有無を言っていない。
2. **`unknown` は「安全」ではなく「分からない」である。** `unknown` は、ツールが追い切れなかったという表明で、軽いという意味ではない。`unknown` の割合が高いことは、ツールが安全側(重いかもしれないと答える側)に倒れていることを示す。それは、ツールが役に立たないことと同じではないが、役に立つことでもない。
3. **下限は参考値であり、保証ではない。** `unknown` のルートに付く下限(`lower_bound`、例: `≥O(N)`)は、「追えた範囲では、少なくともこれだけ」という値である。追えなかった部分があるから `unknown` になっているので、実際はそれより重い場合がありうる。下限を「このルートは少なくともこの重さである」と読んでよいかどうかは、抜き取りで確かめた範囲(§4)に限って言える。
4. **「緩めたら何本救えるか」は見積もりである。** 「その原因の `gap` しか持たないルート」を数えただけで、緩めた先で別の `gap` が現れるかどうかは見ていない。救えることと、確定することは同じではない。
5. **測定は 1 台の開発機の Docker 上で行った。** 時間・メモリは、その機械の参考値である。

## 1. 選んだアプリ(T1)

### 1.1 条件

指示書の条件は 3 つである。`composer.lock` の `laravel/framework` が 11 以上であること(`composer.json` の制約でなく、lock の値で確かめる)。直近 12 か月以内にコミットがあること。OSI 承認のライセンスであること。

### 1.2 選んだ 5 本

解析したコミットは、`git clone --depth 1` で取得した時点の先頭(HEAD)である。`laravel/framework` の版は、各 clone の `composer.lock` の `packages` から読み直した。ライセンスは各 clone の `LICENSE` と `composer.json` の `license` で確かめた。

| アプリ | URL | 解析したコミット | コミット日 | `laravel/framework`(lock) | ライセンス |
| --- | --- | --- | --- | --- | --- |
| BookStack | https://github.com/BookStackApp/BookStack | `045c6a98cac21ea86906674bb91d01f375460db6` | 2026-10-18 | v13.35.0 | MIT |
| Firefly III | https://github.com/firefly-iii/firefly-iii | `d65e916130c91d8f13ca808ca088d225ddd9a939` | 2026-10-26 | v13.35.0 | AGPL-3.0-or-later |
| Koel | https://github.com/koel/koel | `da0e70f4b45f2366b2d40917df8d4bc895127513` | 2026-10-30 | v13.37.0 | MIT |
| Laravel.io | https://github.com/laravelio/laravel.io | `55be0d802236041c24147dc350a485c36227dcd1` | 2026-10-30 | v13.39.0 | MIT |
| Snipe-IT | https://github.com/grokability/snipe-it | `e6dded3eac759353c90c05c7e32430c6dbaba93b` | 2026-10-29 | v12.70.1 | AGPL-3.0-or-later |

基準日は 2026-11-01 で、5 本とも HEAD が基準日の 2 週間以内である。浅い clone なので、過去 12 か月のコミット本数は数えていない(直近の 1 点だけを確かめた)。

**Laravel 11 のアプリは入っていない。** 5 本の lock の版は 13(4 本)と 12(1 本)である。Laravel 11 のアプリは対象にしてよいが、ベストエフォートと明記する方針だった。今回の結果は、Laravel 11 では確かめていない。

### 1.3 書き方の違い(選んだ理由)

指示書は、書き方が偏らないように選ぶことを求めている。偏りを見る観点は、画面中心か API 中心か、コントローラー直書きか層ありか、小規模か大規模かの 3 つである。5 本の特徴は次のとおり。規模は大中小のラベルでなく、解析したルート数と `app/` の PHP ファイル数を並べて示す。2 つの数は測り方が違い、ファイル数の多いアプリがルート数も多いとは限らない(例: BookStack は Koel よりファイルが少なくルートが多い)。ルート数は `route:list` の解析対象(`skipped` は含まない)、ファイル数は clone した `app/` 以下の `*.php` を数えた値である。

| アプリ | 画面と API | 構成 | 解析したルート数 / `app/` の PHP ファイル数 | 選んだ理由 |
| --- | --- | --- | --- | --- |
| BookStack | Blade 中心。付随の REST API は `JsonResource` を使わず自前の整形 | サービス層・リポジトリ層あり。コントローラーが `app/Http/Controllers` でなくドメイン別のディレクトリに分散 | 341 / 522 | 層のある構成と、コントローラーの置き場が標準でない場合を見られる |
| Firefly III | Blade と API の混在。API の整形は `JsonResource` でなく Transformer(Fractal) | リポジトリ層・サービス層・集計層あり | 641 / 1,218 | 画面と API が同居する重い構成。PHP 8.5 を要る点も含めて現実の素材 |
| Koel | API 中心(Vue の SPA)。`JsonResource` あり | サービス層・リポジトリ層あり | 260 / 1,063 | `JsonResource` を使う API 中心の例。他の 4 本と整形の方式が異なる |
| Laravel.io | Blade と Livewire が中心。`JsonResource` は少数 | コントローラー直書きが中心。薄い層(Actions・Services・Queries)が少しある | 97 / 206 | 素朴な構成の例。5 本の中でルート数・ファイル数とも最小 |
| Snipe-IT | Blade 中心に一部 Livewire。API は Transformer | コントローラーとモデルに業務ロジックが寄る。サービス層は薄い | 752 / 745 | コントローラー直書き寄りの大規模アプリ |

5 本を通すと、画面中心(BookStack・Laravel.io・Snipe-IT)、API 中心(Koel)、混在(Firefly III)が入り、層あり(BookStack・Firefly III・Koel)と直書き寄り(Laravel.io・Snipe-IT)が入り、ルート数 97〜752 の幅が入る。ただし Livewire 中心のアプリは、コントローラー以外のルートが多く、対象外(`kind` が `controller` でないもの)が増える。その分は §3 の「対象外の内訳」に出る。

### 1.4 外したアプリ

| アプリ | 外した理由 |
| --- | --- |
| Invoice Ninja | ライセンスが Elastic License 2.0 で、OSI 承認ではない(source-available)。条件を満たさないので、適格性の調査(T1)までで止め、解析していない。`laravel/framework` は v12.70.0、直近のコミットは 2026-10-18 で、他の条件は満たしていた |

指示書の候補例のうち、Pixelfed・Monica・Coolify は調べていない。優劣の判断ではなく、5 本で条件と書き方の幅がそろったためである。

### 1.5 選んだアプリについての注意

- Firefly III と Snipe-IT は AGPL である。このため、本文では対象アプリのコードを引用せず、アプリ名・コミット・ファイルと行で示す(引用が要る場合も数行まで)。
- Laravel.io は、依存に `livewire/flux` を含む。この依存のライセンスは、`composer.lock` に独自(`proprietary`)と記録されている。無償か有償かは lock からは確かめられないので、ここでは判断しない。このリポジトリには同梱していない(clone はリポジトリの外にある)。
- 5 本とも、`composer.lock` の取得元に有償・私設のパッケージは無かった(取得元は GitHub が大半。Packagist はパッケージ情報の参照先であり、取得元ではない)。BookStack の 2 パッケージだけは、作者のリポジトリ(`codeberg.org`)から取得するものだった。取得は、lock に書かれた URL に限り、コンテナの中でだけ行った。

## 2. 方法(T2)

### 2.1 環境

- **すべてコンテナの中で動かした。** 対象アプリの `composer install`・`artisan`・マイグレーションは、ホストでは一度も実行していない。ホストには PHP も composer も入れていない(D19)。
- DB は MySQL 8.4.7(`mysql:8.4.7` の公式イメージ)で、アプリごとに別の DB を使った。SQLite は使っていない(D20)。
- PHP の版は、BookStack・Koel・Snipe-IT が 8.3、Firefly III・Laravel.io が 8.5 である(後者の 2 本は `composer.json` が PHP 8.5 以上を要求する)。イメージは、BookStack・Snipe-IT・Firefly III・Laravel.io が公式の `php:<版>-cli` をもとにし、Koel だけはこのリポジトリの開発用イメージ(`FROM peak-sim-php-dev:8.3`)をもとにした。
- アプリが要る拡張(`gd`・`intl`・`bcmath`・`exif`・`xsl` など)は、アプリごとの Dockerfile で足した。Dockerfile は、このリポジトリでなくリポジトリ外の作業置き場に置いた。`memory_limit` は `-1` にした(このリポジトリの開発用イメージと同じ)。
- コンテナは uid/gid 1000 で動かした。対象アプリの clone は書込み可で、このツール(peak-sim)のリポジトリは読み取り専用でマウントした。

### 2.2 環境づくりの手順

アプリごとに、コンテナの中で次の順に行った。

1. `.env.example` から `.env` を作り、DB の接続先だけを変える。
2. `composer install`(スクリプトは通常どおり走らせた)。
3. `php artisan key:generate`。
4. `php artisan migrate --force`(**5 本とも MySQL 8.4 で通った**)。
5. DB の中身は入れていない(スキーマだけ。Snipe-IT の設定 1 行を除く、§2.4)。このツールは DB のデータを読まない。

画面を動かすための node のビルドは行っていない。このツールが読むのは PHP のコードだけだからである。

### 2.3 入力方式(アプリごと)

入力方式には、artisan を呼ぶ方式(`--artisan-command`)と、事前に書き出した JSON を読む方式(`--export-dir`)がある。

| アプリ | 主な方式 | 補助 |
| --- | --- | --- |
| BookStack | artisan 方式 | なし |
| Firefly III | artisan 方式 | なし |
| Koel | artisan 方式 | 事前書き出し方式でも解析し、`input.mode` 以外が一致することを確かめた |
| Laravel.io | artisan 方式 | なし |
| Snipe-IT | artisan 方式 | 事前書き出し方式で、解析だけの時間を測った。報告の `input.mode` 以外に差は見つからなかった |

artisan の呼び出し時間を測るため、Firefly III・Koel・Snipe-IT では、`php artisan` を包んで開始・終了時刻を記録するだけの薄い外付けスクリプトを `--artisan-command` に渡した。出力は素の `php artisan` と同じである(Firefly III では、素の実行とバイト比較して一致を確かめた)。Snipe-IT の外付けスクリプトは、警告が標準出力に混ざらないよう `display_errors=stderr` を付けて実行する(警告が標準出力を汚す問題は `artisan-compat.md` §3 を参照)。

### 2.4 測定条件(結果を読むときの前提)

結果の数字は、次の条件で測ったものである。違う条件では違う数字が出うる。

- **migrate 済みの DB を前提にしている。** `model:show` は DB に届き、テーブルが無いと失敗する。失敗したモデルを持つルートは、`gap` が付いて `unknown` になる(安全側に倒れる)。したがって、未 migrate の DB で測ると、下限が弱まる。BookStack では、空の DB で測り直したときに下限が後退することを実際に確かめた。表の数字は、migrate 済みの DB で測ったものを正とする。
- **`route:list` は、開発用の依存を入れたままの状態で取っている。** `composer install` に `--no-dev` を付けていない。このため `route:list` には、`/_ignition/*`(Firefly III・Laravel.io・Snipe-IT)や `/log-viewer/*`(Koel)などの開発・デバッグ用のルートが混ざる。これらは本番では出ない可能性があり、アプリのルートの数字を大きく見せる。これらを出すパッケージには、composer の `require-dev` でないものも含まれる。集計では、必要に応じて分母から除いた数字も併記する(§3)。
- **アプリの状態を `.env` 以外で変えた箇所が 2 か所ある。** コードは書き換えていない。
  - Firefly III: コントローラー(`app/Http/Controllers/ExchangeRates/IndexController.php:50`)のコンストラクターが、アプリ設定 `enable_exchange_rates` が false のとき例外を投げる。`route:list` はコントローラーを生成するので、このままだと artisan 方式の入口が全滅する。回避のため、自分用の DB の設定表で `enable_exchange_rates` を `true` に更新した(アプリの管理画面にある設定の切り替えに相当する)。
  - Snipe-IT: `settings` 表が空だと、すべての artisan コマンドが例外で止まる(`route:list` も同じ)。アプリ自身の `SettingsSeeder` を 1 回走らせて、`settings` に 1 行だけ入れた。あわせて、`.env.example` の `APP_URL=null` を `.env` で `http://localhost` に直した(`composer install` の途中で止まるため)。
- **ide-helper の副作用を元に戻した。** Snipe-IT では、`migrate` の後にアプリ自身のイベントリスナーが `barryvdh/laravel-ide-helper` を呼び、追跡されているファイル 10 本(スタブ 1 本とモデル 9 本)を書き換えた。差分を保存したうえで `git apply -R` で正確に戻し、`git status` に変更が無いことを確かめた。解析は、元のコミットのままの状態に対して行っている。`migrate` をやり直すと、同じ書き換えがまた起きる。
- 5 本とも、測定後の clone の `git status` は変更なしである(`.env`・`vendor/`・`storage/` は git の管理外の生成物)。
- 環境づくりの途中で、アプリ側の事情による失敗が他にもあった(例: Laravel.io のイメージに `mysql` クライアントが無く、スキーマの読込みで失敗した。コンテナ内で作り直して解消した)。ツールの不具合ではないので、ここでは省く。
- 別に、`model:show` が一部のモデルで失敗した(例: Snipe-IT の 2 モデル、BookStack の 3 モデル)。アプリまたは Laravel 側の例外である。このツールは、止まらずに `note` を出し、該当するルートを `unknown` にした。これは設計どおりの振る舞いである。

### 2.5 集計の道具

集計は、このツールの出力(`analyze --format=json`。スキーマ 0.1 と、D38 後の 0.2)だけから行った。集計のために出力の形を変えてはいない。道具はリポジトリの外に置いた。手順の要旨は次のとおり。

- Python 3 の標準ライブラリだけで動く。同じ入力からは同じバイト列が出る(乱数・時刻・絶対パスを使わない)。自己試験がある。
- ルートの内訳、軸ごと(クエリ・メモリ)の判定の分布、確度の件数、`gap` コードごとの「そのコードを持つルートの数」(1 ルートに同じコードが複数あっても 1)を数える。下限つきの `unknown` は、`unknown` の別行として数える(D27)。
- `gap` ごとに「原因」を 1 つ決める。`VENDOR_CALL_NOT_MODELLED` は、出力の `message` から呼び出しの名前を読む。`BLADE_UNSUPPORTED` は、`message` から構文(コンポーネント・ディレクティブなど)を読む。`message` から取れないときは、`site`(ファイルと行)のソースを 1 行読んで補う。補った件数は別に数える。
- 「緩めたら何本救えるか」は、**原因の呼び出し単位**で数える。「その原因の `gap` しか持たないルート」を数え、原因をカテゴリ(認可・入力の検証・認証ユーザー・Blade のコンポーネントなど)に辞書で振る。ルートが 2 つ以上のカテゴリを持つときは、どのカテゴリの単独の救いにも数えない。緩め方を組み合わせた場合は、持つ原因がすべて対象に含まれるルートを数える。
- 原因の取り方は、`message` の文言に依存する。文言が変わると集計が黙って崩れる(→ 提案)。
- 抜き取り(T4)の母集団の取り方は決定的で、乱数を使っていない。

### 2.6 時間とピークメモリの測り方

- **解析時間**は、このツールの解析だけを測る。artisan を呼ぶ方式では、解析時間に artisan の呼び出しの時間が入るので、呼び出しの時間を別に記録した。事前書き出し方式で測ったアプリは、その値を「解析だけ」の時間とする。測り方(どちらで測ったか)は、§3.5 の表にアプリごとに書く。
- 計測は 5 回で、中央値・最小・最大を出す。Docker は WSL2 上の開発機で動かした。
- **ピークメモリ**は、`analyze` のコマンドに内蔵の値が無い(`--stats` は時間・ルート数・ファイル数・要約の数・呼び出しの深さを出すが、メモリは出さない)。そこで外から測った。測り方は 2 通りで、同じ量ではない。
  - `/usr/bin/time -v` の「Maximum resident set size」(プロセスの最大の常駐メモリ)。
  - PHP の `auto_prepend_file` で、終了時に自分のピークを標準エラーへ出す。`memory_get_peak_usage` と、`/proc/self/status` の `VmHWM`(最大の常駐メモリ)を使った。
  - どちらも、このツールの PHP プロセスの値で、artisan が起こす子プロセスのメモリは含まない。アプリごとにどちらの方法で測ったかは、§3.5 の表に書く。2 通りの値を同じ列で比べないこと。
- 数字は、機械・Docker の設定・PHP の版に依存する。参考値である。

## 3. 結果(T3)

この節の数字は、5 本すべてを FIX1〜FIX5 に FIX2(ビュー名の `/`)と degree の修正(D38。出力スキーマ 0.2)を加えた最終版(`9f60add`)で測った出力から集計した。DB は migrate 済みである(§2.4)。FIX の前(`a255d02`。以下 baseline)との比較は §3.2.1 に置く。FIX1〜FIX5 だけを入れた版(`f11b1ba`)の数字は、更新前の版の記録である。FIX2 と D38 の前後は、4 アプリで出力が変わらず、Snipe-IT だけが大きく動いた(§3.2.1・§3.5)。割合の分母は、特記なき限り解析の対象(`kind` が `controller`)の 2,091 本である。

先に結論を 4 行で書く。

- 2,091 本のうち、判定が確定した(`unknown` でない)ルートは **5 本(0.2%)** である。5 本とも `O(1)`・確度 `high` で、Firefly III に 2 本、Koel に 3 本ある。`O(N)`・`O(N*M)` と確定したルートは 0 本である。FIX2 と D38 の後も、この 5 本は変わらない。
- 残りの 2,086 本(99.8%)は、少なくとも一方の軸が `unknown` である。**`unknown` は安全ではなく、ツールが追い切れなかったという意味である(D21)。** この割合の高さは、ツールが安全側(重いかもしれないと答える側)に倒れていることを示す。軽いという意味ではない。
- baseline と最終版を、5 アプリ・2,091 ルートすべてで比べた。判定(`growth`)・確度が変わったルートは **0 本**、`unknown` から確定に動いたルートも **0 本** である。変わったのは `gap` の中身と件数、そして Snipe-IT の 19 本で下限が現れた(または上がった)ことだけで、19 本とも重い側への変化である(§3.2.1)。
- **FIX2 で偽の `VIEW_NOT_FOUND` は消えたが、Snipe-IT の解析は重くなった。** 解析だけの時間は約 2.6 倍、ピークメモリは約 2.4 倍(約 1GiB)、出力 JSON は約 3.9 倍(186MB)である。「直したら重くなった」は事実としてここに書く(§3.5)。

### 3.1 ルートの内訳

| アプリ | 全ルート数 | 解析の対象(`controller`) | 対象外 | うち `framework` | うち `closure` |
| --- | ---: | ---: | ---: | ---: | ---: |
| BookStack | 343 | 341 | 2 | 2 | 0 |
| Firefly III | 652 | 641 | 11 | 11 | 0 |
| Koel | 270 | 260 | 10 | 3 | 7 |
| Laravel.io | 115 | 97 | 18 | 18 | 0 |
| Snipe-IT | 792 | 752 | 40 | 33 | 7 |
| **全体** | **2,172** | **2,091** | **81** | **67** | **14** |

- 「全ルート数」は、解析の対象と対象外(出力の `skipped` 配列と、`routes` のうち `controller` でないもの)の合計である。出力の `summary.routes` は対象のルートだけの数で、定義が違う。`route:list` の行数とも一致しない(同名のルートや HEAD の扱いによる)。
- 対象外は、フレームワークが提供するルート(`framework`)とクロージャのルート(`closure`)である。
- §2.4 のとおり、開発用の依存を入れたまま `route:list` を取っているので、2,091 本には開発用のルートが含まれる。composer が `require-dev` と宣言するパッケージのコントローラーが 20 本(Koel 15・Laravel.io 5)あり、本番向けでないと見立てた開発用ツールのルートを加えると 29 本になる。これらを除いた分母は 2,062 本である。確定した 5 本に、開発用のルートは入っていない。

### 3.2 判定の分布

軸ごとの判定を、アプリ別と全体で示す。各セルは「ルート数(割合)」である。`unknown` は、見つかった事実から言える下限が付くものを別の行にした(D27)。下限つきの `unknown` は確定ではない。

**クエリ軸**

| 判定 | BookStack | Firefly III | Koel | Laravel.io | Snipe-IT | 全体 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `O(1)` | 0(0.0%) | 2(0.3%) | 3(1.2%) | 0(0.0%) | 0(0.0%) | 5(0.2%) |
| `O(N)` | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) |
| `O(N*M)` | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) |
| `unknown`(下限なし) | 298(87.4%) | 626(97.7%) | 255(98.1%) | 97(100.0%) | 664(88.3%) | 1,940(92.8%) |
| `unknown` ≥`O(N)` | 41(12.0%) | 10(1.6%) | 2(0.8%) | 0(0.0%) | 81(10.8%) | 134(6.4%) |
| `unknown` ≥`O(N*M)` | 2(0.6%) | 3(0.5%) | 0(0.0%) | 0(0.0%) | 7(0.9%) | 12(0.6%) |
| 合計 | 341 | 641 | 260 | 97 | 752 | 2,091 |

**メモリ軸**

| 判定 | BookStack | Firefly III | Koel | Laravel.io | Snipe-IT | 全体 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `O(1)` | 0(0.0%) | 2(0.3%) | 3(1.2%) | 0(0.0%) | 0(0.0%) | 5(0.2%) |
| `O(N)` | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) |
| `O(N*M)` | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) | 0(0.0%) |
| `unknown`(下限なし) | 232(68.0%) | 532(83.0%) | 243(93.5%) | 97(100.0%) | 462(61.4%) | 1,566(74.9%) |
| `unknown` ≥`O(N)` | 91(26.7%) | 104(16.2%) | 8(3.1%) | 0(0.0%) | 255(33.9%) | 458(21.9%) |
| `unknown` ≥`O(N*M)` | 18(5.3%) | 3(0.5%) | 6(2.3%) | 0(0.0%) | 35(4.7%) | 62(3.0%) |
| 合計 | 341 | 641 | 260 | 97 | 752 | 2,091 |

**ルートの確度**(どちらかの軸が `unknown` なら `null`)

| 確度 | BookStack | Firefly III | Koel | Laravel.io | Snipe-IT | 全体 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `high` | 0 | 2 | 3 | 0 | 0 | 5(0.2%) |
| `medium` | 0 | 0 | 0 | 0 | 0 | 0(0.0%) |
| `null` | 341 | 639 | 257 | 97 | 752 | 2,086(99.8%) |
| 合計 | 341 | 641 | 260 | 97 | 752 | 2,091 |

読み方:

- **確定は 5 本だけで、そのすべてが `O(1)`・`high` である。** クエリ軸もメモリ軸も、確定は同じ 5 本である。`medium`(仮定を置いた確定)は 0 本である。
- 確定した 5 本は、Firefly III の `/api/v1/rules/validate-expression` と `/json/box/available`、Koel の Subsonic 互換 API の `/rest/ping`・`/rest/getMusicFolders`・`/rest/getOpenSubsonicExtensions` である。判定が正しいかの確認は §4 に書く。
- `unknown` を含むルートは 2,086 本(99.8%)で、`gap` を 1 つ以上持つルートの数と同じである。確定しなかったルートには、必ず説明(`gap`)がある。
- 下限つきの `unknown` は、クエリ軸で 146 本(≥`O(N)` 134 + ≥`O(N*M)` 12)、メモリ軸で 520 本(458 + 62)ある。どちらかの軸に下限があるルートは 521 本で、FIX2 の前(511 本)より 10 本増えた(Snipe-IT。§3.2.1)。下限は参考値であり、保証ではない(§0.3)。
- 次数(`degree`)は、`unknown` でも下限があれば下限の次数が入る。**下限のない `unknown` は `null` である(D38。出力スキーマ 0.2)。** 以前は 0 と出ており、「ほぼ `O(1)`」と読めてしまう罠だったので、0 でなく `null` にした(表では `unknown (no lower bound)`)。`null` になった軸は、クエリ軸 1,940・メモリ軸 1,566 で、上の表の「`unknown`(下限なし)」の行と一致する。アプリ別の `null` の軸の数は BookStack 530・Firefly III 1,158・Koel 498・Laravel.io 194・Snipe-IT 1,126 である。判定・確度・下限は変えていない。
- Laravel.io は 97 本すべてが、両軸とも下限のない `unknown` である。メモリ軸で下限つきが占める割合は、BookStack 32%・Snipe-IT 39%・Firefly III 17%・Koel 5% と開きがある。この集計だけから、アプリの書き方との関係は言えない。

#### 3.2.1 baseline から最終版への判定の差(FIX1〜FIX5・FIX2・D38)

| アプリ | ルート数(前→後) | 判定(growth)が変わったルート | 確度 | 下限 | 次数 | `unknown` から確定に動いたルート | `gap` 総数(前→後) | 差 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| BookStack | 341 → 341 | 0 | 0 | 0 | 0 | 0 | 33,051 → 40,431 | +7,380 |
| Firefly III | 641 → 641 | 0 | 0 | 0 | 0 | 0 | 36,893 → 41,923 | +5,030 |
| Koel | 260 → 260 | 0 | 0 | 0 | 0 | 0 | 3,588 → 4,164 | +576 |
| Laravel.io | 97 → 97 | 0 | 0 | 0 | 0 | 0 | 2,333 → 2,488 | +155 |
| Snipe-IT | 752 → 752 | 0 | 0 | 19 | 19 | 0 | 38,098 → 180,384 | +142,286 |
| **全体** | **2,091 → 2,091** | **0** | **0** | **19** | **19** | **0** | **113,963 → 269,390** | **+155,427** |

「次数」の列は、下限が変わったために次数が変わったルートの数である。schema 0.2 による「下限のない `unknown` の次数が 0 から `null` に変わる」分(5 アプリで 3,506 軸)は、判定が動いたのではないので、この表の「次数」には数えていない。

- **判定(growth)・確度が変わったルートは 0 本である。** 判定を軽くした変更は無く、`unknown` が確定に動いたルートも無い。D21 は緩んでいない。D36 の条件 3(確定に動いたルートが 1 本でもあれば全数を追う)は、該当するルートが無かった。
- **下限が動いたのは Snipe-IT の 19 本だけで、すべて重い側である。** 内訳は、下限が新しく現れたもの(なし → `≥O(N)`)がクエリ軸 9・メモリ軸 10 の 19 軸、下限の次数が上がったもの(`≥O(N)` → `≥O(N*M)`)がメモリ軸 4 軸(`/admin`・`/hardware/{asset}/audit`・`/hardware/{asset}/checkin/{backto?}`・`/hardware/{asset}/checkout`)である。軽くなる方向の変化は無い。下限つきのルートは 511 本から 521 本に増えた。これは FIX2 で、これまで読まれなかった共通レイアウトや部分テンプレートの中の事象が、下限の根拠として現れたためである。この 19 本の読み方は §4.7 に書く。
- `gap` の総数は 155,427 件増えた。内訳は 2 つに分かれる。FIX1〜FIX5 までの分が +17,460 件(`f11b1ba` の時点の数字で、更新前から変わらない)、FIX2 の分が +137,967 件で、FIX2 の分はすべて Snipe-IT である。増えた `gap` 156,354 件の内訳は、同じ行の別の原因 13,409 件(FIX4。これまで 1 件に潰れていたものが別々に出る)と、別の行 142,945 件(うち FIX3 の防壁が新しく検出した分が 2,094 件、読めなかった箇所が読めて現れた分などが 140,851 件。別の行 142,945 件のうち 138,665 件が Snipe-IT)である。消えた `gap` は 927 件(`PARSE_FAILED` の BookStack 92 ルート分と、Snipe-IT の偽の `VIEW_NOT_FOUND` 204 件を含む)で、差し引き +155,427 件になる。
- `gap` が増えたのは、ツールの数え方が仕様どおりになり、読めなかった箇所が読めて、未解決の箇所が見えるようになった結果である。`gap` が増えるのは安全側(`unknown` 側)の変化であり、軽くなったことを意味しない。
- BookStack の `PARSE_FAILED` は 92 ルートから 0 になった(FIX1)。このとき、これまで読まれなかったテンプレートの中にあった本来の `gap` が現れた。「`gap` が消えること」と「判定が確定すること」が別であることの実例である。**FIX2 でも同じことが起きた。** Snipe-IT の偽の `VIEW_NOT_FOUND`(204 件・187 ルート)が消え、共通レイアウトが歩かれて、その中の `gap` が 187 ルートに現れた。
- FIX2 の前後(`f11b1ba` の出力と `9f60add` の出力)を 5 アプリで比べると、BookStack・Firefly III・Koel・Laravel.io は、判定・確度・下限・`gap` の件数とも変化が無かった(`VIEW_NOT_FOUND` の「/」形が元々 0 件のため)。変わったのは Snipe-IT だけである。
- FIX1〜FIX4 までの版(`e9003eb`)と baseline の比較では、Koel の `GET /api/duplicate-uploads` の 1 ルートだけ、クエリ軸の下限が `≥O(N)` に変わっていた。実コードを読むと、偽の下限だった。FIX5 で解消し、最終版では baseline と同じに戻った(§5)。

### 3.3 unknown の原因

`gap` のコードごとに「そのコードを持つルートの数」を数えた。1 つのルートに同じコードが何度出ても 1、違うコードが複数あれば各コードに 1 ずつ数える。割合の分母は 2,091 本である。コードの意味は `output-schema.md` の「gap コード」にある。

| gap コード | BookStack | Firefly III | Koel | Laravel.io | Snipe-IT | 全体 | 対 controller |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `VENDOR_CALL_NOT_MODELLED` | 338 | 591 | 211 | 56 | 710 | 1,906 | 91.2% |
| `VENDOR_PARENT` | 159 | 425 | 156 | 85 | 621 | 1,446 | 69.2% |
| `UNRESOLVED_CALL` | 278 | 463 | 163 | 18 | 485 | 1,407 | 67.3% |
| `UNRESOLVABLE_BINDING` | 0 | 603 | 3 | 0 | 603 | 1,209 | 57.8% |
| `LOOP_SOURCE_UNKNOWN` | 200 | 325 | 30 | 5 | 316 | 876 | 41.9% |
| `NEW_UNKNOWN_CLASS` | 148 | 309 | 25 | 2 | 227 | 711 | 34.0% |
| `UNKNOWN_BUILDER_METHOD` | 180 | 0 | 18 | 2 | 326 | 526 | 25.2% |
| `BLADE_UNSUPPORTED` | 122 | 148 | 0 | 13 | 188 | 471 | 22.5% |
| `MODEL_OBSERVER_NOT_ANALYZED` | 0 | 2 | 11 | 0 | 235 | 248 | 11.9% |
| `DYNAMIC_CLASS_NAME` | 22 | 0 | 32 | 0 | 120 | 174 | 8.3% |
| `LOOP_BOUND_UNKNOWN` | 86 | 32 | 9 | 0 | 34 | 161 | 7.7% |
| `METHOD_NOT_FOUND` | 18 | 14 | 3 | 0 | 60 | 95 | 4.5% |
| `RECURSION` | 70 | 0 | 3 | 0 | 22 | 95 | 4.5% |
| `DYNAMIC_METHOD_NAME` | 9 | 23 | 7 | 3 | 42 | 84 | 4.0% |
| `MODEL_INFO_UNAVAILABLE` | 0 | 5 | 45 | 31 | 1 | 82 | 3.9% |
| `RELATION_TARGET_UNKNOWN` | 25 | 0 | 0 | 0 | 18 | 43 | 2.1% |
| `NO_BINDING` | 11 | 0 | 27 | 0 | 0 | 38 | 1.8% |
| `VIEW_NOT_FOUND` | 11 | 2 | 0 | 13 | 2 | 28 | 1.3% |
| `UNKNOWN_COLLECTION_METHOD` | 13 | 0 | 4 | 0 | 10 | 27 | 1.3% |
| `RESOURCE_NOT_ANALYZED` | 0 | 0 | 24 | 0 | 0 | 24 | 1.1% |
| `WITH_CYCLE` | 0 | 0 | 9 | 0 | 5 | 14 | 0.7% |
| `CACHE_VALUE_SIZE_UNKNOWN` | 0 | 0 | 3 | 5 | 0 | 8 | 0.4% |
| `PARSE_FAILED` | 0 | 0 | 0 | 0 | 2 | 2 | 0.1% |
| `TRAIT_CONFLICT` | 0 | 2 | 0 | 0 | 0 | 2 | 0.1% |

- 最も多いのは `VENDOR_CALL_NOT_MODELLED`(許可表に無いベンダーの呼び出し)で、2,091 本の 91.2% が持つ。次が `VENDOR_PARENT`(メソッドが見つからず、祖先がベンダーのクラス)の 69.2%、`UNRESOLVED_CALL` の 67.3%、`UNRESOLVABLE_BINDING`(束縛の実装を読み取れない)の 57.8% である。
- 1 つのルートは複数のコードを持つので、列の合計はルート数を超える。1 つのコードだけを緩めても、他のコードが残るルートは確定しない(見積もりは §3.4)。
- `UNRESOLVABLE_BINDING` は Firefly III(603 本)と Snipe-IT(603 本)に集中し、Koel は 3 本、BookStack と Laravel.io では 0 本である。Snipe-IT は FIX2 の前は 487 本だった。
- **`VIEW_NOT_FOUND` は 215 本から 28 本に減った(FIX2)。** Snipe-IT は 189 本から 2 本になった。残る 28 本は、BookStack 11 本・Laravel.io 13 本・Firefly III 2 本・Snipe-IT 2 本で、いずれも「`/` の読み違い」ではない(定数でないビュー名、名前空間つきの名前 `feed::links`、など)。Snipe-IT の 2 本は `app/Http/Controllers/ModalController.php:45` の、定数でないビュー名である。
- **`PARSE_FAILED` は Snipe-IT の 2 ルートに新しく出た。** 内訳は §3.6 に書く。
- `MODEL_INFO_UNAVAILABLE`(メッセージは「`model:show` の情報が無い」)は、Koel(45 本)と Laravel.io(31 本)に多い。**2 種類の別の原因があり、同じコードに入っている。**
  - **Koel(45 本): モデルと認識されなかった。** Koel の `App\Models\User` は、ベンダーの `Illuminate\Foundation\Auth\User` を継承している。ツールのモデル判定(`AppModelCatalog::isModel` → `AppSourceIndex::extendsVendor`)は、アプリ内の継承しかたどらない。そのため、ベンダーの `Auth\User` の先にある Eloquent の `Model` に届かず、`User` がモデルと認識されない。`model:show` 自体は `User` で成功している(Koel で `model:show` の失敗の記録が 0 件なのはこのため)。メッセージは「情報が無い」だが、実態は「モデルと認識されなかった」であり、食い違っている。Koel の 45 本の大半は `User` 由来で、T2 の調査では 40 本が `User` のみを原因としていた。この件は §6 の提案(ベンダーの既知の `Model` 派生を認める案、またはメッセージを実態に合わせる案)に紐づける。ツールは変更していない。
  - **Laravel.io(31 本): `model:show` が失敗した。** §3.6 の `model:show` の失敗(`Thread`・`Reply`・`Article`・`Tag`)に当たる。モデルの `id()` が、空のインスタンスで未定義のプロパティを読む形で、`model:show` が例外になる。Koel とは別の原因である。
  - **Snipe-IT(1 本): FIX2 で歩かれるようになった部分に出た。** ベンダー(Laravel Passport)の `Token` モデルで、`model:show` の情報が得られなかった。ベンダー継承のモデルという点で Koel の `User` と同種と見ている(原因の切り分けまではしていない)。判定には影響しない。
- `VENDOR_PARENT` の数は、ベンダーが提供するルートと、アプリ自身のコントローラー(親クラスがベンダー)の両方を含む。`gap` のコードが `VENDOR_PARENT` だけのルートは 126 本で、内訳はベンダー提供のルートが 66 本(うち composer の dev 依存が 20 本)、アプリのコントローラーが 60 本である。

#### 3.3.1 `VENDOR_CALL_NOT_MODELLED` の原因の上位 20 件

その呼び出しを 1 つでも持つルートの数である(2,091 本が分母)。呼び出しの名前は、出力の `message` から読んだ。`message` から取れず、ソースで補った件数は 0 である(`gap` 155,475 件のすべてが `message` から取れた)。

| 順位 | 呼び出し | ルート数 | 対 controller | 許可表の状態 |
| ---: | --- | ---: | ---: | --- |
| 1 | `trans()` | 1,197 | 57.2% | 載っていない |
| 2 | `config()` | 976 | 46.7% | 載っていない |
| 3 | `request()` | 753 | 36.0% | 載っていない |
| 4 | `app()` | 690 | 33.0% | 意図して外している |
| 4 | `auth()` | 690 | 33.0% | 意図して外している |
| 6 | `str_replace()` | 678 | 32.4% | 載っていない |
| 7 | `route()` | 638 | 30.5% | 載っていない |
| 8 | `session()` | 637 | 30.5% | 載っていない |
| 9 | `redirect()` | 557 | 26.6% | 載っていない |
| 10 | `is_null()` | 533 | 25.5% | 載っていない |
| 11 | `is_array()` | 512 | 24.5% | 載っていない |
| 12 | `sprintf()` | 455 | 21.8% | 載っていない |
| 13 | `csrf_token()` | 441 | 21.1% | 載っていない |
| 14 | `e()` | 440 | 21.0% | 載っていない |
| 15 | `url()` | 425 | 20.3% | 載っていない |
| 16 | `strtolower()` | 423 | 20.2% | 載っていない |
| 17 | `trim()` | 411 | 19.7% | 載っていない |
| 18 | `array_key_exists()` | 296 | 14.2% | 載っていない |
| 19 | `is_string()` | 278 | 13.3% | 載っていない |
| 20 | `csrf_field()` | 271 | 13.0% | 載っていない |

ほかに 724 種の呼び出しがある。

- 上位 20 の内訳は、Laravel のヘルパー関数が 12 個(`trans()`・`config()`・`request()`・`app()`・`auth()`・`route()`・`session()`・`redirect()`・`csrf_token()`・`e()`・`url()`・`csrf_field()`)、PHP 組み込みの関数が 8 個(`str_replace()`・`is_null()`・`is_array()`・`sprintf()`・`strtolower()`・`trim()`・`array_key_exists()`・`is_string()`)である。FIX2 の前(11 個と 9 個)から、`csrf_field()` が入り `substr()` が外れた。
- 「意図して外している」は、許可表の `refused` に載っているという意味である。`refused` は、ポリシー・リスナー・検証規則・クエリ・通信など、ツールから見えない処理を動かしうる入口を、載せ忘れでなく意図して外した一覧である(`resources/known-calls.php` の説明)。`app()` と `auth()` がこれに当たる。それ以外の上位の呼び出しは、許可表に載っていないだけである。
- アプリごとの上位 3 は次のとおり。
  - BookStack: `trans()`(269 本)・`request()`(240)・`config()`(228)
  - Firefly III: `sprintf()`(410)・`config()`(343)・`route()`(338)
  - Koel: `Illuminate\Database\Eloquent\Model::query()`(106)・`Illuminate\Contracts\Auth\Guard->user()`(85)・`Illuminate\Contracts\Routing\ResponseFactory->noContent()`(46)
  - Laravel.io: `session()`(48)・`redirect()`(43)・`__()`(35)
  - Snipe-IT: `trans()`(597)・`auth()`(387)・`is_null()`(363)(FIX2 の前は `trans()` 522・`redirect()` 283・`is_array()` 250。共通レイアウトが歩かれ、レイアウトの中の呼び出しが多くのルートに載るようになった)
- `header() on a plaint` のように「受け手の型」だけの行がある(Firefly III の上位に出る。138 本)。受け手のクラス名が `message` に無く、どのクラスの呼び出しかを集計できないためである。
- この表は、緩めたときの効果を示すものではない。「何本を救えるか」は、その原因の `gap` しか持たないルートの数として、§3.4 で別に数える。

#### 3.3.2 `BLADE_UNSUPPORTED` の構文

対応外の Blade 構文を持つルートは 471 本(22.5%)である(FIX2 の前は 343 本)。アプリ別では BookStack 122・Firefly III 148・Laravel.io 13・Snipe-IT 188・Koel 0 である(Koel は Vue の SPA で、Blade の画面が出ない)。Snipe-IT は 60 本から 188 本に増えた。FIX2 で共通レイアウトや部分テンプレートが歩かれるようになり、その中の対応外の構文が、多くのルートに現れたためである。構文の種類別のルート数(1 つのルートが複数の種類を持ちうる)は、コンポーネント(`<x-...>`・`<livewire:...>`)344・ディレクティブ 445・その他 201 である。上位 20 を示す。同数のものは同順位にした。

| 順位 | 構文 | ルート数 | 対 controller | アプリ別 |
| ---: | --- | ---: | ---: | --- |
| 1 | `@can` | 186 | 8.9% | Snipe-IT 173・Laravel.io 13 |
| 1 | `@endcan` | 186 | 8.9% | Snipe-IT 173・Laravel.io 13 |
| 3 | `@auth` | 180 | 8.6% | Snipe-IT 178 ほか |
| 3 | `@endauth` | 180 | 8.6% | Snipe-IT 178 ほか |
| 3 | `component <x-icon>` | 180 | 8.6% | Snipe-IT |
| 6 | `component <x-notifications>` | 179 | 8.6% | Snipe-IT |
| 7 | `@grokability` | 172 | 8.2% | Snipe-IT |
| 8 | `component <livewire:alert-menu>` | 171 | 8.2% | Snipe-IT |
| 8 | `component <x-callout>` | 171 | 8.2% | Snipe-IT |
| 10 | `component <x-layout.fav-icons>` | 145 | 6.9% | Firefly III |
| 11 | `component <x-container>` | 131 | 6.3% | Snipe-IT |
| 12 | `@includeFirst` | 126 | 6.0% | Firefly III |
| 12 | `@sectionMissing` | 126 | 6.0% | Firefly III |
| 12 | `component <x-layout.create-menu>` | 126 | 6.0% | Firefly III |
| 12 | `component <x-layout.flash>` | 126 | 6.0% | Firefly III |
| 12 | `component <x-layout.range>` | 126 | 6.0% | Firefly III |
| 12 | `component <x-layout.sidebar>` | 126 | 6.0% | Firefly III |
| 12 | `component <x-layout.tracking>` | 126 | 6.0% | Firefly III |
| 12 | `component <x-layout.user-menu>` | 126 | 6.0% | Firefly III |
| 12 | `syntax: unbalanced @endif` | 126 | 6.0% | Firefly III |

ほかに 314 種の構文がある。「アプリ別」は、各アプリの上位 20 に出た値で、全体の数に足りない分は、そのアプリの上位 20 の外にある。

- 同じ数(126、13 など)が並ぶのは、共通のレイアウトを使うルートが、その中の構文をまとめて持つためと読める(推測。レイアウトの中身を 1 つずつは確かめていない)。1 つの構文の効果が、構文の数でなくルートの数で効くのはこのためである。
- **`@click`・`@keydown`・`@keyup`・`@input` などは、Alpine や Vue の属性であり、Blade のディレクティブではない。** ツールは両者を見分けず、「対応外の Blade ディレクティブ」にする(既知の振る舞い)。上位に出ても、Blade への対応が足りないことの証拠とは読まない。
- **`syntax: unbalanced @endif`・`@else` などは、多くの場合二次の `gap` である。** 開きのディレクティブ(`@sectionMissing`・`@can` など)が未対応で読み飛ばされ、対になる `@else`・`@endif` が相手を失って出る。同じ原因が二重に数えられている。

### 3.4 緩めたら何本救えるか(見積もり)

ここでいう「救われる」は、そのルートの `gap` がゼロになることである。確定(O(1) など)の保証ではない。緩めた先で別の `gap` が現れうる。BookStack の `PARSE_FAILED` を直したとき(FIX1。92 → 0)、読まれていなかったテンプレートの中の本来の `gap` が現れた前例がある。

入力は、FIX1〜FIX5 と FIX2・D38 を入れた後の出力(`9f60add`。migrate 済みの DB)である。解析の対象は controller ルート 2,091 本で、そのうち `gap` を持つのは 2,086 本、`gap` を持たず確定したのは 5 本である。**FIX2 の前(`f11b1ba`)の数字からの変化は、ほぼ Snipe-IT で起きている。** 共通レイアウトが歩かれて、これまで読まれなかった `gap` が各ルートに現れたためで、「全カテゴリを緩めたときに救われる本数」は 415 本から 322 本に減った(§3.4.3)。「原因」は `gap` 1 つから取る(呼び出しの名前・Blade の構文・`クラス::メソッド()`・ビュー名・`gap` コードの型)。ルートの原因の集合が**ちょうど 1 つ**のとき、そのルートを「その原因だけを持つ」と呼ぶ。

#### 3.4.1 原因の呼び出しごと×アプリごとの本数

次の表は、「その原因だけを持つルート」の本数である(単独で救える本数)。複数の原因を持つルートは数えないので、実態より小さく出る。実態に近いのは 3.4.3 の累積である。

単独で救える原因は全部で 122 種、合計 144 本である(FIX2 の前は 125 種・147 本)。上位 30 を示す。

| 原因 | カテゴリ | BookStack | Firefly III | Koel | Laravel.io | Snipe-IT | 単独で救える合計 | 含むルート合計 |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `bind()` (UNRESOLVABLE_BINDING) | binding | 0 | 7 | 0 | 0 | 0 | 7 | 603 |
| `redirect()` | laravel_helper | 3 | 0 | 0 | 0 | 2 | 5 | 557 |
| `action Spatie\LaravelIgnition\Http\Controllers\ExecuteSolutionController::__invoke()` (VENDOR_PARENT) | vendor_parent | 0 | 1 | 0 | 1 | 1 | 3 | 3 |
| `action Spatie\LaravelIgnition\Http\Controllers\HealthCheckController::__invoke()` (VENDOR_PARENT) | vendor_parent | 0 | 1 | 0 | 1 | 1 | 3 | 3 |
| `action Spatie\LaravelIgnition\Http\Controllers\UpdateConfigController::__invoke()` (VENDOR_PARENT) | vendor_parent | 0 | 1 | 0 | 1 | 1 | 3 | 3 |
| `singleton()` (UNRESOLVABLE_BINDING) | binding | 0 | 0 | 0 | 0 | 2 | 2 | 603 |
| `action App\Http\Controllers\Auth\ForgotPasswordController::showLinkRequestForm()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 0 | 1 | 1 | 2 | 2 |
| `action App\Livewire\CustomFieldEditor::__invoke()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 0 | 0 | 2 | 2 | 2 |
| `action ArieTimmerman\Laravel\SCIMServer\Http\Controllers\ResourceController::notImplemented()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 0 | 0 | 2 | 2 | 2 |
| `action ArieTimmerman\Laravel\SCIMServer\Http\Controllers\ResourceController::wrongVersion()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 0 | 0 | 2 | 2 | 2 |
| `action Spatie\Feed\Http\FeedController::__invoke()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 0 | 2 | 0 | 2 | 2 |
| `class stdClass` (NEW_UNKNOWN_CLASS) | (辞書外) | 0 | 0 | 1 | 0 | 0 | 1 | 36 |
| `Illuminate\Contracts\Auth\Authenticatable::$email` | vendor_class_method | 0 | 0 | 1 | 0 | 0 | 1 | 8 |
| `App\Http\Controllers\Api\StatuslabelsController::authorize()` (VENDOR_PARENT) | authorization | 0 | 0 | 0 | 0 | 1 | 1 | 8 |
| `Laravel\Socialite\Facades\Socialite::driver()` | vendor_class_method | 0 | 0 | 0 | 1 | 0 | 1 | 4 |
| `Illuminate\Contracts\Auth\Authenticatable::$playlistFolders` | vendor_class_method | 0 | 0 | 1 | 0 | 0 | 1 | 2 |
| `App\Http\Controllers\API\FetchSongWaveformController::authorize()` (VENDOR_PARENT) | authorization | 0 | 0 | 1 | 0 | 0 | 1 | 1 |
| `App\Http\Controllers\SocialImageController::dispatchSync()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 0 | 1 | 0 | 1 | 1 |
| `action App\Filament\Resources\Articles\Pages\ListArticles::__invoke()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 0 | 1 | 0 | 1 | 1 |
| `action App\Filament\Resources\Replies\Pages\ListReplies::__invoke()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 0 | 1 | 0 | 1 | 1 |
| `action App\Filament\Resources\Threads\Pages\ListThreads::__invoke()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 0 | 1 | 0 | 1 | 1 |
| `action App\Filament\Resources\Users\Pages\ListUsers::__invoke()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 0 | 1 | 0 | 1 | 1 |
| `action App\Http\Controllers\API\AlbumController::destroy()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 1 | 0 | 0 | 1 | 1 |
| `action App\Http\Controllers\API\AlbumController::store()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 1 | 0 | 0 | 1 | 1 |
| `action App\Http\Controllers\API\AlbumSongController::destroy()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 1 | 0 | 0 | 1 | 1 |
| `action App\Http\Controllers\API\AlbumSongController::show()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 1 | 0 | 0 | 1 | 1 |
| `action App\Http\Controllers\API\AlbumSongController::store()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 1 | 0 | 0 | 1 | 1 |
| `action App\Http\Controllers\API\AlbumSongController::update()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 1 | 0 | 0 | 1 | 1 |
| `action App\Http\Controllers\API\Artist\ArtistAlbumController::destroy()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 1 | 0 | 0 | 1 | 1 |
| `action App\Http\Controllers\API\Artist\ArtistAlbumController::show()` (VENDOR_PARENT) | vendor_parent | 0 | 0 | 1 | 0 | 0 | 1 | 1 |

読み方:

- 単独で救えるのは 144 本(2,091 本の 6.9%)にとどまる。大半のルートは、複数の原因を同時に持つためである。
- 単独の原因で多いのは、`VENDOR_PARENT`(親クラスがベンダーのメソッド)のルートごとの原因である。ただしこの中身は、パッケージが提供するルートとアプリのコントローラーの混在である(3.4.2)。
- 逆に、含むルートが多い原因(`trans()` 1,197、`config()` 976、`LOOP_SOURCE_UNKNOWN` 876、`property type not declared` 788、`request()` 753、`str_replace()` 678、`redirect()` 557)は、単独で救える本数が 0 か数本である。多くのルートに載っているが、必ず別の原因と一緒に載っているためである。1 つだけ緩めても、ルートは救われない。

`gap` コードで束ねると(ルートの `gap` コードが 1 種類だけのルート)、次のとおりである。ラベルごとに割ると上の表のように細かくなる `VENDOR_PARENT` は、ここでは 1 行にまとまる。

| gap コード | BookStack | Firefly III | Koel | Laravel.io | Snipe-IT | そのコードだけを持つ合計 | 含むルート合計 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `VENDOR_PARENT` | 1 | 8 | 41 | 41 | 35 | 126 | 1,446 |
| `VENDOR_CALL_NOT_MODELLED` | 19 | 2 | 21 | 4 | 7 | 53 | 1,906 |
| `UNRESOLVABLE_BINDING` | 0 | 7 | 0 | 0 | 2 | 9 | 1,209 |
| `VIEW_NOT_FOUND` | 0 | 1 | 0 | 0 | 0 | 1 | 28 |
| `NEW_UNKNOWN_CLASS` | 0 | 0 | 1 | 0 | 0 | 1 | 711 |

#### 3.4.2 ベンダー提供ルートとアプリのコントローラーの内訳

`VENDOR_PARENT` だけが原因のルート 126 本には、パッケージが提供するルート(アプリのコードではない)と、アプリのコントローラー(親クラスがベンダーなので追えない)が混ざっている。ここでは、解決したコントローラーのクラスがアプリの名前空間(`composer.json` の `autoload.psr-4`)の外にあるものを「ベンダー提供」とした。そのうち、パッケージが `vendor/composer/installed.json` の `dev-package-names` にあるものを「dev 依存」(`composer install --no-dev` では存在しない)とした。「開発用ツール」は、composer では本番の依存だが、開発・デバッグ用のルートを出すと見立てたパッケージ(ignition・debugbar・log-viewer。見立ては集計の道具の一覧による。デバッグ時のみ有効かは対象アプリの設定次第で、確かめていない)である。

**`VENDOR_PARENT` だけが原因(`gap` コードが `VENDOR_PARENT` のみ)のルート**

| アプリ | ルート | ベンダー提供 | うち dev 依存 | うち開発用ツール | うち本番向け | アプリのコントローラー | うち action 自身が親に定義? | うちそれ以外の親メソッド |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| BookStack | 1 | 0 | 0 | 0 | 0 | 1 | 1 | 0 |
| Firefly III | 8 | 3 | 0 | 3 | 0 | 5 | 5 | 0 |
| Koel | 41 | 15 | 15 | 0 | 0 | 26 | 25 | 1 |
| Laravel.io | 41 | 26 | 5 | 3 | 18 | 15 | 9 | 6 |
| Snipe-IT | 35 | 22 | 0 | 3 | 19 | 13 | 10 | 3 |
| 全体 | 126 | 66 | 20 | 9 | 37 | 60 | 50 | 10 |

「action 自身が親に定義?」は、そのルートの action(`クラス@メソッド`)のメソッド自体が、アプリのクラスに宣言が無く親(ベンダー)にあるかもしれない、という `VENDOR_PARENT` を持つものである。

**`VENDOR_PARENT` を 1 つでも持つルート**

| アプリ | ルート | ベンダー提供 | うち dev 依存 | うち開発用ツール | うち本番向け | アプリのコントローラー | うち action 自身が親に定義? | うちそれ以外の親メソッド |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| BookStack | 159 | 0 | 0 | 0 | 0 | 159 | 1 | 158 |
| Firefly III | 425 | 3 | 0 | 3 | 0 | 422 | 5 | 417 |
| Koel | 156 | 15 | 15 | 0 | 0 | 141 | 25 | 116 |
| Laravel.io | 85 | 26 | 5 | 3 | 18 | 59 | 9 | 50 |
| Snipe-IT | 621 | 22 | 0 | 3 | 19 | 599 | 10 | 589 |
| 全体 | 1,446 | 66 | 20 | 9 | 37 | 1,380 | 50 | 1,330 |

ベンダー提供ルートのパッケージごとの内訳は次のとおり。

| アプリ | パッケージ | ルート | 区分 |
| --- | --- | ---: | --- |
| Firefly III | spatie/error-solutions | 3 | 開発用ツール |
| Koel | opcodesio/log-viewer | 15 | dev 依存 |
| Laravel.io | filament/actions | 2 | 本番向け |
| Laravel.io | filament/filament | 3 | 本番向け |
| Laravel.io | fruitcake/laravel-debugbar | 5 | dev 依存 |
| Laravel.io | livewire/flux | 9 | 本番向け |
| Laravel.io | resend/resend-laravel | 1 | 本番向け |
| Laravel.io | spatie/error-solutions | 3 | 開発用ツール |
| Laravel.io | spatie/laravel-feed | 2 | 本番向け |
| Laravel.io | spatie/laravel-login-link | 1 | 本番向け |
| Snipe-IT | arietimmerman/laravel-scim-server | 19 | 本番向け |
| Snipe-IT | spatie/error-solutions | 3 | 開発用ツール |

読み方:

- 「`VENDOR_PARENT` だけ」の 126 本のうち、66 本(52%)はパッケージが提供するルートで、アプリのコードではない。残る 60 本がアプリのコントローラーである。そのうち 50 本は、action のメソッド自体が親(ベンダー)にあるかもしれない形である。
- 66 本のベンダー提供のうち、dev 依存が 20 本、開発用ツールが 9 本、本番向けが 37 本である。
- `VENDOR_PARENT` を 1 つでも持つルートは 1,446 本(69.2%)だが、その 1,380 本はアプリのコントローラーで、ベンダー提供は 66 本にすぎない。多くは、アプリのコントローラーが親クラスのメソッドを呼ぶ形である(`authorize()` を持つルートが 658 本、`input()` 161 本、`validate()` 86 本など)。
- したがって、`VENDOR_PARENT` の 126 本を「緩めれば救える本数」として一括で読まない。ベンダー提供の 66 本は、緩めるべき対象というより、分析の対象に含めるかどうか(=分母の問題)である。

#### 3.4.3 カテゴリ別の本数と、段階的に足した累積

原因を 14 のカテゴリに振った。カテゴリの振り分けは、集計の道具の辞書による。純 PHP 関数や Laravel のヘルパー関数の一覧は、道具側に置いた**見立て**である(I/O・通信・グローバル状態・コールバック実行を含む関数は入れていない)。

- 「含むルート」は、そのカテゴリの原因を 1 つでも持つルートの数である。
- 「単独」は、そのカテゴリだけを持つ(他に別カテゴリの原因も辞書外の原因も無い)ルートの数である。そのカテゴリを緩めれば `gap` がゼロになる。
- 「許可表の状態」は、`VENDOR_CALL_NOT_MODELLED` の `gap` 単位の理由である(`not on allow-list` = 許可表に無いだけ、`refused` = 表が意図して拒否)。

| カテゴリ | 含むルート | BookStack | Firefly III | Koel | Laravel.io | Snipe-IT | 単独で救える合計 | 対 controller | 許可表の状態(`gap` 単位) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| `auth_user`(`Auth::user()`・`auth()`・`$request->user()`・`@auth`) | 781 | 0 | 0 | 0 | 0 | 0 | 0 | 0.0% | refused ×1,634、not on allow-list ×52 |
| `authorization`(Gate・Policy・`authorize()`・`@can`) | 707 | 0 | 0 | 1 | 0 | 1 | 2 | 0.1% | refused ×1 |
| `validation`(`validate()`・FormRequest・Validator) | 107 | 0 | 0 | 0 | 0 | 0 | 0 | 0.0% | refused ×8 |
| `blade_component_include`(コンポーネント・`@include` 系) | 362 | 0 | 0 | 0 | 0 | 0 | 0 | 0.0% | — |
| `view_slash`(ビュー名の「/」の読み。FIX2 で修正済みのため 0 本。6.4) | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0.0% | — |
| `binding`(`bind()`・`singleton()` の抽象が定数でない) | 1,209 | 0 | 7 | 0 | 0 | 2 | 9 | 0.4% | — |
| `builtin_pure`(許可表外の純 PHP 関数: `strtolower()` 等) | 1,581 | 0 | 0 | 0 | 0 | 0 | 0 | 0.0% | not on allow-list ×19,390、callback argument ×3 |
| `container`(`app()`・`resolve()`) | 692 | 0 | 0 | 0 | 0 | 0 | 0 | 0.0% | refused ×2,091 |
| `laravel_helper`(`trans()`・`config()`・`request()`・`route()`・`redirect()`・`session()`・`url()` 等) | 1,763 | 3 | 1 | 0 | 1 | 6 | 11 | 0.5% | not on allow-list ×118,268、arity mismatch ×130、refused ×115 |
| `app_global_function`(アプリ自前のグローバル関数: `setting()`・`userCan()` 等) | 464 | 0 | 0 | 0 | 0 | 0 | 0 | 0.0% | not on allow-list ×6,290 |
| `other_function`(ファイル・通信・状態を持つ PHP 組み込み関数: `file_exists()`・`mkdir()`・`ini_set()` 等) | 425 | 0 | 0 | 0 | 0 | 0 | 0 | 0.0% | not on allow-list ×1,613 |
| `vendor_parent`(親クラス(ベンダー)のメソッド) | 893 | 1 | 8 | 40 | 41 | 34 | 124 | 5.9% | — |
| `blade_other`(`@icon`・`@inject`・`@sectionMissing`・`@click` 等) | 445 | 0 | 0 | 0 | 0 | 0 | 0 | 0.0% | — |
| `vendor_class_method`(ベンダークラスのメソッド: `Request->input()` 等) | 524 | 1 | 0 | 6 | 1 | 0 | 8 | 0.4% | not on allow-list ×3,338、property not modelled ×231、refused ×37 |

(`auth_user` の「含むルート」781 には、`auth()` を持つ 690 本などが重なって入っている。`laravel_helper` の `arity mismatch` は、引数の数が許可表の想定と合わないもの。)

次に、カテゴリを段階的に足したときの累積を示す。累積は、それまでのカテゴリを**すべて**緩めたとき、`gap` がゼロになるルートの数(ルートの持つカテゴリがすべて緩めた集合に含まれる件数)である。足す順序は表のとおり。

| 段 | 足したカテゴリ | BookStack | Firefly III | Koel | Laravel.io | Snipe-IT | 全体 | 増分 | 対 controller |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | +`builtin_pure` | 0 | 0 | 0 | 0 | 0 | 0 | +0 | 0.0% |
| 2 | +`auth_user` | 0 | 0 | 0 | 0 | 0 | 0 | +0 | 0.0% |
| 3 | +`authorization` | 0 | 0 | 1 | 0 | 3 | 4 | +4 | 0.2% |
| 4 | +`validation` | 0 | 0 | 1 | 0 | 3 | 4 | +0 | 0.2% |
| 5 | +`blade_component_include` | 0 | 0 | 1 | 0 | 3 | 4 | +0 | 0.2% |
| 6 | +`view_slash` | 0 | 0 | 1 | 0 | 3 | 4 | +0 | 0.2% |
| 7 | +`binding` | 0 | 10 | 1 | 0 | 5 | 16 | +12 | 0.8% |
| 8 | +`laravel_helper` | 5 | 20 | 1 | 3 | 50 | 79 | +63 | 3.8% |
| 9 | +`container` | 5 | 23 | 1 | 3 | 51 | 83 | +4 | 4.0% |
| 10 | +`app_global_function` | 9 | 27 | 1 | 3 | 51 | 91 | +8 | 4.4% |
| 11 | +`other_function` | 9 | 27 | 1 | 3 | 54 | 94 | +3 | 4.5% |
| 12 | +`vendor_parent` | 17 | 84 | 41 | 51 | 89 | 282 | +188 | 13.5% |
| 13 | +`blade_other` | 17 | 84 | 41 | 51 | 90 | 283 | +1 | 13.5% |
| 14 | +`vendor_class_method` | 30 | 85 | 60 | 56 | 91 | 322 | +39 | 15.4% |

**14 カテゴリをすべて緩めると、2,091 本のうち 322 本(15.4%)で `gap` がゼロになる。** 残りの 1,764 本(`gap` を持つ 2,086 本のうち)は、14 カテゴリの外の原因が残る(3.4.4)。FIX2 の前は 415 本(19.8%)だった。**減ったのは、緩めにくくなったのではなく、FIX2 の算術で説明できる。** FIX2 の前の 415 本のうち、最後の関門が `view_slash` だったルートは 96 本で、すべて Snipe-IT である(下の「最後の関門」の表)。FIX2 でそれらのルートの `VIEW_NOT_FOUND` が消え、共通レイアウトなどが歩かれて、それぞれの本来の `gap`(14 カテゴリの外の原因を含む)が現れたので、`gap` がゼロになるルートには入らなくなった。415 − 96 = 319 で、FIX2 の後の 322 本は、それより 3 本多い(この 3 本の中身は追っていない)。Snipe-IT も 184 − 96 = 88 で、91 本は +3 である。他の 4 アプリの数字(30・85・60・56 本)は変わっていない。`view_slash`(ビュー名の「/」)は FIX2 で修正済みなので、段 6 の増分は 0 である。

累積は集合の和なので、最終の数は足す順序に依らない。順序をランダムに入れ替えた 200 通り(乱数種固定)で、最終の救われるルート数がすべて 322 本で一致することを確かめた(200/200)。一方、**途中の増分は順序で変わる**。たとえば逆順で足すと、先頭の `vendor_class_method` は +8、`vendor_parent` は +124、`builtin_pure` は最後に +88 になる。そこで、順序に依らない指標として、**最終の 322 本から、そのカテゴリだけを緩めない場合に失う本数**(そのカテゴリが最後の関門になっているルート数)を示す。

| カテゴリ | BookStack | Firefly III | Koel | Laravel.io | Snipe-IT | 全体 |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `builtin_pure` | 13 | 34 | 2 | 2 | 37 | 88 |
| `auth_user` | 2 | 13 | 3 | 4 | 9 | 31 |
| `authorization` | 0 | 0 | 5 | 5 | 36 | 46 |
| `validation` | 5 | 0 | 0 | 1 | 0 | 6 |
| `blade_component_include` | 0 | 0 | 0 | 0 | 3 | 3 |
| `view_slash` | 0 | 0 | 0 | 0 | 0 | 0 |
| `binding` | 0 | 74 | 0 | 0 | 35 | 109 |
| `laravel_helper` | 28 | 44 | 6 | 13 | 52 | 143 |
| `container` | 2 | 10 | 0 | 0 | 3 | 15 |
| `app_global_function` | 17 | 4 | 1 | 0 | 0 | 22 |
| `other_function` | 1 | 1 | 0 | 0 | 4 | 6 |
| `vendor_parent` | 12 | 57 | 42 | 52 | 35 | 198 |
| `blade_other` | 0 | 0 | 0 | 0 | 1 | 1 |
| `vendor_class_method` | 13 | 1 | 19 | 5 | 1 | 39 |

読み方:

- 最後の関門の本数が大きいのは、`vendor_parent` 198、`laravel_helper` 143、`binding` 109、`builtin_pure` 88、`authorization` 46、`auth_user` 31 である。
- 全体の 322 本のうち Snipe-IT が 91 本(28%)を占める。FIX2 の前は 415 本のうち 184 本(44%)で、Snipe-IT の `authorization` 118 と `view_slash` 96 が目立っていた。FIX2 の後は、`view_slash` が 0 になり、`authorization` も 36 に下がった。415 から 322 への減少は、`view_slash` が最後の関門だった 96 本が `gap` ゼロから外れたこと(415 − 96 = 319。差の +3 は中身を追っていない)でほぼ説明できる。
- `view_slash` は、ツールの読み違い(有効な名前を無効と読む)だったので、D36 に従い FIX2 で直した。緩和でなく修正済みの数として別に数えていた分は、0 本になった。

#### 3.4.4 全カテゴリを緩めても残る `gap`

14 カテゴリをすべて緩めても `gap` が残るルートは 1,764 本である(FIX2 の前は 1,671 本)。残る原因は、辞書の外の原因(下表)である。「あと 1 原因で救える」は、残る原因がその 1 つだけのルートの数である。

| 残る原因 | 含むルート | あと 1 原因で救える |
| --- | ---: | ---: |
| `LOOP_SOURCE_UNKNOWN` | 876 | 12 |
| `property type not declared` (UNRESOLVED_CALL) | 788 | 94 |
| `variable has no known type` (UNRESOLVED_CALL) | 548 | 55 |
| `other` (UNRESOLVED_CALL) | 492 | 11 |
| `static property read` (UNRESOLVED_CALL) | 470 | 15 |
| `MODEL_OBSERVER_NOT_ANALYZED` | 248 | 48 |
| `class Carbon\Carbon` (NEW_UNKNOWN_CLASS) | 197 | 1 |
| `getMessage() on a plaint` | 193 | 4 |
| `class Illuminate\Support\Collection` (NEW_UNKNOWN_CLASS) | 187 | 4 |
| `DYNAMIC_CLASS_NAME` | 174 | 0 |
| `property name not literal` (UNRESOLVED_CALL) | 165 | 0 |
| `LOOP_BOUND_UNKNOWN` | 161 | 2 |
| `class League\Fractal\Manager` (NEW_UNKNOWN_CLASS) | 148 | 0 |
| `class League\Fractal\Serializer\JsonApiSerializer` (NEW_UNKNOWN_CLASS) | 146 | 0 |
| `header() on a plaint` | 140 | 0 |
| `RECURSION` | 95 | 0 |
| `DYNAMIC_METHOD_NAME` | 84 | 5 |
| `class HTMLPurifier` (NEW_UNKNOWN_CLASS) | 83 | 0 |
| `Safe\json_encode()` | 83 | 0 |
| `MODEL_INFO_UNAVAILABLE` | 82 | 22 |

残るルートが持つ `gap` コードは、`UNRESOLVED_CALL` 1,407、`LOOP_SOURCE_UNKNOWN` 876、`VENDOR_CALL_NOT_MODELLED` 728、`NEW_UNKNOWN_CLASS` 711、`UNKNOWN_BUILDER_METHOD` 526、`MODEL_OBSERVER_NOT_ANALYZED` 248、`DYNAMIC_CLASS_NAME` 174、`LOOP_BOUND_UNKNOWN` 161、`METHOD_NOT_FOUND` 95、`RECURSION` 95、`DYNAMIC_METHOD_NAME` 84、`MODEL_INFO_UNAVAILABLE` 82 などである(本数は、そのコードを持つ残りルート数)。

- `other` (UNRESOLVED_CALL) の 492 本には、FIX3 で足した防壁(型の取れない値へのプロパティ読みを `gap` にする)の `gap` を持つ 471 本が含まれる(集計の分類の規則上、防壁は `other` に入る)。防壁の `gap` の中身は 6.1 に書く。
- `getMessage() on a plaint` や `header() on a plaint` の `plaint` は、ツールが受け手の種類(型の取れない値)として出す語である。受け手のクラス名が `message` に無いので、どのクラスの呼び出しかは集計できない。

残りの原因を、「増える救われるルート数」が最大のものから 1 つずつ足していくと(貪欲法。最適とは限らない)、次のように増える。

| 段 | 足す原因 | 増分 | 累積(全体) |
| ---: | --- | ---: | ---: |
| 1 | `property type not declared` (UNRESOLVED_CALL) | 94 | 416 |
| 2 | `variable has no known type` (UNRESOLVED_CALL) | 74 | 490 |
| 3 | `LOOP_SOURCE_UNKNOWN` | 147 | 637 |
| 4 | `MODEL_OBSERVER_NOT_ANALYZED` | 63 | 700 |
| 5 | `static property read` (UNRESOLVED_CALL) | 45 | 745 |
| 6 | `other` (UNRESOLVED_CALL) | 40 | 785 |
| 7 | `MODEL_INFO_UNAVAILABLE` | 25 | 810 |
| 8 | `getMessage() on a plaint` | 24 | 834 |
| 9 | `Safe\preg_replace()` | 19 | 853 |
| 10 | `class Carbon\Carbon` (NEW_UNKNOWN_CLASS) | 32 | 885 |

(貪欲法を 30 段目まで進めると、累積は 1,099 本になる。)

これらは、例外表や Blade では解けない `gap` である。中身は、型の取れない値(プロパティ・変数・静的プロパティ)、ループの対象、オブザーバ、モデル情報、ベンダーのクラスの `new` などで、フェーズ1の仕組みを広げる話になる(見立て)。14 カテゴリをすべて緩めても、2,091 本の約 84%(1,764 本)は `gap` が残る。

#### 3.4.5 救われたルートの見込みの判定

14 カテゴリをすべて緩めて救われる 322 本を、**現在の出力**の下限(`lower_bound`)・`events_found`・`gap` のループ位置で分類した。緩めた後の判定の**見込み**であって、実測ではない。

- (i) 下限が付いておらず、`gap` がループの外にある。緩めると O(1) になりうる。ただし「下限が付かなかった」だけで、O(1) の根拠ではない(D21)。`events_found` が 0 のものと、あるものに分ける。
- (ii) 下限 ≥O(N) または ≥O(N*M) がある。緩めて確定しても、その次数以上である。
- (iii) 下限が無く、`gap` がループの中にある(または解析が打ち切られた)。緩めると反復あたりの費用が現れうるので、判定できない。

| アプリ | 救われる | (i) events 0 | (i) events あり | (ii) ≥O(N) | (ii) ≥O(N*M) | (iii) 判定できない |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| BookStack | 30 | 18 | 12 | 0 | 0 | 0 |
| Firefly III | 85 | 80 | 1 | 4 | 0 | 0 |
| Koel | 60 | 58 | 2 | 0 | 0 | 0 |
| Laravel.io | 56 | 56 | 0 | 0 | 0 | 0 |
| Snipe-IT | 91 | 51 | 32 | 8 | 0 | 0 |
| 全体 | 322 | 263 | 47 | 12 | 0 | 0 |

軸ごとの下限(救われる 322 本)は次のとおり。**両軸とも下限なしが 310 本**で、残る **12 本が下限つき**である(310 + 12 = 322)。下限つきの 12 本はすべて memory 軸に ≥O(N) を持ち、そのうち query 軸にも ≥O(N) を持つものが 6 本である。≥O(N*M) を持つものは、どちらの軸にも 0 本である。

カテゴリ単独で救われるルートの見込み(全体)は次のとおり。

| カテゴリ | 単独で救われる | (i) events 0 | (i) events あり | (ii) ≥O(N) | (ii) ≥O(N*M) | (iii) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `authorization` | 2 | 0 | 2 | 0 | 0 | 0 |
| `binding` | 9 | 9 | 0 | 0 | 0 | 0 |
| `laravel_helper` | 11 | 8 | 3 | 0 | 0 | 0 |
| `vendor_parent` | 124 | 124 | 0 | 0 | 0 | 0 |
| `vendor_class_method` | 8 | 7 | 1 | 0 | 0 | 0 |

読み方:

- 見込みで最も多い (i)(310 本、96.3%)は、「下限が付かなかった」ルートである。**これは、緩めれば O(1) になるという意味ではない。** 緩めた後に追える範囲が広がり、そこに N 倍の費用が現れれば、O(N) や O(N*M) になりうる。`gap` ゼロは確定の保証ではない(冒頭の注)。
- (ii)(12 本)は、緩めて確定しても、少なくとも下限の次数にはなる、という見込みである。ただし下限そのものが過大になる型がある(抜き取りの結果、§4 と 6.5)ので、この読みも保証ではない。
- (iii) は 0 本だった。`gap` がループの中にあって下限が無いルートは、14 カテゴリを緩めても救われなかった(下限があるものは (ii) に数えている)。
- FIX2 の前は、救われる 415 本が (i) 384 本・(ii) 31 本だった。FIX2 の後に (ii) が 12 本に減ったのは、下限を持つ Snipe-IT のルートの多くが、共通レイアウトの中の `gap` を新たに持ち、14 カテゴリを緩めても `gap` が残る側に移ったためと見ている(切り分けまではしていない)。
- 緩めて「確定」に動くルートが実際に何本かは、緩めて測らなければ分からない。判定を緩める変更を入れる段では、新たに確定したルートの抜き取り確認を必ず入れる(D33)。

#### 3.4.6 測定条件の注記

この節の数字を読むときの条件を、まとめて書く。

1. **開発用ルートの混入。** 5 本とも `composer install` を `--no-dev` なしで行ったため、本番には無い開発用のルート(`/_ignition/*`・`/_debugbar/*`・`/log-viewer` など)が母集団に入っている。分母を変えた場合の数字を次に示す。開発用のルートが多い Koel と Laravel.io で、割合が大きく動く。

   | アプリ | controller | 分母から dev 依存を除く | さらに開発用ツールも除く | アプリのコントローラーだけ | 救われる(全) | 同(dev 除外) | 同(開発用ツールも除外) | 同(アプリのみ) |
   | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
   | BookStack | 341 | 341 | 341 | 341 | 30 (8.8%) | 30 (8.8%) | 30 (8.8%) | 30 (8.8%) |
   | Firefly III | 641 | 641 | 638 | 638 | 85 (13.3%) | 85 (13.3%) | 82 (12.9%) | 82 (12.9%) |
   | Koel | 260 | 245 | 245 | 245 | 60 (23.1%) | 45 (18.4%) | 45 (18.4%) | 45 (18.4%) |
   | Laravel.io | 97 | 92 | 89 | 71 | 56 (57.7%) | 51 (55.4%) | 48 (53.9%) | 30 (42.3%) |
   | Snipe-IT | 752 | 752 | 749 | 730 | 91 (12.1%) | 91 (12.1%) | 88 (11.7%) | 69 (9.5%) |
   | 全体 | 2,091 | 2,071 | 2,062 | 2,025 | 322 (15.4%) | 302 (14.6%) | 293 (14.2%) | 256 (12.6%) |

   「アプリのコントローラーだけ」は、ベンダー提供のルート(66 本)を分母から除いた数である。

2. **`gap` の数え方の変化。** FIX4 で、同じ行の別の原因が別の `gap` として数えられるようになった。FIX2 では、これまで読まれなかった共通レイアウトが Snipe-IT の 187 ルートで歩かれ、その中の `gap` が各ルートに現れた。このため、`gap` の総数は baseline に比べて増えた(5 本の合計で 113,963 → 269,390、+155,427。うち FIX1〜FIX5 の分が +17,460、FIX2 の分が +137,967)。内訳は §3.2.1 に書く。**判定(growth・確度)が動いたルートは、baseline に対して 0 本**である。下限が動いたのは Snipe-IT の 19 本だけで、すべて重い側である(§3.2.1)。つまり、`gap` が正確になった結果、救われる本数の見積もりは FIX2 の後に 415 → 322 本へと減った(重い側に動いた)。軽くなる方向には動いていない。「ルート数」で数える本節の表は、`gap` 件数の増加の影響を受けにくいが、FIX2 の分は Snipe-IT のルートごとの `gap` の組み合わせを変えたので、影響を受けた(例: `VENDOR_CALL_NOT_MODELLED` を持つルートは、FIX1〜FIX5 の段階では 1,847 本のままだったが、FIX2 の後は 1,906 本。`UNRESOLVED_CALL` は 1,222 → 1,407 本)。
3. **集計の道具の限界。**
   - 原因の名前は、出力の `message` の文言から取っている。文言が変わると集計が黙って崩れる(6.9)。
   - `X() on a <型>` の形は、受け手のクラス名が `message` に無いので、どのクラスの呼び出しかが分からない。名前の同じ別クラスのメソッドが 1 つの原因に束ねられる。
   - Alpine / Vue の属性(`@click` など)は Blade のディレクティブではないが、ツールは見分けず `BLADE_UNSUPPORTED` にする。Blade の `gap` の上位に出ても、Blade の能力不足とは読まない(6.2)。
   - `syntax: unbalanced @else` / `@endif` は、開きのディレクティブ(`@can`・`@sectionMissing` など)が未対応で読み飛ばされた結果、対応先を失って出る二次の `gap` であることが多い。同じ原因が二重に数えられる。
   - カテゴリの辞書と、純 PHP 関数・Laravel ヘルパーの一覧は、見立てである。辞書の外の原因は「全カテゴリを緩めた」に含まれない。
   - 緩和の見積もりは、キャッシュの `scenario`(miss と hit)を区別しない。
   - 順序に依らない最終の本数は 322 だが、途中の増分は順序で変わる(3.4.3)。
4. **migrate 済みの DB が前提**である(§2.4)。未 migrate の DB では、`model:show` が失敗して下限が弱まる。
5. **「救われる」は見積もりである。** 冒頭の注のとおり、`gap` ゼロは確定の保証ではない。5 アプリの合計であり、アプリごとの偏りが大きい(Snipe-IT が 322 本の 28%)。

### 3.5 性能(解析時間・ピークメモリ)

測り方は §2.6 のとおりである。5 回測り、中央値と最小〜最大を示す。測定は FIX2 と D38 を入れた最終版(`9f60add`)で、5 本とも migrate 済みの DB で行った。数字は、最終版で測った値である。比較のため、FIX2 の前(`f11b1ba`)の値を併記した。**Snipe-IT だけが大きく重くなった(3.5.1)。**

**解析時間(秒)**

| アプリ | ルート数 | 入口 | 解析だけ(中央値 / 最小〜最大) | artisan 込み(中央値 / 最小〜最大) | FIX2 の前の artisan 込み(中央値) | FIX 前の artisan 込み(中央値) |
| --- | ---: | --- | --- | --- | ---: | ---: |
| BookStack | 341 | artisan | 分離できず | 壁時計 9.15 / 8.79〜9.53(`--stats` の elapsed は 8.71〜9.06) | 8.448(`--stats`) | 7.813 |
| Firefly III | 641 | artisan | 3.985 / 3.806〜4.085 | 13.973 / 13.401〜14.083 | 13.469 | 14.712 |
| Koel | 260 | artisan(主)・事前書き出し(補助) | 0.801 / 0.765〜0.816 | 8.337 / 8.135〜8.739 | 8.112 | 8.373 |
| Laravel.io | 97 | artisan | 分離できず | 2.161 / 2.077〜2.271 | 2.040 | 1.818 |
| **Snipe-IT** | 752 | artisan(主)・事前書き出し(補助) | **10.748 / 10.592〜11.150** | **22.720 / 21.300〜24.559** | 15.605 | 16.703 |

- 「解析だけ」の測り方はアプリごとに違う。Firefly III は artisan 込みの時間から artisan の呼び出し 42 回の合計を引いた値、Koel と Snipe-IT は事前書き出し方式(`--export-dir`)の時間、BookStack と Laravel.io は artisan の呼び出しが解析と一体で分離できなかった。**アプリ間で「解析だけ」の列を比べないこと。** 規模も違う(`app/` の PHP ファイルは BookStack 522・Firefly III 1,218・Koel 1,063・Laravel.io 206・Snipe-IT 745)。
- artisan の書き出しの時間は、解析と別に記録した。Firefly III は 42 回(`route:list` 1 回と `model:show` 41 回)の合計が 9.998 秒(9.496〜10.105)、Koel は 24 回の合計が 7.011 秒(6.789〜7.311。事前書き出し 1 回は 7.102 秒)、Snipe-IT は 41 回の合計が 10.05〜11.36 秒である。Firefly III と Koel では、artisan の書き出しが「artisan 込み」の時間の大半を占める(Firefly III 約 72%・Koel 約 84%)。Snipe-IT は、解析が重くなったので、書き出しの占める割合が約 47% に下がった(10.05〜11.36 秒を、artisan 込みの中央値 22.720 秒で割った概算)。
- BookStack は、FIX2 の後は壁時計で測った(中央値 9.15 秒、8.79〜9.53)。FIX2 の前は無印の壁時計で 8.76 秒(8.68〜8.89)だった。

**ピークメモリ**

`analyze` の `--stats` はピークメモリを出さないので、外から測った。アプリごとに測り方が違い、**列の意味が揃っていない。** いずれも peak-sim の PHP プロセスの値で、artisan が起こす子プロセスのメモリは含まない。

| アプリ | 値(最終版、5 回) | 測り方 | FIX2 の前 | FIX 前 |
| --- | --- | --- | --- | --- |
| BookStack | 常駐メモリの最大 306,076 kB(約 299 MiB。305,692〜306,120 kB) | `/usr/bin/time -v` の Maximum resident set size | 306,068 kB | 約 265,000 kB |
| Firefly III | `memory_get_peak_usage(true)` 373,968,896 B(約 356.6 MiB)、`(false)` 369,117,480 B(約 352.0 MiB)、`VmHWM` 393,248〜393,524 kB(約 384 MiB) | `auto_prepend_file` で、終了時に自分の値を標準エラーへ出す | `(true)` 373,968,896 B(同値) | `(true)` 358,617,088 B |
| Koel | `memory_get_peak_usage(false)` 86,011,624 B(約 82.0 MiB)、`(true)` 90,181,632 B(約 86.0 MiB)、`VmHWM` 116,348〜116,952 kB(約 114 MiB) | `auto_prepend_file` で、終了時に自分の値を標準エラーへ出す | `(false)` 86,008,496 B | `(false)` 83,175,832 B |
| Laravel.io | `memory_get_peak_usage(true)` 31,457,280 B(30.0 MiB) | peak-sim を同じプロセスに読み込み、終了時に出す | 同じ値 | 同じ値 |
| **Snipe-IT** | **常駐メモリの最大 999,696 kB(事前書き出し方式。約 976 MiB)**、999,036 kB(artisan 方式) | `/usr/bin/time -v` の Maximum resident set size | 421,448 kB(事前書き出し方式。約 411 MiB)、420,804 kB(artisan 方式) | 406,692 kB(artisan 方式) |

- `memory_get_peak_usage` は PHP が確保したメモリの最大、Maximum resident set size と `VmHWM` は OS が数える常駐メモリの最大で、後者のほうが大きく出る(Firefly III と Koel で両方を並べた)。**アプリ間でそのまま比べない。** 値の桁は、約 30〜980 MiB である(FIX2 の前は約 30〜420 MiB)。
- **BookStack の増加は、FIX1 の影響と読む。因果は確かめていない。** FIX 前に比べて、時間は 7.813 秒から 8.448 秒(約 +8%)、メモリは約 265,000 kB から 306,068 kB(約 +15%)に増えた。揺れの範囲(±10% 前後)を超える増加である。FIX1 で、これまで `PARSE_FAILED` で読まれなかったテンプレートが読まれるようになり、解析する量が増えたためと読む。`gap` の件数が BookStack でもっとも増えたこと(33,051 件から 40,431 件、+22%)と整合する。ただし、同じ条件(同じ DB・同じ環境)で修正の前後の時間を比べる実験はしていない。FIX1 だけを入れた版との直接の比較(切り分けの測定)もしていないので、環境の違いや揺れの可能性は除けていない。ほかの 4 本は、FIX 前との差がメモリで 0〜+4.3%、時間は向きが揃わない(Firefly III −1.24 秒、Koel −0.26 秒、Laravel.io +0.22 秒、Snipe-IT −1.10 秒。これは FIX2 の前の測定での比較で、Snipe-IT は FIX2 の後に大きく変わった。3.5.1)。FIX2 の後の BookStack は、FIX2 の前から時間が約 +3〜7%、メモリはほぼ同じ(306,076 kB)で、揺れの範囲(±10% 前後)と読む。BookStack には「/」を含むビュー名が無く、FIX2 の影響を受けない。
- **Laravel.io の時間は、比較に使わない。** FIX2 の前の測定では 2.040 秒(1.720〜2.292)で、直前の測定の 1.689 秒より遅かった。測定中に、ほかの Docker が同時に動いていた(`load average` 4.6 の記録がある)ためである。FIX2 の後の測定は 2.161 秒(2.077〜2.271)で、揺れの原因は区別していない。Laravel.io の時間は参考値として載せるだけで、FIX 前後の比較には使わない。
- FIX5 の前後(FIX1〜FIX4 の版と FIX5 の版)では、時間もメモリも差が無い(メモリは全アプリで ±0.1% 以内。時間は Laravel.io の外れ値を除いて、向きが揃わず揺れの範囲)。
- FIX2・D38 の前後(`f11b1ba` と `9f60add`)では、Snipe-IT 以外の 4 本は、メモリが ±0.1〜0.3% 以内(Firefly III と Koel は事実上同値)、時間は揺れの範囲(Firefly III +4%、Koel +1%、BookStack は上記、Laravel.io は参考値)である。degree を `null` にする D38 の変更が速度に効くとは言えない。
- 1 つのアプリにつき 5 回の測定であり、修正の性能への影響を示すものではない(ただし Snipe-IT の FIX2 の影響は、揺れを大きく超える。3.5.1)。測定は WSL2 上の 1 台の開発機の Docker で行った(§0.3)。

#### 3.5.1 FIX2 の後の Snipe-IT:直したら重くなった

FIX2 は偽の `VIEW_NOT_FOUND` を消した。その代わり、Snipe-IT の解析は大きく重くなった。これは事実として書く。

| 項目 | FIX2 の前(`f11b1ba`) | FIX2 の後(`9f60add`) | 倍率 |
| --- | ---: | ---: | ---: |
| 解析だけの時間(事前書き出し方式・中央値) | 4.171 秒 | 10.748 秒(10.592〜11.150) | 約 2.6 倍 |
| artisan 込みの時間(中央値) | 15.605 秒 | 22.720 秒(21.300〜24.559) | 約 1.5 倍 |
| ピークメモリ(常駐の最大。事前書き出し方式) | 421,448 kB(約 411 MiB) | 999,696 kB(約 976 MiB) | 約 2.4 倍 |
| 出力 JSON のサイズ | 47.9 MB | 186.1 MB | 約 3.9 倍 |
| `gap` の総数 | 42,417 | 180,384 | 約 4.3 倍 |

(出力 JSON のサイズは、バイト数を 10 の 6 乗で割った値である。時間とメモリは 5 回の測定で、揺れの範囲を大きく超えた差である。5 回の出力は byte 単位で一致した。)

- **原因。** Snipe-IT の 187 ルートで、共通レイアウト(`layouts/default` など)が歩かれるようになった。FIX2 の前は、「/」を含むビュー名が `VIEW_NOT_FOUND` になって、レイアウトの先へ進まなかった。FIX2 の後は、レイアウトの中の追えない呼び出しが、ルートごとの `gap` として積み上がる。`gap` の総数が 42,417 から 180,384 に増えたことと、時間・メモリ・出力サイズが増えたことは、方向と規模が合う。ただし、原因の切り分け(どの処理が時間とメモリを食うか)はしていない。
- **効いているもの。** 要約(summary)の再利用は効いている(3,556 回を計算し、5,022 回を再利用)。それでも、`gap` をルートごとに持つ出力の大きさと、ルートごとの歩きの量が増える。
- **他の 4 本は影響が無い。** BookStack・Firefly III・Koel・Laravel.io は、「/」を含むビュー名が無いため、FIX2 の前後で出力が変わらず、時間とメモリも揺れの範囲である(上記)。
- **読み方。** 大きなアプリでは、解析に約 1GiB のメモリが要り、出力 JSON は 100MB 級になりうる。この試運転は `memory_limit=-1` で測った(§2.1)。実測した(3.5.2)ところ、既定の `memory_limit`(128M。公式 Docker イメージの既定)では、5 本中 3 本(Snipe-IT・Firefly III・BookStack)が PHP の Fatal error(終了コード 255。1 行のみで、peak-sim の説明は出ない)で落ち、Koel と Laravel.io は通る。最小の完走は、Snipe-IT が JSON で約 960M(表形式なら 384M〜512M の間)、Firefly III と BookStack が 256M〜512M の間である。Firefly III と BookStack は FIX2 の前から 300MB 級を要し、元から落ちる。FIX2 は Snipe-IT の必要量を約 0.4GB から約 1GB に押し上げた。CLI の `memory_limit` は環境により異なる。足りないときの出力を親切にするか、メモリを減らすかは、提案に回す(6.10・6.11)。
- 判定を軽くする方向の変化ではなく、誤読の修正の代償である。FIX2 を戻すと、偽の `VIEW_NOT_FOUND` が 25% のルートに戻り、Snipe-IT の判定が歪む。FIX2 は戻さない。軽くする案は 6.10 に置く。

#### 3.5.2 `memory_limit` を下げたときの実測

試運転は `memory_limit=-1` で測った(§2.1)ので、利用者の環境で上限が付いていたらどうなるかを、別に測った。PHP の起動時に `-d memory_limit=<上限>` を付けて、`peak-sim analyze` を実行した(コンテナの中。JSON 形式)。Snipe-IT は事前書き出し方式(解析のみ)、ほかの 4 本は artisan 方式で、`-d` は peak-sim を動かす PHP にだけ効く(artisan の子プロセスは `-1` のまま)。5 本とも migrate 済みの DB で、対象アプリは変更していない。

| アプリ(ルート数) | 128M | 256M | 512M | 最小の完走(測った刻みの範囲) |
| --- | --- | --- | --- | --- |
| Snipe-IT(752) | 落ちる | 落ちる | 落ちる | 928M は落ち、**960M で完走**(1G も完走) |
| Firefly III(641) | 落ちる | 落ちる | 完走(12.1 秒) | 256M と 512M の間(384M は未試行) |
| BookStack(341) | 落ちる | 落ちる | 完走(7.6 秒) | 256M と 512M の間(384M は未試行) |
| Koel(260) | 完走(6.7 秒) | 完走 | 完走 | 128M 以下(これより下は未試行) |
| Laravel.io(97) | 完走(1.7 秒) | 完走 | 完走 | 128M 以下(これより下は未試行) |

Snipe-IT は、JSON にしない表形式(`--format=table`)なら、**512M で完走**した(488,992 kB。7.4 秒)。128M・256M・320M・384M は落ちた。最小は 384M と 512M の間である(448M は未試行)。JSON 形式との差(約 0.5GB)は、186 MB の JSON を文字列として組み立てる分が大きいと見ている(切り分けはしていない)。

- **既定の 128M では、5 本中 3 本(Snipe-IT・Firefly III・BookStack)が落ちる。** Koel と Laravel.io は通る。ピークメモリ(上の表の測り方)は、BookStack 約 306MB・Firefly III 約 357MB・Snipe-IT 約 976MiB(FIX2 の前は約 421MB)・Koel 約 86MiB・Laravel.io 30MiB で、落ちるかどうかはこの順と合う。
- **落ち方は、PHP の致命的エラーが 1 行出るだけである。** 終了コードは 255、標準出力は空、標準エラーは `Fatal error: Allowed memory size of N bytes exhausted (tried to allocate M bytes) in …` の 1 行だけで、peak-sim の説明や、`memory_limit` を上げる案内は出ない。落ちる場所は上限ごとに違い(エンジン・`gap` の組み立て・根拠の組み立て・判定・JSON の出力・構文解析器)、利用者には原因が分かりにくい。
- **FIX2 のせいではなく、一般の問題である。** Firefly III と BookStack は、FIX2 の前から 300MB 級を要していた。Snipe-IT も FIX2 の前に約 421MB で、128M・256M では落ちる。FIX2 は Snipe-IT の必要量を約 0.4GB から約 1GB に押し上げた(3.5.1)。
- **既定の上限は環境による。** PHP の公式 Docker イメージは `php.ini` が無く、既定が 128M である。Debian や Ubuntu のパッケージの `php-cli` は、CLI の `memory_limit` を `-1`(無制限)にしていることが多い(環境による。ここでは確かめていない)。利用者の環境で、落ちるか落ちないかが変わる。
- 完走の最小値の幅は、測った刻み(128M・256M・512M・960M など)の粗さによる。数字は、WSL2 上の 1 台の開発機の Docker で測った参考値である。

### 3.6 ツールの不具合の件数と内容

直した不具合の詳細は §5 に書く。ここでは、例外で止まったもの・構文解析に失敗したもの・明らかにおかしい出力の件数と内容をまとめる。

| 区分 | 件数 | 内容 |
| --- | ---: | --- |
| ツールが例外で止まった | 0 | 最終版の測定で、異常終了した実行は記録に無い。終了コード 0 を記録したのは、Koel の全実行、Snipe-IT の 10 回すべて、BookStack と Firefly III の表形式の実行である。なお、Firefly III の最初の実行では、ツールが終了コード 3 で終わった。これは、artisan の `route:list` がアプリ側の例外(コンストラクターでの throw)で失敗したことを、ツールが原因つきで報告したものであり、ツールの例外ではない(§2.4) |
| 構文解析の失敗(`PARSE_FAILED`) | BookStack: baseline 92 ルート → 最終版 0。Snipe-IT: baseline 0 → 最終版 2 ルート | BookStack: Blade の `@php ... @endphp` が `}` で終わるとき `;` を補わなかったため(FIX1)。ほかの 3 本は baseline でも 0。Snipe-IT: FIX2 で初めて歩かれるようになった 2 本のテンプレート(`resources/views/kits/accessory-edit.blade.php`・`consumable-edit.blade.php`)で出た。ディレクティブの引数の PHP の配列の中に Blade の echo 記法 `{{ … }}` が入っており、そのままでは PHP として読めない。`PARSE_FAILED` は正しい報告である。FIX2 でテンプレートが読まれて、これまで見えなかったものが見えるようになった(FIX1 と同型) |
| `model:show` の失敗 | 9 モデル(BookStack 3・Snipe-IT 2・Laravel.io 4。Firefly III・Koel は 0) | ツールは止まらず、標準エラーに note を出し、該当するモデルを持つルートを `unknown` にする(設計どおり)。原因はアプリまたは Laravel 側の例外である。BookStack は、モーフマップが未定義(`User`)と、抽象クラスを生成できない(`JointPermission`・`Entity`)。Laravel.io は `Thread`・`Reply`・`Article`・`Tag`。Snipe-IT は `License`・`LicenseSeat` |
| 見逃し(判定が実際より軽い) | 1 件(原因は 1 つ) | リソースが自分のプロパティで持つモデルのリレーションを読むと、遅延読込が事象にも `gap` にも出ず、`O(1)`・`high` と出る形(FIX3)。検証用アプリの試験で再現して直した。実アプリの確定 5 本については §4 に書く |
| 偽の下限(判定が実際より重い) | 1 ルート | Koel の `GET /api/duplicate-uploads` で、読み込み済みのリレーションを失い、存在しない遅延読込から `≥O(N)` が出た(FIX3 の副作用。FIX5 で解消) |
| 報告の欠け | 1 件(原因は 1 つ) | 同じ行・同じコードで原因の違う `gap` が 1 件に潰れ、片方が出力に残らなかった(FIX4) |
| 読み違い(直した) | 1 件(原因は 1 つ) | ビュー名に `/` を含むものが `VIEW_NOT_FOUND` になる(FIX2。D36 で修正と裁定)。`VIEW_NOT_FOUND` の 233 件のうち名前に `/` を含むものが 204 件で、すべて Snipe-IT。これを原因に含むルートは 187 本。ほかのアプリでは 0 件。FIX2 の後は、`VIEW_NOT_FOUND` が 29 件・28 ルート(「/」を含む名前は 0 件)になった |
| 過検知(正当な読みを `gap` にする) | 下記 | FIX3 の防壁が、DB に触れない enum の `->value`・`->name` の読みも `gap` にする |

FIX3 の防壁の `gap` は、全体で 2,966 件(838 箇所、471 ルート)である(FIX2 の前は 2,855 件・787 箇所・450 ルート。増えた分は Snipe-IT で、763 件から 874 件になった。共通レイアウトが歩かれたため)。このうち `->value`・`->name` の読みは 683 件(`->value` 612 件・`->name` 71 件、329 箇所)で、ソースの 1 行で `Class::CASE->value` の形と確かめられた(enum と確実な)読みは 568 件(271 箇所)ある。内訳は Firefly III 423 件(246 箇所)・Snipe-IT 136 件(16 箇所)・Koel 7 件(7 箇所)・Laravel.io 2 件(2 箇所)・BookStack 0 件である。変数に入った enum は形から区別できないので、683 件には enum でない読みも混じる。判定・確度・下限はこの過検知で動かない。例外にするかどうかは、D39 で「今回は入れない。次の判定を緩める段の第 1 候補」と裁定された(提案は §6)。

「明らかにおかしい出力」の数え方について。ここでは、実アプリの測定と検証用アプリの試験で、仕様に照らして誤りと判断し、原因を特定できたものを 1 原因 1 件と数えた(FIX2 の後も、この表の件数は増えていない。`PARSE_FAILED` の Snipe-IT 2 ルートは、ツールの誤りでなく正しい報告なので数えない)。誤りの出たルートの数でなく、原因の数である。`gap` の増減(§3.2.1)は、この件数に含めない。

## 4. 判定の抜き取り確認(T4)

指示書の T4 は、判定が確定した(`unknown` でない)ルートを全アプリ合わせて 15 本以上抜き取り、うち半数以上を O(1)・確度 high とし、コードを追って正否を確かめる、という基準だった。結果を先に書く。

- 確定したルートは 5 本しかなく(5 アプリ・2091 ルート)、基準の 15 本は母数の段階で満たせなかった。基準は緩めていない。
- 確定した 5 本は全数を確認し、5 本とも正しかった。見逃しも過検知も 0 件である。ただし 5 本はどれも定数を返すだけの最も易しい形で、この結果を一般のルートに広げて読んではいけない。
- 補いとして、下限つきの `unknown` から 18 本を抜き取り、下限が過大でないかを確かめた。下限を主張していた 24 軸のうち 8 軸が過大だった。**下限は参考値であり、保証ではない。**
- 確定の外で、見逃しが 1 型だけ見つかった(リソースの自前プロパティ経由の遅延読込)。最小の再現アプリで再現し、直した(§5 の FIX3)。実アプリでは別の `gap` に救われて `unknown` のままだったため、数字には現れなかった。直した後も、実アプリの当該ルート(Koel `GET /api/playlists`)の `query` 軸は `unknown`・下限なしのままで、下限の取りこぼしは残る(§4.4.1)。

### 4.1 T4 の扱い

確定したルートは次の 5 本だけである(全アプリで `query` 軸・`memory` 軸とも O(1)、確度 high)。

| アプリ | 確定 / 解析したルート数 |
| --- | ---: |
| BookStack | 0 / 341 |
| Firefly III | 2 / 641 |
| Koel | 3 / 260 |
| Laravel.io | 0 / 97 |
| Snipe-IT | 0 / 752 |
| 計 | 5 / 2091(0.2%) |

指示書の「15 本以上・半数は O(1)・high」は、母集団が 5 本しかないので満たせない。この事実は、判定の質の問題ではなく、確定するルートがほぼ無いという結果そのものである(確定が少ない原因は §3.3)。

この扱いは、判断記録 D34 に裁定として記録した。

- (a) 確定した 5 本の全数確認と、下限つき `unknown` の追加確認をもって T4 とみなす。
- (b) 確定の多いアプリを加えて 15 本の基準を満たす、という案は退けた。D34 の補足は、その理由を、基準を満たすためにアプリを足すより、母数の全数を確かめるほうが確かめた範囲が明確になるため、と推測している。

どちらの場合も、判定の規則(D21「分からないを安全にしない」)は緩めていない。

あわせて D33 に、判定を緩める変更を入れる段では、その変更で新たに確定したルートの抜き取り確認を必ず工程に入れる、と記録した。今回は「測るだけ」で緩めていないので、この確認は発動していない(§4.6)。

### 4.2 確定した 5 本の全数確認

対象は Firefly III(v6.7.9、`d65e916`)の 2 本と Koel(`da0e70f`)の 3 本である。コードは読むだけで、書き換えていない。ルート定義からコントローラー、呼び出し先、基底コントローラーとミドルウェアまでを人の目で追い、あわせてコンテナの中で 1 リクエストを実際に流してクエリ数を数えた。ファイルは各アプリのリポジトリ内の相対パスで示す。

| ルート | peak-sim の判定 | 追った経路 | 判定 | 根拠(ファイルと行) |
| --- | --- | --- | --- | --- |
| Firefly III `GET /api/v1/rules/validate-expression` | `query` O(1)・`memory` O(1)・high | ルート定義 → コントローラー → フォームリクエスト → 検証規則 → 式の検査 → 基底コントローラーのミドルウェア | 正しい | `routes/api.php:562`。本体は定数の JSON を返すだけ(`app/Api/V1/Controllers/Models/Rule/ExpressionController.php:42`)。検証規則は設定表を 1〜2 回引き(`app/Rules/IsValidActionExpression.php:40`)、式の検査は入力文字列の長さだけに依存する CPU 処理でデータ件数に依らない |
| Firefly III `GET /json/box/available` | 同上 | ルート定義 → コントローラー → 基底コントローラー → ミドルウェア(`Range` など) | 正しい | `routes/web.php:802`。本体は中身のない JSON を返すだけ(`app/Http/Controllers/Json/BoxController.php:51-54`)。`Range` ミドルウェアの取得は先頭 1 行だけ(`app/Http/Middleware/Range.php:121-154`)で、行数に比例しない |
| Koel `/rest/ping{format?}` | 同上 | ルート定義 → コントローラー → 応答の組み立て → ミドルウェア(認証・ライセンス確認など) | 正しい | `routes/subsonic.php:60`。本体は固定の応答を返すだけ(`app/Http/Controllers/Subsonic/PingController.php:10-13`)。DB を引くのは認証のユーザー 1 件取得(`app/Repositories/UserRepository.php:50-53,66-69`)など、要求 1 件あたり定数 |
| Koel `/rest/getMusicFolders{format?}` | 同上 | 同上 | 正しい | 固定配列 1 要素を渡すだけ(`app/Http/Controllers/Subsonic/GetMusicFoldersController.php:12-18`)。以降は ping と同じ |
| Koel `/rest/getOpenSubsonicExtensions{format?}` | 同上 | 同上 | 正しい | 定数の配列(2 要素)を返すだけ(`app/Http/Controllers/Subsonic/GetOpenSubsonicExtensionsController.php:14-17`)。以降は ping と同じ |

**5 本とも、定数を返すだけの最も易しい形である。** データ件数でループする箇所も、DB のデータを集める箇所も無い。見逃しも過検知も 0 件だが、これは「易しい形のルートでは判定が当たる」ことを示すだけで、難しい形のルートで当たることは示さない。

**O(1)・high でも、実測では定数本のクエリがある。** この定数はミドルウェア(認証、ライセンス確認、設定の読み込みなど)に由来し、peak-sim の出力には現れない。`analysis_scope.not_covered` にミドルウェアを対象外と明記してあり(D30)、定数コストの数え方はフェーズ3の前に設計する(D31)。実測は次のとおりである。

| アプリ | 実測のクエリ数(1 リクエスト) | データを増やしたときの変化 |
| --- | --- | --- |
| Koel(3 本) | 3〜4 本 | ユーザーを 300 人足しても変化なし(トランザクション内で作り、最後に巻き戻した) |
| Firefly III の API | 2〜5 本 | `transaction_journals` と `transaction_groups` を各 0 → 50,000 行に増やしても変化なし |
| Firefly III の画面 | 6〜14 本 | 同上(14 本はそのプロセスでの 1 回目、6 本は 2 回目。2 回の別プロセスで同じ値が出た) |

1 回目が多いのは、プロセスの最初に 1 度だけ走る初期化の読み込みがあるためと見ている。実測の記録では、API 側の 1 回目の増分は、設定表に既定値を挿入する 1 回限りの書込(2 回目以降は消える)だった。画面側の 1 回目の内訳(14 本のうちどれが 1 度きりか)までは分けて確かめていない。また、データを増やしても 1 回目・2 回目とも本数は変わらない。

「O(1)・high」は、データ件数に対する増え方の判定である。「クエリが 0 本」とは読まないこと。リクエスト単位のピークメモリは測っていない。経路上のデータ取得が単一行だけであることをコード読みで確かめた。

**独立の追い直し。** 別の者が、5 本すべてを、最初の確認者の記録を見る前提でなく、ルート定義とミドルウェアから独立に追い直した。結果は全て一致した。追い直しでは、Koel の認証が 3 方式を順に試すが、どの方式も 1 件の取得と比較だけで全ユーザーを走査しないこと、ライセンスの確認が 1 件の取得とキャッシュであることも確かめた。実測のプローブは再実行していない(Docker が要る)。コードの追跡と実測の 2 つの独立した証拠が揃っている。

### 4.3 追加の確認:下限つき unknown の抜き取り(18 本)

確定が 5 本では「下限の主張が過大でないか」を確かめる材料にならないので、下限つきの `unknown` ルートから抜き取って確かめた。確かめたのは、`unknown` に添えた下限(`≥O(N)`、`≥O(N*M)`)が、実際のコードの増え方に照らして過大でないかである。

#### 4.3.1 抜き取り方

- **母集団:** `controller` ルートのうち、`query` 軸か `memory` 軸が `unknown` で、下限が付いているもの。511 本ある(抜き取りの時点の出力、つまり FIX2 の前の集計。BookStack 109・Firefly III 107・Koel 14・Snipe-IT 281・Laravel.io 0)。`query` 軸だけで数えると 137 本(`≥O(N)` 125・`≥O(N*M)` 12)で、`memory` 軸は 510 本、どちらかの軸では 511 本になる。
- **抜き取り:** 乱数を使わない。ルートを (アプリ, パス, メソッド, アクション) のバイト順に並べ、アプリごとに「どちらかの軸が `≥O(N*M)`」の層(NM 層)と「`≥O(N)` のみ」の層(N 層)に分けて、層の中から等間隔に取る(層内の n 本から k 本を、位置 `(2i+1)*n//(2k)` で取る)。同じ入力からは同じ標本が出る。
- **本数:** 母数のある 4 アプリで計 18 本。NM 層は各 2 本、N 層は BookStack 3・Firefly III 3・Koel 2・Snipe-IT 2 本である。層の大きさ(NM 層 / N 層)は BookStack 18 / 91、Firefly III 3 / 104、Koel 6 / 8、Snipe-IT 31 / 250 である。Laravel.io は母数が 0 なので標本が無い。
- **判定の語:** **正しい**(下限の根拠が実コードにあり、実際もその下限以上に重い)、**過大**(根拠となる増え方が実際は起きない、または N の意味が違う)、**低すぎ**(実際はさらに重い、または下限が付かなかった軸に重い経路がある)、**判定不能**(追えなかった。推測で埋めない)。
- **方法:** 出力の `evidence`・`gaps` を着手点にして、コントローラーから呼ばれるものをコードで追った。実行はしていない。判定した本人が自分で確度を付けており、確度が中のものは下に示す。

この標本は層別の等間隔抜き取りで、確率標本ではない。18 本は小さく、ここから出る比率を 511 本全体に当てはめてはいけない。

#### 4.3.2 結果

標本は 01〜18 の番号で示す。「判定せず」は、その軸について判定を記録しなかったことを指す。

| 標本 | アプリ | ルート | 層 | `query` 軸(出力 → 判定) | `memory` 軸(出力 → 判定) |
| --- | --- | --- | --- | --- | --- |
| 01 | BookStack | `PUT /api/pages/{id}` | NM | `≥O(N)` → 正しい(条件つき) | `≥O(N*M)` → **過大** |
| 02 | BookStack | `PUT /books/{slug}` | NM | `≥O(N*M)` → **過大**(確度中) | `≥O(N*M)` → 正しい |
| 03 | BookStack | `PUT /api/content-permissions/{contentType}/{contentId}` | N | 判定せず | `≥O(N)` → **過大**、かつ**低すぎ** |
| 04 | BookStack | `GET /books/{bookSlug}/page/{pageSlug}/edit` | N | 判定せず | `≥O(N)` → **過大**、かつ**低すぎ** |
| 05 | BookStack | `GET /settings/roles/delete/{id}` | N | 判定せず | `≥O(N)` → 正しい |
| 06 | Firefly III | `DELETE /api/v1/transactions/{transactionGroup}` | NM | `≥O(N*M)` → 正しい | `≥O(N*M)` → 正しい |
| 07 | Firefly III | `POST /transactions/convert/{transactionType}/{transactionGroup}` | NM | `≥O(N*M)` → 正しい | `≥O(N*M)` → 正しい |
| 08 | Firefly III | `GET /api/v1/budgets` | N | 下限なし → **低すぎ** | `≥O(N)` → 正しい(根拠の場所は弱い) |
| 09 | Firefly III | `POST /api/v1/recurrences/{recurrence}/trigger` | N | 判定せず | `≥O(N)` → **過大**(確度中) |
| 10 | Firefly III | `POST /piggy-banks/remove/{piggyBank}` | N | 下限なし → **低すぎ** | `≥O(N)` → 正しい |
| 11 | Koel | `DELETE /api/duplicate-uploads` | NM | `≥O(N)` → 正しい | `≥O(N*M)` → **過大** |
| 12 | Koel | `GET /api/playlists` | NM | 下限なし → **低すぎ** | `≥O(N*M)` → 正しい(根拠の帰属は誤り) |
| 13 | Koel | `POST /api/playlists/collaborators/accept` | N | 下限なし → 正しい | `≥O(N)` → **過大** |
| 14 | Koel | `POST /api/songs/by-folders` | N | 下限なし → 正しい(上限 500 件で頭打ち) | `≥O(N)` → 正しい |
| 15 | Snipe-IT | `GET /api/v1/hardware/bytag/{any}` | NM | `≥O(N*M)` → **過大**(条件つき) | `≥O(N*M)` → 正しい |
| 16 | Snipe-IT | `GET /locations/{locationId}/printassigned` | NM | 下限なし → 判定不能 | `≥O(N*M)` → 正しい |
| 17 | Snipe-IT | `POST /api/v1/consumables/{consumable}/checkout` | N | 下限なし → 判定不能 | `≥O(N)` → 正しい |
| 18 | Snipe-IT | `GET /hardware/quickscancheckin` | N | 下限なし → 判定不能 | `≥O(N)` → 正しい |

判定の件数を軸ごとに数えると、次のとおりである(1 行が 1 つの判定。標本 03・04 の `memory` 軸は「過大」と「低すぎ」の 2 つを数える)。

| 軸 | 判定の件数 | 正しい | 過大 | 低すぎ | 判定不能 |
| --- | ---: | ---: | ---: | ---: | ---: |
| `query` | 14 | 6 | 2 | 3 | 3 |
| `memory` | 20 | 12 | 6 | 2 | 0 |
| 計 | 34 | 18 | 8 | 5 | 3 |

このうち、出力が下限を主張していた軸(上の表で `≥O(…)` と書いた 24 軸)だけを見ると、正しいのが 16、過大が 8 である。過大の内訳はアプリごとに BookStack 4 / 7、Firefly III 1 / 7、Koel 2 / 5、Snipe-IT 1 / 5 である。

注:

- 「正しい」に、下限が付かない(次数 0 と出た)ことが妥当だった標本 13・14 の `query` 軸を含む。標本 14 は上限 500 件で頭打ちだが、1 曲ごとの遅延読込で最大 1500 本ほどのクエリになりうる。定数としては大きい。
- 標本 06・07・09 には、下限より重い可能性があるが、読めなかった先(Firefly III の `JournalDestroyService`、`JournalUpdateService`、`CreateRecurringTransactions` の `handle`)があり、判定不能と記録した。件数の表には入れていない。標本 08 でも、リポジトリの実装(`OperationsRepository` の全体)は未読である。
- Firefly III の標本 06・07・10 の N は、ひとつの親が持つ子の数(1 つの取引グループの仕訳、1 つの仕訳の取引、1 つの貯金箱の口座)である。peak-sim は hasMany の子の数をそのテーブルの件数 N として扱う。これは peak-sim の定義に沿っているので、正しい側に数えた。実際の件数が小さいか(分割取引の分割数は通常 1〜数件)は別の話である。
- 標本 01 の `query` 軸は、アプリの名前変更でスラッグ(URL)が変わるときだけ走る経路の下限である。「走りうる経路の下限」として正しいと判定した。標本 15 の条件つきとの線引きは、下限の定義の問題である(§4.3.5)。

#### 4.3.3 下限が過大になった 3 つの型

過大 8 軸は、次の 3 つの型に分かれる。対象アプリのコードは引用せず、場所だけを示す。

**型 1: 条件つきの事象や static キャッシュつきの事象を数える(1 軸)。**

- Snipe-IT 標本 15 の `query` 軸。下限 `≥O(N*M)` の根拠の事象のひとつは、資産ごとのコンポーネントのループ内にある注文の取得である(`app/Http/Transformers/AssetsTransformer.php:208-219`、`app/Models/Traits/HasOrders.php:136`)。このループは、リクエストのパラメーター `components` が `true` のときだけ動く(`AssetsTransformer.php:206`)。peak-sim はこのパラメーターの取得を追えず(`VENDOR_CALL_NOT_MODELLED`)、条件を評価しないまま事象を下限に数えた。もうひとつの根拠の事象、設定表の取得(`app/Models/Setting.php:133`)は static キャッシュつきで、1 リクエストあたり 1 回しか走らない。
- 実際に実在するのは「資産数 × 資産あたりのコンポーネント数」のクエリで、パラメーターを付けたときに限る。出力の `m_table` はカスタムフィールドだが、`query` 軸の実体はコンポーネントである。
- 初稿の確認では「フィールドのループ内にクエリが無いので過大」としていたが、追い直しで、コンポーネントのループにクエリがあることが分かり、判定を直した(§4.5)。

**型 2: 回数が未知のループを入れ子と数える(2 軸)。**

- BookStack 標本 01 の `memory` 軸と標本 02 の `query` 軸。`app/Entities/Tools/MixedEntityListLoader.php:56-60` の、型ごとのループ(page・chapter・book・bookshelf の高々 4 種、つまり定数)の中の `select` を入れ子のループと数え、次数を 2(`≥O(N*M)`)としたと読める。同じ辺りに `LOOP_BOUND_UNKNOWN` と `RELATION_TARGET_UNKNOWN` の `gap` が並んでおり、peak-sim は型の個数を知らない。実際の増え方はどちらも O(N) である。標本 01 は 1 行が本文つきで大きいが、それは N*M とは別の話である。
- 標本 02 の `query` 軸の確度は中である(更新ループが重複除去後の参照元の数だけ回る、という数え方までは追い直していない)。
- D32 は、回数が分からないループを N 倍と決めると過大になりうるので `gap` にして `unknown` とする、と定めている。下限の次数の数え方が、その趣旨と噛み合っていない可能性がある。

**型 3: N(または M)の表が実態の軸でない(5 軸)。**

| 標本 | 出力の N(M) | 実際に増える軸 | 場所 |
| --- | --- | --- | --- |
| 03 BookStack | `roles` | リクエストが挙げた `role_id` の行だけを読む(入力に比例) | `app/Entities/Tools/PermissionsUpdater.php:124-130` |
| 04 BookStack | `page_revisions` | 直近 60 分の他者の下書き = 同時に編集している人数 | `app/Entities/Tools/PageEditActivity.php:92-104` |
| 09 Firefly III | 当日作られる取引グループ | その 1 回のトリガーが作るグループだけ(繰り返しの回数程度の小さな数)。確度中 | `app/Jobs/CreateRecurringTransactions.php:272,279-289` |
| 11 Koel | M = `genres` | 実コードで読まれるのは `id`・`title`・`artist_name` だけで、`genres` は読まれない | `app/Repositories/DuplicateUploadRepository.php:14` |
| 13 Koel | `playlists` | 扱うのは 1 つのプレイリストで、増えるのはそのメンバー数・フォルダ数 | `app/Services/Playlist/PlaylistCollaborationService.php:27-45` |

この型では、下限の次数そのものは実コードにあることが多いが、数える単位(何の件数が N か)が違う。出力が `n_table` を軸ごとに 1 つしか示さないことも、読み違いの一因になりうる(提案は §6)。

BookStack の標本 04 は、根拠の N が違う(過大)が、結論の `≥O(N)` 自体は別の軸で成り立つ(その頁の全コメントを読む)。標本 08(Firefly III)の `memory` 軸も、結論は正しいが、出力が示した根拠の場所は弱い(ページ分の件数に限られる)。実際に件数で増えるのは、出力から見えていない別の経路(全 budget を `get()` で読む)にある。

#### 4.3.4 低すぎ(下限が付かなかった、またはさらに重い経路がある)と判定不能

低すぎの 5 件は、どれも見逃しではない。出力は `unknown` と言っており、O(1) とは言っていない。ただし、出力では `unknown` で次数 0(下限なし)と表示されるため、「下限が O(1)」と読めてしまう(提案は §6)。

- **Firefly III 標本 08・10 の `query` 軸:** 下限が付かなかった。どちらも、リポジトリのインターフェースの束縛が解決できず(`UNRESOLVABLE_BINDING`)、その先の口座ごとの取得・保存・イベント発行が見えていない。実際は口座の数に比例するクエリがある。
- **Koel 標本 12 の `query` 軸:** 1 つのプレイリストごとに、所有者・フォルダ・共同編集者の遅延読込が走る(`app/Http/Resources/PlaylistResource.php:52-56`)。N に比例するのに、出力は次数 0・下限なしだった。§4.4.1 で詳しく書く。
- **BookStack 標本 03 の `memory` 軸:** 本の権限を更新すると、本の子(章・頁)の全件 × 全ロールの行を組み立てて挿入する(`app/Permissions/JointPermissionBuilder.php:202-234`)。この経路が出力に現れない。§4.4.2 で書く。
- **BookStack 標本 04 の `memory` 軸:** 編集画面がその頁の全コメントを読む(`app/Activity/Tools/CommentTree.php:148-151`)。`gap` には挙がったが、下限の根拠の軸には入らなかった。

判定不能の 3 件はいずれも Snipe-IT の `query` 軸である。

- **標本 16:** 抜き取りの時点では、`VIEW_NOT_FOUND` は偽だった(`resources/views/locations/print.blade.php` は実在する)。ビュー名に `/` を含むと見つからないと扱う不具合(FIX2)が原因で、実際は少なくとも O(N) と読める(`print.blade.php:92-100,176-190` のループ内の遅延読込)。ツール側の誤りが下限を出せない原因になっていた。**FIX2 で直した後の結果は次のとおり。** この `VIEW_NOT_FOUND` は消え、テンプレートが読まれるようになった(このルートの `gap` は 12 件から 106 件、`events_found` は 10 から 22 に増えた)。しかし `query` 軸は、FIX2 の後も `unknown`・下限なし(次数は `null`)のままである。コードで読んだ O(N) の遅延読込は、出力の下限に現れていない。つまり、偽の `VIEW_NOT_FOUND` という原因は取り除かれたが、下限の取りこぼしが残る。`memory` 軸の `≥O(N*M)` は FIX2 の前から変わらない。
- **標本 17:** ループの回数の上限(`checkout_qty`)が、直前の残数の判定で抑えられる。上限は在庫数で、テーブルの行数の N とは別の軸である。peak-sim は `LOOP_BOUND_UNKNOWN` のままで、この抑え込みを追えない。
- **標本 18:** クエリは `pluck` の 1 回だけで、定数と読める。出力が `unknown` なのは、`trans()` などの追えない呼び出しによる。

#### 4.3.5 読み方:下限は参考値であり、保証ではない

- 下限を「このルートは少なくともこの重さである」と読んでよいのは、その事象が必ず起き、回数が N 以上と分かっている場合に限る。上の過大の 3 つの型は、この条件を満たさない事象を下限に数えたものである。
- 18 本の標本では、見逃し(低い次数と言い切って、実際は重い)は 0 件だった。これは、標本がすべて `unknown` で、O(1) と言い切ったルートが無いためである。見逃しの有無について、この標本は何も言っていない。
- 下限が過大になる型は複数ある。上の 8 軸の割合を、下限つき `unknown` 全体(511 本)に当てはめることはできない。
- 下限の定義の見直し(上の条件を満たす事象だけを下限に数える、など)は、出力スキーマの仕様(D27・D30)に触れる。今回は変えていない。提案は §6 に書く。

### 4.4 見つかった見逃し

#### 4.4.1 リソースの自前プロパティ経由の遅延読込が `O(1)`・high になる(FIX3)

**症状。** Koel 標本 12 の追跡で、`query` 軸の遅延読込が事象にも `gap` にも出ていないことが分かった。`app/Http/Resources/PlaylistResource.php:52-56` は、プレイリストごとに所有者・フォルダ・共同編集者を遅延読込するが、出力の事象は `app/Repositories/PlaylistRepository.php:18` の `select playlists` と eager load だけだった。このルートが `unknown` なのは、別の `gap`(`app/Http/Resources/PlaylistResource.php` の 38・39・42・45・58 行などの `VENDOR_CALL_NOT_MODELLED`)が付いたからである。他に `gap` が無ければ、O(1)・high と出ていた可能性がある。

**最小の再現。** orders / customers の最小アプリを作り、リソースの書き方を変えて 15 ルートを解析した。真の姿は、顧客を注文ごとに遅延読込する N+1 である。

| リソースの形(読み方) | 出力 | 正しいか |
| --- | --- | --- |
| 宣言済みプロパティ(promoted でない)経由で `$this->order->customer` を読む | `query` O(1)・high・`gap` 0 | 誤(見逃し) |
| コンストラクターの promoted 引数で受けて `::collection` で包む | 同上 | 誤(見逃し) |
| 同じ形を `map` で 1 件ずつ `new` して包む | 同上 | 誤(見逃し) |
| `$c = $this->order->customer;` とローカル変数に受ける | 同上 | 誤(見逃し) |
| `$this->unless(…, $this->order->customer->name)` の中で読む | 同上 | 誤(見逃し) |
| アクセサ経由(`$this->order->owner`) | 同上 | 誤(見逃し) |
| コンストラクター無しで `$this->customer->name`(モデルへの転送) | `query` O(N)・high | 正 |
| `$this->resource->customer->name` | `query` O(N)・high | 正 |
| `make` / `new` で 1 件だけ包む | O(1)・high | 答えは正(偶然)、根拠の事象は欠ける |

**原因。** 落ちる条件は、リソースのコンストラクターの形ではなく、「リソース自身が持つプロパティ(`resource` 以外の名前、例: `$this->order`)を読んでから、その先のプロパティを読む」ことである。リソースの `$this->xxx` は、`resource` 以外でも、包んだモデルの列への転送として読まれていた。そのため `$this->order` は値(PlainT)になり、値へのプロパティ読みは `gap` を出さずに通された。一方、値へのメソッド呼び出しは `gap` になる。この非対称が原因である(`src/Analysis/Serialization/ResourceHandler.php`、`src/Analysis/Engine/CallResolver.php`)。

**直し。** FIX3 の 2 つ(§5)。(1) リソースが宣言した(promoted を含む)クラス型のプロパティは、宣言された型で読む。(2) 値へのプロパティ読みを `gap` にする。どちらも判定を軽くしない。先に赤の試験を足し、上の 6 形が O(N) になり、対照が変わらないことを縛った。

**実アプリでは顕在化しなかった。** 確定した 5 本はリソースを使わない固定応答なので、この不具合の影響を受けない。リソースを使う実アプリのルートは、他の `gap` に救われて `unknown` のままだった。つまり、確定ルートが 5 本しかなかったから見逃しが数字に現れなかっただけで、判定を緩めて確定するルートが増えれば、O(1)・high の誤判定として現れうる型である。FIX3 の後の再測定で、実アプリ 5 本の判定が軽く変わったルートは無かった(§5)。

**直した後も、実アプリの当該ルートは直っていない。** 直ったのは最小の再現(orders / customers)で、Koel `GET /api/playlists` の `query` 軸は、FIX3 の後も直前と同じ `unknown`・次数 0・下限なしである(最終の再測定の出力で確かめた)。変わったのは `gap` が 8 から 11 に増えたことだけである。増えた 3 つは、`app/Repositories/PlaylistRepository.php:50` の `Authenticatable->playlists()` と、`app/Models/Playlist.php:81・105` の `Attribute` アクセサの `shouldCache()` が追えないこと(クロージャへの呼び出しが未モデル)である。FIX3 の後は、リソースが持つ所有者のアクセサの中まで歩いて、この `gap` に当たる。遅延読込(所有者・フォルダ・共同編集者)はその `gap` の先にあって下限に現れない。すなわち、見逃しの危険(O(1)・high と言い切る)は `gap` で `unknown` が守られて塞がれたが、下限の取りこぼしは残る。

**FIX3 の副作用(FIX5)。** FIX3 の後の再測定で、Koel の 1 ルート(`GET /api/duplicate-uploads`)に、実際には無い `query` の下限 `≥O(N)` が付いた。FIX3 の副作用である。FIX5 で解消し(§5)、最終の再測定で、このルートの `query` は修正前と同じ(`unknown`・下限なし)に戻った。FIX5 の前の版との差は、このルート 1 本だけだった。

#### 4.4.2 見逃しではなかった疑い

見逃しの疑いは 3 つ挙がり、調べた結果、見逃しは上の 1 型だけだった。残りの 2 つは別々の機構で、共通の根は無かった。

**BookStack 標本 03:抽象型を受ける引数への呼び出しが、事象にも `gap` にも出ない。** `app/Entities/Tools/PermissionsUpdater.php` の 48・50・54・61・68・69 行(`permissions()` の削除、`createMany`、`save()`、`rebuildPermissions()` など)が出力に現れない。引数が抽象型 `Entity` だからではないかと疑ったが、違った。

- 15 の最小事例(具象・抽象・interface・型なし・ユニオンなど)で切り分けた。引数の型が抽象かどうかは無関係で、差を生むのは受け手が「型不明(UnknownT)」かどうかだけだった。
- 受け手が型不明のとき、下流の呼び出しは追われない(引数だけを評価して受け手をそのまま返す。`src/Analysis/Engine/CallResolver.php:460-464`)。これは設計である。型不明を作る箇所は必ず `gap` を出すので(`src/Analysis/Engine/EvalContextImpl.php:50-62`)、起点の `gap` は出力にある。
- 標本 03 の起点は、`app/Permissions/ContentPermissionApiController.php:71` の `newQuery()` が `UNKNOWN_BUILDER_METHOD` の `gap` になったことだった。この `gap` は `query`・`memory` の両軸に効くので、軸は `unknown` になり、15 事例のどれでも O(1)・high は出なかった。
- 失われるのは、下流の呼び出しの場所と、呼び先(`rebuildPermissions()` から `JointPermissionBuilder`)の重い処理が下限に現れないことである。見逃しではなく、下限の取りこぼしである。D21 の字義は「追えなかった箇所は `gap` として出す」だが、実装は「追えなくなった起点」を `gap` に出す。読み手は、下流で何が追われなかったかを知れない(提案は §6)。
- 出力の `evidence` は、成長する事象(次数 1 以上)だけを載せる(`src/Judge/Evidence.php:44-50`)。48・50・54・61・68 行が出ないのは、受け手が既知でも同じである。

**BookStack の下限の後退(5 軸)。** 最初の再測定で、BookStack の 4 ルート・5 軸の下限が `O(N)` から「なし」に後退した。無関係なテンプレートの修正で別のルートの事象が消えたのではないかと疑ったが、違った。原因は修正ではなく、再測定のときの DB が空(未 migrate)だったことである。

- 版(修正前 / 後)× DB(空 / migrate 済み)の 2×2 で測った。migrate 済みの DB で版だけを変えると、`query`・`memory`・`events_found`・確度が違うルートは 0 本だった(PARSE_FAILED が 92 から 0 になったのみ)。
- 空の DB では `model:show` が失敗し(`Table … doesn't exist`、SQLSTATE 42S02)、`Bookshelf` と `Image` のモデル情報が欠けて、`gap` が付き、下限が弱まった。`model:show` の失敗の note は 6 件から 10 件に増えていた。`gap` が付いているので、黙った脱落ではない。
- これは解析の非決定ではなく、入力(`model:show` の結果)が環境で変わるという測定条件の問題である。§2.4 に、migrate 済みの DB を前提にすると書いた。BookStack の「修正後」の数字は、migrate 済みの DB で取り直したものを正とする。

どちらの疑いでも、判定の規則は変えていない。

### 4.5 独立の追い直しの記録

抜き取りを行った者とは別の者が、数え直しと追い直しをした。

**数え直し。**

- 確定ルートの数を、5 アプリの出力から数え直した。5 / 2091(BookStack 0 / 341、Firefly III 2 / 641、Koel 3 / 260、Laravel.io 0 / 97、Snipe-IT 0 / 752)で一致した。最終の集計とも一致する。
- 下限つき `unknown` の数も、D34 の付記と同じ数(`query` 軸 137 = `≥O(N)` 125 + `≥O(N*M)` 12、`memory` 軸 510、どちらかの軸 511)を出力から数え直し、一致した。
- 最終版の出力が、修正前の出力(抜き取りの元)と判定で同一であることも確かめた。5 アプリの全 2091 ルートを比べて、`query`・`memory`・確度が違うルートは 0 本だった。抜き取りの結論は、最終版にも当てはまる。

**抜き取りの追い直し。**

- 確定した 5 本(Firefly III 2 本・Koel 3 本)の全てを、独立にコードで追い直し、一致した(§4.2)。
- 下限つき `unknown` の標本では、次を追い直した。
  - 標本 12:出力とコードの両方で、追跡が正しいと確かめた。ここから §4.4.1 の調査が始まった。
  - 標本 01・02:型ごとのループを次数に数えたという読みが、出力とコードの両方と整合することを確かめた。
  - 標本 03:事象も `gap` も出ない行が、出力にも無いことを確かめた。
  - 標本 09:方向性(1 日分の作成結果に限られる)の文脈を確かめた。
  - 標本 15:**追い直しで判定を直した。** 初稿は「フィールドのループ内にクエリは無いので `query` 軸は過大(O(N) までは確認、O(N*M) は確認できず)」だった。追い直しで、コンポーネントのループにクエリが実在し、パラメーターを付けたときに限ることが分かり、「過大(条件つき)」とした。
- 追い直していない標本がある。標本 06〜10 の大半、11・13・14・17・18、標本 02 の更新ループの数え方である。標本 15 で下限の根拠の取り違えが見つかったので、他の「正しい」にも同種の取り違えが無いとは言い切れない。

**修正の確認。** 適用した修正(FIX1・FIX3・FIX4・FIX5。FIX2 と D38 は、その後に直した。§5)について、別の者が全スイートを独立に再実行し、PHP 8.2 と 8.3 の両方で通ること(SKIP 0)を確かめた(§8)。検証用アプリの 24 ルートの期待値は変わっていない。FIX5 については、再測定で Koel の当該ルートの偽の下限が消えたことも確かめた(§4.4.1)。

### 4.6 この節から言えること・言えないこと

言えること:

- 確定した 5 本の範囲では、見逃しも過検知も 0 件である。独立の追い直しとコンテナ内の実測が、これを支える。
- 確定の外で、見逃しの型がひとつ(リソースの自前プロパティ経由の遅延読込)見つかり、最小の再現で確かめて、判定を軽くしない方向で直した。ただし実アプリの当該ルート(Koel `GET /api/playlists`)は `unknown` のままで、下限の取りこぼしが残る。見逃しの危険は `gap` で `unknown` が守られて塞がれたが、下限が付くようになったわけではない。
- 下限は、過大になる型が少なくとも 3 つある。標本の範囲では、下限を主張した 24 軸の 3 分の 1 が過大だった。
- FIX2 と D38 の後も、確定したルートは同じ 5 本で、`unknown` から確定に動いたルートは 0 本である(§4.7)。FIX2 で下限が新しく現れた 19 本のうち、コードで確かめたのは 2 本である。

言えないこと:

- 確定の母数が 5 本で、いずれも最も易しい形なので、「見逃し 0」を一般のルートに広げることはできない。
- 下限つき `unknown` の標本 18 本は、判定の質を母集団全体に言うには小さい。
- 判定の規則を緩めた後の確定ルートの質は、今回は測っていない。判定を緩めて確定するルートが増える段では、その新たに確定したルートの抜き取り確認を必ず工程に入れる(D33)。FIX3 の型は、この確認で見つけるべき見逃しの実例である。

`unknown` の多さは、ツールが「分からない」と答えていることの表れで、見逃しではなく、過検知の側(重いかもしれないと答える側)に倒れていることを示す。それが役に立つことと同じではない点は、§0.3 のとおりである。

### 4.7 FIX2 の後に新しく現れた下限 19 本(Snipe-IT)

FIX2 と D38 を入れた版(`9f60add`)で 5 アプリを測り直し、FIX2 の前(`f11b1ba`)と baseline の両方と比べた。D36 の条件 2・3 に従い、`VIEW_NOT_FOUND` の増減・新たに出た `gap`・確定に動いたルートを確かめた。

- **確定に動いたルートは 0 本である。** 5 アプリ・2,091 ルートで、`unknown` から確定に動いた軸は 0 だった。D36 の条件 3(確定に動いたルートが 1 本でもあれば全数をコードで追う)は、該当するルートが無い。D33 の抜き取りも発動しない。
- **`VIEW_NOT_FOUND` は 215 本から 28 本になった**(Snipe-IT は 189 本から 2 本、`gap` の件数は 206 件から 2 件)。D36 が見積もった「`/` 形 204 件・187 ルート」と一致した。ほかの 4 アプリでは、判定・確度・下限・`gap` の件数とも変化が無かった。
- **新たに出た `gap` は、大半が Snipe-IT の共通レイアウトの中の呼び出しである**(`gap` の総数 +137,967。内訳は §3.2.1)。新しいコードは 2 つ、`PARSE_FAILED` が 2 ルート、`MODEL_INFO_UNAVAILABLE` が 1 ルートである(§3.3・§3.6)。
- **下限が現れた(または上がった)ルートが 19 本ある。** 内訳は、クエリ軸の下限が新しく現れたもの 9 軸(`/account`・`/account/view-assets`・`/admin`・`POST /hardware/bulkedit`・`/hardware/{asset}/audit`・同 `checkin/{backto?}`・同 `checkout`・`/reports/licenses`・`POST /users/bulkedit`)、メモリ軸の下限が新しく現れたもの 10 軸(`/account/requestable`・`/account/requested`・`/departments/create`・`/departments/{department}`・同 `edit`・`/depreciations/{depreciation}`・`/hardware/audit/due`・`/hardware/checkins/due`・`/manufacturers/{manufacturer}`・`/suppliers/{supplier}`)、メモリ軸の下限の次数が `≥O(N)` から `≥O(N*M)` に上がったもの 4 軸(`/admin`・`/hardware/{asset}/audit`・同 `checkin/{backto?}`・同 `checkout`)である。**19 本とも重い側への変化で、軽くなったものは無い。** 判定(growth)は `unknown` のままである。
- **2 本を、コードを追って確かめた。** `/account`(クエリ軸の下限)は `app/Models/Traits/HasOrders.php:136` の `order_items` の select などが、`/departments/create`(メモリ軸の下限)は共通ビューの中の `Company.php` の `company_user` などの遅延読込が、実在する事象だった。どちらも、新しく歩かれるようになった共通レイアウトや部分テンプレートの中の事象である。2 本の範囲では、過大の型(条件つきの事象・回数が未知のループ。§4.3.3)ではなかった。
- **残りの 17 本は追っていない。** 下限は参考値であり、保証ではない(§0.3)。新しい下限にも、§4.3.3 の過大の型が混じる可能性は除けない。

## 5. 直した不具合

試運転の中で見つけ、試験を足して直した不具合は 5 件ある(FIX1〜FIX5)。あわせて、出力の読み違いの罠を、裁定(D38)で 1 件直した(出力スキーマを 0.2 にした)。いずれも、判定を軽くする方向の変更ではない。試験を足し、直した後に判定が重く(`unknown` が増える側)なるか、変わらないかのどちらかになることを確かめる方針で直した。最終版の実アプリ 5 本(2,091 ルート)では、baseline と判定(growth)・確度の違うルートが 0 本で、`unknown` から確定に動いたルートも 0 本である。下限が動いたのは Snipe-IT の 19 本だけで、すべて重い側である(§3.2.1)。検証用アプリでは `/service-map-new` が `unknown` から `O(N)`(確度 medium)になったが、これは読み違いを正して N+1 が見えるようになった結果であり、軽くなったのではない。

FIX1〜FIX4 を入れた版では、実アプリで Koel の 1 ルートの下限が重く変わっていた。これは FIX3 の副作用の偽の下限で、FIX5 で解消した(下記 FIX3 の既知の限界も参照)。

FIX2(ビュー名の `/`)は、更新前の版では直すかどうかの裁定を待っていた。D36 で「誤読の修正である」として直すと裁定したので、赤の試験を先に足して直した(下の表)。FIX5 の位置づけは D37、`degree` の出力は D38 で裁定された。

一覧(FIX5 の `def1607` は、赤の試験を足した後に期待値の確度を明記した commit である。D38 の `9f60add` は、出力スキーマの説明を直した commit である):

| 番号 | 種類 | 試験の commit | 直しの commit | 状態 |
| --- | --- | --- | --- | --- |
| FIX1 | 読めないはずのテンプレートが読めない(構文解析の失敗) | `a2be19a` | `bd9e228` | 直した |
| FIX2 | 読み違い(ビュー名の `/`) | `7275a97` | `e48a085` | 直した(D36) |
| FIX3 | 見逃し(判定が実際より軽い) | `f08329d` | `536eeed`・`e9003eb` | 直した |
| FIX4 | 報告の欠け(`gap` が潰れる) | `c156b19` | `18427f0` | 直した |
| FIX5 | 偽の下限(FIX3 の副作用。判定が実際より重い) | `b06fd4b`・`e7e1c17`・`def1607` | `f11b1ba` | 直した(D37) |
| D38 | 出力の読み違いの罠(下限のない `unknown` の次数が 0 と出る) | `bd66635` | `dfdbe07`・`9f60add` | 直した(D38。schema 0.2) |

| 番号 | 症状 | 原因 | 直し | 試験 | 判定を軽くしていない理由 |
| --- | --- | --- | --- | --- | --- |
| FIX1 | BookStack のテンプレートで `PARSE_FAILED` が出て、そのテンプレートの中が一切読まれなかった。Blade では正しい書き方なのに、構文エラーとして扱われた | Blade の `@php ... @endphp` は、本体の末尾を `;` として読む(PHP が閉じタグを `;` と読むのと同じ)。ツールの変換(`src/Analysis/Blade/BladeToPhp.php`)は、本体が `}` で終わるとき文として完結していると見なして `;` を足さなかった。しかし `$x = match (...) { ... }` のように、式が `}` で終わる場合は `;` が要る | 本体が `}` で終わるとき、構文解析器で「そのままで読めるか」「`;` を足せば読めるか」を試し、足せば読めるときだけ `;` を足す。どちらでも読めないものは、そのままにする(`bd9e228`) | 赤の試験を先に足した(`a2be19a`): `match` 式・複数行の `match`・クロージャ・配列・アロー関数で終わるブロック、`foreach` の後に `match` が続くブロック、行番号がずれないこと。直した後も構文エラーのままであるべきもの(`$a = ;`、閉じていない `{`)が `PARSE_FAILED` のままであること、および別ブロックで開いた波括弧や読めない `}` は直さず従来と同じ出力のままであることも縛っている(`tests/Unit/Blade/BladeToPhpTest.php`、`tests/Unit/Blade/ViewHandlerEdgesTest.php`) | 本物の構文エラーは直らず `PARSE_FAILED` のまま、読めない `}` も直さず従来と同じ出力のままである。直った後は、テンプレートの中が読まれるので、中にある追えない呼び出し(例: `match` の条件の `config()`、腕の中のベンダーの静的呼び出し)は `gap` として現れる。試験で、これらの `gap` が残ることを縛っている。数字への影響は §3 で比べる |
| FIX2 | Blade のビュー名に `/` を含むもの(例: `locations/print`)が `VIEW_NOT_FOUND` になる。Laravel では有効な名前である。影響は Snipe-IT だけで、`VIEW_NOT_FOUND` の 233 件のうち 204 件(これを原因に含むルートは 187 本)が偽だった | `src/Analysis/Blade/ViewHandlerTemplates.php` の `locate` が、ビュー名を「英数字・`_`・`-` を `.` で区切った形」に限る正規表現で検査し、`/` を含む名前を最初に除外していた | `/` を `.` と同じ「ディレクトリの一段」として許す(名前に使える文字は英数字・`_`・`-` と、区切りの `.` と `/`)。次のものは従来どおり拒否する: `..` の段を含む名前、先頭が `/` の名前(絶対パス)、名前空間つきの名前(`pkg::x`)、NUL を含む名前、ほかの文字を含む名前。**あわせて、ファイルが見つかった階層(ビューのディレクトリ)の下にあるかを、リンクを解決したうえで確かめ、外へ出るもの(ファイルのシンボリックリンクも、ディレクトリのシンボリックリンクも)は読まない**(`e48a085`。`liesUnder()`) | 赤の試験を先に足した(`7275a97`、`tests/Unit/Blade/ViewNameResolutionTest.php` の 25 本): `/` 形とドット形が同じテンプレートに解決される、複数段の `/`、`/` と `.` の混在、設定された全ビューパスの探索、`/` 形でファイルが無ければ見つからない、ドット形と単純な名前が従来どおり動く、見つからない `/` 形は呼び出し位置の `gap` のまま。**拒否の縛り(D36 の条件 1)**: `..` の段・ファイルがあっても拒否、ドット形の中の `..`、絶対パス(実在のビューの絶対パスも)、`pkg::x`、NUL、ほかの文字、歩いたときの `gap`(`..`・絶対・パッケージ名前空間)、ファイルのリンクとディレクトリのリンクがビューのディレクトリの外へ出るものは読まない、内に収まるリンクとリンク先のビューのディレクトリそのものは読む | `/` を許すのは、有効な名前を無効と読んでいた誤読の修正であり、規則・例外表・知識の追加による緩和ではない(D36)。拒否は従来どおりで、パスを抜ける指定(`..`・絶対パス)と、リンクで外へ出るファイルは読まない。見つからない名前は `VIEW_NOT_FOUND` のまま。直した後に、判定を軽くしたルートは 0 本、`unknown` から確定に動いたルートも 0 本である(§4.7)。読めるようになった共通レイアウトの中の `gap` が現れ、下限が現れた(または上がった)Snipe-IT の 19 本は、すべて重い側である |
| FIX3 | リソースが自分のプロパティに包んだモデルを持ち(コンストラクターで受けて保持する書き方、または宣言したプロパティ)、`$this->order->customer` のように読むと、ルートごとのクエリの遅延読込が事象にも `gap` にも出なかった。N+1 になるはずのルートが `O(1)`・確度 high・`gap` なしと出た(**見逃し**) | リソースの `$this->xxx` は、`resource` 以外のプロパティでも、包んだモデルへの転送として読まれた。そのため `$this->order` はモデルの列を読んだと解釈され、型は値(PlainT)になった。値へのプロパティ読みは、`gap` を出さずに値のまま通された(メソッド呼び出しだけが `gap` になる非対称)。`src/Analysis/Serialization/ResourceHandler.php`、`src/Analysis/Engine/CallResolver.php` | 2 つの直しである。(1) リソースが宣言した(または promoted の)クラス型のプロパティは、宣言された型で読む(`536eeed`)。型のないプロパティは従来の転送のまま。(2) 値へのプロパティ読みは `gap` にする。通さない(`e9003eb`)。ただし Blade の `$loop->index` など `$loop` の 1 段目の読みだけは、値のまま通す(D35) | 赤の試験を先に足した(`f08329d`): 検証用アプリ `r-resource-declared-property`(宣言済み・promoted・ローカル変数経由・`unless`・アクセサの各形と、変わってはならない対照 4 本)。`ViewHandlerEdgesTest` に、`$loop` の例外が 1 段目に限られることと、他の組み込みへの読みが `gap` のままであることを足した。24 ルートの期待値は変えていない | (1) は事象を見つけて重く出す方向、(2) は黙って通していたものを `gap`(`unknown`)にする方向であり、どちらも判定を軽くしない。D21 の直接違反を直したものである。`$loop` の例外は、(2) の副作用を抑えるためで、DB に触れない値に限る(D35 に記録) |
| FIX4 | 1 行に別々の原因の `gap` が 2 つあると、片方しか出力に残らなかった | `Gap` の同一性(`fingerprint`)が、コード・場所・軸などで決まり、`message`(原因の名前)を含まなかった。同じ行・同じコードの別原因が 1 件に潰れた(`src/Model/Gap.php`) | 同一性に `message` を足した。同じ原因が同じ行で 2 回出る場合は、これまでどおり 1 件にまとまる(`18427f0`) | 赤の試験を先に足した(`c156b19`): 同一性の単体試験・判定の出力の試験・要約の試験。入力の順序に依らないことも縛っている | 出力の `gap` が増える(落とさない)方向の直しである。軸の判定は変わらない(どちらの原因も `unknown`)。集計が同じ行の複数の原因を数え落とすのを防ぐ |
| FIX5 | Koel の `GET /api/duplicate-uploads` で、クエリ軸に偽の下限 `≥O(N)`(次数 1)が出た(**過検知**。判定は `unknown` のまま)。リソースが、eager load 済みのモデルを自分の宣言したプロパティに持つ形で、存在しない遅延読込が事象として数えられた | FIX3 の直し (1) の副作用である。宣言したクラス型のプロパティを宣言型で読むため、包んだモデルと同じクラスの「新しいモデル」ができ、`with()` で読み込み済みのリレーションを失った。そのため `$this->prop->rel` が遅延読込として数えられた(`src/Analysis/Serialization/ResourceHandler.php`) | 宣言型が包んだモデルと同じクラスで、かつコンストラクターが **そのクラス自身で、第 1 引数を `parent::__construct()` にそのまま 1 回だけ渡し**、そのプロパティが第 1 引数から promoted されている(または 1 回だけ代入されている)と示せるときに限り、新しいモデルでなく**包んだモデルそのもの**(読み込み済みのリレーションつき)を返す。第 2 引数・別クラス・引数を変える・別の場所でも代入する・親クラスやトレイトのコンストラクター・条件つきの呼び出しは、示せないものとして従来の宣言型のまま(`f11b1ba`。`holdsWrapped()`) | 赤の試験を先に足した(`b06fd4b`): 検証用アプリ `r-resource-eager-declared`(eager load 済みの promoted・宣言プロパティ・ネストの各形は遅延読込が出ず `O(1)`。`Order::get()` のままの対照は `O(N)`、別のリレーションを読み込んだだけの対照は遅延読込が残る、読み込みが浅いネストは `O(N*M)`、包んだモデルと別クラスのプロパティは従来どおり)。第 2 引数を包む形のガード(`e7e1c17`)と、両軸の確度 `medium` の明記(`def1607`)も足した。24 ルートの期待値は変えていない | 同一の実体と示せるときだけ、読み込み済みの状態を引き継ぐ。示せなければ従来どおり、遅延読込を数える重い側に倒れる(D21)。最終版は、baseline と同じ判定に**戻った**のであり、baseline より軽くなったルートは 0 本である。引き継ぐ条件が厳しいので、偽の下限が残る形はありうるが、それは安全側(重い側)である |
| D38 | 下限のない `unknown` の軸(`lower_bound` が `null`)で、`degree` が 0 と出た。「`unknown`・`degree` 0」が `O(1)` に読める罠だった(Koel の `GET /api/playlists` が実例。§4.4.1) | 出力の `degree` は軸の内部の次数をそのまま出していた。下限が無いときの内部の次数は 0 で、「何も追えなかった」と「事象がすべて定数だった」を区別しなかった(`src/Output/JsonRenderer.php`、`src/Output/TableRenderer.php`) | `growth` が `unknown` で `lower_bound` が `null` のとき、`degree` を `null` にする。表では `unknown (no lower bound)` と出し、`degree` の列に 0 を出さない。`schema_version` を `0.1` から `0.2` に上げる(`dfdbe07`)。`docs/output-schema.md` の説明と例を実際の出力に合わせて直した(`9f60add`) | 赤の試験を先に足した(`bd66635`): 下限のない `unknown` は `degree` が `null`(`testAnUnknownAxisWithNoLowerBoundHasANullDegreeNotZero`)、下限つきの `unknown` は下限の次数を保つ、`O(1)` は `degree` 0 を保つ、`null` の次数は下限のない `unknown` にだけ出る、`schema_version` が `0.2`、表が `unknown (no lower bound)` と出す、説明文書が `null` を述べ、例に「`unknown` で `degree` 0」が残らない(`tests/Unit/Output/JsonRendererTest.php`・`TableRendererTest.php`・`SchemaDocTest.php`。期待値の golden も更新) | 出力の表し方を変えただけで、判定・確度・下限は変えていない。5 アプリ・2,091 ルートで、`null` になった軸は 3,506(クエリ軸 1,940・メモリ軸 1,566)で、すべて下限のない `unknown` の軸だった(異常 0)。確定軸や下限つきの軸に `null` は出ていない |

各行の補足:

- **全スイートの結果(最終版 `9f60add`。FIX1〜FIX5 と FIX2・D38 を入れた版)**: PHP 8.2 と 8.3 のどちらでも、2,373 件・7,887 アサーションで通り、失敗 0・SKIP 0 だった。`--fail-on-skipped`・`--fail-on-risky`・`--fail-on-warning` を付けても同じである(SKIP が 1 件でもあれば「未完了」と扱う)。FIX5 の版(`f11b1ba`)では 2,336 件・7,768 アサーションだったので、FIX2 と D38 の試験で 37 件・119 アサーションが増えた。実行ログは、この版の分に更新した(§8)。
- FIX1 は、見逃しではなく「読めないはずのテンプレートが読めなかった」不具合である。直す前の `PARSE_FAILED` は、`gap` なので安全側に倒れていた。直す価値は、テンプレートの中が読まれ、中の他の `gap` が見えるようになることにある。
- FIX3 が、今回見つけた**判定の見逃しの原因**である。実アプリの確定ルートの範囲には出なかった(確定が少なかったため)。判定を緩めた後に `O(1)`・high として現れうる形なので、D33 の抜き取り確認の対象に含める。
- FIX4 は、見逃しではなく報告の欠けである。
- FIX5 は、見逃しではなく過検知(偽の下限)である。FIX3 の直しが作った副作用を、直した直しである。
- FIX2 は、見逃しではなく読み違い(有効な入力を無効と読む)の修正である。影響は Snipe-IT だけで、`VIEW_NOT_FOUND` の `gap` 233 件のうち、名前に `/` を含むものが 204 件(これを原因に含むルートは 187 本)だった。ほかの 4 本では 0 件で、FIX2 の前後で出力が変わらなかった。「緩和」でなく「修正」として別に数え、直した後の数字は §3 と §4.7 に書いた。**直したら重くなった**(Snipe-IT の解析時間・メモリ・出力サイズ。§3.5.1)。
- D38 は、不具合の修正ではなく、出力の読み違いの罠を除く変更である。判定の規則は変えていない。
- **D37(FIX5 の位置づけ)。** FIX5 は「判定を軽くする変更」に当たらず、比べる基準は baseline である、と裁定された。FIX3 の最小再現(リソースが自分のプロパティ経由で読む遅延読込が `O(N)` と出る 6 形)が、FIX5 の後も通ることは、次の試験が縛っている。`tests/Acceptance/MiniAppTest.php` の `testCaseMatchesItsExpectation` の、データセット `r-resource-declared-property`(12 ルート。宣言済みプロパティ `/prop`・promoted の `::collection` `/promoted-collection`・`map` で 1 件ずつ包む `/promoted-map-new`・ローカル変数経由 `/local-var`・`unless` の中 `/unless-flag`・アクセサ `/accessor` の 6 形が `O(N)` で、遅延読込が事象として出ること。1 件だけ包む `/promoted-make`・`/promoted-new` は `O(1)` で、遅延読込の事象は出ること。変わってはならない対照 4 本)。FIX5 の側は、同じ試験のデータセット `r-resource-eager-declared`(読み込み済みのモデルは遅延読込が出ず、`Order::get()` のままの対照や別のリレーションを読み込んだだけの対照は遅延読込が残る)が縛っている。この 2 つは、FIX2・D38 の後の全スイート(上記)でも通っている。

FIX3 の既知の限界(副作用を隠さず書く):

- **enum の `->value`・`->name` の読みも `gap` になる(過検知)。** (2) の防壁が、DB に触れない enum の読みまで `gap` にする。正当な読みを `gap` にするので過検知だが、安全側であり、判定は変わらない。防壁の `gap` 全体は 2,855 件(787 箇所、450 ルート)で、そのうち `->value`・`->name` の読みは 680 件(326 箇所)である。ソースの 1 行で `Class::CASE->value` の形と確かめられた読みは 568 件(271 箇所)で、内訳は Firefly III 423 件・Snipe-IT 136 件・Koel 7 件・Laravel.io 2 件である(§3.6)。変数に入った enum は形から区別できないので、680 件には enum でない読みも混じる。例外にするかどうかは提案(§6)に回す。
- **Koel の偽の下限は、FIX5 で解消した(第 1 引数で包む形に限る)。** FIX1〜FIX4 の版では、eager load 済みのモデルを宣言プロパティで持つリソースで、読み込み済みのリレーションが失われ、Koel の 1 ルート(`GET /api/duplicate-uploads`)のクエリ軸に偽の下限 `≥O(N)` が出ていた。FIX5 の後に再測定し、このルートの下限が baseline と同じ(次数 0・下限なし)に戻ったこと、ほかのルートの判定・確度・下限に変化が無いことを確かめた。**残る限界**: 引き継ぎの条件を「コンストラクターが第 1 引数をそのまま親に渡し、そのプロパティが第 1 引数から作られている」場合に限ったので、第 2 引数以降で包む形や、複雑なコンストラクターでは偽の下限が残りうる。その場合も出力は重い側(`unknown` 側)であり、軽くはならない。なお、このルートのメモリ軸の下限 `≥O(N*M)` は baseline からあり、FIX5 とは関係がない。実コードでも、全件を読み込む `get()` と関係の先読みがあり、下限は本物である。

## 6. 例外表・Blade・定数コストについての提案

この節の提案は、**判定を軽くする方向のものは実装していない**(§0.2)。実装したのは、裁定で決まった FIX2(6.4)と D38(6.6)だけである。各提案には、件数の根拠と、優先度の見立てを添える。優先度は見立てで、決まったものではない。件数は、特記なき限り FIX2・D38 を入れた後(`9f60add`)の出力に合わせた。FIX2 の前の集計でしか数えていないものは、「(FIX2 の前の集計)」と書いた。

| 番号 | 提案 | 件数の根拠(要点) | 優先度の見立て | 判断が要るか |
| --- | --- | --- | --- | --- |
| 6.1 | 例外表の緩め方(純 PHP 関数・ヘルパー・enum の読み) | 純 PHP 関数が最後の関門 88 本、ヘルパーが 143 本。enum の読みは確実なもので 568 件 | 高(純関数・ヘルパー)/中(enum) | D28 の作法で 1 件ずつ。enum は D39 で「次の判定を緩める段の第 1 候補」 |
| 6.2 | Blade の緩和案 B1・B2 | Blade の `gap` を持つルートは 471 本。B1 を含む 25 本、B2 を含む 147 本。ただし両案を足しても救われる本数の増分は 0(FIX2 の前の集計) | 低〜中 | 要(判定を軽くする方向) |
| 6.3 | 定数コストの呼び出しの数え方 | `auth_user` を含む 781 本、最後の関門 31 本。定数は実測で 2〜14 本 | 高(フェーズ3の前提) | 要(D29・D31) |
| 6.4 | ビュー名の「/」の読み | 204 件・187 本(Snipe-IT) | — | **解決済み**(D36・FIX2) |
| 6.5 | 下限の質(過大の 3 型) | 標本 18 本のうち、過大を含むのが 8 本 | 高 | 要(D27 に関わる) |
| 6.6 | 出力の degree の読み方 | `unknown` かつ下限なしの query 1,940 本・memory 1,566 本 | — | **解決済み**(D38・schema 0.2) |
| 6.7 | 入口の脆さ | `route:list` が止まる 2 アプリ、`model:show` が失敗する 3 アプリ(`MODEL_INFO_UNAVAILABLE` を持つルート 82 本) | 中〜低 | 不要(親切の問題) |
| 6.8 | `newQuery` の既知化・`skipped` の可視化・`model:show` 失敗の扱い | `newQuery()` 由来 33 本(FIX2 の前の集計) | 中 | 要(出力の形に触れる案を含む) |
| 6.9 | 集計の道具で見えた出力の限界 | `message` の文言に依存する集計 | 中 | 要(schema に触れる) |
| 6.10 | 出力サイズとメモリの削減(FIX2 で重くなった Snipe-IT) | 出力 JSON 186.1 MB、ピークメモリ約 976 MiB、`gap` 180,384 件(Snipe-IT) | 中〜高 | 要(出力の形に触れる案を含む) |
| 6.11 | `memory_limit` の案内と、JSON のストリーム出力 | 既定の 128M では 5 本中 3 本が Fatal error で落ち、説明が出ない。Snipe-IT は JSON で約 960M、表形式で 384M〜512M | 中〜高 | P-A・P-B は不要(判定に無関係)。P-C は要(実装の重さと決定性の試験への影響) |

### 6.1 例外表の緩め方

**前提。** 例外表(`resources/known-calls.php`)に載せるのは、「DB に触れず、アプリのコードを呼び返さない」と根拠(basis・why)つきで確かめた呼び出しだけである(D28)。載せることは「この呼び出しは増え方に影響しない」という主張になるので、通すためだけに載せない。1 件ずつ、根拠・理由・試験を添える。

**件数の根拠(カテゴリ別の「最後の関門」)。** 3.4.3 の順序に依らない指標のうち、例外表の領分に近いものを示す。数は、そのカテゴリだけを緩めなかった場合に失う本数である(FIX2・D38 の後の値。FIX2 の前は `laravel_helper` 185・`builtin_pure` 104・`container` 12・`authorization` 128)。

| カテゴリ | 最後の関門のルート数 | 例外表で扱えるか |
| --- | ---: | --- |
| `laravel_helper` | 143 | 1 件ずつ根拠を確かめれば可 |
| `builtin_pure` | 88 | 可(純 PHP 関数のみ。コールバックを取る関数は不可) |
| `vendor_class_method` | 39 | 一部(`Request->input()` など) |
| `app_global_function` | 22 | アプリごとの個別判断(アプリ自前の関数の中身を読む必要がある) |
| `container` | 15 | 不可(`app()` は何でも解決できる。D28) |
| `other_function` | 6 | 個別(ファイル・通信・状態を持つ) |
| `authorization` | 46 | 載せない(D28) |
| `validation` | 6 | 載せない(D28) |

**緩め方の見立て(段階的に)。** 1 件ずつ緩める効果の見当をつけるために、「他の 13 カテゴリがすべて緩んだ」と仮定して、そのカテゴリの原因を使用ルート数の多い順に k 個緩めたときの、救われるルート数を数えた。他のカテゴリが緩むとは限らないので、**上限の見立て**である。**この表と下の読み方は、FIX2 の前の出力(救われる 415 本が基準)で数えた。FIX2 の後の再集計はしていない**(FIX2 の後は全カテゴリを緩めて救われるのが 322 本で、最終行の値が変わる)。

| k | `builtin_pure`(異なる関数 136 種)を k 個緩めたとき | `laravel_helper`(異なる関数 51 種)を k 個緩めたとき |
| ---: | ---: | ---: |
| 0 | 311 | 230 |
| 1 | 313 | 259 |
| 3 | 353 | 268 |
| 5 | 368 | 319 |
| 10 | 372 | 370 |
| 20 | 381 | 391 |
| 50 | 405 | 415 |
| 100 | 409 | 415 |
| 全部 | 415 | 415 |

- `builtin_pure`: 上位 3 個(`str_replace()`・`is_array()`・`sprintf()`)で 311 → 353(+42)、上位 5 個(+`trim()`・`is_null()`)で 368 になる。10 個で 372、その先は緩やかで、50 個で 405。**上位 10 個の純関数を 1 件ずつ根拠つきで載せると、最後の関門 104 本(FIX2 の前)のうち 61 本(311 → 372)が救われる見込みで、残りは長い裾野になる。**
- `laravel_helper`: `trans()` 1 個で 230 → 259(+29)、上位 5 個(+`config()`・`request()`・`redirect()`・`route()`)で 319、10 個(+`session()`・`csrf_token()`・`url()`・`e()`・`__()`)で 370、20 個で 391。
- 含むルート数の上位は、`trans()` 1,122、`config()` 843、`request()` 603、`redirect()` 557、`str_replace()` 533、`route()` 515、`is_array()` 510、`session()` 496、`sprintf()` 455、`trim()` 406 である。
- 根拠の書き方に注意が要るもの(見立て): `session()` は、セッションのドライバがデータベースのとき DB に触れうる(アプリの設定次第)。`trans()`・`__()` は、翻訳の読み込み方を差し替えるパッケージがあれば、ファイル以外に触れうる。`config()` は設定ファイルを読むだけだが、設定の値が実行時に書き換えられる場合がある。これらは「条件つきで DB に触れない」という書き方になり、D28 の「根拠つきで確かめた」に足りるかは、1 件ずつ見る必要がある。コールバックを取る純関数(`array_map()` など)は、アプリのコードを呼び返すので載せられない(許可表の状態にも `callback argument ×3` が出ている)。

**enum の `->value` / `->name`。** FIX3 の防壁は、型の取れない値へのプロパティ読みを `gap` にする。このとき、PHP の backed enum の `->value` / `->name` の読み(DB に触れず、アプリのコードを呼び返さない)も `gap` になる。これは過検知である(安全側であり、判定は動かない)。

| アプリ | 防壁の `gap`(件) | 防壁の `gap` を持つルート | `->value` の件数 | `->name` の件数 | `Class::CASE->value/name` の形(enum と確実)の件数 | 同・箇所(ファイル:行の異なる数) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| BookStack | 612 | 101 | 14 | 30 | 0 | 0 |
| Firefly III | 1,415 | 177 | 426 | 17 | 423 | 246 |
| Koel | 61 | 11 | 8 | 4 | 7 | 7 |
| Laravel.io | 4 | 1 | 4 | 0 | 2 | 2 |
| Snipe-IT | 874 | 181 | 160 | 20 | 136 | 16 |
| 全体 | 2,966 | 471 | 612 | 71 | 568 | 271 |

- 「確実」な形(`Class::CASE->value/name`)は 568 件・271 箇所で、これが過検知の下限である。変数に入った enum(Blade の `$type->value` など)や、モデルの `->name` と見分けのつかない形は、確実な数に入れていない(`->value/->name` の件数には非 enum の読みも混じる)。
- **効果は小さい(FIX2 の前の集計)。** 防壁の `gap` を持つ 450 本のうち、防壁の `gap` が enum の確実な形**だけ**のルートは 84 本(Firefly III 63、Snipe-IT 21)である。これを緩めて 14 カテゴリに足しても、救われるのは 415 → 416 本(+1)にとどまる。多くのルートは、foreach の要素へのプロパティ読み(733 件)、コールバック引数のプロパティ読み(275 件)、プロパティの連鎖(491 件)など、正当な `gap` も同時に持っているためである。
- 提案: 受け手が enum と分かる形(`Class::CASE`、宣言された enum 型)に限って、`->value`・`->name` を `gap` にしない。根拠は「backed enum の `value`・`name` は、列挙の定義から決まる定数で、DB にもアプリのコードにも触れない」。変数に入った enum は、型が enum と取れる場合に限る。優先度(見立て): 中。`gap` の中身を正確にする効果はあるが、救われる本数への効果は小さい。**D39 の裁定: 今回は入れない。次の「判定を緩める段」の第 1 候補とし、その段で D28 の根拠と D33 の抜き取りを付けて入れる。**

**`$loop` 以外の例外。** `$loop` の 1 段目の読みだけは、DB に触れない値として通している(D35)。2 段目以降(`$loop->parent->first`)は `gap` のままである。これを緩めるかどうかの件数は、今回は数えていない。

**提案のまとめ。**

1. 純 PHP 関数: 使用ルート数の多い順に、1 件ずつ根拠と試験を添えて載せる。まず上位 5〜10 個。コールバックを取る関数は除く。優先度(見立て): 高。
2. Laravel のヘルパー: `trans()`・`__()`・`route()`・`url()`・`e()`・`csrf_token()` など、DB に触れない根拠が書けるものから。`session()`・`config()` は、条件つきの根拠が要る。優先度(見立て): 高。
3. enum の `->value`・`->name`: 上記。優先度(見立て): 中。D39 により、次の判定を緩める段の第 1 候補である。
4. 載せないもの(Gate・Policy・Validator・`app()`・`Auth::user()`)は、D28 のとおり載せない。`Auth::user()` は 6.3 に回す。

### 6.2 Blade の緩和案 B1・B2 への材料

**案の内容。**

- **B1**: `<style>` の内側の `@語`、HTML タグ内属性の `@click=`・`@語.修飾=` を地の文とする。`@語(` は、独自ディレクティブ(`Blade::directive`)の見逃しを避けるため対象外。いまは `@media`・`@keyframes`・`@tailwind`・`@click` も `BLADE_UNSUPPORTED` になり、そのルートは `unknown` になる。
- **B2**: `@class`・`@checked`・`@selected`・`@disabled` を、`@lang` と同様に「引数を残して許可」する(遅延ロードを見逃さない)。`@includeIf`・`@includeWhen`・`@includeFirst` を、`@include` 系の読み(`__peak_include`)に寄せる。許可表を広げる判断は別に要る。

**`BLADE_UNSUPPORTED` の現状。** 5 アプリ中、`BLADE_UNSUPPORTED` を持つのは 471 本(controller の 22.5%)である(FIX2 の前は 343 本)。アプリ別では BookStack 122 本、Firefly III 148 本、Laravel.io 13 本、Snipe-IT 188 本、Koel 0 本。構文の種別ごとのルート数は、コンポーネント 344、ディレクティブ 445、その他(構文の不一致)201 である(重なる)。異なる構文は 334 種ある。FIX2 の後の上位は 3.3.2 に示した。**次の表は FIX2 の前の集計で、緩和案との対応を示すために残した**(同じルート数のものは、同じ共通レイアウトに由来すると見られる)。

| 構文 | ルート数 | 主なアプリ | 緩和案 |
| --- | ---: | --- | --- |
| `component <x-layout.fav-icons>` | 145 | Firefly III | (コンポーネント系) |
| `@includeFirst` | 126 | Firefly III | B2 |
| `@sectionMissing` | 126 | Firefly III | — |
| `component <x-layout.*>`(`create-menu`・`flash`・`range`・`sidebar`・`tracking`・`user-menu`) | 各 126 | Firefly III | (コンポーネント系) |
| `syntax: unbalanced @endif` | 126 | Firefly III | 二次の `gap` |
| `@icon` | 114 | BookStack | — |
| `@inject` | 104 | BookStack | — |
| `component <x-icon>` | 39 | Snipe-IT | (コンポーネント系) |
| `@can` / `@endcan` | 各 37 | Snipe-IT 24、Laravel.io 13 | `authorization` |
| `component <x-container>` | 33 | Snipe-IT | (コンポーネント系) |
| `component <x-box>` | 30 | Snipe-IT | (コンポーネント系) |
| `@click` | 21 | Firefly III 8、Laravel.io 13 | B1 |
| `syntax: unbalanced @else` | 21 | Snipe-IT 20、ほか | 二次の `gap` |
| `@component` / `@endcomponent` | 各 18 | BookStack | (コンポーネント系) |
| `@js` | 17 | Snipe-IT | — |
| `@keyup` | 14 | Laravel.io 13、ほか | B1(Alpine の属性) |
| `@checked` | 13 | Snipe-IT | B2 |
| `@input`・`@keydown` | 各 13 | Laravel.io | B1(Alpine の属性) |

Firefly III の 126 本は、1 つの共通レイアウトで `x-layout.*` のコンポーネント・`@includeFirst`・`@sectionMissing` を使っているために、すべてのルートが同じ `gap` を持つ形である。BookStack の 122 本も、`@icon` 114 と `@inject` 104 が大半を占める。

**各案を採った場合に救われる本数(FIX2 の前の集計。FIX2 の後の再集計はしていない。B1・B2 の増分が 0 だった傾向が FIX2 の後も同じかは、確かめていない)。** Blade の 2 カテゴリ(`blade_component_include`・`blade_other`)を除く 12 カテゴリ(`view_slash` を含む)がすべて緩んだと仮定して、そのうえで案を足したときの救われるルート数を数えた(上限の見立て)。B1 と B2 の構文の一覧は、道具の側で名前から決めたもの(下記)である。

- B1 に当たる構文(`@click`・`@keyup`・`@keydown`・`@input`・`@submit`・`@change`・`@media`・`@page`): これを含むルートは 25 本(Laravel.io 13、Firefly III 9、Snipe-IT 3)。`@keyframes`・`@tailwind` は、今回の 5 アプリには出なかった。
- B2 に当たる構文(`@class`・`@checked`・`@selected`・`@disabled`・`@includeIf`・`@includeWhen`・`@includeFirst`): これを含むルートは 147 本(Firefly III 126(すべて `@includeFirst`)、Snipe-IT 21)。

| 想定 | 救われるルート数(全体) | 増分 |
| --- | ---: | ---: |
| Blade 以外の 12 カテゴリを緩める | 405 | — |
| ＋B1 | 405 | +0 |
| ＋B2 | 405 | +0 |
| ＋B1 と B2 | 405 | +0 |
| ＋コンポーネント・`@include` 系すべて | 411 | +6 |
| ＋そのほかのディレクティブすべて | 405 | +0 |
| ＋Blade 全部(上の 2 つ) | 415 | +10 |

- **B1・B2 を足しても、救われる本数は増えない(+0)。** B1・B2 の構文を持つルートは、必ず他の原因(他の Blade の構文や、14 カテゴリ外の原因)も持つためである。B1・B2 の構文だけが原因のルートは 0 本。
- コンポーネント・`@include` 系をすべて対応しても、救われるのは +6 本、そのほかのディレクティブを足して合計 +10 本である。**Blade の緩和は、「救われるルートの本数」への効きが小さい。**
- 一方で、Blade の `gap` は 471 本のルート(FIX2 の前は 343 本)に載っている。緩和の価値は、救われる本数でなく、`gap` の中身が見えること(テンプレートの中の本物の `gap` が見える)にある。FIX1 のように、読めるようになって初めて見える `gap` もある。
- 注意: `@click` などは、Blade のディレクティブでなく Alpine / Vue の属性であることが多い。ツールは見分けず `BLADE_UNSUPPORTED` にする(既知)。B1 は、これを地の文と読む案である。ただし「`@語(` は独自ディレクティブの見逃しを避けるため対象外」とするため、`@click="open()"` のように括弧を含む属性値の扱いは、案の詳細を詰める必要がある。

提案: 今は B1・B2 を入れない(従来どおり)。入れるなら、判定を軽くする方向の変更なので、新たに確定したルートの抜き取り確認を工程に入れる(D33)。優先度(見立て): 低〜中。救われる本数への効きが小さく(Blade 全体を緩めても +10 本)、緩和の主な価値は `gap` の可視化にある。

### 6.3 定数コストの呼び出しの数え方(D29・D31)

**方針の確認。** `Auth::user()` のように、DB に触れるが 1 リクエストあたりの本数が一定の呼び出しは、例外表に足さず、将来「定数コストの呼び出し」として数える(D29)。増え方には効かないが、フェーズ3の負荷計算には効く(D31)。

**件数の根拠。** `auth_user`(認証の取得)を原因に持つルートは 781 本である(FIX2 の前は 618 本)。原因の内訳(その原因を持つルート数)は、`auth()` 690 などである。アプリ別と、`auth()` 以外の原因の内訳は、FIX2 の前の集計で、アプリ別が Firefly III 251、Snipe-IT 227、Koel 91、Laravel.io 28、BookStack 21、原因が `Guard->user()` 85、`Request->user()` 15、`Auth::guest()` 13、`Auth::user()` 13(ファサードの完全名で 4)、`@auth` 2、`@guest` 1 だった。

- `auth_user` だけが原因のルートは 0 本である(`auth_user` は常に他の原因と一緒に載る)。単独では救えない。
- 他の 13 カテゴリがすべて緩んだとき、`auth_user` が最後の関門になるルートは 31 本(BookStack 2、Firefly III 13、Koel 3、Laravel.io 4、Snipe-IT 9)。`auth_user` を除く 13 カテゴリで救われるのは 291 本、`auth_user` を足して 322 本。
- つまり、`Auth::user()` 等を定数コストとして数えると、`gap` の観点で最大 31 本のルートが追加で救われる見込みである。大きな数ではない。**定数コストの扱いの価値は、救われる本数でなく、フェーズ3で本数を負荷に積めること**にある。

**定数の大きさ(抜き取り確認での実測)。** 確定した 5 本の追跡で、判定の外にある定数分のクエリ数を測った。

- Firefly III の `GET /api/v1/rules/validate-expression`: 2〜5 本(設定表・preferences の単一行検索)。`GET /json/box/available`: 冷 14 本、温 6 本。行数を 0 → 50,000 に増やしても本数は変わらない。
- Koel の `/rest/ping`: 4 本(ライセンス・ユーザー・ロール・権限)。`/rest/getMusicFolders` と `/rest/getOpenSubsonicExtensions`: 3 本(実際のサーバー環境ではライセンスの 1 本が加わり 4 本になりうる)。ユーザーを 300 人足しても変わらない。
- どの定数も、出力の `degree 0` には現れない。出力の `coefficients.extra_queries_per_request` は `null` で、`analysis_scope.not_covered` が「アプリ自作のミドルウェア・認可・ルートモデル束縛・FormRequest の検証・グローバルスコープ・オブザーバとイベントリスナー・ジョブ」を対象外と宣言している。
- 定数コストの発生源の例: 基底コントローラーのコンストラクターが積むクロージャのミドルウェア(Firefly III の `app/Api/V1/Controllers/Controller.php`、`app/Http/Controllers/Controller.php`)、`Range` ミドルウェア(`app/Http/Middleware/Range.php:121-154`)、Koel のライセンス確認(`LicenseService.php:81-97`)。キャッシュが無いとき毎リクエスト 1 本かかり、キャッシュが効くと 0 本になる、という条件つきの定数もある。

**提案。**

1. 定数コストを、増え方の判定とは別の欄(例: `coefficients.extra_queries_per_request` に値を入れる)に出す。数え方は、`Auth::user()` のように「DB に触れるが本数が一定」と根拠を確かめた呼び出しを、リクエストあたりの本数として積む。
2. 数える対象は、`auth_user` の呼び出し、ルートモデル束縛、基底コントローラーのコンストラクターの設定読みなど。条件つき(キャッシュの有無など)の定数は、「最大本数」として扱う案と、「条件つき」と注記する案がある。
3. 出力の `high` の近くに、「定数コストは含まれない。安全という意味ではない」(D30)が見えるようにする。確定ルートの追跡で、`degree 0` を「クエリ 0 本」と読む誤読の可能性が指摘された。

優先度(見立て): 高。フェーズ3の前提だが、フェーズ1の判定には効かない。

### 6.4 ビュー名の「/」の読み(`VIEW_NOT_FOUND`)—解決済み(D36・FIX2)

**症状。** `view('locations/print')` のように、ビュー名に「/」を含むものが `VIEW_NOT_FOUND` になった。Laravel では有効な名前で、`locations/print.blade.php` は実在する(Snipe-IT の `resources/views/locations/print.blade.php`)。有効な入力を無効と読む誤りで、BookStack の `PARSE_FAILED`(FIX1)と同じ型である。

**原因。** `src/Analysis/Blade/ViewHandlerTemplates.php` の `locate` が、ビュー名を「英数字・`_`・`-` を `.` で区切った形」に限る正規表現で検査し、「/」を含む名前を最初に除外していた。

**裁定と直し。** D36 で「誤読の修正であり、判定を軽くする変更に当たらない」として、直すと裁定した。条件は 3 つで、すべて満たした。(1) `..`・先頭 `/`・`pkg::x` を従来どおり拒否することを、赤の試験で先に縛る(`7275a97`。25 本。リンクでビューのディレクトリの外へ出るファイルを読まない防壁も含む)。(2) 修正前の出力を基準に 5 アプリを再測定し、`VIEW_NOT_FOUND` の増減・新たに出た `gap`・確定に動いたルートを書く(§3・§4.7)。(3) 確定に動いたルートが 1 本でもあれば全数をコードで追う(該当するルートは 0 本)。直しは `e48a085` である(§5)。

**結果。**

- `VIEW_NOT_FOUND` は 5 アプリで 215 ルートから 28 ルートに減った(`gap` の件数は 233 件から 29 件)。Snipe-IT は 189 ルートから 2 ルートになり、D36 の見積もり(204 件・187 ルート)と一致した。残る 2 ルートは、定数でないビュー名である。
- 確定に動いたルートは 0 本。判定(growth)・確度が動いたルートも 0 本である。
- 共通レイアウトが歩かれ、その中の `gap` が現れた。Snipe-IT の `gap` は 42,417 件から 180,384 件に増えた。下限が現れた(または上がった)ルートが 19 本ある。すべて重い側である(§4.7)。
- **代償として、Snipe-IT の解析が重くなった**(解析時間 約 2.6 倍・ピークメモリ 約 2.4 倍・出力 JSON 約 3.9 倍。§3.5.1)。軽くする案は 6.10 に置いた。
- 「全カテゴリを緩めたときに救われる本数」では、`view_slash` は最後の関門ではなくなった(0 本)。

### 6.5 下限の質(過大の 3 型)

**位置づけ。** 判定が `unknown` で下限(`lower_bound`)が付いたルートは、いずれかの軸で 511 本ある(抜き取りの時点の出力、つまり FIX2 の前の集計。FIX2 の後は 521 本。query 軸で 137 本: ≥O(N) 125・≥O(N*M) 12。memory 軸は ≥O(N) 452・≥O(N*M) 58)。確定したルートが 5 本しか無かったため、この 511 本から決定的な抜き取り(各アプリ、≥O(N*M) 層と ≥O(N) 層に分け、等間隔)で 18 本を選び、下限が過大でないかをコードを追って確かめた(§4)。ここでは、その結果から見えた下限の質を示す。

**結果(標本 18 本の集計)。** 各記録から数えた。標本は 18 本(BookStack 5、Firefly III 5、Koel 4、Snipe-IT 4。Laravel.io は母数が 0 本)で、**全体の過大率は言えない**。

- 下限が過大(または、条件つきでしか成り立たない)の軸を含むルートが **8 本**(BookStack 4、Firefly III 1、Koel 2、Snipe-IT 1)。軸の数でも 8 軸。
- 下限が低すぎる、または欠けている軸が 5 軸(BookStack 2、Firefly III 2、Koel 1)。いずれも `unknown` であり、見逃し(低い次数と言い切って実際は重い)ではない。このほか、Snipe-IT の 3 軸(標本 16〜18 の `query` 軸)は判定不能とした(§4.3.4)。
- 見逃し(低い次数と言い切って実際は重い)は、標本の範囲では 0 件である。標本はすべて `unknown` で、O(1) とは言っていないためである。ただし、標本の確認(Koel の `GET /api/playlists`)をきっかけに、見逃しに繋がる取りこぼしが 1 型見つかり、FIX3 で直した(§5)。ただし実アプリの当該ルートは `unknown`・下限なしのままである(§4.4.1)。

**過大の 3 型。** 8 軸の内訳は、1 型が 1 軸、2 型が 2 軸、3 型が 5 軸である(§4.3.3)。

1. **条件つきの事象・キャッシュ付きの事象を、下限に数える。** Snipe-IT の `GET /api/v1/hardware/bytag/{any}` では、query の ≥O(N*M) の根拠が、`request('components') == 'true'` の条件の中のループ(`AssetsTransformer.php:206-219`)にある。ツールは `request()` を追えず、条件を評価せずに事象を下限に数える。リクエストに `?components=true` が付かない通常の呼び出しでは、その下限は起きない。また、`Setting.php:133` の select は static キャッシュ(要求あたり 1 回)なのに、ループの中の事象として数えている。
2. **回数の分からない(実際は定数の)ループを、入れ子と数える。** BookStack の `PUT /api/pages/{id}` の memory と `PUT /books/{slug}` の query は、`MixedEntityListLoader.php:56-60` の型ごとの `select`(page・chapter・book・bookshelf の高々 4 種=定数)を入れ子のループと数え、次数が 2 になっている。実際は O(N)。D32 は「回数が分からないループは、N 倍と決めると過大になりうるので `gap` にして `unknown` とする」と定めており、下限の次数の数え方と噛み合っていない可能性がある。
3. **`n_table` の選び方が、実態の軸と違う。** 下限の根拠として選ばれる表(N)が、データの件数ではない。BookStack の `PUT /api/content-permissions/{type}/{id}` は、リクエストが挙げた role_id の数(入力依存)。`GET /books/{b}/page/{p}/edit` は、同時編集者数。Firefly III の `POST /api/v1/recurrences/{recurrence}/trigger` は、1 回のトリガーが作る group(小さな固定の数)。Koel の `POST /api/playlists/collaborators/accept` は、1 つのプレイリストのメンバー数。Koel の `DELETE /api/duplicate-uploads` の memory は、`eager_load genres` を根拠に M = `genres` と出ているが、実コードは `id`・`title`・`artist_name` だけを読み、`genres` は読んでいない。出力の `n_table`・`m_table` は 1 組だけで、軸ごとに分かれていない。

**下限が低すぎる、または欠けている軸の例(と判定不能の例)。**

- BookStack の `PUT /api/content-permissions/...`: 抽象型の引数に対する `rebuildPermissions()` の先(子 × 全ロールの行を組む O(子×ロール))が、事象にも `gap` にも出ていない。受け手が型不明(`UnknownT`)のとき、下流の呼び出しは素通しされる設計の帰結で、起点の `gap` は出力にある。
- Firefly III の `GET /api/v1/budgets` と `POST /piggy-banks/remove/{piggyBank}` の query: リポジトリのインターフェースの束縛が不明(`UNRESOLVABLE_BINDING`)で、先の呼び出しが見えず、下限が付かない。
- 判定不能の例: Snipe-IT の `GET /locations/{locationId}/printassigned` の query は、抜き取りの時点では偽の `VIEW_NOT_FOUND`(6.4)で下限が出なかった。実際は少なくとも O(N) と読めるが、ツール側の誤りが原因なので、「低すぎ」には数えず判定不能とした(§4.3.4)。FIX2 で `VIEW_NOT_FOUND` は消えたが、このルートの query 軸は下限なしのままである。

**提案。**

1. 下限を「その事象が必ず起き、回数が N 以上と分かっているもの」に限る。条件を評価できた経路の事象、回数が既知のループだけを数える。それ以外は、下限に数えず、`gap` の根拠として別に示す(または、下限に「条件つき」の注記を添える)。
2. `n_table`・`m_table` を軸ごとに出す。
3. 入力依存・同時編集者数に由来する事象は、`n_table` の対象から外すか注記する。

件数の根拠は、標本 18 本のみである。511 本のうち、条件つきの事象を下限にしているルートが何本かは、未集計である。優先度(見立て): 高。下限は「参考値であり、保証ではない」(§0.3)と書いたが、過大になる型が複数あるので、保証の強さを下げるか、下限の定義を直すか、判断が要る。D27 に関わる。

### 6.6 出力の degree の読み方(D30)—解決済み(D38・schema 0.2)

**この節は、直す前の症状の記録である。** D38 で「`lower_bound` が `null` のとき `degree` も `null` にする。表では `unknown (no lower bound)` と出し、`degree` の列に 0 を出さない。`docs/output-schema.md` も直し、`schema_version` を `0.2` とする」と裁定し、直した(§5)。提案 1 と、提案 2 の表示の部分を採った。説明だけを実態に合わせる案は採っていない。直した後の出力では、下限のない `unknown` の軸の `degree` が `null` で、クエリ軸 1,940 本・メモリ軸 1,566 本である(§3.2)。以下の数字は、直す前(スキーマ 0.1)のものである。

**症状。** `growth` が `unknown` のとき、`degree` は「見つかった最大の次数」を持つ。下限が付くと、`degree` は下限の次数(1 や 2)になる。下限が付かないときは `degree` が 0 になる。output-schema の説明は「`unknown` のときは意味を持たず 0」だが、実際の出力はそうなっていない。

**実際の出力(5 アプリ・controller 2,091 本)。**

| 軸 | `unknown` かつ下限なし(`degree` 0) | `unknown` ≥O(N)(`degree` 1) | `unknown` ≥O(N*M)(`degree` 2) | 同(`degree` 3) |
| --- | ---: | ---: | ---: | ---: |
| query | 1,949 | 125 | 12 | 0 |
| memory | 1,576 | 452 | 54 | 4 |

- `degree` が 0 の `unknown` は、「何も追えなかった(下限なし)」と、「追えた範囲では事象がすべて定数だった」を区別しない。下限なしの query 1,949 本のうち、`events_found` が 0 のものが 1,008 本、1 以上のものが 941 本ある。後者は、事象が見つかっているのに `degree 0` で、`lower_bound` も `null` である。
- 読み手には、`unknown` かつ `degree 0` が「ほぼ O(1)」に見えうる。実際、Koel の `GET /api/playlists` では、`query` が `degree 0`・下限なしと出ていたが、実コードではプレイリストごとに遅延読込(owner・folders・collaborators)が走り、N に比例していた。このルートが `unknown` だったのは、別の `gap` があったためで、遅延読込の取りこぼし(FIX3 で直した見逃し)が `unknown` に救われていた。FIX3 の後も、このルートの `query` は `unknown`・下限なしのままである(§4.4.1)。

**提案(直す前に出したもの。output-schema 0.1 の確定(D30)に触れるので判断を仰いだ)。**

1. `lower_bound` が `null` のとき、`degree` も `null` にする(0 と書かない)。
2. または、output-schema の説明を実態に合わせて直す(「`unknown` のとき `degree` は下限の次数。下限が無ければ 0 で、O(1) を意味しない」)。表示でも、下限なしの `unknown` を `—` や `unknown (no lower bound)` と出して、`degree` の列に 0 を出さない。

優先度(見立て): 中。判定の規則は変わらず、出力の読み違いを防ぐ直しである。(→ D38 で直した。)

### 6.7 入口の脆さ

artisan を呼ぶ方式の入口は、アプリが起動できる状態(設定行・`APP_KEY`・`APP_URL`・DB のマイグレーション)を要求する。コンストラクターの例外 1 つで、全ルートが取れなくなる場合があった。

- **コントローラーのコンストラクターの例外。** Firefly III の `app/Http/Controllers/ExchangeRates/IndexController.php:50` は、アプリ設定 `enable_exchange_rates` が false のときコンストラクターで例外を投げる。`route:list` はコントローラーを生成するので、このままだと入口が全滅する(ツールは、終了コード 3 と原因つきで正しく報告した)。回避は、設定表の更新(§2.4)。Snipe-IT は、`settings` 表が空だと全 artisan コマンドが例外で止まる。
- **`model:show` の失敗。** 空のインスタンスを作るので、モデルの前提や副作用で失敗しうる。5 アプリ中 3 アプリで起きた。Laravel.io 4 モデル(`id()` メソッドが空インスタンスの `$this->id` を読む `ErrorException`)、BookStack 3 モデル(`User` で morph map 未定義の `ClassMorphViolationException`)、Snipe-IT 2 モデル(`guarded` が文字列の `TypeError`)。ツールは止まらず `note` を出して継続し、そのモデルに頼る呼び出しは `gap`(`MODEL_INFO_UNAVAILABLE`・`UNKNOWN_BUILDER_METHOD`・`LOOP_SOURCE_UNKNOWN` など)になった(設計どおり)。`MODEL_INFO_UNAVAILABLE` を持つルートは 82 本(Koel 45、Laravel.io 31、Firefly III 5、Snipe-IT 1)である。Koel は別の事象で、`User` が `model:show` では成功するのに、ツールがモデルと認識しない。Koel の 45 本は、この事象の影響と見られる。
- **未 migrate の DB。** `model:show` が `SQLSTATE[42S02]`(テーブルが無い)で失敗し、下限が弱まる。BookStack で空の DB で測ると、下限が後退することを実際に確かめた。

提案(判定の規則は変えない):

1. `model:show` の失敗が `SQLSTATE 42S02` のとき、`note` に「DB が未 migrate の恐れ」の一行の助言を足す。環境起因と対象アプリ起因を読み分けやすくなる。
2. 事前書き出し方式なら、該当ルートだけの失敗にできる可能性がある(見立て。未確認)。
3. 文書に、入口の前提(起動できるアプリの状態)を一覧で示す。

優先度(見立て): 中〜低。親切の問題で、判定には効かない。

### 6.8 `newQuery()` の既知化・`skipped` の可視化・`model:show` の失敗の扱い

**`newQuery()` の既知化。** モデルのインスタンスの `newQuery()` が、`UNKNOWN_BUILDER_METHOD`(「…is not declared in the model and is not a known builder method」)になる。ビルダーの既知メソッドの一覧には `newQuery` があるが、受け手がモデルのインスタンスのとき、この一覧を引かない経路がある(`src/Analysis/Engine/CallResolver.php` の、モデルのメソッドが見つからない場合の分岐。コードを読んで確認した範囲)。

- 件数(FIX2 の前の集計。FIX2 の後の再集計はしていない): `newQuery()` 由来の `UNKNOWN_BUILDER_METHOD` を持つルートは 33 本(BookStack 24、Snipe-IT 9)。異なる呼び出しは 8 種(BookStack の `Book`・`Chapter`・`Bookshelf`・`Page`・`User`、Snipe-IT の `Accessory`・`Component`・`Consumable` の `::newQuery()`)。`UNKNOWN_BUILDER_METHOD` を持つルート 507 本(FIX2 の後は 526 本)の 6.5% である。
- 効果: 偽の `gap` の起点を減らす。受け手が型不明になると下流の呼び出しが素通しされるので、起点の偽の `gap` を減らす効果が大きい。ただし Eloquent の振る舞いを「知っている」とする変更なので、D28 の作法(根拠つき)に従う。
- 優先度(見立て): 中。

**`skipped` の可視化。** 受け手が型不明(`UnknownT`)のとき、下流の呼び出しは評価されず素通しされる。起点の `gap` は出力に出るので、軸は `unknown` になる(安全側)。ただし、**下流でどんな呼び出しが追われなかったか**は、出力に出ない。そのため、重い処理(BookStack の `rebuildPermissions()` の先など)が下限にも `gap` の場所にも現れない。

- 提案: 追えなかった呼び出しの場所を、`role`(例: `skipped`)として出力に残す。「見つけようとして追えなかった」の可視化で、D21 の趣旨に沿う。判定は変えない(軽くしない)。出力の形(schema 0.1。D30 で確定)に新しい `role` を足す変更なので、判断を仰ぐ。名称は仮。
- 優先度(見立て): 中。

**`model:show` の失敗の扱い。** 現状は、失敗したら `note` を出して `MODEL_INFO_UNAVAILABLE` の `gap` で継続する。推測で補う方向(失敗したら「列を知らない」として進む)に直すと、判定を軽くする変更になる(今回は禁止)。提案は 6.7 の助言の足しのみ。

### 6.9 集計の道具で見えた出力の限界

集計は、出力(`analyze --format=json`。スキーマ 0.1 と 0.2)だけから行った。そのとき見えた、出力に足したい項目を挙げる。いずれも schema の追加に当たるので、判断を仰ぐ。

1. **`gap` に機械可読の原因欄**(例: `gaps[].subject`)。いまは `message` の文言を正規表現で読んでいる。文言が変わると集計が黙って崩れる。呼び出し名は、`VENDOR_CALL_NOT_MODELLED` の 155,475 件すべてが `message` から取れた(取得不能 0。FIX2 の前は 74,056 件)が、`X() on a <型>` の形は受け手のクラス名が無い。
2. **`VENDOR_CALL_NOT_MODELLED` の理由の `reason` コード化**(許可表に無い / 拒否 / 引数の数 / コールバック引数)。「許可表に足せば救える」のか、「拒否が意図」なのかを、機械で分けられる。
3. **ルートにベンダー提供・dev 依存の印**。いまは `composer.json` と `installed.json` から集計側が推定した(3.4.2)。出力にあれば、開発用ルートを分母から除いた数字を、ツール自身が出せる。
4. **`n_table`・`m_table` を軸ごとに**(6.5)。
5. **下限の根拠の種別**(条件つき・キャッシュ付き・ループの回数が既知か)。6.5 の過大の 3 型を、出力から見分けられる。
6. **`evidence` は、次数 1 以上の成長する事象だけを載せる。** 定数の事象は載らないので、「事象も `gap` も出ていない」ように見える行が出る。読み方を文書に注記するか、定数の事象の件数を出す。
7. **`summary` に、軸ごとの下限つき `unknown` の数。** 検算が書ける。
8. **Blade の `gap` の `site` に、呼び出し元の controller の行。** いまは、同じテンプレートが複数のルートを `unknown` にしている重複を、ルートごとに数えるので、多重に数えられる(Firefly III の 126 本は 1 つの共通レイアウト)。
9. **`degree` の読み方**(6.6。D38 で解決済み)。

優先度(見立て): 1・4・5 が中〜高(下限の質と原因の集計に効く)。ほかは中〜低。

### 6.10 出力サイズとメモリの削減(FIX2 で重くなった Snipe-IT)

**(b) 提案。判断を仰ぐ。実装はしていない。**

**事実。** FIX2 の後、Snipe-IT の解析は、時間が約 2.6 倍(4.171 → 10.748 秒)、ピークメモリが約 2.4 倍(421,448 → 999,696 kB。約 976 MiB)、出力 JSON が約 3.9 倍(47.9 → 186.1 MB)になった(§3.5.1)。原因は、187 ルートで共通レイアウトが歩かれ、レイアウトの中の追えない呼び出しが、ルートごとの `gap` として積み上がること(`gap` 42,417 → 180,384 件)である。要約の再利用(3,556 回を計算し、5,022 回を再利用)は効いている。他の 4 アプリには影響が無い。

**案。**

1. **同じ共通レイアウトの同じ `gap` を、ルートごとに持たず参照にする。** 出力で、`gap` をルートの中に重複して書くのをやめ、テンプレート(ファイルと行)単位の表に 1 回だけ書いて、ルートからは参照する。出力サイズが大きく減る見込みである。**出力の形(スキーマ)に触れる**(新しい欄が要る。0.x の間の変更の扱いは `docs/output-schema.md` 章 2)ので、判断が要る。判定は変わらない。
2. **要約の再利用を広げる。** 共通レイアウトの中の `gap` を、テンプレート単位の要約として再利用し、ルートごとに歩き直さない。時間とメモリを減らす見込みである。実装の重さは見積もっていない。判定が変わらないことを、試験で縛る必要がある。
3. **メモリの上限への備え**は、6.11 に分けて書く(実測は 3.5.2)。落ちたときの案内(P-A)、目安の文書化(P-B)、JSON のストリーム出力(P-C)である。P-C は、この 6.10 の案 1・2 と同じく出力サイズ・メモリを減らす案なので、合わせて判断するのがよい。

優先度(見立て): 中〜高。FIX2 は戻さない(戻すと偽の `VIEW_NOT_FOUND` が戻り、Snipe-IT の判定が歪む)ので、読み違いを直した代償を軽くする案である。

### 6.11 `memory_limit` の案内と、JSON のストリーム出力

**事実。** 既定の `memory_limit`(128M)では、5 本中 3 本(Snipe-IT・Firefly III・BookStack)が PHP の致命的エラーで落ち、peak-sim の説明は何も出ない(3.5.2)。FIX2 のせいではなく、一般の問題である。利用者が最初にぶつかる実害になりうる。

**提案(実装していない)。**

- **P-A: 落ちたときの案内(小さな修正。判定に無関係)。** メモリ不足で落ちたとき、標準エラーに「`memory_limit` を上げて再実行してください(例: `php -d memory_limit=1G bin/peak-sim …`。この規模のアプリでは数百 MB〜1GB が目安)」と一言添える。PHP の致命的エラーはキャッチできないので、`register_shutdown_function` で `error_get_last()` を見て、`Allowed memory size` のときだけ出すのが現実的である。判定・出力・成功時の挙動は変えない。赤の試験(`memory_limit` を極端に下げた子プロセスで案内が出る、正常時は出ない)を先に足す。
- **P-B: 目安を README と本書に書く。** 今回の最小完走(Laravel.io と Koel は 128M 以下、Firefly III と BookStack は 256M〜512M、Snipe-IT は JSON で約 960M・表形式で 384M〜512M)と、「既定 128M の環境(公式 Docker イメージなど)では 5 本中 3 本が落ちる」「CLI の `memory_limit` は環境による」を書く。文書だけの変更である。
- **P-C: JSON をストリームで書き出す(出力の形は変えない)。** 186 MB 規模の JSON を一度に文字列として組み立てず、少しずつ書き出す。Snipe-IT の JSON(約 960M)と表形式(384M〜512M)の差(約 0.5GB)を減らせる見込みである。出力の内容を変えずにピークを下げられる見込みだが、実装の重さと、出力が決定的であること(同じ入力から同じバイト列)の試験への影響を見積もる必要がある。6.10 の案(同じ `gap` を参照にする)と合わせて検討するのがよい。

優先度(見立て): P-A・P-B は中〜高(小さく、判定に触れない)。P-C は中(6.10 と合わせて判断)。

## 7. 仕様書・判断記録との食い違い、作業中に出た質問

### 7.1 判断記録(D33〜D40)と指示書

- **D33(判定を緩める変更の段では、新たに確定したルートの抜き取りを必ず入れる)**: 今回は判定を緩める変更を入れていない。baseline から最終版へ `unknown` が確定に動いたルートは 0 本なので(§3.2.1)、D33 の対象になる「新たに確定したルート」は無い。食い違いは無い。
- **D34(T4 は確定 5 本の全数確認と、下限つき `unknown` の追加確認とする)**: 指示書は、確定したルートを 15 本以上抜き取り、その半分以上を `O(1)`・`high` にすることを求めていた。確定は 5 本しか無く、基準に届かなかった。D34 で、アプリを足さず母数の全数を確かめる形に改めた。指示書とは食い違うが、裁定済みである。結果は §4 に書く。
- **D35(`$loop` の 1 段目の読みだけを、プロパティ読みの `gap` 化の例外とする)**: FIX3 の防壁の副作用を抑えるための例外で、baseline より判定を軽くするものではない(例外にしなければ、`$loop->index` を読むだけのテンプレートまで `unknown` になる)。例外表(`resources/known-calls.php`)には載せず、`ViewHandler` の個別処理にしてある。「ベンダーの振る舞いを知っている」という主張の追加にあたるので、D28 と同じ性質として記録した。
- **D36(FIX2 を直す)**: 裁定で直した(§5・6.4)。誤読の修正であり、判定を軽くする変更に当たらない。条件の 3 つ(拒否の縛り・再測定の追記・確定に動いたルートの全数確認)は、すべて満たした。確定に動いたルートは 0 本だった(§4.7)。
- **D37(FIX5 は「判定を軽くする変更」に当たらない。比べる基準は baseline)**: 食い違いは無い。条件の「FIX3 の最小再現が FIX5 の後も通ること」は、試験の名前つきで §5 に示した。
- **D38(`lower_bound` が `null` のとき `degree` も `null`。schema 0.2)**: 直した(§5・6.6)。出力の形を変える裁定で、D30 が確定した 0.1 に触れるため、スキーマの版を 0.2 に上げた。
- **D39(enum の `->value`・`->name` の例外化は今回は入れない)**: 入れていない。次の「判定を緩める段」の第 1 候補である(6.1)。
- **D40(Laravel 11 のアプリは足さない)**: 足していない。`docs/spec.md` に「実アプリでの試運転は Laravel 12/13 のみで、11 は未確認」と 1 行足した。

### 7.2 仕様書・出力スキーマの説明と、実際の出力の食い違い

1. **`degree` の説明**: 解消済みである(D38)。以前の `output-schema.md` は、`degree` を「`unknown` のときは意味を持たず 0」と説明していたが、実際の出力は、`unknown` でも下限があれば下限の次数を持っていた。D38 で、出力を直した(下限のない `unknown` は `degree` が `null`。下限があれば下限の次数)。`output-schema.md` の説明と例も、これに合わせて直した(schema 0.2)。
2. **`VIEW_NOT_FOUND` の説明**: 解消済みである(FIX2)。`output-schema.md` は「ビュー名が定数でない、またはファイルが無い」と説明する。以前は、ビュー名が定数でファイルもあるのに、名前に `/` を含むだけで出る場合があり(Snipe-IT で 204 件)、説明どおりでない出力が出ていた。FIX2 で出力を説明に合わせた。
3. **`BLADE_UNSUPPORTED` の説明**: 「対応外の Blade 構文(独自ディレクティブ・コンポーネント・`@can` など)」とある。実際には、Alpine や Vue の属性(`@click`・`@keydown` など)も、Blade のディレクティブとして対応外に数えられる(§3.3.2)。説明に、この場合の記述が無い。
4. **`summary.routes` と「全ルート数」**: `summary.routes` は対象のルートだけの数で、対象外(`summary.skipped`)を含まない。集計で「全ルート数」を言うときは、2 つを足す必要がある(§3.1)。スキーマの記述自体は正しいが、読み違えやすい。
5. **対象のバージョン**: `spec.md` は「Laravel 11 以降(11 はベストエフォート。動作は確認済み)」とする。今回選んだ 5 本は Laravel 12(1 本)と 13(4 本)で、Laravel 11 のアプリでは確かめていない(§1.2)。(D40 で、実アプリでの試運転は Laravel 12/13 のみで 11 は未確認、と `spec.md` に 1 行足した。)

### 7.3 作業中に出た質問と裁定

Q1〜Q5 は、2026-11-01 に D36〜D40 で裁定された(`docs/decisions.md`)。

1. **Q1: FIX2(ビュー名の `/`)を直すか。 → D36: 直す。** 誤読の修正であり、判定を軽くする変更に当たらない。条件は、拒否(`..`・先頭 `/`・`pkg::x`)の試験を先に足すこと、再測定の結果を追記すること、確定に動いたルートがあれば全数をコードで追うこと。いずれも満たした(§5・§4.7)。
2. **Q2: FIX5 は、指示書の「判定を軽くする方向の変更はしない」に当たるか。 → D37: 当たらない。** 比べる基準は baseline とする。条件は、FIX3 の最小再現が FIX5 の後も通ることを、試験の名前つきで示すこと(§5)。
3. **Q3: `degree` の説明を直すのか、出力を直すのか。 → D38: 出力を直す。** `lower_bound` が `null` のとき `degree` も `null` にし、表では `unknown (no lower bound)` と出す。`output-schema.md` も直し、`schema_version` を `0.2` にした(§5・6.6)。
4. **Q4: enum の `->value`・`->name` の過検知を例外にするか。 → D39: 今回は入れない。** 次の「判定を緩める段」の第 1 候補とし、その段で D28 の根拠と D33 の抜き取りを付けて入れる(6.1)。
5. **Q5: Laravel 11 のアプリを対象に足すか。 → D40: 足さない。** `docs/spec.md` に「実アプリでの試運転は Laravel 12/13 のみで、11 は未確認」と 1 行足した。

## 8. 試験ログ・再現

試験の実行ログは、FIX2 と D38 を入れた後に、作業木が clean な状態の HEAD(`57a5764`)で保存し直した。保存し直したのは、ビュー名の `/` の修正(FIX2)と degree の修正(D38)の後に件数が増えたためである。`9f60add` から `57a5764` までの間の commit は文書だけで、試験の対象は変わらない。

| ログ | PHP | 件数 | アサーション | SKIP | 実行した範囲 |
| --- | --- | --- | --- | --- | --- |
| `docs/research/phase1-test-php82.log` | 8.2 | 2,373 | 7,887 | 0 | Unit・Acceptance の全スイート |
| `docs/research/phase1-test-php83.log` | 8.3 | 2,373 | 7,887 | 0 | Unit・Acceptance の全スイート |

**この表のログは、FIX1〜FIX5 に FIX2 と D38 を加えた版の結果である**(§5 の全スイートの数字と同じ。FIX5 の版 `f11b1ba` の 2,336 件・7,768 アサーションから、37 件・119 アサーション増えた)。

- 実行は `--fail-on-skipped --fail-on-risky --fail-on-warning` 付きで、両方とも `OK` で終わった。
- 各ログの先頭に、実行したコマンド・PHP のバージョン・リポジトリの状態(HEAD と clean)を書いた。絶対パス・ユーザー名・ホスト名・コンテナ ID は保存の前に除いてある。
- `phase1-test-php83.log` のヘッダーの repository state は、保存スクリプトが 8.2 のログを書いた後で git の状態を取るため、一度 uncommitted と出た。実行開始時の作業木は clean だったので、この 1 行だけ手で clean に直した(他は無改変)。
- 再現は `scripts/run-tests-logged.sh <compose プロジェクト名>` で行える(PHP 8.2 と 8.3 の順に実行し、ログを書き直す)。
