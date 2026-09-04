<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Converts staff-uploaded video into the one format WordPress stores.
 *
 * The market sites only ever hold MP4 profile videos: the sync plugin accepts
 * nothing else and the theme's video listing queries `video/mp4` directly. So
 * the CRM normalises here, at the edge, instead of teaching WordPress about
 * more containers.
 */
class VideoTranscodeService
{
    /** Containers we accept from staff and convert on the way through. */
    public const CONVERTIBLE_EXTENSIONS = ['mov', 'qt'];

    private ?bool $availability = null;

    private ?string $resolvedFfmpeg = null;

    private ?string $resolvedFfprobe = null;

    public static function needsConversion(string $extension): bool
    {
        return in_array(strtolower(trim($extension)), self::CONVERTIBLE_EXTENSIONS, true);
    }

    /**
     * Whether this host can convert at all. Cached per instance: the probe
     * shells out, and the upload path asks more than once per request.
     */
    public function available(): bool
    {
        if ($this->availability !== null) {
            return $this->availability;
        }

        $this->availability = $this->ffmpegPath() !== null;

        return $this->availability;
    }

    public function ffmpegPath(): ?string
    {
        if ($this->resolvedFfmpeg !== null) {
            return $this->resolvedFfmpeg ?: null;
        }

        $this->resolvedFfmpeg = (string) ($this->resolveBinary('ffmpeg', config('media.ffmpeg_path')) ?? '');

        return $this->resolvedFfmpeg ?: null;
    }

    public function ffprobePath(): ?string
    {
        if ($this->resolvedFfprobe !== null) {
            return $this->resolvedFfprobe ?: null;
        }

        $this->resolvedFfprobe = (string) ($this->resolveBinary('ffprobe', config('media.ffprobe_path')) ?? '');

        return $this->resolvedFfprobe ?: null;
    }

    /**
     * Describe the transcoding capability of this host, for diagnostics.
     *
     * @return array<string, mixed>
     */
    public function capability(): array
    {
        return [
            'available' => $this->available(),
            'ffmpeg_path' => $this->ffmpegPath(),
            'ffprobe_path' => $this->ffprobePath(),
            'process_functions_enabled' => $this->processFunctionsEnabled(),
            'disabled_functions' => $this->disabledProcessFunctions(),
            'timeout_seconds' => (int) config('media.transcode_timeout', 900),
        ];
    }

    /**
     * Convert a video file to MP4 (H.264/AAC).
     *
     * Streams that are already H.264 are remuxed rather than re-encoded — a
     * .mov shot as H.264 becomes an .mp4 in seconds instead of minutes. Only
     * HEVC and friends pay for a full transcode, which is also the case that
     * has to happen: browsers other than Safari mostly cannot play it.
     *
     * @return array{ok:bool, mode:string, message:string, duration_seconds:float}
     */
    public function toMp4(string $sourcePath, string $targetPath): array
    {
        $startedAt = microtime(true);

        if (! $this->available()) {
            return [
                'ok' => false,
                'mode' => 'none',
                'message' => 'Video conversion is not available on this server.',
                'duration_seconds' => 0.0,
            ];
        }

        if (! is_readable($sourcePath)) {
            return [
                'ok' => false,
                'mode' => 'none',
                'message' => 'The uploaded video could not be read for conversion.',
                'duration_seconds' => 0.0,
            ];
        }

        $videoCodec = $this->streamCodec($sourcePath, 'v');
        $audioCodec = $this->streamCodec($sourcePath, 'a');
        $canRemux = $videoCodec === 'h264' && in_array($audioCodec, ['aac', '', null], true);
        $mode = $canRemux ? 'remux' : 'transcode';

        $arguments = $canRemux
            ? $this->remuxArguments($sourcePath, $targetPath)
            : $this->transcodeArguments($sourcePath, $targetPath);

        $result = $this->run($arguments, (int) config('media.transcode_timeout', 900));

        if (! $result['ok']) {
            @unlink($targetPath);

            Log::warning('Video conversion failed.', [
                'mode' => $mode,
                'video_codec' => $videoCodec,
                'audio_codec' => $audioCodec,
                'error' => $result['message'],
            ]);

            return [
                'ok' => false,
                'mode' => $mode,
                'message' => $result['message'],
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
            ];
        }

        if (! is_file($targetPath) || filesize($targetPath) <= 0) {
            @unlink($targetPath);

            return [
                'ok' => false,
                'mode' => $mode,
                'message' => 'Conversion produced an empty file.',
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
            ];
        }

        return [
            'ok' => true,
            'mode' => $mode,
            'message' => $canRemux
                ? 'Repackaged to MP4 without re-encoding.'
                : 'Re-encoded to H.264 MP4.',
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function remuxArguments(string $sourcePath, string $targetPath): array
    {
        return [
            $this->ffmpegPath(), '-y', '-i', $sourcePath,
            '-c', 'copy',
            '-movflags', '+faststart',
            $targetPath,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function transcodeArguments(string $sourcePath, string $targetPath): array
    {
        $maxHeight = max(360, (int) config('media.transcode_max_height', 1280));

        return [
            $this->ffmpegPath(), '-y', '-i', $sourcePath,
            '-map_metadata', '-1',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '26',
            '-pix_fmt', 'yuv420p',
            // Never upscale, and keep both edges even for H.264.
            '-vf', "scale=-2:'min({$maxHeight},ih)'",
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            $targetPath,
        ];
    }

    private function streamCodec(string $sourcePath, string $streamType): ?string
    {
        $ffprobe = $this->ffprobePath();
        if ($ffprobe === null) {
            return null;
        }

        $result = $this->run([
            $ffprobe, '-v', 'error',
            '-select_streams', $streamType . ':0',
            '-show_entries', 'stream=codec_name',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $sourcePath,
        ], 60);

        return $result['ok'] ? strtolower(trim($result['output'])) : null;
    }

    /**
     * @param  array<int, string|null>  $arguments
     * @return array{ok:bool, output:string, message:string}
     */
    private function run(array $arguments, int $timeout): array
    {
        $arguments = array_values(array_filter($arguments, fn ($value): bool => $value !== null && $value !== ''));

        try {
            $process = new Process($arguments);
            $process->setTimeout($timeout);
            $process->run();
        } catch (ProcessTimedOutException) {
            return [
                'ok' => false,
                'output' => '',
                'message' => sprintf('Conversion timed out after %d seconds.', $timeout),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'output' => '',
                'message' => $exception->getMessage(),
            ];
        }

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            return [
                'ok' => false,
                'output' => $process->getOutput(),
                // ffmpeg is chatty; the last line carries the actual reason.
                'message' => $this->lastLine($stderr) ?: 'Conversion failed.',
            ];
        }

        return [
            'ok' => true,
            'output' => $process->getOutput(),
            'message' => '',
        ];
    }

    private function lastLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line): bool => $line !== ''));

        return $lines === [] ? '' : (string) end($lines);
    }

    private function resolveBinary(string $binary, ?string $configured): ?string
    {
        if (is_string($configured) && trim($configured) !== '') {
            return is_executable($configured) ? $configured : null;
        }

        if (! $this->processFunctionsEnabled()) {
            return null;
        }

        $candidates = [
            '/usr/bin/' . $binary,
            '/usr/local/bin/' . $binary,
            '/opt/homebrew/bin/' . $binary,
            '/opt/cpanel/ffmpeg/bin/' . $binary,
        ];

        $home = (string) (getenv('HOME') ?: '');
        if ($home !== '') {
            $candidates[] = rtrim($home, '/') . '/bin/' . $binary;
        }

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $located = $this->run(['/usr/bin/which', $binary], 10);
        $path = $located['ok'] ? trim($located['output']) : '';

        return $path !== '' && is_executable($path) ? $path : null;
    }

    private function processFunctionsEnabled(): bool
    {
        return $this->disabledProcessFunctions() === [];
    }

    /**
     * @return array<int, string>
     */
    private function disabledProcessFunctions(): array
    {
        $disabled = array_map(
            'trim',
            explode(',', strtolower((string) ini_get('disable_functions')))
        );

        return array_values(array_intersect(['proc_open', 'proc_get_status'], $disabled));
    }
}
