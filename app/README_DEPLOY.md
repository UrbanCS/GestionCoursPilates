# Déploiement de la PWA Memi Studio

## Prérequis

- Joomla/Memi Studio est installé directement dans `public_html`.
- PHP 8.2 ou plus récent avec `curl`, `json`, `mbstring` et `openssl`.
- HTTPS actif sur `memistudio.ca`.
- Le dossier `vendor` produit par Composer est présent dans l’archive.

L’application ne crée aucun second compte client. Elle démarre Joomla depuis le dossier parent et partage l’authentification, les cours, les promotions, les crédits et les points déjà présents.

## Installation cPanel

1. Dans le gestionnaire de fichiers, créer `public_html/app`.
2. Téléverser le contenu de l’archive de déploiement dans ce dossier (le fichier `.htaccess` doit être conservé).
3. Ouvrir `https://memistudio.ca/app/` une première fois. Les tables `#__memi_pwa_*`, la date d’installation et les clés VAPID sont alors créées automatiquement par l’utilisateur MySQL de Joomla.
4. Vérifier que l’écran de connexion charge et qu’un compte Joomla existant ouvre bien l’espace client.
5. Dans **Cron Jobs**, ajouter une exécution toutes les 5 minutes. Adapter le chemin PHP et le nom du compte cPanel affichés par l’hébergeur :

~~~text
*/5 * * * * /usr/local/bin/ea-php82 /home/UTILISATEUR_CPANEL/public_html/app/cron/run.php >/dev/null 2>&1
~~~

Le script refuse toute exécution Web. Il utilise un verrou MySQL, déduplique chaque événement et reprend temporairement les envois échoués.

## Catégories

- `courses` : nouvelle séance publique ajoutée à l’horaire;
- `promotions` : nouvelle promotion active;
- `other` : annonce publiée depuis l’espace de gestion de la PWA ou nouvelle récompense fidélité.

Toutes les catégories sont désactivées par défaut. Un client doit cocher ses choix, puis autoriser les notifications sur chaque appareil souhaité. Les nouvelles apparaissent aussi dans l’application.

Sur iPhone/iPad, l’utilisateur doit d’abord ajouter la PWA à l’écran d’accueil depuis Safari, l’ouvrir depuis son icône, puis toucher le bouton d’activation des notifications.

## Mise à jour

Pour une nouvelle version, remplacer les fichiers de `public_html/app` avec une archive complète. Conserver les tables `#__memi_pwa_*` : elles contiennent les préférences, les abonnements et les clés VAPID nécessaires aux appareils déjà inscrits.
