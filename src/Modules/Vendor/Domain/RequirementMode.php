<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * Where the marketplace manager has got to in defining required documents.
 *
 * The distinction between Undefined and ExplicitlyNone is the whole point:
 * "nobody has decided yet" must never be read as "nothing is needed", because
 * that would silently let applications through a check that was never made
 * (owner's instruction, plan §2.1).
 */
enum RequirementMode: string
{
    /** Default. No document type exists and no one said none are needed. */
    case Undefined = 'undefined';

    /** At least one document type is defined. */
    case Configured = 'configured';

    /** A manager stated, on the record, that this needs no documents. */
    case ExplicitlyNone = 'explicitly_none';
}
