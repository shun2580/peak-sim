# expected.json の構造化メモ
- 出典は meta に1か所だけ(spec.md「検証用テストアプリ」表・2026-10-31・commit 6f06bd3)。全24ルートが同じキー集合。
- null = 表に記載なし(推測で埋めない)。計算量は ASCII 表記(O(N×M) → "O(N*M)")、不明は "unknown"。
- query/memory = 表の先頭の計算量。補足は構造化: query_coefficient("1/chunk_size")・per_item_queries(2)・query_extra_per_request(1)。
- /hidden/cache: query は null、query_on_miss="O(N)"・query_on_hit="O(1)"・cached=true、memory_restores_all_on_hit=true。
- /hidden/cursor: memory_cause="pdo_buffers_all_rows"。/hidden/conditional-binding: worst_case_adopted=true。
- /ng/nested: n_table=orders、m_table=items、m_per=orders(M=1注文あたりのitems)。planted は表の「仕込む内容」を原文のまま。
