# artisan 出力の調査メモ: Laravel 13

調査日: 2026-10-31 / 担当: T1 の Laravel 13 分(統合は `docs/research/artisan-compat.md` で後段に行う)

## 1. 確定した版数

| 項目 | 値 |
|---|---|
| Laravel(`composer create-project laravel/laravel:^13`) | `laravel/framework` v13.39.0(`php artisan --version` = 13.39.0) |
| 骨組みの `require.php` | `^8.3`(実物の composer.json と Packagist の `laravel/framework` v13 系で確認) |
| 公式の対応 PHP | 8.3〜8.5(Laravel 13 リリースノートの Support Policy 表。2026-10-31 に取得) |
| 検証した最小 PHP | 8.3.36(`php:8.3-cli`) |
| 検証した最大 PHP | 8.5.12(`php:8.5-cli`) |
| Composer | 2.11.1 |
| DB(主) | MySQL 8.4.7(`mysql:8.4.7` の公式イメージ) |
| DB(補足) | SQLite(Laravel 既定。PHP 8.5 のみで取得) |

PHP 8.4 は取っていない(最小と最大のみ。手順 1 の指示どおり)。

composer が解決した依存は PHP で異なる。PHP 8.3 では Symfony 7.4 系(`symfony/console` v7.4.22 ほか)、PHP 8.5 では Symfony 8.1 系(同 v8.1.10 ほか)になった。`laravel/framework` は両方 v13.39.0 で同じ。この違いがあっても、下記の 5 コマンドの標準出力はバイト単位で同一だった。

## 2. 再現手順

絶対パスや環境情報は書かない。`$V` は 8.3 または 8.5。Compose のプロジェクト名は版ごとに分ける。

```sh
# 開発用 Dockerfile(公式 php イメージ + pdo_mysql + zip + git/unzip + composer)
#   FROM php:${PHP_VERSION}-cli
#   RUN apt-get install -y git unzip libzip-dev && docker-php-ext-install pdo_mysql zip
#   COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# compose: db(mysql:8.4.7, ホストポートは公開しない) + app(上のイメージ, ホストの UID:GID で実行)

docker compose -p t1-13-php$V up -d --build
docker compose -p t1-13-php$V exec app composer create-project laravel/laravel:^13 laravel --no-interaction
# .env を mysql に変更: DB_CONNECTION=mysql DB_HOST=db DB_PORT=3306 DB_DATABASE=laravel DB_USERNAME=root DB_PASSWORD=<使い捨て>
# 題材(下記 §2.1)のモデル・マイグレーション・コントローラ・ルートを配置
docker compose -p t1-13-php$V exec -w /work/laravel app php artisan migrate:fresh --force

# 5 コマンド。標準出力と標準エラーを別ファイルに取り、終了コードも記録する
for c in "route:list --json" "model:show Order --json" "model:show Item --json" \
         "db:table orders --json" "db:table items --json"; do
  docker compose -p t1-13-php$V exec -T -w /work/laravel app php artisan $c > out.json 2> err.txt
  echo $? > exit.txt
  python3 -m json.tool out.json > /dev/null   # 構文検証
done

docker compose -p t1-13-php$V down -v         # 片付け
```

SQLite 版は `.env` を `DB_CONNECTION=sqlite` にして(`DB_HOST` などの行を除き)`database/database.sqlite` を作り、同じ手順を繰り返した。

php.ini は無い(`php -i` が `Loaded Configuration File => (none)`)。公式イメージの既定のままで、`display_errors` は STDOUT、`error_reporting` は未設定(PHP 8 の既定 = E_ALL)。

### 2.1 題材(全 PHP 共通)

- customers(id, name, email)、products(id, name, price)、orders(id, customer_id → customers, status, total)、items(id, order_id → orders, product_id → products, quantity, price)。いずれも `timestamps()` 付き
- Customer(hasMany Order)、Order(belongsTo Customer, hasMany Item)、Item(belongsTo Order, belongsTo Product)、Product(hasMany Item)
- ルート: 素の骨組みの `/`、`/up` に加え、`GET /orders`(OrderController@index)・`GET /orders/{order}`(OrderController@show)・`POST /orders`(クロージャ)を足した(コントローラ・クロージャ両方の出力形を見るため)

## 3. 結果の表

「動く」は終了コード 0 かつ標準出力が JSON として妥当(`python3 -m json.tool` が通る)かつ標準エラーが空。

| コマンド | PHP 8.3 / MySQL | PHP 8.5 / MySQL | PHP 8.5 / SQLite | PHP 間の差 |
|---|---|---|---|---|
| `route:list --json` | 動く | 動く | 動く | なし(バイト同一) |
| `model:show Order --json` | 動く | 動く | 動く(差分あり: §4.2) | なし(バイト同一) |
| `model:show Item --json` | 動く | 動く | 動く(差分あり: §4.2) | なし(バイト同一) |
| `db:table orders --json` | 動く | 動く | 動く(差分あり: §4.3) | なし(バイト同一) |
| `db:table items --json` | 動く | 動く | 動く(差分あり: §4.3) | なし(バイト同一) |

全 15 実行で終了コード 0、標準エラーは 0 バイト、標準出力は JSON として妥当。素の状態では、非推奨警告などが標準出力に混ざって JSON が壊れることは起きなかった。

## 4. 差分・想定外

### 4.1 JSON が壊れる条件(実際に再現した)

- **`bootstrap/app.php` など、フレームワークの例外ハンドラが登録される前に実行されるコードが非推奨警告を出すと、標準出力の先頭に `\nDeprecated: ... in <project>/bootstrap/app.php on line 2\n` が混ざり、JSON が壊れる。** 終了コードは 0 のまま。`APP_ENV=local`/`APP_DEBUG=true` でも `production`/`false` でも同じ。PHP 8.3・8.5 の両方で再現。標準エラーは空で、警告は標準出力へ出る(php.ini 無しでは `display_errors` が STDOUT のため)
- 同じ非推奨警告を `routes/web.php`(ルート読込時に実行される)に置いた場合は、標準出力に混ざらなかった。フレームワークの例外ハンドラが登録済みで、警告がログに回るため(実行されたことは確認済み)
- 提案: ツール側は標準出力を JSON として厳密に解析し、先頭の非 JSON 部分を検出して明示的に失敗させるか、最初の `[` または `{` までを読み飛ばす。標準エラーには頼れない。可能なら `php -d display_errors=stderr` や `-d error_reporting=...` を artisan の呼び出し設定に足せるようにする

### 4.2 `model:show --json`(MySQL と SQLite の差)

キー構成は同じ(`class, database, table, policy, attributes, relations, events, observers, collection, builder, resource`)。差は値のみ。

- `database`: `mysql` と `sqlite`
- `attributes[].type`: MySQL は `bigint unsigned`・`int unsigned`・`varchar(255)`・`timestamp`。SQLite は `integer`・`integer`・`varchar`・`datetime`(符号・長さが落ちる)
- `relations` は DB に依らず同一(`name`, `type`(`BelongsTo`/`HasMany` の短い名前), `related`(完全修飾クラス名))。外部キーの列名は含まれない

### 4.3 `db:table --json`(MySQL と SQLite の差)

キー構成は同じ(`table, columns, indexes, foreign_keys`)。

- `table`: SQLite は `size`・`comment`・`collation`・`engine` が `null`。`schema` は MySQL がデータベース名(`laravel`)、SQLite が `main`
- `columns[].type`: SQLite は型の親和性名のみ(`integer`, `varchar`, `datetime`)
- `indexes`: SQLite は `primary` のみ。MySQL は外部キーに自動で付く索引(`orders_customer_id_foreign` など)も出る
- `foreign_keys[].name`: SQLite は `null`。MySQL は制約名。複数ある場合の並び順も異なる(SQLite は定義と逆順に見えた)
- `on_update`/`on_delete`: どちらも `no action`(MySQL の既定の RESTRICT も `no action` と出る)

### 4.4 同一 Laravel 内で気づいた形式上の癖(MySQL でも SQLite でも)

- **`db:table` の `columns[].attributes`、`indexes[].attributes` は、JSON の配列になる場合とオブジェクト(キーが `"0"`, `"2"`, `"4"` のような飛び番号)になる場合が混在する。** 例: `["bigint"]` と `{"0":"bigint","2":"autoincrement"}`。PHP の配列のキーが飛んだまま JSON 化されるため。ツールは両方を読めるようにする
- `route:list --json` の `method` は `"GET|HEAD"` のように `|` 区切りの文字列。`uri` は先頭に `/` が付かない(ルート `/` のみ `"/"`)。`path` はクロージャ(と `storage.local` など)ではファイルと行(`routes/web.php:5`)、コントローラ指定では `null`
- `route:list --json` の `action` は `Closure`、`App\Http\Controllers\OrderController@index`、`Illuminate\Foundation\Http\HealthCheck` のように 3 形式
- 素の骨組みでも `storage/{path}`(`storage.local`)と `up` がルートに出る。ツールは対象外にするか判断が要る
- `db:table` の `table.size` はデータサイズ依存(MySQL で 32768 / 49152)で、再実行や環境で変わりうる

### 4.5 DB に接続できない場合(想定外の挙動)

`DB_HOST` を到達不能なホストにして実行した(PHP 8.3・8.5 で同じ結果)。

- `route:list --json`: 動く(DB 不要)
- `model:show Order --json`、`db:table orders --json`: **DB 接続が必須。** 終了コード 1。**エラー本文(整形済みの例外と ANSI 装飾の制御文字を含む)が標準出力に出て、標準エラーは空。** 標準出力は JSON にならない。したがってツールは「終了コード 0 以外」を先に見て、標準出力を解析しないこと

## 5. 未確認事項

- Laravel 13 での PHP 8.4(最小と最大の間)は取っていない
- ここで取ったのは `laravel/framework` v13.39.0 の 1 点のみ。13.x の他のパッチでの差は未確認(`composer create-project` の最新パッチ)
- SQLite は PHP 8.5 のみ。PHP 8.3 での SQLite は未確認
- SQLite の外部キー並び順が定義逆順になるのは 2 テーブルの観察のみ。規則かどうかは未確認
- 本調査の対象外: MariaDB・PostgreSQL、`--json` 以外の出力、`route:list` のオプション(`--path` など)、巨大なルート数・多数のモデルでの挙動

## 6. 保存した出力について

- `php8.3/mysql/`、`php8.5/mysql/`、`php8.5/sqlite/`: 5 コマンドの標準出力(`*.stdout.json`)、標準エラー(`*.stderr.txt`、全て空)、終了コード(`*.exit`)
- `php8.3/probe/`、`php8.5/probe/`: §4.1 の非推奨警告の実験(`deprecation-*`)と §4.5 の DB 接続不能の実験(`nodb-*`)。標準出力は JSON でないので拡張子は `.stdout.txt`
- **消した箇所:** プローブ出力に含まれるコンテナ内の作業ディレクトリの絶対パスを `<project>` に置換した(`deprecation-*.stdout.txt` の 1 か所ずつ)。接続用の使い捨てパスワードを含む `.env` の内容は保存していない。上記以外の標準出力は無加工
- 骨組みの `composer create-project` のログと `migrate` の出力は保存していない
