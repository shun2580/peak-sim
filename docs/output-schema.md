# JSON 出力のスキーマ(下書き)

Nov 1, 2026

`peak-sim analyze --format=json` が出す JSON の形を定める。**下書き**であり、殿の確認で確定する(章 11 に確認してほしい点を並べた)。実装は `src/Output/JsonRenderer.php`、CLI の表は `src/Output/TableRenderer.php`。

## 1. 目的と状態

- 目的:ルートごとの判定(クエリ・メモリの増え方、確度、追えなかった部分)を、機械が読める形で出す。後から MCP モードや CI(`--fail-on`)が同じ形を読む(D2)。
- 状態:**下書き**。フィールド名・列挙値・入れ子の形は、殿の確認が済むまで変わりうる。
- 読み方の注意(重要):
  - この JSON の `unknown` は、**静的解析が「追えなかった」と言っている**ことである。`fixtures/` の実測で、増え方を測った結果が基準(傾き 0.2〜0.5)の間に落ちて「判定不能」とした扱いとは**別物**。後者はツールの出力に現れない(静的には「確定」か「unknown」しか無い)。
  - `unknown` を「安全」や `O(1)` と読んではならない(D21)。`unknown` には必ずそれを説明する gap がある。
  - 決定性:同じ入力から同じバイト列が出る(章 9)。時刻・ホスト名・絶対パスは出さない。

## 2. 互換方針

- `schema_version` は文字列。現在は `"0.1"`。
- 0.x の間は、予告なしに壊れる変更がありうる。1.0 以降は、キーの削除・意味の変更は major を上げ、キーの追加と列挙値の追加は minor で行う。
- 読み手は、知らないキーと知らない列挙値を無視する(gap と assumption のコードは今後増える)。

## 3. トップレベル

キーはこの順で出る。

| キー | 型 | 内容 |
| --- | --- | --- |
| `schema_version` | string | 章 2。 |
| `tool` | object | `{"name": <ツール名>}`。名前は未決で、`PeakSim\Name::TOOL_NAME` の1か所から出る。 |
| `input` | object | `{"mode": "export-dir" または "artisan-command" または null}`。ルートとモデルの情報をどう読んだか。呼び出し側が言わなければ `null`。**入力方式によって変わるのはこの1つだけ**(ほかは同じバイト列になる)。 |
| `analysis_scope` | object | `{"not_covered": [string, ...], ...}`。ツールが解析の対象外と宣言しているもの(ミドルウェア・認可・ルートモデル束縛など)。**宣言した対象外は「安全」の意味ではない**。`not_covered` は昇順で、無くても空配列で出る。ほかのキーはキーの昇順。 |
| `summary` | object | 章 3.1。 |
| `skipped` | array | 解析の対象にしなかったルート(クロージャ・フレームワーク)。章 3.2。 |
| `routes` | array | 章 4。`route`(URI)の昇順。 |

### 3.1 summary

| キー | 型 | 内容 |
| --- | --- | --- |
| `routes` | int | 解析したルートの数(`kind` が `controller` のもの)。 |
| `by_confidence` | object | `{"high": n, "medium": n, "none": n}`。`none` はルートの確度が `null` のもの(両軸 unknown)。 |
| `by_growth` | object | `{"query": {...}, "memory": {...}}`。それぞれ `{"O(1)": n, "O(N)": n, "O(N*M)": n, "unknown": n}`。4つのキーは常に出る。 |
| `gap_reasons` | object | gap コード → それを持つルートの数(1ルートに同じコードが複数あっても1と数える)。コードの昇順。無ければ `{}`。 |
| `skipped` | int | 対象外の数。`skipped` 配列の件数に、`routes` のうち `kind` が `controller` でないものの件数を足したもの。 |

### 3.2 skipped の要素

`{"route": string, "methods": [string, ...], "action": string, "kind": string}`。`route`・`methods`・`action` の昇順で並ぶ。

## 4. ルート(`routes[]` の要素)

キーはこの順で出る。

| キー | 型 | 内容 |
| --- | --- | --- |
| `route` | string | URI。先頭にスラッシュが付く(`/ng/n-plus-one`)。 |
| `methods` | array of string | HTTP メソッド(昇順)。 |
| `action` | string | `Class@method`、`Class`(`__invoke`)、`Closure`。 |
| `kind` | string | 章 8。 |
| `query` | object | クエリ数の判定(章 5)。 |
| `memory` | object | メモリの判定(章 5)。 |
| `n_table` | string または null | N に当たるテーブル。追えなかったルートでは `null`。 |
| `m_table` | string または null | M に当たるテーブル(`O(N*M)` のとき)。 |
| `m_per` | string または null | M が「どのテーブルの1行あたり」か(`items` per `orders`)。 |
| `n_table_alternatives` | array of string | N の決め方で `n_table` と同点になった別のテーブル(同じ次数・軸・種類)。名前の昇順で、無ければ空配列。 |
| `confidence` | string または null | ルートの確度。2つの軸のうち**低い方**。両軸が unknown なら `null`(Q3)。 |
| `cache` | object または null | `Cache::remember` があるルートだけ(章 6.1)。 |
| `worst_case` | array または null | 候補が複数あって最悪を採ったとき(章 6.2)。 |
| `coefficients` | object | 補足の係数。**表示するだけで、判定には使わない**(章 6.3)。 |
| `gaps` | array | 追えなかった部分(章 7.1)。 |
| `assumptions` | array | 置いた仮定(章 7.2)。 |
| `evidence` | array | 判定の根拠の場所(章 7.3)。 |
| `events_found` | int | 見つかったコスト源の数。0 で gap が無ければ「何も無かった」、0 で gap があれば「追えなかった」。 |
| `truncated` | bool | 呼び出しの段数上限で打ち切ったか(`DEPTH_LIMIT`)。 |

## 5. 軸(`query` / `memory`)

| キー | 型 | 内容 |
| --- | --- | --- |
| `growth` | string | `O(1)` / `O(N)` / `O(N*M)` / `unknown`。 |
| `degree` | int | 増え方の次数。0 = `O(1)`、1 = `O(N)`、2 以上 = `O(N*M)`。3 段以上の入れ子もラベルは `O(N*M)` で、次数だけが 3 以上になる(Q4)。`unknown` のときは意味を持たず 0。 |
| `confidence` | string または null | `high` / `medium`。`unknown` のときは `null`(`low` は使わない)。 |
| `lower_bound` | string または null | `growth` が `unknown` のとき、**見つかった事実だけから言える下限**(`O(N)` など)。下限が無ければ `null`。gap の位置の周りから推測では足さない。`unknown` 以外では `null`。 |

`growth` が `unknown` になるのは、その軸に効く gap があるときだけ。既知の下限が `O(N)` でも、`O(N)` と確定せず `unknown` と出して `lower_bound` を添える(Q1 の読み)。

## 6. cache・worst_case・coefficients

### 6.1 cache

| キー | 型 | 内容 |
| --- | --- | --- |
| `sites` | array of site | `Cache::remember` の場所。 |
| `on_miss` | object | `{"query": 軸, "memory": 軸}`。ミスしたときの判定。 |
| `on_hit` | object | `{"query": 軸, "memory": 軸}`。ヒットしたときの判定。 |
| `restores_all_on_hit` | bool | ヒット時に、テーブルの件数に比例する値を復元するか。 |
| `adopted_view` | string | ルートの判定をどちらのビューから採ったか。常に `"miss"`。 |

ルートの `query` / `memory` は、ミスとヒットの**悪い方**。2つが同じ悪さのときは、確度も含めてミス側のものを採る。

### 6.2 worst_case

要素は `{"site": site, "abstract": string, "candidates": [string, ...], "chosen": string}`。`abstract` は束縛先が複数ある抽象(インターフェース)、`candidates` は候補の実装、`chosen` は最悪として採った実装。環境によって実装が切り替わるなど、どれが使われるか静的に決められないときに出る。

### 6.3 coefficients

キーは常にこの3つ。出せなかったものは `null`。**判定の一致には使わない**。

| キー | 型 | 内容 |
| --- | --- | --- |
| `query_coefficient` | string または null | `chunk` / `lazy` の `"1/<サイズ>"`(定数に解けたときだけ)。 |
| `per_item_queries` | int または null | `firstOrCreate` / `updateOrCreate` の1件あたりの文数(条件つき)。 |
| `extra_queries_per_request` | int または null | 件数によらず毎回足される文の数(`$with` の先読みなど。次数 0 の文の数 − 1)。 |

## 7. gap・assumption・evidence

### 7.1 gaps の要素

キー:`code`・`site`・`message`・`affects`・`loop_depth`・`scenario`。

| キー | 型 | 内容 |
| --- | --- | --- |
| `code` | string | 章 8 の gap コード。 |
| `site` | site | 追えなくなった場所。 |
| `message` | string | 理由。 |
| `affects` | array of string | 効く軸(`query` / `memory`)。 |
| `loop_depth` | int | その位置を囲むループの段数(参考表示)。 |
| `scenario` | array | キャッシュのどちら側の話か。`[{"cache": <番号>, "side": "miss" または "hit"}, ...]`。キャッシュと無関係なら空配列。 |

gap は、`affects` に書かれた軸を `unknown` にする。同じ場所・コード・メッセージの gap は1つにまとめ、場所・コード・メッセージの順で並べる。

### 7.2 assumptions の要素

キー:`code`・`site`・`message`・`affects`・`scenario`・`candidates`・`chosen`。`candidates`(文字列の配列)と `chosen`(文字列)は、候補から選んだ仮定(`WORST_CASE_OF_CANDIDATES`)のときだけ値を持ち、ほかは `null`。仮定が効く軸(`affects`)の確度は `medium` になる。並びは gap と同じ規則。

### 7.3 evidence の要素

キー:`role`・`site`・`axis`・`note`。判定の根拠となる場所を、**見つけた順**に重複なく並べる(並べ替えない)。

| キー | 型 | 内容 |
| --- | --- | --- |
| `role` | string | 章 8 の evidence の役割。 |
| `site` | site | 場所。 |
| `axis` | string または null | 効いた軸。両方に効くなら `null`。 |
| `note` | string | 短い説明(コスト源の種類・囲むループの数など)。 |

### 7.4 site

`{"file": string, "line": int, "note": string}`。`file` は**アプリのルートからの相対パス**(絶対パスは決して出ない。出そうとすると出力側が拒否する)。Blade の場合は、変換後ではなくテンプレートの行番号。

## 8. 列挙値の表

### growth

| 値 | 意味 |
| --- | --- |
| `O(1)` | 件数に依らず一定。 |
| `O(N)` | N(テーブルの件数)に比例。 |
| `O(N*M)` | N と M の積(2 段以上の入れ子)。次数は `degree`。 |
| `unknown` | 追えなかった(`lower_bound` に下限があれば添える)。 |

### confidence

| 値 | 意味 |
| --- | --- |
| `high` | 仮定なしで判定した。 |
| `medium` | 仮定(`assumptions`)を置いた軸。 |
| `null` | 判定が `unknown`(軸)、または両軸が `unknown`(ルート)。 |

### kind

| 値 | 意味 |
| --- | --- |
| `controller` | アプリのコントローラー。解析の対象。 |
| `closure` | クロージャのルート。対象外。 |
| `framework` | フレームワーク側のルート。対象外。 |

### input.mode

| 値 | 意味 |
| --- | --- |
| `export-dir` | 事前に書き出した `route-list.json` と `model-show/*.json` を読んだ。 |
| `artisan-command` | `artisan` を実行して読んだ。 |

### evidence の役割(`role`)

| 値 | 意味 |
| --- | --- |
| `call` | 解析で入った呼び出し。 |
| `binding` | 束縛の候補を選んだ場所。 |
| `event` | コストの源(クエリ・メモリ)。 |
| `gap` | 追えなかった場所。 |
| `assumption` | 仮定を置いた場所。 |

### gap コード

`src/Model/GapCode.php` の全ケース。

| 値 | 意味 |
| --- | --- |
| `UNRESOLVED_CALL` | 解決できなかった関数・`new`・静的呼び出し |
| `VENDOR_CALL_NOT_MODELLED` | 許可表(`resources/known-calls.php`)に無いベンダーの呼び出し |
| `VENDOR_PARENT` | メソッドが見つからず、祖先がベンダーのクラス(ベンダー由来の振る舞いかもしれない) |
| `METHOD_NOT_FOUND` | メソッドが見つからない(祖先はすべてアプリ内) |
| `TRAIT_CONFLICT` | トレイトの衝突が複雑で、どれが使われるか決められない |
| `DYNAMIC_METHOD_NAME` | `$this->{$name}()` のように、メソッド名が変数 |
| `DYNAMIC_CLASS_NAME` | `new $class` のように、クラス名が変数 |
| `NEW_UNKNOWN_CLASS` | アプリにも許可表にも無いクラスの `new` |
| `UNKNOWN_BUILDER_METHOD` | ビルダー・モデルに無いメソッド(マクロの可能性) |
| `UNKNOWN_COLLECTION_METHOD` | コレクションに無いメソッド(マクロの可能性) |
| `LOOP_SOURCE_UNKNOWN` | `foreach` の対象の大きさが分からず、本体にクエリがある |
| `LOOP_BOUND_UNKNOWN` | `for` / `while` / `do-while`(本体にイベントがある) |
| `GENERATOR_NOT_ANALYZED` | `yield` を含むメソッド(本体が遅延して動く) |
| `NO_BINDING` | アプリ内のインターフェース・抽象クラスに束縛が無い |
| `UNRESOLVABLE_BINDING` | 束縛の実装を読み取れない(動的な束縛など) |
| `BINDING_DECORATED` | `extend()` などで束縛が装飾されている |
| `DEPTH_LIMIT` | 呼び出しの段数が上限(既定 20)を超えた。`truncated` が true になる |
| `RECURSION` | 再帰(直接・相互) |
| `BUDGET_EXCEEDED` | 解析の計算量の予算を超えた |
| `UNION_TOO_LARGE` | 分岐で型の候補が多すぎる(上限 8) |
| `MODEL_INFO_UNAVAILABLE` | `model:show` の結果が得られない |
| `MODEL_OBSERVER_NOT_ANALYZED` | オブザーバ・モデルイベントを持つモデルへの書き込み(中身は解析しない) |
| `RELATION_TARGET_UNKNOWN` | `morphTo` など、相手のモデルが決まらないリレーション |
| `VIEW_NOT_FOUND` | ビュー名が定数でない、またはファイルが無い |
| `BLADE_UNSUPPORTED` | 対応外の Blade 構文(独自ディレクティブ・コンポーネント・`@can` など) |
| `PARSE_FAILED` | ファイルの構文エラー |
| `ACCESSOR_NOT_ANALYZED` | アクセサの本体を解析できない |
| `RESOURCE_NOT_ANALYZED` | JsonResource を解析できない |
| `CACHE_VALUE_SIZE_UNKNOWN` | キャッシュする値の大きさが分からない(ヒット時に何を復元するか不明) |
| `CLOSURE_ROUTE` | クロージャのルート(本体は解析しない) |
| `MIDDLEWARE_APP_CLASS` | アプリ自作のミドルウェア(将来対応) |
| `WITH_CYCLE` | `$with` が循環している |
| `MODEL_PROPERTY_UNRESOLVED` | モデルの `$with` / `$appends` / `$table` が定数でなく読めなかった(`$with` はクエリを足しうる) |
### assumption コード

`src/Model/AssumptionCode.php` の全ケース。

| 値 | 意味 |
| --- | --- |
| `WORST_CASE_OF_CANDIDATES` | 候補が複数あり、結果が食い違う軸で、最悪のものを採用した |
| `BRANCH_WORST_CASE` | 分岐の合流で精度が落ち、悪い側を採用した |
| `RELATION_INFERRED` | リレーションを `model:show` ではなく継承などから推定した |
| `DRIVER_ASSUMED` | DB ドライバが読めない・未知のため、メモリ軸で仮定を置いた |
| `CACHE_DRIVER_SERIALIZING` | ヒット時に全件を復元する(シリアライズするドライバ)と仮定した。キャッシュのヒット側のメモリ軸にだけ効く |
| `SQL_NOT_PARSED` | 生の SQL は読まず、件数を無制限と仮定した |
| `TABLE_NAME_UNKNOWN` | テーブル名が分からない |
| `ACCUMULATION_HEURISTIC` | ストリーム内で配列に溜めるときの安全弁(参考値) |
| `FIND_ARGUMENT_ASSUMED_SCALAR` | `find()` の引数が単一の ID か ID の配列か決められず、単一と仮定した |
## 9. 決定性

同じアプリ・同じ入力からは、次の条件で**同じバイト列**が出る。

- キーの順は固定(章 3〜7 の表の順)。
- 順序に意味の無いリスト(`routes`・`skipped`・`gaps`・`assumptions`・`methods`・`not_covered`・`gap_reasons`)は、決まった順に並べる(URI、場所、コードなどのバイト順。数として比べない)。順序が「見つけた順」であるリスト(`evidence`・`cache.sites`・`worst_case[].candidates`)は並べ替えない。
- 浮動小数を使わない(件数・次数は整数、係数は文字列)。
- JSON は 4 スペースのインデント、スラッシュと非 ASCII 文字をエスケープしない、末尾に改行 1 つ。
- 時刻・ホスト名・ユーザー名・絶対パスを出さない。
- 空の連想配列は `{}`、空のリストは `[]` で出る(`gap_reasons` だけが空の連想配列になりうる)。

## 10. 例

実際の出力は、解析器とファサード(`bin/peak-sim`)が揃った段階で、次のコマンドの結果に**差し替える**。それまで例は載せない(手で作った例は、実装の癖を写して読み手を誤らせるため)。

```
bin/peak-sim analyze fixtures/laravel-app --export-dir fixtures/artisan-export --format=json --route=/ng/n-plus-one
bin/peak-sim analyze fixtures/laravel-app --export-dir fixtures/artisan-export --format=json --route=/hidden/cache
bin/peak-sim analyze fixtures/laravel-app --export-dir fixtures/artisan-export --format=json --route=/hidden/conditional-binding
bin/peak-sim analyze fixtures/laravel-app --export-dir fixtures/artisan-export --format=json --route=/unknown/dynamic
```

差し替え時に載せる要点:

- `/ng/n-plus-one`:`O(N)`・`O(N)`・`n_table` が `orders`・確度 `high`。
- `/hidden/cache`:`cache.on_miss.query` が `O(N)`、`on_hit.query` が `O(1)`、`restores_all_on_hit` が true。
- `/hidden/conditional-binding`:`worst_case` に2つの候補、確度 `medium`。
- `/unknown/dynamic`:両軸 `unknown`・確度 `null`・`n_table` が `null`・`events_found` が 0・gap `DYNAMIC_METHOD_NAME`。

`tests/Fixtures/Golden/report.json` は、整形の確認のために**手で組んだ**架空のレポートの期待出力であり、実際の解析結果ではない。例として読んではならない。

## 付録 A. CLI の表(`--format=table`)

列は `ROUTE | QUERY | MEMORY | N(M) | 確度 | 下限 | GAP`。1ルート1行で、URI の昇順。`kind` が `controller` のルートだけが行になる。

- `QUERY` / `MEMORY`:`O(N)`、`O(N*M)`。3 段以上は `O(N*M)×3`。`unknown` は、下限があれば `unknown (≥O(N))`。
- キャッシュ付きは、ミスとヒットが違えば `O(N) miss / O(1) hit`、`QUERY` の末尾に `[cache]`。
- 最悪ケースを採ったルートは、`QUERY` の末尾に `[worst-case of 2]`(候補の数)。
- `N(M)`:`orders`、M があれば `orders (M: items per orders)`。追えなかったときは `—`。
- `確度`:`high` / `medium`、両軸 `unknown` なら `—`。
- `下限`:`unknown` の軸の下限(`query ≥O(N), memory ≥O(N*M)`)。無ければ `—`。
- `GAP`:そのルートの gap コード(重複なし・昇順、`, ` 区切り)。無ければ `—`。
- 末尾に3行の要約:解析したルート数と対象外の数、確度別の件数、gap の理由ごとのルート数。

## 11. 殿に確認してほしい点

1. **Q3 ルートの確度の集約**:ルートの `confidence` を「2軸のうち低い方」とし、両軸 unknown なら `null`(表では `—`)とした。D21 は「各判定に確度」までしか決めていない。
2. **Q4 3 段以上の入れ子の表記**:ラベルは `O(N*M)` のままにし、`degree` を整数で別に出す(表は `O(N*M)×3`)。仕様のラベルが3つだけなので、4つ目を足す案もある。
3. **gap・assumption のコード名**:章 8 の名前(`GapCode`・`AssumptionCode`)は初期集合で、公開後は読み手が頼る。名前を変えるなら、いまのうちに。
4. **`analysis_scope` の中身**:`not_covered` に何を宣言するか(ミドルウェア・認可・ルートモデル束縛・FormRequest・オブザーバ以外のグローバルスコープなど)は、解析器側の決めで、この文書は形だけを定める。「対象外と宣言する」ことが「安全」と読まれないための文言も要る。
5. **`input.mode` を JSON に入れるか**:設計は「入力方式が違っても出力は同一」と言うが、`input.mode` は方式ごとに変わる。いまは `input.mode` だけが違う(ほかは同一)形にした。完全に同一にしたければ、`input` を削るか `--stats` 側(標準エラー)へ移す。
6. **`summary` の数え方**:`routes` は `kind` が `controller` のルートだけ、`skipped` は skipped 配列と `controller` 以外のルートの合計。`gap_reasons` は「そのコードを持つルートの数」(gap の個数ではない)。
7. **`degree` を整数で出す**:`AxisVerdict` の注記は「3 以上は `>=3` と表示」だが、JSON は整数のまま出し、表示側で `×3` にした。文字列にしてよいか。
8. **`dominant_site` を出さない**:`AxisVerdict` には支配的な場所があるが、設計書の軸のキー(4つ)に無いので出していない。`evidence` が根拠を持つ。要るなら足す。
9. **表の見出しと言語(Q11)**:列の見出し `確度`・`下限` と gap の `message` の言語。OSS 公開なら英語が望ましい。いまは見出しだけ日本語(指示どおり)で、要約行は英語。
10. **`skipped` の要素の形**:`{route, methods, action, kind}` の4つに固定した(`route` は `uri` という名前でも受ける)。理由(`reason`)を足すかは未定。
11. **`gaps[].site.note` と `evidence[].note`**:短い説明文はメッセージと同じく人が読む文で、機械判定には `code` / `role` を使うこと。
