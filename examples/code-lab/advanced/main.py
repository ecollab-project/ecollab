"""Directed Dijkstra: heapq, dataclasses, type hints and arbitrary-size integers."""
from dataclasses import dataclass
import heapq
import sys
from typing import List, Optional, Tuple

@dataclass
class Graph:
    edges: List[List[Tuple[int, int]]]

    def shortest(self, source: int) -> List[Optional[int]]:
        distances: List[Optional[int]] = [None] * len(self.edges)
        distances[source] = 0
        heap = [(0, source)]
        while heap:
            cost, node = heapq.heappop(heap)
            if cost != distances[node]:
                continue
            for neighbor, weight in self.edges[node]:
                candidate = cost + weight
                if distances[neighbor] is None or candidate < distances[neighbor]:
                    distances[neighbor] = candidate
                    heapq.heappush(heap, (candidate, neighbor))
        return distances

def main() -> None:
    try:
        values = iter(map(int, sys.stdin.read().split()))
        n, m, source = next(values), next(values), next(values)
        if not (1 <= n <= 200 and 0 <= m <= 2000 and 0 <= source < n):
            raise ValueError()
        graph = Graph([[] for _ in range(n)])
        for _ in range(m):
            u, v, weight = next(values), next(values), next(values)
            if not (0 <= u < n and 0 <= v < n and 0 <= weight <= 10**9):
                raise ValueError()
            graph.edges[u].append((v, weight))
    except (ValueError, StopIteration):
        print("INVALID")
        return
    distances = graph.shortest(source)
    print(" ".join("INF" if d is None else str(d) for d in distances))
    reached = sum(d is not None for d in distances)
    checksum = sum((i + 1) * d for i, d in enumerate(distances) if d is not None)
    print(f"reachable={reached} checksum={checksum}")

if __name__ == "__main__":
    main()
