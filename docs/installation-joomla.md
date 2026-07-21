# Installation et mise à jour Joomla

Ce guide installe le paquet natif Joomla **pkg_memipilates**. Il ne faut jamais copier des fichiers dans le cœur Joomla, modifier directement le template, ni installer les sous-extensions séparément si le paquet les distribue.

## Avant toute installation

1. Déterminer explicitement si la cible est une préproduction ou la production. En production, planifier une fenêtre de maintenance si une migration peut verrouiller des tables.
2. Relever la version Joomla, PHP, MySQL/MariaDB, le template actif, les surcharges, le préfixe de tables, le cache, la session, le SMTP, les groupes et les règles ACL actuels.
3. Vérifier PHP 8.1+ et les extensions nécessaires à Joomla ainsi qu’à HTTPS : cURL, OpenSSL, JSON, mbstring, ZIP et le pilote PDO/MySQL correspondant. Ne changer la version PHP du site qu’après validation de la compatibilité de ses extensions existantes.
4. Réaliser et étiqueter une sauvegarde des fichiers et de la base, conformément à [Sauvegarde et retour arrière](sauvegarde-retour-arriere.md).
5. Vérifier l’intégrité du ZIP livré avec sa somme SHA-256. Utiliser uniquement un paquet de version identifiée.
6. Préparer une URL HTTPS publique si Square ou la caméra doivent être activés. Les webhooks Square ne fonctionnent pas avec une URL locale privée.

## Installation standard du paquet

1. Ouvrir l’administration Joomla avec un compte Super administrateur.
2. Aller dans **Système → Installer → Extensions**.
3. Utiliser **Téléverser un fichier de paquet** et sélectionner le ZIP de pkg_memipilates.
4. Attendre le message de succès de l’installateur. Garder la page d’erreurs si l’opération échoue; ne pas réessayer plusieurs fois sans comprendre la cause.
5. Aller dans **Système → Gérer → Extensions** et confirmer que le composant et les éventuels modules/plugins du paquet sont présents. N’activer que les plugins nécessaires.
6. Ouvrir **Composants → Memi Pilates → Options** et renseigner les réglages non secrets : fuseau, délai d’annulation, fenêtres d’activité des cours, politique de promotion de liste d’attente et règles de points.
7. Ouvrir **Composants → Memi Pilates → Mise en route** et créer l’emplacement, la salle, l’instructeur, le type de cours, le cours, une séance future ou un horaire hebdomadaire, puis un forfait. Une séance future publiée est requise avant que l’horaire public puisse afficher le cours.
8. Configurer les secrets Square uniquement côté administration sécurisée/serveur, selon [Square](square.md). Commencer obligatoirement en Sandbox.
9. Créer des éléments de menu Joomla vers les vues publiques. Dans **Menus → [menu voulu] → Nouveau → Type d’élément de menu → Memi Pilates**, choisir : **Horaire**, **Réservation**, **Acheter un forfait**, **Mon espace client**, **Borne de présence** ou **Offre de liste d’attente**. Les routes restent ainsi menu-centrées et conservent le template, l’en-tête, le pied de page et les URL SEO du site. Ajouter au minimum Horaire, Acheter un forfait et Mon espace client; limiter Borne de présence au personnel.
10. Attribuer les groupes et actions ACL décrits dans [Routes et ACL](routes-acl.md). Ne pas donner l’accès d’administration global à un employé de borne.
11. Purger seulement les caches concernés puis ouvrir le site dans une session client distincte.

Le paquet peut inclure un composant, des modules et des plugins. Joomla gère leur enregistrement et les migrations : les fichiers ne doivent pas être déplacés manuellement après installation.

## Solution si le téléversement est bloqué par cPanel

Si la taille maximale de téléversement est trop faible, employer **Installer depuis un dossier** dans le même écran Joomla :

1. Téléverser le ZIP ou son contenu dans un dossier temporaire non public, idéalement sous le répertoire tmp Joomla, via le gestionnaire de fichiers cPanel ou SFTP.
2. Décompresser le paquet dans ce dossier temporaire.
3. Dans **Système → Installer → Extensions**, choisir **Installer depuis un dossier** et fournir le chemin absolu du dossier décompressé.
4. Laisser Joomla exécuter l’installation, puis supprimer le dossier temporaire et le ZIP du répertoire accessible au Web.

Ne pas déposer le composant directement dans components ou administrator/components : cela contourne les migrations, les enregistrements d’extensions et le mécanisme de désinstallation.

## Contrôles après installation

Exécuter ces contrôles sur une préproduction, puis à nouveau en production :

- Le site existant, ses menus, pages, template et surcharges se chargent sans erreur PHP ou JavaScript.
- Les tables #__memi_* ont été créées, avec les index et contraintes attendus. Voir [Modèle de données](modele-donnees.md).
- Une page Horaire rend dans le template actif, avec une vue mobile et une navigation clavier correcte.
- Un client Joomla ne peut voir que son propre espace; un employé de borne n’accède pas aux réglages Square.
- Une réservation test avec crédit, une réservation payante Sandbox, une annulation et un webhook signé ont le statut attendu.
- Un QR test valide crée une seule présence et une seule attribution de points; le même scan est ensuite signalé comme déjà traité.
- La commande cron est testée manuellement et son journal est lisible sans secret.

Conserver les captures/identifiants de test dans un dossier d’exploitation protégé, pas dans les tickets publics ni le dépôt.

## Mise à jour

1. Lire les notes de version et vérifier les éventuelles migrations irréversibles.
2. Sauvegarder la base et les fichiers avant l’upgrade.
3. Activer temporairement une fenêtre de maintenance fonctionnelle : ne pas accepter de paiement ou de scan pendant la migration.
4. Installer le nouveau ZIP depuis le même écran **Extensions**. Le manifeste doit gérer le mode upgrade; ne pas désinstaller l’ancienne version avant l’upgrade.
5. Vérifier le résultat de la migration, les versions d’extension, les tâches, les modules et les menus.
6. Exécuter les contrôles de fumée ci-dessus, puis rouvrir les réservations et la borne.

Une migration de registre, de paiement ou de réservation ne doit pas être « annulée » en modifiant des lignes à la main. Si l’upgrade a déjà produit des opérations métier, utiliser la procédure de reprise de [Sauvegarde et retour arrière](sauvegarde-retour-arriere.md).

## Désinstallation et conservation des données

En production, la désinstallation est une dernière mesure, pas une procédure de mise à jour. Désactiver d’abord les extensions Memi Pilates, exporter les données nécessaires, puis restaurer une sauvegarde si un retour de version est requis.

La politique recommandée est de **préserver les réservations, paiements, registres, présences et journaux d’audit** tant que les obligations comptables, opérationnelles et de confidentialité l’exigent. Une suppression de données ne doit être effectuée que par une commande/outil explicitement prévu, avec confirmation, export préalable et journal d’audit. Ne jamais utiliser une désinstallation pour effacer implicitement les données opérationnelles.
