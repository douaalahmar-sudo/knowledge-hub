<?php

namespace App\Jobs;

use App\Models\ProcedureVersion;
use App\Services\ProcedureDocumentIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessProcedureVersionDocument implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $procedureVersionId)
    {
    }

    public function handle(ProcedureDocumentIngestionService $ingestionService): void
    {
        $version = ProcedureVersion::with('tenant')->find($this->procedureVersionId);

        if (! $version) {
            return;
        }

        $ingestionService->processVersion($version);
    }
}