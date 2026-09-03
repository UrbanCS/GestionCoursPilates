# Selixo production overrides

Upload `css/custom.css` to `/templates/selixo/css/custom.css`.

LiteSpeed serves that file with a seven-day browser cache. Keep Helix's normal
call in `templates/selixo/index.php`:

```php
$theme->add_css('custom.css');
```

Then add this link after the PHP block that registers the Helix styles and
before the optional `containerMaxWidth` inline style:

```php
<link data-memi-custom-css rel="stylesheet" href="<?php echo Uri::root(true) . '/templates/' . $template->template . '/css/custom.css?release=1816'; ?>">
```

Increment the `release` value whenever `custom.css` changes so returning
visitors receive the new responsive fixes immediately.
