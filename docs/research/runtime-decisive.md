# 実測モードは役に立つかを白黒つける実験

作成日: 2026-11-02

問いは次の一つである。**静的解析が「少なくとも O(N)」と下限を付けたルートを、実測で本当に O(N) と測れるか。**

- 測れる → 実測は静的解析の推定を裏づけ、埋める役に立つ
- 分かっている O(N) すら測れない → 実測モードはやめ、静的解析に集中する

この文書は実験の記録で、節ごとに足していく。この版は **§T1(対象アプリとルートの選定、環境の用意、煙試験)** だけを書く。測定(§T2)とコードで追う答え合わせ(§T3)は、後の版で足す。

**これは試作の結果であり、実測モードの本採用ではない。** 試作のコードは `poc/runtime/` のままで、`src/` には入れていない。判定の規則(log-log 傾き)も D21 も変えていない。生の出力と選定の記録は、リポジトリの外の試行用ディレクトリ(`peak-sim-trials/_out/runtime-decisive/`)に置いた。

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

選定の記録(機械可読)は `peak-sim-trials/_out/runtime-decisive/T1_routes.json`。選ぶ手順のスクリプトは同じ場所の `select_t1.py` で、同じ入力から同じ一覧ができる。各ルートには、メソッド・URI・静的の判定・下限・層・選抜の序数(`ordinal`)を付けた。

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
