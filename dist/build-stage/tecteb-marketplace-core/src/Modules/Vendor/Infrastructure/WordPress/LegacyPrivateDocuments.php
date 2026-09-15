<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

/**
 * Moves documents written by the first build — which kept them under
 * `wp-content/uploads/tmc-private/` — into the directory outside the web
 * roots that replaced it.
 *
 * Why this exists rather than "new files go to the new place": a site that
 * ran the earlier build still has licence scans sitting somewhere the web
 * server is willing to serve. Changing where the NEXT upload lands would fix
 * nothing for those, and the rows in the database point at paths relative to
 * the storage base, so moving the directories keeps every existing document
 * readable without touching a single row.
 *
 * It is deliberately timid: it copies nothing it cannot verify, removes a
 * source file only after its copy is in place, and leaves the legacy
 * directory alone the moment anything goes wrong — a half-moved document is
 * still readable from the old path, a deleted one is gone.
 */
final class LegacyPrivateDocuments
{
    public const DONE_OPTION = 'tmc_private_storage_relocated';

    /**
     * @return array{moved:int, failed:int, skipped:bool, reason:string}
     */
    public static function relocate(PrivateUploadStorage $storage): array
    {
        $legacy = PrivateUploadStorage::legacyBaseDir();
        $target = $storage->baseDir();
        if ($target === null) {
            return ['moved' => 0, 'failed' => 0, 'skipped' => true, 'reason' => 'no_safe_directory'];
        }
        if ($legacy === '' || !is_dir($legacy) || rtrim($legacy, '/') === rtrim($target, '/')) {
            return ['moved' => 0, 'failed' => 0, 'skipped' => true, 'reason' => 'nothing_to_move'];
        }

        $moved = 0;
        $failed = 0;
        foreach (self::documents($legacy) as $relative) {
            $from = $legacy . '/' . $relative;
            $to = $target . '/' . $relative;
            $dir = dirname($to);
            if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
                $failed++;
                continue;
            }
            if (file_exists($to)) {
                $failed++;                 // never overwrite: two different documents could collide
                continue;
            }
            if (!@rename($from, $to)) {
                // Different filesystems cannot be renamed across; copy, verify, then unlink.
                if (!@copy($from, $to) || filesize($to) !== filesize($from)) {
                    @unlink($to);
                    $failed++;
                    continue;
                }
                @unlink($from);
            }
            @chmod($to, 0o600);
            $moved++;
        }
        return ['moved' => $moved, 'failed' => $failed, 'skipped' => false, 'reason' => $failed === 0 ? 'complete' : 'partial'];
    }

    /**
     * Only files that look like documents this plugin wrote: one scope
     * directory, one 32-hex name. Anything else in that directory belongs to
     * somebody else and is left where it is.
     *
     * @return list<string>
     */
    private static function documents(string $legacy): array
    {
        $found = [];
        foreach (scandir($legacy) ?: [] as $scope) {
            if ($scope === '.' || $scope === '..' || !is_dir($legacy . '/' . $scope)) {
                continue;
            }
            if (preg_match('#^[a-z0-9\-]{1,64}$#', $scope) !== 1) {
                continue;
            }
            foreach (scandir($legacy . '/' . $scope) ?: [] as $name) {
                if (preg_match('#^[a-f0-9]{32}\.[a-z0-9]{1,5}$#', $name) === 1) {
                    $found[] = $scope . '/' . $name;
                }
            }
        }
        return $found;
    }
}
