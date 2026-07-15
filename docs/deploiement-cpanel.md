# Déploiement sur cPanel

Le déploiement se fait par le mécanisme d’extensions Joomla. cPanel sert à préparer les sauvegardes, ajuster l’environnement PHP, déposer un gros ZIP si nécessaire et planifier le cron; il ne remplace pas l’installateur Joomla.

## Stratégie recommandée

1. Installer d’abord la même version du paquet sur une copie de préproduction avec une copie anonymisée de la base.
2. Tester l’intégration au template et la matrice d’acceptation prioritaire.
3. Préparer un créneau de production, une sauvegarde, une personne habilitée à restaurer et le paquet précédent.
4. Installer le nouveau paquet Joomla, appliquer les contrôles de fumée et seulement alors activer Square Production.

Ne tester ni les migrations ni une nouvelle extension directement sur la production sans sauvegarde vérifiée.

## Préparation cPanel

- Identifier la racine Joomla réelle, par exemple /home/COMPTE/public_html, sans supposer qu’elle est la racine du domaine.
- Dans **MultiPHP Manager** ou **Select PHP Version**, conserver une version PHP compatible avec le Joomla existant et avec le minimum PHP 8.1 du paquet.
- Vérifier les extensions PHP requises, le quota disque, la taille maximale d’upload et les droits d’écriture des répertoires Joomla tmp, cache, logs et media.
- Conserver les fichiers en lecture/écriture uniquement pour le propriétaire lorsque l’hébergeur le permet. Des permissions usuelles 755 pour les dossiers et 644 pour les fichiers sont préférables à 777; respecter les recommandations spécifiques de l’hébergeur.
- Vérifier que HTTPS est valide avant d’activer Square ou le scan caméra.
- Configurer une adresse d’alerte cPanel pour les échecs cron et surveiller l’espace disque des journaux.

Les ZIP de livraison, exports et sauvegardes ne doivent pas rester sous un chemin publiquement téléchargeable.

## Déploiement du paquet

1. Passer temporairement le studio en mode opérationnel restreint : interrompre les nouveaux paiements, les promotions automatiques de liste d’attente et les scans pendant la migration.
2. Faire les sauvegardes de pré-déploiement.
3. Se connecter à Joomla, installer le ZIP pkg_memipilates via **Système → Installer → Extensions**.
4. Vérifier les tâches, plugins et modules installés; n’activer que ceux indiqués par la version livrée.
5. Réexaminer les paramètres après chaque upgrade. Un installateur ne doit pas écraser silencieusement les réglages, groupes ACL ou secrets en production.
6. Vider le cache Joomla ciblé, puis tester en navigation privée : horaire, session client, paiement Sandbox/Production selon l’environnement, route webhook et borne.
7. Remettre en service, consigner le numéro de version, l’heure et le résultat des tests.

Si l’installation par téléversement échoue à cause d’une limite, suivre la procédure **Installer depuis un dossier** de [Installation Joomla](installation-joomla.md), puis retirer le contenu temporaire.

## Paramètres de production

| Élément | Attendu |
| --- | --- |
| URL publique | HTTPS, certificat valide, redirections cohérentes |
| Joomla | Cache et session conformes au site existant; aucun changement du cœur |
| PHP | 8.1+ et extensions nécessaires; display_errors désactivé en production |
| Base de données | Sauvegarde testée, charset/collation cohérents avec Joomla, espace pour les registres |
| Courriel | SMTP Joomla déjà validé; expéditeur et réponse contrôlés |
| Square | Environnement Production seulement après validation Sandbox et webhook signé |
| Journalisation | Chemin non public, rotation, accès restreint, aucune donnée de carte/jeton QR |
| Cron | Chemin PHP absolu, chemin Joomla absolu, exécution et alerte vérifiées |

## Vérification de santé après publication

- La page d’accueil et plusieurs pages non liées au composant continuent de répondre normalement.
- La console navigateur ne signale aucune erreur JavaScript nouvelle; les styles du composant ne s’appliquent pas globalement au template.
- Le client peut consulter l’horaire et son tableau de bord sans fuite de données d’un autre client.
- Le compte Employé peut ouvrir la borne mais ne peut pas modifier les paramètres Square ou un réglage global.
- Un webhook test reçoit une réponse attendue, est vérifié par signature et laisse une seule trace métier même en cas de répétition.
- Le cron s’exécute une fois en manuel et une fois via cPanel, sans erreurs ni secrets dans son journal.

## Échecs et retour de service

En cas d’erreur, ne pas multiplier les installations par-dessus une base incertaine. Désactiver les extensions Memi Pilates concernées, conserver les journaux, empêcher de nouveaux paiements et appliquer le plan documenté dans [Sauvegarde et retour arrière](sauvegarde-retour-arriere.md). Un paiement déjà accepté par Square doit être rapproché et traité comptablement avant toute restauration de base.
