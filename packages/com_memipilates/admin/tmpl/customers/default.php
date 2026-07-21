<?php
/** @var \Memi\Component\Memipilates\Administrator\View\Customers\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$filterUrl = $escape(Route::_('index.php?option=com_memipilates&view=customers', false));
$token = $escape(Session::getFormToken());
?>
<div class="container-fluid memi-admin-customers">
    <h1><?= $escape($this->label('COM_MEMIPILATES_SUBMENU_CUSTOMERS', 'Customers')); ?></h1>
    <?php if ($this->canCreateClient) : ?>
        <section class="card mb-4" aria-labelledby="memi-create-client">
            <div class="card-header"><h2 class="h5 mb-0" id="memi-create-client"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CREATE_CLIENT', 'Créer un client')); ?></h2></div>
            <div class="card-body">
                <p class="text-muted mb-3"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CREATE_CLIENT_HELP', 'Crée un compte Joomla et son profil Memi. Communiquez le nom d’utilisateur et le mot de passe temporaire à la cliente de manière sécurisée.')); ?></p>
                <form action="<?= $filterUrl; ?>" method="post" class="row g-3">
                    <input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="management.createClient"><input type="hidden" name="<?= $token; ?>" value="1">
                    <div class="col-12 col-md-6"><label class="form-label" for="memi-client-name"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CLIENT_NAME', 'Nom complet')); ?></label><input class="form-control" id="memi-client-name" name="name" maxlength="255" required autocomplete="name"></div>
                    <div class="col-12 col-md-6"><label class="form-label" for="memi-client-email"><?= $escape(Text::_('JGLOBAL_EMAIL')); ?></label><input class="form-control" id="memi-client-email" name="email" maxlength="100" type="email" required autocomplete="email"></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="memi-client-username"><?= $escape(Text::_('JGLOBAL_USERNAME')); ?></label><input class="form-control" id="memi-client-username" name="username" maxlength="150" pattern="[A-Za-z0-9._-]{3,150}" required autocomplete="username"></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="memi-client-password"><?= $escape(Text::_('JGLOBAL_PASSWORD')); ?></label><input class="form-control" id="memi-client-password" name="password" minlength="12" type="password" required autocomplete="new-password"></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="memi-client-password-confirm"><?= $escape(Text::_('JGLOBAL_CONFIRM_PASSWORD')); ?></label><input class="form-control" id="memi-client-password-confirm" name="password_confirm" minlength="12" type="password" required autocomplete="new-password"></div>
                    <div class="col-12 col-md-6"><label class="form-label" for="memi-client-phone"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_PHONE', 'Téléphone')); ?></label><input class="form-control" id="memi-client-phone" name="phone" maxlength="64" type="tel" autocomplete="tel"></div>
                    <div class="col-12 col-md-3"><label class="form-label" for="memi-client-locale"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_PREFERRED_LOCALE', 'Langue préférée')); ?></label><select class="form-select" id="memi-client-locale" name="preferred_locale"><option value="fr-FR">Français</option><option value="en-GB">English</option></select></div>
                    <div class="col-12 d-flex align-items-end"><button class="btn btn-primary" type="submit"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CREATE_CLIENT', 'Créer le client')); ?></button></div>
                </form>
            </div>
        </section>
    <?php endif; ?>
    <form action="<?= $filterUrl; ?>" method="get" class="row g-3 align-items-end mb-3"><input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="view" value="customers"><div class="col-12 col-md-6"><label class="form-label" for="filter-search"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_SEARCH', 'Search')); ?></label><input class="form-control" id="filter-search" name="filter_search" value="<?= $escape($this->filterSearch); ?>" type="search"></div><div class="col-12 col-md-6 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_APPLY', 'Filter')); ?></button><a class="btn btn-outline-secondary" href="<?= $filterUrl; ?>"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_FILTER_RESET', 'Reset')); ?></a></div></form>
    <div class="table-responsive"><table class="table table-striped table-hover align-middle"><thead><tr><th><?= $escape(Text::_('JGRID_HEADING_ID')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CUSTOMER', 'Customer')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_PHONE', 'Phone')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_CREDIT_BALANCE', 'Credit balance')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_POINT_BALANCE', 'Point balance')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ACTIVE_PACKAGES', 'Active packages')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_JOINED_AT', 'Joined')); ?></th><th><?= $escape($this->label('COM_MEMIPILATES_ADMIN_ACCOUNT_STATUS', 'Account status')); ?></th></tr></thead><tbody>
        <?php foreach ($this->items as $item) : ?>
            <tr><td><?= (int) $item['user_id']; ?></td><td><strong><?= $escape($item['name'] ?? ''); ?></strong><br><small><?= $escape($item['email'] ?? ''); ?></small></td><td><?= $escape($item['phone'] ?? ''); ?></td><td><?= (int) ($item['credit_balance'] ?? 0); ?></td><td><?= (int) ($item['point_balance'] ?? 0); ?></td><td><?= (int) ($item['active_packages'] ?? 0); ?></td><td><?= $escape($this->formatDate((string) ($item['joined_at'] ?? ''))); ?></td><td><?= $escape((int) ($item['block'] ?? 0) === 1 ? $this->label('COM_MEMIPILATES_ADMIN_BLOCKED', 'Blocked') : $this->label('COM_MEMIPILATES_ADMIN_ACTIVE', 'Active')); ?></td></tr>
        <?php endforeach; ?>
        <?php if ($this->items === []) : ?><tr><td colspan="8" class="text-muted"><?= $escape($this->label('COM_MEMIPILATES_ADMIN_NO_RESULTS', 'No records found.')); ?></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($this->paginationLinks() !== '') : ?><nav class="mt-3" aria-label="<?= $escape($this->label('COM_MEMIPILATES_ADMIN_PAGINATION', 'Pagination')); ?>"><?= $this->paginationLinks(); ?></nav><?php endif; ?>
</div>
