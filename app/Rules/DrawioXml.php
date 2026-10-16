<?php

namespace App\Rules;

use Closure;
use DOMDocument;
use Illuminate\Contracts\Validation\ValidationRule;

class DrawioXml implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 5 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $value)) {
            $fail('Нужен XML draw.io размером не более 5 МБ, без внешних сущностей.');

            return;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $valid = $document->loadXML($value, LIBXML_NONET);
            if (! $valid || ! in_array($document->documentElement?->tagName, ['mxfile', 'mxGraphModel'], true)) {
                $fail('Некорректный исходник схемы draw.io.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
