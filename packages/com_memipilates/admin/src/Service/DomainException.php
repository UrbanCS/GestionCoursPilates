<?php
/**
 * @package     Memi.Component.Memipilates
 * @copyright   (C) Memi Studio
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

/**
 * A safe, translatable domain error for public and back-office workflows.
 *
 * The public controller deliberately exposes only the language key and its
 * substitution data, never a database or payment-provider exception.
 */
final class DomainException extends \RuntimeException
{
    /** @var array<string, scalar|null> */
    private array $context;

    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(string $messageKey, array $context = [], int $code = 400, ?\Throwable $previous = null)
    {
        parent::__construct($messageKey, $code, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
