<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\View\Offers;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Memi\Component\Memipilates\Administrator\View\AbstractAdminView;

/** Administration of promotion codes and client loyalty rewards. */
class HtmlView extends AbstractAdminView
{
    /** @var list<array<string,mixed>> */
    public array $promotions = [];
    /** @var list<array<string,mixed>> */
    public array $rewards = [];
    /** @var list<array<string,mixed>> */
    public array $packages = [];
    /** @var array<string,mixed>|null */
    public ?array $promotion = null;
    /** @var array<string,mixed>|null */
    public ?array $reward = null;
    /** @var list<int> */
    public array $promotionPackageIds = [];
    public bool $canManagePromotions = false;
    public bool $canManageRewards = false;

    public function display($tpl = null): void
    {
        $this->initialise(['promotions.manage', 'loyalty.adjust']);
        $this->canManagePromotions = $this->can('promotions.manage') || $this->can('core.admin');
        $this->canManageRewards = $this->can('loyalty.adjust') || $this->can('core.admin');
        $this->loadPackages();
        $this->loadPromotions();
        $this->loadRewards();
        $this->loadEditRecords();
        Factory::getApplication()->getDocument()->setTitle('Promotions et fidélité');
        parent::display($tpl);
    }

    public function dateInput(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($this->timezone)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private function loadPackages(): void
    {
        $query = $this->db->getQuery(true)
            ->select(['id', 'title', 'credits'])
            ->from($this->db->quoteName('#__memi_packages'))
            ->where('archived_at IS NULL')
            ->order('title ASC');
        $this->db->setQuery($query);
        $this->packages = $this->db->loadAssocList() ?: [];
    }

    private function loadPromotions(): void
    {
        $query = $this->db->getQuery(true)
            ->select([
                'p.*',
                '(SELECT COUNT(*) FROM #__memi_promotion_redemptions AS pr WHERE pr.promotion_id = p.id) AS redemption_count',
                '(SELECT GROUP_CONCAT(pk.title ORDER BY pk.title SEPARATOR \' | \') FROM #__memi_promotion_packages AS pp INNER JOIN #__memi_packages AS pk ON pk.id = pp.package_id WHERE pp.promotion_id = p.id) AS package_titles',
            ])
            ->from($this->db->quoteName('#__memi_promotions', 'p'))
            ->where('p.archived_at IS NULL')
            ->order('p.created_at DESC, p.id DESC');
        $this->db->setQuery($query);
        $this->promotions = $this->db->loadAssocList() ?: [];
    }

    private function loadRewards(): void
    {
        $query = $this->db->getQuery(true)
            ->select(['r.*', 'p.title AS package_title', '(SELECT COUNT(*) FROM #__memi_reward_redemptions AS rr WHERE rr.reward_id = r.id) AS redemption_count'])
            ->from($this->db->quoteName('#__memi_rewards', 'r'))
            ->leftJoin($this->db->quoteName('#__memi_packages', 'p') . ' ON p.id = r.package_id')
            ->where('r.archived_at IS NULL')
            ->order('r.ordering ASC, r.title ASC');
        $this->db->setQuery($query);
        $this->rewards = $this->db->loadAssocList() ?: [];
    }

    private function loadEditRecords(): void
    {
        $input = Factory::getApplication()->input;
        $promotionId = $input->getInt('edit_promotion');
        if ($promotionId > 0) {
            $this->promotion = $this->record('#__memi_promotions', $promotionId);
            if ($this->promotion !== null) {
                $id = $promotionId;
                $query = $this->db->getQuery(true)
                    ->select($this->db->quoteName('package_id'))
                    ->from($this->db->quoteName('#__memi_promotion_packages'))
                    ->where($this->db->quoteName('promotion_id') . ' = :id')
                    ->bind(':id', $id);
                $this->db->setQuery($query);
                $this->promotionPackageIds = array_map('intval', $this->db->loadColumn() ?: []);
            }
        }
        $rewardId = $input->getInt('edit_reward');
        if ($rewardId > 0) {
            $this->reward = $this->record('#__memi_rewards', $rewardId);
        }
    }

    /** @return array<string,mixed>|null */
    private function record(string $table, int $id): ?array
    {
        $identifier = $id;
        $query = $this->db->getQuery(true)
            ->select('*')->from($this->db->quoteName($table))
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName('archived_at') . ' IS NULL')
            ->bind(':id', $identifier);
        $this->db->setQuery($query);

        return $this->db->loadAssoc() ?: null;
    }
}
