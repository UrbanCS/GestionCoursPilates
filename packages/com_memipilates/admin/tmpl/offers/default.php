<?php
/** @var \Memi\Component\Memipilates\Administrator\View\Offers\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$url = $escape(Route::_('index.php?option=com_memipilates&view=offers', false));
$token = $escape(Session::getFormToken());
$promotion = $this->promotion ?? [];
$reward = $this->reward ?? [];
$p = static fn (string $key, mixed $default = ''): mixed => $promotion[$key] ?? $default;
$r = static fn (string $key, mixed $default = ''): mixed => $reward[$key] ?? $default;
$money = static fn (mixed $cents): string => number_format(max(0, (int) $cents) / 100, 2, '.', '');
$promotionType = (string) $p('discount_type', 'fixed');
$promotionValue = $promotionType === 'percentage' ? number_format((int) $p('discount_basis_points', 0) / 100, 2, '.', '') : $money($p('discount_cents', 0));
$rewardType = (string) $r('reward_type', 'discount');
$rewardValue = $money($r('discount_cents', 0));
?>
<div class="container-fluid memi-admin-offers">
    <h1>Promotions et fidélité</h1>
    <p class="text-muted">Gérez les codes promotionnels pour les forfaits et les récompenses visibles dans l’espace client.</p>

    <div class="row g-4">
        <div class="col-12 col-xl-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h4"><?= $promotion !== [] ? 'Modifier le code promotionnel' : 'Nouveau code promotionnel'; ?></h2>
                <?php if ($this->canManagePromotions) : ?><form action="<?= $url; ?>" method="post" class="row g-3">
                    <input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="offers.savePromotion"><input type="hidden" name="id" value="<?= (int) $p('id'); ?>"><input type="hidden" name="<?= $token; ?>" value="1">
                    <div class="col-md-5"><label class="form-label">Code *</label><input required maxlength="64" pattern="[A-Za-z0-9_-]{3,64}" class="form-control text-uppercase" name="code" value="<?= $escape($p('code')); ?>" placeholder="BIENVENUE10"></div>
                    <div class="col-md-7"><label class="form-label">Titre *</label><input required class="form-control" name="title" value="<?= $escape($p('title')); ?>"></div>
                    <div class="col-md-5"><label class="form-label">Type de réduction</label><select class="form-select" name="discount_type"><option value="fixed"<?= $promotionType === 'fixed' ? ' selected' : ''; ?>>Montant fixe (CAD)</option><option value="percentage"<?= $promotionType === 'percentage' ? ' selected' : ''; ?>>Pourcentage (%)</option></select></div>
                    <div class="col-md-3"><label class="form-label">Valeur</label><input min="0" step="0.01" type="number" class="form-control" name="discount_value" value="<?= $escape($promotionValue); ?>"></div>
                    <div class="col-md-4"><label class="form-label">Minimum (CAD)</label><input min="0" step="0.01" type="number" class="form-control" name="minimum_order_value" value="<?= $escape($money($p('minimum_amount_cents', 0))); ?>"></div>
                    <div class="col-md-4"><label class="form-label">Crédits bonis</label><input min="0" type="number" class="form-control" name="bonus_credits" value="<?= (int) $p('bonus_credits', 0); ?>"></div>
                    <div class="col-md-4"><label class="form-label">Points bonis</label><input min="0" type="number" class="form-control" name="bonus_points" value="<?= (int) $p('bonus_points', 0); ?>"></div>
                    <div class="col-md-4"><label class="form-label">Utilisations totales</label><input min="1" type="number" class="form-control" name="maximum_redemptions" value="<?= $escape($p('maximum_redemptions')); ?>" placeholder="Illimité"></div>
                    <div class="col-md-4"><label class="form-label">Maximum par client</label><input min="1" type="number" class="form-control" name="per_customer_limit" value="<?= $escape($p('per_customer_limit')); ?>" placeholder="Illimité"></div>
                    <div class="col-md-6"><label class="form-label">Débute le</label><input type="datetime-local" class="form-control" name="starts_at" value="<?= $escape($this->dateInput($p('starts_at'))); ?>"></div>
                    <div class="col-md-6"><label class="form-label">Se termine le</label><input type="datetime-local" class="form-control" name="ends_at" value="<?= $escape($this->dateInput($p('ends_at'))); ?>"></div>
                    <div class="col-12"><label class="form-label">Forfaits admissibles</label><select multiple class="form-select" name="package_ids[]" size="<?= min(6, max(2, count($this->packages))); ?>"><?php foreach ($this->packages as $package) : ?><option value="<?= (int) $package['id']; ?>"<?= in_array((int) $package['id'], $this->promotionPackageIds, true) ? ' selected' : ''; ?>><?= $escape($package['title']); ?></option><?php endforeach; ?></select><div class="form-text">Aucune sélection = tous les forfaits.</div></div>
                    <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" rows="2" name="description"><?= $escape($p('description')); ?></textarea></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" id="promotion-published" type="checkbox" name="published" value="1"<?= (int) $p('published', 1) === 1 ? ' checked' : ''; ?>><label class="form-check-label" for="promotion-published">Code actif</label></div></div>
                    <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $promotion !== [] ? 'Enregistrer' : 'Créer le code'; ?></button><?php if ($promotion !== []) : ?><a class="btn btn-outline-secondary" href="<?= $url; ?>">Annuler</a><?php endif; ?></div>
                </form><?php else : ?><p class="text-muted mb-0">Vous avez accès à la consultation seulement.</p><?php endif; ?>
            </div></div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h4"><?= $reward !== [] ? 'Modifier la récompense' : 'Nouvelle récompense'; ?></h2>
                <?php if ($this->canManageRewards) : ?><form action="<?= $url; ?>" method="post" class="row g-3">
                    <input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="offers.saveReward"><input type="hidden" name="id" value="<?= (int) $r('id'); ?>"><input type="hidden" name="<?= $token; ?>" value="1">
                    <div class="col-md-8"><label class="form-label">Titre *</label><input required class="form-control" name="title" value="<?= $escape($r('title')); ?>"></div>
                    <div class="col-md-4"><label class="form-label">Coût (points) *</label><input required min="1" type="number" class="form-control" name="points_cost" value="<?= (int) $r('points_cost', 100); ?>"></div>
                    <div class="col-md-4"><label class="form-label">Type</label><select class="form-select" name="reward_type"><option value="discount"<?= $rewardType === 'discount' ? ' selected' : ''; ?>>Réduction</option><option value="credits"<?= $rewardType === 'credits' ? ' selected' : ''; ?>>Crédits</option><option value="package"<?= $rewardType === 'package' ? ' selected' : ''; ?>>Forfait</option><option value="custom"<?= $rewardType === 'custom' ? ' selected' : ''; ?>>À traiter par le personnel</option></select></div>
                    <div class="col-md-4"><label class="form-label">Réduction (CAD)</label><input min="0" step="0.01" type="number" class="form-control" name="discount_value" value="<?= $escape($rewardValue); ?>"></div>
                    <div class="col-md-4"><label class="form-label">Crédits</label><input min="0" type="number" class="form-control" name="credits" value="<?= (int) $r('credits', 0); ?>"></div>
                    <div class="col-12"><label class="form-label">Forfait de référence (obligatoire pour « Crédits » et « Forfait »)</label><select class="form-select" name="package_id"><option value="">—</option><?php foreach ($this->packages as $package) : ?><option value="<?= (int) $package['id']; ?>"<?= (int) $r('package_id') === (int) $package['id'] ? ' selected' : ''; ?>><?= $escape($package['title']); ?> (<?= (int) $package['credits']; ?> crédits)</option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Disponible à partir du</label><input type="datetime-local" class="form-control" name="starts_at" value="<?= $escape($this->dateInput($r('available_from'))); ?>"></div>
                    <div class="col-md-6"><label class="form-label">Disponible jusqu’au</label><input type="datetime-local" class="form-control" name="ends_at" value="<?= $escape($this->dateInput($r('available_until'))); ?>"></div>
                    <div class="col-md-6"><label class="form-label">Utilisations totales</label><input min="1" type="number" class="form-control" name="maximum_redemptions" value="<?= $escape($r('maximum_redemptions')); ?>" placeholder="Illimité"></div>
                    <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" rows="2" name="description"><?= $escape($r('description')); ?></textarea></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" id="reward-published" type="checkbox" name="published" value="1"<?= (int) $r('published', 1) === 1 ? ' checked' : ''; ?>><label class="form-check-label" for="reward-published">Récompense active</label></div></div>
                    <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $reward !== [] ? 'Enregistrer' : 'Créer la récompense'; ?></button><?php if ($reward !== []) : ?><a class="btn btn-outline-secondary" href="<?= $url; ?>">Annuler</a><?php endif; ?></div>
                </form><?php else : ?><p class="text-muted mb-0">Vous avez accès à la consultation seulement.</p><?php endif; ?>
            </div></div>
        </div>
    </div>

    <div class="card mt-4"><div class="card-body"><h2 class="h4">Codes promotionnels actifs</h2><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Code</th><th>Offre</th><th>Forfaits</th><th>Utilisations</th><th>État</th><th></th></tr></thead><tbody>
        <?php foreach ($this->promotions as $item) : ?><tr><td><strong><?= $escape($item['code']); ?></strong></td><td><?= $escape($item['title']); ?><br><small class="text-muted"><?php if ((int) $item['discount_cents'] > 0) : ?><?= $escape($this->formatMoney((int) $item['discount_cents'])); ?><?php elseif ((int) $item['discount_basis_points'] > 0) : ?><?= number_format((int) $item['discount_basis_points'] / 100, 2); ?> %<?php endif; ?><?php if ((int) $item['bonus_credits'] > 0) : ?> · +<?= (int) $item['bonus_credits']; ?> crédits<?php endif; ?></small></td><td><?= $escape($item['package_titles'] ?: 'Tous'); ?></td><td><?= (int) $item['redemption_count']; ?><?= $item['maximum_redemptions'] !== null ? ' / ' . (int) $item['maximum_redemptions'] : ''; ?></td><td><?= (int) $item['published'] === 1 ? 'Actif' : 'Inactif'; ?></td><td class="d-flex gap-2"><?php if ($this->canManagePromotions) : ?><a class="btn btn-sm btn-outline-primary" href="<?= $escape(Route::_('index.php?option=com_memipilates&view=offers&edit_promotion=' . (int) $item['id'], false)); ?>">Modifier</a><form action="<?= $url; ?>" method="post" onsubmit="return confirm('Retirer ce code ?');"><input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="offers.archivePromotion"><input type="hidden" name="id" value="<?= (int) $item['id']; ?>"><input type="hidden" name="<?= $token; ?>" value="1"><button class="btn btn-sm btn-outline-danger" type="submit">Retirer</button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if ($this->promotions === []) : ?><tr><td colspan="6" class="text-muted">Aucun code promotionnel.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>

    <div class="card mt-4"><div class="card-body"><h2 class="h4">Récompenses fidélité actives</h2><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Récompense</th><th>Coût</th><th>Type</th><th>Utilisations</th><th>État</th><th></th></tr></thead><tbody>
        <?php foreach ($this->rewards as $item) : ?><tr><td><strong><?= $escape($item['title']); ?></strong><br><small class="text-muted"><?= $escape($item['description']); ?></small></td><td><?= (int) $item['points_cost']; ?> points</td><td><?= $escape($item['reward_type']); ?><?php if (($item['package_title'] ?? '') !== '') : ?> · <?= $escape($item['package_title']); ?><?php endif; ?></td><td><?= (int) $item['redemption_count']; ?><?= $item['maximum_redemptions'] !== null ? ' / ' . (int) $item['maximum_redemptions'] : ''; ?></td><td><?= (int) $item['published'] === 1 ? 'Actif' : 'Inactif'; ?></td><td class="d-flex gap-2"><?php if ($this->canManageRewards) : ?><a class="btn btn-sm btn-outline-primary" href="<?= $escape(Route::_('index.php?option=com_memipilates&view=offers&edit_reward=' . (int) $item['id'], false)); ?>">Modifier</a><form action="<?= $url; ?>" method="post" onsubmit="return confirm('Retirer cette récompense ?');"><input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="offers.archiveReward"><input type="hidden" name="id" value="<?= (int) $item['id']; ?>"><input type="hidden" name="<?= $token; ?>" value="1"><button class="btn btn-sm btn-outline-danger" type="submit">Retirer</button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if ($this->rewards === []) : ?><tr><td colspan="6" class="text-muted">Aucune récompense.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
</div>
