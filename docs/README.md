# Documentation opérationnelle — Memi Pilates

Cette documentation accompagne le paquet Joomla **pkg_memipilates**. Le produit principal est le composant **com_memipilates**, dans l’espace de noms PHP **Memi\Component\Memipilates**, et ses éventuelles extensions enfants fournies par le paquet.

## Périmètre et prérequis

- Joomla 4.4 ou Joomla 5.x, avec une version de PHP prise en charge par l’installation Joomla (PHP 8.1 ou supérieur pour ce paquet).
- MySQL ou MariaDB déjà compatible avec Joomla. Toutes les tables fonctionnelles utilisent le préfixe Joomla #__ et sont donc créées comme #__memi_*.
- Hébergement cPanel avec HTTPS, tâches cron et les extensions PHP nécessaires à Joomla et aux communications HTTPS (notamment cURL et OpenSSL).
- Aucune application, session ou base d’utilisateurs parallèle : les comptes, groupes, ACL, sessions, mails et menus restent ceux de Joomla.
- Node.js, Docker et un serveur de processus ne sont jamais nécessaires en production. Les médias distribués par le paquet doivent déjà être compilés.

La configuration de production ne doit jamais être copiée dans ce dépôt. Cela couvre notamment les accès cPanel, les mots de passe Joomla ou SQL, les clés Square, les clés de signature de webhooks et les données personnelles.

## Guides

| Sujet | Guide |
| --- | --- |
| Installation et mise à jour par paquet Joomla | [Installation Joomla](installation-joomla.md) |
| Déploiement et exploitation sur cPanel | [Déploiement cPanel](deploiement-cpanel.md) |
| Sauvegarde, reprise et retour arrière | [Sauvegarde et retour arrière](sauvegarde-retour-arriere.md) |
| Square Sandbox, Production et webhooks | [Square](square.md) |
| Valeurs de configuration non sensibles | [Configuration d’exemple](configuration-exemple.md) |
| Tâches planifiées et supervision | [Cron](cron.md) |
| Tables, registres et contraintes métier | [Modèle de données](modele-donnees.md) |
| Routes, menus et autorisations | [Routes et ACL](routes-acl.md) |
| Borne de présence, Mac, lecteur HID et caméra | [Borne QR sur Mac](borne-qr-mac.md) |
| Opérations de catalogue, paiement et rapports | [Guide administrateur](administration.md) |
| Accueil, présence et inscription manuelle | [Guide employé](employe.md) |
| Sécurité, secrets et exploitation | [Sécurité et exploitation](securite-exploitation.md) |
| Stratégie, matrice et preuves de tests | [Plan de tests](plan-tests.md) |
| Squelette de tests automatisés | [tests/README.md](../tests/README.md) |
| État de la dernière campagne connue | [tests/RESULTATS.md](../tests/RESULTATS.md) |
| Changements et livraisons | [Journal des changements](CHANGELOG.md) |

## Principes non négociables

1. **Le serveur Joomla est l’autorité.** Le navigateur ne confirme jamais seul un paiement, une présence, des points ou un mouvement de crédits.
2. **Toute opération monétaire ou de capacité est atomique et idempotente.** Les doublons de réservation, de paiement, de webhook, de scan et de points sont refusés par les validations applicatives et les contraintes de base.
3. **Les registres sont append-only.** Un crédit ou des points sont corrigés par un nouveau mouvement traçable, jamais par la modification silencieuse d’un solde historique.
4. **Les routes vérifient l’ACL côté serveur.** Un lien de menu, un bouton masqué ou une donnée envoyée par le navigateur ne constitue pas une autorisation.
5. **Les QR sont opaques et révocables.** Seule une empreinte du jeton est conservée lorsque possible; le jeton complet ne va ni dans les journaux ni dans les exports.
6. **Le temps métier est cohérent.** Les horaires sont présentés dans le fuseau Joomla, avec America/Toronto comme valeur recommandée, et les tests couvrent les changements d’heure.

## Mise en production : porte de sortie

Une version n’est prête à être mise en service que si les conditions suivantes sont réunies :

- une sauvegarde restaurable des fichiers et de la base a été vérifiée;
- le paquet a été installé et testé en préproduction avec le template Joomla réel;
- le test d’achat Sandbox, le webhook signé, la réservation, l’annulation, la liste d’attente et la borne ont réussi;
- les groupes ACL sont attribués au moindre privilège;
- le cron a été lancé manuellement puis observé après au moins une exécution planifiée;
- tous les secrets sont configurés sur le serveur, absents du dépôt et absents des journaux;
- le résultat de la matrice d’acceptation est consigné dans [plan-tests.md](plan-tests.md).

Conserver avec chaque livraison : le ZIP exact de pkg_memipilates, son SHA-256, le numéro de version, la date, l’opérateur, les migrations exécutées et la preuve des contrôles ci-dessus.
