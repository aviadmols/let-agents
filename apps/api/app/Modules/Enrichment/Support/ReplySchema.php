<?php

namespace App\Modules\Enrichment\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * A model's reply held to the JSON Schema kept beside its prompt (`Prompts/{name}.v{n}.schema.json`), in code, whatever
 * the provider promised: a driver that cannot hold a reply to a schema answers as plain JSON, and a reply that only
 * looks right is worse than none. Nothing is mended here; the caller fails on any problem.
 */
final class ReplySchema
{
    /** @return array<string, mixed> the schema, as the provider is given it */
    public static function load(string $prompt, int $version): array
    {
        $path = dirname(__DIR__).'/Prompts/'.$prompt.'.v'.$version.'.schema.json';
        $schema = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($schema)) {
            throw new RuntimeException("Schema of prompt {$prompt} v{$version} does not exist.");
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $schema
     * @return list<string> what breaks the schema; empty when the reply holds to it
     */
    public static function problems(array $reply, array $schema): array
    {
        $validator = new Validator;
        $validator->setMaxErrors(5);
        $result = $validator->validate(
            json_decode((string) json_encode($reply, JSON_UNESCAPED_UNICODE)),
            json_decode((string) json_encode($schema)),
        );

        if ($result->isValid()) {
            return [];
        }

        $errors = (new ErrorFormatter)->format($result->error(), false);

        return array_values(array_map(fn ($message, $path): string => $path.': '.$message, $errors, array_keys($errors)));
    }
}
