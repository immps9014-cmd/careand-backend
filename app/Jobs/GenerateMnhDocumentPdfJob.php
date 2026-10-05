<?php

namespace App\Jobs;

use App\Models\MnhDocument;
use App\Services\MnhDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** 서명된 바우처 서류 → PDF(크롬 인쇄). 큐 워커가 root 라 ~/.cache 의 크롬을 쓴다(php-fpm=apache 는 못 씀). */
class GenerateMnhDocumentPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 80;

    public function __construct(public int $documentId)
    {
        $this->afterCommit();
    }

    public function handle(MnhDocumentService $svc): void
    {
        $doc = MnhDocument::find($this->documentId);
        if ($doc && $doc->status === 'signed' && !$doc->pdf_path) {
            $svc->generatePdf($doc);
        }
    }
}
