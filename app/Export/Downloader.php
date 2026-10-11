<?php


final class Downloader
{
    /** Un solo archivo → descarga directa. Varios → .zip */
    public static function send(array $files, string $zipName): bool
    {
        if (count($files) === 1) {
            self::headers($files[0]['mime'], $files[0]['name'], strlen($files[0]['content']));
            echo $files[0]['content'];
            exit;
        }
        return self::sendZip($files, $zipName);
    }

    private static function sendZip(array $files, string $zipName): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'moodle_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
        $used = [];
        foreach ($files as $f) $zip->addFromString(self::uniqueName($f['name'], $used), $f['content']);
        $zip->close();
        self::headers('application/zip', $zipName, filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    private static function uniqueName(string $name, array &$used): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME); $ext = pathinfo($name, PATHINFO_EXTENSION); $n = 1;
        while (in_array($name, $used, true)) $name = $base . '_' . (++$n) . '.' . $ext;
        $used[] = $name;
        return $name;
    }

    private static function headers(string $mime, string $filename, int $length): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $length);
    }
}
