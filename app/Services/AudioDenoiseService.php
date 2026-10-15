<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * عزل الضوضاء من ملف صوت/فيديو مرفوع (بيتطبّق على سيرفر قبل التخزين).
 *
 *  - لو ffmpeg فيه فلتر arnndn وموديل RNNoise موجود (resources/audio/rnnoise-bd.rnnn)
 *    بيستخدم الشبكة العصبية (جودة عالية للكلام).
 *  - غير كده بيرجع لـ afftdn (فلتر FFT) + high-pass.
 *  - الفيديو: بيتنسخ ستريم الصورة كما هو (-c:v copy) وبيتعالج الصوت بس، فمفيش فقد في الجودة ولا وقت إعادة ترميز.
 *  - Fail-open زي MediaProbeService: أي فشل = الملف الأصلي زي ما هو والـ caller بيعرف من القيمة الراجعة.
 */
class AudioDenoiseService
{
    /** امتداد => [codec الصوت, bitrate]. */
    private const CODECS = [
        'webm' => ['libopus', '64k'], 'weba' => ['libopus', '64k'], 'ogg' => ['libopus', '64k'], 'opus' => ['libopus', '64k'],
        'mkv'  => ['libopus', '64k'],
        'mp4'  => ['aac', '128k'],    'm4a'  => ['aac', '128k'],    'mov'  => ['aac', '128k'], 'aac' => ['aac', '128k'],
        'mp3'  => ['libmp3lame', '128k'], 'avi' => ['libmp3lame', '128k'],
        'wav'  => ['pcm_s16le', null],
    ];

    /** الامتدادات اللي ممكن يبقى فيها فيديو (بنحافظ على ستريم الصورة). */
    private const VIDEO_EXT = ['mp4', 'webm', 'mov', 'mkv', 'avi'];

    public function supports(string $extension): bool
    {
        return isset(self::CODECS[strtolower($extension)]);
    }

    public function available(): bool
    {
        return $this->commandExists($this->ffmpegBinary());
    }

    public function modelPath(): ?string
    {
        $path = (string) config('messaging.denoise.model_path', resource_path('audio/rnnoise-bd.rnnn'));
        return $path !== '' && is_file($path) ? $path : null;
    }

    /**
     * بيعالج الملف المرفوع "في مكانه" (نفس المسار المؤقت) عشان FileUploadService::store() يخزّن النسخة النضيفة.
     * @return bool true لو اتعالج فعلاً.
     */
    public function denoiseUpload(UploadedFile $file): bool
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $in  = $file->getRealPath();
        if (!$in || !$this->supports($ext) || !$this->available()) {
            return false;
        }

        $out = tempnam(sys_get_temp_dir(), 'dn_');
        if ($out === false) {
            return false;
        }
        @unlink($out);
        $out .= '.' . $ext;

        $ok = $this->run($in, $out, $ext);
        if ($ok) {
            // copy() مش rename(): مسار الـ tmp ممكن يكون على filesystem تاني.
            $ok = @copy($out, $in);
        }
        @unlink($out);
        return $ok;
    }

    private function run(string $in, string $out, string $ext): bool
    {
        [$codec, $bitrate] = self::CODECS[$ext];
        $model = $this->modelPath();

        $filter = $model
            ? 'highpass=f=70,arnndn=m=' . basename($model) . ':mix=1'
            : 'highpass=f=70,afftdn=nf=-30:nr=20:tn=1,lowpass=f=14000';

        $cmd = [$this->ffmpegBinary(), '-nostdin', '-y', '-v', 'error', '-i', $in];
        if (in_array($ext, self::VIDEO_EXT, true)) {
            array_push($cmd, '-map', '0:v?', '-c:v', 'copy');
        }
        array_push($cmd, '-map', '0:a:0', '-af', $filter, '-c:a', $codec);
        if ($bitrate) {
            array_push($cmd, '-b:a', $bitrate);
        }
        if ($ext === 'mp4' || $ext === 'm4a' || $ext === 'mov') {
            array_push($cmd, '-movflags', '+faststart');
        }
        $cmd[] = $out;

        $log = tempnam(sys_get_temp_dir(), 'dnlog_');
        $proc = @proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']],
            $pipes,
            $model ? dirname($model) : null // arnndn بياخد اسم الملف نسبي → من غير مشاكل escape لمسارات Windows (C:\…)
        );
        if (!is_resource($proc)) {
            @unlink($log);
            return false;
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + max(5, $this->timeout());
        do {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($proc, 9);
            proc_close($proc);
            Log::warning('Noise reduction timed out', ['file' => basename($in)]);
            @unlink($log);
            return false;
        }
        $exit = $status['exitcode'];
        proc_close($proc);

        $good = ($exit === 0 || $exit === -1) && is_file($out) && filesize($out) > 0;
        if (!$good) {
            Log::warning('ffmpeg noise reduction failed', ['file' => basename($in), 'exit' => $exit, 'log' => mb_substr((string) @file_get_contents($log), -600)]);
        }
        @unlink($log);
        return $good && $exit === 0;
    }

    private function commandExists(string $binary): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $check = DIRECTORY_SEPARATOR === '\\'
            ? 'where ' . escapeshellarg($binary) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';
        @exec($check, $output, $code);
        return $code === 0 && !empty($output);
    }

    private function ffmpegBinary(): string
    {
        return (string) config('messaging.media_probe.ffmpeg_binary', 'ffmpeg');
    }

    private function timeout(): int
    {
        // معالجة صوت كامل أطول من thumbnail: ضعف المدة المسموحة للـ probe كحد أدنى 120 ثانية.
        return (int) config('messaging.denoise.timeout', 180);
    }
}
