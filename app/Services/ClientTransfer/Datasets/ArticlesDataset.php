<?php

declare(strict_types=1);

namespace App\Services\ClientTransfer\Datasets;

use App\Services\ClientTransfer\Exceptions\BlobChecksumMismatchException;
use App\Services\ClientTransfer\Logging\ImportRun;
use App\Services\ClientTransfer\Support\BlobManager;
use App\Services\ClientTransfer\Support\ReferenceMap;
use Omnichannel\Addons\Content\Models\SeoArticle;

final class ArticlesDataset extends BaseDataset
{
    public function key(): string
    {
        return 'articles';
    }

    public function relativeSubdir(): string
    {
        return 'content/articles';
    }

    public function dependencies(): array
    {
        return ['sites', 'users'];
    }

    public function maxRecordsPerPart(): int
    {
        return 500;
    }

    public function sliceLimit(): int
    {
        return 100;
    }

    protected function queryForExport(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        return SeoArticle::withTrashed();
    }

    protected function mapRecordForExport(mixed $row, BlobManager $blobs): ?array
    {
        $body = (string) ($row->body ?? '');
        $blobInfo = $blobs->store($body, 'html');

        return [
            'ref' => 'article:'.$row->id,
            'site_ref' => 'site:'.$row->site_id,
            'author_ref' => $row->author_id ? ('user:'.$row->author_id) : null,
            'title' => (string) $row->title,
            'slug' => (string) ($row->slug ?? ''),
            'language' => $row->language,
            'status' => (string) ($row->status ?? 'draft'),
            'excerpt' => $row->excerpt,
            'focus_keyword' => $row->focus_keyword,
            'canonical_url' => $row->canonical_url,
            'document_version' => $row->document_version !== null ? (int) $row->document_version : 1,
            'editor_document_schema_version' => $row->editor_document_schema_version !== null ? (int) $row->editor_document_schema_version : null,
            'editor_document_updated_at' => $row->editor_document_updated_at?->toIso8601String(),
            'review_status' => $row->review_status,
            'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            'reviewed_by_ref' => $row->reviewed_by ? ('user:'.$row->reviewed_by) : null,
            'review_notes' => $row->review_notes,
            'last_manual_saved_at' => $row->last_manual_saved_at?->toIso8601String(),
            'last_ai_content_at' => $row->last_ai_content_at?->toIso8601String(),
            'editor_document' => $row->editor_document,
            'blocks' => $row->blocks,
            'body_blob' => $blobInfo['path'],
            'body_sha256' => $blobInfo['sha256'],
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
            'deleted_at' => $row->deleted_at?->toIso8601String(),
        ];
    }

    public function importRecord(
        array $record,
        ReferenceMap $refMap,
        ImportRun $run,
        BlobManager $blobs,
        string $partFile,
        int $recordIndex,
    ): void {
        $ref = (string) ($record['ref'] ?? '');
        $siteRef = (string) ($record['site_ref'] ?? '');
        $authorRef = (string) ($record['author_ref'] ?? '');
        $title = (string) ($record['title'] ?? '');

        if ($title === '') {
            $run->markFailedRoot($ref);
            $run->recordFailed('articles', $ref, 'VALIDATION', 'Article title is required.', $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        $siteId = $refMap->get($siteRef);
        if ($siteId === null) {
            $run->markFailedRoot($ref);
            $run->recordFailed('articles', $ref, 'MISSING_DEP', "Missing site dependency [{$siteRef}]", $partFile, $recordIndex, rawRecord: $record);

            return;
        }

        $authorId = null;
        if ($authorRef !== '') {
            $authorId = $refMap->get($authorRef);
        }

        $reviewedBy = null;
        if (! empty($record['reviewed_by_ref'])) {
            $reviewedBy = $refMap->get((string) $record['reviewed_by_ref']);
        }

        // Read and verify body blob
        $body = '';
        if (! empty($record['body_blob']) && ! empty($record['body_sha256'])) {
            $blobRelPath = (string) $record['body_blob'];
            $expectedSha = (string) $record['body_sha256'];
            $fullBlobPath = dirname($blobs->blobDirectory, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $blobRelPath);

            try {
                $body = $blobs->readAndVerify(
                    fullPath: $fullBlobPath,
                    expectedSha256: $expectedSha,
                    dataset: 'articles',
                    recordRef: $ref,
                    field: 'body',
                );
            } catch (BlobChecksumMismatchException $e) {
                $run->markFailedRoot($ref);
                $run->recordFailed('articles', $ref, 'CHECKSUM_MISMATCH', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);

                return;
            } catch (\Throwable $e) {
                $run->markFailedRoot($ref);
                $run->recordFailed('articles', $ref, 'BLOB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);

                return;
            }
        }

        try {
            $article = new SeoArticle;
            $article->site_id = $siteId;
            $article->author_id = $authorId;
            $article->title = $title;
            $article->slug = (string) ($record['slug'] ?? '');
            if (isset($record['language'])) {
                $article->language = $record['language'];
            }
            $article->status = (string) ($record['status'] ?? 'draft');
            $article->excerpt = $record['excerpt'] ?? null;
            $article->focus_keyword = $record['focus_keyword'] ?? null;
            $article->canonical_url = $record['canonical_url'] ?? null;
            $article->body = $body;
            $article->editor_document = $record['editor_document'] ?? null;
            $article->blocks = $record['blocks'] ?? null;
            if (isset($record['document_version'])) {
                $article->document_version = (int) $record['document_version'];
            }
            if (isset($record['editor_document_schema_version'])) {
                $article->editor_document_schema_version = (int) $record['editor_document_schema_version'];
            }
            if (! empty($record['editor_document_updated_at'])) {
                $article->editor_document_updated_at = $record['editor_document_updated_at'];
            }
            $article->review_status = $record['review_status'] ?? null;
            if (! empty($record['reviewed_at'])) {
                $article->reviewed_at = $record['reviewed_at'];
            }
            $article->reviewed_by = $reviewedBy;
            $article->review_notes = $record['review_notes'] ?? null;
            if (! empty($record['last_manual_saved_at'])) {
                $article->last_manual_saved_at = $record['last_manual_saved_at'];
            }
            if (! empty($record['last_ai_content_at'])) {
                $article->last_ai_content_at = $record['last_ai_content_at'];
            }
            if (! empty($record['created_at'])) {
                $article->created_at = $record['created_at'];
            }
            if (! empty($record['updated_at'])) {
                $article->updated_at = $record['updated_at'];
            }
            if (! empty($record['deleted_at'])) {
                $article->deleted_at = $record['deleted_at'];
            }

            $article->saveQuietly();

            $refMap->set($ref, 'article', (int) $article->id);
            $run->recordImported('articles', $ref, $partFile, $recordIndex);
        } catch (\Throwable $e) {
            $run->markFailedRoot($ref);
            $run->recordFailed('articles', $ref, 'DB_ERROR', $e->getMessage(), $partFile, $recordIndex, rawRecord: $record);
        }
    }
}
