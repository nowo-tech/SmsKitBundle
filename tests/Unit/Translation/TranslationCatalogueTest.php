<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_diff;
use function array_keys;
use function array_values;
use function basename;
use function glob;
use function is_array;
use function mb_strlen;
use function preg_match;
use function sort;
use function sprintf;

/**
 * Guards against untranslated strings: in every non-en catalogue (all domains) a key must exist and a value longer than
 * 15 characters, without placeholders, must differ from the English one unless the key is in ALLOWED_IDENTICAL.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../src/Resources/translations';

    /**
     * Keys whose value is legitimately identical across locales (brands, product names, CSS classes, route names).
     *
     * @var array<string, string>
     */
    private const ALLOWED_IDENTICAL = [
        'settings.sms.twilio_account_sid'           => 'Twilio field name (product term)',
        'settings.sms.twilio_auth_token'            => 'Twilio field name (product term)',
        'settings.sms.twilio_messaging_service_sid' => 'Twilio field name (product term)',
        'settings.sms.bridge_account_sid'           => 'SMS Bridge field name (product term)',
    ];

    /** @return iterable<string, array{string, string}> */
    public static function catalogues(): iterable
    {
        $files = glob(self::DIR . '/*.yaml') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (preg_match('/^(.+)\.([a-z]{2})\.yaml$/', basename($file), $m) === 1 && $m[2] !== 'en') {
                yield basename($file) => [$m[1], $m[2]];
            }
        }
    }

    #[DataProvider('catalogues')]
    public function testLocaleHasEveryEnglishKey(string $domain, string $locale): void
    {
        $missing = array_values(array_diff(array_keys($this->load($domain, 'en')), array_keys($this->load($domain, $locale))));

        self::assertSame([], $missing, sprintf('Keys of %s.en.yaml missing in %s.%s.yaml', $domain, $domain, $locale));
    }

    #[DataProvider('catalogues')]
    public function testNoUntranslatedEnglishValues(string $domain, string $locale): void
    {
        $english = $this->load($domain, 'en');
        $target  = $this->load($domain, $locale);
        $gaps    = [];

        foreach ($english as $key => $value) {
            if (!isset($target[$key]) || $target[$key] !== $value || isset(self::ALLOWED_IDENTICAL[$key])) {
                continue;
            }

            if (mb_strlen($value) > 15 && preg_match('/%[^%\s]+%|\{[^}]+\}/', $value) !== 1) {
                $gaps[] = $key;
            }
        }

        self::assertSame([], $gaps, sprintf('Values identical to English in %s.%s.yaml (translate or allow-list)', $domain, $locale));
    }

    public function testAllowListOnlyHoldsExistingEnglishKeys(): void
    {
        $known = [];

        foreach (self::catalogues() as [$domain]) {
            $known += $this->load($domain, 'en');
        }

        self::assertSame([], array_values(array_diff(array_keys(self::ALLOWED_IDENTICAL), array_keys($known))));
    }

    /** @return array<string, string> */
    private function load(string $domain, string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/%s.%s.yaml', self::DIR, $domain, $locale));

        return $this->flatten(is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }
}
