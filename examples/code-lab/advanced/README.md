# Advanced Code Lab language tests

Supported choices for new tasks/playgrounds: **C, C++, Python, Java**.
JavaScript and HTML/CSS/JS are removed from both selectors and rejected by the
new-task/playground APIs. Existing tasks, submissions and saved sources are not
deleted. Existing task review/grading remains available. The internal runner can
retain its installed JavaScript package; installing this change needs no rebuild.

## Inside Ecollab
1. Open a server's Code Lab, then Playground.
2. Select C, C++, Python or Java.
3. Click **Load test program**. Confirm replacing existing editor code if needed.
4. Click **Run code**. The source and stdin are loaded, but not saved automatically.
5. Repeat for the other languages. The expected output is shown in Instructions.

All four programs solve the same directed shortest-path problem so results can
be compared across languages. They exercise input parsing, validation, dynamic
collections, graph algorithms, zero-weight cycles, unreachable nodes and integer
values beyond 32 bits. C uses an allocated adjacency matrix and O(V²) Dijkstra;
C++, Python and Java use adjacency lists and priority queues. Only standard
libraries are required. Java uses the required public class Main.

Sample stdin:
```text
6 8 0
0 1 7
0 2 9
0 5 14
1 2 10
1 3 15
2 3 11
2 5 2
3 4 6
```

Expected stdout:
```text
0 7 9 20 26 11
reachable=6 checksum=317
```

Input: n m source, then m directed edges u v weight. Vertices are zero-based.
Limits: 1–200 vertices, 0–2000 edges, weights 0–1,000,000,000. Duplicate
edges are allowed. Invalid headers/edges or missing tokens print INVALID.
Output: one distance per vertex (INF if unreachable), then reachable vertex
count and checksum Σ((vertex+1)×distance), excluding unreachable vertices.

## Full sandbox check on the VPS
```bash
cd ~/ecollab-inspect
php scripts/test-code-lab-languages.php
# Or one language:
php scripts/test-code-lab-languages.php --language=java
```

This runs 9 cases per language (36 total) through the application's configured
sandbox client, sequentially. Exit code 0 means every comparison passed;
nonzero means failure or incomplete execution. It does not create student
attempts, modify submissions or assign grades. It takes several minutes and is
a functional test, not a concurrency/capacity benchmark.

cases.json includes weighted paths, disconnected vertices, duplicate edges,
a zero-weight cycle, 64-bit distances, nonzero source, single vertex, negative
weight rejection, invalid vertex rejection and truncated input rejection.

To check the actual automatic-grading flow, create a Python/C/C++/Java practice
task with automatic checks, copy the fixture inputs/expected outputs into its
checks, and submit the matching program from a student account. Expect 100/100;
then use a separate task/account to submit an incorrect solution and confirm
it fails. These public sample cases are for verification, not secure exam cases.
