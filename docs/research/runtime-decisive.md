# 実測モードは役に立つかを白黒つける実験

作成日: 2026-11-02

問いは次の一つである。**静的解析が「少なくとも O(N)」と下限を付けたルートを、実測で本当に O(N) と測れるか。**

- 測れる → 実測は静的解析の推定を裏づけ、埋める役に立つ
- 分かっている O(N) すら測れない → 実測モードはやめ、静的解析に集中する

この文書は実験の記録で、節ごとに足していく。この版は **§T1(対象アプリとルートの選定、環境の用意、煙試験)**、**§T2(本測定)**、**§T3(コードで追う答え合わせ)**、**§T4(T3 と T2 の突き合わせ。分類 A〜E)** を書く。§T4 には事実と数だけを書き、判断の材料(実測モードを本採用するかの材料)は後の版で足す。

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

測定は R0 → R1 → R2 の順に、構成ごとに 1 回ずつ行った(測定中にコードは替えていない)。O(N*M) の判定には、N の軸の規模 10・100・1000 のほか、M の軸の規模 3・30・300(と参照の規模 0)も使われる。`--only` は URI の部分一致で広く当たるため、`=` で始めると URI 全体の一致になる指定を試作に足した上で、対象 24 の URI を完全一致で指定した。URI が同じで HTTP メソッドが違うルートも当たるので、実行したルートとメソッドの組は 33 になる(うち 27 が T1 の一覧のもの)。

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

なお、`firefly.php` の先頭のコメント 8 行は、測定のあとに書き換えた(commit a292904。コメントのみで、測定時の版とコードは同一)。

Passport の鍵は、対象アプリを書き換えない形(計測用の使い捨て領域・コンテナ内のプロセスの中)で用意できた。鍵が使われるのはガードを作る段階だけで、トークンの検証は行わない。

**試作に足したもの。** プロファイルは、データの列と DB の行しか触れず、起動後のプロセスに手を入れる口が無かった。そのため、プロファイルが `on_boot`(起動直後に呼ぶ関数)を持てるようにした(別の commit)。プロファイルを指定しない実行には何も影響しないので、R0 と R1 の結果はそのまま比べられる。この口が無ければ、API の 15 組は「届かず」と記録するしかなかった。

### T2.6 所要時間

| 構成 | 測定の所要(ドライバーの計時) | 実行全体 |
| --- | ---: | ---: |
| R0 | 1146 秒 | 1160 秒 |
| R1 | 1223 秒 | 1237 秒 |
| R2 | 1329 秒 | 1344 秒 |

N の軸の規模 10・100・1000 の 3 点(O(N*M) の判定では M の軸の規模 3・30・300 と参照の規模 0 も使う)、33 組、環境の立ち上げと後始末を含む。R2 は届くルートが増え、規模 1000 の要求が重いため長い。破壊的なルート(DELETE や POST で、単回・非冪等のもの)は、試作の既存の仕組み(要求ごとにロールバックし、できなければ DB を作り直す)で扱った。R2 では `DELETE` の 3 本(`/api/v1/piggy-banks/{piggyBank}`・`/api/v1/rule-groups/{ruleGroup}`・`/api/v1/transactions/{transactionGroup}`)と `POST /transactions/destroy/{transactionGroup}` に届き、うち 3 本は規模に応じた増加と測れた。

### T2.7 記録

生の出力は、リポジトリの外の作業置場の `T2_R0/`・`T2_R1/`・`T2_R2/`(各構成の `routes.jsonl`・`attempts.jsonl`・`summary.json`・`report.md`)。ルートごとの表は、構成別・規模別の判定・傾き・届いたか・応答のバイト数・止まった理由を、機械可読の `T2_summary.json` と、人が読む `T2_summary.md` に出した。応答の行数は試作が記録しないので、バイト数と、読んだ表・クエリ数で代えた。

### T2 の結論

- 素の試作(R0)では、対象 27 本のうち **0 本** が届いた。ミドルウェアのグループの展開(R1)で認証は通るが、利用者の二要素認証と Passport の鍵で止まり、**届いたのは 0 本**(200 が返った 10 本は二要素認証の中継ぎ)。
- 最小のプロファイル(R2)を足すと **18 本** が届き、そのうち静的が下限を付けた 16 本で、実測が増加(O(N) 以上)と測れたのは **5 本**、一定が 7 本、判定できずが 4 本。衝突は 0 件。
- 届かなかった 9 本は、全て「データの形」(取引の行の組み合わせ、定期取引の項目、Webhook の持ち主、必須の入力)で、最小のプロファイルの外だった。
- R1 の「200」「`measured_O1`」は、ハンドラーに届かない経路の判定だった。この見分けは試作の弱点として残る(前段の束縛が規模の表を読むと、`no_info` にならない)。

この版は測定の記録である。実測の判定と静的の下限の食い違いがどちらの誤りかは、§T3(コードで追う答え合わせ)で見る。

## T3. コードで追う答え合わせ

静的解析が付けた下限が、実態に合っているかを、**実測の出力を見ずに、コードを読んで**決めた。実測(§T2)が「届かない」「一定」と言った理由を、後で突き合わせるための基準になる。

### T3.1 方法

- 対象は §T1.3 の 27 本(対象 25 本と対照 2 本)。アプリは Firefly III(コミット `d65e916`)。対象アプリは読むだけで、書き換えず、ホストで `artisan`・`composer` を走らせていない。クエリ数は読解による見立てで、実行して数えたものではない。
- ルートのハンドラーから、リポジトリ・サービス・オブザーバー・イベントのリスナーまでを読み、**クエリの回数が何の行数で増えるか**を決めた。見るのはクエリの軸で、メモリの軸は別に記録した。
- ルートごとに次の6つを記録した: 本当の増え方(`O(1)`・`O(N)`・`O(N*M)`・条件つき)、増える軸、静的の下限の判定(正しい / 一部 / 過大)、過大の型(§T3.3)、確度(high・medium・low)、根拠の `file:line`。
- **決めきれないものは E** として別に数える。確度が low のものも E に入れる。今回は 0 本だった。
- 担当は2つに分けた。前半(対象 `≥O(N)` 層 15 本と対照 2 本)と、後半(`≥O(N*M)` 層 10 本)は、別の追い手が互いの答えを見ずに追った。さらに 4 本(16・18・23・24)は、前半の追い手が独立に追い直した(§T3.4)。

### T3.2 集計

本当の増え方(27 本):

| 層 | 本数 | O(N) | O(N*M) | 条件つき | O(1) | E |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `≥O(N)` 層 | 15 | 10 | 0 | 3 | 2 | 0 |
| `≥O(N*M)` 層 | 10 | 4 | 2 | 4 | 0 | 0 |
| 対照 | 2 | 0 | 0 | 0 | 2 | 0 |
| 計 | 27 | 14 | 2 | 7 | 4 | 0 |

- **コードで O(N) 以上と決まったのは 16 本**(O(N) 14・O(N*M) 2)。この 16 本が §T4 の分母になる。条件つきの 7 本は、条件が成り立つと増えるが、成り立たなければ増えない。そのため分母には入れず、別表に分ける(§T4.7)。
- O(1) は 4 本。うち 2 本が対照(静的が O(1)・high と言ったもの)で、コードでも O(1) だった。残る 2 本(10・15)は、静的が `≥O(N)` と下限を付けたが、コードでは O(1) だった。

静的の下限の判定(対象 25 本。対照の 2 本には下限が無い):

| 層 | 正しい | 一部(下限は成り立つが、次数か軸が違う) | 過大 |
| --- | ---: | ---: | ---: |
| `≥O(N)` 層 | 9 | 2 | 4 |
| `≥O(N*M)` 層 | 0 | 7 | 3 |
| 計 | 9 | 9 | 7 |

- `≥O(N*M)` 層の 10 本は、静的の下限がそのまま正しかったものが **0 本**。一部が 7 本、過大が 3 本だった。いずれも、数えた単位(何の件数が N か)や、定数のループを入れ子と数えたことが理由である(§T3.3)。
- 確度は high 16 本・medium 11 本・low 0 本。medium の理由は、イベントのリスナーの先を追い切れていないこと、キャッシュの有無、条件(データの形)に依ること。

### T3.3 静的の下限が実態とずれた型

両担当の呼び名を1つにそろえた。型1〜3 は `real-app-trial.md` の §4.3.3 のもの。枝の記号(a・b・c)を付けて細分し、枝のない呼び方は総称として使う。

| 型 | 意味 | 下限の向き | 出現するルート |
| --- | --- | --- | --- |
| 型1 条件つきの事象を数えた | 重い事象が、ある条件のときだけ起きる | 過大 | 7(1a)・14(1b)・15(1b)・23(1c)・24(1c) |
| 　1a 設定ゲート | 既定の設定では走らない(404 など)経路を数えた | 過大 | 7 |
| 　1b 走らない枝 | 主経路ではない早期 return・redirect の枝の事象を数えた | 過大 | 14・15 |
| 　1c 入力が有効なときだけ | 本文が有効なときだけ重い経路に進み、無効・空だと早期に終わる | 過大 | 23・24 |
| 型2 定数のループを入れ子と数えた | 回数は実は定数(複式簿記の取引は仕訳あたり 2 件など)のループを入れ子にした | 次数が過大 | 16・17・24・25 |
| 型3 N(M)の表が実態の軸でない | 数えた単位が違う | 過大、または軸だけ違う | 後半の 10 本(16〜25。3a)・4 と 11(3b)・14(3c)・15(3a 相当) |
| 　3a 束縛した 1 件の子 | N・M が、URL で束縛した 1 件のレコードの子の件数(表全体の件数ではない) | 過大 | 16〜25 の 10 本・15 |
| 　3b 入力の要素数 | N がリクエスト本文の配列の要素数で、表の行数ではない | 過大 | 4・11 |
| 　3c 別の表が実軸 | 次数は合うが、実際に増える表が別 | 軸違い(次数は正) | 14 |
| 型4 追えない呼び出しの先に実際の重さ | イベントのリスナーなど、静的が先を追わない呼び出しの中に、件数で増える重い経路がある | 低すぎと過大が同時に起きる | 16・17・24・25 |
| 型5 ループ内の遅延読込が実は 1 回 | ループ内の関係の読み出しが、同じモデルインスタンスの関係キャッシュにより最初の 1 回しか走らない | 過大 | 10 |

1 本が複数の型を持つ。型なし(下限が正しい)は 9 本(1・2・3・5・6・8・9・12・13)。この表は 25 本の標本から作ったもので、型の網羅は保証しない。

使うときの注意が 3 つある。

- 「下限が過大」と「下限が低すぎ」は、同じルートで同時に起きうる(型4)。実測が O(N) と出うるかは、別に見る必要がある(16・17・25 は実測が O(N) と出うる)。
- 型3a と型3b は、データの作り方しだいで下限どおりになりうる(例: 1 件の貯金箱に多数の口座を紐づける)。**実測が O(1) と出たとき、実測の失敗か、下限の過大かは、実測側のデータの作り方を見ないと決められない。**
- 型5 は、メソッド名や行の見た目からは見分けられず、同一インスタンスの関係キャッシュを確かめる必要がある。

### T3.4 交差検証(独立の追い直し)

`≥O(N*M)` 層の 4 本(16・18・23・24)を、後半の追い手の答えと実測の出力を見ずに、前半の追い手が独立に追い直し、結論を書き終えてから突き合わせた。

| 項目 | 一致 |
| --- | --- |
| 本当の増え方 | 3/4(突き合わせ前。18 だけ不一致)。突き合わせのあと、後半の記録を改めて 4/4 |
| 増える軸 | 4/4(コードで追った事実は食い違わなかった) |
| 静的の下限の判定(正しい/一部/過大。改訂後の記録の比較) | 0/4 |

下限の判定が合わなかった 4 本のうち、中身の食い違いは **18 の 1 本だけ**だった。他の 3 本は、同じ事実に付けたラベルの粒度の差である(「一部」と「過大」の境目)。

- **18**: 追い直しは O(N*M)・下限は正しい、後半の初稿は「条件つき・過大(型3a)」だった。事実は同じ(クエリ ≈ N + N×M + 頁分)で、差は「入れ子を積と数えるか、総添付数に比例する和と数えるか」だった。仕様の判定規則(`spec.md` の N を動かした傾きと M を動かした傾きがどちらも 0.5 以上)を当てると、18 は O(N*M) になる。ただし 1 つのグループが複数の仕訳(split 取引)を持たないと N が動かせないので、一様に埋めたデータでは O(N) 相当にしか見えない(データの形の理由)。
- **16・23・24**: 本当の増え方と軸は一致した。下限の判定のラベルだけが異なる(16 は一部と過大、23 は過大と正しい、24 は一部と過大)。分類には、後半の担当の記録を使った。
- 追い直しで見つけた事実は、後半の記録に取り込んだ。16 は、口座を規模に比例して増やす一様な埋め方だと、口座あたりの取引数が一定になり効かなくなる(データの形)。

### T3.5 ルートごとの答え(27 本)

| # | ルート | 層 | 本当の増え方 | 軸 | 静的の下限 | 型 | 確度 |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET /accounts/show/{account}/all | `≥O(N)` | O(N) | attachments(URL の口座に紐づく行 / morphMany) | 正しい | — | high |
| 2 | GET /api/v1/bills/{bill}/attachments | `≥O(N)` | O(N) | attachments(URL の請求=bill に紐づく行 / morphMany) | 正しい | — | high |
| 3 | GET /api/v1/categories/{category}/attachments | `≥O(N)` | O(N) | attachments(URL のカテゴリに紐づく行 / morphMany) | 正しい | — | high |
| 4 | PUT /api/v1/piggy-banks/{piggyBank} | `≥O(N)` | 条件つき | accounts(リクエスト本文の accounts[] の要素数 K / および貯金箱に紐づく口座 A) | 過大 | 型3b | medium |
| 5 | DELETE /api/v1/rule-groups/{ruleGroup} | `≥O(N)` | O(N) | rules(ユーザーの全ルール)+ rule_groups(ユーザーの全グループ) | 正しい | — | high |
| 6 | GET /api/v1/tags/{tagOrId}/attachments | `≥O(N)` | O(N) | attachments(URL のタグに紐づく行 / morphMany) | 正しい | — | high |
| 7 | POST /api/v1/webhooks/{webhook}/submit | `≥O(N)` | 条件つき | webhook_messages(URL の webhook に紐づく、sent=0 かつ errored=0 の行) | 一部 | 型1a | high |
| 8 | GET /budgets/show/{budget}/{budgetLimit} | `≥O(N)` | O(N) | budget_limits(URL の予算の期間枠 / 範囲内)と attachments(URL の予算に紐づく行) / さらに負債口座(liability)との積 | 正しい | — | medium |
| 9 | POST /groups/destroy/{objectGroup} | `≥O(N)` | O(N) | piggy_banks(URL の object group に属する貯金箱) | 正しい | — | high |
| 10 | GET /piggy-banks/remove/{piggyBank} | `≥O(N)` | O(1) | (クエリは表の件数に依らない) / メモリ・CPU は accounts(この貯金箱の口座 A) | 過大 | 型5 | high |
| 11 | POST /piggy-banks/update/{piggyBank} | `≥O(N)` | 条件つき | accounts(フォームの accounts[] の要素数 K / および貯金箱に紐づく口座 A) | 過大 | 型3b | medium |
| 12 | GET /recurring/show/{recurrence} | `≥O(N)` | O(N) | journal_meta(この定期取引が作った仕訳=meta name=recurrence_id の行 J) | 正しい | — | high |
| 13 | GET /subscriptions/show/{bill?} | `≥O(N)` | O(N) | transaction_journals(この請求=bill に紐づく仕訳 J / bill_id) | 正しい | — | high |
| 14 | GET /transactions/convert/{transactionType}/{transactionGroup} | `≥O(N)` | O(N) | accounts(ユーザーの有効な口座: 4 つの選択肢リストで毎回全件を回す) | 一部 | 型1b・型3c | medium |
| 15 | GET /transactions/edit/{transactionGroup} | `≥O(N)` | O(1) | (表の件数に依らない) | 過大 | 型1b・型3a | high |
| 16 | DELETE /api/v1/transactions/{transactionGroup} | `≥O(N*M)` | O(N) | transactions(削除した仕訳に触れた口座の、仕訳の日付以降の取引行 / 1 行ごとに UPDATE) | 一部 | 型2・型3a・型4 | medium |
| 17 | PUT /api/v1/transactions/{transactionGroup} | `≥O(N*M)` | O(N) | transactions(触れた口座の、仕訳の日付以降の取引行)に、rules(update-journal 用の有効な規則)の件数が加わる | 一部 | 型2・型3a・型4 | medium |
| 18 | GET /api/v1/transactions/{transactionGroup}/attachments | `≥O(N*M)` | O(N*M) | 束縛した1グループの 仕訳数 J × 仕訳あたりの添付数 A_j(クエリ ≈ J + J×A_j + 頁分) / 軸は表全体の件数ではなく、1件のレコードの子の件数 | 一部 | 型3a | high |
| 19 | GET /api/v1/transactions/{transactionGroup}/piggy-bank-events | `≥O(N*M)` | O(N*M) | 束縛した1グループの 仕訳数 J × 仕訳あたりの貯金箱イベント数 E_j(クエリ ≈ J + J×E_j + 定数) / 軸は1件のレコードの子の件数 | 一部 | 型3a | high |
| 20 | GET /piggy-banks | `≥O(N*M)` | O(N) | piggy_banks(貯金箱 × 紐づく口座の組 / account_piggy_bank の行数) / 組あたり約 10 クエリ以上 | 一部 | 型3a | high |
| 21 | GET /piggy-banks/add-money/{piggyBank} | `≥O(N*M)` | 条件つき | piggy_banks(束縛した貯金箱の口座と同じ口座に紐づく貯金箱の件数 K) / 貯金箱 1 つの口座数 M0 × (K + 定数) | 過大 | 型3a | medium |
| 22 | GET /piggy-banks/add/{piggyBank} | `≥O(N*M)` | 条件つき | piggy_banks(束縛した貯金箱の口座と同じ口座に紐づく貯金箱の件数 K) / 貯金箱 1 つの口座数 M0 × (K + 定数) | 過大 | 型3a | medium |
| 23 | POST /piggy-banks/add/{piggyBank} | `≥O(N*M)` | 条件つき | 入力なしなら O(1) / 入力ありなら 21・22 と同じ(同じ口座の貯金箱の件数 K × 口座数 M0) | 過大 | 型1c・型3a | medium |
| 24 | POST /transactions/convert/{transactionType}/{transactionGroup} | `≥O(N*M)` | 条件つき | 入力が有効なとき 17 と同じ(transactions と rules) / 入力が無効なら O(1) | 一部 | 型1c・型2・型3a・型4 | medium |
| 25 | POST /transactions/destroy/{transactionGroup} | `≥O(N*M)` | O(N) | transactions(削除した仕訳に触れた口座の、仕訳の日付以降の取引行 / 1 行ごとに UPDATE) | 一部 | 型2・型3a・型4 | medium |
| 26 | GET /api/v1/rules/validate-expression | 対照 | O(1) | (表の件数に依らない) | —(対照) | — | high |
| 27 | GET /json/box/available | 対照 | O(1) | (表の件数に依らない) | —(対照) | — | high |

各ルートの根拠(`file:line`)と、条件つきの条件は、作業置場の `T3a_trace.json`・`T3b_trace.json`・`T4_classification.json` に機械可読で置いた。

### T3 の結論

- コードで O(N) 以上と決まったのは **16 本**。条件つきが 7 本、O(1) が 4 本(うち対照 2)。決めきれないもの(E)は **0 本**。
- 静的の下限(対象 25 本)が、そのまま正しかったのは 9 本、一部が 9 本、過大が 7 本。`≥O(N*M)` 層では、そのまま正しかったものは 0 本だった。
- 過大の理由は 5 つの型に整理できる(§T3.3)。うち型3 と型4 は、「実測が O(1) と出たとき、実測の失敗か、下限の過大か」を分けるのに、データの作り方を見る必要があることを示している。
- 独立の追い直しで、増え方は 3/4 で一致し(不一致の 1 本は、突き合わせのあと後半の記録を改めた)、軸は 4/4 で一致した。

## T4. T3 と T2 の突き合わせ(分類 A〜E)

§T3(コードの答え)と §T2(実測)を、27 本のルートごとに、3 つの構成(R0・R1・R2)別に突き合わせて、A〜E に振った。振り分けは、記録から機械的に決まる(作業置場の `T4_join.py` を入力の JSON に当てると、同じ結果が出る)。判定の規則(log-log 傾き)も D21 も変えていない。

### T4.1 分類の規則

| 分類 | 条件 |
| --- | --- |
| A | コードで O(N) 以上 かつ 実測が増加(`measured_ON`・`measured_ONM`) |
| B | コードで O(N) 以上 かつ 実測が届かず、または情報なし(`no_info`)。理由を記録する |
| C | コードで O(N) 以上 かつ 実測が一定(`measured_O1`)=偽の O(1) |
| D | コードで O(1) か条件つき かつ 実測が O(1) か、条件を裏づける増加。**確**=コードで O(1) と出て実測も O(1)、または条件つきで実測が増えた。**疑**=条件つきで、応答のバイト数が全規模で同一(条件が成り立ったか不明ゆえ、D と言い切れない) |
| D 候補 | コードで O(1) か条件つき かつ 実測が届かず、何も言えないもの。D の本数には入れない |
| E | コードでも決めきれない(今回は 0 本) |

- **分母 A+B+C は、コードで O(N) 以上の 16 本**。条件つきの 7 本(4・7・11・21・22・23・24)は、コードの答えが固定の増え方でないので、分母に入れず別表にした(§T4.7)。O(1) の 4 本も分母に入らない。
- 「届いた」は、§T2.2 のとおり、応答が 2xx〜3xx で、認証・引数・例外・検証のどこにも止められなかったもの。R1 の二要素認証の 48 バイト応答は、届いたに数えない。その判定欄が `measured_O1` や `no_info` でも、届いていない応答の値なので、**分類は B(理由は二要素認証の入力待ち)**とし、対象が O(1) か条件つきなら D 候補とした。
- 「応答不変」は、届いた応答のバイト数が、N の軸(10・100・1000)と M の軸(3・30・300)の全ての点で同一であること。
- 構成は §T2.1 の R0(素の試作)・R1(ミドルウェアの展開を足した。**「プロファイルなし」の本命**)・R2(R1 に最小のプロファイルを足した)。

### T4.2 集計

| 構成 | 届いた | A | B | C | D | D 候補 | E | A÷(A+B+C) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| R0 | 0/27 | 0 | 16 | 0 | 0 | 11 | 0 | 0/16 = 0.0% |
| R1 | 0/27 | 0 | 16 | 0 | 0 | 11 | 0 | 0/16 = 0.0% |
| R2 | 18/27 | 5 | 10 | 1 | 7(確 2/疑 5) | 4 | 0 | 5/16 = 31.2% |

R2 の D 7 本の内訳は、確が 10・26、疑が 4・21・22・23・24。D の分け方は A÷(A+B+C) を変えない。

層別:

| 構成 | 層 | 本数 | A | B | C | D | D 候補 | A÷(A+B+C) |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| R0 | `≥O(N)` 層 | 15 | 0 | 10 | 0 | 0 | 5 | 0/10 = 0.0% |
| R0 | `≥O(N*M)` 層 | 10 | 0 | 6 | 0 | 0 | 4 | 0/6 = 0.0% |
| R0 | 対照 | 2 | 0 | 0 | 0 | 0 | 2 | 分母ゼロ |
| R1 | `≥O(N)` 層 | 15 | 0 | 10 | 0 | 0 | 5 | 0/10 = 0.0% |
| R1 | `≥O(N*M)` 層 | 10 | 0 | 6 | 0 | 0 | 4 | 0/6 = 0.0% |
| R1 | 対照 | 2 | 0 | 0 | 0 | 0 | 2 | 分母ゼロ |
| R2 | `≥O(N)` 層 | 15 | 1 | 9 | 0 | 2(確 1/疑 1) | 3 | 1/10 = 10.0% |
| R2 | `≥O(N*M)` 層 | 10 | 4 | 1 | 1 | 4(確 0/疑 4) | 0 | 4/6 = 66.7% |
| R2 | 対照 | 2 | 0 | 0 | 0 | 1(確 1/疑 0) | 1 | 分母ゼロ |

### T4.3 B の理由

B はコードで O(N) 以上の 16 本のうち、実測が増加を言えなかったもの。理由は次のとおり。

- **R0**(B 16 本): Passport の鍵 7、認証 401 6、引数 3
- **R1**(B 16 本): Passport の鍵 7、二要素認証 5、引数 3、二要素認証(書き込み) 1
- **R2**(B 10 本): 応答が空 4、例外: 金額が NULL 3、例外(要求が組めず) 1、例外: 取引グループが壊れている 1、例外: 定期取引の項目名 1

- R2 の例外 6 本は、データの形が原因(取引グループの組み合わせ、定期取引の項目名など。§T2.4)。
- R2 の「応答が空」4 本(2・3・6・9)は、ハンドラーには届いたが、応答に生成した行が出ていないもの。偽の O(1) と同じ型の疑いがあるが、これらは規則上は `no_info` で B に入る。

### T4.4 C(偽の O(1))とその原因

C は R0・R1 に 0 本、R2 に **1 本**(20 GET /piggy-banks)。

**GET /piggy-banks(20)**: コードでは O(N)(確度 high)。実測は `measured_O1`(傾き 0)で、クエリ 109・応答 53989 バイトが規模 10・100・1000(M の軸 3・30・300 も)で同一だった。規模 0 は 38 クエリ・42277 バイトで、一覧が空だった。

**原因は、測定のデータが、この一覧に載る行に届いていなかったこと**である。実行時の行数で確かめた。測定用の使い捨てのスタック(内部ネットワークのみ・DB は測定専用)で、R2 と同じデータの作り方(同じ計画・同じプロファイル)を規模 0・10・1000 で作り直し、`SELECT` だけで数えた。対象アプリは書き換えていない。

| 規模 | `piggy_banks` | `account_piggy_bank`(中間表) | `accounts` | 利用者 1 の口座 | 利用者 1 に紐づく組 | 一覧に載る貯金箱 | 貯金箱 1 に紐づく口座 |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| 10 | 10 | 5 | 30 | 6 | 1 | 1 | 5 |
| 1000 | 1000 | 5 | 3000 | 600 | 1 | 1 | 5 |

- 規模が 10 から 1000 に増えても、`piggy_banks` は 10 → 1000 と増え、`accounts` は 30 → 3000 と増えたが、**中間表は 5 行のまま**で、**一覧に載る貯金箱は 1 件**のまま、利用者 1 に紐づく組も **1 組**のままだった。規模 0 は全てが 0 で、一覧が空になる。
- 5 行の中身は、どちらの規模でも「貯金箱 1 × 口座 1〜5」だった。口座は利用者 1〜5 に順に割り当たり、利用者 1 の組は口座 1 の 1 組になる。
- アプリ側の理由: 一覧は `piggy_banks` を中間表と `accounts` に左結合し、`accounts.user_id` が利用者のものに絞って `distinct` を取る(`app/Repositories/PiggyBank/PiggyBankRepository.php:302-313`)。載った貯金箱ごとに、その貯金箱の口座を全件処理する(`app/Http/Controllers/PiggyBank/IndexController.php:127,131-134`)。コストは「載る貯金箱の数」とその口座数に比例する。
- 測定側の理由: 計画が増やすのは n 表(`piggy_banks`)と m 表(`accounts`)だけで、両者を結ぶ中間表は計画に載らず、基底の 5 行のままだった(`poc/runtime/src/Data/ScalePlan.php:96-99`、`poc/runtime/src/Measure/GeneratorPlanner.php:20`)。中間表の (口座, 貯金箱) の組は、口座の番号を最下位の桁とする混在基数で並べる(`poc/runtime/src/Data/Generator.php:277-280,304-319`)。5 行では、貯金箱 1 より先へ進まない。

**試作側の問題か、判定器の問題か。**

- 一次の原因は**試作側(データの作り方)**。n 表と m 表の間にある中間表を計画に入れていない。
- 判定器の `no_info` 規則は、この型を拾えない。規則は「増やした表を読まなかった道筋」と「最大規模の応答が空の系と同じ」の 2 つ。20 は貯金箱の表を読んでおり、規模 0 の応答(42277 バイト)とも違う(53989 バイト)ので、どちらにも当たらず `measured_O1` になった。

提案(今回は直さない):

- 試作側(提案のみ・直さない): 計画に『n 表と m 表の両方へ外部キーを持つ中間表』を入れ、行数を m 表の行数(または N×M)に比例させる。数え上げ(radixKeys)の最下位の桁が先に回りきるまで貯金箱 1 に固まるので、中間表の行数が親の行数より少ないと必ず先頭の親に偏る点も計画の側で見る。
- 試作側(提案のみ): データを作った後に、n 表の行が『n 表を読む問い合わせの道筋』に届く形かを確かめる検査(n 表の子・中間表が基底のままなら警告 pivot_at_base)を足し、警告が出たルートは判定を no_info にする。
- 判定器(提案のみ・未検証): 応答の道筋で読まれた n 表の行数が規模で増えない(1 回の要求で Eloquent が組み立てた n 表のモデルの数が規模 10 と 1000 で同じ)なら no_info とする。この数が取れるかは試していない。

確かなのは、規模 10 以上で中間表が 5 行・載る貯金箱が 1 件・利用者 1 に紐づく組が 1 組のこと(`SELECT` で数えた。鍵の有無を変えた 2 回の撮り直しで同一)。推定のままなのは、109 クエリの内訳(1 貯金箱 × 口座 5 件の処理)で、分解していない。データの作り方を直した再測定はしていないので、直せば 20 が O(N) と出るかは未確認である。

**同じ型の疑い。** 応答が全規模で同一だった貯金箱系の 6 本(4・10・20・21・22・23)のうち、4・10(計画 `t52edb7`)と 21・22・23(計画 `td308fa`)も、応答が全規模で同一だった。この 2 つの計画の行数も、同じ手順で数えた。

| 計画 | 使うルート | 規模 | `piggy_banks` | 中間表 | `accounts` | 貯金箱 1 に紐づく口座 |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `t52edb7` | 4・10・11(n 表 accounts) | 0 | 0 | 0 | 0 | 0 |
| `t52edb7` | 4・10・11(n 表 accounts) | 10 | 5 | 5 | 10 | 5 |
| `t52edb7` | 4・10・11(n 表 accounts) | 1000 | 5 | 5 | 1000 | 5 |
| `td308fa` | 21・22・23(n 表 accounts・m 表 piggy_banks) | 0 | 0 | 0 | 0 | 0 |
| `td308fa` | 21・22・23(n 表 accounts・m 表 piggy_banks) | 10 | 30 | 5 | 10 | 5 |
| `td308fa` | 21・22・23(n 表 accounts・m 表 piggy_banks) | 1000 | 3000 | 5 | 1000 | 5 |

どの計画も、規模 10 と 1000 で**中間表は 5 行のまま**、貯金箱 1 の口座は 5 件で一定だった。この 5 本は貯金箱 1 番を引数に取るので、コストは規模によらず一定になる形である。21・22・23 の条件(同じ口座に多数の貯金箱)は、作られていない。ただし、これで応答が不変だった**因果を確かめたわけではない**(本文の形が送られたか、など別の理由が重なりうる)。確かめたのは、データが条件を作っていないことまでである。

### T4.5 D の型

- **R0・R1**: D は 0 本。D 候補 11 本(R1 の 4 本の `measured_O1` は二要素認証の応答の値なので、D に入れず D 候補にした)。
- **R2**: D 7 本=(型なし) 1、型1c 2、型2 1、型3a 4、型3b 1、型4 1、型5 1。D 候補 4 本=(型なし) 1、型1a 1、型1b 1、型3a 1、型3b 1。

1 本が複数の型を持つので、型の合計は本数を超える。R2 の D のうち、**確 2 本(10・26)**は、コードが O(1) で、実測も O(1) だった。**疑 5 本(4・21・22・23・24)**は、条件つきなのに応答が全規模で同一で、条件が成り立ったか不明なので、D と言い切れない。

### T4.6 ルートごとの分類(27 本)

`A`〜`E` は §T4.1 の分類、`D 確`・`D 疑` は D の分け方(R2 のみ)。B・D 候補の括弧は止まった理由(認証 401 = ログインできず / Passport の鍵 = API の公開鍵が無い / 二要素認証 = 入力待ち / 引数 = URL の引数を埋められず / 404 / 422)、A の括弧は実測の判定と N の軸の傾き。根拠は T3 の `file:line`(先頭 2 件)。

| # | ルート | T3(増え方・型・確度) | R0 | R1 | R2 | 根拠 |
| ---: | --- | --- | --- | --- | --- | --- |
| 1 | GET /accounts/show/{account}/all | O(N)・型なし・high | B(認証 401) | B(二要素認証) | B(例外: 金額が NULL) | app/Http/Controllers/Account/ShowController.php:209; app/Repositories/Account/AccountRepository.php:367 |
| 2 | GET /api/v1/bills/{bill}/attachments | O(N)・型なし・high | B(Passport の鍵) | B(Passport の鍵) | B(応答が空) | app/Api/V1/Controllers/Models/Bill/ListController.php:77; app/Repositories/Bill/BillRepository.php:189 |
| 3 | GET /api/v1/categories/{category}/attachments | O(N)・型なし・high | B(Passport の鍵) | B(Passport の鍵) | B(応答が空) | app/Api/V1/Controllers/Models/Category/ListController.php:73; app/Repositories/Category/CategoryRepository.php:170 |
| 4 | PUT /api/v1/piggy-banks/{piggyBank} | 条件つき・型3b・medium | D 候補(Passport の鍵) | D 候補(Passport の鍵) | D 疑 | app/Api/V1/Controllers/Models/PiggyBank/UpdateController.php:67; app/Support/JsonApi/Enrichments/PiggyBankEnrichment.php:201 |
| 5 | DELETE /api/v1/rule-groups/{ruleGroup} | O(N)・型なし・high | B(Passport の鍵) | B(Passport の鍵) | A(ON・傾き 0.8941) | app/Repositories/RuleGroup/RuleGroupRepository.php:75; app/Repositories/RuleGroup/RuleGroupRepository.php:88 |
| 6 | GET /api/v1/tags/{tagOrId}/attachments | O(N)・型なし・high | B(引数) | B(引数) | B(応答が空) | app/Api/V1/Controllers/Models/Tag/ListController.php:76; app/Repositories/Tag/TagRepository.php:123 |
| 7 | POST /api/v1/webhooks/{webhook}/submit | 条件つき・型1a・high | D 候補(Passport の鍵) | D 候補(Passport の鍵) | D 候補(404) | app/Api/V1/Controllers/Webhook/SubmitController.php:60; app/Repositories/Webhook/WebhookRepository.php:87 |
| 8 | GET /budgets/show/{budget}/{budgetLimit} | O(N)・型なし・medium | B(認証 401) | B(二要素認証) | B(例外: 金額が NULL) | app/Http/Controllers/Budget/ShowController.php:251; app/Http/Controllers/Budget/ShowController.php:252 |
| 9 | POST /groups/destroy/{objectGroup} | O(N)・型なし・high | B(認証 401) | B(二要素認証(書き込み)) | B(応答が空) | app/Http/Controllers/ObjectGroup/DeleteController.php:80; app/Repositories/ObjectGroup/ObjectGroupRepository.php:69 |
| 10 | GET /piggy-banks/remove/{piggyBank} | O(1)・型5・high | D 候補(認証 401) | D 候補(二要素認証) | D 確 | app/Http/Controllers/PiggyBank/AmountController.php:249; app/Repositories/PiggyBank/PiggyBankRepository.php:138 |
| 11 | POST /piggy-banks/update/{piggyBank} | 条件つき・型3b・medium | D 候補(認証 401) | D 候補(二要素認証(書き込み)) | D 候補(422) | app/Http/Requests/PiggyBankUpdateRequest.php:105; app/Factory/PiggyBankFactory.php:125 |
| 12 | GET /recurring/show/{recurrence} | O(N)・型なし・high | B(認証 401) | B(二要素認証) | B(例外: 定期取引の項目名) | app/Http/Controllers/Recurring/ShowController.php:108; app/Repositories/Recurring/RecurringRepository.php:80 |
| 13 | GET /subscriptions/show/{bill?} | O(N)・型なし・high | B(認証 401) | B(二要素認証) | B(例外(要求が組めず)) | app/Http/Controllers/Bill/ShowController.php:136; app/Repositories/Bill/BillRepository.php:275 |
| 14 | GET /transactions/convert/{transactionType}/{transactionGroup} | O(N)・型1b+型3c・medium | B(引数) | B(引数) | B(例外: 取引グループが壊れている) | app/Http/Controllers/Transaction/ConvertController.php:110; app/Http/Controllers/Transaction/ConvertController.php:280 |
| 15 | GET /transactions/edit/{transactionGroup} | O(1)・型1b+型3a・high | D 候補(認証 401) | D 候補(二要素認証) | D 候補(例外: 空の参照) | app/Http/Controllers/Transaction/EditController.php:81; app/Http/Controllers/Transaction/EditController.php:96 |
| 16 | DELETE /api/v1/transactions/{transactionGroup} | O(N)・型2+型3a+型4・medium | B(Passport の鍵) | B(Passport の鍵) | A(ONM・傾き 0.9878) | app/Api/V1/Controllers/Models/Transaction/DestroyController.php:74; app/Services/Internal/Destroy/TransactionGroupDestroyService.php:42 |
| 17 | PUT /api/v1/transactions/{transactionGroup} | O(N)・型2+型3a+型4・medium | B(Passport の鍵) | B(Passport の鍵) | B(例外: 金額が NULL) | app/Api/V1/Controllers/Models/Transaction/UpdateController.php:83; app/Listeners/Model/TransactionGroup/ProcessesUpdatedTransactionGroup.php:57 |
| 18 | GET /api/v1/transactions/{transactionGroup}/attachments | O(N*M)・型3a・high | B(Passport の鍵) | B(Passport の鍵) | A(ON・傾き 0.7884) | app/Api/V1/Controllers/Models/Transaction/ListController.php:75; app/Repositories/Journal/JournalAPIRepository.php:68 |
| 19 | GET /api/v1/transactions/{transactionGroup}/piggy-bank-events | O(N*M)・型3a・high | B(Passport の鍵) | B(Passport の鍵) | A(ONM・傾き 0.9215) | app/Api/V1/Controllers/Models/Transaction/ListController.php:105; app/Repositories/Journal/JournalAPIRepository.php:90 |
| 20 | GET /piggy-banks | O(N)・型3a・high | B(認証 401) | B(二要素認証) | C(O(1)・応答不変) | app/Http/Controllers/PiggyBank/IndexController.php:81; app/Factory/PiggyBankFactory.php:213 |
| 21 | GET /piggy-banks/add-money/{piggyBank} | 条件つき・型3a・medium | D 候補(認証 401) | D 候補(二要素認証) | D 疑 | app/Http/Controllers/PiggyBank/AmountController.php:116; app/Repositories/PiggyBank/PiggyBankRepository.php:359 |
| 22 | GET /piggy-banks/add/{piggyBank} | 条件つき・型3a・medium | D 候補(認証 401) | D 候補(二要素認証) | D 疑 | app/Http/Controllers/PiggyBank/AmountController.php:76; app/Repositories/PiggyBank/PiggyBankRepository.php:359 |
| 23 | POST /piggy-banks/add/{piggyBank} | 条件つき・型1c+型3a・medium | D 候補(認証 401) | D 候補(二要素認証(書き込み)) | D 疑 | app/Http/Controllers/PiggyBank/AmountController.php:144; app/Repositories/PiggyBank/ModifiesPiggyBanks.php:96 |
| 24 | POST /transactions/convert/{transactionType}/{transactionGroup} | 条件つき・型1c+型2+型3a+型4・medium | D 候補(引数) | D 候補(引数) | D 疑 | app/Http/Controllers/Transaction/ConvertController.php:148; app/Http/Controllers/Transaction/ConvertController.php:196 |
| 25 | POST /transactions/destroy/{transactionGroup} | O(N)・型2+型3a+型4・medium | B(引数) | B(引数) | A(ONM・傾き 0.981) | app/Http/Controllers/Transaction/DeleteController.php:98; app/Support/Http/Controllers/UserNavigation.php:78 |
| 26 | GET /api/v1/rules/validate-expression | O(1)・型なし・high | D 候補(Passport の鍵) | D 候補(Passport の鍵) | D 確 | app/Api/V1/Controllers/Models/Rule/ExpressionController.php:44; app/Rules/IsValidActionExpression.php:45 |
| 27 | GET /json/box/available | O(1)・型なし・high | D 候補(認証 401) | D 候補(二要素認証) | D 候補(応答が空) | app/Http/Controllers/Json/BoxController.php:54 |

### T4.7 条件つき 7 本の別表

| # | ルート | 条件(T3) | R2 の分類 | 実測が言ったこと |
| ---: | --- | --- | --- | --- |
| 4 | PUT /api/v1/piggy-banks/{piggyBank} | 本文に accounts[] を付けたとき K 比例(口座ごとに find+残高検査+sync) / target_amount < 現在額 のとき removeAmountFr… | D 疑 | O(1) を示した(応答不変) |
| 7 | POST /api/v1/webhooks/{webhook}/submit | 設定 allow_webhooks が true のときだけ処理に入る(既定は false → 404) / 入った後は、送信待ち(未送信・未エラー)メッセージ W 件で W 比例 | D 候補(404) | 届かず(404) |
| 11 | POST /piggy-banks/update/{piggyBank} | accounts[] は required の配列(最低1件) / クエリは K 比例(検証で口座ごとに find+通貨+メタ+種別、更新で find+sync) / 表の行数で… | D 候補(422) | 届かず(422) |
| 21 | GET /piggy-banks/add-money/{piggyBank} | 同じ口座に多数の貯金箱が紐づいていること / 口座を少数に固定して貯金箱だけ増やす形なら O(N) / 口座も貯金箱も規模に比例して増える一様な紐づけなら K は一定で O(1) | D 疑 | O(1) を示した(応答不変) |
| 22 | GET /piggy-banks/add/{piggyBank} | 同じ口座に多数の貯金箱が紐づいていること / 口座を少数に固定して貯金箱だけ増やす形なら O(N) / 口座も貯金箱も規模に比例して増える一様な紐づけなら K は一定で O(1) | D 疑 | O(1) を示した(応答不変) |
| 23 | POST /piggy-banks/add/{piggyBank} | 本文に amount[<口座 id>] が 0 でない数値で入っていること / 入っていない口座は continue で飛ばされ、貯金箱の口座の読込(1 クエリ)とエラーの fl… | D 疑 | O(1) を示した(応答不変) |
| 24 | POST /transactions/convert/{transactionType}/{transactionGroup} | 本文に、対象の種別で有効な source_id・destination_id(または名前)が、仕訳 id をキーにして入っていること / 無効だと convertJournal … | D 疑 | O(1) を示した(応答不変) |

実測が増加を示した条件つきは 0 本。O(1) を示したのは 4・21・22・23・24 で、いずれも応答バイトが全規模で同一だった。条件(本文の形、同じ口座に多数の貯金箱など)が成り立ったかは、応答バイトが不変なものでは分からない。7 は 404、11 は 422 で届かなかった。

### T4.8 A の質

R2 の A は 5 本(5・16・18・19・25)。次数を T3 と比べた。

| # | ルート | T3 | 実測 | 次数 |
| ---: | --- | --- | --- | --- |
| 5 | DELETE /api/v1/rule-groups/{ruleGroup} | O(N) | ON(N 軸の傾き 0.8941) | 整合 |
| 16 | DELETE /api/v1/transactions/{transactionGroup} | O(N) | ONM(N 軸の傾き 0.9878・M 軸の傾き 0.8503) | 実質整合 |
| 18 | GET /api/v1/transactions/{transactionGroup}/attachments | O(N*M) | ON(N 軸の傾き 0.7884・M 軸の傾き 0) | 食い違い |
| 19 | GET /api/v1/transactions/{transactionGroup}/piggy-bank-events | O(N*M) | ONM(N 軸の傾き 0.9215・M 軸の傾き 0.9348) | 整合 |
| 25 | POST /transactions/destroy/{transactionGroup} | O(N) | ONM(N 軸の傾き 0.981・M 軸の傾き 0.839) | 実質整合 |

- **整合 2 本(5・19)**: T3 と実測の次数が一致した。
- **実質整合 2 本(16・25)**: T3 は O(N)、実測は `measured_ONM`(M の軸でも傾きが出た)。T3 の軸は「触れた口座の取引」で、測定器の M の軸の表(`transactions`)と同じ量である。N の軸を動かしても M の軸を動かしても同じ量が増えるので、2 つの軸で傾きが出るのは測定器の見え方であり、T3 と実測は食い違っていない。
- **食い違い 1 本(18)**: T3 は O(N*M)、実測は `measured_ON`。N の軸のみ増加(クエリ 17 → 35 → 215)で、M の軸(3・30・300)は平坦(19 のまま)、応答は 380 バイトで不変だった。積は確認できなかった。M 側(添付が仕訳に付く形)に、データの作り方で届いていない可能性がある。分類は規則どおり A(傾きが O(N) 以上)にした。

A になった 5 本のうち 4 本(5・16・18・19)は R1 では Passport の鍵で、25 は引数の規則で止まっていた。

静的が確定を言わず、実測が増加を見つけた(§T2 の `runtime_only`)本のうち、対象 27 本に入るのは 5・16・19・25(いずれも R2 の A)。

### T4.9 R1 の止まりの汎用化の見積り

R1(プロファイルなし)で止まった 27 本を、止まった理由ごとに数え、R2 でそれを解いたプロファイルの項目を並べた。「試作側の汎用の改善(アプリを問わない)で解けるか、アプリ固有の知識が要るか」は、**コードと記録を読んだ見積り**で、実装も、項目を 1 つずつ外した再測定もしていない。

| R1 の止まり | 本数 | ルート | R2 でそれを解いたプロファイルの項目 | 行 | 汎用の改善で解けるか |
| --- | ---: | --- | --- | --- | --- |
| 二要素認証の入力待ち(GET は 200 で 48 B・書き込みは 400 で 48 B) | 13 | 1・8・9・10・11・12・13・15・20・21・22・23・27 | values.users.mfa_secret=null | `poc/runtime/profiles/firefly.php:14-20(効くのは :19 の1行)` | 汎用で解ける見込み: 高 |
| Passport の公開鍵 | 10 | 2・3・4・5・7・16・17・18・19・26 | on_boot の 1(使い捨ての RSA 鍵を作って passport.public_key に設定) | `poc/runtime/profiles/firefly.php:43-54(鍵づくりは :48-54 の 7 行。:56-111 の Vite の差し替えは別項目)` | 汎用で解ける見込み: 高(アプリを問わず・パッケージ単位) |
| URL の引数を埋められず | 4 | 6・14・24・25 | arguments の 3 項目 | `poc/runtime/profiles/firefly.php:116-120(tagOrId :117・transactionType :118・transactionGroup :119)` | 一部だけ: 3 項目のうち 1 項目が汎用で解け、2 項目はアプリ固有の知識が要る |

合計 27 本(R1 で届いた 0 本の理由の全て)。**試作側の汎用の改善で止まりを越えられる見込みは 24 本、アプリ固有の知識が要るのは 3 本**(6・14・24)。

- **二要素認証の入力待ち**: GET は 200 で 48 B、書き込み(POST)は同じ要求が 400 で 48 B になる(client_error 400 の3本も同じ原因)。
  - 根拠: 原因はアプリ固有の知識でなく、生成器が NULL 可の列にも語を入れること(poc/runtime/profiles/firefly.php:16-19 のコメント)。二要素認証の中継ぎは、秘密の列が空でなければ有効とみなす(jc5/google2fa-laravel の Google2FA.php:125 isActivated)。書き込みで 400 になるのは同パッケージの Support/Response.php:36-44(GET 以外で入力が空なら 400)。試作側の既定として『ログインに使う利用者表の NULL 可の秘密・トークン系の列(名前に secret・token・key・two_factor・mfa・otp・recovery を含む)は語を入れず NULL にする』を足せば足りる。別のアプリ(koel)のプロファイルにも同じ型の項目がある(poc/runtime/profiles/koel.php:64-72: subsonic_api_key・two_factor_secret など)=2つのアプリで同じ型が出た。
  - 注意: 列名の一覧は経験則で、一覧にない名前の列は漏れる(その場合は今まで通りプロファイルで足す)。
- **Passport の公開鍵**: API の認証が公開鍵を読み、無いと『Invalid key supplied』で止まる(Passport が config('passport.public_key') を読む)。
  - 根拠: 鍵を読む場所は Passport の設定項目で Firefly III に固有でない(poc/runtime/profiles/firefly.php:44-47 のコメント)。試作側が『laravel/passport が入っているアプリ(composer.lock にある)なら、起動後に使い捨ての鍵を設定する』を持てば、Passport を使うアプリ全てで要らなくなる。アプリ固有の知識は要らない。要るのは『どの認証パッケージか』の検出だけ。
  - 注意: Passport 以外の認証パッケージは、それぞれ同じ型の足場が要る(Sanctum は鍵が要らない)。パッケージごとに1つずつ足す性質。
- **引数**: URL の引数(tagOrId・transactionType・transactionGroup)の値を、試作側の規則が埋められず止まる。
  - 根拠: transactionGroup(:119)は、名前そのものが表(transaction_groups)を指す。ArgumentResolver の規則 2 は名前の末尾が id のものしか表に当てない(ArgumentResolver.php:100-101)ので、『名前の変換候補(同 :168 tableCandidates)が既存の表に当たれば、その表の先頭の行の主キー』を足せば解ける。tagOrId(:117)は『番号でも名前でも受ける』独自の束縛(アプリの Tag.php routeBinder)を読まないと分からず、transactionType(:118)は withdrawal という語彙の知識が要る=アプリ固有。
  - 注意: 4 本(#6 tagOrId・#14 と #24 は transactionType と transactionGroup・#25 transactionGroup)のうち、汎用の改善だけで引数が埋まるのは #25 の 1 本。

R1 の止まりを越えた先で R2 のプロファイルがさらに足していた項目(R1 では手前の止まりに隠れて見えなかった): 時刻帯の列 13 表(poc/runtime/profiles/firefly.php:21-37・アプリ固有の型 SeparateTimezoneCaster)/ Vite の差し替え(:56-111・Vite を使う Laravel アプリ全般で汎用に解ける見込み)/ 行の意味を直す UPDATE 3 件(:129-145・アプリ固有)/ 本文が要る GET の問い(:124-126・アプリ固有寄り)。これらの個別の効きは未測定(1項目ずつ外した再測定はしていない)。

『止まりを越える見込み』は『届いて測れる』と同じではない。R2 でも全項目を入れて 18/27 で、残り 9 本は例外(データの形)・404・422 などで届かなかった。したがって汎用の改善で越えられる止まりの本数(上の表の合計)は、届く本数の上限の見立てにとどまる。

### T4.10 観察(数から言える事実)

- R1(プロファイルなし・本命): A÷(A+B+C) = 0/16 = 0.0%。届いた 0/27。
- R0(参照): A÷(A+B+C) = 0/16 = 0.0%。届いた 0/27。
- R2(プロファイルあり): A÷(A+B+C) = 5/16 = 31.2%。届いた 18/27。A 5・B 10・C 1。
- R2 で届いた 18 本の内訳は、A 5・B 4・C 1・D 7・D 候補 1。
- R2 の A 5 本のうち、次数が T3 と整合か実質整合なのは 4 本、食い違いは 1 本(18)。
- R2 の D 7 本は、確 2 本・疑 5 本。疑 5 本は、応答が全規模で同一で、条件が成り立ったか不明。
- R2 の C 1 本(20)の原因は、測定のデータの作り方(中間表が基底の 5 行のまま)だった。
- R1 の「届いていない応答の判定」(`measured_O1`・`no_info`)が 10 本あり、いずれも二要素認証の 48 バイト応答だった。判定欄だけを見ると R1 に `measured_O1` が出るが、分類は B か D 候補にした。

### T4 の記録

ルートごとの全データ(規模ごとの点・応答バイト・クエリ・傾き・根拠)は、作業置場の `T4_classification.json`、人が読む版は `T4_classification.md`。再計算は `T4_join.py`(入力の JSON から決定的に出る。2 回走らせて一致を確認した)。C の原因の行数の記録は同じ置き場の `C_rowcount/`。

この版は事実の記録で、**判断の材料(実測モードを本採用するかの材料)は書かない**。後の版で足す。
