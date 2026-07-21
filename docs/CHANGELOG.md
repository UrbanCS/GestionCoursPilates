# Journal des changements

Le format suit l’esprit de Keep a Changelog. Les versions publiées doivent être datées, liées au ZIP exact de pkg_memipilates et accompagnées de son SHA-256. Ne pas placer de secret, de QR réel, d’e-mail client ou d’identifiant Square sensible dans ce journal.

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
