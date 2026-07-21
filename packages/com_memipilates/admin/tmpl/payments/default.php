<?php
/** @var \Memi\Component\Memipilates\Administrator\View\Payments\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$url = $escape(Route::_('index.php?option=com_memipilates&view=payments', false));
?>
<div class="container-fluid memi-admin-payments">
    <h1>Paiements</h1>
    <p class="text-muted">Suivi des commandes en ligne et de leur état Square. Les données de carte complètes ne sont jamais enregistrées.</p>
    <form action="<?= $url; ?>" method="get" class="row g-3 align-items-end mb-3"><input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="view" value="payments">
        <div class="col-12 col-md-5"><label class="form-label" for="payment-search">Client, courriel ou no de commande</label><input class="form-control" id="payment-search" name="filter_search" type="search" value="<?= $escape($this->filterSearch); ?>"></div>
        <div class="col-12 col-md-3"><label class="form-label" for="payment-status">État</label><select class="form-select" id="payment-status" name="filter_status"><option value=""><?= $escape(Text::_('JALL')); ?></option><?php foreach ($this->statuses as $status) : ?><option value="<?= $escape($status); ?>"<?= $this->filterStatus === $status ? ' selected' : ''; ?>><?= $escape($this->statusLabel($status)); ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-4 d-flex gap-2"><button class="btn btn-primary" type="submit">Filtrer</button><a class="btn btn-outline-secondary" href="<?= $url; ?>">Réinitialiser</a></div>
    </form>
    <div class="table-responsive"><table class="table table-striped table-hover align-middle"><thead><tr><th>No</th><th>Client</th><th>Créée</th><th>Montant</th><th>Promotion</th><th>Commande</th><th>Square</th><th>Reçu</th></tr></thead><tbody>
        <?php foreach ($this->items as $item) : ?><tr><td>#<?= (int) $item['id']; ?></td><td><strong><?= $escape($item['customer_name']); ?></strong><br><small><?= $escape($item['customer_email']); ?></small></td><td><?= $escape($this->formatDate((string) $item['created_at'])); ?><?php if (($item['paid_at'] ?? '') !== '') : ?><br><small class="text-success">Payée : <?= $escape($this->formatDate((string) $item['paid_at'])); ?></small><?php endif; ?></td><td><strong><?= $escape($this->formatMoney((int) $item['total_cents'], (string) $item['currency'])); ?></strong><br><small><?= $escape($this->formatMoney((int) $item['subtotal_cents'], (string) $item['currency'])); ?><?php if ((int) $item['discount_cents'] > 0) : ?> − <?= $escape($this->formatMoney((int) $item['discount_cents'], (string) $item['currency'])); ?><?php endif; ?></small></td><td><?= $escape($item['promotion_code'] ?: '—'); ?></td><td><?= $escape($this->statusLabel((string) $item['status'])); ?></td><td><?= $escape($item['payment_status'] ?: '—'); ?><?php if (($item['card_brand'] ?? '') !== '') : ?><br><small><?= $escape($item['card_brand']); ?> •••• <?= $escape($item['card_last4']); ?></small><?php endif; ?></td><td><?php if (($item['receipt_url'] ?? '') !== '') : ?><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer" href="<?= $escape($item['receipt_url']); ?>">Voir le reçu</a><?php else : ?>—<?php endif; ?></td></tr><?php endforeach; ?>
        <?php if ($this->items === []) : ?><tr><td colspan="8" class="text-muted">Aucune commande ne correspond aux filtres.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($this->paginationLinks() !== '') : ?><nav class="mt-3"><?= $this->paginationLinks(); ?></nav><?php endif; ?>
</div>
