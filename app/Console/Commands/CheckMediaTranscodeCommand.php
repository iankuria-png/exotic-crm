<?php

namespace App\Console\Commands;

use App\Services\VideoTranscodeService;
use Illuminate\Console\Command;

/**
 * Read-only probe: can this host convert MOV uploads to MP4?
 *
 * Staff-facing MOV support is gated on the answer, so this exists to be run on
 * cPanel rather than inferred from a local machine.
 */
class CheckMediaTranscodeCommand extends Command
{
    protected $signature = 'crm:media-transcode-check';

    protected $description = 'Report whether this server can convert MOV profile videos to MP4.';

    public function handle(VideoTranscodeService $transcoder): int
    {
        $capability = $transcoder->capability();

        $this->line('');
        $this->line('Video conversion capability');
        $this->line('---------------------------');

        $this->table(['Check', 'Value'], [
            ['Conversion available', $capability['available'] ? 'YES' : 'NO'],
            ['ffmpeg', $capability['ffmpeg_path'] ?: 'not found'],
            ['ffprobe', $capability['ffprobe_path'] ?: 'not found (falls back to full re-encode)'],
            ['proc_open usable', $capability['process_functions_enabled'] ? 'yes' : 'NO — disabled in php.ini'],
            ['Disabled functions', $capability['disabled_functions'] === [] ? 'none relevant' : implode(', ', $capability['disabled_functions'])],
            ['Conversion timeout', $capability['timeout_seconds'].'s'],
            ['Queue lane', 'heavy (database_long)'],
        ]);

        if ($capability['available']) {
            $this->info('MOV uploads will be converted to MP4 before they reach WordPress.');

            return self::SUCCESS;
        }

        $this->warn('MOV uploads are rejected at the upload form on this host.');
        $this->line('');
        $this->line('To enable conversion:');
        $this->line('  1. Upload a static ffmpeg build into the account, e.g. ~/bin/ffmpeg (and ffprobe).');
        $this->line('  2. chmod +x ~/bin/ffmpeg ~/bin/ffprobe');
        $this->line('  3. Add to .env:  FFMPEG_PATH=/home/<cpanel-user>/bin/ffmpeg');
        $this->line('                   FFPROBE_PATH=/home/<cpanel-user>/bin/ffprobe');
        $this->line('  4. php artisan config:clear, then re-run this command.');
        $this->line('');
        $this->line('Nothing else changes: MP4 uploads keep working either way.');

        return self::SUCCESS;
    }
}
