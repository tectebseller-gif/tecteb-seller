<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Audit;

use Tecteb\Marketplace\Core\Config\SettingsSchema;

/**
 * Allowlist of event types and, per type, the payload keys that may be
 * persisted (CORE-08). Anything else is dropped before insert.
 */
final class AuditEventCatalog
{
    public const SETTINGS_UPDATED = 'settings.updated';
    public const PLUGIN_ACTIVATED = 'plugin.activated';
    public const PLUGIN_DEACTIVATED = 'plugin.deactivated';
    public const MIGRATION_APPLIED = 'migration.applied';
    public const MIGRATION_FAILED = 'migration.failed';
    // Vendor phase. Payload keys stay coarse on purpose: an audit row records
    // that a decision happened and by whom, never the applicant's documents,
    // address or phone number.
    public const VENDOR_APPLICATION_SUBMITTED = 'vendor.application_submitted';
    public const VENDOR_APPLICATION_REVIEWED = 'vendor.application_reviewed';
    public const VENDOR_DOCUMENT_UPLOADED = 'vendor.document_uploaded';
    public const VENDOR_DOCUMENT_REJECTED = 'vendor.document_rejected';
    public const VENDOR_DOCUMENT_DOWNLOADED = 'vendor.document_downloaded';
    public const VENDOR_REQUIREMENTS_CHANGED = 'vendor.requirements_changed';
    /** Documents moved out of the old in-uploads directory. Counts only. */
    public const VENDOR_DOCUMENTS_RELOCATED = 'vendor.documents_relocated';
    /** Store settings, staff and the manager's change queue. */
    public const VENDOR_STORE_UPDATED = 'vendor.store_updated';
    public const VENDOR_CHANGE_REQUESTED = 'vendor.change_requested';
    public const VENDOR_CHANGE_REVIEWED = 'vendor.change_reviewed';
    public const VENDOR_STAFF_INVITED = 'vendor.staff_invited';
    public const VENDOR_STAFF_ACTIVATED = 'vendor.staff_activated';
    public const VENDOR_STAFF_ROLE_CHANGED = 'vendor.staff_role_changed';
    public const VENDOR_STAFF_STATUS_CHANGED = 'vendor.staff_status_changed';
    /** Products: the vendor's catalogue and the manager's review of it. */
    public const PRODUCT_SAVED = 'product.saved';
    public const PRODUCT_SUBMITTED = 'product.submitted';
    public const PRODUCT_REVIEWED = 'product.reviewed';
    public const PRODUCT_STATUS_CHANGED = 'product.status_changed';
    public const PRODUCT_INVENTORY_CHANGED = 'product.inventory_changed';
    public const PRODUCT_REVISION_REQUESTED = 'product.revision_requested';
    public const PRODUCT_REVISION_REVIEWED = 'product.revision_reviewed';
    public const PRODUCT_TEMPLATE_CHANGED = 'product.template_changed';
    public const PRODUCT_CSV_EXPORTED = 'product.csv_exported';
    public const PRODUCT_CSV_IMPORTED = 'product.csv_imported';
    public const PRODUCT_PUBLISH_PERMISSION_CHANGED = 'product.publish_permission_changed';
    public const PRODUCT_SYNCED = 'product.synced';
    public const PRODUCT_SEO_CHANGED = 'product.seo_changed';
    /** Orders: what the marketplace recorded about a WooCommerce sale. */
    public const ORDER_CAPTURED = 'order.captured';
    public const ORDER_ITEM_STATUS_CHANGED = 'order.item_status_changed';
    public const ORDER_BLOCKED = 'order.blocked';
    public const ORDER_SETTLEMENT_RECORDED = 'order.settlement_recorded';
    /** Shipping and returns: how much of a line moved, and how much came back. */
    public const ORDER_ITEM_SHIPPED = 'order.item_shipped';
    public const ORDER_RETURN_OPENED = 'order.return_opened';
    public const ORDER_RETURN_DECIDED = 'order.return_decided';
    public const ORDER_RETURN_REFUNDED = 'order.return_refunded';
    public const ORDER_RETURN_RESTOCKED = 'order.return_restocked';
    /** The storefront switch: the marketplace's own products, out of sale and back. */
    public const STOREFRONT_STOPPED = 'storefront.stopped';
    public const STOREFRONT_RESUMED = 'storefront.resumed';
    /** Finance: rules and the append-only ledger. */
    public const FINANCE_RATE_CHANGED = 'finance.rate_changed';
    public const FINANCE_ACCRUED = 'finance.accrued';
    public const FINANCE_REVERSED = 'finance.reversed';
    public const WITHDRAWAL_REQUESTED = 'finance.withdrawal_requested';
    public const WITHDRAWAL_REVIEWED = 'finance.withdrawal_reviewed';
    /** Phase-7 items: a shop's own codes, wholesale buyers and the ticket thread. */
    public const COUPON_CREATED = 'marketplace.coupon_created';
    public const COUPON_DISABLED = 'marketplace.coupon_disabled';
    public const COUPON_USED = 'marketplace.coupon_used';
    public const WHOLESALE_APPLIED = 'marketplace.wholesale_applied';
    public const WHOLESALE_DECIDED = 'marketplace.wholesale_decided';
    public const WHOLESALE_TIERS_SET = 'marketplace.wholesale_tiers_set';
    public const TICKET_OPENED = 'marketplace.ticket_opened';
    public const TICKET_REPLIED = 'marketplace.ticket_replied';
    public const TICKET_STATE_CHANGED = 'marketplace.ticket_state_changed';
    public const TICKET_MESSAGE_HIDDEN = 'marketplace.ticket_message_hidden';
    /** Dokan migration: read first, written only on an explicit import. */
    public const DOKAN_DRY_RUN = 'migration.dokan_dry_run';
    public const DOKAN_IMPORTED = 'migration.dokan_imported';
    public const DOKAN_ROLLED_BACK = 'migration.dokan_rolled_back';

    /** @return array<string, list<string>> event type => allowed top-level payload keys */
    public static function allowlist(): array
    {
        return [
            self::SETTINGS_UPDATED => ['changed', 'old', 'new'],
            self::PLUGIN_ACTIVATED => ['plugin_version', 'schema_version', 'migration_status'],
            self::PLUGIN_DEACTIVATED => ['plugin_version', 'withdrawn'],
            self::MIGRATION_APPLIED => ['from', 'to', 'steps'],
            self::MIGRATION_FAILED => ['step', 'message'],
            self::VENDOR_APPLICATION_SUBMITTED => ['application_id', 'from', 'to', 'documents'],
            self::VENDOR_APPLICATION_REVIEWED => ['application_id', 'from', 'to', 'decision', 'has_note'],
            self::VENDOR_DOCUMENT_UPLOADED => ['application_id', 'type', 'bytes', 'mime'],
            self::VENDOR_DOCUMENT_REJECTED => ['application_id', 'type', 'reason', 'bytes'],
            self::VENDOR_DOCUMENT_DOWNLOADED => ['application_id', 'document_id', 'type'],
            self::VENDOR_REQUIREMENTS_CHANGED => ['action', 'type', 'mode'],
            self::VENDOR_DOCUMENTS_RELOCATED => ['moved', 'failed'],
            self::VENDOR_STORE_UPDATED => ['vendor_id', 'tab', 'problems'],
            self::VENDOR_CHANGE_REQUESTED => ['vendor_id', 'field'],
            self::VENDOR_CHANGE_REVIEWED => ['vendor_id', 'field', 'decision', 'has_note'],
            self::VENDOR_STAFF_INVITED => ['vendor_id', 'preset', 'status'],
            self::VENDOR_STAFF_ACTIVATED => ['vendor_id'],
            self::VENDOR_STAFF_ROLE_CHANGED => ['vendor_id', 'preset'],
            self::VENDOR_STAFF_STATUS_CHANGED => ['vendor_id', 'status'],
            self::PRODUCT_SAVED => ['vendor_id', 'product_id', 'status', 'created'],
            self::PRODUCT_SUBMITTED => ['vendor_id', 'product_id', 'to'],
            self::PRODUCT_REVIEWED => ['vendor_id', 'product_id', 'decision', 'has_note'],
            self::PRODUCT_STATUS_CHANGED => ['vendor_id', 'product_id', 'from', 'to'],
            self::PRODUCT_INVENTORY_CHANGED => ['vendor_id', 'product_id', 'stock'],
            self::PRODUCT_REVISION_REQUESTED => ['vendor_id', 'product_id', 'revision_id', 'fields'],
            self::PRODUCT_REVISION_REVIEWED => ['vendor_id', 'product_id', 'revision_id', 'decision', 'has_note'],
            self::PRODUCT_TEMPLATE_CHANGED => ['action', 'category', 'field', 'schema_version'],
            self::PRODUCT_CSV_EXPORTED => ['vendor_id', 'rows'],
            self::PRODUCT_CSV_IMPORTED => ['vendor_id', 'rows', 'created', 'updated', 'skipped'],
            self::PRODUCT_PUBLISH_PERMISSION_CHANGED => ['vendor_id', 'granted'],
            self::PRODUCT_SYNCED => ['vendor_id', 'product_id', 'wc_product_id', 'action'],
            self::PRODUCT_SEO_CHANGED => ['product_id', 'has_slug', 'has_title'],
            self::ORDER_CAPTURED => ['order_id', 'vendors', 'items', 'recorded', 'skipped'],
            self::ORDER_ITEM_STATUS_CHANGED => ['vendor_id', 'order_id', 'item_id', 'from', 'to'],
            self::ORDER_BLOCKED => ['product_id', 'vendor_id', 'reason'],
            self::ORDER_SETTLEMENT_RECORDED => ['vendor_id', 'order_id', 'item_id', 'completed_at'],
            self::ORDER_ITEM_SHIPPED => [
                'vendor_id', 'order_id', 'shipment_id', 'quantity', 'shipped_total',
                'line_quantity', 'carrier', 'to',
            ],
            self::ORDER_RETURN_OPENED => ['vendor_id', 'order_id', 'item_id', 'return_id', 'quantity', 'has_reason'],
            self::ORDER_RETURN_DECIDED => ['vendor_id', 'return_id', 'item_id', 'from', 'to'],
            self::ORDER_RETURN_REFUNDED => [
                'vendor_id', 'return_id', 'item_id', 'quantity', 'refund_minor',
                'tax_minor', 'commission_minor', 'vendor_share_minor', 'account', 'event_key',
            ],
            self::ORDER_RETURN_RESTOCKED => ['vendor_id', 'return_id', 'product_id', 'quantity', 'stock_after'],
            self::STOREFRONT_STOPPED => ['reason', 'withdrawn', 'failed', 'total', 'orders_held', 'orders_stuck'],
            self::STOREFRONT_RESUMED => ['published', 'refused', 'total', 'orders_released'],
            self::FINANCE_RATE_CHANGED => ['scope', 'reference', 'rate_bp', 'cleared'],
            self::FINANCE_ACCRUED => ['vendor_id', 'rate_bp', 'rate_source', 'base_minor'],
            self::FINANCE_REVERSED => ['vendor_id', 'reverses', 'amount_minor'],
            self::WITHDRAWAL_REQUESTED => ['vendor_id', 'withdrawal_id', 'amount_minor', 'lines'],
            self::WITHDRAWAL_REVIEWED => ['vendor_id', 'withdrawal_id', 'from', 'to', 'has_note'],
            self::COUPON_CREATED => ['vendor_id', 'coupon_id', 'kind', 'value'],
            self::COUPON_DISABLED => ['vendor_id', 'coupon_id'],
            self::COUPON_USED => ['coupon_id', 'order_id', 'amount_minor'],
            self::WHOLESALE_APPLIED => ['user_id', 'has_registration'],
            self::WHOLESALE_DECIDED => ['user_id', 'from', 'to'],
            self::WHOLESALE_TIERS_SET => ['vendor_id', 'product_id', 'steps'],
            self::TICKET_OPENED => ['vendor_id', 'ticket_id', 'has_order_ref'],
            self::TICKET_REPLIED => ['vendor_id', 'ticket_id', 'role'],
            self::TICKET_STATE_CHANGED => ['vendor_id', 'ticket_id', 'to', 'locked'],
            self::TICKET_MESSAGE_HIDDEN => ['message_id', 'has_reason'],
            self::DOKAN_DRY_RUN => ['vendors', 'products', 'orders', 'conflicts', 'run_id'],
            self::DOKAN_IMPORTED => ['vendors', 'products', 'run_id', 'mode'],
            self::DOKAN_ROLLED_BACK => ['vendors', 'products', 'run_id'],
        ];
    }

    /** Nested maps under old/new/changed may only carry these keys. */
    public static function nestedKeyAllowlist(string $eventType): array
    {
        if ($eventType === self::SETTINGS_UPDATED) {
            return SettingsSchema::storedKeys();
        }
        return [];
    }

    public static function isKnown(string $eventType): bool
    {
        return isset(self::allowlist()[$eventType]);
    }
}
