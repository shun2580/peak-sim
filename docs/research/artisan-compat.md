# artisan 互換性調査(Laravel 11 / 12 / 13)

調査日: 2026-10-31

対象は、ツールが依存する次の 3 コマンドの JSON 出力である。

- `php artisan route:list --json`
- `php artisan model:show {Model} --json`
- `php artisan db:table {table} --json`

三版の調査メモと、取得した実出力は `docs/research/artisan/{11,12,13}/` にある。この文書の表の事実は、メモの記述ではなく保存出力で確かめたものだけを「確認済み」と書く。保存出力に裏が無いものは、その旨を書く。

## 0. 要点

1. 5 コマンド(`route:list`、`model:show` ×2、`db:table` ×2)は、公式対応の PHP の範囲で 11・12・13 のすべてで動く。MySQL 8.4.7 では、同じ Laravel の中で PHP の最小と最大の出力はバイト単位で同一だった(11 は 8.2 と 8.4、12 は 8.2 と 8.5、13 は 8.3 と 8.5)。
2. 版間の差は小さい。キー構成の差は 3 点だけである(§2.1)。`db:table` の `attributes` が配列とオブジェクトで混在する癖は三版とも同じである(§2.2)。
3. 本当の問題は標準出力の汚染である。警告が出る場所によっては、終了コード 0・標準エラー空のまま、標準出力の JSON の前に警告文が混ざって壊れる。これは版の差ではなく、「どこで警告が出たか」で決まる(§3)。
4. `model:show` と `db:table` は DB 接続が必須である。DB に届かないと終了コード 1 になり、エラー本文が標準出力に出る(§4)。
5. Laravel 11 は技術的には動くが、保守が終わっており、勧告に修正版が無い。ツールが 11 を支持するかどうかは殿の判断である(§5)。

## 1. 対応表

### 1.1 凡例

- 「動く」: 終了コード 0、標準エラーが空、標準出力が JSON として妥当(`python3 -m json.tool` が通る)。
- 「JSON 破損」: 終了コードと標準エラーは「動く」と同じだが、標準出力の前に警告文が混ざり、JSON として読めない。
- 「差分あり」: 動くが、MySQL の出力と値が異なる(SQLite、§2.3)。
- **PHP の最小・最大**: 最大は各版の公式対応上限である(11 = 8.4、12 = 8.5、13 = 8.5)。最小は 11 = 8.2、12 = 8.2、13 = 8.3 である。11 では公式範囲外の 8.5 も参考として取った。その列は「参考」と明記する。
- 三版とも、PHP 8.2 と 8.5 の間の中間の版(8.3 または 8.4)は、取っていない箇所がある(§6)。
- DB は MySQL 8.4.7。SQLite は補足である。
- 試験は素の骨組みに題材(customers / products / orders / items)を足した状態で行った。警告の注入は含まない(§3 で別に扱う)。

### 1.2 コマンド × Laravel × PHP(MySQL 8.4.7)

| コマンド | 11.57.1 / PHP 8.2 | 11.57.1 / PHP 8.4 | 11.57.1 / PHP 8.5(参考・公式範囲外) | 12.70.2 / PHP 8.2 | 12.70.2 / PHP 8.5 | 13.39.0 / PHP 8.3 | 13.39.0 / PHP 8.5 |
|---|---|---|---|---|---|---|---|
| `route:list --json` | 動く | 動く | **JSON 破損** | 動く | 動く | 動く | 動く |
| `model:show Order --json` | 動く | 動く | **JSON 破損** | 動く | 動く | 動く | 動く |
| `model:show Item --json` | 動く | 動く | **JSON 破損** | 動く | 動く | 動く | 動く |
| `db:table orders --json` | 動く | 動く | **JSON 破損** | 動く | 動く | 動く | 動く |
| `db:table items --json` | 動く | 動く | **JSON 破損** | 動く | 動く | 動く | 動く |

確認済みのこと(保存出力で確かめた):

- 表のすべてで終了コード 0・標準エラー 0 バイト(11 の 8.5 の JSON 破損 5 件も同じ。破損は標準出力の側に出る)。「動く」の欄は、すべて `python3 -I -m json.tool` が通る。
- 同じ Laravel の中で、PHP の最小と最大の MySQL 出力はバイト同一(11 は 8.2 と 8.4 を `diff -r`、12 は 8.2 と 8.5、13 は 8.3 と 8.5 を `cmp`)。13 は Composer が解決した Symfony が 8.3 では 7.4 系、8.5 では 8.1 系と異なっていたが、出力は同一だった。
- 11 の 8.5 だけが壊れる。壊す警告は、骨組みの `config/database.php`(61 行目と 81 行目)の `PDO::MYSQL_ATTR_SSL_CA` が 8.5 で非推奨になったことによる。12 と 13 は 8.5 で素の状態でも壊れなかった。なぜ 12・13 では出ないのかの原因(骨組みの `config/database.php` の書き方の差かどうか)は、調べていない。

### 1.3 SQLite(補足)

| Laravel | PHP | 結果 |
|---|---|---|
| 11.57.1 | 8.4 のみ | 5 コマンドとも動く。`route:list` は MySQL と同一。他は差分あり(§2.3) |
| 12.70.2 | 8.5 のみ | 同上 |
| 13.39.0 | 8.5 のみ | 同上 |

SQLite は、版ごとに 1 つの PHP でしか取っていない(11 は 8.4、12 と 13 は 8.5)。したがって「SQLite の差分が PHP によって変わるか」は、どの版でも確かめていない。

## 2. 差分の具体

### 2.1 版間のキー構成の差(確認済み。MySQL・同一の取得条件で比べた)

| 対象 | 11.57.1 | 12.70.2 | 13.39.0 |
|---|---|---|---|
| `route:list` の各行のキー | `domain, method, uri, name, action, middleware` | 左に **`path`** が加わる(クロージャは `routes/web.php:5` のようなファイルと行、コントローラ指定は `null`、ベンダー登録ルートはベンダー内のファイルと行) | 12 と同じ。`up` ルートの `path` は `null` |
| `model:show` の最上位キー | `class, database, table, policy, attributes, relations, events, observers` | 11 と同じ | 左に **`collection, builder, resource`** が加わる(値は `Illuminate\Database\Eloquent\Collection`、`Illuminate\Database\Eloquent\Builder`、`null`) |
| `db:table` の `table` のキー | `schema, name, columns, size, comment, collation, engine` | 左に **`schema_qualified_name`** が加わる | 12 と同じ |

素の骨組みに入っているルートにも差がある。

| 素のルート | 11.57.1 | 12.70.2 | 13.39.0 |
|---|---|---|---|
| `storage/{path}` | GET|HEAD(`storage.local`)のみ | GET|HEAD に加え **PUT(`storage.local.upload`)** | 12 と同じ |
| `up` の `action` | `Closure` | `Closure` | `Illuminate\Foundation\Http\HealthCheck` |

`model:show` の `attributes[]`(`name, type, increments, nullable, default, unique, fillable, hidden, appended, cast`)と `relations[]`(`name, type, related`)、`db:table` の `columns[]`・`indexes[]`・`foreign_keys[]` のキー構成は、三版で同一だった。`on_update`・`on_delete` は三版とも `no action`(MySQL の既定でも `no action` と出る)。

**ツールへの含意:** 追加されたキーは読み飛ばせば済む。`route:list` の `path` を使う設計にするなら、11 には無いことを前提にすること。

### 2.2 三版に共通する形式の癖(確認済み)

- **`db:table` の `columns[].attributes` と `indexes[].attributes` は、JSON の配列になる場合と、飛び番号のキーを持つオブジェクトになる場合が混在する。** 例: `["bigint"]` と `{"0":"bigint","2":"autoincrement"}`。主キー索引は `{"0":"btree","3":"primary"}`。PHP の配列のキーが飛んだまま JSON になるためである。キーの番号は位置として安定しないので、値だけを読むこと(12 のメモが `customers_email_unique` の `{"0":"btree","2":"unique"}` を示している)。
- `db:table` の索引には `unique` や `primary` の真偽値のキーが無い。種別は `attributes` の値(`"primary"`、`"unique"`)にだけ入る。
- `route:list` の `method` は `"GET|HEAD"`、`"POST|PUT"` のように `|` で連結した 1 つの文字列である。`uri` は先頭に `/` が付かない(ルート `/` だけが `"/"`)。行は `uri` 順で、定義順ではない。`middleware` は名前のままで、クラスには展開されない。`action` は `Closure`、`App\...\Controller@method`、`invokable` はクラス名のみ、の形がある。
- `model:show` の `relations[]` は `name`、`type`(`BelongsTo`・`HasMany` のような短い名前)、`related`(完全修飾クラス名)だけで、外部キーの列名は含まれない。
- `db:table` の `table.columns` は列の個数(整数)で、列の一覧ではない。`table.size` はデータの状態に依存して変わる。
- 素の骨組みでも `storage/{path}` と `up` がルートに出る。対象にするかどうかはツール側の判断が要る。

### 2.3 SQLite と MySQL の差(確認済み)

三版とも同じ傾向である。`route:list` は同一で、他は次の点が異なる。

| 場所 | MySQL 8.4.7 | SQLite |
|---|---|---|
| `model:show` の `database` | `"mysql"` | `"sqlite"` |
| 列の `type`(`model:show`・`db:table`) | `bigint unsigned`、`varchar(255)`、`decimal(10,2)`、`int unsigned` | `integer`、`varchar`、`numeric`、`integer`(長さ・精度・符号が落ちる) |
| `db:table` の `columns[].attributes` | 基本型に加え、文字列列は照合順序(例 `{"0":"varchar","4":"utf8mb4_unicode_ci"}`) | 基本型のみ(`["varchar"]`) |
| `db:table` の `table` | `size` は 32768 / 49152、`comment` は `""`、`collation` は `utf8mb4_unicode_ci`、`engine` は `InnoDB` | `size` は 4096、`comment`・`collation`・`engine` は `null` |
| `db:table` の `table.schema` | データベース名(`laravel`) | 12・13 は `main`(`schema_qualified_name` は `main.orders`)。11 は `null`(`schema_qualified_name` のキーが無い) |
| `db:table` の `indexes` | 主キーに加え、**外部キーの列ごとに 1 本**(`orders_customer_id_foreign` など) | **主キーのみ**。外部キーの列に索引が出ない |
| `db:table` の `foreign_keys[].name` | 制約名(`orders_customer_id_foreign` など) | `null` |
| `db:table` の `foreign_keys[].foreign_schema` | データベース名 | 12・13 では `main`。11 は `null` |
| `foreign_keys` の並び(`items`) | `order_id`、`product_id`(定義順) | `product_id`、`order_id`(逆順) |

D20 に直結する点: SQLite は外部キーの列に索引を報告しない。SQLite の出力で「外部キーの列に索引があるか」を判定すると、すべての外部キーを「索引なし」と誤判定する。索引の有無は MySQL の出力で見ること。外部キーの並び順が逆になるのは、2 テーブルの観察のみで、規則かどうかは確かめていない。

### 2.4 題材の差(版差ではない)

三版の調査は、題材が完全には同じではない。以下は題材の差であり、上の版間差には含めていない。

| 項目 | 11 | 12 | 13 |
|---|---|---|---|
| モデルの `timestamps` | なし(`$timestamps = false`) | なし(`$timestamps = false`) | あり(`created_at`・`updated_at` の列が `model:show` に出る) |
| 足したルート | 補足パスで拡充(下記) | `GET /orders`(`throttle:60,1`)、`GET /orders/{order}`、`Route::resource('items')->only(['index','store'])` | `GET /orders`、`GET /orders/{order}`(コントローラ)、`POST /orders`(クロージャ) |
| `middleware` に出る名前 | 素のルートは `web` | `web` に加え `throttle:60,1` が出る行がある | `web` |

したがって、`model:show` の属性数や `route:list` の行数、`middleware` の中身を三版で比べても、版差の根拠にならない。版間の比較ができるのは、題材が重なる部分(§2.1 のキー構成、素の骨組みのルート、列の型の書式など)だけである。

11 だけは、素の骨組みのルートが 3 件と乏しいので、補足パス(`route-list-extra`)を取った。コントローラの各アクション、`Route::resource`(7 アクション)、`invokable`、`prefix` と `['web','auth']` のグループ、`domain` 指定、`Route::match`、`where()` と省略可能パラメータなど 16 行である。キー構成は通常の 6 つのままで、PHP 8.2 と 8.4 で同一だった。12 と 13 では、これらの形は取っていない(§6)。

## 3. 標準出力の汚染

### 3.1 前提

公式の `php` イメージには `php.ini` が無い(`Loaded Configuration File: (none)`)。この状態の既定では `display_errors` が標準出力向きで、`error_reporting` は E_ALL である。以下の結果は、この既定の条件に限る。本番用の `php.ini`(`display_errors=Off`)、非 Docker の PHP、Alpine 版は試していない。

### 3.2 「どこで出た警告か」で整理する

警告が壊すかどうかは、出る場所と種類で決まる。表の各行は、取れた版と PHP を明記する。**取っていない版には「未試験」と書く。「壊れない」と読まないこと。**

| 警告の出る場所 | 警告の種類 | 結果 | 取った版・PHP | 保存出力 |
|---|---|---|---|---|
| **早い段階**: Laravel の例外ハンドラが登録される前(`bootstrap/app.php` の先頭、config 読込と同じ段階) | 非推奨(`strlen(null)`)、警告(未定義変数)、8.4 では暗黙の null 許容引数の非推奨 | 警告文が**標準出力の JSON の前**に出る。終了コード 0、標準エラー空、**JSON 破損** | 11(8.2・8.4)、13(8.3・8.5。`APP_ENV`・`APP_DEBUG` を変えても同じ) | `11/php8.2/induced-early/`、`11/php8.4/induced-early/`、`13/php*/probe/deprecation-*` |
| 同上(**素の骨組みが 8.5 で自ら出す**) | `config/database.php` の `PDO::MYSQL_ATTR_SSL_CA` の非推奨 | 同上。**JSON 破損** | 11(8.5 のみ) | `11/php8.5/mysql/` |
| 起動後(ルート読込中) | 非推奨(`E_USER_DEPRECATED`) | Laravel が握りつぶし、何も出ない。JSON は妥当 | 12(8.5)。13 にも同じ報告があるが、13 は出力を保存していない(下記注) | `12/php8.5/warning-probe/deprecation-default*` |
| 起動後(ルート読込中) | 警告・注意(`E_USER_WARNING`・`E_USER_NOTICE`) | `ErrorException` になる。**終了コード 1**。整形された例外本文が**標準出力**に出る。JSON なし。標準エラーは改行 1 つ | 12(8.5) | `12/php8.5/warning-probe/` |
| 起動後(モデルのコンストラクタ。`model:show` が呼ぶ) | 非推奨(`strlen(null)`)と警告(未定義変数) | 非推奨は握りつぶされ、警告は `ErrorException`:**終了コード 1**、例外本文が標準出力、標準エラー空、JSON なし | 11(8.2・8.4) | `11/php8.2/induced-late/`、`11/php8.4/induced-late/` |
| 起動中(ルートのファイル内の直接出力) | `echo "stray output";` | テキストが JSON の前に付く。**終了コード 0 のまま JSON 破損** | 12(8.5) | `12/php8.5/warning-probe/echo-in-routes.*` |

注(13 の `routes/web.php` 内の警告): 13 のメモは「同じ非推奨警告を `routes/web.php` に置いても標準出力に混ざらなかった」と書くが、対応する出力は保存されていない(軍師QCの指摘も同じ)。12 の `routes` 内の非推奨が握りつぶされるという保存済みの結果と整合するが、13 については保存出力による裏付けが無い。

### 3.3 版間差と試験条件差を混ぜない

この表で、版が違うと壊れ方が変わるように見える箇所のうち、大半は**試験条件の差**である。

- 「早い段階の警告」の試験は 11 と 13 でしか行っていない。12 では行っていない(12 のメモは起動後の警告だけを注入した)。だから「12 は早い段階の警告で壊れない」とは言えない。**未試験**である。
- 「`E_USER_WARNING`・`E_USER_NOTICE`・ルート内の `echo`」は 12 でしか行っていない。11・13 で同じ結果になるかは**未試験**である。
- 起動後の警告は 11(モデルのコンストラクタ内)と 12(ルートのファイル内)で、注入箇所が違う。結果は両方とも「警告は例外になって終了コード 1・標準出力に本文」で矛盾しない。

版による差が実際に確認できたのは、次の 1 点だけである。**PHP 8.5 で素の状態のまま壊れるのは 11 だけ**(12・13 は壊れなかった)。11 は 8.5 が公式範囲外なので、運用上の問題は小さい。ただし `composer.json` の制約は `^8.2` で 8.5 を通す。

### 3.4 ツール側の吸収策(提案)

1. **`php -d display_errors=stderr artisan ...` で呼べるようにする(設定可能にする)。**
   - 効いたことを保存出力で確認した範囲: **Laravel 11 の `route:list --json` だけ**(PHP 8.2・8.4 の「早い段階」の注入、PHP 8.5 の素の状態)。警告文が標準エラーに移り、標準出力は妥当な JSON に戻った。PHP 8.5 では `model:show Order` に `-d display_errors=0`、`db:table orders` に `-d error_reporting="E_ALL & ~E_DEPRECATED"` を付けた場合も、JSON が妥当になった(保存あり)。
   - **効くと確認できていない範囲:** 12 と 13 では、`display_errors=stderr` を付けた試験をしていない。11 でも `model:show`・`db:table` に `display_errors=stderr` を付けた試験は無い。13 のメモも同じ案を提案しているが、試験はしていない。
   - **効かないと分かっている範囲:** 起動後の警告が `ErrorException` になる場合(§3.2 の終了コード 1 の行)は、Laravel が例外本文を標準出力に書く。`display_errors` の設定では防げない。この場合に `display_errors=stderr` を付けた試験は、していない。**(未確認。効かない可能性が高いが、確かめていない。)**
2. **標準出力と標準エラーを分けて取り、標準出力を JSON として厳密に構文検証する。** 終了コード 0 でも JSON が壊れる場合(早い段階の警告、`echo`)があるため、終了コードだけでは検知できない。先頭に非 JSON の部分があれば、明示的に失敗にして中身を報告する。最初の `[` または `{` までを読み飛ばす案もあるが、警告の文章に `[` が含まれうるので、推奨しない(判断はツールの設計者に委ねる)。
3. **終了コードを先に見る。** 0 以外のときは標準出力を JSON として解析しない。例外本文は標準エラーではなく標準出力に出る。
4. **事前書き出しモード(S-31 (d))では、書き出し側で上の 1〜3 を行う。** 解析側は、検証を通った JSON ファイルだけを読む。

## 4. 想定外の問題

| 問題 | 11 | 12 | 13 |
|---|---|---|---|
| `model:show`・`db:table` に DB 接続が必須か | **必須**(確認済み。PHP 8.4。到達不能ホストで終了コード 1、`QueryException` の本文が標準出力、JSON なし) | **未確認**(12 では試していない。13 の結果を当てはめない) | **必須**(確認済み。PHP 8.3・8.5。終了コード 1、整形された例外本文と ANSI 装飾の制御文字が標準出力、標準エラー空) |
| `route:list` に DB 接続が必要か | 不要(終了コード 0、JSON 妥当) | 未確認 | 不要(同左) |
| コマンドの有無 | 3 コマンドとも存在 | 同左 | 同左 |
| JSON 破損 | PHP 8.5 の素の状態、および早い段階の警告(§3) | 起動後の警告・`echo`(§3) | 早い段階の警告(§3) |
| 骨組みの導入 | 現行の Composer 2.11.1 が、11.31〜11.57.1 のすべてを勧告で弾く。`composer create-project laravel/laravel:^11` が失敗し、`COMPOSER_NO_SECURITY_BLOCKING=1` が要る(出力には影響しない) | 該当の記録なし | 該当の記録なし |

出力は、DB の到達不能時に使い物にならなくなる(エラー本文だけが標準出力に出る)。ツールは、`model:show` と `db:table` については、到達できるローカル DB にマイグレーション済みのスキーマがあるか、あるいは事前に書き出した JSON を使う(S-31 (d))前提で設計すること。D5(データの中身は読まない)とは矛盾しない。

## 5. Laravel 11 の扱い(殿の判断材料。この文書は支持の可否を決めない)

次の事実を併記する。

**事実(技術)**

- 11.57.1 は PHP 8.2 と 8.4 で、5 コマンドとも動く(§1.2)。出力は 8.2 と 8.4 でバイト同一。
- 12・13 との差は、キー構成の 3 点と素のルートの差だけである(§2.1)。

**事実(保守状況)**

- Laravel 公式ドキュメントの Laravel 11 のセキュリティ修正期限は **2026-03-12** で、調査日(2026-10-31)の時点で過ぎている。この期限の日付は、ashigaru が公式ドキュメントから取得したメモと、軍師QCの記述に依る。この文書の作成者は公式ドキュメントを独立には見直していない。
- Packagist のセキュリティ勧告 **PKSA-mdq4-51ck-6kdq**(CVE-2026-48019、2026-05-19 登録、題は「Laravel CRLF injection in default email rule」)の影響範囲は `>=9.0.0,<10.0.0`、`>=10.0.0,<11.0.0`、`>=11.0.0,<12.0.0`、`>=12.0.0,<12.60.0`、`>=13.0.0,<13.10.0` である。11 系は全バージョンが影響を受け、修正版が無い。12 は 12.60.0 以降、13 は 13.10.0 以降が修正版である(この文書の作成者が 2026-10-31 に Packagist の API で確認した)。
- この勧告のため、現行の Composer は Laravel 11 の全リリースの導入を既定で拒否する(§4)。

**意味すること**

- 11 を支持範囲に入れると、利用者は「Composer に勧告を無視させて導入する」か「既に導入済みの 11 を使う」ことになる。ツール自体は artisan の出力を読むだけで、対象アプリの実行時の安全性には関与しない。ただし、公開実績として「保守が終わり、未修正の勧告がある版を公式に支持する」と見えるかどうかは、方針の問題である。
- 技術的な作業量は小さい(支持範囲に加えるだけで、キー構成の差は 3 点)。

**殿の判断が要ること:** ツールが 11 を公式に支持するか。選択肢は、(a) 支持する(注意書きつき)、(b) 動作は妨げないが公式には支持しない(ベストエフォート)、(c) 対象外にする。これは互換性ではなく方針であり、この文書では決めない。

## 6. 未確認の事項

- **Docker での再取得による再現は、軍師QCでも未実施である。** QC は保存出力の内部整合、Packagist、公開物の走査までを確認した。再取得による再現は「観測不能」であって、否定ではない。この文書の作成者も再取得はしていない(保存出力と Packagist の確認のみ)。
- 12 と 13 の「早い段階の警告」「`E_USER_WARNING`・`E_USER_NOTICE`・`echo`」は、片方の版でしか試していない(§3.3)。
- `display_errors=stderr` の効果は、11 の `route:list` でしか確認していない(§3.4)。12・13、11 の他コマンド、起動後の例外には未試験である。
- 12 で DB 接続が必須かどうかは未確認(§4)。
- PHP の中間の版は、取っていない。12 の 8.3・8.4、13 の 8.4、11 の 8.3。11 は 8.2 と 8.4 が同一なので中間も同一と推定するが、確かめていない。
- Laravel のパッチ版は各 1 点(11.57.1、12.70.2、13.39.0)のみ。それ以前のパッチは比べていない。
- SQLite は版ごとに 1 つの PHP のみ(11 は 8.4、12・13 は 8.5)。外部キーの並び順が逆になる件は、2 テーブルの観察のみ。
- 12・13 の `route:list` の拡充(`domain` 指定、`prefix` のグループ、`Route::fallback` など)は、11 でしか取っていない。
- `model:show` は、リレーションを持つ 2 モデルのみ。`$with`、キャスト、アクセサ、オブザーバ、ポリシー、ソフトデリート、ポリモーフィックなリレーション、戻り型の無いリレーションは未確認。
- `db:table` は、ビュー、複合索引、一意でない索引は未確認(一意索引は 12 の `customers` のみ)。
- MariaDB・PostgreSQL・MySQL 8.4.7 以外の版は未確認。`--json` 以外の出力や `route:list` のオプションも対象外。
- 13 の `routes/web.php` 内の警告が標準出力に混ざらないという記述は、保存出力による裏付けが無い(§3.2 の注)。
- 12 と 13 で、PHP 8.5 の素の状態が壊れない原因は調べていない(§1.2)。
- 保存出力の体裁は三版で揃っていない(11 は `*.stdout`・`*.stderr`、12 は `*.json`・`*.err`、13 は `*.stdout.json`・`*.stderr.txt`)。12 は壊れた出力にも `.json` の拡張子が付いており、妥当かどうかは隣の `*.jsoncheck` を見る必要がある。機械処理するなら名前を揃える判断が要る。

## 7. 保存出力の対応

| 版 | 配置 |
|---|---|
| 11 | `artisan/11/php8.2/`、`php8.4/`、`php8.5/`(参考)。各 `mysql/`、`induced-early/`、`induced-late/`、`mitigation/`。8.4 に `sqlite/`、`no-db/`。メモ本体は `artisan/11/NOTES.md` |
| 12 | `artisan/12/php8.2/mysql/`、`php8.5/mysql/`、`php8.5/sqlite/`、`php8.5/warning-probe/`。メモ本体は `artisan/12/NOTES.md` |
| 13 | `artisan/13/php8.3/`、`php8.5/`。各 `mysql/`、`probe/`。8.5 に `sqlite/`。メモ本体は `artisan/13/NOTES.md` |

保存出力は、環境情報(コンテナ内のプロジェクトのパス)を消したものである。11 は `<scrubbed>`、13 は `<project>` に置換した。12 は元から含まれていなかった。接続用の資格情報を含む `.env` の内容は保存していない。
