<?php

namespace App\Rules;

use Closure;
use DOMDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

class SvgIcon implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 32768 || preg_match('/<!DOCTYPE|<!ENTITY/i', $value)) {
            $fail('Нужна SVG-иконка размером не более 32 КБ, без внешних сущностей.');

            return;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($value, LIBXML_NONET) || $document->documentElement?->localName !== 'svg'
                || $document->documentElement?->namespaceURI !== 'http://www.w3.org/2000/svg') {
                $fail('Некорректная SVG-иконка. Нужен элемент svg с xmlns="http://www.w3.org/2000/svg".');

                return;
            }
            if ($document->getElementsByTagName('svg')->length !== 1 || preg_match('/<\?(?!xml\s)/i', $value)) {
                $fail('Иконка должна содержать один SVG-документ без инструкций обработки.');

                return;
            }
            foreach ($document->getElementsByTagName('*') as $element) {
                if ($element->namespaceURI !== 'http://www.w3.org/2000/svg'
                    || in_array(Str::lower($element->localName), ['script', 'foreignobject', 'iframe', 'object', 'embed', 'style', 'animate', 'animatemotion', 'animatetransform', 'set'], true)) {
                    $fail('В SVG-иконке разрешена только статическая графика.');

                    return;
                }
                foreach ($element->attributes as $attributeNode) {
                    $name = Str::lower($attributeNode->localName);
                    $content = trim($attributeNode->value);
                    if (str_starts_with($name, 'on') || (in_array($name, ['href', 'src'], true) && ! str_starts_with($content, '#'))
                        || preg_match('/javascript:|vbscript:|data:|@import|expression\s*\(/i', $content)) {
                        $fail('В SVG-иконке запрещены скрипты и внешние ресурсы.');

                        return;
                    }
                    preg_match_all('/url\(([^)]*)\)/i', $content, $urls);
                    foreach ($urls[1] as $url) {
                        if (! str_starts_with(trim($url, " \t\n\r\0\x0B\"'"), '#')) {
                            $fail('В SVG-иконке запрещены внешние ресурсы.');

                            return;
                        }
                    }
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
