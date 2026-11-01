# JSON 出力のスキーマ

Nov 1, 2026

`peak-sim analyze --format=json` が出す JSON の形を定める。`schema_version` `0.2` として**確定**している(0.1 は 2026-11-01 殿裁定。0.2 は D38。章 11・12)。実装は `src/Output/JsonRenderer.php`、CLI の表は `src/Output/TableRenderer.php`。

## 1. 目的と状態

- 目的:ルートごとの判定(クエリ・メモリの増え方、確度、追えなかった部分)を、機械が読める形で出す。後から MCP モードや CI(`--fail-on`)が同じ形を読む(D2)。
- 状態:**確定(`schema_version` `0.2`)**。2026-11-01 に殿が確認点(章 11)を裁いて確定し、同日の実アプリ試運転の裁定(D38)で 0.2 に改めた(章 12)。0.x の間の変更の扱いは章 2。この文書は実際の出力に合わせてあり、章 10 の例は実際のコマンドの出力である。
- 出力の言語:出力(表の見出し、gap・assumption の `message`、`note`、要約行、エラー文)はすべて**英語**で、英日併記はしない(Q11)。この文書(`docs/`)は日本語。
- 読み方の注意(重要):
  - この JSON の `unknown` は、**静的解析が「追えなかった」と言っている**ことである。`fixtures/` の実測で、増え方を測った結果が基準(傾き 0.2〜0.5)の間に落ちて「判定不能」とした扱いとは**別物**。後者はツールの出力に現れない(静的には「確定」か「unknown」しか無い)。
  - `unknown` を「安全」や `O(1)` と読んではならない(D21)。`unknown` には必ずそれを説明する gap がある。
  - 決定性:同じ入力から同じバイト列が出る(章 9)。時刻・ホスト名・絶対パスは出さない。

## 2. 互換方針

- `schema_version` は文字列。現在は `"0.2"`。
- 0.x の間は、予告なしに壊れる変更がありうる。1.0 以降は、キーの削除・意味の変更は major を上げ、キーの追加と列挙値の追加は minor で行う。
- 読み手は、知らないキーと知らない列挙値を無視する(gap と assumption のコードは今後増える)。

## 3. トップレベル

キーはこの順で出る。

| キー | 型 | 内容 |
| --- | --- | --- |
| `schema_version` | string | 章 2。 |
| `tool` | object | `{"name": <ツール名>}`。名前は未決で、`PeakSim\Name::TOOL_NAME` の1か所から出る。 |
| `input` | object | `{"mode": "export-dir" または "artisan-command" または null}`。ルートとモデルの情報をどう読んだか。呼び出し側が言わなければ `null`。**入力方式以外は同一のバイト列**になる。入力方式によって変わるのはこの `input.mode` の1つだけ。 |
| `analysis_scope` | object | `{"not_covered": [string, ...], "note": string}`。ツールが解析の対象外と宣言しているもの。**宣言した対象外は「安全」の意味ではない**。中身は章 3.3。`not_covered` は宣言した順(並べ替えない。無くても空配列で出る)。ほかのキーはキーの昇順。 |
| `summary` | object | 章 3.1。 |
| `skipped` | array | 解析の対象にしなかったルート(クロージャ・フレームワーク)。章 3.2。 |
| `routes` | array | 章 4。`route`(URI)の昇順。 |

### 3.1 summary

| キー | 型 | 内容 |
| --- | --- | --- |
| `routes` | int | 解析したルートの数(`kind` が `controller` のもの)。 |
| `by_confidence` | object | `{"high": n, "medium": n, "none": n}`。`none` はルートの確度が `null` のもの(どちらかの軸が unknown)。 |
| `by_growth` | object | `{"query": {...}, "memory": {...}}`。それぞれ `{"O(1)": n, "O(N)": n, "O(N*M)": n, "unknown": n}`。4つのキーは常に出る。 |
| `gap_reasons` | object | gap コード → それを持つルートの数(1ルートに同じコードが複数あっても1と数える)。コードの昇順。無ければ `{}`。 |
| `skipped` | int | 対象外の数。`skipped` 配列の件数に、`routes` のうち `kind` が `controller` でないものの件数を足したもの。 |

### 3.2 skipped の要素

`{"route": string, "methods": [string, ...], "action": string, "kind": string}`。`route`・`methods`・`action` の昇順で並ぶ。

### 3.3 analysis_scope

ツールが解析の対象外と宣言している処理。**宣言は「安全」の意味ではない**。ここに挙げた処理のコストは結果に含まれないので、これらに依存するルートの判定は、その分だけ小さく出うる。

| キー | 型 | 内容 |
| --- | --- | --- |
| `not_covered` | array of string | 対象外の7項目。下表の順で出る(宣言した順。並べ替えない)。 |
| `note` | string | 殿の原文の英訳。下記。 |

`not_covered` の7項目(出力の文字列そのまま):

| 値 | 対象外の処理 |
| --- | --- |
| `application middleware` | アプリ自作のミドルウェア |
| `authorization (gates and policies)` | 認可(Gate / Policy) |
| `route model binding` | ルートモデル束縛 |
| `form request validation` | FormRequest の検証 |
| `global scopes` | グローバルスコープ |
| `observers and event listeners` | オブザーバとイベントリスナー |
| `jobs` | ジョブ |

`note` の文言。殿の原文(日本語):

> ここに挙げた処理のコストは結果に含まれない。安全という意味ではない。

出力に載る英訳(実装の定数 `AnalysisRunner::SCOPE_NOTE`):

> The cost of the processing listed here is not included in the results. This does not mean the route is safe.

(D30。対象外の宣言を「安全」と読ませないため、D21 と同じ向きにそろえた。ルートモデル束縛などの定数コストをフェーズ3の前にどう数えるかは D31。)

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
| `confidence` | string または null | ルートの確度。2つの軸のうち**低い方**。**どちらかの軸が `unknown` なら `null`**(Q3。片方の軸だけ分かっていても、その確度をルート全体の確度にしない)。表では `—`。 |
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
| `degree` | int または null | 増え方の次数。0 = `O(1)`、1 = `O(N)`、2 以上 = `O(N*M)`。3 段以上の入れ子もラベルは `O(N*M)` で、次数だけが 3 以上になる(Q4)。`growth` が `unknown` で `lower_bound` が `null` のときは `null`(0 を出さない。0 は `O(1)` と読めるため。D38)。`unknown` で `lower_bound` があるときは、その下限の次数(`O(N)` なら 1)。 |
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

キー名について:`fixtures/expected.json` の期待値は同じものを `query_extra_per_request` と呼ぶ。出力のキーは確定した `extra_queries_per_request` のままで、期待値の側の名前は変えない(期待値の器は殿の領分。S-33)。対応づけは試験で取る。

## 7. gap・assumption・evidence

### 7.1 gaps の要素

キー:`code`・`site`・`message`・`affects`・`loop_depth`・`scenario`。

| キー | 型 | 内容 |
| --- | --- | --- |
| `code` | string | 章 8 の gap コード。 |
| `site` | site | 追えなくなった場所。 |
| `message` | string | 理由(英語)。 |
| `affects` | array of string | 効く軸(`query` / `memory`)。 |
| `loop_depth` | int | その位置を囲むループの段数(参考表示)。 |
| `scenario` | array | キャッシュのどちら側の話か。`[{"cache": <整数>, "side": "miss" または "hit"}, ...]`。`cache` は `Cache::remember` の呼び出しを区別する整数(ファイルと位置のハッシュ。同じ呼び出しには同じ値)。キャッシュと無関係なら空配列。 |

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
| `unknown` | 追えなかった(`lower_bound` に下限があれば添える。下限が無ければ `degree` も `null`)。 |

### confidence

| 値 | 意味 |
| --- | --- |
| `high` | 仮定なしで判定した。 |
| `medium` | 仮定(`assumptions`)を置いた軸。 |
| `null` | 判定が `unknown`(軸)、またはどちらかの軸が `unknown`(ルート。Q3)。 |

### kind

| 値 | 意味 |
| --- | --- |
| `controller` | アプリのコントローラー。解析の対象。 |
| `closure` | クロージャのルート。対象外。 |
| `framework` | フレームワーク側のルート。対象外。 |

### input.mode

入力方式を表す。JSON のうち、入力方式によって変わるのはこの値だけである(入力方式以外は同一のバイト列)。

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
- 順序に意味の無いリスト(`routes`・`skipped`・`gaps`・`assumptions`・`methods`・`gap_reasons`)は、決まった順に並べる(URI、場所、コードなどのバイト順。数として比べない)。順序が「見つけた順」「宣言した順」であるリスト(`evidence`・`cache.sites`・`worst_case[].candidates`・`analysis_scope.not_covered`)は並べ替えない。
- 浮動小数を使わない(件数・次数は整数、係数は文字列)。
- JSON は 4 スペースのインデント、スラッシュと非 ASCII 文字をエスケープしない、末尾に改行 1 つ。
- 時刻・ホスト名・ユーザー名・絶対パスを出さない。
- 空の連想配列は `{}`、空のリストは `[]` で出る(`gap_reasons` だけが空の連想配列になりうる)。

## 10. 例

実際の出力。`fixtures/laravel-app`(検証用アプリ)を、事前に書き出した `fixtures/artisan-export` から読んで解析した結果で、手で整えていない。コマンドは次のとおり(`--route` を付けると、`summary` はその1ルートだけの数になる)。

```
bin/peak-sim analyze fixtures/laravel-app --export-dir=fixtures/artisan-export --format=json --route=/ng/n-plus-one
bin/peak-sim analyze fixtures/laravel-app --export-dir=fixtures/artisan-export --format=json --route=/hidden/cache
bin/peak-sim analyze fixtures/laravel-app --export-dir=fixtures/artisan-export --format=json --route=/hidden/conditional-binding
bin/peak-sim analyze fixtures/laravel-app --export-dir=fixtures/artisan-export --format=json --route=/unknown/dynamic
```

### 10.1 `/ng/n-plus-one`:`O(N)`・`O(N)`・確度 `high`

全体を載せる。`n_table` が `orders`、`evidence` に N+1 の根拠(`lazy_load customers, 1 loop`)がある。

```json
{
    "schema_version": "0.2",
    "tool": {
        "name": "peak-sim"
    },
    "input": {
        "mode": "export-dir"
    },
    "analysis_scope": {
        "not_covered": [
            "application middleware",
            "authorization (gates and policies)",
            "route model binding",
            "form request validation",
            "global scopes",
            "observers and event listeners",
            "jobs"
        ],
        "note": "The cost of the processing listed here is not included in the results. This does not mean the route is safe."
    },
    "summary": {
        "routes": 1,
        "by_confidence": {
            "high": 1,
            "medium": 0,
            "none": 0
        },
        "by_growth": {
            "query": {
                "O(1)": 0,
                "O(N)": 1,
                "O(N*M)": 0,
                "unknown": 0
            },
            "memory": {
                "O(1)": 0,
                "O(N)": 1,
                "O(N*M)": 0,
                "unknown": 0
            }
        },
        "gap_reasons": {},
        "skipped": 0
    },
    "skipped": [],
    "routes": [
        {
            "route": "/ng/n-plus-one",
            "methods": [
                "GET",
                "HEAD"
            ],
            "action": "App\\Http\\Controllers\\Ng\\BasicNPlusOneController",
            "kind": "controller",
            "query": {
                "growth": "O(N)",
                "degree": 1,
                "confidence": "high",
                "lower_bound": null
            },
            "memory": {
                "growth": "O(N)",
                "degree": 1,
                "confidence": "high",
                "lower_bound": null
            },
            "n_table": "orders",
            "m_table": null,
            "m_per": null,
            "n_table_alternatives": [],
            "confidence": "high",
            "cache": null,
            "worst_case": null,
            "coefficients": {
                "query_coefficient": null,
                "per_item_queries": null,
                "extra_queries_per_request": null
            },
            "gaps": [],
            "assumptions": [],
            "evidence": [
                {
                    "role": "call",
                    "site": {
                        "file": "app/Http/Controllers/Ng/BasicNPlusOneController.php",
                        "line": 11,
                        "note": "App\\Http\\Controllers\\Ng\\BasicNPlusOneController::__invoke()"
                    },
                    "axis": null,
                    "note": ""
                },
                {
                    "role": "event",
                    "site": {
                        "file": "app/Http/Controllers/Ng/BasicNPlusOneController.php",
                        "line": 13,
                        "note": ""
                    },
                    "axis": "memory",
                    "note": "select orders"
                },
                {
                    "role": "event",
                    "site": {
                        "file": "app/Http/Controllers/Ng/BasicNPlusOneController.php",
                        "line": 17,
                        "note": ""
                    },
                    "axis": "query",
                    "note": "lazy_load customers, 1 loop"
                }
            ],
            "events_found": 2,
            "truncated": false
        }
    ]
}
```

以降の3例は、`schema_version`・`tool`・`input`・`analysis_scope` が上と同じなので、`summary` と `routes` だけを載せる。

### 10.2 `/hidden/cache`:キャッシュのミスとヒット

`cache.on_miss.query` が `O(N)`、`on_hit.query` が `O(1)`、`restores_all_on_hit` が true。ヒット側のメモリは `CACHE_DRIVER_SERIALIZING` の仮定を置いたので `medium`。ルートの `query` / `memory` はミスとヒットの悪い方で、同じ悪さならミス側の確度を採る(章 6.1)。

```json
    "summary": {
        "routes": 1,
        "by_confidence": {
            "high": 1,
            "medium": 0,
            "none": 0
        },
        "by_growth": {
            "query": {
                "O(1)": 0,
                "O(N)": 1,
                "O(N*M)": 0,
                "unknown": 0
            },
            "memory": {
                "O(1)": 0,
                "O(N)": 1,
                "O(N*M)": 0,
                "unknown": 0
            }
        },
        "gap_reasons": {},
        "skipped": 0
    },
    "skipped": [],
    "routes": [
        {
            "route": "/hidden/cache",
            "methods": [
                "GET",
                "HEAD"
            ],
            "action": "App\\Http\\Controllers\\Hidden\\CacheController@index",
            "kind": "controller",
            "query": {
                "growth": "O(N)",
                "degree": 1,
                "confidence": "high",
                "lower_bound": null
            },
            "memory": {
                "growth": "O(N)",
                "degree": 1,
                "confidence": "high",
                "lower_bound": null
            },
            "n_table": "orders",
            "m_table": null,
            "m_per": null,
            "n_table_alternatives": [],
            "confidence": "high",
            "cache": {
                "sites": [
                    {
                        "file": "app/Http/Controllers/Hidden/CacheController.php",
                        "line": 17,
                        "note": ""
                    }
                ],
                "on_miss": {
                    "query": {
                        "growth": "O(N)",
                        "degree": 1,
                        "confidence": "high",
                        "lower_bound": null
                    },
                    "memory": {
                        "growth": "O(N)",
                        "degree": 1,
                        "confidence": "high",
                        "lower_bound": null
                    }
                },
                "on_hit": {
                    "query": {
                        "growth": "O(1)",
                        "degree": 0,
                        "confidence": "high",
                        "lower_bound": null
                    },
                    "memory": {
                        "growth": "O(N)",
                        "degree": 1,
                        "confidence": "medium",
                        "lower_bound": null
                    }
                },
                "restores_all_on_hit": true,
                "adopted_view": "miss"
            },
            "worst_case": null,
            "coefficients": {
                "query_coefficient": null,
                "per_item_queries": null,
                "extra_queries_per_request": null
            },
            "gaps": [],
            "assumptions": [
                {
                    "code": "CACHE_DRIVER_SERIALIZING",
                    "site": {
                        "file": "app/Http/Controllers/Hidden/CacheController.php",
                        "line": 17,
                        "note": ""
                    },
                    "message": "a hit restores the cached list from the store; its memory grows with it only if the cache driver serialises the value (it does for file, database and redis)",
                    "affects": [
                        "memory"
                    ],
                    "scenario": [
                        {
                            "cache": 252446270,
                            "side": "hit"
                        }
                    ],
                    "candidates": null,
                    "chosen": null
                }
            ],
            "evidence": [
                {
                    "role": "call",
                    "site": {
                        "file": "app/Http/Controllers/Hidden/CacheController.php",
                        "line": 14,
                        "note": "App\\Http\\Controllers\\Hidden\\CacheController::index()"
                    },
                    "axis": null,
                    "note": ""
                },
                {
                    "role": "event",
                    "site": {
                        "file": "app/Http/Controllers/Hidden/CacheController.php",
                        "line": 17,
                        "note": ""
                    },
                    "axis": "memory",
                    "note": "select orders"
                },
                {
                    "role": "event",
                    "site": {
                        "file": "app/Http/Controllers/Hidden/CacheController.php",
                        "line": 22,
                        "note": ""
                    },
                    "axis": "query",
                    "note": "lazy_load customers, 1 loop"
                },
                {
                    "role": "event",
                    "site": {
                        "file": "app/Http/Controllers/Hidden/CacheController.php",
                        "line": 17,
                        "note": ""
                    },
                    "axis": "memory",
                    "note": "cache_restore orders"
                },
                {
                    "role": "assumption",
                    "site": {
                        "file": "app/Http/Controllers/Hidden/CacheController.php",
                        "line": 17,
                        "note": ""
                    },
                    "axis": "memory",
                    "note": "CACHE_DRIVER_SERIALIZING: a hit restores the cached list from the store; its memory grows with it only if the cache driver serialises the value (it does for file, database and redis)"
                }
            ],
            "events_found": 3,
            "truncated": false
        }
    ]
```

### 10.3 `/hidden/conditional-binding`:最悪ケースの採用

`worst_case` に候補が2つ、`assumptions` に `WORST_CASE_OF_CANDIDATES`。`query` の確度が `medium` なので、ルートの `confidence` も `medium`。`coefficients.extra_queries_per_request` は 2。

```json
    "summary": {
        "routes": 1,
        "by_confidence": {
            "high": 0,
            "medium": 1,
            "none": 0
        },
        "by_growth": {
            "query": {
                "O(1)": 0,
                "O(N)": 1,
                "O(N*M)": 0,
                "unknown": 0
            },
            "memory": {
                "O(1)": 0,
                "O(N)": 1,
                "O(N*M)": 0,
                "unknown": 0
            }
        },
        "gap_reasons": {},
        "skipped": 0
    },
    "skipped": [],
    "routes": [
        {
            "route": "/hidden/conditional-binding",
            "methods": [
                "GET",
                "HEAD"
            ],
            "action": "App\\Http\\Controllers\\Hidden\\ConditionalBindingController@index",
            "kind": "controller",
            "query": {
                "growth": "O(N)",
                "degree": 1,
                "confidence": "medium",
                "lower_bound": null
            },
            "memory": {
                "growth": "O(N)",
                "degree": 1,
                "confidence": "high",
                "lower_bound": null
            },
            "n_table": "orders",
            "m_table": null,
            "m_per": null,
            "n_table_alternatives": [],
            "confidence": "medium",
            "cache": null,
            "worst_case": [
                {
                    "site": {
                        "file": "app/Http/Controllers/Hidden/ConditionalBindingController.php",
                        "line": 13,
                        "note": ""
                    },
                    "abstract": "App\\Hidden\\Contracts\\OrderSummaries",
                    "candidates": [
                        "App\\Hidden\\Services\\EagerOrderSummaries",
                        "App\\Hidden\\Services\\LazyOrderSummaries"
                    ],
                    "chosen": "App\\Hidden\\Services\\LazyOrderSummaries"
                }
            ],
            "coefficients": {
                "query_coefficient": null,
                "per_item_queries": null,
                "extra_queries_per_request": 2
            },
            "gaps": [],
            "assumptions": [
                {
                    "code": "WORST_CASE_OF_CANDIDATES",
                    "site": {
                        "file": "app/Http/Controllers/Hidden/ConditionalBindingController.php",
                        "line": 13,
                        "note": ""
                    },
                    "message": "App\\Hidden\\Contracts\\OrderSummaries has 2 implementations that cost differently; the costliest is adopted",
                    "affects": [
                        "query"
                    ],
                    "scenario": [],
                    "candidates": [
                        "App\\Hidden\\Services\\EagerOrderSummaries",
                        "App\\Hidden\\Services\\LazyOrderSummaries"
                    ],
                    "chosen": "App\\Hidden\\Services\\LazyOrderSummaries"
                }
            ],
            "evidence": [
                {
                    "role": "call",
                    "site": {
                        "file": "app/Http/Controllers/Hidden/ConditionalBindingController.php",
                        "line": 11,
                        "note": "App\\Http\\Controllers\\Hidden\\ConditionalBindingController::index()"
                    },
                    "axis": null,
                    "note": ""
                },
                {
                    "role": "call",
                    "site": {
                        "file": "app/Hidden/Providers/ConditionalBindingServiceProvider.php",
                        "line": 25,
                        "note": "binding App\\Hidden\\Contracts\\OrderSummaries => App\\Hidden\\Services\\EagerOrderSummaries"
                    },
                    "axis": null,
                    "note": ""
                },
                {
                    "role": "call",
                    "site": {
                        "file": "app/Hidden/Providers/ConditionalBindingServiceProvider.php",
                        "line": 26,
                        "note": "binding App\\Hidden\\Contracts\\OrderSummaries => App\\Hidden\\Services\\LazyOrderSummaries"
                    },
                    "axis": null,
                    "note": ""
                },
                {
                    "role": "call",
                    "site": {
                        "file": "app/Hidden/Services/EagerOrderSummaries.php",
                        "line": 10,
                        "note": "App\\Hidden\\Services\\EagerOrderSummaries::all()"
                    },
                    "axis": null,
                    "note": ""
                },
                {
                    "role": "call",
                    "site": {
                        "file": "app/Hidden/Services/LazyOrderSummaries.php",
                        "line": 10,
                        "note": "App\\Hidden\\Services\\LazyOrderSummaries::all()"
                    },
                    "axis": null,
                    "note": ""
                },
                {
                    "role": "binding",
                    "site": {
                        "file": "app/Http/Controllers/Hidden/ConditionalBindingController.php",
                        "line": 13,
                        "note": ""
                    },
                    "axis": "query",
                    "note": "App\\Hidden\\Contracts\\OrderSummaries has 2 implementations that cost differently; the costliest is adopted"
                },
                {
                    "role": "event",
                    "site": {
                        "file": "app/Hidden/Services/EagerOrderSummaries.php",
                        "line": 12,
                        "note": ""
                    },
                    "axis": "memory",
                    "note": "select orders"
                },
                {
                    "role": "event",
                    "site": {
                        "file": "app/Hidden/Services/LazyOrderSummaries.php",
                        "line": 13,
                        "note": ""
                    },
                    "axis": "memory",
                    "note": "select orders"
                },
                {
                    "role": "event",
                    "site": {
                        "file": "app/Hidden/Services/LazyOrderSummaries.php",
                        "line": 18,
                        "note": ""
                    },
                    "axis": "query",
                    "note": "lazy_load customers, 1 loop"
                }
            ],
            "events_found": 4,
            "truncated": false
        }
    ]
```

### 10.4 `/unknown/dynamic`:追えなかったルート

両軸 `unknown`、ルートの `confidence` は `null`、`n_table` は `null`、`events_found` は 0、gap は `DYNAMIC_METHOD_NAME`。`unknown` を `O(1)` や安全と読んではならない(D21)。

```json
    "summary": {
        "routes": 1,
        "by_confidence": {
            "high": 0,
            "medium": 0,
            "none": 1
        },
        "by_growth": {
            "query": {
                "O(1)": 0,
                "O(N)": 0,
                "O(N*M)": 0,
                "unknown": 1
            },
            "memory": {
                "O(1)": 0,
                "O(N)": 0,
                "O(N*M)": 0,
                "unknown": 1
            }
        },
        "gap_reasons": {
            "DYNAMIC_METHOD_NAME": 1
        },
        "skipped": 0
    },
    "skipped": [],
    "routes": [
        {
            "route": "/unknown/dynamic",
            "methods": [
                "GET",
                "HEAD"
            ],
            "action": "App\\Http\\Controllers\\Unknown\\DynamicController",
            "kind": "controller",
            "query": {
                "growth": "unknown",
                "degree": null,
                "confidence": null,
                "lower_bound": null
            },
            "memory": {
                "growth": "unknown",
                "degree": null,
                "confidence": null,
                "lower_bound": null
            },
            "n_table": null,
            "m_table": null,
            "m_per": null,
            "n_table_alternatives": [],
            "confidence": null,
            "cache": null,
            "worst_case": null,
            "coefficients": {
                "query_coefficient": null,
                "per_item_queries": null,
                "extra_queries_per_request": null
            },
            "gaps": [
                {
                    "code": "DYNAMIC_METHOD_NAME",
                    "site": {
                        "file": "app/Http/Controllers/Unknown/DynamicController.php",
                        "line": 21,
                        "note": ""
                    },
                    "message": "the method name is not a literal",
                    "affects": [
                        "query",
                        "memory"
                    ],
                    "loop_depth": 0,
                    "scenario": []
                }
            ],
            "assumptions": [],
            "evidence": [
                {
                    "role": "call",
                    "site": {
                        "file": "app/Http/Controllers/Unknown/DynamicController.php",
                        "line": 15,
                        "note": "App\\Http\\Controllers\\Unknown\\DynamicController::__invoke()"
                    },
                    "axis": null,
                    "note": ""
                },
                {
                    "role": "gap",
                    "site": {
                        "file": "app/Http/Controllers/Unknown/DynamicController.php",
                        "line": 21,
                        "note": ""
                    },
                    "axis": null,
                    "note": "DYNAMIC_METHOD_NAME: the method name is not a literal"
                }
            ],
            "events_found": 0,
            "truncated": false
        }
    ]
```

`tests/Fixtures/Golden/report.json` は、整形の確認のために**手で組んだ**架空のレポートの期待出力であり、実際の解析結果ではない。例として読んではならない。

## 付録 A. CLI の表(`--format=table`)

出力は英語(Q11)。実際の出力(`bin/peak-sim analyze fixtures/laravel-app --export-dir=fixtures/artisan-export --format=table`):

```
ROUTE                        QUERY                         MEMORY                    N(M)                          CONFIDENCE  LOWER BOUND  GAPS
---------------------------  ----------------------------  ------------------------  ----------------------------  ----------  -----------  -------------------
/hidden/appends              O(N)                          O(N)                      orders                        high        —            —
/hidden/blade                O(N)                          O(N)                      orders                        high        —            —
/hidden/cache                O(N) miss / O(1) hit [cache]  O(N)                      orders                        high        —            —
/hidden/conditional-binding  O(N) [worst-case of 2]        O(N)                      orders                        medium      —            —
/hidden/cursor               O(1)                          O(N)                      orders                        high        —            —
/hidden/model-with           O(1)                          O(N)                      orders                        high        —            —
/hidden/resource             O(N)                          O(N)                      orders                        high        —            —
/ng/collection-filter        O(1)                          O(N)                      orders                        high        —            —
/ng/count-in-loop            O(N)                          O(N)                      orders                        high        —            —
/ng/first-or-create-in-loop  O(N)                          O(N)                      products                      high        —            —
/ng/get-then-count           O(1)                          O(N)                      orders                        high        —            —
/ng/layered                  O(N)                          O(N)                      orders                        high        —            —
/ng/n-plus-one               O(N)                          O(N)                      orders                        high        —            —
/ng/nested                   O(N*M)                        O(N*M)                    orders (M: items per orders)  high        —            —
/ng/save-in-loop             O(N)                          O(N)                      customers                     high        —            —
/ng/service                  O(N)                          O(N)                      orders                        high        —            —
/ng/unbounded                O(1)                          O(N)                      orders                        high        —            —
POST /ok/bulk                O(1)                          O(N)                      customers                     high        —            —
/ok/chunk                    O(N)                          O(1)                      orders                        high        —            —
/ok/eager                    O(1)                          O(N)                      orders                        high        —            —
/ok/lazy                     O(N)                          O(1)                      orders                        high        —            —
/ok/paginate                 O(1)                          O(1)                      orders                        high        —            —
/ok/with-count               O(1)                          O(N)                      orders                        high        —            —
/unknown/dynamic             unknown (no lower bound)      unknown (no lower bound)  —                             —           —            DYNAMIC_METHOD_NAME

peak-sim: 24 routes analysed, 2 not analysed (framework / closure)
confidence: high 22, medium 1, — 1
gaps: DYNAMIC_METHOD_NAME in 1 route
```

列は `ROUTE | QUERY | MEMORY | N(M) | CONFIDENCE | LOWER BOUND | GAPS`。1ルート1行で、URI の昇順。`kind` が `controller` のルートだけが行になる。

- `ROUTE`:URI。`GET` を含まないルートは、メソッドを前に付ける(`POST /ok/bulk`。複数なら `|` でつなぐ)。
- `QUERY` / `MEMORY`:`O(1)`、`O(N)`、`O(N*M)`。3 段以上は `O(N*M)×3`(`degree` が 3 以上のとき。Q7)。`unknown` は、下限があれば `unknown (≥O(N))`、下限が無ければ `unknown (no lower bound)`(D38。`O(1)` や次数 0 に読ませない)。
- キャッシュ付きは、ミスとヒットが違えば `O(N) miss / O(1) hit`、`QUERY` の末尾に `[cache]`。
- 最悪ケースを採ったルートは、`QUERY` の末尾に `[worst-case of 2]`(候補の数)。
- `N(M)`:`orders`、M があれば `orders (M: items per orders)`。追えなかったときは `—`。
- `CONFIDENCE`:`high` / `medium`。どちらかの軸が `unknown` なら `—`(JSON の `null`。Q3)。
- `LOWER BOUND`:`unknown` の軸の下限(`query ≥O(N), memory ≥O(N*M)`)。無ければ `—`。
- `GAPS`:そのルートの gap コード(重複なし・昇順、`, ` 区切り)。無ければ `—`。
- 末尾に3行の要約:解析したルート数と対象外の数、確度別の件数(`—` が確度 `null` の数)、gap の理由ごとのルート数。

## 11. 確認済みの点(2026-11-01 殿裁定)

下書きで殿に確認を求めた点を、2026-11-01 に殿が裁いた(`docs/decisions.md` の D30)。各項目の結論を書く。項目の番号は、下書きの番号のままにしてある(殿の裁定文の「Q3」「Q4」…と同じ番号。ただし殿の裁定文が「Q11」と呼ぶ出力の言語は、ここでは 9。ここでの 11 は `note` の項目)。`schema_version` は `0.1` のまま確定した(その後、D38 で `degree` の `null` を足して `0.2` に改めた。章 12)。

1. **Q3 ルートの確度の集約**:どちらかの軸が `unknown` なら、ルートの `confidence` は `null`(表は `—`)。片方の軸だけ分かっていても、その確度をルート全体の確度にしない(D21・D27 に合わせる)。下書きは「軸の低い方、両軸 unknown なら `null`」だったのを改めた。
2. **Q4 3 段以上の入れ子の表記**:ラベルは `O(N*M)` のまま、`degree` を整数で別に出す。4つ目のラベルは足さない。
3. **gap・assumption のコード名**:章 8 の名前は変えずに確定(公開後は読み手が頼る)。コードは今後増えうるので、読み手は知らないコードを無視する(章 2)。
4. **`analysis_scope` の中身**:`not_covered` は、アプリ自作のミドルウェア・認可(Gate / Policy)・ルートモデル束縛・FormRequest の検証・グローバルスコープ・オブザーバとイベントリスナー・ジョブの7項目(章 3.3)。「ここに挙げた処理のコストは結果に含まれない。安全という意味ではない」を `note`(英訳)として添える。
5. **`input.mode` を JSON に入れるか**:残す。「入力方式以外は同一のバイト列」の意味で、入力方式によって変わるのは `input.mode` だけ(章 3)。`input` を削る・標準エラーへ移す案は採らない。
6. **`summary` の数え方**:下書きのとおり確定。`routes` は `kind` が `controller` のルートだけ、`skipped` は `skipped` 配列と `controller` 以外のルートの合計、`gap_reasons` は「そのコードを持つルートの数」(gap の個数ではない)。
7. **`degree` を整数で出す**:JSON は整数のまま、表の表示は `O(N*M)×3`(3 以上のとき)。`AxisVerdict` の注記にあった「3 以上は `>=3` と表示」は、実際の表示 `×3` に文書を合わせた。
8. **`dominant_site` を出さない**:軸のキーは4つのまま。根拠は `evidence` が持つ。
9. **出力の言語(殿の裁定文では Q11)**:出力(表の見出し・gap と assumption の `message`・`note`・要約行・エラー文)はすべて英語。英日併記はしない。`docs/` は日本語のまま。
10. **`skipped` の要素の形**:`{route, methods, action, kind}` の4つのまま。`reason` は足さない。
11. **`gaps[].site.note` と `evidence[].note`**:人が読む文(英語)で、機械判定には `code` / `role` を使う。

## 12. 改訂履歴

- `0.1`(2026-11-01):初版。殿が確認点(章 11)を裁いて確定した。
- `0.2`(2026-11-01):`growth` が `unknown` で `lower_bound` が `null` の軸は、`degree` を `null` にする(それまでは 0)。表は `unknown (no lower bound)`。`unknown` と次数 0 が `O(1)` に読める誤読の罠をなくすため(D38)。下限があるとき、`O(1)` のとき、他のキーは変えていない。
