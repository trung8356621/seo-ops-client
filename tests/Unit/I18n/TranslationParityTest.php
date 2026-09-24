<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cheap deterministic i18n guardrails (no AI).
 *
 * - EN/VI key parity for owned lang trees
 * - Freeze known hardcoded-Vietnamese Filament nav/model static props
 *
 * Full inventory: docs/i18n-audit.md + tools/i18n-audit-scan.py
 */
final class TranslationParityTest extends TestCase
{
    private const VI_CHAR_CLASS = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴÈÉẸẺẼÊỀẾỆỂỄÌÍỊỈĨÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠÙÚỤỦŨƯỪỨỰỬỮỲÝỴỶỸĐ';

    /**
     * Known Critical debt from 2026-03 audit — must shrink, never grow.
     * Format: relative path from client root OR addons root marker.
     *
     * @var list<string>
     */
    private const KNOWN_HARDCODED_VI_NAV_PROPS = [
        'app/Filament/Pages/ControlServer.php|$navigationGroup',
        'app/Filament/Pages/CoreSettingsHub.php|$navigationGroup',
        'app/Filament/Pages/CoreSettingsHub.php|$navigationLabel',
        'app/Filament/Pages/HelpTopicsAdmin.php|$navigationGroup',
        'app/Filament/Resources/SeoDatabaseConnectionResource.php|$navigationGroup',
        'app/Filament/Resources/SiteResource.php|$navigationGroup',
        'app/Filament/Resources/SiteServiceResource.php|$navigationGroup',
        'app/Filament/Resources/UserResource.php|$navigationGroup',
        'app/Filament/Resources/UserResource.php|$navigationLabel',
        'app/Filament/Resources/UserResource.php|$modelLabel',
        'app/Filament/Resources/UserResource.php|$pluralModelLabel',
        'app/Providers/Filament/AdminPanelProvider.php|NavigationGroup::make',
        'addons:seo/src/Support/SeoUserNavigation.php|GROUP_SYSTEM',
        'addons:search-foundation/src/Filament/Pages/Statistics.php|$navigationLabel',
    ];

    public function test_client_json_lang_key_parity(): void
    {
        $en = $this->loadJsonKeys(base_path('lang/en.json'));
        $vi = $this->loadJsonKeys(base_path('lang/vi.json'));

        self::assertSame(
            [],
            array_values(array_diff(array_keys($en), array_keys($vi))),
            'lang/en.json has keys missing from lang/vi.json'
        );
        self::assertSame(
            [],
            array_values(array_diff(array_keys($vi), array_keys($en))),
            'lang/vi.json has keys missing from lang/en.json'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function strictPhpLangPairsProvider(): array
    {
        return [
            'client_control' => ['lang/en/client_control.php', 'lang/vi/client_control.php'],
            'seo' => ['lang/en/seo.php', 'lang/vi/seo.php'],
            'seo_rules' => ['lang/en/seo_rules.php', 'lang/vi/seo_rules.php'],
            'site-service' => ['lang/en/site-service.php', 'lang/vi/site-service.php'],
            'seeding_filament' => [
                'addons/seeding/resources/lang/en/filament.php',
                'addons/seeding/resources/lang/vi/filament.php',
            ],
            'compat_common' => [
                'addons/seo-content-ai-compat/lang/en/common.php',
                'addons/seo-content-ai-compat/lang/vi/common.php',
            ],
            'compat_prompt_hooks' => [
                'addons/seo-content-ai-compat/lang/en/prompt_hooks.php',
                'addons/seo-content-ai-compat/lang/vi/prompt_hooks.php',
            ],
        ];
    }

    #[DataProvider('strictPhpLangPairsProvider')]
    public function test_strict_php_lang_pairs_have_key_parity(string $enRel, string $viRel): void
    {
        $enPath = base_path($enRel);
        $viPath = base_path($viRel);
        self::assertFileExists($enPath);
        self::assertFileExists($viPath);

        $en = $this->flattenPhpLang(include $enPath);
        $vi = $this->flattenPhpLang(include $viPath);

        $enOnly = array_values(array_diff(array_keys($en), array_keys($vi)));
        $viOnly = array_values(array_diff(array_keys($vi), array_keys($en)));

        self::assertSame([], $enOnly, "{$enRel} keys missing in {$viRel}: ".implode(', ', $enOnly));
        self::assertSame([], $viOnly, "{$viRel} keys missing in {$enRel}: ".implode(', ', $viOnly));
    }

    public function test_seo_filament_lang_parity_does_not_worsen(): void
    {
        // Baseline from project-wide audit (2026-03). Repair batches should lower these.
        $maxEnOnly = 52;
        $maxViOnly = 194;

        $en = $this->flattenPhpLang(include base_path('addons/seo-content-ai-compat/lang/en/filament.php'));
        $vi = $this->flattenPhpLang(include base_path('addons/seo-content-ai-compat/lang/vi/filament.php'));

        $enOnly = array_diff(array_keys($en), array_keys($vi));
        $viOnly = array_diff(array_keys($vi), array_keys($en));

        self::assertLessThanOrEqual(
            $maxEnOnly,
            count($enOnly),
            'seo-content-ai filament.php EN-only keys grew (was ≤'.$maxEnOnly.'). New keys must be added to both locales. Sample: '
            .implode(', ', array_slice($enOnly, 0, 15))
        );
        self::assertLessThanOrEqual(
            $maxViOnly,
            count($viOnly),
            'seo-content-ai filament.php VI-only keys grew (was ≤'.$maxViOnly.'). Prefer adding EN or removing dead VI keys.'
        );
    }

    public function test_hardcoded_vietnamese_nav_model_props_do_not_grow(): void
    {
        $found = $this->scanHardcodedViNavProps();
        sort($found);
        $known = self::KNOWN_HARDCODED_VI_NAV_PROPS;
        sort($known);

        $newDebt = array_values(array_diff($found, $known));
        $resolved = array_values(array_diff($known, $found));

        self::assertSame(
            [],
            $newDebt,
            "New hardcoded-VI Filament nav/model labels detected. Localize them or update KNOWN_HARDCODED_VI_NAV_PROPS after fixing: "
            .implode(', ', $newDebt)
        );

        // Allow shrinking the known list without failing — remind via incomplete diff only when grown.
        if ($resolved !== []) {
            // Soft signal in assertion message if someone forgets to trim allowlist — still pass.
            self::assertTrue(
                true,
                'Resolved hardcoded-VI sites (trim KNOWN_HARDCODED_VI_NAV_PROPS): '.implode(', ', $resolved)
            );
        }
    }

    /**
     * @return list<string>
     */
    private function scanHardcodedViNavProps(): array
    {
        $hits = [];
        $vi = self::VI_CHAR_CLASS;
        $propRe = '/protected\s+static\s+\?string\s+\$(navigationGroup|navigationLabel|modelLabel|pluralModelLabel)\s*=\s*[\'"]([^\'"]*['.$vi.'][^\'"]*)[\'"]/u';
        $navGroupRe = '/NavigationGroup::make\(\s*[\'"]([^\'"]*['.$vi.'][^\'"]*)[\'"]/u';
        $constRe = '/const\s+GROUP_SYSTEM\s*=\s*[\'"]([^\'"]*['.$vi.'][^\'"]*)[\'"]/u';

        $clientRoots = [
            base_path('app/Filament'),
            base_path('app/Providers/Filament'),
        ];
        foreach ($clientRoots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach ($this->phpFiles($root) as $file) {
                $src = (string) file_get_contents($file);
                $rel = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));
                if (preg_match_all($propRe, $src, $m, PREG_SET_ORDER)) {
                    foreach ($m as $row) {
                        $hits[] = $rel.'|$'.$row[1];
                    }
                }
                if (preg_match($navGroupRe, $src)) {
                    $hits[] = $rel.'|NavigationGroup::make';
                }
            }
        }

        $addons = base_path('addons');
        $addonTargets = [
            'seo/src/Support/SeoUserNavigation.php',
            'search-foundation/src/Filament/Pages/Statistics.php',
        ];
        foreach ($addonTargets as $rel) {
            $file = $addons.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (! is_file($file)) {
                continue;
            }
            $src = (string) file_get_contents($file);
            $key = 'addons:'.$rel;
            if (str_contains($rel, 'SeoUserNavigation') && preg_match($constRe, $src)) {
                $hits[] = $key.'|GROUP_SYSTEM';
            }
            if (preg_match_all($propRe, $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $row) {
                    $hits[] = $key.'|$'.$row[1];
                }
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function loadJsonKeys(string $path): array
    {
        self::assertFileExists($path);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $this->flattenAssoc($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function flattenPhpLang(array $data): array
    {
        return $this->flattenAssoc($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function flattenAssoc(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (is_array($v)) {
                /** @var array<string, mixed> $v */
                $out += $this->flattenAssoc($v, $key);
            } else {
                $out[$key] = is_scalar($v) || $v === null ? (string) $v : '';
            }
        }

        return $out;
    }
}
