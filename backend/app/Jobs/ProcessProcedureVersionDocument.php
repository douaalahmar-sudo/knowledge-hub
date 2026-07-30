<?php

namespace App\Jobs;

use App\Models\ProcedureVersion;
use App\Services\ProcedureDocumentIngestionService;
use App\Support\Database\FilialeContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessProcedureVersionDocument implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $procedureVersionId,
        public ?string $filialeId = null,
    ) {
    }

    /**
     * A queue worker has no HTTP request, so SetTenantContext never runs for it
     * and the RLS policies would hide the very row this job exists to process.
     * The filiale therefore travels with the job payload and is published for
     * the duration of the work.
     */
    public function handle(ProcedureDocumentIngestionService $ingestionService): void
    {
        FilialeContext::runAs($this->filialeId, function () use ($ingestionService) {
            $version = ProcedureVersion::with('filiale')->find($this->procedureVersionId);

            if (! $version) {
                return;
            }

            $ingestionService->processVersion($version);
        });
    }
}