/* Directed shortest paths: adjacency matrix + Dijkstra, 64-bit distances.
   Vertices 0..n-1; input: n m source, followed by m triples u v weight. */
#include <stdio.h>
#include <stdlib.h>
#include <limits.h>
#define MAXN 200
#define INF (LLONG_MAX / 4)
int main(void) {
    int n,m,s,u,v;
    long long w;
    if (scanf("%d%d%d",&n,&m,&s)!=3 || n<1 || n>MAXN ||
        m<0 || m>2000 || s<0 || s>=n) { puts("INVALID"); return 0; }
    long long *a=malloc((size_t)n*n*sizeof(*a));
    long long *d=malloc((size_t)n*sizeof(*d));
    int *done=calloc((size_t)n,sizeof(*done));
    if (!a || !d || !done) { free(a);free(d);free(done);return 1; }
    for(int i=0;i<n*n;i++) a[i]=INF;
    for(int i=0;i<n;i++) d[i]=INF;
    for(int i=0;i<m;i++) {
        if(scanf("%d%d%lld",&u,&v,&w)!=3 || u<0 || u>=n || v<0 || v>=n ||
           w<0 || w>1000000000LL) {
            puts("INVALID");free(a);free(d);free(done);return 0;
        }
        if(w<a[u*n+v]) a[u*n+v]=w;
    }
    d[s]=0;
    for(int step=0;step<n;step++){
        int best=-1;
        for(int i=0;i<n;i++)
            if(!done[i] && (best<0 || d[i]<d[best])) best=i;
        if(best<0 || d[best]==INF) break;
        done[best]=1;
        for(int j=0;j<n;j++)
            if(a[best*n+j]!=INF && d[best]+a[best*n+j]<d[j])
                d[j]=d[best]+a[best*n+j];
    }
    int reached=0;long long checksum=0;
    for(int i=0;i<n;i++){
        if(i) putchar(' ');
        if(d[i]==INF) printf("INF");
        else { printf("%lld",d[i]);reached++;checksum+=(i+1)*d[i]; }
    }
    printf("\nreachable=%d checksum=%lld\n",reached,checksum);
    free(a);free(d);free(done);return 0;
}
