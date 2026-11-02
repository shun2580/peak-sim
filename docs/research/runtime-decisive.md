# 実測モードは役に立つかを白黒つける実験

作成日: 2026-11-02

問いは次の一つである。**静的解析が「少なくとも O(N)」と下限を付けたルートを、実測で本当に O(N) と測れるか。**

- 測れる → 実測は静的解析の推定を裏づけ、埋める役に立つ
- 分かっている O(N) すら測れない → 実測モードはやめ、静的解析に集中する

この文書は実験の記録で、節ごとに足していく。この版は **§T1(対象アプリとルートの選定、環境の用意、煙試験)** と **§T2(本測定)** を書く。コードで追う答え合わせ(§T3)は、後の版で足す。

**これは試作の結果であり、実測モードの本採用ではない。** 試作のコードは `poc/runtime/` のままで、`src/` には入れていない。判定の規則(log-log 傾き)も D21 も変えていない。生の出力と選定の記録は、リポジトリの外の作業置場に置いた。

## T1. 対象アプリとルートの選定

### T1.1 アプリの選定

候補は Firefly III と BookStack の2本。基準は2つで、(i) 静的解析の出力で、クエリの軸に下限(`≥O(N)` か `≥O(N*M)`)が付いたルートが多いこと、(ii) 実測の環境が作れる見込みがあること。

(i) 静的解析の出力(段階 A3 の後)で数えた。数えたのは `kind=controller` で、`query.lower_bound` が付いたルートである。

| アプリ | ルート数 | `≥O(N)` | `≥O(N*M)` | 下限つき計 | 確定(O(1)・high) | 確定(O(N*M)・high) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Firefly III | 641 | 39 | 10 | **49** | 2 | 1 |
| BookStack | 341 | 42 | 2 | 44 | 0 | 0 |

(ii) 実測の環境:

| 点 | Firefly III | BookStack |
| --- | --- | --- |
| PHP の要求 | 8.5 以上(`composer.json`) | 8.3 以上 |
| 追加の拡張 | 既定のイメージにあるもので足りる(`bcmath`・`intl` は既定で入る) | `gd` が要る(`PEAK_PHP_EXTENSIONS=gd`) |
| 静的解析の段階での migration | MySQL 8.4 で全件が通った(段階 A3 の記録) | 同じく通った |
| vendor | 済み | 済み |

**Firefly III を選ぶ。** 理由は3つある。

1. 下限つきが 49 本で、BookStack の 44 本より多い
2. `≥O(N*M)` の層が 10 本あり、BookStack の 2 本より層ごとの比較に足りる。BookStack ではこの層が 20 本の対象に届かない
3. 静的解析が確定(O(1)・high)と言ったルートが 2 本あり、対照に使える。BookStack には無い

環境は作れた(§T1.4)ので、BookStack へは替えていない。

### T1.2 対象ルートの選び方

選び方は決定的で、乱数を使わない。

1. 静的解析の出力から、`kind=controller` で `query.lower_bound` が付いたルートを取る
2. 層に分ける: `≥O(N)`(39 本)と `≥O(N*M)`(10 本)
3. 各層を (URI, メソッドの並び) の昇順に並べる
4. 層から k 本を、並びの添字 `floor((i + 0.5) × n / k)`(i = 0..k-1、n は層の本数)で取る。k が n 以上なら層の全部を取る
5. k は、`≥O(N)` 層が 15、`≥O(N*M)` 層が 10(層の全部)。合わせて **25 本**で、20 本以上の条件を満たす
6. 対照として、静的解析が O(1)・high と言ったルートを全部(2 本)足す

選定の記録(機械可読)は 作業置場の `T1_routes.json`。選ぶ手順のスクリプトは同じ場所の `select_t1.py` で、同じ入力から同じ一覧ができる。各ルートには、メソッド・URI・静的の判定・下限・層・選抜の序数(`ordinal`)を付けた。

| 層 | 本数 | 内訳 |
| --- | ---: | --- |
| `≥O(N)` | 15 | 39 本から等間隔 |
| `≥O(N*M)` | 10 | 層の全部 |
| 対照(O(1)・high) | 2 | 全部 |
| 計 | 27 | |

対象に入れたメソッドは GET・POST・PUT・DELETE を含む(HEAD は GET と同じルートなので別に数えない)。書き込み系は、試作が毎回トランザクションで巻き戻す。

注記: 静的解析が確定(O(N*M)・high)と言ったルートが 1 本ある。実験の問いは「下限つき」のルートなので、対象にも対照にも入れていない。

### T1.3 対象の一覧

序数の順に並べる。静的の判定は、全て「クエリは unknown、下限のみ」(対照を除く)である。

| 序数 | 層 | メソッド | URI | 静的が増やす表(n_table / m_table) |
| ---: | --- | --- | --- | --- |
| 1 | `≥O(N)` | GET | `/accounts/show/{account}/all` | なし |
| 2 | `≥O(N)` | GET | `/api/v1/bills/{bill}/attachments` | attachments |
| 3 | `≥O(N)` | GET | `/api/v1/categories/{category}/attachments` | attachments |
| 4 | `≥O(N)` | PUT | `/api/v1/piggy-banks/{piggyBank}` | accounts / accounts |
| 5 | `≥O(N)` | DELETE | `/api/v1/rule-groups/{ruleGroup}` | rules |
| 6 | `≥O(N)` | GET | `/api/v1/tags/{tagOrId}/attachments` | attachments |
| 7 | `≥O(N)` | POST | `/api/v1/webhooks/{webhook}/submit` | webhook_messages |
| 8 | `≥O(N)` | GET | `/budgets/show/{budget}/{budgetLimit}` | attachments |
| 9 | `≥O(N)` | POST | `/groups/destroy/{objectGroup}` | piggy_banks |
| 10 | `≥O(N)` | GET | `/piggy-banks/remove/{piggyBank}` | accounts / accounts |
| 11 | `≥O(N)` | POST | `/piggy-banks/update/{piggyBank}` | accounts / accounts |
| 12 | `≥O(N)` | GET | `/recurring/show/{recurrence}` | journal_meta |
| 13 | `≥O(N)` | GET | `/subscriptions/show/{bill?}` | transaction_journals |
| 14 | `≥O(N)` | GET | `/transactions/convert/{transactionType}/{transactionGroup}` | transactions |
| 15 | `≥O(N)` | GET | `/transactions/edit/{transactionGroup}` | transactions |
| 16 | `≥O(N*M)` | DELETE | `/api/v1/transactions/{transactionGroup}` | transaction_journals / transactions |
| 17 | `≥O(N*M)` | PUT | `/api/v1/transactions/{transactionGroup}` | transaction_journals / transactions |
| 18 | `≥O(N*M)` | GET | `/api/v1/transactions/{transactionGroup}/attachments` | transaction_journals / attachments |
| 19 | `≥O(N*M)` | GET | `/api/v1/transactions/{transactionGroup}/piggy-bank-events` | transaction_journals / piggy_bank_events |
| 20 | `≥O(N*M)` | GET | `/piggy-banks` | piggy_banks / accounts |
| 21 | `≥O(N*M)` | GET | `/piggy-banks/add-money/{piggyBank}` | accounts / piggy_banks |
| 22 | `≥O(N*M)` | GET | `/piggy-banks/add/{piggyBank}` | accounts / piggy_banks |
| 23 | `≥O(N*M)` | POST | `/piggy-banks/add/{piggyBank}` | accounts / piggy_banks |
| 24 | `≥O(N*M)` | POST | `/transactions/convert/{transactionType}/{transactionGroup}` | transaction_journals / transactions |
| 25 | `≥O(N*M)` | POST | `/transactions/destroy/{transactionGroup}` | transaction_journals / transactions |
| 26 | 対照 | GET | `/api/v1/rules/validate-expression` | なし(O(1)・high) |
| 27 | 対照 | GET | `/json/box/available` | なし(O(1)・high) |

### T1.4 実測の環境

安全の条件(コンテナの封じ・読み取り専用のマウント・計測専用 DB)は、試作の既存の仕組みをそのまま使った。対象アプリは書き換えず、パッケージも足していない。

| 項目 | 状態 |
| --- | --- |
| 実測用の PHP イメージ | `peak-measure-php-firefly:8.5` を `bin/stack build-image` で作った(PHP 8.5.12)。ビルドは1回で通った(終了コード 0)。拡張は既定のまま(追加なし) |
| ビルド時のネットワーク | イメージ作りのビルド用コンテナの中から、ベースイメージの Debian 公式ミラーへ `apt-get` した(`libzip-dev`・`libicu-dev`)。ホストでの `apt` と取得元の追加はしていない。実測の走そのものは、外へ出られない内部ネットワークの中で行った |
| 起動前の検査 | 実行ごとに通った(`PREFLIGHT OK`。外部への接続4つが失敗し、計測用 DB への接続・名前解決・ログインが成功することを確かめる) |
| 対象アプリの起動 | 通った。Laravel 13.35.0。実行時のルート表が引け、`--only` で絞ったルートだけを測れた |
| migration と合成データ | 規模 10・100・1000 で通った(`/login` の実行で、`users` 表を含む2表に届くことを確かめた) |
| 1 回の実行の時間 | ルート 1 本が約 78 秒、2 本が約 104〜105 秒(規模 3 点、環境の立ち上げと後始末を含む) |

### T1.5 煙試験

「ハンドラー(コントローラーの処理)に届くか」を、3 回の実行で調べた。規模は 10・100・1000。プロファイルは使っていない。記録は同じ置き場所の `smoke/`・`smoke2/`・`smoke3/`。

| 実行 | ルート | 応答 | クエリ | 分類 | ハンドラーに届いたか |
| --- | --- | --- | --- | --- | --- |
| smoke | GET `/transactions/edit/{transactionGroup}`(対象 15) | 401 | 9 本(`configuration` のみ) | `unauthenticated` | **届かない** |
| smoke2 | GET `/json/box/available`(対照 27) | 401 | 9 本(`configuration` のみ) | `unauthenticated` | **届かない** |
| smoke2 | GET `/api/v1/bills/{bill}/attachments`(対象 2) | 500 | 0 本 | `exception`(`LogicException: Invalid key supplied`) | **届かない** |
| smoke3 | GET・POST `/login`(対象外の確認用) | 302 | 13 本・4 本・4 本(`configuration`・`users`) | `ok` | **届く**(`users` 表に触れた) |

結果は次のとおりである。

- **環境は動いた。** 封じたコンテナで Firefly III が起動し、リクエストがアプリのコードに入り、クエリが数えられる。ログイン画面のルート(`/login`)は、アプリのコードに届き、測れた(GET・POST とも `ok`)。
- **対象のルートは、プロファイルなしでは、煙試験の時点で届かなかった。** 試した対象と対照の3本は、いずれも認証のところで止まった。

届かなかった理由は、試作の中で確かめた。

1. **web のルート(`user-full-auth` グループ)**: 試作がルートの地図に書いたミドルウェアは `['web', 'user-full-auth']` で、`user-full-auth` は Firefly III が `bootstrap/app.php` で `Authenticate` などを束ねたグループの名前である。試作は認証の要否を、ミドルウェアの名前(`auth`・`authenticate` など)から読む(設計 §5.3)。グループの名前は中身が展開されておらず、認証が要ると読めなかったので、ログインの手順が立たなかった(記録の `act_as` が空)。リクエストはログインなしで進み、`Authenticate` が 401 を返した。
2. **API のルート(`api` グループ)**: 同じく地図のミドルウェアは `['api']` で、グループの中の `auth:api` が読めなかった。`auth:api` のガードが働いた段階で `LogicException: Invalid key supplied` が出た(クエリは 0 本)。リポジトリ外の試行用コピーの `storage/` に OAuth の鍵ファイルは無い(Passport が鍵を要求するとき、鍵が無いと思われる。ここは原因の特定まではしていない)。

つまり、対象ルートの多くが **「アプリが自分で定義したミドルウェアのグループ」の中に認証を持つ作り** であり、プロファイルなしの試作はこれを認証と読めない。この件は §T2 で、プロファイルなしとありの別にどこまで届くかを測るときの、最初の論点になる。プロファイルの `user` と `login_as` は、ログインが要ると読めたルートにだけ働く(`Route\UserProvider::plan`)。そのため、最小のプロファイルで足りるかも、§T2 で確かめる必要がある。

この版では、試作のコードを変えていない。

## T1 の結論

- 対象アプリは **Firefly III**。下限つき 49 本(`≥O(N)` 39・`≥O(N*M)` 10)、対照 2 本。
- 対象は 25 本(`≥O(N)` 層 15・`≥O(N*M)` 層 10)と対照 2 本。選び方は決定的で、記録は `T1_routes.json`。
- 環境は作れた。実測用イメージ `peak-measure-php-firefly:8.5`。
- 煙試験: 環境は端から端まで動く(`/login` は届く)。対象と対照の3本は、プロファイルなしでは認証のところで止まり、ハンドラーに届かなかった。理由は、アプリが自分で定義したミドルウェアのグループを試作が展開しないこと(web 2 本)と、API の認証ガードの鍵(1 本)。

## T2. 本測定

対象 27 本(T1 の一覧)を、規模 10・100・1000 で実測した。判定の規則(log-log 傾き)も D21 も変えていない。データは合成(試作の Generator)。1 回の実行で、応答の状態・応答のバイト数・読んだ表・クエリ数を規模ごとに記録した。

### T2.1 3 つの構成

煙試験で、対象ルートが認証のところで止まった。「実測が測れなかった」のか「試作の読み残しか」を分けるため、同じ対象を次の 3 つの構成で測った。

| 構成 | 内容 |
| --- | --- |
| R0 | 素の試作(煙試験のときの状態)。プロファイルなし。「試作のままで届くか」の参照値 |
| R1 | R0 に、アプリ自身のルーターからミドルウェアのグループと別名を展開する修正を足したもの(ルート表を作る1箇所のみ。宣言名は先頭に残し、判定の規則は不変)。プロファイルなし。「プロファイルなし」の本命値 |
| R2 | R1 に、最小のプロファイル(`poc/runtime/profiles/firefly.php`)を足したもの。「プロファイルあり」 |

測定は R0 → R1 → R2 の順に、構成ごとに 1 回ずつ行った(測定中にコードは替えていない)。`--only` は URI の部分一致で広く当たるため、`=` で始めると URI 全体の一致になる指定を試作に足した上で、対象 24 の URI を完全一致で指定した。URI が同じで HTTP メソッドが違うルートも当たるので、実行したルートとメソッドの組は 33 になる(うち 27 が T1 の一覧のもの)。

### T2.2 届いた本数

「届いた」は、応答が 2xx〜3xx で、かつ認証・引数・例外・検証のどこにも止められなかったものとした。R1 の「200 が返った」ものは、下の T2.3 のとおり届いていない。

| 構成 | 組(27 本の一覧 / 33 組) | 届いた | 届かなかった理由の内訳(27 本の一覧) |
| --- | --- | ---: | --- |
| R0 | 27 / 33 | 0 / 0 | 認証 13・Passport の鍵 10・引数の規則なし 4 |
| R1 | 27 / 33 | 0 / 0 | 二要素認証の確認で止まる 10・Passport の鍵 10・引数の規則なし 4・クライアントエラー 3 |
| R2 | 27 / 33 | 18 / 23 | 例外(データの形)7・見つからない 1・入力の検証(422)1 |

- **R0 では 1 本も届かなかった。** 認証の止まりと Passport の鍵の例外が、全て。
- **R1 のミドルウェアの修正で、認証の止まりは解けた。** web のルートは認証を通るようになった。API のルートは `auth:api` のガードを作る段階で `Invalid key supplied` のまま。引数の規則が無い 4 本もそのまま。
- **R2 では 27 本中 18 本が届いた。** 33 組では 23 組。

### T2.3 R1 で「200」が返ったものは届いていなかった

R1 で web の 10 ルートは 200 を返し、うち 4 本は `measured_O1` の判定が出た。しかし応答は、どのルートも、どの規模でも、**同じ 48 バイト**だった。これは二要素認証の中継ぎ(`google2fa-laravel` の既定の文言 `{"message":"One Time Password cannot be empty."}` が 48 バイト)で、生成した利用者の `mfa_secret` に文字列が入っていたため、ハンドラーの前で止まっていた。実測用の積み上げ(測定とは別の出力先)に同じ構成で入り、同じ要求を送って確かめた(HTML で受けると二要素認証の入力画面になる)。

つまり R1 の 4 本の `measured_O1` は「ハンドラーに届かない経路が、規模によらず一定だった」だけで、ルートの性質の判定ではない。試作は「規模の表を読まない一定は `no_info`」と見分ける規則を持つが、ここでは前段の束縛(`Binder`)が規模の表を読んでいたため、見分けられなかった。この件は R2 の表(T2.4)に反映していない。R1 の表の「届いた」の欄は 0 とした。

### T2.4 R2 の結果(27 本の一覧)

| 判定 | 本数 |
| --- | ---: |
| `measured_O1`(規模によらず一定) | 8 |
| `measured_ON` | 2 |
| `measured_ONM` | 3 |
| `no_info`(届いたが規模の表を読まず、判定できない) | 5 |
| `not_measured`(届かない) | 9 |

静的解析の下限が付いた 25 本のうち、R2 で届いたのは 16 本(対照の 2 本を除く)。その内訳は次のとおり。

| 静的の下限 | 届いた本数 | 実測が増加(O(N) 以上) | 実測が一定 | 判定できず(`no_info`) |
| --- | ---: | ---: | ---: | ---: |
| `≥O(N)` | 7 | 1 | 2 | 4 |
| `≥O(N*M)` | 9 | 4 | 5 | 0 |

- 増加と測れた 5 本は、`DELETE /api/v1/rule-groups/{ruleGroup}`(傾き 0.89)、`GET /api/v1/transactions/{transactionGroup}/attachments`(0.79)、`GET /api/v1/transactions/{transactionGroup}/piggy-bank-events`(0.92)、`DELETE /api/v1/transactions/{transactionGroup}`(0.99)、`POST /transactions/destroy/{transactionGroup}`(0.98)。クエリ数は 規模 10→100→1000 で たとえば 79→619→6019。
- 実測が一定だった 7 本は、静的の下限と食い違う(`static_stronger`)。この表の判定は規則どおりの出力で、どちらが正しいかは T3(コードで追う答え合わせ)で見る。ここでは決めない。
- 静的が確定していて実測が反対を言った「衝突」は 0 件。
- 全 33 組では、判定は `measured_O1` 12・`measured_ON` 2・`measured_ONM` 3・`no_info` 6・`not_measured` 10。静的との関係は 一致 1・実測のみ 7・静的が強い 9・比較できず 16。

届かなかった 9 本(33 組では 10 組)の理由は次のとおり。いずれも、この版の最小のプロファイルでは作らなかったデータの形である。

| 理由 | 組 | 内容 |
| --- | ---: | --- |
| 取引の金額が無い | 4 | `Amount is NULL for a transaction in group #1`。取引グループに、振替・出金に必要な 2 行(出し手と受け手)が揃っていない |
| 取引グループが壊れている | 1 | `Transaction group #1 is broken` |
| 定期取引の項目名 | 1 | `Recurrence transformer cant handle field "rt_meta_name_1"` |
| 空の参照 | 2 | `Attempt to read property "type" on null`・サブスクリプションの `amount` が null |
| 見つからない | 1 | `POST /api/v1/webhooks/{webhook}/submit`(Webhook の行が利用者に結びつかない) |
| 入力の検証(422) | 1 | `POST /piggy-banks/update/{piggyBank}`(必須の `name`・`accounts` を送っていない) |

### T2.5 最小のプロファイル(R2)

`poc/runtime/profiles/firefly.php`。**全体 166 行(コメント・空行を除くと 117 行)**。そのルートに届くために必要だった分だけを、足した順に足した。期待される答えに合わせた作り込みはしていない。

| 項目 | 理由 | 対象アプリの場所 |
| --- | --- | --- |
| `users.mfa_secret` を空にする | 秘密に文字列が入っていると、ハンドラーの前で二要素認証の入力を求められる(T2.3) | `vendor/jc5/google2fa-laravel/src/Google2FA.php:125` |
| 時間帯の列 13 表 22 列を `UTC` にする | 日付の列と対の `_tz` 列から時間帯を作る。生成した語は時間帯ではなく、ページが落ちる | `app/Casts/SeparateTimezoneCaster.php:50` |
| `transaction_currencies` の 1 行目を `EUR` にする | システム通貨をコード `EUR` で引く。無いとき金額を出すページが全て落ちる | `app/Support/Amount.php:332` |
| `account_types` の 1 行目を `Asset account` にする | 型が既知でないと、口座の表示が別のページへ送られる | `app/Http/Controllers/Account/ShowController.php:95` |
| `transaction_types` の 1 行目を `Withdrawal` にする | 型が既知でないと、編集ページが口座のページへ送られる | `app/Http/Controllers/Transaction/EditController.php:82` |
| 引数 `tagOrId`(`tags` の `id`)・`transactionType`(`withdrawal`)・`transactionGroup`(`transaction_groups` の `id`) | 試作の規則が埋められなかった 4 本の引数 | `app/Models/Tag.php`(routeBinder)・`routes/web.php` の convert |
| `GET /api/v1/rules/validate-expression` に `expression` を付ける | 必須の引数が無いと 422 になる | 同ルートの入力検証 |
| 起動直後の処理(`on_boot`)で Passport の公開鍵を入れる | `auth:api` のガードを作るとき鍵を読み、無いと `Invalid key supplied` で API 全部が止まる(T1.5)。使い捨ての鍵を実行中のプロセスの中だけで作り、対象アプリの設定は書き換えない | `config/passport.php`(`public_key`) |
| 起動直後の処理で Vite の補助を空のものに替える | 画面の描画で `@vite` がビルド済みの資産を引き、無いと `Vite manifest not found` で落ちる。資産の読み込み行は何のデータも運ばない。Laravel 自身の試験が使う「空の Vite」と同じ形 | `resources/views/layout/v3/blank.blade.php:22` |

Passport の鍵は、対象アプリを書き換えない形(計測用の使い捨て領域・コンテナ内のプロセスの中)で用意できた。鍵が使われるのはガードを作る段階だけで、トークンの検証は行わない。

**試作に足したもの。** プロファイルは、データの列と DB の行しか触れず、起動後のプロセスに手を入れる口が無かった。そのため、プロファイルが `on_boot`(起動直後に呼ぶ関数)を持てるようにした(別の commit)。プロファイルを指定しない実行には何も影響しないので、R0 と R1 の結果はそのまま比べられる。この口が無ければ、API の 15 組は「届かず」と記録するしかなかった。

### T2.6 所要時間

| 構成 | 測定の所要(ドライバーの計時) | 実行全体 |
| --- | ---: | ---: |
| R0 | 1146 秒 | 1160 秒 |
| R1 | 1223 秒 | 1237 秒 |
| R2 | 1329 秒 | 1344 秒 |

規模 10・100・1000 の 3 点、33 組、環境の立ち上げと後始末を含む。R2 は届くルートが増え、規模 1000 の要求が重いため長い。破壊的なルート(DELETE や POST で、単回・非冪等のもの)は、試作の既存の仕組み(要求ごとにロールバックし、できなければ DB を作り直す)で扱った。R2 では `DELETE` の 3 本(`/api/v1/piggy-banks/{piggyBank}`・`/api/v1/rule-groups/{ruleGroup}`・`/api/v1/transactions/{transactionGroup}`)と `POST /transactions/destroy/{transactionGroup}` に届き、うち 3 本は規模に応じた増加と測れた。

### T2.7 記録

生の出力は、リポジトリの外の作業置場の `T2_R0/`・`T2_R1/`・`T2_R2/`(各構成の `routes.jsonl`・`attempts.jsonl`・`summary.json`・`report.md`)。ルートごとの表は、構成別・規模別の判定・傾き・届いたか・応答のバイト数・止まった理由を、機械可読の `T2_summary.json` と、人が読む `T2_summary.md` に出した。応答の行数は試作が記録しないので、バイト数と、読んだ表・クエリ数で代えた。

### T2 の結論

- 素の試作(R0)では、対象 27 本のうち **0 本** が届いた。ミドルウェアのグループの展開(R1)で認証は通るが、利用者の二要素認証と Passport の鍵で止まり、**届いたのは 0 本**(200 が返った 10 本は二要素認証の中継ぎ)。
- 最小のプロファイル(R2)を足すと **18 本** が届き、そのうち静的が下限を付けた 16 本で、実測が増加(O(N) 以上)と測れたのは **5 本**、一定が 7 本、判定できずが 4 本。衝突は 0 件。
- 届かなかった 9 本は、全て「データの形」(取引の行の組み合わせ、定期取引の項目、Webhook の持ち主、必須の入力)で、最小のプロファイルの外だった。
- R1 の「200」「`measured_O1`」は、ハンドラーに届かない経路の判定だった。この見分けは試作の弱点として残る(前段の束縛が規模の表を読むと、`no_info` にならない)。

この版は測定の記録である。実測の判定と静的の下限の食い違いがどちらの誤りかは、§T3(コードで追う答え合わせ)で見る。

