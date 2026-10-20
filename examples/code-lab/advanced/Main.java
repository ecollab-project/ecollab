// Directed shortest paths: buffered input, generics, classes, and PriorityQueue.
import java.io.*;
import java.util.*;
public class Main {
    static class Input {
        final BufferedInputStream in = new BufferedInputStream(System.in);
        long number() throws IOException {
            int c; do { c=in.read(); } while(c>=0 && c<=32);
            if(c<0) throw new EOFException();
            boolean negative=c=='-';if(negative)c=in.read();
            if(c<'0'||c>'9')throw new IOException();
            long value=0;
            while(c>32 && c>=0){
                if(c<'0'||c>'9'||value>(Long.MAX_VALUE-(c-'0'))/10)
                    throw new IOException();
                value=value*10+c-'0';c=in.read();
            }
            return negative?-value:value;
        }
        int integer() throws IOException {
            long value=number();
            if(value<Integer.MIN_VALUE||value>Integer.MAX_VALUE)throw new IOException();
            return (int)value;
        }
    }
    static class Edge {
        final int to;final long weight;
        Edge(int to,long weight){this.to=to;this.weight=weight;}
    }
    static class Entry implements Comparable<Entry> {
        final int node;final long cost;
        Entry(int node,long cost){this.node=node;this.cost=cost;}
        public int compareTo(Entry other){
            int result=Long.compare(cost,other.cost);
            return result!=0?result:Integer.compare(node,other.node);
        }
    }
    public static void main(String[] args) throws Exception {
        Input input=new Input();int n,m,source;
        List<List<Edge>> graph=new ArrayList<>();
        try {
            n=input.integer();m=input.integer();source=input.integer();
            if(n<1||n>200||m<0||m>2000||source<0||source>=n)throw new IOException();
            for(int i=0;i<n;i++)graph.add(new ArrayList<>());
            for(int i=0;i<m;i++){
                int u=input.integer(),v=input.integer();long w=input.number();
                if(u<0||u>=n||v<0||v>=n||w<0||w>1000000000L)throw new IOException();
                graph.get(u).add(new Edge(v,w));
            }
        }catch(IOException error){System.out.println("INVALID");return;}
        long[] distance=new long[n];Arrays.fill(distance,Long.MAX_VALUE);distance[source]=0;
        PriorityQueue<Entry> heap=new PriorityQueue<>();heap.add(new Entry(source,0));
        while(!heap.isEmpty()){
            Entry entry=heap.remove();
            if(entry.cost!=distance[entry.node])continue;
            for(Edge edge:graph.get(entry.node)){
                long candidate=entry.cost+edge.weight;
                if(candidate<distance[edge.to]){
                    distance[edge.to]=candidate;heap.add(new Entry(edge.to,candidate));
                }
            }
        }
        StringBuilder out=new StringBuilder();int reached=0;long checksum=0;
        for(int i=0;i<n;i++){
            if(i>0)out.append(' ');
            if(distance[i]==Long.MAX_VALUE)out.append("INF");
            else {out.append(distance[i]);reached++;checksum+=(i+1)*distance[i];}
        }
        System.out.println(out);
        System.out.println("reachable="+reached+" checksum="+checksum);
    }
}
