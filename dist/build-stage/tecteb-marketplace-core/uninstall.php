<?php
/**
 * Uninstall guard for Tecteb Marketplace Core.
 *
 * This file deletes NOTHING on purpose (CORE-01, Master Spec §13):
 * the tmc_* options, the four tmc_* capabilities on Administrator and the
 * {prefix}tmc_audit_events table are all preserved when the plugin is
 * deleted from the plugins screen. A separate, explicitly confirmed purge
 * is out of scope for phase 1 and would need its own decision (DEC-05 for
 * audit retention).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Intentionally no data removal.
