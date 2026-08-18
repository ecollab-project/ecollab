---
name: ecollab-database-integrity
description: Safely design, debug, migrate, and optimize E-Collab MySQL/MariaDB data operations while preserving integrity and compatibility.
---

# Database Integrity

Inspect the real schema and migrations before changing SQL. Verify columns, types, nullability, indexes, foreign keys, uniqueness, and ownership relationships.

Use prepared statements. Prefer minimal compatible schema changes. Consider existing data, migration order, rollback, concurrency, transactions, and query performance. Test both expected and invalid inputs. Re-check affected API contracts after database changes.