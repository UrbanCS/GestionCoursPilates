# Journal des changements

Le format suit l’esprit de Keep a Changelog. Les versions publiées doivent être datées, liées au ZIP exact de pkg_memipilates et accompagnées de son SHA-256. Ne pas placer de secret, de QR réel, d’e-mail client ou d’identifiant Square sensible dans ce journal.

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
- SHA-256 : `BDA237DA21778E25E84AA6122D735A6DA5FCF38924F72361FE0847A4228EA330`
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
