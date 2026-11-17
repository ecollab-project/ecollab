<?php
declare(strict_types=1);

/** Bounded output contracts for explicit formats; never grants tools or overrides policy. */
final class JarredResponseFormat
{
    public static function forPrompt(string $prompt): ?array
    {
        if (preg_match('/\b(?:answer|respond|reply)\s+(?:only\s+(?:with\s+)?(?:the\s+)?number|with\s+(?:the\s+)?number\s+only)\b/i', $prompt)) {
            return ['kind'=>'number', 'schema'=>['type'=>'object','properties'=>['answer'=>['type'=>'number']],'required'=>['answer'],'additionalProperties'=>false],
                'instruction'=>'Return JSON with only the numeric answer in the answer field. Do not put the calculation or explanatory text in the answer.'];
        }
        if (preg_match('/\b(?:exactly|in)\s+(one|two|three|four|five|six|seven|eight|[1-8])\s+(?:short\s+)?sentences?\b/i', $prompt, $match)) {
            $words=['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8];
            $count=$words[strtolower($match[1])]??(int)$match[1];
            return ['kind'=>'sentences','count'=>$count,'schema'=>['type'=>'object','properties'=>['sentences'=>['type'=>'array','minItems'=>$count,'maxItems'=>$count,'items'=>['type'=>'string','minLength'=>1,'maxLength'=>2000]]],'required'=>['sentences'],'additionalProperties'=>false],
                'instruction'=>"Return JSON with a sentences array of exactly $count items. Each item must be one complete sentence with ending punctuation. No headings, bullets, preamble or additional sentences."];
        }
        return null;
    }

    public static function decode(string $text, ?array $format): string
    {
        if ($format===null) return trim($text);
        $data=json_decode($text,true);
        if ($format['kind']==='number' && is_array($data) && count($data)===1 && array_key_exists('answer',$data)
            && (is_int($data['answer']) || is_float($data['answer'])) && is_finite((float)$data['answer'])) {
            return (string)json_encode($data['answer'],JSON_THROW_ON_ERROR);
        }
        if ($format['kind']==='sentences' && is_array($data) && count($data)===1 && isset($data['sentences'])
            && is_array($data['sentences']) && array_is_list($data['sentences']) && count($data['sentences'])===$format['count']) {
            $sentences=[];
            foreach ($data['sentences'] as $sentence) {
                if (!is_string($sentence)) break;
                $sentence=trim($sentence);
                if ($sentence==='' || strlen($sentence)>8000 || preg_match('/[\r\n]/',$sentence)
                    || !preg_match('/[.!?]["\x{201d}\x{2019}]?$/u',$sentence)
                    || preg_match('/[.!?]["\x{201d}\x{2019}]?\s+\p{L}/u',$sentence)) break;
                $sentences[]=$sentence;
            }
            if (count($sentences)===$format['count']) return implode(' ',$sentences);
        }
        // Fail the queue job normally, allowing retry, rather than publish a
        // response that is known to violate the user's explicit contract.
        throw new RuntimeException('The model did not follow the requested response format.',503);
    }
}
