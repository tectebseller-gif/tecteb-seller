<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * Why an upload was refused. A reason code rather than a message, so the UI
 * can say it in Persian and the audit log can count it without parsing text.
 */
enum UploadRejection: string
{
    case NoFile = 'no_file';
    case TransferFailed = 'transfer_failed';
    case UnknownType = 'unknown_type';
    case MimeNotAllowed = 'mime_not_allowed';
    case TooLarge = 'too_large';
    case Empty = 'empty_file';
    case StorageFailed = 'storage_failed';
    case NotEditable = 'not_editable';
}
