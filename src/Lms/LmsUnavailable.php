<?php
/**
 * Raised when the learning platform cannot answer.
 *
 * Deliberately a distinct exception rather than an empty array. "The platform
 * is down" and "the platform has no modules" are different facts, and a system
 * that cannot tell them apart ends up showing an empty page during an outage
 * as though the catalogue had genuinely been emptied.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Lms;

defined('ABSPATH') || exit;

final class LmsUnavailable extends \RuntimeException
{
}
