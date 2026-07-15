# Journal des changements

Le format suit l’esprit de Keep a Changelog. Les versions publiées doivent être datées, liées au ZIP exact de pkg_memipilates et accompagnées de son SHA-256. Ne pas placer de secret, de QR réel, d’e-mail client ou d’identifiant Square sensible dans ce journal.

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
