<?php

declare(strict_types=1);

namespace Tests\Feature\ClientTransfer;

use App\Models\Site;
use App\Models\User;
use App\Services\ClientTransfer\ClientTransferExporter;
use App\Services\ClientTransfer\ClientTransferImporter;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Tests\Unit\ClientTransfer\TransferDatabaseTestCase;

final class ArticleFidelityTest extends TransferDatabaseTestCase
{
    public function test_torture_article_exact_byte_and_hash_round_trip(): void
    {
        // 1. Create author and site
        $user = User::query()->create([
            'name' => 'Fidelity Author',
            'email' => 'author@fidelity.test',
            'role' => 'admin',
        ]);

        $site = Site::query()->create([
            'domain' => 'fidelity.test',
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        // 2. Build Torture Article Body containing all difficult payloads
        $tortureBody = <<<'EOT'
<!-- wp:heading {"level":1} -->
<h1 class="entry-title" data-custom="vi-test" style="font-family: 'Arial', sans-serif; color: #1a202c;">
    Hướng dẫn toàn diện SEO & Content Marketing: ắ, ế, ố, ừ, ỳ, ỡ, ễ... 🚀💡🔥🎉
</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>
    Đây là bài kiểm tra độ toàn vẹn dữ liệu (Data Fidelity Torture Test) với trích dẫn: "Trí tuệ nhân tạo" và 'Hệ thống Omnichannel'.
    Mã inline: `const apiKey = "xyz-123";` và đường dẫn Windows: C:\Users\Admin\Documents\Project\test.txt hoặc \\nas-server\share\data.
</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>
    Dòng 1 kết thúc bằng LF
    Dòng 2 kết thúc bằng CRLF:
Line A with CRLF
Line B with CRLF
</p>
<!-- /wp:paragraph -->

<div class="code-container" style="background: #f7fafc; padding: 15px; border-radius: 8px;">
    <script>
        console.log("XSS simulation: alert('Hello world');");
        const jsonLike = {"status": "ok", "items": [1, 2, 3], "nested": {"key": "value & symbols: © ® ™ § ¶ • → ←"}};
    </script>
    <style>
        .custom-widget { display: flex; align-items: center; justify-content: space-between; }
        .custom-widget::after { content: "»"; }
    </style>
</div>

[gallery ids="101,102,103" columns="3" size="medium" link="file"]

<!-- wp:quote -->
<blockquote class="wp-block-quote">
    <p>“Người thành công luôn tìm thấy cơ hội trong mọi khó khăn.”</p>
    <cite>— Tác giả ẩn danh</cite>
</blockquote>
<!-- /wp:quote -->
EOT;

        // Make it very long (20,000+ characters)
        $filler = "\n<p>Đoạn văn mở rộng với ký tự Unicode đặc biệt: Việt Nam tươi đẹp, công nghệ phát triển, dữ liệu số hóa. " . str_repeat('Bản ghi thử nghiệm kiểm tra dung lượng lớn và tính toàn vẹn từng byte. ', 150) . "</p>\n";
        $tortureBody .= $filler;

        $originalHash = hash('sha256', $tortureBody);
        $originalLength = strlen($tortureBody);

        // 3. Create the article in the source database
        $article = new SeoArticle();
        $article->site_id = (int) $site->id;
        $article->author_id = (int) $user->id;
        $article->title = 'Bài viết kiểm tra độ toàn vẹn: Tiếng Việt 🚀 "Quotes" & \\Backslashes\\';
        $article->slug = 'bai-viet-kiem-tra-do-toan-ven';
        $article->language = 'vi';
        $article->status = 'published';
        $article->excerpt = 'Tóm tắt bài viết với emoji 🎉 và dấu ngoặc.';
        $article->focus_keyword = 'kiểm tra độ toàn vẹn';
        $article->body = $tortureBody;
        $article->editor_document = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'text' => 'Structured JSON editor document test'],
            ],
        ];
        $article->blocks = [
            ['name' => 'core/paragraph', 'attributes' => ['content' => 'Gutenberg blocks structure']],
        ];
        $article->saveQuietly();

        $originalArticleId = (int) $article->id;

        // 4. Export to ZIP
        $zipPath = $this->tempDir . DIRECTORY_SEPARATOR . 'torture_export.zip';
        $exporter = new ClientTransferExporter();
        $exportResult = $exporter->export($zipPath);

        self::assertFileExists($zipPath);
        self::assertSame(1, $exportResult['counts']['articles']);

        // 5. Simulate fresh client target: wipe business tables
        $this->wipeBusinessTables();
        self::assertSame(0, SeoArticle::query()->count());

        // 6. Import package into fresh target
        $importer = new ClientTransferImporter();
        $importResult = $importer->import($zipPath);

        self::assertSame(0, $importResult['total_failed']);
        self::assertSame(0, $importResult['total_blocked']);
        self::assertSame(1, $importResult['dataset_stats']['articles']['imported']);

        // 7. Verify imported article fidelity
        $importedArticle = SeoArticle::query()->where('slug', 'bai-viet-kiem-tra-do-toan-ven')->firstOrFail();

        // Exact byte equality check
        self::assertSame($originalLength, strlen((string) $importedArticle->body), 'Article body length must be identical.');
        self::assertSame($originalHash, hash('sha256', (string) $importedArticle->body), 'Article body SHA-256 hash must be identical.');
        self::assertSame($tortureBody, $importedArticle->body, 'Article body string must be byte-for-byte identical.');

        // Structured JSON equality check
        self::assertSame('doc', $importedArticle->editor_document['type'] ?? null);
        self::assertSame('core/paragraph', $importedArticle->blocks[0]['name'] ?? null);

        // Metadata check
        self::assertSame('vi', $importedArticle->language);
        self::assertSame('published', $importedArticle->status);
    }
}
