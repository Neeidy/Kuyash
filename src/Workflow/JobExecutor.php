<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

/**
 * The executor seam. Real providers (Phase 5 OpenAI, Phase 7 TTS/Pexels/
 * ffmpeg, Phase 10 Zernio) implement this per job type and register in
 * ExecutorRegistry — one adapter class + one registry line per swap.
 *
 * execute() runs OUTSIDE any transaction (sqlite rule: no external call ever
 * holds a transaction). Throwables are treated as a failed attempt by the
 * worker — executors may throw freely.
 */
interface JobExecutor
{
    /**
     * @param array<string, mixed>                $job   the claimed jobs row
     * @param array<string, array<string, mixed>> $prior result arrays of finished
     *                                                   jobs in this run, keyed by job type
     */
    public function execute(array $job, array $prior): JobResult;
}
