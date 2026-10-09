<?php
/** Exact configured signaling/media origins for pages with a restrictive CSP. */
function ecollabCallOrigins(): string
{
    $origins=[];
    foreach ([defined('WS_URL') ? WS_URL : '', (string)getenv('LIVEKIT_URL')] as $url) {
        $parts=parse_url($url);
        if (!$parts || !in_array($parts['scheme']??'', ['ws','wss'], true)) continue;
        $host=$parts['host']??'';
        if (!preg_match('/^[a-zA-Z0-9.\[\]:-]+$/', $host)) continue;
        $authority=$host.(isset($parts['port']) ? ':'.(int)$parts['port'] : '');
        $origins[]=$parts['scheme'].'://'.$authority;
        $origins[]=($parts['scheme']==='wss'?'https':'http').'://'.$authority;
    }
    return implode(' ',array_unique($origins));
}
