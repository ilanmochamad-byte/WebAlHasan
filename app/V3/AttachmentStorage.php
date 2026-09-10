<?php

declare(strict_types=1);

namespace App\V3;

use finfo;

/** Penyimpanan bukti Fase 2 di luar jalur aset publik. */
final class AttachmentStorage
{
    public const MAX_BYTES = 5_242_880;
    private const TYPES = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];

    public function __construct(private string $directory)
    {
    }

    public function stageUpload(?array $upload): ?array
    {
        if ($upload === null || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int)($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new V3Exception('Lampiran gagal diterima.');
        }
        $temporary = (string)($upload['tmp_name'] ?? '');
        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw new V3Exception('Lampiran tidak sah.');
        }
        return $this->stageFile($temporary,(string)($upload['name'] ?? 'lampiran'),true);
    }

    public function stageBase64(?array $input): ?array
    {
        if ($input === null) { return null; }
        if (!is_string($input['data_base64'] ?? null) || !is_string($input['nama'] ?? null)) {
            throw new V3Exception('Format lampiran tidak valid.');
        }
        $raw = base64_decode((string)$input['data_base64'],true);
        if ($raw === false || $raw === '') { throw new V3Exception('Isi lampiran tidak valid.'); }
        if (strlen($raw) > self::MAX_BYTES) { throw new V3Exception('Lampiran maksimum 5 MB.'); }
        $this->ensureDirectory();
        $temporary = tempnam($this->directory,'.v3-stage-');
        if ($temporary === false || file_put_contents($temporary,$raw,LOCK_EX) !== strlen($raw)) {
            if (is_string($temporary) && is_file($temporary)) { @unlink($temporary); }
            throw new V3Exception('Lampiran tidak dapat disiapkan.',503);
        }
        try { return $this->stageFile($temporary,(string)$input['nama'],false); }
        finally { if (is_file($temporary)) { @unlink($temporary); } }
    }

    /** Hanya untuk fixture fiktif pada suite khusus. */
    public function stageTestFile(string $path, string $name): array
    {
        if (getenv('V3_RUN_TESTS') !== '1') { throw new V3Exception('Mode fixture tidak aktif.',403); }
        return $this->stageFile($path,$name,false);
    }

    public function finalize(array &$file): void
    {
        if (!rename((string)$file['pending'],(string)$file['absolute'])) {
            throw new V3Exception('Lampiran tidak dapat diamankan.',503);
        }
        $file['finalized'] = true;
    }

    public function discard(?array $file): void
    {
        if ($file === null) { return; }
        foreach (['pending','absolute'] as $key) {
            $path = (string)($file[$key] ?? '');
            if ($path !== '' && is_file($path)) { @unlink($path); }
        }
    }

    public function absolute(string $storedPath): string
    {
        $name = basename($storedPath);
        $path = rtrim($this->directory,'/').'/'.$name;
        if (!is_file($path)) { throw new V3Exception('Lampiran tidak ditemukan.',404); }
        return $path;
    }

    private function stageFile(string $source, string $originalName, bool $uploaded): array
    {
        $size = filesize($source);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new V3Exception('Lampiran wajib berisi data dan maksimum 5 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            throw new V3Exception('Lampiran hanya boleh JPG, PNG, atau PDF.');
        }
        $this->ensureDirectory();
        $token = bin2hex(random_bytes(24));
        $filename = $token.'.'.self::TYPES[$mime];
        $pending = $this->directory.'/.'.$filename.'.pending';
        $moved = $uploaded ? move_uploaded_file($source,$pending) : copy($source,$pending);
        if (!$moved) { throw new V3Exception('Lampiran tidak dapat disiapkan.',503); }
        @chmod($pending,0600);
        $safeName = mb_substr(trim(str_replace(["\0","\r","\n"],' ',basename($originalName))),0,255);
        if ($safeName === '') { $safeName = 'lampiran.'.self::TYPES[$mime]; }
        return [
            'name'=>$safeName,'mime'=>$mime,'size'=>(int)$size,'sha256'=>hash_file('sha256',$pending),
            'path'=>'storage/private/v3/'.$filename,'pending'=>$pending,
            'absolute'=>$this->directory.'/'.$filename,'finalized'=>false,
        ];
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory)) {
            throw new V3Exception('Penyimpanan privat tidak tersedia.',503);
        }
    }
}
