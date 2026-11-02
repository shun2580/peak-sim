# 静的解析の読み残しの修正(static-widening)

調査日: 2026-11-01

実アプリ試運転(`docs/research/real-app-trial.md`)で見つかった読み残しのうち、3 つを直した後の段ごとの測定をまとめる文書である。節の構成は、目的と読み方(§0)・方法(§1)・段ごとの結果(§2)・判定の抜き取り確認(§3)・A1 の内訳(§4)・A2 で見つけた同じ経路の既知メソッド(§5)・A3 の要旨と効果(§6)・提案(§7)・試験ログ(§8)である。試験ログ(§8)だけは、この文書の commit の後に別の commit で追加する。

生の出力(JSON・表・差分表)と、対象アプリの clone は、このリポジトリの外に置いた。ここに残すのは集計である。

## 0. 目的と読み方

### 0.1 目的

実アプリで「言い切れるルート」の割合は低い(2,091 ルート中 5 本、0.2%)。その原因の一部は、ツールの誤読と読み残しである。次の 3 つを直し、1 つずつ入れて測り、何本が動いたかを見る。

| 段 | 内容 | 性格 | commit |
| --- | --- | --- | --- |
| A2 | モデルのインスタンスの `newQuery()` を、新しい Eloquent ビルダーとして読む | 誤読の修正(D36 と同じ扱い) | `54b64b6` |
| A1 | ServiceProvider の `bind`・`singleton`・`scoped` に渡した、クロージャ 1 つだけの登録を、クロージャの戻り値の型で読む | 誤読の修正(D36 と同じ扱い) | `e22d293` |
| A3 | 受け手が enum と静的に分かる `->value`・`->name` を `gap` にしない(D45) | **判定を緩める変更**(D39 の実施) | `98f089f` |

### 0.2 読み方の注意

1. **判定を緩めたのは A3 の enum だけである。** A1・A2 は誤読の修正で、判定を軽くする方向の変更ではない。A3 の例外も、受け手が enum と静的に分かるものに限る(D45)。検証用アプリの 24 ルートの期待値(`fixtures/expected.json`)は変えていない。
2. **`unknown` は「安全」ではなく「分からない」である。** `unknown` のルートが減らなくても、それはツールが追えていないという表明であり、軽いという意味ではない。逆に `gap` の件数が減ったことは、判定が良くなったことと同じではない(A3 は `gap` を約 900 件減らしたが、判定は 1 本も動かしていない。§6)。
3. **`gap` が増えることは、悪化ではない。** 読めなかった所が読めるようになると、その先が歩かれ、先にある追えない呼び出しが別の `gap` として現れる(A1 の firefly で +8,255 件。§4)。「`UNRESOLVABLE_BINDING` が消えた」を「軽くなった」と読んではならない。
4. **「見逃し 0」が言えるのは、確定したルートの範囲だけである。** 確定に動いたルートをコードで追って確かめた(§3)。確定しなかったルートについては、見逃しの有無を言っていない。
5. **段の順は A0(案内文の例の変更。hint)→ A2 → A1 → A3 である。** 3 つの修正を並行して開発したため、履歴(commit)の順がこうなった。測定も、履歴の順に、前の段を含めた状態で行った。A2 と A1 は、この 5 アプリでは効く先が重ならなかった(A2 は BookStack と Snipe-IT、A1 は Firefly III だけに効き、A1 の段で BookStack・Koel・Laravel.io・Snipe-IT の出力は A2 の段と 1 バイトも違わない)。したがって、この 2 段は順を入れ替えても、各アプリの結果は同じになるはずである(測っていないので推測)。A0 は判定に触れないので、測定の対象にしていない。
6. **測定は 1 台の開発機の Docker 上で行った。** 時間・メモリは参考値である。

## 1. 方法

- 対象は `real-app-trial.md` §1 の 5 本で、解析したコミットも同じである(BookStack `045c6a9`・Firefly III `d65e916`・Koel `da0e70f`・Laravel.io `55be0d8`・Snipe-IT `e6dded3`。clone は変更していない)。ルート数の合計は 2,091 で、`kind` が `controller` のものを数える。
- 基準は、メモリ不足の案内(D41)などを入れた後の出力(以下 `after4`。schema 0.2)である。比べる相手は、段ごとに「その前の段の出力」とした(A2 は `after4`、A1 は A2 の出力、A3 は A1 の出力)。`after4` との差も取ったので、累積の差は §2.5 に示す。
- 段ごとに、その段の最後のコード commit を `git archive` でリポジトリの外へ展開し、作業木の他の変更が混ざらないようにして測った。展開先の一覧が `git ls-tree` と一致することを確かめた。`vendor` は、このツール本体の `vendor` を読み取り専用で重ねた。
- 対象アプリは、空の DB に `php artisan migrate` を済ませてから、artisan 方式で解析した。解析の前後で、対象アプリの木が汚れていないこと(`git status --porcelain` が空)を確かめた。Snipe-IT だけは、migrate で `ide-helper` が 10 ファイルを書き換えるため、その差分をパッチとして保存し、解析の前に厳密に元へ戻した。
- 同じ入力を 2 回解析し、出力が 1 バイトも違わないことを確かめた(決定性)。5 回測定はしていない(時間・メモリの数字は 1 回の値)。
- 差分表は、リポジトリの外の道具 `diff_runs.py` で作った。表の項目は、(a) 判定(growth・確度・下限・degree)が動いたルート、(c) `unknown` から確定に動いた軸、(b) `gap` の増減(コード別)、(d) degree の 0→null の異常、である。下限なしの `unknown` の degree の 0 と null の違い(schema 0.2 の仕様)は、動きに数えていない。
- 確定に動いたルートは、D33 に従ってコードで追った(§3)。

## 2. 段ごとの結果

### 2.1 全体

| 段(commit) | 確定したルート / 2,091 | 判定が動いたルート | `unknown` から確定に動いた軸 | `gap` 総数(5 アプリの合計) |
| --- | ---: | ---: | ---: | ---: |
| 基準 `after4` | 5 | - | - | 269,390 |
| A2(`54b64b6`) | 5 | 2 | 0 | 269,410(+20) |
| A1(`e22d293`) | 6 | 80 | 2(1 ルート) | 277,665(+8,255) |
| A3(`98f089f`) | 6 | 0 | 0 | 276,733(−932) |

- 確定したルートは、`after4` の 5 本(Firefly III の 2 本と Koel の 3 本。いずれも O(1))が、A1 の後に 6 本になった(Firefly III の O(N*M)・確度 high の 1 本が増えた)。割合は 0.24% から 0.29% で、ほとんど動いていない。
- 「判定が動いたルート」「`gap` 総数」は、その段の 1 つ前の出力との差である。「確定したルート」は、query・memory のどちらかの軸が `unknown` でないルートを数えた(5 アプリとも、2 軸の確定・未確定は一致していた)。

### 2.2 A2: `newQuery()`(`54b64b6`)

`after4` との差。

| アプリ | 共通ルート | 判定が動いたルート | `unknown`→確定 | `gap` 総数 | 差 | 主な変化(コード別) |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| BookStack | 341 | 2 | 0 | 40,431 → 40,469 | +38 | `UNKNOWN_BUILDER_METHOD` −29、`VENDOR_CALL_NOT_MODELLED` +62、`NEW_UNKNOWN_CLASS` +2、`UNRESOLVED_CALL` +2、`LOOP_SOURCE_UNKNOWN` +1 |
| Firefly III | 641 | 0 | 0 | 41,923 | 0 | なし(出力が 1 バイトも違わない) |
| Koel | 260 | 0 | 0 | 4,164 | 0 | なし(同上) |
| Laravel.io | 97 | 0 | 0 | 2,488 | 0 | なし(同上) |
| Snipe-IT | 752 | 0 | 0 | 180,384 → 180,366 | −18 | `UNKNOWN_BUILDER_METHOD` −18(`Accessory`・`Component`・`Consumable` の `newQuery()` が各 6) |

- BookStack で動いた 2 ルートは、どちらも、下限が付いただけである(`unknown` のまま)。`GET,HEAD /api/content-permissions/{contentType}/{contentId}` の memory 軸と、`POST /settings/maintenance/regenerate-references` の query 軸が、下限なしから `≥O(N)`(degree 1)になった。`newQuery()` を既知にしたことで、これまで偽の `gap` で止まっていた先が歩かれ、下限が見えたと読む。重い側への動きで、軽くなった軸はない。
- BookStack の `gap` が +38 になった理由は、同じく、止まっていた先が歩かれたことである。`UNKNOWN_BUILDER_METHOD` は減ったが、その先の `VENDOR_CALL_NOT_MODELLED` などが増えた。
- Laravel.io は、該当する `newQuery()` が無く、出力が `after4` と一致した。
- 範囲:`Model::newQuery()` のみ。他のメソッドは従来どおり `gap` のままである(§5 に、同じ経路で落ちている既知メソッドを提案として挙げる)。受け手の型が分からないとき、モデルが自前の `newQuery` を定義しているときも従来どおりで、後者はエンジンに任せる。標準の Eloquent ビルダーとして扱うので、カスタムビルダー(`#[UseEloquentBuilder]` など)を持つモデルでは、カスタムビルダーのメソッドが `UNKNOWN_BUILDER_METHOD` になる(重い側の誤り。`Model::query()` と同じ扱いで、新しい種類の誤りではない)。

### 2.3 A1: クロージャの戻り値の型(`e22d293`)

A2 の出力との差。BookStack・Koel・Laravel.io・Snipe-IT は、判定・`gap` とも差が 0 である(A2 の出力と 1 バイトも違わない)。Firefly III だけが動いた。

| 項目 | 値 |
| --- | --- |
| 共通ルート | 641 |
| 判定が動いたルート | 80(軸の変化 115) |
| `unknown`→確定に動いた軸 | 2(1 ルート:`GET,HEAD /transactions/debug/{transactionGroup}`。query・memory とも O(N*M)・確度 high) |
| 下限が付いた・上がった軸 | 113(query 40・memory 73)。内訳は、下限なし→`≥O(N)` が 90、下限なし→`≥O(N*M)` が 5、`≥O(N)`→`≥O(N*M)` が 18 |
| 軽い側への動き(下限が下がる・確度が下がる・`unknown` へ戻る) | 0 |
| `gap` 総数 | 41,923 → 50,178(**+8,255**) |
| degree の 0→null の異常 | 0 |

`gap` のコード別(Firefly III、A2 → A1):

| gap コード | 件数 before | 件数 after | 差 | ルート数 before | ルート数 after |
| --- | ---: | ---: | ---: | ---: | ---: |
| `UNRESOLVABLE_BINDING` | 5,872 | 0 | **−5,872** | 603 | 0 |
| `VENDOR_CALL_NOT_MODELLED` | 17,747 | 28,289 | +10,542 | 591 | 623 |
| `UNRESOLVED_CALL` | 12,391 | 14,560 | +2,169 | 463 | 519 |
| `VENDOR_PARENT` | 2,066 | 2,573 | +507 | 425 | 467 |
| `METHOD_NOT_FOUND` | 24 | 452 | +428 | 14 | 126 |
| `NEW_UNKNOWN_CLASS` | 1,057 | 1,196 | +139 | 309 | 328 |
| `LOOP_SOURCE_UNKNOWN` | 1,262 | 1,393 | +131 | 325 | 353 |
| `MODEL_OBSERVER_NOT_ANALYZED` | 2 | 116 | +114 | 2 | 57 |
| `MODEL_INFO_UNAVAILABLE` | 5 | 50 | +45 | 5 | 35 |
| `DYNAMIC_METHOD_NAME` | 23 | 49 | +26 | 23 | 37 |
| `UNKNOWN_BUILDER_METHOD`(新規) | 0 | 12 | +12 | 0 | 6 |
| `LOOP_BOUND_UNKNOWN` | 35 | 45 | +10 | 32 | 36 |
| `RECURSION`(新規) | 0 | 3 | +3 | 0 | 3 |
| `UNKNOWN_COLLECTION_METHOD`(新規) | 0 | 1 | +1 | 0 | 1 |
| 合計 | 41,923 | 50,178 | +8,255 | | |

注:前後で件数の変わらないコード(`BLADE_UNSUPPORTED` 1,435 件・`TRAIT_CONFLICT` 2 件・`VIEW_NOT_FOUND` 2 件、計 1,439 件)は表から省いた。合計行はこの 1,439 件を含む(表の行の和は before 40,484・after 48,739 で、どちらも 1,439 件少ない)。差の列は、省いた行の差が 0 なので、行の和と合計が一致する。

- `UNRESOLVABLE_BINDING` の 603 ルートは、1 件でも抽象が読めない登録があると、そのアプリの全ての抽象の解決を止める作りのため、まとめて止まっていたものである。止めていた Firefly III の登録 36 件が全て解けて、この全体停止が外れた。
- 外れた先では、これまで隠れていたリポジトリ層・サービス層の中が歩かれ、その中の追えない呼び出しが各ルートの `gap` として現れた。+8,255 件はそれである(§4.3)。
- 新しく出た `gap` のコード(`UNKNOWN_BUILDER_METHOD`・`RECURSION`・`UNKNOWN_COLLECTION_METHOD`)は件数が少ない。中身は 1 件ずつ見ていない。
- 参考(1 回の測定):Firefly III の解析時間は A2 の 14.287 秒から 17.965 秒に、`memory_get_peak_usage(true)` は 373,968,896 バイトから 497,553,408 バイト(約 +33%)になった。歩く範囲が広がった分と読めるが、因果は確かめていない。

### 2.4 A3: enum の `->value`・`->name`(`98f089f`)

A1 の出力との差。**判定が動いたルートは 5 アプリとも 0、`unknown`→確定に動いた軸も 0 である。** 変わったのは `gap` の件数だけである。

| アプリ | 共通ルート | 判定が動いたルート | `unknown`→確定 | `gap` 総数 | 差 | コード別 |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| BookStack | 341 | 0 | 0 | 40,469 | 0 | なし(出力が 1 バイトも違わない) |
| Firefly III | 641 | 0 | 0 | 50,178 → 49,398 | −780 | `UNRESOLVED_CALL` −779(519 → 516 ルート)、`LOOP_SOURCE_UNKNOWN` −1 |
| Koel | 260 | 0 | 0 | 4,164 → 4,150 | −14 | `UNRESOLVED_CALL` −14(163 ルートのまま) |
| Laravel.io | 97 | 0 | 0 | 2,488 → 2,486 | −2 | `UNRESOLVED_CALL` −2(18 ルートのまま) |
| Snipe-IT | 752 | 0 | 0 | 180,366 → 180,230 | −136 | `UNRESOLVED_CALL` −136(485 → 484 ルート) |
| 合計 | 2,091 | 0 | 0 | 277,665 → 276,733 | −932 | |

- 減った `gap` は全て、enum の読みに由来する `UNRESOLVED_CALL`(「`property value is read on a value that is not an object`」「`the type of property …::$value is not declared`」)である。増えた `gap` は 0 件。
- 減った 14 件の Koel の内訳は、受け手が `Class::CASE` の形のもの 7 件(`DirectoryScanner.php` の 43・44・50・51 行の `->name`、`ScanRecorder.php` の 42〜44 行の `->value`)と、受け手の宣言された型が enum のもの 7 件(`Role::$value` 5 件、`LibraryStatisticUnit::$value` 1 件、`ScanResultType::$value` 1 件)である。影響を受けたルートは 7 本で、どれも判定は動かなかった(元から `unknown` か、他の `gap` で止まっている)。
- BookStack は、enum の読みを含む出力行数が `after4`・A1・A3 のいずれも 1,215 で、A3 の影響を受けなかった。BookStack に例外が効くルートが無いのか、効いても判定に届かないのかは、切り分けていない。

### 2.5 累積(`after4` との差)

| アプリ | 判定が動いたルート | `unknown`→確定の軸 | `gap` 総数(`after4` → A3) | 差 |
| --- | ---: | ---: | --- | ---: |
| BookStack | 2 | 0 | 40,431 → 40,469 | +38 |
| Firefly III | 80 | 2 | 41,923 → 49,398 | +7,475 |
| Koel | 0 | 0 | 4,164 → 4,150 | −14 |
| Laravel.io | 0 | 0 | 2,488 → 2,486 | −2 |
| Snipe-IT | 0 | 0 | 180,384 → 180,230 | −154 |
| 合計 | 82 | 2 | 269,390 → 276,733 | +7,343 |

`UNRESOLVABLE_BINDING` を持つルートは、5 アプリで 1,209(Firefly III 603・Koel 3・Snipe-IT 603)から 606(Koel 3・Snipe-IT 603)になった。`gap` の件数は 14,110(Firefly III 5,872・Koel 8・Snipe-IT 8,230)から 8,238 になった(§4)。

## 3. 判定の抜き取り確認(D33)

`unknown` から確定に動いたルートは、30 本以下なら全数を、コードを追って確かめる。見逃しが 1 件でも出たら、その段の変更を止める。

| 段 | `unknown`→確定に動いたルート | 追ったルート | 見逃し | 過検知 |
| --- | ---: | ---: | ---: | ---: |
| A2 | 0 | 0 | 0 | 0 |
| A1 | 1 | 1(全数) | 0 | 0 |
| A3 | 0 | 0 | 0 | 0 |

A2 と A3 は、確定に動いたルートが無かったので、抜き取りの対象が無い。これは「正しいことを確かめた」ではなく「確かめる対象が無かった」である。A1 の 1 本だけが、確かめた対象のすべてである。

### 3.1 A1 で確定に動いた 1 本

- ルート:`GET,HEAD /transactions/debug/{transactionGroup}`(Firefly III、`Transaction\ShowController@debugShow`)。query・memory とも `unknown` から O(N*M)・確度 high になった。`gap` は 0 件。`n_table` は `transaction_journals`、`m_table` は `transactions`。
- 束縛の根拠:`app/Providers/JournalServiceProvider.php` の `TransactionGroupRepositoryInterface` を返すクロージャの登録(81 行付近)。戻り値の型で束縛先が決まる。A1 の変更がこれを解いた。実装は `app/Repositories/TransactionGroup/TransactionGroupRepository.php` の 65 行で、このインターフェースを実装している。
- コードの追跡:`debugShow` は、JSON を返すだけで、`expandGroup`(`TransactionGroupRepository.php` の 84 行)を呼ぶ。`expandGroup` は取引グループの仕訳を `foreach` で回して `expandJournal` を呼び(N)、`expandJournal`(394 行付近)は仕訳ごとに関連(メタ・タグ・カテゴリ・予算・メモ・添付・貯金箱イベント)を遅延読込し、さらに仕訳の取引を `foreach` で回して `expandTransaction`(416 行付近)を呼ぶ(M)。`expandTransaction` は取引ごとに口座などを遅延読込する。クエリは、仕訳の数 × (約 8 + 仕訳あたりの取引数 × 3 以上)で増える。メモリも、各層が結果を保持するので、同じ積で増える。
- 判定:**正しい確定である**(見逃しなし・過検知なし)。N=1 つの取引グループの仕訳数、M=1 つの仕訳の取引数で、静的解析の `n_table`・`m_table`・`m_per` と一致する。実際の N・M は通常小さい(分割取引)が、ここでの O(N*M) は「データ件数に対する増え方」の意味である。確度 high で `gap` が 0 であることも、ルートモデル束縛とミドルウェアは `analysis_scope.not_covered` で宣言済みの範囲外なので、妥当と読む。

### 3.2 判定を軽くした動きの有無

A1 で判定が動いた 80 ルートの、軸ごとの動きを分類した(§2.3)。軽い側への動き(下限が下がる・確度が下がる・`unknown` へ戻る)は 0 で、下限が付いた・上がった軸が 113、`unknown` から確定に動いた軸が 2 である。A2 の BookStack の 2 軸も、重い側への動きである。A3 は判定を 1 本も動かしていない。

## 4. A1 の内訳

### 4.1 基準の数字

`UNRESOLVABLE_BINDING` を持つルートの数は、基準によって 2 通りあるので、書き分ける。

- 1,093 ルートは、FIX2 の前の出力(`after3`)の数である(Firefly III 603・Koel 3・Snipe-IT 487)。
- 1,209 ルートは、FIX2 の後の出力(`after4`)の数である(Firefly III 603・Koel 3・Snipe-IT 603)。FIX2 で共通レイアウトが歩かれ、Snipe-IT の該当ルートが 487 から 603 に増えた。

どちらも正しく、基準が違うだけである。A1 の効果は `after4`(1,209)を基準に測る。

### 4.2 解けた数と残った数

| 単位 | A1 の前 | A1 の後 |
| --- | ---: | ---: |
| `UNRESOLVABLE_BINDING` を持つルート(5 アプリ) | 1,209 | 606 |
| `UNRESOLVABLE_BINDING` の `gap` の件数 | 14,110 | 8,238 |
| 読めない登録(抽象が読めない・本体が追えない) | 45 | 9 |

登録単位の内訳(A1 の前後で、束縛の一覧を出して数えた):

| アプリ | 読めない登録 前 | 後 | 備考 |
| --- | ---: | ---: | --- |
| Firefly III | 36 | 0 | 全て「抽象が定数でない」形(クロージャ 1 つだけの `bind`・`singleton`)。35 が `bind`、1 が `singleton`。読める登録は 25 から 61 になった(+36) |
| Snipe-IT | 5 | 5 | 変化なし(下記) |
| Koel | 4 | 4 | 変化なし(下記) |
| BookStack・Laravel.io | 0 | 0 | 元から出ていない(下記) |

- Firefly III は 36 登録が全て解けて、603 ルートの `UNRESOLVABLE_BINDING`(5,872 件)が 0 になった。
- Laravel.io は、`routes/bindings.php` の `Route::bind` 7 件(ルートパラメータの解決)だけで、コンテナの `bind`・`singleton` のクロージャは無い。A1 の対象外で、効果は 0 だった。
- BookStack は、束縛を 1 件も読めていない(読めた登録が 0 件)。`UNRESOLVABLE_BINDING` が 0 なのは「全て解けた」ではなく「読めていない」である(A1-F2。§7)。

### 4.3 残った理由

残った 9 登録の理由は次のとおり(ツールが出す理由の文言に沿って分類した)。

| アプリ | 登録(場所) | 理由 | 影響 |
| --- | --- | --- | --- |
| Snipe-IT | `SettingsServiceProvider.php` の 57 行・82 行 | `foreach` の中で `app()->singleton($key, fn …)` と書かれ、抽象が変数(`$key`)で、定数でない | **603 ルートの全体停止の原因**(1 件でも抽象が読めない登録があると、全抽象の解決が止まる作りのため)。A1-F1(§7) |
| Snipe-IT | 87 行・90 行 | 本体が文字列を返す(抽象は `users_upload_url`・`eula_pdf_path`) | その抽象だけが読めない |
| Snipe-IT | 94 行 | 本体がメソッド呼び出し(抽象は `maintenances_path`) | その抽象だけが読めない |
| Koel | `AppServiceProvider.php` の 67 行(`SpotifyWebAPI\Session`)、`ObjectStorageServiceProvider.php` の 13 行(`Aws\S3\S3ClientInterface`) | 本体が定数の形の式(ConstFetch)で、実装のクラスに辿れない | その抽象だけが読めない |
| Koel | `SongStorageServiceProvider.php` の 23 行 | `make()` に渡すクラスが定数でない(設定値でクラスが決まる) | その抽象だけが読めない |
| Koel | `AppServiceProvider.php` の 117 行 | 変数名(`$path`)の文脈束縛で、値がメソッド呼び出し | その束縛だけが読めない |

読めないものは、D21 に従い `gap` のままにした。設定値や条件で実装が変わるもの、本体が文字列や定数のものは、静的に実装を言えないからである。

### 4.4 「戻り値の型は実装でなく抽象」

当初の想定は「クロージャの戻り値の型宣言を、実装のクラスの手掛かりとして読む」というものだった。実装は、Laravel の実際の動作に合わせて、戻り値の型を**抽象**として、クロージャ自身を**実装**として束縛する。Laravel は、`bind` にクロージャ 1 つだけが渡されたとき、そのクロージャの戻り値の型に含まれる各クラス名を抽象として、クロージャを束縛する(コンテナの `bindBasedOnClosureReturnTypes`)。向きが当初の想定と逆であるが、「束縛が読めるようにする」という意図には沿う。

- 型が無い、組み込み型(`object`・`mixed`・`string`)、`self`・`static`・`parent`、交差型、`Foo|string` のような混在は、抽象が分からないものとして、従来どおり `gap` にする。
- 型が名前(`?Foo`・`A|B` の全名)のとき、各名を抽象として束縛する。
- 第 2 引数のある登録、`bindIf` 系は追わない。
- クロージャの本体が読めなければ、その抽象だけを読めないものにする。
- 本体が設定値で実装を変えるものを守る試験(ミニアプリ)を足した。その形は、query・memory とも `unknown` で `UNRESOLVABLE_BINDING` のままである。

## 5. A2 で見つけた同じ経路の既知メソッド(提案)

A2 で直したのは `newQuery()` だけである。「モデルのインスタンスに、モデルが定義していないメソッドを呼ぶ」ときの経路で、Eloquent の `Model` が持つメソッドが、まだ `UNKNOWN_BUILDER_METHOD`(`… is not declared in the model and is not a known builder method`)で落ちている。

**この表は、A3 の出力から数え直した。** 5 アプリの `UNKNOWN_BUILDER_METHOD` の中で、メッセージの形から、`Illuminate\Database\Eloquent` の `Model`(とそのトレイト)に実在するメソッド名を拾い、箇所(ファイルと行)を重複なく数えた。名前の実在は、Firefly III の `vendor` のソースで確かめた。受け手が本当にモデルのインスタンスかどうかは、メッセージの形からは確かめていない。

| メソッド | 箇所 | ルート | 備考 |
| --- | ---: | ---: | --- |
| `fill` | 63 | 73 | 属性の一括設定。ミューテーターを通る場合がある |
| `setAttribute` | 17 | 35 | ミューテーターを通る場合がある |
| `getRawOriginal` | 14 | 44 | 属性の元の値を返すだけ |
| `getMorphClass` | 13 | 46 | クラス名・モーフ名を返すだけ |
| `setRelation` | 13 | 36 | 読み込み済みの関連を差し込む |
| `getAttribute` | 11 | 40 | アクセサーを通る場合がある |
| `getAttributes` | 11 | 32 | 属性の配列を返すだけ |
| `relationLoaded` | 11 | 9 | 読み込み済みかを返すだけ |
| `forceFill` | 10 | 53 | `fill` と同様 |
| `isDirty` | 8 | 12 | 変更の有無を返すだけ |
| `makeVisible` | 7 | 16 | 表示する属性の設定 |
| `getKey` | 7 | 15 | 主キーの値を返すだけ |
| `unsetRelations` | 5 | 13 | 読み込み済みの関連を捨てる |
| `is` | 3 | 15 | 同じモデルかの比較 |
| `unsetRelation` | 1 | 3 | 同上(1 つ) |
| `setRawAttributes` | 1 | 9 | 属性を直接置き換える |
| `getRelationValue` | 1 | 2 | 関連を取る。**未読み込みなら遅延読込(DB に触れる)** |
| `getHidden` | 1 | 1 | 隠す属性の一覧 |
| `makeHidden` | 1 | 1 | 隠す属性の設定 |
| `newInstance` | 1 | 1 | 新しいインスタンスを作る |
| 合計 | 199 | | |

- 「ルート」の列は、そのメソッドの `gap` を持つルートの本数で、アプリをまたいだ重複は除いていない。メソッド間でルートが重なるので、列の和は意味を持たない。
- 提案であり、直してはいない。入れるなら、1 つずつ D28 の作法(PHP・Laravel の仕様に根拠を置き、DB に触れず、アプリのコードを呼び返さない)で確かめる。特に、`fill`・`forceFill`・`setAttribute`・`getAttribute` は、アプリが定義したミューテーター・アクセサーを呼びうる(アプリのコードを呼び返す)ので、一律には既知にできない(推測。仕様の確認が要る)。`getRelationValue` は遅延読込でクエリを出すので、「既知」ではなく、リレーションの読みとして数える必要がある。
- 上の表に載せなかった `UNKNOWN_BUILDER_METHOD` には、アプリ側のスコープ(Snipe-IT の `pending()`・`notArchived()` など)、アプリが定義したトレイトのメソッド(Snipe-IT の `getErrors` 132 箇所など)、モデルの関連メソッド(`hasMany`・`belongsTo` など)がある。これらは別の扱いが要る。

## 6. A3: D45 の要旨と効果

### 6.1 D45 の要旨

プロパティ読みの受け手が、次のどちらかで enum と静的に分かるとき、`->name`(backed enum・pure enum とも)と `->value`(backed enum のみ)を、値として通し、`gap` にしない。

- `Foo::BAR->value`・`self::BAR->name` の形で、`Foo` がアプリの enum、`BAR` がその enum の宣言された `case` である。
- 受け手の宣言された型(引数・プロパティ・戻り値。`?Foo` を含む)が、アプリの enum である。

根拠は、PHP のマニュアルの「Enumerations」である。全ての case は read-only の `name` を持ち(Basic enumerations)、backed の case は read-only の `value` を持つ(Backed enumerations)。DB に触れず、アプリのコードを呼び返さない。「ゲッターを呼ばない」という文言がマニュアルにあるわけではなく、read-only の property と書かれていることから読んだ(D45 にもそう書いてある)。

個別の処理にして、例外表(`resources/known-calls.php`)には載せていない。例外表は呼び出しの名前だけで引くが、この例外は「受け手の型が enum と静的に分かること」を要するからである(D35 の前例と同じ選び方)。

次は従来どおり `gap` のままである:型が分からない受け手(型宣言の無い変数・プロパティ、`mixed`、型の取れない呼び出しの結果)、enum でないクラスの `->value`、case でない定数の読み、pure enum の `->value`、結果への更なる読み(`$e->value->x`)、enum のメソッド呼び出し(`$e->label()`)。

### 6.2 効果

- **`gap` は約 900 件減った(5 アプリで −932 件)が、判定は 1 本も動かなかった。** enum の読みが `gap` になっていたルートは、他の `gap` でも `unknown` だったか、元から確定していたかのどちらかで、この例外だけで確定には届かなかった。
- 減った件数は、アプリで偏る:Firefly III −780、Snipe-IT −136、Koel −14、Laravel.io −2、BookStack 0。
- 試運転で見積もった「確実な形」の 568 件(Firefly III 423・Snipe-IT 136・Koel 7・Laravel.io 2・BookStack 0。`real-app-trial.md` §3.6)と比べると、Snipe-IT(136)と Laravel.io(2)は一致した。Koel は 14 件で、見積もり(7)に型が宣言された分(7)が加わった。Firefly III は 780 件で、見積もり(423)と一致しないが、基準が違う(A1 で歩く範囲が広がった後の出力で数えている)ので、単純には比べない。
- **変数に代入した enum は通らない。** 受け手の型が宣言で取れないものは、従来どおり `gap` のままである。たとえば Snipe-IT では、「`property value is read on a value that is not an object`」が 160 件から 24 件になり、`->name` の同種の `gap`(20 件)は変わらなかった。Koel でも、同じ形の `gap` が 61 件から 54 件になっただけで、残った。Laravel.io では、同じ形の `gap` が 4 件から 2 件になり、残る 2 件は Blade のテンプレート(`notification_settings.blade.php` の 26・27 行)の読みである。

## 7. 提案

判定を軽くする方向を含むものは、実装していない(D21)。

| 番号 | 提案 | 件数の根拠 | 備考 |
| --- | --- | --- | --- |
| A1-F1 | 「全体停止」の粒度を細かくする:抽象が変数の `singleton` でも、その値が「クラスでない文字列のキー」(リテラルの配列のキー)と分かれば、他の抽象の解決を止めない | Snipe-IT の 603 ルート(`SettingsServiceProvider.php` の 2 件が原因。§4.3) | 今の作りは D21 の安全側(抽象が不明なら、どの抽象かもしれない)で正しい。緩めるのは判定を軽くする変更なので、別の段で、D33 の抜き取りを付けて入れる。ただし、止まりが外れた先で別の `gap` が現れるので、確定に動く本数は測ってみないと分からない(Firefly III では 603 ルートの全体停止が外れて確定に動いたのは 1 本だった) |
| A1-F2 | BookStack のプロバイダの一覧を読む:プロバイダの一覧が `app/Config/app.php` にあるが、ツールは `config/app.php`・`bootstrap/providers.php`・`bootstrap/app.php` しか見ない。コンフィグのパスを変えるアプリ(`bootstrap` の `useConfigPath` など)を読むには、その指定を追う必要がある | BookStack:読めた登録 0 件。束縛が解けないインターフェースは `NO_BINDING`(11 ルート・12 件)に見える | 誤読(読み残し)の修正で、D36 と同じ型と読む。効果は測るまで分からない |
| A2 | `Model` の同じ経路のメソッドを既知にする(§5) | 199 箇所 | 1 つずつ D28 で確かめる。ミューテーター・アクセサーを通りうるもの(`fill`・`setAttribute`・`getAttribute` など)と、遅延読込のもの(`getRelationValue`)は別に考える |
| A3 | 変数に代入した enum の `->value`・`->name`(型が宣言で取れない受け手)を、どう扱うか | Snipe-IT で `->value` 24 件・`->name` 20 件、Koel で 54 件が残る(Koel の 54 件は「not an object」の `gap` の全体で、enum でない読みも混じる) | 受け手の型を、代入元(`Foo::from(…)`・`Foo::BAR` など)からたどれば、一部は enum と言える。型が取れないものを通すと、enum でない値(モデルの列の `->name` など)まで通すので、やらない |

## 8. 試験ログ

コードの最後の commit `66a8dda` で走らせた結果(実行日 2026-11-02)。走らせる直前と直後に作業木が clean であることを確かめた。走らせている間に `8c2ef79`(`docs/research/runtime-poc.md` だけの変更)が加わったが、コードは `66a8dda` と同じ。すべて SKIP 0・失敗 0・警告 0・risky 0(`failOnSkipped`・`failOnWarning`・`failOnRisky`・`failOnIncomplete` を有効にした設定で走らせた)。

| 組 | PHP | tests | assertions | SKIP | 結果 |
| --- | --- | --- | --- | --- | --- |
| Unit・Acceptance(`phpunit.xml`) | 8.2 | 2460 | 8122 | 0 | OK |
| Unit・Acceptance(`phpunit.xml`) | 8.3 | 2460 | 8122 | 0 | OK |
| RuntimeUnit(`poc/runtime/phpunit.runtime.xml`、Docker 不要) | 8.2 | 1121 | 3196 | 0 | OK |
| RuntimeUnit(同上) | 8.3 | 1121 | 3196 | 0 | OK |
| RuntimeDocker 段1(封じた構成の外から。漏れる構成と `bin/measure` を含む) | 8.3 | 20 | 87 | 0 | OK |
| RuntimeDocker 段2(封じた構成の中で `in-stack` の群) | 8.3 | 73 | 402 | 0 | OK |

- RuntimeDocker は Docker の MySQL を要するので、通常の組とは別の組として走らせた(通常の組の SKIP 0 に影響しない)。
- PHP 8.2 の RuntimeDocker は走らせていない。検証用アプリの要件(PHP 8.3)による。
- 参考(前回の記録 `phase1-test-php8*.log` は 2404 tests・7992 assertions):通常の組は 56 tests・130 assertions 増えている。
- 生のログは、環境情報(絶対パス・ユーザー名・ホスト名・コンテナ ID)を含むので、リポジトリには置かない。
