<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Site;
use App\Models\User;
use App\Support\Automation\AutomationModel;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentAutomation;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Media\Models\ArticleMediaState;
use Omnichannel\Addons\Publishing\Models\PublishingArticleState;
use Omnichannel\Addons\Seo\Models\SeoArticleProfile;
use Omnichannel\Addons\WordPress\Models\WordpressArticleLink;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Final DB plane assertions (connection names, not physical DB names).
 * Physical: mysql → omi_client; omi_seo_ai → addon business.
 */
final class FinalDatabaseConnectionAssertionTest extends TestCase
{
    #[Test]
    public function core_identity_models_use_core_connection_never_channel(): void
    {
        $core = (string) config('database.core_connection', 'mysql');

        $this->assertSame($core, (new User)->getConnectionName());
        $this->assertSame($core, (new Site)->getConnectionName());
        $this->assertNotSame('omi_channel', (new User)->getConnectionName());
        $this->assertNotSame('omi_channel', (new Site)->getConnectionName());
        $this->assertNotSame('omi_seo_ai', (new User)->getConnectionName());
    }

    #[Test]
    public function automation_models_follow_config_and_never_channel(): void
    {
        config(['automation.connection' => 'mysql']);

        $this->assertSame('mysql', (new BusinessEvent)->getConnectionName());
        $this->assertSame('mysql', (new AutomationRule)->getConnectionName());
        $this->assertSame('mysql', (new AutomationExecution)->getConnectionName());
        $this->assertInstanceOf(AutomationModel::class, new BusinessEvent);
        $this->assertNotSame('omi_channel', (new BusinessEvent)->getConnectionName());
    }

    #[Test]
    public function automation_config_file_default_is_mysql(): void
    {
        $source = file_get_contents(config_path('automation.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString("env('AUTOMATION_DB_CONNECTION', 'mysql')", $source);
        $this->assertStringNotContainsString("env('AUTOMATION_DB_CONNECTION', 'omi_seo_ai')", $source);
    }

    #[Test]
    public function business_addon_models_declare_omi_seo_ai_connection(): void
    {
        foreach ([
            new SeoArticle,
            new ArticleMediaState,
            new SeoArticleProfile,
            new WordpressArticleLink,
            new PublishingArticleState,
            new SeoAgentAutomation,
        ] as $model) {
            $this->assertSame(
                'omi_seo_ai',
                $model->getConnectionName(),
                $model::class.' must declare omi_seo_ai',
            );
            $this->assertNotSame('omi_channel', $model->getConnectionName());
        }
    }
}
