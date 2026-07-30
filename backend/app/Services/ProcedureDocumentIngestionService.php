<?php

namespace App\Services;

use App\Jobs\ProcessProcedureVersionDocument;
use App\Models\Embedding;
use App\Models\Procedure;
use App\Models\ProcedureVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcedureDocumentIngestionService
{
    public function __construct(
        private readonly DocumentTextExtractor $textExtractor,
        private readonly EmbeddingGenerationService $embeddingGenerationService,
    ) {
    }

    public function createVersion(Procedure $procedure, UploadedFile $file): ProcedureVersion
    {
        $storedPath = $file->storeAs(
            'procedures/' . $procedure->filiale_id . '/' . $procedure->id,
            now()->format('YmdHis') . '_' . $file->getClientOriginalName(),
            'local'
        );

        $version = DB::transaction(function () use ($procedure, $storedPath) {
            $nextVersionNumber = ((int) ProcedureVersion::where('procedure_id', $procedure->id)->max('version_number')) + 1;

            $version = ProcedureVersion::create([
                'filiale_id' => $procedure->filiale_id,
                'procedure_id' => $procedure->id,
                'version_number' => $nextVersionNumber,
                'pdf_url' => $storedPath,
                'infographic_url' => null,
                'video_url' => null,
                'published_at' => now(),
            ]);

            $procedure->update([
                'current_version_id' => $version->id,
            ]);

            return $version;
        });

        ProcessProcedureVersionDocument::dispatch($version->id, $version->filiale_id)->afterCommit();

        return $version->load(['procedure', 'filiale']);
    }

    public function processVersion(ProcedureVersion $version): void
    {
        $storedPath = $version->pdf_url;

        if (! Storage::disk('local')->exists($storedPath)) {
            return;
        }

        $documentText = $this->textExtractor->extract($storedPath);

        if (trim($documentText) === '') {
            return;
        }

        Embedding::where('procedure_version_id', $version->id)->delete();

        foreach ($this->chunkText($documentText) as $index => $chunkText) {
            Embedding::create([
                'filiale_id' => $version->filiale_id,
                'procedure_version_id' => $version->id,
                'chunk_text' => $chunkText,
                'chunk_index' => $index,
                'embedding' => $this->embeddingGenerationService->generate($chunkText),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function chunkText(string $text, int $chunkSize = 1200, int $overlap = 150): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        if ($normalized === '') {
            return [];
        }

        $chunks = [];
        $length = mb_strlen($normalized);
        $start = 0;

        while ($start < $length) {
            $chunk = mb_substr($normalized, $start, $chunkSize);

            if ($chunk === '') {
                break;
            }

            $chunks[] = trim($chunk);
            $start += max(1, $chunkSize - $overlap);
        }

        return $chunks;
    }
}