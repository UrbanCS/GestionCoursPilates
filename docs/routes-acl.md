# Routes, menus et ACL

## Règle générale

Les vues publiques sont exposées par des éléments de menu Joomla afin qu’elles héritent du template, de la navigation, de l’accessibilité et des URL SEO du site. Les tâches qui modifient un état vérifient côté serveur l’utilisateur connecté, l’ACL, le jeton CSRF Joomla et les règles métier. Masquer un bouton ou un menu n’est jamais un contrôle d’accès.

La route technique non SEF reste une référence de diagnostic. Lorsqu’un élément de menu existe, Joomla peut fournir une URL SEF différente sans changer l’autorisation requise.

## Vues frontend

| Fonction | Vue/route technique de référence | Accès | Contrôles |
| --- | --- | --- | --- |
| Horaire | index.php?option=com_memipilates&view=schedule | Public | Filtres validés; seules les séances publiées sont visibles. |
| Horaire et détail d’une séance | view=schedule, puis view=booking avec session_id | Horaire public; connexion requise pour confirmer | Seules les séances publiées sont listées; l’ID est revalidé, puis la capacité, la fenêtre d’inscription et le crédit sont contrôlés sous transaction. |
| Achat d’un forfait | view=checkout avec package_id | Client connecté, propriétaire de la commande | Offre active, prix serveur en cents, promotion et total recalculés côté serveur. |
| Parcours de réservation | view=booking avec session_id | Client connecté au moment de confirmer | CSRF, capacité transactionnelle, fenêtre d’inscription et crédit serveur. |
| Offre de liste d’attente | view=waitlistoffer&id=…&token=… | Client connecté, propriétaire de l’offre | Jeton temporaire haché côté serveur, CSRF à l’acceptation et place temporairement retenue. |
| Paiement | view=checkout | Client propriétaire de la commande | CSRF, ordre non payé, montant reconstruit serveur. |
| Espace client et confirmations | view=dashboard | Client connecté | Filtre systématique sur l’ID Joomla connecté; réservations, forfaits, points, commandes et QR du seul client. |
| QR personnel | view=dashboard | Client connecté | Jeton opaque uniquement; affichage, impression et régénération auditée. |
| Borne | index.php?option=com_memipilates&view=kiosk | Employé autorisé | attendance.kiosk avant de rendre la page. |
| Gestion du studio | index.php?option=com_memipilates&view=manage | Personnel connecté autorisé | Portail protégé; chaque section vérifie son ACL propre, chaque écriture conserve CSRF, portée et validation métier. |
| Paramètres du studio | index.php?option=com_memipilates&view=settings | Super administrateur | `core.admin`; les secrets Square ne sont jamais renvoyés au navigateur et une valeur vide les conserve. |

L’interface de borne utilise la racine HTML [data-memi-kiosk]. La page peut recevoir des routes AJAX non SEF dans ses attributs de données afin de rester compatible avec Joomla et les déploiements cPanel.

## Tâches et interfaces d’administration

| Fonction | Tâche/route de référence | Méthode | Autorisation minimale | Protection |
| --- | --- | --- | --- | --- |
| Scan HID/caméra | task=kiosk.scan | POST | attendance.scan et attendance.kiosk | CSRF Joomla, token QR opaque, session sélectionnée, méthode autorisée, idempotency_key. |
| Recherche borne | task=kiosk.search | POST/GET contrôlé | attendance.manual et attendance.kiosk | Requête minimisée, limitation de débit, pas de recherche globale non autorisée. |
| Présence manuelle | contrôleur attendance | POST | attendance.manual | CSRF, réservation/cours validés, journal d’audit. |
| Dérogation | contrôleur attendance | POST | attendance.override | CSRF, raison obligatoire, journal d’audit et indication visible. |
| Annulation/correction présence | contrôleur attendance | POST | attendance.undo | CSRF, justification, conservation de l’historique. |
| Test lecteur | uniquement local dans la borne | Aucun appel serveur | kiosk.test | Ne transmet ni ne conserve le QR, ne crée aucune présence. |
| Réservation/annulation client | contrôleurs booking | POST | client propriétaire | CSRF, état/capacité/délai validés serveur. |
| Régénération du QR personnel | task=qr.regenerate | POST | client propriétaire | CSRF, idempotency_key et rotation auditée; la valeur brute n'est jamais stockée. |
| Révocation/régénération QR par le personnel | task=management.revokeQr / management.regenerateQr | POST | qr.manage | CSRF, client actif exigé pour régénérer, révocation permise aussi après blocage/archivage, idempotency_key à la régénération et journal d'audit. |
| Paiement | contrôleur checkout | POST | client propriétaire | CSRF, ordre/total serveur, clé d’idempotence. |
| Webhook Square | route configurée dans l’option Square | POST | Signature Square, non pas session Joomla | Signature, environnement, ID d’événement unique, limitation de débit. |
| Administration | administrator/index.php?option=com_memipilates&view=... | GET/POST | Action métier spécifique | ACL Joomla, CSRF pour écriture, filtres de portée. |
| Portail de gestion frontal | index.php?option=com_memipilates&view=manage, catalog, sessions, bookings, customers, packages, offers, payments, attendance ou settings | GET/POST | Action métier spécifique | Même ACL, CSRF, services métier et filtres de portée que l’administration. |

Le scan poste token, session_id, method (hid ou camera) et idempotency_key. Le navigateur ne choisit pas le client final ni le résultat de la présence : le serveur retrouve l’empreinte QR, vérifie la réservation et enregistre présence/points atomiquement.

Le mode test du lecteur est une exception volontaire : il est local au navigateur, n’appelle aucun endpoint et n’affiche/conserve jamais le QR complet. La recherche manuelle est une action distincte et ne doit pas devenir un contournement d’ACL.

## Actions ACL du composant

Les actions Joomla core s’appliquent lorsque pertinentes : core.admin, core.manage, core.create, core.edit, core.edit.state et core.delete. Le composant définit en complément les actions suivantes :

| Domaine | Actions |
| --- | --- |
| Catalogue | courses.manage, schedules.manage, instructors.manage, rooms.manage |
| Clients et réservations | clients.manage, bookings.manage, bookings.manual, waitlist.manage |
| Forfaits, crédits et promotions | packages.manage, credits.adjust, promotions.manage |
| Paiements et rapports | payments.view, payments.refund, reports.view, export.data |
| Configuration et audit | core.admin pour toutes les Options, y compris Square; audit.view pour le journal d’audit |
| QR et fidélité | qr.manage, loyalty.adjust |
| Présence | attendance.kiosk, attendance.all_sessions, attendance.scan, attendance.manual, attendance.override, attendance.undo |
| Borne de diagnostic | kiosk.test |

Ces noms doivent être déclarés dans le manifeste ACL du composant et vérifiés dans les contrôleurs/services, pas seulement dans les vues.

## Matrice de rôles recommandée

| Rôle Joomla | Accès accordé | Exclusions importantes |
| --- | --- | --- |
| Super administrateur | Toutes les actions, y compris core.admin et audit.view | Utiliser seulement pour configuration et incident; ne pas partager ce compte sur la borne. |
| Gestionnaire du studio | courses/schedules/instructors/rooms/clients/bookings/waitlist/packages/promotions, credits.adjust, payments.view, reports.view, export.data, présence complète selon politique | Les Options et secrets Square restent réservés à core.admin; ACL globale et remboursement ne sont accordés que si nécessaires. |
| Employé ou instructeur | Ses cours/participants selon la liaison du compte Joomla à l’instructeur, bookings.manual, attendance.kiosk, attendance.scan, attendance.manual, kiosk.test | attendance.all_sessions, core.manage global, réglages, Square, export, crédit/points, remboursement et dérogation par défaut. |
| Employé habilité à la dérogation | Droits d’employé plus attendance.override et/ou attendance.undo après formation | Aucun accès Square, audit global ou administration Joomla. |
| Client | Vues publiques et actions sur ses propres données via le contrôleur frontend | Toute route backend, toute donnée d’un autre client, les exports et les actions de présence. |

L’ACL de Joomla répond à « qui peut faire quoi ». Les contrôleurs appliquent aussi la portée : un instructeur voit seulement les séances qui lui sont attribuées par la liaison `#__memi_instructors.user_id`; un client ne lit que les lignes associées à son user_id; une borne n’accède qu’à une séance autorisée. Un compte sans liaison instructeur ne reçoit aucune séance par défaut. `attendance.all_sessions` constitue l’exception explicite pour une borne partagée ou un responsable dûment autorisé. Le contrôle de portée reste obligatoire même si un utilisateur connaît l’ID d’une autre ressource.

## Mise en place des groupes

1. Créer ou réutiliser des groupes Joomla cohérents avec le site : Gestionnaires studio, Employés/Instructeurs, Employés borne, Clients.
2. Dans **Système → Utilisateurs → Groupes** et les options de com_memipilates, partir du refus par défaut.
3. Accorder les actions explicitement, à la plus petite portée possible. Lier chaque compte instructeur depuis le Catalogue, ajouter attendance.override séparément de attendance.scan et réserver attendance.all_sessions à une borne partagée ou à un responsable.
4. Configurer les niveaux d’accès de menu pour l’espace client, l’administration et la borne; un niveau de menu ne remplace pas l’action ACL.
5. Tester chaque rôle dans une session distincte et confirmer les réponses serveur : accès autorisé, 403 sans droit et absence de fuite de données.
6. Auditer régulièrement les membres de groupes, surtout les comptes de borne, les exportateurs et les comptes ayant core.admin.

Lors d’une mise à jour, le script d’installation retire aussi de l’asset du composant les anciennes règles `core.options`, `settings.manage` et `square.configure`. Elles ne doivent pas survivre à une version antérieure puisque la page Options expose désormais, dans un même formulaire, les secrets Square et les réglages généraux.

## Exports, téléchargements et API

Un export CSV, reçu ou téléchargement est une route protégée, avec contrôle d’ownership/ACL avant de générer le fichier. Les CSV doivent prévenir l’injection de formule, avoir un nom prévisible non sensible et être générés à la demande ou stockés temporairement hors de la racine web. Chaque export administratif est consigné avec l’acteur, le filtre, l’heure et la justification lorsque requise.

Les réponses JSON retournent des codes d’erreur génériques à l’utilisateur et le détail diagnostique nettoyé dans les journaux restreints. Les tokens CSRF ne s’appliquent pas à un webhook Square authentifié par signature, mais aucune autre route publique ne peut invoquer cette exception.
