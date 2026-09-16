<?php
/**
 * Autosave drafts, revision conflict and bulk operations — exercised through
 * the plugin's own services on the disposable WordPress.
 *
 *   draft-put <user> <product>      keep a draft, then read it back
 *   draft-forget <user> <product>   what a successful save does to it
 *   conflict <user> <vendor> <product>  two editors, one product
 *   bulk <user> <vendor> <action> <ids…>  a batch with a refused row in it
 *   preview <user> <vendor> <action> <ids…>  the forecast, then the action
 *   image-refusals                  every upload refusal this host produces
 *   ids                             a vendor, a staff user and some products
 */

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductDraftStoreInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

if (DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refusing: not the disposable database\n");
    exit(2);
}

$command = (string) ($args[0] ?? 'ids');
$container = Bootstrap::container();
/** @var ProductRepositoryInterface $products */
$products = $container->get(ProductRepositoryInterface::class);
/** @var ProductDraftStoreInterface $drafts */
$drafts = $container->get(ProductDraftStoreInterface::class);
/** @var ManageProducts $manage */
$manage = $container->get(ManageProducts::class);

switch ($command) {
    case 'ids':
        global $wpdb;
        $row = $wpdb->get_row('SELECT vendor_user_id FROM `' . $wpdb->prefix . 'tmc_products` LIMIT 1', ARRAY_A);
        $vendor = (int) ($row['vendor_user_id'] ?? 0);
        echo 'vendor=', $vendor, "\n";
        $ids = [];
        foreach ($products->allForVendor($vendor) as $product) {
            $ids[] = $product->id . ':' . $product->status->value;
        }
        echo 'products=', implode(',', $ids), "\n";
        break;

    case 'draft-put':
        $user = (int) ($args[1] ?? 0);
        $product = (int) ($args[2] ?? 0);
        $ok = $drafts->put($user, $product, ['title' => 'عنوانِ نیمه‌تمام', 'price' => '123000'], 'rev-a');
        $back = $drafts->get($user, $product);
        echo 'stored=', $ok ? '1' : '0', "\n";
        echo 'read_back=', $back === null ? 'null' : ($back['payload']['title'] ?? '-'), "\n";
        echo 'revision=', $back['revision'] ?? '-', "\n";
        // A draft belongs to ONE person: another member of the same shop must
        // not see it, or autosave becomes the silent overwrite the revision
        // check exists to stop, one step earlier.
        echo 'visible_to_another_user=', $drafts->get($user + 1000, $product) === null ? 'no' : 'YES', "\n";
        break;

    case 'draft-forget':
        $user = (int) ($args[1] ?? 0);
        $product = (int) ($args[2] ?? 0);
        $drafts->put($user, $product, ['title' => 'x'], 'rev-a');
        echo 'before=', $drafts->get($user, $product) === null ? 'absent' : 'present', "\n";
        $drafts->forget($user, $product);
        echo 'after=', $drafts->get($user, $product) === null ? 'absent' : 'present', "\n";
        echo 'forget_is_idempotent=', $drafts->forget($user, $product) ? 'yes' : 'no', "\n";
        break;

    case 'conflict':
        $user = (int) ($args[1] ?? 0);
        $vendor = (int) ($args[2] ?? 0);
        $productId = (int) ($args[3] ?? 0);
        wp_set_current_user($user);
        $product = $products->findOwned($productId, $vendor);
        if ($product === null) {
            echo "conflict=no_such_product\n";
            break;
        }
        // The stamp is a COUNTER now, not a timestamp. `updated_at` has
        // second precision, so two saves inside one second carried an
        // identical stamp and the conflict was invisible — the very case a
        // busy shop hits. The counter is bumped inside the same UPDATE that
        // writes the row, so it cannot collide.
        $stamp = $product->rowVersion;
        $timeStamp = $product->updatedAt;
        echo 'revision_on_the_form=', $stamp, "\n";
        echo 'revision_is_a_counter=', ctype_digit($stamp) ? 'yes' : 'no', "\n";

        // Editor A saves. Their stamp matches, so it goes through.
        $a = $manage->save($user, $vendor, $productId, $product->details, $product->specs,
            $product->imageIds, $product->mainImageId, $stamp);
        echo 'editor_a=', $a->ok ? 'saved' : ('refused:' . $a->code), "\n";

        // Editor B had the page open since before that, so their form still
        // carries the OLD stamp. Note this happens in the SAME SECOND as
        // editor A's save: under the old timestamp scheme both stamps read
        // alike and editor B silently won.
        $b = $manage->save($user, $vendor, $productId, $product->details, $product->specs,
            $product->imageIds, $product->mainImageId, $stamp);
        echo 'editor_b=', $b->ok ? 'saved' : ('refused:' . $b->code), "\n";
        echo 'both_stamps_reported=', (isset($b->context['submitted_revision'], $b->context['current_revision']) ? 'yes' : 'no'), "\n";
        echo 'counter_moved_once=',
            ((int) ($products->findOwned($productId, $vendor)?->rowVersion ?? 0) === (int) $stamp + 1) ? 'yes' : 'no', "\n";

        // WHY the stamp stopped being a timestamp, measured rather than
        // asserted: two saves that land inside one second get the SAME
        // `updated_at` and DIFFERENT counters. Retried until two of them do
        // land in one second, because a probe that depends on where the
        // second boundary fell is a coin toss, not evidence.
        $sameSecond = 'not_observed';
        $timeMoved = '-';
        $counterMoved = '-';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $one = $products->findOwned($productId, $vendor);
            $manage->save($user, $vendor, $productId, $one->details, $one->specs,
                $one->imageIds, $one->mainImageId, $one->rowVersion);
            $two = $products->findOwned($productId, $vendor);
            $manage->save($user, $vendor, $productId, $two->details, $two->specs,
                $two->imageIds, $two->mainImageId, $two->rowVersion);
            $three = $products->findOwned($productId, $vendor);
            if ($two->updatedAt === $three->updatedAt) {
                $sameSecond = 'yes';
                $timeMoved = 'no';
                $counterMoved = ((int) $three->rowVersion) > ((int) $two->rowVersion) ? 'yes' : 'no';
                break;
            }
        }
        echo 'two_saves_in_one_second=', $sameSecond, "\n";
        echo 'timestamp_could_tell_them_apart=', $timeMoved, "\n";
        echo 'counter_could_tell_them_apart=', $counterMoved, "\n";

        // An EMPTY stamp still saves: a form from an older build has nothing to
        // compare, and refusing it would break saving across an upgrade.
        $fresh = $products->findOwned($productId, $vendor);
        $c = $manage->save($user, $vendor, $productId, $fresh->details, $fresh->specs,
            $fresh->imageIds, $fresh->mainImageId, '');
        echo 'empty_stamp=', $c->ok ? 'saved' : ('refused:' . $c->code), "\n";

        // …and so does a stamp in the PREVIOUS build's format. A vendor whose
        // browser cached an alpha.13 form carries a timestamp; locking them
        // out of their own product over an upgrade would be a worse bug than
        // the one this replaced.
        $fresh = $products->findOwned($productId, $vendor);
        $d = $manage->save($user, $vendor, $productId, $fresh->details, $fresh->specs,
            $fresh->imageIds, $fresh->mainImageId, $timeStamp);
        echo 'old_format_stamp=', $d->ok ? 'saved' : ('refused:' . $d->code), "\n";
        break;

    case 'bulk':
        $user = (int) ($args[1] ?? 0);
        $vendor = (int) ($args[2] ?? 0);
        $action = (string) ($args[3] ?? 'submit');
        $ids = array_map('intval', array_slice($args, 4));
        wp_set_current_user($user);

        $result = $manage->bulk($user, $vendor, $action, $ids);
        echo 'ok=', $result->ok ? '1' : '0', ' code=', $result->code, "\n";
        echo 'succeeded=', $result->context['ok'] ?? 0, "\n";
        echo 'refused=', $result->context['failed'] ?? 0, "\n";
        foreach ($result->context['rows'] ?? [] as $row) {
            echo 'row product=', $row['product_id'], ' ok=', $row['ok'] ? '1' : '0', ' code=', $row['code'], "\n";
        }
        // Empty selection, unknown verb, and an oversized batch: three refusals
        // that must be three different codes.
        echo 'empty=', $manage->bulk($user, $vendor, $action, [])->code, "\n";
        echo 'unknown_verb=', $manage->bulk($user, $vendor, 'delete_everything', $ids)->code, "\n";
        echo 'too_large=', $manage->bulk($user, $vendor, $action, range(1, ManageProducts::BULK_LIMIT + 1))->code, "\n";
        break;

    case 'preview':
        // The whole point measured in one place: what the preview says, what
        // the action then does, and — the part a returned value cannot show —
        // that nothing moved in between.
        $user = (int) ($args[1] ?? 0);
        $vendor = (int) ($args[2] ?? 0);
        $action = (string) ($args[3] ?? 'archive');
        $ids = array_map('intval', array_slice($args, 4));
        wp_set_current_user($user);

        global $wpdb;
        $auditBefore = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $wpdb->prefix . 'tmc_audit_events`');
        $statusesBefore = [];
        foreach ($ids as $id) {
            $statusesBefore[$id] = $products->find($id)?->status->value ?? '-';
        }

        $forecast = $manage->previewBulk($user, $vendor, $action, $ids);
        echo 'preview_ok=', $forecast->ok ? '1' : '0', ' code=', $forecast->code, "\n";
        echo 'forecast_go=', $forecast->context['ok'] ?? 0, "\n";
        echo 'forecast_stay=', $forecast->context['failed'] ?? 0, "\n";
        $forecastByRow = [];
        foreach ($forecast->context['rows'] ?? [] as $row) {
            $forecastByRow[(int) $row['product_id']] = (string) $row['code'];
            echo 'forecast product=', $row['product_id'], ' ok=', $row['ok'] ? '1' : '0',
                ' code=', $row['code'], ' from=', $row['from'], ' to=', $row['to'],
                ' titled=', $row['title'] !== '' ? '1' : '0', "\n";
        }

        $moved = 0;
        foreach ($ids as $id) {
            if (($products->find($id)?->status->value ?? '-') !== $statusesBefore[$id]) {
                $moved++;
            }
        }
        echo 'rows_moved_by_preview=', $moved, "\n";
        echo 'audit_lines_by_preview=',
            (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $wpdb->prefix . 'tmc_audit_events`') - $auditBefore, "\n";

        // Now run it for real and compare code by code.
        $actual = $manage->bulk($user, $vendor, $action, $ids);
        $same = 1;
        foreach ($actual->context['rows'] ?? [] as $row) {
            $id = (int) $row['product_id'];
            echo 'actual product=', $id, ' ok=', $row['ok'] ? '1' : '0', ' code=', $row['code'], "\n";
            if (($forecastByRow[$id] ?? '') !== (string) $row['code']) {
                $same = 0;
            }
        }
        echo 'forecast_matched_outcome=', $same, "\n";
        echo 'actual_go=', $actual->context['ok'] ?? 0, "\n";

        // The same request refusals, from both sides. A selection one accepts
        // and the other rejects would be two sets of rules.
        foreach (['empty' => [], 'unknown' => null, 'too_large' => null] as $label => $_) {
            $probeIds = $label === 'empty' ? [] : $ids;
            $probeAction = $label === 'unknown' ? 'delete_everything' : $action;
            if ($label === 'too_large') {
                $probeIds = range(1, ManageProducts::BULK_LIMIT + 1);
            }
            echo 'refusal_', $label, '=', $manage->previewBulk($user, $vendor, $probeAction, $probeIds)->code,
                ':', $manage->bulk($user, $vendor, $probeAction, $probeIds)->code, "\n";
        }
        break;

    case 'image-refusals':
        // The policy the vendor is actually judged by, on THIS installation —
        // including the host's own upload ceiling, which is the number the
        // form must quote.
        $policy = $container->get(\Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy::class);
        echo 'our_ceiling=', \Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy::MAX_BYTES, "\n";
        echo 'host_ceiling=', (int) wp_max_upload_size(), "\n";
        echo 'effective=', $policy->maxBytes(), "\n";
        echo 'effective_is_the_smaller=',
            $policy->maxBytes() === min(
                \Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy::MAX_BYTES,
                max(1, (int) wp_max_upload_size())
            ) ? '1' : '0', "\n";

        // Each platform error, named. «Too large» and «interrupted» call for
        // different second attempts and must not collapse into one code.
        $file = static fn (int $error, int $size = 0, string $mime = '', string $tmp = '', string $name = 'x.jpg')
            => new \Tecteb\Marketplace\Contracts\Files\UploadedFile($name, $tmp, $size, $mime, $error);
        foreach ([
            'ini_size' => $file(UPLOAD_ERR_INI_SIZE),
            'partial' => $file(UPLOAD_ERR_PARTIAL),
            'no_tmp_dir' => $file(UPLOAD_ERR_NO_TMP_DIR),
            'absent' => $file(UPLOAD_ERR_NO_FILE, 0, '', '', ''),
            'empty' => $file(UPLOAD_ERR_OK, 0, 'image/jpeg', '/tmp/x'),
            'over_cap' => $file(UPLOAD_ERR_OK, $policy->maxBytes() + 1, 'image/jpeg', '/tmp/x'),
            'php_in_a_jpg' => $file(UPLOAD_ERR_OK, 1000, 'text/x-php', '/tmp/x'),
            'good' => $file(UPLOAD_ERR_OK, 1000, 'image/png', '/tmp/x', 'x.png'),
        ] as $label => $upload) {
            $code = $policy->refuse($upload);
            $said = \Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages::notice(
                $code,
                ['saved' => 1, 'max_mb' => max(1, (int) floor($policy->maxBytes() / 1048576))]
            );
            printf(
                "refusal %s code=%s said=%s ends_with_instruction=%s\n",
                $label,
                $code === '' ? '-' : $code,
                $code === '' ? '-' : ($said === null ? 'NOTHING' : '1'),
                $code === '' ? '-' : ((bool) preg_match('/ید\.$/u', (string) $said) ? '1' : '0')
            );
        }
        break;

    default:
        fwrite(STDERR, "unknown command: {$command}\n");
        exit(2);
}
