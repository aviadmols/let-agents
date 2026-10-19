<?php

namespace App\Modules\Retrieval\Support;

use App\Modules\Retrieval\Contracts\TopicQuestions;

/** The phrasings of each topic of a question, from resources/topics.json ("asks"), in its language. */
final class TopicAsks implements TopicQuestions
{
    private const FILE = __DIR__.'/../resources/topics.json';

    public function questions(string $question, string $locale): array
    {
        static $asks = null;
        $asks ??= (array) (json_decode((string) file_get_contents(self::FILE), true)['asks'] ?? []);
        $out = [];

        foreach (Topics::ofQuestion($question) as $topic) {
            $phrasings = (array) ($asks[$topic][$locale] ?? $asks[$topic]['he'] ?? []);
            array_push($out, ...array_map('strval', $phrasings));
        }

        return array_values(array_unique($out));
    }
}
