<?php
/** @var \Memi\Component\Memipilates\Administrator\View\Catalog\HtmlView $this */
defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$record = $this->record ?? [];
$value = static fn (string $key, mixed $default = ''): mixed => $record[$key] ?? $default;
$money = static fn (mixed $cents): string => number_format(max(0, (int) $cents) / 100, 2, '.', '');
$base = 'index.php?option=com_memipilates&view=catalog';
$formUrl = $escape(Route::_($base . '&entity=' . rawurlencode($this->entity), false));
$token = $escape(Session::getFormToken());
$editId = (int) ($record['id'] ?? 0);
$selectOptions = static function (array $items, mixed $selected, bool $empty = false) use ($escape): string {
    $html = $empty ? '<option value="">—</option>' : '';
    foreach ($items as $item) {
        $id = (int) ($item['id'] ?? 0);
        $title = $escape($item['title'] ?? '');
        $html .= '<option value="' . $id . '"' . ((int) $selected === $id ? ' selected' : '') . '>' . $title . '</option>';
    }

    return $html;
};
?>
<div class="container-fluid memi-admin-catalog">
    <h1>Catalogue du studio</h1>
    <p class="text-muted">Créez, modifiez ou retirez de la vente les éléments du studio. Les éléments déjà liés à des clients ne peuvent pas être supprimés.</p>

    <div class="nav nav-pills flex-wrap gap-2 mb-4" role="tablist">
        <?php foreach ($this->entities as $key => $title) : ?>
            <a class="nav-link<?= $this->entity === $key ? ' active' : ''; ?>" href="<?= $escape(Route::_($base . '&entity=' . rawurlencode($key), false)); ?>"><?= $escape($title); ?></a>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4"><div class="card-body">
        <h2 class="h4 mb-3"><?= $editId > 0 ? 'Modifier' : 'Créer'; ?> : <?= $escape($this->entities[$this->entity]); ?></h2>
        <?php if ($this->entity === 'session') : ?><div class="alert alert-info">Une séance ponctuelle est publiée immédiatement. Pour l’annuler ensuite, utilisez l’écran <strong>Séances</strong>.</div><?php endif; ?>
        <form action="<?= $formUrl; ?>" method="post" class="row g-3">
            <input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="catalog.save"><input type="hidden" name="entity" value="<?= $escape($this->entity); ?>"><input type="hidden" name="id" value="<?= $editId; ?>"><input type="hidden" name="<?= $token; ?>" value="1">

            <?php if ($this->entity === 'location') : ?>
                <div class="col-md-6"><label class="form-label">Nom *</label><input required class="form-control" name="title" value="<?= $escape($value('title')); ?>"></div>
                <div class="col-md-6"><label class="form-label">Téléphone</label><input class="form-control" name="phone" value="<?= $escape($value('phone')); ?>"></div>
                <div class="col-md-6"><label class="form-label">Adresse</label><input class="form-control" name="address_line1" value="<?= $escape($value('address_line1')); ?>"></div>
                <div class="col-md-6"><label class="form-label">Complément d’adresse</label><input class="form-control" name="address_line2" value="<?= $escape($value('address_line2')); ?>"></div>
                <div class="col-md-4"><label class="form-label">Ville</label><input class="form-control" name="city" value="<?= $escape($value('city')); ?>"></div>
                <div class="col-md-4"><label class="form-label">Province</label><input class="form-control" name="province" value="<?= $escape($value('province', 'Québec')); ?>"></div>
                <div class="col-md-4"><label class="form-label">Code postal</label><input class="form-control" name="postal_code" value="<?= $escape($value('postal_code')); ?>"></div>
            <?php elseif ($this->entity === 'room') : ?>
                <div class="col-md-5"><label class="form-label">Emplacement *</label><select required class="form-select" name="location_id"><?= $selectOptions($this->locations, $value('location_id')); ?></select></div>
                <div class="col-md-5"><label class="form-label">Nom *</label><input required class="form-control" name="title" value="<?= $escape($value('title')); ?>"></div>
                <div class="col-md-2"><label class="form-label">Capacité *</label><input required min="1" max="500" type="number" class="form-control" name="capacity" value="<?= (int) $value('capacity', 8); ?>"></div>
                <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" rows="3" name="description"><?= $escape($value('description')); ?></textarea></div>
            <?php elseif ($this->entity === 'instructor') : ?>
                <div class="col-md-6"><label class="form-label">Nom affiché *</label><input required class="form-control" name="display_name" value="<?= $escape($value('display_name')); ?>"></div>
                <div class="col-md-6"><label class="form-label">Courriel</label><input type="email" class="form-control" name="email" value="<?= $escape($value('email')); ?>"></div>
                <div class="col-md-6"><label class="form-label">Téléphone</label><input class="form-control" name="phone" value="<?= $escape($value('phone')); ?>"></div>
                <div class="col-12"><label class="form-label">Biographie</label><textarea class="form-control" rows="4" name="bio"><?= $escape($value('bio')); ?></textarea></div>
            <?php elseif ($this->entity === 'course_type') : ?>
                <div class="col-md-6"><label class="form-label">Titre *</label><input required class="form-control" name="title" value="<?= $escape($value('title')); ?>"></div>
                <div class="col-md-3"><label class="form-label">Niveau</label><input class="form-control" name="level" value="<?= $escape($value('level')); ?>"></div>
                <div class="col-md-3"><label class="form-label">Intensité (1–10)</label><input min="1" max="10" type="number" class="form-control" name="intensity" value="<?= $escape($value('intensity')); ?>"></div>
                <div class="col-md-3"><label class="form-label">Durée (min)</label><input required min="5" type="number" class="form-control" name="duration_minutes" value="<?= (int) $value('default_duration_minutes', 60); ?>"></div>
                <div class="col-md-3"><label class="form-label">Capacité</label><input required min="1" type="number" class="form-control" name="capacity" value="<?= (int) $value('default_capacity', 8); ?>"></div>
                <div class="col-md-3"><label class="form-label">Prix (CAD)</label><input min="0" step="0.01" type="number" class="form-control" name="price" value="<?= $escape($money($value('default_price_cents'))); ?>"></div>
                <div class="col-md-3"><label class="form-label">Crédits requis</label><input min="0" type="number" class="form-control" name="credits_required" value="<?= (int) $value('default_credits_required', 1); ?>"></div>
                <div class="col-md-3"><label class="form-label">Taxe (centièmes %)</label><input min="0" max="10000" type="number" class="form-control" name="tax_rate_basis_points" value="<?= (int) $value('tax_rate_basis_points', 0); ?>"></div>
                <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" rows="3" name="description"><?= $escape($value('description')); ?></textarea></div>
            <?php elseif ($this->entity === 'course') : ?>
                <div class="col-md-4"><label class="form-label">Type de cours *</label><select required class="form-select" name="course_type_id"><?= $selectOptions($this->courseTypes, $value('course_type_id')); ?></select></div>
                <div class="col-md-4"><label class="form-label">Instructeur</label><select class="form-select" name="instructor_id"><?= $selectOptions($this->instructors, $value('instructor_id'), true); ?></select></div>
                <div class="col-md-4"><label class="form-label">Salle</label><select class="form-select" name="room_id"><?= $selectOptions($this->rooms, $value('room_id'), true); ?></select></div>
                <div class="col-12"><label class="form-label">Titre *</label><input required class="form-control" name="title" value="<?= $escape($value('title')); ?>"></div>
                <div class="col-md-3"><label class="form-label">Durée (min)</label><input required min="5" type="number" class="form-control" name="duration_minutes" value="<?= (int) $value('duration_minutes', 60); ?>"></div>
                <div class="col-md-3"><label class="form-label">Capacité</label><input required min="1" type="number" class="form-control" name="capacity" value="<?= (int) $value('capacity', 8); ?>"></div>
                <div class="col-md-2"><label class="form-label">Prix (CAD)</label><input min="0" step="0.01" type="number" class="form-control" name="price" value="<?= $escape($money($value('price_cents'))); ?>"></div>
                <div class="col-md-2"><label class="form-label">Crédits</label><input min="0" type="number" class="form-control" name="credits_required" value="<?= (int) $value('credits_required', 1); ?>"></div>
                <div class="col-md-2"><label class="form-label">Taxe (centièmes %)</label><input min="0" max="10000" type="number" class="form-control" name="tax_rate_basis_points" value="<?= (int) $value('tax_rate_basis_points', 0); ?>"></div>
                <div class="col-md-6"><label class="form-label">Ouverture (jours avant)</label><input min="0" max="365" type="number" class="form-control" name="booking_opens_days" value="<?= (int) floor((int) $value('booking_opens_offset_minutes', 10080) / 1440); ?>"></div>
                <div class="col-md-6"><label class="form-label">Fermeture (minutes avant)</label><input min="0" max="10080" type="number" class="form-control" name="booking_closes_minutes" value="<?= (int) $value('booking_closes_offset_minutes', 0); ?>"></div>
                <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" rows="3" name="description"><?= $escape($value('description')); ?></textarea></div>
            <?php elseif ($this->entity === 'session_rule') : ?>
                <div class="col-md-4"><label class="form-label">Cours *</label><select required class="form-select" name="course_id"><?= $selectOptions($this->courses, $value('course_id')); ?></select></div>
                <div class="col-md-4"><label class="form-label">Instructeur</label><select class="form-select" name="instructor_id"><?= $selectOptions($this->instructors, $value('instructor_id'), true); ?></select></div>
                <div class="col-md-4"><label class="form-label">Salle</label><select class="form-select" name="room_id"><?= $selectOptions($this->rooms, $value('room_id'), true); ?></select></div>
                <div class="col-md-3"><label class="form-label">Débute le *</label><input required type="date" class="form-control" name="starts_on" value="<?= $escape($value('starts_on')); ?>"></div>
                <div class="col-md-3"><label class="form-label">Se termine le</label><input type="date" class="form-control" name="ends_on" value="<?= $escape($value('ends_on')); ?>"></div>
                <div class="col-md-2"><label class="form-label">Jour *</label><select class="form-select" name="weekday"><?php foreach ([1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'] as $day => $label) : ?><option value="<?= $day; ?>"<?= (int) $value('weekday', 1) === $day ? ' selected' : ''; ?>><?= $label; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label">Heure *</label><input required type="time" class="form-control" name="start_time" value="<?= $escape(substr((string) $value('start_time', '09:00'), 0, 5)); ?>"></div>
                <div class="col-md-2"><label class="form-label">Durée (min)</label><input required min="5" type="number" class="form-control" name="duration_minutes" value="<?= (int) $value('duration_minutes', 60); ?>"></div>
                <div class="col-md-3"><label class="form-label">Capacité</label><input required min="1" type="number" class="form-control" name="capacity" value="<?= (int) $value('capacity_override', 8); ?>"></div>
            <?php elseif ($this->entity === 'session') : ?>
                <div class="col-md-4"><label class="form-label">Cours *</label><select required class="form-select" name="course_id"><?= $selectOptions($this->courses, ''); ?></select></div>
                <div class="col-md-4"><label class="form-label">Instructeur</label><select class="form-select" name="instructor_id"><?= $selectOptions($this->instructors, '', true); ?></select></div>
                <div class="col-md-4"><label class="form-label">Salle</label><select class="form-select" name="room_id"><?= $selectOptions($this->rooms, '', true); ?></select></div>
                <div class="col-md-4"><label class="form-label">Début *</label><input required type="datetime-local" class="form-control" name="starts_at"></div>
                <div class="col-md-4"><label class="form-label">Durée (min)</label><input required min="5" type="number" class="form-control" name="duration_minutes" value="60"></div>
                <div class="col-md-4"><label class="form-label">Capacité</label><input required min="1" type="number" class="form-control" name="capacity" value="8"></div>
            <?php elseif ($this->entity === 'package') : ?>
                <div class="col-md-6"><label class="form-label">Titre *</label><input required class="form-control" name="title" value="<?= $escape($value('title')); ?>"></div>
                <div class="col-md-3"><label class="form-label">Prix (CAD) *</label><input required min="0" step="0.01" type="number" class="form-control" name="price" value="<?= $escape($money($value('price_cents'))); ?>"></div>
                <div class="col-md-3"><label class="form-label">Crédits *</label><input required min="1" type="number" class="form-control" name="credits" value="<?= (int) $value('credits', 1); ?>"></div>
                <div class="col-md-4"><label class="form-label">Validité (jours, facultatif)</label><input min="1" type="number" class="form-control" name="validity_days" value="<?= $escape($value('validity_days')); ?>"></div>
                <div class="col-md-4"><label class="form-label">Points bonis</label><input min="0" type="number" class="form-control" name="bonus_points" value="<?= (int) $value('bonus_points', 0); ?>"></div>
                <div class="col-md-4"><label class="form-label">Taxe (centièmes %)</label><input min="0" max="10000" type="number" class="form-control" name="tax_rate_basis_points" value="<?= (int) $value('tax_rate_basis_points', 0); ?>"></div>
                <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" rows="3" name="description"><?= $escape($value('description')); ?></textarea></div>
            <?php endif; ?>

            <?php if ($this->entity !== 'session') : ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="published" value="1" id="published"<?= (int) $value('published', 1) === 1 ? ' checked' : ''; ?>><label class="form-check-label" for="published">Publié / disponible</label></div></div><?php endif; ?>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $editId > 0 ? 'Enregistrer les modifications' : 'Créer'; ?></button><?php if ($editId > 0) : ?><a class="btn btn-outline-secondary" href="<?= $formUrl; ?>">Annuler la modification</a><?php endif; ?></div>
        </form>
    </div></div>

    <?php if ($this->entity !== 'session') : ?><div class="card"><div class="card-body"><h2 class="h4">Éléments actifs</h2><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Id</th><th>Élément</th><th>Détails</th><th>État</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($this->items as $item) : ?><tr><td><?= (int) $item['id']; ?></td><td><strong><?= $escape($item['title']); ?></strong></td><td><?= $escape($item['detail']); ?></td><td><?= (int) $item['published'] === 1 ? 'Publié' : 'Non publié'; ?></td><td class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" href="<?= $escape(Route::_($base . '&entity=' . rawurlencode($this->entity) . '&id=' . (int) $item['id'], false)); ?>">Modifier</a><form action="<?= $formUrl; ?>" method="post" onsubmit="return confirm('Retirer cet élément du catalogue ?');"><input type="hidden" name="option" value="com_memipilates"><input type="hidden" name="task" value="catalog.archive"><input type="hidden" name="entity" value="<?= $escape($this->entity); ?>"><input type="hidden" name="id" value="<?= (int) $item['id']; ?>"><input type="hidden" name="<?= $token; ?>" value="1"><button class="btn btn-sm btn-outline-danger" type="submit">Retirer</button></form></td></tr><?php endforeach; ?>
        <?php if ($this->items === []) : ?><tr><td colspan="5" class="text-muted">Aucun élément actif.</td></tr><?php endif; ?>
    </tbody></table></div></div></div><?php endif; ?>
</div>
