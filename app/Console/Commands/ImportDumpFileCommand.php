<?php

namespace App\Console\Commands;

use App\Jobs\ImportSystemsDumpFileJob;
use App\Services\JsonLargeFileSplitService;
use Illuminate\Console\Command;

class ImportDumpFileCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:dumpfile
        {--type= : The type of dump file to import.}
        {--file= : The dump file, located at `/storage/dumps`.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import records from `/storage/dumps` dump files.';

    /**
     * JSON large file service for splitting.
     */
    private JsonLargeFileSplitService $jsonLargeFileSplitService;

    /**
     * Constructor
     */
    public function __construct(JsonLargeFileSplitService $jsonLargeFileSplitService)
    {
        $this->jsonLargeFileSplitService = $jsonLargeFileSplitService;

        return parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Get the file
        $filename = $this->option('file');
        $filepath = storage_path("dumps/{$filename}");
        if (! file_exists($filepath)) {
            $this->error('File not found!');

            return;
        }

        $this->line("Configuring import job for {$filename}");
        $type = $this->option('type'); // e.g. systems

        // Get the file size and set the threshold for type of processing
        $threshold = 1073741824; // 1GB

        // If it's large, split it into parts
        if (filesize($filepath) > $threshold) {
            $this->warn("{$filename} is larger than ".bytes_format($threshold));
            $this->line('The file will need to be split into parts for parallel processing.');
            $parts = (int) $this->ask('How many parts should the file be split into?', 16);
            if ($parts < 2) {
                $this->error('Split parts must be at least 2.');
                return;
            }
            $this->jsonLargeFileSplitService->split($filename, $filepath, $parts);
            $this->info("Successfully split {$filename} into {$parts} parts.");

            for ($part = 1; $part <= $parts; $part++) {
                $this->info("Dispatching part {$part} import job for processing...");

                $filename = pathinfo($this->option('file'), PATHINFO_FILENAME)."_part_{$part}.json";
                $this->dispatchJob($type, $filename);
            }

            $this->warn('Please ensure you have enough queue workers for parallel processing.');
        } else {
            $this->dispatchJob($type, $filename);
        }
    }

    /**
     * Dispatch a job to process the file.
     *
     * @param string $type
     * @param string $filename
     * @return void
     */
    private function dispatchJob(string $type, string $filename)
    {
        if ($type === 'systems') {
            ImportSystemsDumpFileJob::dispatch('import:system', $filename)
                ->onQueue('default');
            $this->info('Systems import job has been dispatched.');
        } else {
            $this->error('Type does not match a valid job type.');
        }
    }
}
