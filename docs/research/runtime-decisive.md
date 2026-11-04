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

## T2'. 再測定

試作の汎用の改善(一般の Laravel アプリに効く 10 件。コミット 22 本)を `main` に取り込んだあとで、同じ測り方で測り直した。判定の規則(log-log 傾き)も D21 も変えていない。**この節は測定の記録で、分類 A〜E も A÷(A+B+C) も書かない**(T4' で別に出す)。

### T2'.1 取り込みと試験

- 取り込み: 汎用の改善のブランチの 22 本を、古い順に `git cherry-pick` した。衝突はなく、`main` の先端は取り込み前の 198cd50 から 0919bea になった。
- 試作の単体の試験(`RuntimeUnit`): **1229 本が PHP 8.2 と 8.3 のどちらも緑**。スキップ 0。
- 実測の採点の試験(`tests/Unit/Benchmarks`): 64 本が PHP 8.2 と 8.3 のどちらも緑。スキップ 0。

### T2'.2 構成と対象

| 構成 | 内容 |
| --- | --- |
| R1' | 汎用の改善が入った試作。プロファイルなし |
| R2' | R1' に、縮めた firefly のプロファイル(下の T2'.5)を足したもの |

| 対象の組 | 内容 |
| --- | --- |
| 新しい 24 本 | 判定用。静的解析が `≥O(N)` の下限を付けたコントローラのルートから、前回の 15 本を除いて決まりの規則で選んだ。実行した HTTP メソッドの組は 35 |
| 前回の 27 本 | 比較用(T1 の一覧)。実行した組は 33 |

規模は 10・100・1000(O(N*M) の判定の M の軸は前回と同じ仕組み)。1 要求の制限は 30 秒。データは試作の Generator の合成。URI が同じで HTTP メソッドが違うルートも当たるため、実行した組は一覧より多い。表の「一覧の組」は、一覧に載っているメソッドの組(新 24 本で 24 組、前回の 27 本で 27 組)。**新しい 24 本のために、プロファイルに項目を足していない**。届かない本は、届かない理由のまま記録した。

構成 2 × 対象 2 の 4 つの塊を、1 つずつ順に実行した(各塊の途中でコードは替えていない)。

### T2'.3 届いた本数

「届いた」は、応答が 2xx〜3xx で、認証・引数・例外・検証(422)・時間切れのどこにも止められず、二要素認証の 48 バイトの中継ぎでもないこと。**これはハンドラーに届いたことで、データが届いたことではない。** データが届いていない疑い(`data_not_arriving_suspected`)は別の付記で、届いたものの内数として数える(判定は付記では変わらない)。

新しい 24 本(判定用):

| 構成 | 組(一覧の組 / 実行した組) | 届いた(一覧の組 / 実行した組) | うち、データが届いていない疑い付き(一覧の組 / 実行した組) |
| --- | ---: | ---: | ---: |
| R1' | 24 / 35 | 0 / 0 | 0 / 0 |
| R2' | 24 / 35 | 12 / 19 | 3 / 5 |

前回の 27 本(比較用):

| 構成 | 組(一覧の組 / 実行した組) | 届いた(一覧の組 / 実行した組) | うち、データが届いていない疑い付き(一覧の組 / 実行した組) |
| --- | ---: | ---: | ---: |
| R1' | 27 / 33 | 0 / 0 | 0 / 0 |
| R2' | 27 / 33 | 17 / 22 | 5 / 8 |

前回(T2.2)の R2 は、27 本の一覧で 18 本、33 組で 23 組だった。今回は 17 と 22 で、一覧の組で 1 本少ない。差は `GET /piggy-banks`(下の T2'.4)。

- **R1' では、新旧どちらの組でも 0 本。** 二要素認証の中継ぎで止まるものはなくなった(中継ぎの 48 バイトは 0 本)。止まりの大半は、通貨の参照行が無いため `Amount::getSystemCurrency()` が型エラーになるもの(新 24 本の 35 組中 28、前回の 33 組中 30)。参照データはアプリごとの語彙で、汎用の改善では作れない。
- R2' で届いた組の判定(新 24 本の実行した 35 組): `measured_O1` 7・`measured_ON` 8・`no_info` 4・`not_measured` 16。一覧の 24 組では `measured_O1` 3・`measured_ON` 6・`no_info` 3・`not_measured` 12。傾きと値は構成別の表(作業置場の `T2p_summary.md/json`)に出した。
- 前回の 27 本の R2' の判定(実行した 33 組): `measured_O1` 9・`measured_ON` 7・`measured_ONM` 3・`inconclusive` 2・`no_info` 1・`not_measured` 11。

### T2'.4 届かなかった理由の内訳

R2'(プロファイルあり)の、届かなかった組の内訳。

| 理由 | 新 24 本(一覧の組 / 実行した組) | 前回の 27 本(一覧の組 / 実行した組) | 内容 |
| --- | ---: | ---: | --- |
| 取引の金額が無い(例外) | 5 / 5 | 3 / 4 | `Amount is NULL for a transaction in group #1`。取引グループに、出金・振替に必要な 2 行が揃わない |
| 認可・認証 | 2 / 4 | 0 / 0 | `api/v1/users/{user}`(`This action is unauthorized.`)と `settings/links`(ログイン画面へ)。生成した利用者がオーナーの役を持たない |
| 見つからない | 1 / 1 | 1 / 1 | 新: `POST /settings/users/destroy/{user}`。前回: `POST /api/v1/webhooks/{webhook}/submit` |
| ルールに起動の時機が無い | 0 / 2 | 0 / 0 | `Rule #1 has no valid trigger moment`(一覧の外のメソッド 2 組) |
| 入力の形(例外) | 2 / 2 | 0 / 0 | `bccomp(): Argument #1 is not well-formed`・`Undefined array key "accounts"` |
| 起動の条件(例外) | 1 / 1 | 0 / 0 | `GET /flush`: `This is not the moment.` |
| メッセージの無い 500 | 1 / 1 | 1 / 1 | `GET /bills/show/{bill}`・`GET /subscriptions/show/{bill?}` |
| その他の例外 | 0 / 0 | 3 / 3 | 定期取引の項目名・取引グループが壊れている・`Attempt to read property "type" on null` |
| 入力の検証(422) | 0 / 0 | 1 / 1 | `POST /piggy-banks/update/{piggyBank}` |
| **時間切れ(timeout)** | 0 / 0 | **1 / 1** | `GET /piggy-banks`: M の軸の規模 300 で 30 秒を超えた |

「時間切れ」は、測れなかった理由の分類語として他と別に数えた。`GET /piggy-banks` は、前回(T2.4)は中間表が 5 行のまま増えず、規模 10・100・1000 で一定と見えていた(`measured_O1`)。今回は中間表が親に合わせて増えるようになり、N の軸で規模 1000 の応答が約 1.3 MB まで育つ(クエリ数の傾き 0.99)が、M の軸の規模 300 は 30 秒に収まらず `not_measured`(`insufficient_points`)になった。

R1' の止まりは、新 24 本の 35 組が「通貨の型エラー 28・認可/認証 4・引数 2・見つからない 1」、前回の 33 組が「通貨の型エラー 30・引数 1・見つからない 2」。

### T2'.5 プロファイル(縮めた firefly)

`poc/runtime/profiles/firefly.php`。**全体 70 行(コメント・空行を除くと 45 行)**。前回の R2(全体 166 行・コメントと空行を除いて 117 行)から、試作が全アプリ向けにやるようになった分を削った。残った項目と理由:

| 項目 | 理由 | 対象アプリの場所 |
| --- | --- | --- |
| 引数 `tagOrId`(`tags` の `id`) | 数でも名前でも引ける独自の束縛。試作の規則が読めない | `app/Models/Tag.php`(routeBinder) |
| 引数 `transactionType`(`withdrawal`) | 語彙の引数。名前が表名と同じで、汎用の規則だと数が入って誤る | `routes/web.php`(`transactions/convert/{transactionType}/{transactionGroup}`) |
| `GET /api/v1/rules/validate-expression` に `expression` を付ける | 必須の入力が無いと 422 | 同ルートの入力検証 |
| `account_types` の 1 行目を `Asset account` にする | 型が既知でないと口座の表示が別のページへ送られる | `app/Http/Controllers/Account/ShowController.php:95` |
| `transaction_types` の 1 行目を `Withdrawal` にする | 型が既知でないと編集ページが口座のページへ送られる | `app/Http/Controllers/Transaction/EditController.php:82` |
| `transaction_currencies` の 1 行目を `EUR` にする | システム通貨をコード `EUR` で引く | `app/Support/Amount.php:332` |

前回のプロファイルから消えた分(試作が汎用でやる): `on_boot` の全て(Passport の使い捨て鍵・空の Vite の補助)・`values` の全て(`mfa_secret` を空・`_tz` 列を `UTC`)・引数 `transactionGroup`(引数名が表名ならその表の先頭行)。

### T2'.6 所要時間

| 塊 | 実行した組 | ドライバーの計時 | 実行全体(wall) |
| --- | ---: | ---: | ---: |
| R1' × 新 24 本 | 35 | 816 秒 | 830 秒 |
| R2' × 新 24 本 | 35 | 869.6 秒 | 883 秒 |
| R1' × 前回の 27 本 | 33 | 1126 秒 | 1140 秒 |
| R2' × 前回の 27 本 | 33 | 1290.6 秒 | 1305 秒 |
| **4 塊の合計** | | | **4158 秒(約 69 分)** |

環境の立ち上げと後始末を含む。塊は 1 つずつ順に実行した(完了の印のある塊は飛ばして再開できる作りにした)。

### T2'.7 記録

生の出力は、リポジトリの外の作業置場に、塊ごとのディレクトリ(`T2p_R1_new24/` など)で置いた(各塊の `routes.jsonl`・`attempts.jsonl`・`summary.json`・`report.md`)。構成別・組別・ルート別の表を、機械可読の `T2p_summary.json` と人が読む `T2p_summary.md` に出した。応答の行数は試作が記録しないので、バイト数と、読んだ表の行数・クエリ数で代えた。

**読み方の注。** (1) 「届いた」はハンドラーに届いたことで、データが届いたことではない。疑い付きのものは、規模によらず一定の値がデータの不到達による見かけの一定である可能性がある。(2) R1' の判定欄は全て `not_measured` で、届いた本数は 0。(3) 表の「実行した組」は、一覧に無い HTTP メソッドの組(同じ URI)を含む。一覧の組だけを見るときは「一覧の組」の列を使う。(4) 新 24 本と前回の 27 本は、同じアプリ・同じ構成・取り込み後の同じコードで測った。前回(T2)との比較は、今回の前回組の列を使う。

## T3'. 新しい 24 本のコードの答え

再測定の判定用に、前回の 15 本を除いた **新しい 24 本** の答えを、前回(§T3)と同じ方法で決めた。**実測(§T2')を見ずに**、コードを読んで決めた。答えの全文(軸・条件・根拠の `file:line`)は `benchmarks/real-apps/truth.json` の集合 `round2-new24` にある。ここは選び方と要約だけを書く。

### T3'.1 選び方

- Firefly III(コミット `d65e916`)の静的解析のうち、クエリ軸の下限が `≥O(N)` のコントローラのルートは 39 本。前回の 15 本(等間隔に選んだもの)を除いた残り 24 本を、ordinal 1〜24 として並べた(uri とメソッドの順)。選ぶ余地はなく、規則だけで決まる。
- 前回の抜き取り(下限の確認)の 1 本(`POST /piggy-banks/remove/{piggyBank}`)が、この 24 本の 16 番にあたる。この 1 本は、全体を追った答えを判定とし、前回の記録は情報として残した(`truth.json` の `earlier_records` と `cross_check`)。
- 対象アプリは読むだけで、ホストで `artisan`・`composer` を走らせていない。クエリ数は読解による。3 人の追い手が 8 本ずつ追った。

### T3'.2 集計

| 本当の増え方 | 本数 |
| --- | ---: |
| O(N) 以上(確度 high/medium) | 16 |
| 条件つき | 6 |
| O(1) | 2 |
| E(決めきれず) | 0 |

- **コードで O(N) 以上と決まったのは 16 本**(O(N) 12 が high、4 が medium)。この 16 本が §T4' の分母になる。条件つきの 6 本(3・6・13・14・16・17)は、条件が成り立つと増えるが成り立たなければ増えないので、前回と同じく分母に入れず別表にする。O(1) は 2 本(15・23)。
- 確度は high 15 本・medium 9 本・low 0 本。medium の理由は、イベントの購読先を追い切れていないこと、データの形(保存済みの順序、期間の統計の有無)に依ること。
- 追い手が見た、静的の下限の読み(参考。§T4 の採点の『下限の過大』とは物差しが違う): 正しい 16・一部 3・条件つき(下限が条件に乗る)3・過大 2。
- 軸の種類: 親 1 件の子の件数(添付・規則・貯金箱など。期間の数が別軸になる 1 本を含む)14 本、表全体(ユーザーを越える表・設定表・条件つきを含む)8 本、名前の一致で選ばれる行 1 本、軸なし(O(1))1 本。

### T3'.3 仕組みごとにまとまる本

| 仕組み | 本(ordinal) | 増え方 |
| --- | --- | --- |
| 添付ごとに `notes()->first()` を 1 回(1+A) | 2・4・7・9・12・21・22 | 親 1 件の添付 A。全件を読んでから呼び出し側が切るので、ページの大きさに依らない |
| `deleteEmptyGroups` が全グループを読み、1 グループにつき 1 クエリ(1+G) | 10・18・20 | アプリ全体の `user_groups` の総行数(削除する利用者の大きさではない) |
| 設定表 `link_types` | 19 | 設定表の行数。測るには `link_types` を直接増やさねばならず、実測が増加を示しても『データ量で増える』裏づけにならない(判定の O(N) 自体は変えない) |

条件つきの 6 本: 3(本文で予算の名前を変えたとき、旧名を値に持つ規則の行に比例)・6 と 17(保存済みの `order` が連番でないとき、ずれた貯金箱に比例)・13(名前変更と旧名一致の行)・14(`balance_dirty` の行)・16(0 でない金額の入った口座の数)。**3 と 6 の条件が成り立つかは、生成器が作るデータの形に依る。**

## T4'. 答えと再測定の突き合わせ(分類 A〜E)

§T3'(新 24 本の答え)・§T3(前回 27 本の答え)と、§T2'(再測定)を、構成 R1'・R2' 別に突き合わせて A〜E に振った。分類器は前回の `T4_join.py` の規則を変えず、入力だけ差し替えた(`T4p_join.py`。同じ入力で同じバイトが出る)。判定の規則(log-log 傾き)も D21 も変えていない。**答え(§T3')は測定に合わせて直していない。** 新 24 本が判定用、前回の 27 本は比較として並べる。

### T4'.1 規則(前回と同じ)

| 分類 | 条件 |
| --- | --- |
| A | コードで O(N) 以上 かつ 実測が増加 |
| B | コードで O(N) 以上 かつ 実測が届かず、または情報なし。理由を記録する |
| C | コードで O(N) 以上 かつ 実測が一定(`measured_O1`)=偽の O(1) |
| D | コードで O(1) か条件つき かつ 実測が O(1) か増加。確=コードで O(1) と出て実測も O(1)、または条件つきで実測が増加。疑=条件つきで応答バイトが全規模で同一 |
| D 候補 | コードで O(1) か条件つき かつ 実測が届かず何も言えぬもの。D の本数には入れない |
| E | コードでも決めきれない(今回 0 本) |

分母 A+B+C は、コードで O(N) 以上の本(新 24 本も前回の 27 本も **16 本**)。条件つきと O(1) は分母に入れない。『届いた』はハンドラーに届いたことで、データが届いたことではない。

### T4'.2 集計

| 組 | 構成 | 届いた | A | B | C | D | D候補 | E | A÷(A+B+C) |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| **新 24 本** | R1' | 0/24 | 0 | 16 | 0 | 0 | 8 | 0 | 0/16 = 0.0% |
| **新 24 本** | R2' | 12/24 | 6 | 9 | 1 | 2(確 1/疑 1) | 6 | 0 | 6/16 = 37.5% |
| 前回の 27 本 | R1' | 0/27 | 0 | 16 | 0 | 0 | 11 | 0 | 0/16 = 0.0% |
| 前回の 27 本 | R2' | 17/27 | 8 | 8 | 0 | 7(確 3/疑 4) | 4 | 0 | 8/16 = 50.0% |

前回の 27 本の R2'(17 本が届いた)は、前回の R2(18 本)と比べて、B→A が 4 本(添付の 3 本 `GET /api/v1/bills/{bill}/attachments`・`GET /api/v1/categories/{category}/attachments`・`GET /api/v1/tags/{tagOrId}/attachments` と、`POST /groups/destroy/{objectGroup}`)、A→B が 1 本(`GET /api/v1/transactions/{transactionGroup}/attachments`。判定が `inconclusive`)、C→B が 1 本(`GET /piggy-banks`。時間切れ)。

層別(前回の 27 本のみ。新 24 本は全て `≥O(N)` 層):

| 構成 | 層 | 本数 | A | B | C | D | D候補 | A÷(A+B+C) |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| R1' | O(N)層 | 15 | 0 | 10 | 0 | 0 | 5 | 0/10 = 0.0% |
| R1' | O(N*M)層 | 10 | 0 | 6 | 0 | 0 | 4 | 0/6 = 0.0% |
| R1' | 対照 | 2 | 0 | 0 | 0 | 0 | 2 | 分母ゼロ |
| R2' | O(N)層 | 15 | 5 | 5 | 0 | 2(確 2/疑 0) | 3 | 5/10 = 50.0% |
| R2' | O(N*M)層 | 10 | 3 | 3 | 0 | 4(確 0/疑 4) | 0 | 3/6 = 50.0% |
| R2' | 対照 | 2 | 0 | 0 | 0 | 1(確 1/疑 0) | 1 | 分母ゼロ |

### T4'.3 B の理由

| 理由 | 新 24 本 R1' | 新 24 本 R2' | 前回 27 本 R1' | 前回 27 本 R2' |
| --- | ---: | ---: | ---: | ---: |
| 通貨の型エラー(getSystemCurrency・システム通貨の参照行が無い) | 11 | 0 | 14 | 0 |
| argument | 2 | 0 | 1 | 0 |
| auth | 2 | 2 | 0 | 0 |
| not_found | 1 | 1 | 1 | 0 |
| 例外(その他) | 0 | 6 | 0 | 6 |
| 届いたが判定が増加でも一定でもない(reached(inconclusive)) | 0 | 0 | 0 | 1 |
| **時間切れ(別行)** | 0 | 0 | 0 | 1 |
| 計(B) | 16 | 9 | 16 | 8 |

- **R1' の B は通貨の型エラーに集中している。** 新 24 本では、届かなかった 24 本のうち 19 本(B 16 本の中の 11 本、D 候補 8 本の中の 8 本)が、`Amount::getSystemCurrency()` の型エラーで止まった。前回の 27 本でも 27 本中 24 本。システム通貨(コード `EUR`)の参照行が無いと null が返る、アプリごとの語彙の参照データの欠落で、**汎用の改善で作れない**(§T2'.3)。
- 読み違えぬこと: これは『汎用の改善が効かなかった』ことを意味しない。汎用の改善が解いた止まり(二要素認証の中継ぎ・公開鍵)は R1' で 0 件になり、引数の埋めの止まりも、独自の束縛を持つ `tagOrId` の 2 本(新 24 本)と 1 本(前回の 27 本)だけに減った。その先にある、アプリ固有の参照データという壁が、止まりの大半(新 24 本で 24 本中 19 本)として現れた。
- R2'(プロファイルあり)で新 24 本の B 9 本は、認可・認証 2(`DELETE /api/v1/users/{user}`・`GET /settings/links`)・見つからない 1(`POST /settings/users/destroy/{user}`)・取引の金額が無い例外 5・メッセージの無い 500 が 1。
- 時間切れ型は前回の 27 本の `GET /piggy-banks`(M の軸の規模 300 が 30 秒を超えた)の 1 本で、新 24 本には無い。

### T4'.4 C(偽の O(1))とその原因

C は R1' で新旧とも 0 本。R2' では新 24 本に **1 本**(前回の 27 本は 0)。

**R2' #18 `POST /profile/delete-account`(答え O(N)・高)**: 実測は `measured_O1`、N 軸のクエリ数 [18, 18, 18]、応答は 426 バイトで全規模同一、T2' が『データが届いていない疑い』を付けた。

- 原因(測定側の入力): このルートは、本文の `password` が現在のパスワードと一致しなければ、削除に進まず `profile.delete-account` へ戻す(フォームの検証と、`ProfileController.php` の `Hash::check` の分岐)。測定器が送った本文のパスワードでは削除に進めなかったと読める(送った本文の記録は無く、根拠は次の 2 点)。応答は 302(戻し)の 426 バイトで、全規模で同一。
- 生の出力の根拠: 3 回の試行のクエリ数は規模によらず 44・18・14 の同じ並びで、`user_groups` を読んだ行数は **どの規模でも 1**。答えの重い経路(`UserGroup::get()` で全グループを読み、グループごとに 1 クエリ)は、一度も走っていない。1+G の形にならない。
- 答えの側は動かさない。§T3' の #18 には、パスワードの一致と内部認証が『到達の要件で、仕事量を決める入力ではない』と既に書いてある。これは、答えと実測の食い違いではなく、実測が重い経路に届かなかった例である。試作の判定は `measured_O1` と言ったが、同じ記録に『データが届いていない疑い』が付いている。
- 同じ仕組み(`deleteEmptyGroups`)の #10・#20 は、R2' でも認可・見つからないで止まり、B のまま。#18 だけが、ハンドラーに届いたうえで早期の戻しに終わった。
- 直さない。提案: 本文の必須入力に利用者のパスワードを要するルートは、測定器が作った利用者のパスワードを知っていれば送れる。アプリごとに欄の名前が違うため、汎用の改善とするかプロファイルに足すかは別に決める。

### T4'.5 D の型(確/疑)

| 組 | 構成 | D | 確 | 疑 | D候補 |
| --- | --- | ---: | --- | --- | ---: |
| 新 24 本 | R1' | 0 | なし | なし | 8 |
| 新 24 本 | R2' | 2 | #15 | #16 | 6 |
| 前回 27 本 | R1' | 0 | なし | なし | 11 |
| 前回 27 本 | R2' | 7 | #4・#10・#26 | #21・#22・#23・#24 | 4 |

- 新 24 本の R2' の D 2 本: 確は #15(`GET /piggy-banks/remove-money/{piggyBank}`。コードで O(1)・実測も O(1)。型5)、疑は #16(`POST /piggy-banks/remove/{piggyBank}`。条件つき・実測 O(1)・応答 382 バイトが全規模同一。型1c)。
- D 候補 6 本: 実測が空の応答か、入力の形・起動の条件で止まった(#3・#13・#23 は応答が空、#6 は `bccomp(): Argument #1 is not well-formed`、#14 は `This is not the moment.`、#17 は `Undefined array key "accounts"`)。
- 型は、答え(§T3')の型1b・1c・3a・3b・5 を統一名で付けた。D の分け方は A÷(A+B+C) を変えない。
- 補足: 実測の採点の道具 `score.php` は、D の確/疑の分け方が前回の分類器より厳しい(条件つきで実測が O(1) のものは、応答バイトが変わっても『疑』にする)。前回の 27 本では #4(`PUT /api/v1/piggy-banks/{piggyBank}`)の 1 本だけ分類器の確が道具の疑になり、確 3/疑 4 と確 2/疑 5 の差になる。新 24 本は同じ数(確 1/疑 1)。A・B・C・D・D 候補の本数は一致する。本節の確/疑は前回の分類器の規則のまま。

### T4'.6 疑い付きの到達

T2' が『データが届いていない疑い』を付けた到達は、**A に数えず別掲**する(分類そのものは規則どおり。分母は動かさない)。

- 新 24 本 の R2'(3 本): #3→D_candidate(no_info)、#13→D_candidate(no_info)、#18→C(measured_O1)。うち A は 0 本。A÷(A+B+C): 疑い付きを A に含めた場合 6/16 = 37.5%、疑い付きの A を除いた場合 6/16 = 37.5%。
- 前回 27 本 の R2'(5 本): #21→D(measured_ON)、#22→D(measured_ON)、#23→D(measured_O1)、#26→D(measured_O1)、#27→D_candidate(no_info)。うち A は 0 本。A÷(A+B+C): 疑い付きを A に含めた場合 8/16 = 50.0%、疑い付きの A を除いた場合 8/16 = 50.0%。
- 新 24 本の疑い付き 3 本は #3(D 候補)・#13(D 候補)・#18(C)。前回の 27 本の 5 本は #21・#22・#23・#26(D)と #27(D 候補)。**A に疑い付きは無い**ので、A÷ は含めても除いても同じ。R1' に疑い付きは無い(届いた本数が 0)。

### T4'.7 仕組み別の束(新 24 本の ordinal)

| 束 | 本 | R1' の A/B/C | R2' の A/B/C |
| --- | --- | --- | --- |
| 添付 1+A(添付ごとに notes()->first()) | #2・#4・#7・#9・#12・#21・#22 | 0/7/0 | 4/3/0 |
| deleteEmptyGroups 共有(全グループを読み 1+G) | #10・#18・#20 | 0/3/0 | 0/2/1 |

- 添付 1+A の 7 本のうち R2' で A になったのは 4 本(#2・#4・#7・#9。傾き 0.63〜0.66)。B の 3 本は #12(`GET /budgets/show/{budget}`)・#21・#22(`GET /tags/show/...`)で、取引の金額が無い例外で止まった(添付の経路そのものではない)。
- `deleteEmptyGroups` 共有の 3 本は A が 0 本。#10 は認可、#20 は見つからない、#18 は C(§T4'.4)。
- #19(`GET /settings/links`)は束に入れず、R2' でも認可で止まり B。**設定表 `link_types` を増やさないと測れず、実測が増加を示しても『データ量で増える』裏づけにならない**(判定の O(N) 自体は変えない)。

### T4'.8 条件つき 6 本の別表

| # | ルート | R1' | R2' | 実測が言ったこと |
| --- | --- | --- | --- | --- |
| 3 | PUT /api/v1/budgets/{budget} | D候補 | D候補 | 届かず(suspect_empty(応答が空・生成行が届かず)) |
| 6 | POST /api/v1/piggy-banks | D候補 | D候補 | 届かず(exception: ValueError: bccomp(): Argument #1 ($num1) is not ) |
| 13 | POST /budgets/update/{budget} | D候補 | D候補 | 届かず(suspect_empty(応答が空・生成行が届かず)) |
| 14 | GET /flush | D候補 | D候補 | 届かず(exception: FireflyIII\Exceptions\FireflyException: This is n) |
| 16 | POST /piggy-banks/remove/{piggyBank} | D候補 | D | O(1) を示した(応答不変) |
| 17 | POST /piggy-banks/store | D候補 | D候補 | 届かず(exception: ErrorException: Undefined array key "accounts") |

- 条件つきは分母に入れない。R2' で届いて O(1) を示したのは #16 の 1 本だけで、応答が全規模同一のため『疑』とした(金額の入った口座が無かった可能性)。他の 5 本は届かず、D 候補。
- **#6 と #3 は、条件が成り立つかが生成器依存。** #6(`POST /api/v1/piggy-banks`)の重さは、保存済みの `order` が連番から外れているかで決まり、#3(`PUT /api/v1/budgets/{budget}`)は、旧名と同じ値を持つ規則の行が生成されるかで決まる。生成器がそれらを作るかは、実測の記録からは分からない(#3 は R2' で、規模を振った表を一度も読んでいない疑い付き)。条件つきの答えは、実測が O(1) でも矛盾しない。

### T4'.9 前回の記録との交差検証(新 24 本の 16 番)

`POST /piggy-banks/remove/{piggyBank}`(前回の抜き取り S10)。
- 前回の記録: メモリは口座の数に比例する下限が正しい。クエリは下限が付いておらず低すぎで、実際はクエリも金額の入った口座の数に比例(O(N) 以上)と読んでいた。
- 全体を追った答え(判定): **条件つき**。リクエストの `amount[]` で 0 でない金額が入った口座の数 K に比例(口座ごとに pivot を引く 1 クエリ・pivot の UPDATE・イベント)。確度 medium。
- 照合: 次数は同じ(1 次)。前回の記録は条件を言わず O(N) と読み、全体を追った答えが条件を足した。**答えは全体を追ったほうを判定とし、前回の記録は情報のみ**(動かさない)。
- 実測: R1' は通貨の型エラーで届かず(D 候補)。R2' は届いて `measured_O1`、応答 382 バイトが全規模で同一 → **D(疑)**。条件(金額の入った口座)が成り立ったかは、この実測からは分からない。

### T4'.10 プロファイルの行数

`poc/runtime/profiles/firefly.php`: 全体 **166 行 → 70 行**、コメントと空行を除いて **117 行 → 45 行**(§T2'.5)。**新 24 本のために項目は足していない。** 残った項目は 6 つ(独自の束縛の引数・語彙の引数・必須入力・口座の型と取引の型の先頭行・システム通貨 `EUR`)。

### T4'.11 実測の採点(`score.php`)

`benchmarks/real-apps/score.php --runtime=...` で、R1'・R2' の実測の出力(`routes.jsonl`・`attempts.jsonl`)を、`truth.json` の答えに当てて採点した。新 24 本は集合 `round2-new24`、前回の 27 本は `round1-target` と `round1-control`。

| 組 | 構成 | 届いた | A | B | C | D(確/疑) | D候補 | A÷(A+B+C) |
| --- | --- | ---: | ---: | ---: | ---: | --- | ---: | --- |
| 新 24 本 | R1' | 0/24 | 0 | 16 | 0 | 0(0/0) | 8 | 0/16 = 0.0% |
| 新 24 本 | R2' | 12/24 | 6 | 9 | 1 | 2(1/1) | 6 | 6/16 = 37.5% |
| 前回 27 本 | R1' | 0/27 | 0 | 16 | 0 | 0(0/0) | 11 | 0/16 = 0.0% |
| 前回 27 本 | R2' | 17/27 | 8 | 8 | 0 | 7(2/5) | 4 | 8/16 = 50.0% |

- A・B・C・D・D 候補の本数は、§T4'.2 の分類器の出力と全て一致した。D の確/疑だけ、上のとおり道具のほうが厳しい(前回の 27 本の R2' で 確 2/疑 5)。
- 静的の基準値との対応: 静的の採点(`score.php --analysis`)は軸ごとの次数の物差しで、実測の採点はルートごとの A〜E。**数を混ぜない。** 新 24 本を足した静的の採点は、`round2-new24` が 39 軸・採点 39・一致 28・unknown 0・下限の過大 5・過大な言い切り 0・見逃し 0(基準値 73 軸 / 71 / 44 / 5 / 16 / 0 / 0 は不変。`real-app-scoring.md` の『新 24 本を足した後』)。同じ 24 本の実測は、R2' で A 6・C 1(上表)。静的の『下限の過大』は次数だけで数えた物差しで、§T3 の『静的の下限の判定』(正しい/一部/過大)や、前回の抜き取りの『24 軸中 8 軸が過大』とは別物である。

### T4'.12 D52 の基準への機械的な当てはめ

数と不等号の成否だけを書く。『見込み高/見送り妥当/間』の言い回しは、判断の材料として別に書く。

| 組 | R1' の A÷(A+B+C) | 50% を超える | R2' の A÷(A+B+C) | 30% 未満 | 両方に該当せず(その間) |
| --- | --- | --- | --- | --- | --- |
| **新 24 本(判定用)** | 0/16 = 0.0% | 成り立たぬ | 6/16 = 37.5% | 成り立たぬ | はい |
| 前回 27 本(比較) | 0/16 = 0.0% | 成り立たぬ | 8/16 = 50.0% | 成り立たぬ | はい |

### T4'.13 記録

分類器 `T4p_join.py` と、その出力 `T4p_classification.json/.md`、実測の採点の出力は、リポジトリの外の作業置場に置いた。ここには要約だけを書いた。生の出力・分類器は取り込まない。

## T2-B. BookStack の本測定

第二のアプリ BookStack(Laravel 製の文書 wiki)を、Firefly III と同じ測り方で測った。判定の規則(log-log 傾き)も D21 も変えていない。試作は `main` の先端(単一表継承のアプリでも、型を宣言しないファイルや、宣言した型と道筋の合うファイルだけを引く直しを含む)のまま使った。**この節は測定の記録で、分類 A〜E も A÷(A+B+C) も書かない**(答え合わせは別に出す)。

### T2-B.1 構成と対象

| 構成 | 内容 |
| --- | --- |
| R1 | プロファイルなし。合否に使う |
| R2 | R1 に、最小のプロファイル(下の T2-B.5)を足したもの |

対象は、静的解析が下限(`≥O(N)` か `≥O(N*M)`)を付けたコントローラのルート 44 本(`≥O(N)` 42・`≥O(N*M)` 2。対照の `O(1)` はこのアプリには 0 本)。URI が同じで HTTP メソッドが違うルートも当たるため、実行した組は 69。規模は 10・100・1000(O(N*M) の M の軸は前回と同じ仕組み)。1 要求の制限は 30 秒。データは試作の Generator の合成。構成 2 つを 1 つずつ順に実行した。

### T2-B.2 届いた本数

「届いた」は、応答が 2xx〜3xx で、認証・引数・例外・検証(4xx)・時間切れのどこにも止められず、二要素認証の 48 バイトの中継ぎでもないこと。**これはハンドラーに届いたことで、データが届いたことではない。** データが届いていない疑い(`data_not_arriving_suspected`)は別の付記で、届いたものの内数として数える(判定は付記では変わらない)。

| 構成 | 一覧の組 | 届いた | うち疑い付き | 実行した組 | 届いた | うち疑い付き |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| R1 | 44 | 6 | 5 | 69 | 12 | 9 |
| R2 | 44 | 25 | 10 | 69 | 39 | 20 |

時間切れ(timeout)で測れなかった組は R1・R2 とも 0。

### T2-B.3 届かなかった理由の内訳

| 理由 | R1(一覧の組) | R2(一覧の組) | 内容 |
| --- | ---: | ---: | --- |
| 引数が埋まらない | 31 | 0 | 道筋の引数(スラッグ・番号・語彙)を試作の規則が埋められない |
| 認証 | 3 | 12 | R2 の 12 は `/api/*` の全て。API トークンの要る組で、トークンはプロファイルの語彙に無い |
| 例外 | 3 | 4 | 説明の無い 500: `DELETE /my-account`・`POST /register`・`DELETE /settings/users/{id}`(R1・R2 共通)、R2 のみ `GET /login/service/{socialDriver}/callback` |
| 見つからない | 0 | 2 | `POST /books/{bookSlug}/copy`(規模 0 の空の系では本が無い)・`GET /books/{bookSlug}/export/zip`(下の注) |
| 入力の検証(422) | 1 | 1 | `POST /settings/users/create` |

届いた組の判定(一覧の 44 組): R1 は `measured_ON` 1・`no_info` 5・`not_measured` 38。R2 は `measured_O1` 7・`measured_ON` 8・`inconclusive` 1・`no_info` 9・`not_measured` 19。傾きと値は構成別・ルート別の表(作業置場)に出した。

### T2-B.4 所要時間

| 塊 | 実行した組 | ドライバーの計時 | 実行全体(wall) |
| --- | ---: | ---: | ---: |
| R1 | 69 | 316.9 秒 | 332 秒 |
| R2 | 69 | 822.5 秒 | 837 秒 |
| **合計** | | | **1169 秒(約 19.5 分)** |

環境の立ち上げと後始末を含む。

### T2-B.5 プロファイル(最小)

`poc/runtime/profiles/bookstack.php`。**全体 106 行(空行を除いて 99 行、うちコメント 9 行。`after_generation` の骨組み約 20 行は他のプロファイルと同じ)**。項目は 20(引数 9・生成後の補正 11)。答えに合わせず、ハンドラーに届いたか否かだけを見て足した。

| 区分 | 項目 | 汎用/アプリ固有 |
| --- | --- | --- |
| 引数 | `id`(行 1 は存在する) | 汎用 |
| 引数 | `revId` | 値は汎用、結び付けはアプリ固有 |
| 引数 | `slug`・`bookSlug`・`chapterSlug`・`pageSlug`・`pageId`・`socialDriver`・`category` | アプリ固有(独自の検索キー・語彙) |
| 補正 | 単一表継承の型・親子・権限の事前計算表・ページ本体の表・コンテナの説明の表・管理者の役・画像の型・版の紐付け(11 項目) | アプリ固有 |

項目ごとの理由と対象アプリの場所は別に記録した(作業置場 `T2_profile_items.md`)。**API トークン(`/api/*`)と `PUT` の本文は、プロファイルの語彙に無いため足していない**(足せば汎用の改善にあたる)。

### T2-B.6 記録と読み方の注

生の出力は、リポジトリの外の作業置場に、塊ごとのディレクトリ(`R1/`・`R2/`)で置いた。R2 は最初のプロファイルで一度走らせた(コンテナの説明の表の補正を足す前。740 秒)。その結果では、`POST /settings/maintenance/regenerate-references` が R1 の `ok` から `exception` へ退行し、`PUT` 3 本(本、章、棚の更新)も説明の列が NULL になる例外(`description` 列の NOT NULL 違反)で止まった。説明の行が無いことが共通の原因だったので、補正 2 項目(届いたか否かだけを見た足し)を加えて取り直した。取り直した R2 では `PUT` 3 本とも 302 で届き、この例外は 0 件である。**最初の R2 は集計に入れていない**(参考として別に残した)。

**読み方の注。** (1) 「届いた」はハンドラーに届いたことで、データが届いたことではない。疑い付きの一定は、データの不到達による見かけの一定である可能性がある。(2) `/api/*` が R2 でも届かないのは、プロファイルの語彙にトークンが無いため(試作の届かせる力の限り)。(3) 表の「実行した組」は、一覧に無い HTTP メソッドの組(同じ URI)を含む。

### T2-B.7 見つかった試作の不具合と直し

`GET /books/{bookSlug}/export/zip` は R2 で規模 0 の 404 の 1 件だけが記録され、規模 10 以降の試行が 1 件も残らなかった(各規模は測っていた)。子プロセスの生の標準出力を取って確かめた: アプリが ZIP をそのまま標準出力へ書き(出力の捕捉を外す)、ZIP は改行で終わらないため、直後に出す JSON の 1 行が行頭に来ず、親が `{` で始まる行だけを読む仕組みのせいで読み落としていた。**試作の記録の不具合で、判定を軽くする方向の直しではない。** 各試行の行を改行で始めるようにし(`poc/runtime/src/Run/MeasureOne.php`)、試験を 2 本足した(直す前は 2 本とも落ちる)。`RuntimeUnit` は PHP 8.2・8.3 の両方で 1233 本が緑(スキップ 0)、実測の採点の試験(`tests/Unit/Benchmarks`)は両方で 67 本が緑。`src/` は変えていない(静的解析の採点の総計は変わらない)。

このルートだけを R2 で測り直した(元の R2 は書き換えず、別の置き場に出した)。

| | 直す前 | 直した後 |
| --- | --- | --- |
| 残った試行 | 規模 0 の 404 のみ | 規模 0 の 404 と、規模 10・100・1000 の各 3 試行 |
| クエリ数(規模 10・100・1000) | - | 16・124・1204 |
| 傾き / 判定 | - / `not_measured` | 0.9872 / `measured_ON` |

なお、この応答のバイト数は 0 と記録される(アプリが出力を直に書くため、試作が数えられない)。R1 はこのルートの引数が埋まらず、届かない。元の表の数字は直す前のまま残し、直した後の 1 本を足すと R2 の届いた本数は一覧の組で 26 本になる。

## T4-B. BookStack の答え合わせ(分類 A〜E)

§T3(BookStack の 44 本のコードの答え。測定の前に固定)と、§T2-B(本測定)を突き合わせて A〜E に振った。分類器は前回の `T4p_join.py` の規則を変えず、入力だけ差し替えた(同じ入力で同じバイトが出ることを 2 回の実行で確かめた)。判定の規則(log-log 傾き)も D21 も変えていない。**答えは測定に合わせて直していない**(固定したときに記録した答えと道筋の追跡 7 ファイルの sha256 は、答え合わせの冒頭で照合し全て一致した)。

### T4-B.1 規則と分母

規則は §T4'.1 と同じ(A=コードで O(N) 以上かつ実測が増加/B=同かつ届かず、または情報なし/C=同かつ実測が一定/D=コードで O(1) か条件つきで、実測が O(1) か増加/D 候補=届かず何も言えぬもの/E=決めきれぬもの)。分母 A+B+C は「コードで追って O(N) 以上と決まった本」で、条件つきと O(1) は入れない。

| コードの答え | 本数 | 内訳 |
| --- | ---: | --- |
| O(N) 以上(確度 high/medium) | **10** | O(N)・high 8(一覧の 1,2,3,12,17,22,32,34)、O(N)・medium 1(33)、O(N*M)・medium 1(15) |
| 条件つき | 21 | 確度 high 10・medium 11 |
| O(1) | 13 | 全て high |
| E(決めきれぬ) | 0 | |

- **分母の経緯。** 答えを固定する前に、`POST /api/imports/{id}`(5)を「O(N) の決め打ち」から「条件つき(取り込む ZIP の要素数が軸)」に直した。本文・入力の要素数が軸なら条件つき、という前回までの規則に合わせたもので、直す前の分母は 11 本だった。同じ規則で 10・36・38(O(1) から条件つき)も直したが、分母には効かない。**いずれも測定の前の直しで、測定の結果を見て動かしたものではない。**
- 36 と 38 の「届く条件」の文言に「無し」が残る(本来は本文に K 件渡すこと)。値・分母には効かない。分類では、この 2 本は条件つきの記述(`conditional_on`)を正とした。
- 構成は R1(プロファイルなし。合否に使う)・R2(最小のプロファイル。記録用)。R2 には「測ったまま」と「zip の直し(各試行の行を改行で始める修正。§T2-B.7)の後に測り直した 1 本を足したもの(R2z)」の 2 つの値がある。以下、**表には毎回どちらで数えたかを書く**。

### T4-B.2 集計

| 構成 | 届いた | A | B | C | D(確/疑) | D候補 | E | A÷(A+B+C) |
| --- | ---: | ---: | ---: | ---: | --- | ---: | ---: | --- |
| **R1**(合否に使う) | 6/44 | 1 | 9 | 0 | 0 | 34 | 0 | **1/10 = 10.0%** |
| R2(測ったまま。届いた 25) | 25/44 | 4 | 6 | 0 | 11(確 6/疑 5) | 23 | 0 | 4/10 = 40.0% |
| R2z(zip の測り直しを足す。届いた 26) | 26/44 | 5 | 5 | 0 | 11(確 6/疑 5) | 23 | 0 | 5/10 = 50.0% |

層別(静的の層で分けた。O(N) 層 42 本・O(N*M) 層 2 本。コードの答えが O(N*M) の 15 は、静的の層では O(N) 層に入る):

| 構成 | 静的の層 | 本数 | A | B | C | D | D候補 | A÷(A+B+C) |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| R1 | O(N) 層 | 42 | 1 | 9 | 0 | 0 | 32 | 1/10 = 10.0% |
| R1 | O(N*M) 層 | 2 | 0 | 0 | 0 | 0 | 2 | 分母ゼロ |
| R2 | O(N) 層 | 42 | 4 | 6 | 0 | 10 | 22 | 4/10 = 40.0% |
| R2 | O(N*M) 層 | 2 | 0 | 0 | 0 | 1 | 1 | 分母ゼロ |
| R2z | O(N) 層 | 42 | 5 | 5 | 0 | 10 | 22 | 5/10 = 50.0% |
| R2z | O(N*M) 層 | 2 | 0 | 0 | 0 | 1 | 1 | 分母ゼロ |

(静的の O(N*M) 層の 2 本は、答えでは条件つき(43・44)で分母に入らない。)

**R2 の 25 と 26 の取り違えについて。** R2 の元の出力(25 本)は zip の記録を直す前の値で、`GET /books/{bookSlug}/export/zip`(17)は規模 0 の 404 だけが残り、B(見つからない)だった。直した後にこの 1 本だけを測り直した(クエリ 16・124・1204、傾き 0.9872、増加)ので、R2z では A になる。**R1 の値には効かない**(R1 はこのルートの引数が埋まらず、測り直しの対象でもない)。したがって合否の値は変わらない。

### T4-B.3 分類(分母の 10 本)

| # | ルート | 答え | R1 | R2(測ったまま) | R2z |
| ---: | --- | --- | --- | --- | --- |
| 1 | DELETE /api/books/{id} | O(N)・high | B 引数 | B 認証 | B 認証 |
| 2 | GET /api/books/{id}/export/zip | O(N)・high | B 認証 | B 認証 | B 認証 |
| 3 | DELETE /api/chapters/{id} | O(N)・high | B 引数 | B 認証 | B 認証 |
| 12 | DELETE /books/{bookSlug}/chapter/{chapterSlug} | O(N)・high | B 引数 | **A**(傾き 0.8414) | A |
| 15 | POST /books/{bookSlug}/copy | O(N*M)・medium | B 引数 | B 見つからない(下の注) | B |
| 17 | GET /books/{bookSlug}/export/zip | O(N)・high | B 引数 | B 見つからない | **A**(傾き 0.9872) |
| 22 | DELETE /books/{id} | O(N)・high | B 引数 | **A**(0.8365) | A |
| 32 | DELETE /settings/maintenance/cleanup-images | O(N)・high | B 届いたが情報なし(応答が空の系と同じ) | **A**(0.9925) | A |
| 33 | POST /settings/maintenance/regenerate-references | O(N)・medium | B 届いたが情報なし | B 届いたが判定が増加でも一定でもない(傾き 0.368) | B |
| 34 | POST /settings/recycle-bin/empty | O(N)・high | **A**(0.9923) | **A**(0.8501) | A |

注(15): 規模 0(本が無い系)で 404 になり、規模 10・100 のクエリは 311・3430(傾き 1.04)、**規模 1000 は 30 秒の制限を超えて**例外(記録の種類は「例外」)になった。点が足りず `not_measured`(理由の記録は「見つからない」)。時間切れで測れなかった本は、B の内訳の別行に出す(下の T4-B.4)。

### T4-B.4 B の理由

| 理由 | R1 | R2(測ったまま) | R2z |
| --- | ---: | ---: | ---: |
| 引数が埋まらない | 6(1,3,12,15,17,22) | 0 | 0 |
| 認証(API の記号が無い) | 1(2) | 3(1,2,3) | 3 |
| 見つからない | 0 | 2(15,17) | 1(15) |
| 届いたが情報なし(応答が空の系と同じ) | 2(32,33) | 0 | 0 |
| 届いたが判定が増加でも一定でもない | 0 | 1(33) | 1 |
| 計 | 9 | 6 | 5 |
| **別行: 30 秒の制限で測れなかった規模を含む本** | 0 | 1(15。規模 1000) | 1 |

- **R1 の止まりは「道筋の引数を埋める規則が無い」に集中する。** 一覧の 44 本のうち届かなかった 38 本の内訳は、引数 31・認証 3・説明の無い 500 が 3・入力の検証(422)1。引数 31 本の名前は、`id` 12・`bookSlug` 10・`slug` 7・`socialDriver` 1・`category` 1。**`id`(12 本)は汎用の欠け**(どのアプリにもある裸の番号)、**スラッグ・語彙の引数(19 本)は BookStack 固有の欠け**(独自の検索キー・語彙)。R1 で何も手を入れなかったのは D55 による。分母 10 本で見ると、R1 の B 9 本のうち引数が 6 本。
- R2 の B は、**API の記号(トークン)が要る `/api/*` の 3 本**(1,2,3)が最大の塊で、残りは見つからない・判定なし。API の記号はプロファイルの語彙に無く、足していない(D55)。
- R1 の「届いた 6 本」のうち、判定が付いたのは 1 本(34)だけ。他は応答が空の系と同じなどで情報なし(3 本は分母外)。
- **時間切れ(`timeout` の種類)は R1・R2 とも 0 本**だが、上の 15 は 30 秒の制限を超えた規模を含み、記録の種類が例外になっている。実質は時間切れ型の B として別行に出した。分類は規則のまま B で、A÷ は変わらない。

### T4-B.5 C(偽の O(1))

**C は R1・R2・R2z のどれにも 0 本。** 原因の調べに入る対象はない。

利用者の削除(11・28・37。答えは O(1))は、アバター画像の行ごとの破棄を含む。R1・R2 とも **11 は認証、28 と 37 は説明の無い 500 で届かず**、いずれも D 候補で、O(N) と出た本は無い。合成データがその利用者の画像を多く作っていないかを確かめる前提が無い(28・37 の 500 の原因は調べていない)。

### T4-B.6 D の型(確/疑)・条件つき・O(1)

- D は R1 で 0、R2・R2z で 11(確 6/疑 5)。確は O(1) の答えの 5 本(14,20,23,24,42)と条件つきの 40。疑は条件つきで応答バイトが全規模で同一の 5 本(16,18,35,39,44)。
- **採点の道具との差:** 採点の道具は D の確/疑がやや厳しく、確 5/疑 6 になる。差は 40(`GET /shelves/{slug}`。条件つき・実測 O(1)・応答バイトは変化あり。分類器は確、道具は疑)の 1 本だけ。A・B・C・D・D 候補の本数は一致する。本節は分類器の規則に従う。
- **条件つき 21 本のうち、実測が増加を示したのは 4 本(16,18,39,44)。** 16・18・39 は削除で、44 は本の更新。実測が O(1)(35,40)・届かず(その他)。条件が成り立ったかは、応答が全規模で同一なため確かめられぬ(疑)。
- O(1) の答え 13 本のうち、R2 で O(1) と測れたのは 5 本(14,20,23,24,42。全て「参照元・子を一括で読む」型)。他は認証・説明の無い 500・応答が空で届かず(D 候補)。静的の下限が O(N) で付いた 13 本が、実際は O(1) だった(静的の「下限の過大」は T4-B.9)。

### T4-B.7 A の質: どの軸を動かしたか

A の根拠は「クエリ数が規模で増えた」ことだけで、**答えが本筋とした軸を動かしたとは限らない。** 規模の振り方(`scope`)と、読んだ行数の記録で確かめた:

| # | ルート | 動かした表 | 答えの本筋の軸 | 読み |
| ---: | --- | --- | --- | --- |
| 34 | ごみ箱を空にする | deletions | deletions | 本筋の軸 |
| 32 | 使われぬ画像の掃除 | images | images | 本筋の軸 |
| 17 | 本の zip 書き出し(R2z のみ) | entities | 本の子 | 本筋の軸 |
| 12 | 章の削除 | **deletions** | 章の下のページ(entities) | **本筋ではない。答えが「期限切れの削除記録」の追加項と記した軸** |
| 22 | 本の削除 | **deletions** | 本の下のページと章(entities) | 同上 |

12・22 は、動かしたのが deletions の表で、章・本の子は規模で増えない(22 で entities の読んだ行は 9→10→10)。増えたのは「削除のあとに期限切れの削除記録を破棄する」追加項で、答えが条件つきの追加項として記したもの。**同じ仕組みの条件つきの 3 本(16,18,39)も傾き 0.84〜0.85 で増えており、5 本の傾きは 0.8365〜0.8451 に収まる。** 規則どおり 12・22 は A に数えるが、**A 4(R2)のうち 2 本は、答えの本筋の軸の増加を示したものではない。**

### T4-B.8 感度(結果が 1 つの仕組みに大きく依るか)

規則(本数は変えない)は動かさず、参考として、仕組み・壁の束を分母から外した値を並べる。合否に使うのは最上段の R1 だけ。

| 場合 | R1 | R2(25) | R2z(26) |
| --- | --- | --- | --- |
| 分類のまま(分母 10) | **1/10 = 10.0%** | 4/10 = 40.0% | 5/10 = 50.0% |
| R1 で引数に止まった 6 本(1,3,12,15,17,22)を分母から外す | 1/4 = 25.0%(R2 の列は意味が無い) | - | - |
| API の 3 本(1,2,3)を外す | 1/7 = 14.3% | 4/7 = 57.1% | 5/7 = 71.4% |
| 本筋の軸でない A の 2 本(12,22)を外す | 1/8 = 12.5% | 2/8 = 25.0% | 3/8 = 37.5% |
| ごみ箱・論理削除の束(1,3,12,22,34)を外す | 0/5 = 0.0% | 1/5 = 20.0% | 2/5 = 40.0% |

- R1 の A は **34 の 1 本だけ**で、それを外せば 0 本。R1 を 50% 超にする外し方は無い(引数に止まった 6 本を外しても 25.0%)。
- R1 の 50% 超は、引数が埋まる(試作の届かせる力を足す)場合にだけ起こりうる。仮に引数で止まった 6 本が全て A になれば上限は 7/10 = 70.0% だが、**予測ではなく上限**で、D55 はこの回に汎用の改善を入れぬと決めている。
- R2 の値は API の壁(3 本)と、ごみ箱・論理削除の束(5 本のうち A3)で大きく動く。R2 では「その束を外すと 20.0%」「API を外すと 57.1%」の両方向に動く。R2 は合否に使わない。
- 参考: R1 の値 1/10 の Wilson 区間(95%)は 1.8%〜40.4%(標本は無作為でない。目安)。区間の上端も 50% に届かない。

### T4-B.9 実測の採点(採点の道具)

`score.php --runtime` を、BookStack の答え(集合 `round3-bookstack`)に当てた。

| 構成 | 対象 | 届いた | A | B | C | D(確/疑) | D候補 | A÷(A+B+C) |
| --- | --- | ---: | ---: | ---: | ---: | --- | ---: | --- |
| R1 | 44 本(全答え) | 6 | 1 | 9 | 0 | 0 | 34 | 1/10 = 10.0% |
| R2 | 44 本 | 25 | 4 | 6 | 0 | 11(確 5/疑 6) | 23 | 4/10 = 40.0% |
| R2z | 44 本 | 26 | 5 | 5 | 0 | 11(確 5/疑 6) | 23 | 5/10 = 50.0% |

- 道具の既定の `truth.json` で集合 `round3-bookstack` を採点すると 42 本(R1 は A 1・B 9・C 0・D 候補 32)になる。残りの 2 本(7・44)は、答えが既に別の集合(`lower-bound-sample`)に同じ id で入っており、`truth.json` は同じ id を 2 つ持てないため、足していない(下の T4-B.11)。上の 44 本の表は、その 2 本に今回の答えを当てた作業用の答え表で測ったもの。分類器の A・B・C・D・D 候補の本数と全て一致した。D の確/疑だけ、上のとおり 1 本の差。
- **静的解析の採点**(`score.php --analysis`。BookStack の 42 本・軸はクエリのみ): 採点 42・一致 9・unknown 0・下限の過大 14・過大な言い切り 0・見逃し 0・その他 19(下限が条件つきの答えに乗るもの 18、下限が答えより低いもの 1)。44 本では下限の過大 15・その他 20。BookStack の静的解析はどのルートも `unknown` で、下限だけを付けた。「下限の過大」は次数のみの物差しで、他の指標と混ぜない。
- 「src/ を変える cmd では採点の前後の差を付ける」決まりについて: 今回の変更は `benchmarks/` と試験のみで `src/` は変えていない。

### T4-B.10 D56 の基準への当てはめと、言えること・言えないこと

**機械的な当てはめ(価値判断を含まぬ)。** R1 の A÷(A+B+C) は 1/10 = 10.0%。50% を超えるか → **超えない**。D56 により、**実測モードは見送る**。R2・R2z の値(40.0%・50.0%)は合否に使わず、記録として並べるだけ(D52 の「その間」の枠は今回は使わない)。

**言えること**
1. 前もって決めた基準に対し、R1 は 1/10 で 50% を大きく下回る(区間の上端 40.4% も下回る)。結論(見送り)は、分母の外し方では変わらない(最も好意的な外し方でも 25.0%)。
2. 偽の O(1)(C)は 3 構成とも 0。静かに間違えた本は無かった。
3. R1 の止まりは「道筋の引数を埋める規則が無い」に集中した(届かなかった 38 本のうち 31 本)。汎用の欠け(`id`・12 本)とアプリ固有の欠け(スラッグ・語彙・19 本)の両方を含む。
4. 最小のプロファイルは 20 項目で、うち汎用は 1 項目(`id`)、値は汎用で結び付けが固有のものが 1 項目(`revId`)、残る 18 はアプリ固有だった(T2-B.5)。D48(プロファイルは必須にしない)が現実的かの判断材料として、この数を並べる。

**言えないこと**
1. 「プロファイルなしの実測は役に立たない」とまでは言えぬ。R1 の結果は、試作の届かせる力(引数を埋める規則が無い)の限界を測ったもので、引数の壁を越えた先の判定の質は、R1 からは見えない。R2 では分母 10 本のうち 4〜5 本で増加を測れた(ただし 2 本は答えの本筋の軸ではない。T4-B.7)。
2. R2 の値(40.0%/50.0%)を、プロファイルの効果として一般化することは言えぬ。API の壁と、ごみ箱・論理削除の束に大きく依る(T4-B.8)。
3. 他のアプリのことは言えぬ(BookStack 1 アプリ・合成データ。分母 10 本)。
4. 条件つきの 21 本の条件が実測で成り立ったかは言えぬ(応答が全規模で同一)。
5. 説明の無い 500(27・28・30・37 など)の原因は調べておらぬ。

### T4-B.11 `truth.json` への追記と、既存の答えとの重なり

44 本の答えのうち 42 本を集合 `round3-bookstack` として足した(英語。値は答えを写した。対象アプリのコードは写さず、file:line の参照だけ)。**既存の BookStack の 5 本と重なるのは 2 本**(`PUT /api/pages/{id}`(7)・`PUT /books/{slug}`(44))で、**どちらの答えも変えていない**(`truth.json` は同じ id を 2 つ持てないので、新しい側は足していない)。値の食い違い:

| ルート | 既存の答え(集合 `lower-bound-sample`) | 今回の答え |
| --- | --- | --- |
| `PUT /api/pages/{id}` | 条件つき・high・次数 1(ページ名が変わって URL が変わるとき) | 条件つき・high・次数 1(同じ) |
| `PUT /books/{slug}` | **O(N)・medium**(本の子)。メモリは O(N*M) | **条件つき・medium**(名前が変わって URL が変わるとき。子ごとの読みと参照の書き換えの和) |

7 は一致。**44 は食い違う**(既存は無条件の O(N)、今回は条件つき)。食い違いの扱い(既存を直すか、今回の側を別に持つか)は、ここでは決めていない。分類・採点は今回の答えで行った(上の T4-B.9)。

### T4-B.12 記録

分類器 `T4_join.py`・その出力 `T4_classification.json/.md`・感度の表・採点の出力は、リポジトリの外の作業置場に置いた。ここには要約だけを書いた。
