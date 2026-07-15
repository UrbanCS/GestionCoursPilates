# Routes, menus et ACL

## Règle générale

Les vues publiques sont exposées par des éléments de menu Joomla afin qu’elles héritent du template, de la navigation, de l’accessibilité et des URL SEO du site. Les tâches qui modifient un état vérifient côté serveur l’utilisateur connecté, l’ACL, le jeton CSRF Joomla et les règles métier. Masquer un bouton ou un menu n’est jamais un contrôle d’accès.

La route technique non SEF reste une référence de diagnostic. Lorsqu’un élément de menu existe, Joomla peut fournir une URL SEF différente sans changer l’autorisation requise.

## Vues frontend

| Fonction | Vue/route technique de référence | Accès | Contrôles |
| --- | --- | --- | --- |
| Horaire | index.php?option=com_memipilates&view=schedule | Public | Filtres validés; seules les séances publiées sont visibles. |
| Détail d’un cours/séance | view=course ou view=session, idéalement via menu/alias | Public | ID/alias validé; informations internes exclues. |
| Forfaits | view=packages | Public | Offres actives, prix serveur en cents. |
| Parcours de réservation | view=booking | Client connecté au moment de confirmer | CSRF, capacité transactionnelle, crédit/promo serveur. |
| Offre de liste d’attente | view=waitlistoffer&id=…&token=… | Client connecté, propriétaire de l’offre | Jeton temporaire haché côté serveur, CSRF à l’acceptation et place temporairement retenue. |
| Paiement | view=checkout | Client propriétaire de la commande | CSRF, ordre non payé, montant reconstruit serveur. |
| Confirmation | view=confirmation | Client propriétaire ou lien temporaire signé | Ne révèle pas une commande d’autrui. |
| Espace client | view=account | Client connecté | Filtre systématique sur l’ID Joomla connecté. |
| QR personnel | vue/section de compte | Client connecté | Jeton opaque uniquement; régénération auditée. |
| Borne | index.php?option=com_memipilates&view=kiosk | Employé autorisé | attendance.kiosk avant de rendre la page. |

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
| Paiement | contrôleur checkout | POST | client propriétaire | CSRF, ordre/total serveur, clé d’idempotence. |
| Webhook Square | route configurée dans l’option Square | POST | Signature Square, non pas session Joomla | Signature, environnement, ID d’événement unique, limitation de débit. |
| Administration | administrator/index.php?option=com_memipilates&view=... | GET/POST | Action métier spécifique | ACL Joomla, CSRF pour écriture, filtres de portée. |

Le scan poste token, session_id, method (hid ou camera) et idempotency_key. Le navigateur ne choisit pas le client final ni le résultat de la présence : le serveur retrouve l’empreinte QR, vérifie la réservation et enregistre présence/points atomiquement.

Le mode test du lecteur est une exception volontaire : il est local au navigateur, n’appelle aucun endpoint et n’affiche/conserve jamais le QR complet. La recherche manuelle est une action distincte et ne doit pas devenir un contournement d’ACL.

## Actions ACL du composant

Les actions Joomla core s’appliquent lorsque pertinentes : core.admin, core.manage, core.create, core.edit, core.edit.state, core.delete et core.options. Le composant définit en complément les actions suivantes :

| Domaine | Actions |
| --- | --- |
| Catalogue | courses.manage, schedules.manage, instructors.manage, rooms.manage |
| Clients et réservations | clients.manage, bookings.manage, bookings.manual, waitlist.manage |
| Forfaits, crédits et promotions | packages.manage, credits.adjust, promotions.manage |
| Paiements et rapports | payments.view, payments.refund, reports.view, export.data |
| Configuration et audit | settings.manage, square.configure, audit.view |
| QR et fidélité | qr.manage, loyalty.adjust |
| Présence | attendance.kiosk, attendance.scan, attendance.manual, attendance.override, attendance.undo |
| Borne de diagnostic | kiosk.test |

Ces noms doivent être déclarés dans le manifeste ACL du composant et vérifiés dans les contrôleurs/services, pas seulement dans les vues.

## Matrice de rôles recommandée

| Rôle Joomla | Accès accordé | Exclusions importantes |
| --- | --- | --- |
| Super administrateur | Toutes les actions, y compris core.admin, settings.manage, square.configure et audit.view | Utiliser seulement pour configuration et incident; ne pas partager ce compte sur la borne. |
| Gestionnaire du studio | courses/schedules/instructors/rooms/clients/bookings/waitlist/packages/promotions, credits.adjust, payments.view, reports.view, export.data, présence complète selon politique | square.configure, core.admin, ACL globale et remboursement ne sont accordés que si nécessaires. |
| Employé ou instructeur | Ses cours/participants selon portée, bookings.manual, attendance.kiosk, attendance.scan, attendance.manual, kiosk.test | core.manage global, réglages, Square, export, crédit/points, remboursement et dérogation par défaut. |
| Employé habilité à la dérogation | Droits d’employé plus attendance.override et/ou attendance.undo après formation | Aucun accès Square, audit global ou administration Joomla. |
| Client | Vues publiques et actions sur ses propres données via le contrôleur frontend | Toute route backend, toute donnée d’un autre client, les exports et les actions de présence. |

L’ACL de Joomla répond à « qui peut faire quoi ». Les contrôleurs doivent aussi appliquer la portée : un instructeur voit seulement les séances qui lui sont attribuées; un client ne lit que les lignes associées à son user_id; une borne n’accède qu’à la séance sélectionnée/autorisée. Le contrôle de portée est obligatoire même si un utilisateur connaît l’ID d’une autre ressource.

## Mise en place des groupes

1. Créer ou réutiliser des groupes Joomla cohérents avec le site : Gestionnaires studio, Employés/Instructeurs, Employés borne, Clients.
2. Dans **Système → Utilisateurs → Groupes** et les options de com_memipilates, partir du refus par défaut.
3. Accorder les actions explicitement, à la plus petite portée possible. Ajouter attendance.override séparément de attendance.scan.
4. Configurer les niveaux d’accès de menu pour l’espace client, l’administration et la borne; un niveau de menu ne remplace pas l’action ACL.
5. Tester chaque rôle dans une session distincte et confirmer les réponses serveur : accès autorisé, 403 sans droit et absence de fuite de données.
6. Auditer régulièrement les membres de groupes, surtout les comptes de borne, les exportateurs et les comptes ayant square.configure.

## Exports, téléchargements et API

Un export CSV, reçu ou téléchargement est une route protégée, avec contrôle d’ownership/ACL avant de générer le fichier. Les CSV doivent prévenir l’injection de formule, avoir un nom prévisible non sensible et être générés à la demande ou stockés temporairement hors de la racine web. Chaque export administratif est consigné avec l’acteur, le filtre, l’heure et la justification lorsque requise.

Les réponses JSON retournent des codes d’erreur génériques à l’utilisateur et le détail diagnostique nettoyé dans les journaux restreints. Les tokens CSRF ne s’appliquent pas à un webhook Square authentifié par signature, mais aucune autre route publique ne peut invoquer cette exception.
