<?php

namespace Goldnead\StatamicInsights\Tests\Feature;

use Goldnead\StatamicInsights\Tests\TestCase;

/**
 * The English language files are what an English-speaking buyer sees in the
 * role editor and on every screen. A German remnant there once slipped through
 * ("Insightsen ansehen"); this fails on the next one.
 */
class EnglishLangIsEnglishTest extends TestCase
{
    /** Words that are German and not English, matched as whole words. */
    private const GERMAN = [
        'ansehen', 'anzeigen', 'und', 'oder', 'nicht', 'der', 'die', 'das', 'für', 'fuer',
        'einstellungen', 'verwalten', 'bearbeiten', 'löschen', 'speichern', 'zeitraum',
        'umsatz', 'abos', 'kunden', 'zahlungen', 'bestellungen', 'alle', 'keine',
    ];

    public function test_view_insights_permission_is_english(): void
    {
        $permissions = require dirname(__DIR__, 2).'/resources/lang/en/permissions.php';

        $this->assertSame('View insights', $permissions['view_insights']);
    }

    public function test_no_english_lang_string_contains_german_words(): void
    {
        $base = dirname(__DIR__, 2).'/resources/lang';
        $strings = [];

        foreach (glob($base.'/en/*.php') as $file) {
            $this->flatten(require $file, basename($file), $strings);
        }
        foreach (json_decode(file_get_contents($base.'/en.json'), true) as $key => $value) {
            $strings["en.json:$key"] = $value;
        }

        $offenders = [];
        foreach ($strings as $where => $text) {
            foreach (self::GERMAN as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', (string) $text)) {
                    $offenders[] = "$where => $text";
                    break;
                }
            }
        }

        $this->assertSame([], $offenders, "German text in resources/lang/en:\n".implode("\n", $offenders));
    }

    private function flatten(array $array, string $prefix, array &$out): void
    {
        foreach ($array as $key => $value) {
            is_array($value) ? $this->flatten($value, "$prefix.$key", $out) : $out["$prefix.$key"] = $value;
        }
    }
}
