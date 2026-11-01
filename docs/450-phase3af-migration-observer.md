# Phase 3AF migration observer correction

The original 20 ms polling reproduced a false-negative lock-wait observation with a live blocked child. Extending the total timeout to five seconds and closing the cursor did not resolve it. MariaDB 10.11.14 source `storage/innobase/trx/trx0i_s.cc` defines `CACHE_MIN_IDLE_TIME_NS` as 100,000,000 ns and refreshes only after that idle period. Repeated reads at 20 ms can starve refresh.

Source: https://github.com/MariaDB/server/blob/mariadb-10.11.14/storage/innobase/trx/trx0i_s.cc

The corrected test primes an empty diagnostic snapshot while the child waits for an explicit parent release. It then polls at 250 ms and still requires the exact child connection to be waiting before releasing the parent transaction. Commit must produce duplicate-key 1062; rollback must allow creation. No assertion, application source, migration, schema or production configuration is relaxed.

Preserved controls under `450-phase3af-correction/raw/`: delayed child with 20 ms polling FAIL; closing the cursor with 20 ms polling FAIL; 250 ms polling PASS; final explicit-barrier/primed-cache full rehearsal PASS; the same final barrier with only the poll interval changed back to 20 ms FAIL. The final passing local run observed both commit and rollback waits at approximately 251 ms and completed the database failure/restart and existing regression suites. Hosted exact-final-head validation remains a separate gate.
