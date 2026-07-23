# Journal des changements

Le format suit l’esprit de Keep a Changelog. Les versions publiées doivent être datées, liées au ZIP exact de pkg_memipilates et accompagnées de son SHA-256. Ne pas placer de secret, de QR réel, d’e-mail client ou d’identifiant Square sensible dans ce journal.

## [1.5.0] - 2026-07-22

### Ajouté

- Remboursement Square total et idempotent depuis l’administration, protégé par l’ACL `payments.refund` et consigné dans l’audit.
- Réglage explicite de l’URL du webhook Square et contrôles de reprise des courriels (nombre maximal d’essais et délai exponentiel).
- Actions administratives pour régénérer ou révoquer le QR d’un client, protégées par l’ACL `qr.manage`.
- Fiche QR imprimable depuis l’espace client et raccourcis cohérents entre l’horaire, les forfaits et le compte.
- Liaison facultative d’un instructeur à son compte Joomla et droit explicite `attendance.all_sessions` pour une borne partagée autorisée.
- Courriels métier détaillés pour les réservations, annulations, rappels, séances annulées, listes d’attente, paiements et crédits bientôt expirés.

### Corrigé

- Les webhooks Square ne dépendent plus d’un champ d’idempotence absent des objets Payment; ils valident l’ordre, le montant, la devise, l’emplacement et le statut avant attribution.
- La réponse synchrone de Square est également rapprochée avec le montant, la devise, l’emplacement et la référence de la commande avant toute attribution de forfait ou de points.
- Les paiements dont la réponse réseau est inconnue sont rapprochés automatiquement par référence immuable, puis annulés par leur clé Square avant qu’un nouvel essai ne soit autorisé; les remboursements incertains sont rejoués avec leur requête idempotente d’origine.
- Un paiement définitivement refusé reçoit une nouvelle clé Square générée côté serveur lors de l’essai suivant; les clés de paiement et de remboursement respectent la limite Square de 45 caractères et les motifs envoyés respectent la limite de 192 caractères.
- Une livraison concurrente ne peut plus rétrograder un webhook déjà traité de `processed` vers `failed`.
- Le script de construction refuse désormais une version différente de celle déclarée dans l’un des quatre manifestes Joomla.
- Les limites globales et par client des codes promotionnels sont vérifiées sous verrou afin d’éviter les dépassements simultanés.
- Les courriels temporairement en échec sont repris avec temporisation; une offre de liste d’attente définitivement non livrable libère sa place et passe au client suivant.
- Le lien d’acceptation de la liste d’attente est reconstruit à partir d’une signature HMAC et n’est plus conservé en clair dans la file de courriels.
- Une annulation admissible restitue un crédit réellement réutilisable même si le forfait d’origine a expiré entre-temps, sans réactiver les autres crédits expirés.
- Le QR actif reste affichable et imprimable après rechargement; la régénération est idempotente et la base garantit un seul QR actif par client.
- Les QR appartenant à un compte Joomla bloqué ou à un profil client archivé sont refusés par la borne, tout en restant révocables par une personne autorisée.
- La fidélité attribue maintenant un point par dollar par défaut, en plus des points de présence configurés.
- Les employés et instructeurs ne voient et ne modifient plus que les séances qui leur sont assignées; les contacts clients restent masqués sans `clients.manage` et toutes les Options, y compris les secrets Square, exigent `core.admin`.
- L’accès direct à une réservation rejette maintenant une séance passée, archivée, non publiée ou hors de sa fenêtre d’inscription, et affiche les dates dans le fuseau du studio.
- L’acceptation d’une offre de liste d’attente envoie une confirmation de réservation; les avis d’expiration de crédits et reçus de paiement sont idempotents et ne contiennent aucun secret Square.
- Les dates, montants et fenêtres d’inscription des tableaux et parcours publics utilisent désormais le fuseau et la devise configurés au lieu des valeurs du serveur ou du navigateur.

### Migration

- Ajout non destructif des métadonnées de reprise de notification et des clés d’idempotence/unicité QR.
- Les anciens QR expirés ou actifs en double sont révoqués automatiquement; toutes les données métier existantes sont conservées.
- Les anciennes autorisations `core.options`, `settings.manage` et `square.configure` sont retirées de l’asset Joomla afin qu’une mise à jour ne conserve pas un accès historique aux secrets Square.

### Validation connue

- Archive : `dist/pkg_memipilates-1.5.0.zip`
- SHA-256 : `2A522C8E32165733EBA11159C76A190EFCBF91C8A00E723272AFE2D369B5CC96`

## [1.4.2] - 2026-07-22

### Corrigé

- Le bouton « Calendrier complet » ouvre maintenant un calendrier mensuel intégré au lieu de dépendre du sélecteur de date natif et invisible du navigateur.
- La sélection d’une date déjà chargée reste instantanée; une date hors de la semaine affichée recharge automatiquement la bonne période en conservant les filtres.

### Modifié

- Les accents roses et rouges de l’horaire public utilisent désormais le vert olive du site (`#9A9A8B`), avec des variantes foncées suffisamment contrastées pour les textes et le focus.
- Le calendrier mensuel permet de changer de mois, revenir à aujourd’hui, fermer le panneau et naviguer entièrement au clavier.

### Validation connue

- Archive : `dist/pkg_memipilates-1.4.2.zip`
- SHA-256 : `119B477D75F535F2A26AB36413F0895C76347C2A9D5D42048C50C28BC15FFCC6`

## [1.4.1] - 2026-07-21

### Corrigé

- Les chemins déclarés au gestionnaire d’assets Joomla ne doublent plus les répertoires `css` et `js`; la feuille de style et le calendrier interactif se chargent maintenant sur le site public.
- Le calendrier complet recharge la bonne période lorsqu’une date choisie se trouve hors des sept journées déjà affichées.
- Les jours et les mois utilisent les traductions propres au composant afin d’éviter les dates anglaises sur le site français.

### Modifié

- L’horaire public adopte une présentation moderne inspirée du parcours Rouge Pilates : fond clair, grande carte blanche, filtres compacts, sélecteur de sept dates circulaires et lignes de cours lisibles.
- La mise en page a été resserrée et adaptée aux ordinateurs, tablettes et téléphones sans reprendre la marque ni le code du site de référence.

### Validation connue

- Archive : `dist/pkg_memipilates-1.4.1.zip`
- SHA-256 : `15758733982E7985F11862CB0DB5BA0ECB6B74397AA96DEE344A575CB31D13EE`

## [1.4.0] - 2026-07-21

### Ajouté

- Sélecteur public de sept dates inspiré du parcours de réservation de Rouge Pilates, avec navigation vers la période précédente ou suivante.
- Choix d’une date sans rechargement lorsque JavaScript est disponible, avec lien de repli fonctionnel dans le cas contraire.

### Modifié

- Présentation de l’horaire en lignes compactes indiquant l’heure, le cours, l’instructeur, le lieu, les places restantes et l’action de réservation ou de liste d’attente.
- Dates et libellés localisés en français ou en anglais selon la langue du site, dans le fuseau horaire configuré pour le studio.
- Mise en page adaptée aux téléphones, tablettes et ordinateurs, avec une apparence cohérente avec le site Memi Studio.

### Validation connue

- Archive : `dist/pkg_memipilates-1.4.0.zip`
- SHA-256 : `31556C81897B394EAE000164AE7ABB2457115EA175E61C113BC12B84CAE36692`

## [1.3.1] - 2026-07-21

### Corrigé

- Les six types d’éléments de menu du site affichent maintenant leurs libellés français ou anglais dans l’administration Joomla au lieu des clés `COM_MEMIPILATES_*`.

### Validation connue

- Archive : `dist/pkg_memipilates-1.3.1.zip`
- SHA-256 : `277E27C74F86795016E60DDC78BE507CC8CD4808452744BFB075728E1ABEA12C`

## [1.3.0] - 2026-07-18

### Ajouté

- Écrans d’administration **Catalogue**, **Promotions et fidélité** et **Paiements** : création, modification, retrait sécurisé et suivi des opérations courantes.
- Création d’un client Joomla/Memi par le personnel et inscription manuelle à une séance avec crédit ou à titre gratuit.
- Création et gestion des codes promotionnels, restrictions par forfait, limites, bonus de crédits/points et catalogue de récompenses fidélité.
- Types d’éléments de menu Joomla pour Horaire, Réservation, Achat de forfait, Espace client, Borne et offre de liste d’attente.

### Corrigé

- Les réglages de fidélité, de borne, du nom d’expéditeur et du mode de promotion de liste d’attente s’appliquent réellement aux flux métier.
- Les points de fidélité sont également bloqués pour les présences ajoutées manuellement lorsque la fidélité est désactivée.
- Les promotions sont validées côté serveur pour les dates, forfaits, minimums et limites, puis journalisées et créditées après paiement.

### Validation connue

- Archive : `dist/pkg_memipilates-1.3.0.zip`
- SHA-256 : `18E92A43F82FC13C93E8B7DB15D94B9855A29FF20F0A485973C63558F6AEFBC7`
- Contrôles statiques : PHP lint sur les 70 fichiers PHP du paquet, XML des manifestes et métadonnées de menu, vérification de l’archive.

## [1.2.0] - 2026-07-17

### Ajouté

- Bouton protégé de réinitialisation du catalogue de test dans « Mise en route », réservé aux administrateurs du composant et confirmé par la saisie de `REINITIALISER`.
- Archivage cohérent des emplacements, salles, instructeurs, cours, horaires, séances et forfaits de test sans effacer le journal d’audit.

### Corrigé

- Empêche la génération d’une nouvelle séance par le planificateur pendant ou après la réinitialisation.
- Les anciennes URL de réservation pour une séance archivée répondent maintenant 404.
- Les étiquettes « Publiée » et « Actions » sont traduites dans les tableaux d’administration.

### Validation connue

- Archive : `dist/pkg_memipilates-1.2.0.zip`
- SHA-256 : `5062AC779A56319D40FBA218E51ED8DE90EA72311F6DCC28B8BD530DB3B77606`

## [1.1.1] - 2026-07-17

### Corrigé

- Compatibilité Joomla 6.1 : le formulaire de mise en route accepte maintenant le type d’entrée réellement transmis par Joomla lors de l’enregistrement.

### Validation connue

- Archive : `dist/pkg_memipilates-1.1.1.zip`
- SHA-256 : `2FA67B4122C339C2D4C2ACB1BCD12807AAEAED876CAA884077C59E1444B692B5`

## [1.1.0] - 2026-07-17

### Ajouté

- Écran d’administration protégé « Mise en route » pour créer l’emplacement, la salle, l’instructeur, le type de cours, le cours, les séances, les horaires hebdomadaires et les forfaits de départ.
- Génération immédiate des séances futures lors de l’enregistrement d’un horaire hebdomadaire, selon l’horizon configuré.

### Corrigé

- Liaison des valeurs SQL de l’écran de mise en route afin que chaque champ soit enregistré dans sa propre colonne.
- Conservation du taux de taxe du cours lors de la génération de séances récurrentes.
- Réduction des coordonnées enregistrées dans le journal d’audit et alignement des droits de création avec l’accès d’administration du composant.

### Validation connue

- Archive : `dist/pkg_memipilates-1.1.0.zip`
- SHA-256 : `956654CECF093124527C11C4DB17F348EB80806C34550D770A00C37F9599AB96`

## [1.0.4] - 2026-07-15

### Corrigé

- Correction de la racine du formulaire de configuration afin que Joomla 6 affiche les paramètres du composant.

### Validation connue

- Archive : `dist/pkg_memipilates-1.0.4.zip`
- SHA-256 : `C4A5A5F1B0683D246C053692A51AFE7DC6355014FA7ACD9FDD636175FC1C9E4E`

## [1.0.3] - 2026-07-15

### Corrigé

- Ajout du bouton natif « Options » dans la barre d’outils de toutes les vues d’administration pour les personnes autorisées.

### Validation connue

- Archive : `dist/pkg_memipilates-1.0.3.zip`
- SHA-256 : `DE10644AC48CAD7659C7DE76E95067F756A41D7505DC139A5F58526C5E417455`

## [1.0.2] - 2026-07-15

### Corrigé

- Alignement du fournisseur de services du composant sur les interfaces Joomla 6 : le répartiteur MVC est désormais enregistré sous le bon namespace et les services non implémentés ont été retirés.

### Validation connue

- Archive : `dist/pkg_memipilates-1.0.2.zip`
- SHA-256 : `C6889E2E24D120ABE27A98B8BAB6154D6BF58805E3583A0DAB898178098E2E35`

## [1.0.1] - 2026-07-15

### Corrigé

- Ajout des libellés de sous-menu dans les fichiers de langue système Joomla afin qu’ils s’affichent correctement dans l’administration avant l’ouverture du composant.
- Reconstruction des archives ZIP avec des séparateurs de chemins compatibles avec les hébergements Linux.

### Validation connue

- Archive : `dist/pkg_memipilates-1.0.1.zip`
- SHA-256 : `08662BB753BD58A07A715555A985DCB9D0529F92467E91AAF4DC7D283FEA26F6`

## [1.0.0] - 2026-07-15

### Ajouté

- Paquet Joomla natif comprenant le composant `com_memipilates`, la tâche planifiée et la commande CLI cPanel.
- Réservation transactionnelle, crédits et points par registres, QR opaque, borne HID/caméra, listes d’attente avec place temporairement retenue et fidélité.
- Paiement Square côté serveur, vérification de webhook et réconciliation idempotente en cas de reprise après interruption.
- Interfaces Joomla publiques et administratives, langues FR/EN, schéma SQL, ACL, guides d’exploitation et matrice AT-01 à AT-26.

### Corrigé

- Restauration de tous les crédits requis par une séance, y compris lorsqu’ils proviennent de plusieurs forfaits.
- Conservation des paramètres liés dans les requêtes `FOR UPDATE`, promotion automatique d’attente, recherche manuelle de borne limitée aux participants de la séance et envoi de notification réclamé atomiquement.

### Validation connue

- Archive : `dist/pkg_memipilates-1.0.0.zip`
- SHA-256 : `DB48A61ACC7D4EAC171DC0502D1E28F5CDBD496DDEA7EB1FF50A7D7EF0B7686E`
- Contrôles statiques exécutés avec PHP 8.3.20; les essais Joomla/Square/cPanel restent à réaliser en préproduction isolée.

### Ajouté

- Guides d’installation Joomla, de déploiement cPanel, de sauvegarde et de retour arrière.
- Procédures Square Sandbox/Production, webhook signé, secrets et rotation.
- Documentation des tâches cron, du modèle de données, des routes et ACL.
- Guide de borne Mac : lecteur QR HID USB/Bluetooth, caméra, recherche manuelle, révocation et correction.
- Stratégie de sécurité, matrice d’acceptation AT-01 à AT-26 et squelette PHPUnit.

### À compléter avant une livraison

- Numéro de version réel, date, compatibilités Joomla/PHP validées et hash de l’archive.
- Éléments de menu, chemins de commande CLI et URL webhook finalement livrés.
- Résultats des tests automatisés et manuels réalisés sur préproduction.
- Migrations, modifications incompatibles et procédure de reprise associée.

## Modèle de note de version

### [X.Y.Z] - AAAA-MM-JJ

#### Ajouté

- …

#### Modifié

- …

#### Corrigé

- …

#### Sécurité

- …

#### Migration/déploiement

- ZIP : pkg_memipilates-X.Y.Z.zip
- SHA-256 : à renseigner hors secret
- Préproduction : réussi/échoué, lien vers preuve protégée
- Retour arrière compatible : oui/non, procédure
