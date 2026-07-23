<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Rebuilds an opaque waitlist acceptance token from non-secret offer metadata.
 * The Joomla application secret is the only key and never enters the queue.
 */
final class WaitlistOfferTokenService
{
    private const CONTEXT = 'memipilates.waitlist.offer.v1';

    public function issue(int $waitlistId, int $userId, string $offeredAt, string $expiresAt): string
    {
        if ($waitlistId <= 0 || $userId <= 0 || $offeredAt === '' || $expiresAt === '') {
            throw new \InvalidArgumentException('Incomplete waitlist offer metadata.');
        }

        $secret = trim((string) Factory::getApplication()->get('secret', ''));
        if ($secret === '') {
            throw new \RuntimeException('The Joomla application secret is unavailable.');
        }

        $message = implode('|', [self::CONTEXT, $waitlistId, $userId, $offeredAt, $expiresAt]);
        $binary = hash_hmac('sha256', $message, $secret, true);

        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
