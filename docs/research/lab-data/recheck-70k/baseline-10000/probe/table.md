| route | status | ms (3) | queries meas. | rows meas. | DB CPU s (median of 3) | queries est ÷ meas | rows est ÷ meas (point / safe) | CPU est ÷ meas (point / safe) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GET /books/{bookSlug}/chapter/{chapterSlug}/references | 200,200,200 | 36,32,28 | 8 | 222 | 0.0036 (below the measurement floor) | 1 | 1.01 / 1.01 | withheld: would be 0.747 / 0.747 (below the measurement floor (empty-window / idle-drift rule)) |
| GET /books/{bookSlug}/export/zip | 200,200,200 | 7388,7544,7412 | 1.2e+04 | 8.37e+04 | 3.47 | 1 | 0.992 / 10.3 | 1.02 / 1.46 |
| GET /books/{bookSlug}/page/{pageSlug}/references | 200,200,200 | 28,27,27 | 8 | 229 | 0.00495 (below the measurement floor) | 1 | 0.999 / 2.55 | withheld: would be 0.545 / 0.685 (below the measurement floor (empty-window / idle-drift rule)) |
| GET /books/{slug} | 200,200,200 | 1230,1044,1035 | 45 | 1.26e+05 | 0.156 | 1.01 | 0.984 / 16.7 | 1.64 / 26.6 |
| GET /books/{slug}/references | 200,200,200 | 26,28,25 | 7 | 217 | 0.00366 (below the measurement floor) | 1 | 1 / 1 | withheld: would be 0.655 / 0.655 (below the measurement floor (empty-window / idle-drift rule); the three readings scatter by more than their median (rule added after the 100,000-row reading)) |
| GET /images/edit/{id} | 200,200,200 | 21,21,21 | 6 | 199 | 0.00765 (below the measurement floor) | 1 | 1 / 1 | withheld: would be 0.272 / 0.272 (the three readings scatter by more than their median (rule added after the 100,000-row reading)) |
| GET /oidc/callback | 302,302,302 | 13,13,12 | 0 | 0 | -0.00053 (below the measurement floor) | n/a | n/a / n/a | withheld: would be -0 / -0 (below the measurement floor (empty-window / idle-drift rule); the three readings scatter by more than their median (rule added after the 100,000-row reading)) |
| GET /shelves/{slug} | 200,200,200 | 36,38,35 | 18 | 238 | 0.00802 | 1 | 1.1 / 1.1 | 0.696 / 0.696 |
