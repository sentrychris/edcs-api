<?php

namespace App\Console\Commands;

use App\Services\Eddn\EddnListenerService;
use App\Services\Eddn\EddnMarketService;
use App\Services\Eddn\EddnSystemService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;

class EddnListenCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'eddn:listen {--memory=256 : Memory limit in MB; the listener exits cleanly between batches when exceeded so a supervisor can restart it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen to EDDN and process incoming data';

    /**
     * Memory ceiling in bytes after which the listener exits between batches.
     */
    private int $memoryLimitBytes;

    public function __construct(
        private readonly EddnListenerService $eddnListenerService,
        private readonly EddnSystemService $eddnSystemService,
        private readonly EddnMarketService $eddnMarketService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Long-running daemons must not accumulate per-request buffers. Telescope
        // and the query log both keep entries in memory until a request lifecycle
        // ends, which never happens inside our while(true) recv loop.
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        DB::connection()->disableQueryLog();

        $this->memoryLimitBytes = ((int) $this->option('memory')) * 1024 * 1024;

        $this->info('Started EDDN listener...');
        $this->eddnListenerService->listen([$this, 'processBatch']);

        return self::SUCCESS;
    }

    /**
     * Callback to process message batches.
     */
    public function processBatch(array $batch): void
    {
        $this->eddnSystemService->process($batch);
        $this->eddnMarketService->process($batch);

        if (memory_get_usage(true) >= $this->memoryLimitBytes) {
            $message = sprintf(
                'EDDN listener exiting for restart: memory %dMB exceeded limit %dMB (peak %dMB).',
                memory_get_usage(true) / 1024 / 1024,
                $this->memoryLimitBytes / 1024 / 1024,
                memory_get_peak_usage(true) / 1024 / 1024,
            );

            Log::channel('eddn')->warning($message);
            $this->warn($message);

            exit(self::SUCCESS);
        }
    }
}
