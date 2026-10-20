// Directed shortest paths using STL adjacency lists and a min priority queue.
#include <iostream>
#include <vector>
#include <queue>
#include <functional>
#include <limits>
using namespace std;
int main() {
    ios::sync_with_stdio(false); cin.tie(nullptr);
    int n,m,s,u,v; long long w;
    if(!(cin>>n>>m>>s)||n<1||n>200||m<0||m>2000||s<0||s>=n){
        cout<<"INVALID\n";return 0;
    }
    vector<vector<pair<int,long long>>> graph(n);
    for(int i=0;i<m;i++){
        if(!(cin>>u>>v>>w)||u<0||u>=n||v<0||v>=n||w<0||w>1000000000LL){
            cout<<"INVALID\n";return 0;
        }
        graph[u].emplace_back(v,w);
    }
    const long long inf=numeric_limits<long long>::max()/4;
    vector<long long> distance(n,inf);distance[s]=0;
    using Entry=pair<long long,int>;
    priority_queue<Entry,vector<Entry>,greater<Entry>> heap;
    heap.emplace(0,s);
    while(!heap.empty()){
        auto [cost,node]=heap.top();heap.pop();
        if(cost!=distance[node])continue;
        for(auto [next,weight]:graph[node])
            if(cost+weight<distance[next]){
                distance[next]=cost+weight;heap.emplace(distance[next],next);
            }
    }
    int reached=0;long long checksum=0;
    for(int i=0;i<n;i++){
        if(i)cout<<' ';
        if(distance[i]==inf)cout<<"INF";
        else {cout<<distance[i];reached++;checksum+=(i+1)*distance[i];}
    }
    cout<<"\nreachable="<<reached<<" checksum="<<checksum<<'\n';
}
