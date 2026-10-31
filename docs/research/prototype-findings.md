# 検出プロトタイプ(T3)の所見

対象: `src/Prototype/`(PHP + nikic/php-parser 5)。フェーズ1の本実装ではなく、静的解析で判定できるかの見通しを小さな範囲で確かめたもの。作り直す前提。

試験: `tests/Prototype/`。PHP 8.2 と 8.3 の両方で全件通過、SKIP 0(`docs/research/prototype-test.log`)。

## 1. 結論

- 指示書の3ルート(`/ng/n-plus-one`・`/ok/eager`・`/ng/layered`)は、`fixtures/expected.json` の query・memory・n_table のすべてと一致した。確度は3つとも high。
- 「簡易推論(`model:show` のリレーション名 + 先読みの追跡)」と「ServiceProvider の束縛からのインターフェース解決」は、この範囲では機能した。PHPStan / Larastan は使っていない。
- 一方で、**追えないものを黙って捨てると、確度 high のまま間違った答えを出す**ことが実測で分かった(§4)。フェーズ1の本実装で最優先に直すべき点。
- 解析中に artisan・DB・ネットワークは使っていない。入力は `fixtures/artisan-export/` の JSON(事前書き出しモード)とアプリのソースだけ。

## 2. 3ルートの判定結果

| ルート | 期待(query / memory / N) | 判定 | 確度 | 一致 |
| --- | --- | --- | --- | --- |
| `/ng/n-plus-one` | O(N) / O(N) / orders | O(N) / O(N) / orders | high | 一致 |
| `/ok/eager` | O(1) / O(N) / orders | O(1) / O(N) / orders | high | 一致 |
| `/ng/layered` | O(N) / O(N) / orders | O(N) / O(N) / orders | high | 一致 |

判定の根拠(evidence の要約。行番号は実出力のとおり):

- `/ng/n-plus-one`: `BasicNPlusOneController.php:13` の `Order::get()`(件数制限なし)の結果を `foreach` し、`:17` の `$order->customer` が `Order` のリレーションで、先読みされていないので遅延ロード。ループ1段の中なので query は O(N)。
- `/ok/eager`: `OrderController.php:19` の `Order::with('customer')->get()`。`customer` が先読み済みとして変数の型に記録され、`map` のコールバック内の `$order->customer` は遅延ロード扱いにならない。query は O(1)(`with` の1本 + `get` の1本)、memory は制限なしの `get()` で O(N)。
- `/ng/layered`: Controller のコンストラクタ型宣言 → `ListOrderSummaries` → `OrderSummaryService` → `OrderRepository`(インターフェース)と追い、`bootstrap/providers.php` に並ぶ `LayeredServiceProvider.php:16` の `bind` で `EloquentOrderRepository` に解決。最奥 `EloquentOrderRepository.php:13` の `Order::all()` のループ内、`:15` の `$order->customer` を検出した。evidence に束縛の行も含む。

## 3. 仕組み(プロトタイプの範囲)

1. `route-list.json` から、URI のアクション(`Class@method` または invokable)を引く。
2. コントローラーのメソッドを AST にし、メソッド本体を上から順に歩く。変数の型は `Type`(モデル / クエリビルダー / コレクション / 注入されたオブジェクトの4種だけ)で持つ。
3. 検出するもの: `Model::get()` / `all()` / `find()` 系 / `count()` 系(クエリ)、`with()`(先読み)、`foreach` と Collection の `each` / `map` / `filter` など(ループ)、`$model->relation`(遅延ロード)。ループは「DB から取った行のコレクション」を回すものだけを数える。
4. `app/` 内のメソッド呼び出しは、コンストラクタの型宣言から相手のクラスを決めてたどる。インターフェースは ServiceProvider の `bind` / `singleton` / `scoped`(`X::class` 同士のもの)から実装を引く。実装が複数あれば全候補を解析して最悪ケースを採る(コードはあるが、複数束縛での動作は未検証)。
5. メソッドごとの結果(要約)を `クラス::メソッド` をキーに1度だけ計算して使い回す。再帰は検出して打ち切る。安全上限は 20 段(到達の実証はしていない)。
6. 連想配列・glob の順序は固定していて、同じ入力に同じ結果を返す(試験で2回実行・別インスタンスの一致を確認)。

## 4. 追えなかったもの・実際に試して分かったこと

3ルート以外について、全24ルートをプロトタイプに一度だけ通して挙動を見た。**これは参考であり、プロトタイプは3ルート向けにしか調整していない**(確認用のスクリプトはコミットしていない)。比較は query と memory のみ。

### 4.1 追えず、そう表示できたもの(正しい失敗)

追えない箇所を gap として記録し、確度を下げ、判定を `unknown` にした。仕様の「推測で埋めない」に沿う。

| ルート | 追えなかった理由 |
| --- | --- |
| `/ok/paginate`・`/ok/chunk`・`/ok/lazy`・`/hidden/cursor` | `paginate()` / `chunk()` / `lazy()` / `cursor()` を未実装(未着手)。gap → unknown。 |
| `/ok/with-count` | `withCount()` / `withSum()` を未実装。 |
| `/ok/bulk` | `pluck()` / `insert()` を未実装。 |
| `/hidden/conditional-binding` | 束縛が `bind(OrderSummaries::class, self::byEnvironment(...))`(first-class callable)で、`X::class` 同士ではないため読めない。「束縛が見つからない」として gap → unknown。 |
| `/unknown/dynamic` | `$this->{$method}()` を動的メソッド名として gap → unknown。期待値(unknown)と一致。 |

### 4.2 追えなかったのに、過小な判定を出したもの

gap は記録したが、そこまでに見えた分だけで判定を出したため、**実際より軽い結果**になった。確度は medium。

| ルート | 判定(期待) | 追えなかった理由 |
| --- | --- | --- |
| `/ng/count-in-loop` | query O(1)(O(N)) | ループ内の `$order->items()->count()`。モデルに対するメソッド呼び出し(リレーションのビルダー)を未実装。 |
| `/ng/save-in-loop` | query O(1)(O(N)) | `$customer->save()` を未実装。 |
| `/ng/first-or-create-in-loop` | query O(1)(O(N)) | `firstOrCreate()` / `updateOrCreate()` を未実装。 |

### 4.3 追えなかったのに、gap も出さず、確度 high で誤ったもの(最重要)

| ルート | 判定(期待) | 確度 | 原因 |
| --- | --- | --- | --- |
| `/hidden/blade` | query O(1)(O(N)) | high | `view('...', [...])` の呼び出しを素通りした。Blade の中は読んでいない。 |
| `/hidden/resource` | query O(1)(O(N)) | high | `OrderResource::collection(...)` を素通りした(モデル以外の静的呼び出し)。リソースの中は読んでいない。 |

| ルート | 判定(期待) | 確度 | 原因 |
| --- | --- | --- | --- |
| `/hidden/appends` | O(1) / O(1)(O(N) / O(N)) | low | `$appends` のアクセサを呼び出しグラフにつないでいない。イベントが何も見つからなかったので O(1) を返した。 |
| `/hidden/model-with` | O(1) / O(1)(O(1) / O(N)) | low | モデルの `$with` を読んでいない。同上。 |
| `/hidden/cache` | O(1) / O(1)(query は null、memory O(N)) | low | `Cache::remember(..., fn)` の Facade 呼び出しとクロージャ本体を評価していない。同上。 |

原因は同じで、「呼び出しを解決できなかった」ことを数えていない。ゆえに、何も見つからない場合と、見つけようとして追えなかった場合が区別できない。後者のほうが危険。

### 4.4 3ルート以外で、偶然ではなく仕組みが効いて一致したもの

`/ng/nested`・`/ng/unbounded`・`/ng/get-then-count`・`/ng/collection-filter`・`/ng/service` は、調整なしで期待値(query・memory・N)と一致した。ループ2段(`$order->items` → `$item->product`)は O(N*M) と判定できた。

### 4.5 試していないもの(未着手 / 未検証)

- `$bindings` プロパティによる束縛、`singleton` / `scoped` の束縛(コードは `bind` と同じ扱いにしているが、試験していない)。
- 実装が複数ある束縛での最悪ケース採用(コードはあるが、複数束縛のフィクスチャも試験もない)。
- `for` / `while`(出現したら gap を出す実装だが、試していない)。
- ミドルウェア、モデルのスコープ、Facade、クロージャ束縛の本体。
- 呼び出し深さの安全上限 20 段への到達と、再帰の打ち切り(コードはあるが、到達する例を作っていない)。
- 性能(フィクスチャが小さく、計測していない)。
- 旧形式の `config/app.php` の providers 配列(読むのは `bootstrap/providers.php` だけ)。

## 5. フェーズ1の本実装に向けた提案と懸念

1. **「追えなかった」を数える(最優先)。** 解決できなかった呼び出し(関数呼び出し・`new`・モデル以外の静的呼び出し・未知のメソッド)は、すべて gap として記録する。gap が1つでもあれば、確度は high にしない。さらに、イベントが何も見つからず gap もある、あるいは gap が判定に効いている場合は、O(1) と出さず `unknown`(または「少なくとも」の下限)にする。§4.3 の high の誤りは、この仕組みがないために起きた。
2. **確度の意味を仕様で決める。** プロトタイプでは「gap なし = high、gap あり = medium、イベントなし = low」としたが、§4.2 のとおり medium でも判定が実際より軽い。確度と「下限であること」は別の情報として出したほうがよい。
3. **コントローラーの外の入口を、呼び出しグラフにつなぐ層が要る。** Blade(`view()` に渡す配列から、テンプレート変数の型を引く)・`JsonResource`・アクセサ(`$appends`)・`$with` は、いずれも「モデルや戻り値がどこへ渡るか」を追う層が必要。今のプロトタイプは `$this->x->y()` のようなメソッド呼び出しの連鎖しか持たない。
4. **メソッド要約の使い回しは、キーに引数の型を入れる必要がある。** 今は `クラス::メソッド` だけをキーにしている。呼び出し側が先読み済みのモデルを引数で渡すと、要約の結果が変わりうる。深くしても計算量が爆発しない利点は確認できていない(フィクスチャが小さい)ので、要約の粒度は本実装で測って決める。
5. **型推論の限界。** 変数の型は、直前の代入と宣言型からしか決めていない。分岐(`if` / 三項)で型が変わる場合、ループ内で代入した型をループの外で使う場合は伝播させていない。配列に詰め替えてから回す(`pluck()` した結果を `foreach` するなど)と、N の出どころを失う。ループは「DB 行のコレクション」だけを数えているため、配列のループは数えない。
6. **ServiceProvider の読み方。** `bootstrap/providers.php` に並ぶ provider だけを読む。束縛の第2引数がクロージャや first-class callable(`/hidden/conditional-binding`)のときは、中の `$app->make(X::class)` を全候補として拾い、最悪ケースを採る設計が自然。環境による切り替え(`environment('production')`)の判定は、条件を評価せず両方を候補にする。
7. **クラスの解決は php-parser の `NameResolver` に任せられた。** `use` や別名の扱いを自前で書かずに済んだ。ただし、トレイトからのメソッドや、親クラスがベンダー側にあるメソッドは今は見つけられない(`app/` 内の親だけを遡る)。
8. **疑問として残るもの。** 解析対象を `app/` と PSR-4 の対応表(対象アプリの `composer.json`)で決めている。これは対象アプリのオートロードを動かさない読み取りだが、PSR-4 以外(classmap など)のアプリでは使えない。

## 6. 再現手順

```bash
cd docker
PHP_VERSION=8.3 docker compose -p <name> run --rm --no-deps php-dev vendor/bin/phpunit --colors=never --testdox
PHP_VERSION=8.2 docker compose -p <name> run --rm --no-deps php-dev vendor/bin/phpunit --colors=never --testdox
docker compose -p <name> down -v
```

`vendor/` が無ければ先に `docker compose -p <name> run --rm --no-deps php-dev composer install` を行う。PHP 8.2 から対象アプリの artisan を呼ぶ経路は作っていない(JSON は PHP 8.3 で書き出して commit 済み)。
